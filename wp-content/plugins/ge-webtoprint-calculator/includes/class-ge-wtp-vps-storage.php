<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Private uploads stored on a VPS path that is physically outside DocumentRoot.
 */
final class GE_WTP_VPS_Storage {
    const AJAX_ACTION = 'ge_vps_prepare_upload';
    const RECEIVE_ACTION = 'ge_vps_receive_upload';
    const CLEANUP_HOOK = 'ge_vps_cleanup_pending_uploads';
    const CLAIM_TTL = 1800;
    const MAX_FILES = 5;
    const MAX_FILE_BYTES = 262144000;
    const MAX_TOTAL_BYTES = 524288000;
    const MAX_USER_BYTES = 1073741824;
    const MAX_GLOBAL_BYTES = 21474836480;
    const RESERVE_BYTES = 10737418240;
    const PENDING_RETENTION = 604800;

    public static function init() {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_prepare_upload' ) );
        add_action( 'wp_ajax_' . self::RECEIVE_ACTION, array( __CLASS__, 'ajax_receive_upload' ) );
        add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_pending' ) );
        add_action( 'init', array( __CLASS__, 'schedule_cleanup' ), 35 );
    }

    public static function configured() {
        $base = self::base_directory();
        if ( '' === $base || ! self::is_absolute_path( $base ) ) { return false; }
        if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) { return false; }
        $real = realpath( $base );
        if ( false === $real || ! is_writable( $real ) ) { return false; }
        foreach ( array_filter( array( realpath( ABSPATH ), defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : false ) ) as $public_root ) {
            if ( self::path_is_inside( $real, $public_root ) ) { return false; }
        }
        return true;
    }

    public static function ready() {
        return self::configured();
    }

    public static function limits() {
        return array(
            'max_files' => self::MAX_FILES,
            'max_file_bytes' => self::config_int( 'GE_WTP_PRIVATE_MAX_FILE_BYTES', self::MAX_FILE_BYTES ),
            'max_total_bytes' => self::config_int( 'GE_WTP_PRIVATE_MAX_TOTAL_BYTES', self::MAX_TOTAL_BYTES ),
            'max_user_bytes' => self::config_int( 'GE_WTP_PRIVATE_MAX_USER_BYTES', self::MAX_USER_BYTES ),
            'max_global_bytes' => self::config_int( 'GE_WTP_PRIVATE_MAX_GLOBAL_BYTES', self::MAX_GLOBAL_BYTES ),
            'reserve_bytes' => self::config_int( 'GE_WTP_PRIVATE_RESERVE_BYTES', self::RESERVE_BYTES ),
            'pending_retention' => self::config_int( 'GE_WTP_PRIVATE_PENDING_RETENTION', self::PENDING_RETENTION ),
        );
    }

    public static function allowed_extensions() {
        return array( 'pdf', 'ai', 'eps', 'psd', 'tif', 'tiff', 'svg', 'cdr', 'zip', 'jpg', 'jpeg', 'png' );
    }

    public static function validate_manifest( $files ) {
        $limits = self::limits();
        if ( ! is_array( $files ) || ! $files ) { return new WP_Error( 'ge_vps_empty', 'Elegí al menos un archivo.' ); }
        if ( count( $files ) > $limits['max_files'] ) { return new WP_Error( 'ge_vps_count', 'Podés subir hasta cinco archivos por producto.' ); }
        $clean = array();
        $total = 0;
        foreach ( $files as $file ) {
            $name = sanitize_file_name( wp_basename( (string) ( $file['name'] ?? '' ) ) );
            $size = (int) ( $file['size'] ?? 0 );
            $mime = sanitize_text_field( (string) ( $file['type'] ?? 'application/octet-stream' ) );
            $extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            if ( ! $name || ! in_array( $extension, self::allowed_extensions(), true ) ) { return new WP_Error( 'ge_vps_type', 'Uno de los archivos tiene un formato no permitido.' ); }
            if ( $size < 1 || $size > $limits['max_file_bytes'] ) { return new WP_Error( 'ge_vps_size', 'Uno de los archivos supera el límite permitido.' ); }
            $total += $size;
            if ( $total > $limits['max_total_bytes'] ) { return new WP_Error( 'ge_vps_total', 'El conjunto de archivos supera el límite permitido.' ); }
            $clean[] = array( 'name' => $name, 'size' => $size, 'mime' => $mime );
        }
        return $clean;
    }

    public static function issue_claim( $data, $now = null ) {
        $now = null === $now ? time() : (int) $now;
        $payload = array(
            'v' => 1,
            'provider' => 'vps',
            'user_id' => absint( $data['user_id'] ?? 0 ),
            'path' => ltrim( str_replace( '\\', '/', (string) ( $data['path'] ?? '' ) ), '/' ),
            'name' => sanitize_file_name( (string) ( $data['name'] ?? '' ) ),
            'size' => (int) ( $data['size'] ?? 0 ),
            'mime' => sanitize_text_field( (string) ( $data['mime'] ?? 'application/octet-stream' ) ),
            'iat' => $now,
            'exp' => $now + self::CLAIM_TTL,
        );
        $encoded = self::base64url_encode( wp_json_encode( $payload ) );
        return $encoded . '.' . hash_hmac( 'sha256', $encoded, self::claim_secret() );
    }

    public static function verify_claim( $token, $user_id, $now = null ) {
        $parts = explode( '.', (string) $token, 2 );
        if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], self::claim_secret() ), $parts[1] ) ) { return new WP_Error( 'ge_vps_claim', 'La autorización del archivo no es válida.' ); }
        $payload = json_decode( self::base64url_decode( $parts[0] ), true );
        $now = null === $now ? time() : (int) $now;
        if ( ! is_array( $payload ) || 'vps' !== ( $payload['provider'] ?? '' ) || absint( $payload['user_id'] ?? 0 ) !== absint( $user_id ) ) { return new WP_Error( 'ge_vps_owner', 'El archivo no pertenece a esta cuenta.' ); }
        if ( $now < (int) ( $payload['iat'] ?? 0 ) - 60 || $now > (int) ( $payload['exp'] ?? 0 ) ) { return new WP_Error( 'ge_vps_expired', 'La autorización del archivo venció. Volvé a subirlo.' ); }
        $prefix = 'pending/' . absint( $user_id ) . '/';
        if ( 0 !== strpos( (string) ( $payload['path'] ?? '' ), $prefix ) ) { return new WP_Error( 'ge_vps_path', 'La ubicación privada no es válida.' ); }
        return $payload;
    }

    public static function validate_uploaded_claims( $tokens, $user_id ) {
        if ( ! $tokens ) { return array(); }
        if ( ! self::ready() || ! $user_id ) { return new WP_Error( 'ge_vps_unavailable', 'La carga privada no está disponible.' ); }
        if ( ! is_array( $tokens ) || count( $tokens ) > self::MAX_FILES ) { return new WP_Error( 'ge_vps_claim_count', 'La cantidad de archivos no es válida.' ); }
        $result = array();
        foreach ( $tokens as $token ) {
            $claim = self::verify_claim( (string) $token, $user_id );
            if ( is_wp_error( $claim ) ) { return $claim; }
            $path = self::absolute_path( $claim['path'] );
            if ( ! $path || ! is_file( $path ) || filesize( $path ) !== (int) $claim['size'] ) { return new WP_Error( 'ge_vps_missing', 'El archivo no llegó completo al almacenamiento privado.' ); }
            $result[] = array(
                'provider' => 'vps',
                'relative_path' => $claim['path'],
                'name' => $claim['name'],
                'size' => (int) $claim['size'],
                'mime' => $claim['mime'],
                'uploaded_by' => absint( $user_id ),
                'uploaded_at' => current_time( 'mysql' ),
            );
        }
        return $result;
    }

    public static function finalize_descriptor( $descriptor, $order_id, $item_id ) {
        if ( 'vps' !== ( $descriptor['provider'] ?? '' ) || empty( $descriptor['relative_path'] ) ) { return new WP_Error( 'ge_vps_descriptor', 'El archivo privado no es válido.' ); }
        $source = self::absolute_path( $descriptor['relative_path'] );
        if ( ! $source || ! is_file( $source ) ) { return new WP_Error( 'ge_vps_missing', 'El archivo privado no existe.' ); }
        $target_relative = 'orders/' . absint( $order_id ) . '/' . absint( $item_id ) . '/' . wp_generate_uuid4() . '-' . sanitize_file_name( $descriptor['name'] );
        $target = self::absolute_path( $target_relative, true );
        if ( ! $target || ! wp_mkdir_p( dirname( $target ) ) || ! rename( $source, $target ) ) { return new WP_Error( 'ge_vps_move', 'No se pudo consolidar el archivo privado.' ); }
        $descriptor['relative_path'] = $target_relative;
        return $descriptor;
    }

    public static function download_path( $relative_path ) {
        $relative_path = ltrim( str_replace( '\\', '/', (string) $relative_path ), '/' );
        if ( 0 !== strpos( $relative_path, 'orders/' ) ) { return ''; }
        $path = self::absolute_path( $relative_path );
        $real = $path ? realpath( $path ) : false;
        $base = realpath( self::base_directory() );
        return $real && $base && self::path_is_inside( $real, $base ) && is_file( $real ) ? $real : '';
    }

    public static function store_order_upload( $file, $order_id, $item_id = 0 ) {
        if ( ! self::configured() || ! is_uploaded_file( $file['tmp_name'] ?? '' ) ) { return new WP_Error( 'ge_vps_source', 'No se pudo validar el archivo recibido.' ); }
        $manifest = self::validate_manifest( array( array( 'name' => $file['name'] ?? '', 'size' => $file['size'] ?? 0, 'type' => $file['type'] ?? '' ) ) );
        if ( is_wp_error( $manifest ) ) { return $manifest; }
        $quota = self::check_quota( get_current_user_id(), (int) $manifest[0]['size'] );
        if ( is_wp_error( $quota ) ) { return $quota; }
        $relative = 'orders/' . absint( $order_id ) . '/' . absint( $item_id ) . '/' . wp_generate_uuid4() . '-' . $manifest[0]['name'];
        $destination = self::absolute_path( $relative, true );
        if ( ! $destination || ! wp_mkdir_p( dirname( $destination ) ) || ! move_uploaded_file( $file['tmp_name'], $destination ) ) { return new WP_Error( 'ge_vps_move', 'No se pudo guardar el archivo privado.' ); }
        @chmod( $destination, 0640 );
        return array( 'provider' => 'vps', 'relative_path' => $relative, 'name' => $manifest[0]['name'], 'size' => (int) $manifest[0]['size'], 'mime' => $manifest[0]['mime'] );
    }

    public static function ajax_prepare_upload() {
        if ( ! is_user_logged_in() || ! check_ajax_referer( self::AJAX_ACTION, 'nonce', false ) || ! self::ready() ) { wp_send_json_error( array( 'message' => 'La carga privada no está disponible.' ), 403 ); }
        $rate_key = 'ge_vps_prepare_' . get_current_user_id();
        $attempts = (int) get_transient( $rate_key );
        if ( $attempts >= 20 ) { wp_send_json_error( array( 'message' => 'Esperá unos minutos antes de intentar otra carga.' ), 429 ); }
        set_transient( $rate_key, $attempts + 1, 10 * MINUTE_IN_SECONDS );
        $files = json_decode( isset( $_POST['files'] ) ? wp_unslash( $_POST['files'] ) : '[]', true );
        $files = self::validate_manifest( $files );
        if ( is_wp_error( $files ) ) { wp_send_json_error( array( 'message' => $files->get_error_message() ), 400 ); }
        $incoming = array_sum( wp_list_pluck( $files, 'size' ) );
        $quota = self::check_quota( get_current_user_id(), $incoming );
        if ( is_wp_error( $quota ) ) { wp_send_json_error( array( 'message' => $quota->get_error_message() ), 507 ); }
        $uploads = array();
        foreach ( $files as $file ) {
            $relative = 'pending/' . get_current_user_id() . '/' . gmdate( 'Y/m' ) . '/' . wp_generate_uuid4() . '/' . $file['name'];
            $claim = self::issue_claim( array_merge( $file, array( 'user_id' => get_current_user_id(), 'path' => $relative ) ) );
            $uploads[] = array(
                'name' => $file['name'],
                'mime' => $file['mime'],
                'token' => $claim,
                'url' => add_query_arg( array( 'action' => self::RECEIVE_ACTION, 'claim' => $claim ), admin_url( 'admin-ajax.php' ) ),
            );
        }
        wp_send_json_success( array( 'uploads' => $uploads ) );
    }

    public static function ajax_receive_upload() {
        if ( ! is_user_logged_in() || 'PUT' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || ! self::configured() ) { wp_send_json_error( array( 'message' => 'Carga no autorizada.' ), 403 ); }
        $claim = self::verify_claim( isset( $_GET['claim'] ) ? wp_unslash( $_GET['claim'] ) : '', get_current_user_id() );
        if ( is_wp_error( $claim ) ) { wp_send_json_error( array( 'message' => $claim->get_error_message() ), 403 ); }
        $quota = self::check_quota( get_current_user_id(), (int) $claim['size'] );
        if ( is_wp_error( $quota ) ) { wp_send_json_error( array( 'message' => $quota->get_error_message() ), 507 ); }
        $destination = self::absolute_path( $claim['path'], true );
        if ( ! $destination || ! wp_mkdir_p( dirname( $destination ) ) ) { wp_send_json_error( array( 'message' => 'No se pudo preparar el almacenamiento.' ), 500 ); }
        $input = fopen( 'php://input', 'rb' );
        $output = fopen( $destination, 'xb' );
        if ( ! $input || ! $output ) { if ( is_resource( $input ) ) { fclose( $input ); } wp_send_json_error( array( 'message' => 'No se pudo guardar el archivo.' ), 500 ); }
        $written = 0;
        while ( ! feof( $input ) && $written <= (int) $claim['size'] ) {
            $chunk = fread( $input, 1048576 );
            if ( false === $chunk ) { break; }
            $written += strlen( $chunk );
            if ( $written > (int) $claim['size'] ) { break; }
            $offset = 0;
            while ( $offset < strlen( $chunk ) ) {
                $count = fwrite( $output, substr( $chunk, $offset ) );
                if ( false === $count || 0 === $count ) { break 2; }
                $offset += $count;
            }
        }
        fclose( $input );
        fclose( $output );
        if ( $written !== (int) $claim['size'] || filesize( $destination ) !== (int) $claim['size'] ) {
            @unlink( $destination );
            wp_send_json_error( array( 'message' => 'El tamaño recibido no coincide con el autorizado.' ), 400 );
        }
        @chmod( $destination, 0640 );
        wp_send_json_success( array( 'stored' => true ) );
    }

    public static function schedule_cleanup() {
        if ( self::configured() && ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    public static function cleanup_pending() {
        if ( ! self::configured() ) { return; }
        $pending = self::absolute_path( 'pending' );
        if ( ! $pending || ! is_dir( $pending ) ) { return; }
        $cutoff = time() - self::limits()['pending_retention'];
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $pending, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $iterator as $item ) {
            if ( $item->isFile() && ! $item->isLink() && $item->getMTime() < $cutoff ) { @unlink( $item->getPathname() ); }
            elseif ( $item->isDir() && ! $item->isLink() ) { @rmdir( $item->getPathname() ); }
        }
    }

    private static function check_quota( $user_id, $incoming ) {
        $limits = self::limits();
        $base = self::base_directory();
        if ( self::directory_size( $base ) + $incoming > $limits['max_global_bytes'] ) { return new WP_Error( 'ge_vps_global_quota', 'El almacenamiento privado alcanzó su cuota operativa.' ); }
        if ( self::directory_size( $base . '/pending/' . absint( $user_id ) ) + $incoming > $limits['max_user_bytes'] ) { return new WP_Error( 'ge_vps_user_quota', 'Tu cuenta alcanzó la cuota temporal de archivos.' ); }
        $free = @disk_free_space( $base );
        if ( false === $free || $free - $incoming < $limits['reserve_bytes'] ) { return new WP_Error( 'ge_vps_reserve', 'No hay reserva de disco suficiente para aceptar el archivo.' ); }
        return true;
    }

    private static function directory_size( $directory ) {
        if ( ! is_dir( $directory ) ) { return 0; }
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $item ) { if ( $item->isFile() && ! $item->isLink() ) { $bytes += $item->getSize(); } }
        return $bytes;
    }

    private static function absolute_path( $relative, $allow_missing = false ) {
        if ( ! self::configured() ) { return ''; }
        $base = realpath( self::base_directory() );
        $relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
        if ( '' === $relative || in_array( '..', explode( '/', $relative ), true ) ) { return ''; }
        $candidate = $base . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
        $parent = realpath( dirname( $candidate ) );
        if ( false === $parent && $allow_missing ) {
            $cursor = dirname( $candidate );
            while ( false === $parent && dirname( $cursor ) !== $cursor ) { $cursor = dirname( $cursor ); $parent = realpath( $cursor ); }
        }
        return false !== $parent && self::path_is_inside( $parent, $base ) ? $candidate : '';
    }

    private static function base_directory() {
        return rtrim( self::config_value( 'GE_WTP_PRIVATE_UPLOAD_DIR' ), '/\\' );
    }

    private static function path_is_inside( $path, $root ) {
        $path = rtrim( str_replace( '\\', '/', (string) $path ), '/' ) . '/';
        $root = rtrim( str_replace( '\\', '/', (string) $root ), '/' ) . '/';
        return 0 === strpos( strtolower( $path ), strtolower( $root ) );
    }

    private static function is_absolute_path( $path ) {
        return 1 === preg_match( '#^(?:[A-Za-z]:[\\\\/]|/)#', (string) $path );
    }

    private static function config_value( $name ) {
        if ( defined( $name ) ) { return trim( (string) constant( $name ) ); }
        $value = getenv( $name );
        return false === $value ? '' : trim( (string) $value );
    }

    private static function config_int( $name, $default ) {
        $value = (int) self::config_value( $name );
        return $value > 0 ? $value : (int) $default;
    }

    private static function claim_secret() {
        $secret = self::config_value( 'GE_WTP_PRIVATE_UPLOAD_CLAIM_SECRET' );
        return '' !== $secret ? $secret : wp_salt( 'auth' );
    }

    private static function base64url_encode( $data ) { return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); }
    private static function base64url_decode( $data ) { return base64_decode( strtr( $data, '-_', '+/' ) ); }
}
