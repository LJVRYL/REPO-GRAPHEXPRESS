<?php
defined( 'ABSPATH' ) || exit;

/** WSFEv1 transport. External configuration only; no request/credential logging. */
final class GE_WTP_ARCA_Client {
    const SERVICE = 'wsfe';
    private $config;
    public function __construct( $config ) { $this->config = $config; }
    public function environment() { return $this->config['environment']; }
    public function homologation_pos_allowed( $pos ) { return 'homologation' === $this->environment() && in_array( (int) $pos, (array) ( $this->config['homologation_points_of_sale'] ?? array() ), true ); }
    public static function configuration( $issuer ) {
        if ( ! defined( 'GE_WTP_ARCA_EMISSION_CONFIG_FILE' ) ) { return new WP_Error( 'ge_arca_configuration', 'Falta configurar certificado y habilitación ARCA del emisor.' ); }
        $path = self::outside_public_tree( GE_WTP_ARCA_EMISSION_CONFIG_FILE );
        if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) { return new WP_Error( 'ge_arca_configuration', 'Configuración ARCA no disponible.' ); }
        try { $all = require $path; } catch ( Throwable $e ) { return new WP_Error( 'ge_arca_configuration', 'Configuración ARCA no disponible.' ); }
        $c = is_array( $all ) ? ( $all[$issuer['credentials_ref'] ?? ''] ?? null ) : null;
        if ( ! is_array( $c ) || ! in_array( $c['environment'] ?? '', array( 'production', 'homologation' ), true ) || (string) ( $c['represented_cuit'] ?? '' ) !== (string) $issuer['cuit'] || empty( $issuer['cert_ref'] ) || ( $c['cert_ref'] ?? '' ) !== $issuer['cert_ref'] || empty( $c['enabled'] ) ) { return new WP_Error( 'ge_arca_configuration', 'Certificado, CUIT o ambiente ARCA no habilitado para este emisor.' ); }
        foreach ( array( 'certificate_path', 'private_key_path', 'runtime_dir' ) as $key ) {
            $real = self::outside_public_tree( $c[$key] ?? '' );
            if ( ! $real ) { return new WP_Error( 'ge_arca_storage', 'ARCA requiere almacenamiento privado fuera del sitio y Git.' ); }
            $c[$key] = $real;
        }
        if ( ! is_file( $c['certificate_path'] ) || ! is_readable( $c['certificate_path'] ) || ! is_file( $c['private_key_path'] ) || ! is_readable( $c['private_key_path'] ) || ! is_dir( $c['runtime_dir'] ) || ! is_writable( $c['runtime_dir'] ) ) { return new WP_Error( 'ge_arca_storage', 'Archivos privados ARCA no disponibles.' ); }
        if ( '/' === DIRECTORY_SEPARATOR ) { foreach ( array( $path, $c['private_key_path'], $c['runtime_dir'] ) as $file ) { if ( fileperms( $file ) & 0077 ) { return new WP_Error( 'ge_arca_permissions', 'Revisá los permisos privados ARCA.' ); } } }
        $cert = openssl_x509_read( file_get_contents( $c['certificate_path'] ) );
        $key = openssl_pkey_get_private( 'file://' . $c['private_key_path'], (string) ( $c['private_key_passphrase'] ?? '' ) );
        $info = $cert ? openssl_x509_parse( $cert ) : false;
        if ( ! $info || ! $key || ! openssl_x509_check_private_key( $cert, $key ) || (int) $info['validFrom_time_t'] > time() || (int) $info['validTo_time_t'] <= time() + 300 ) { return new WP_Error( 'ge_arca_certificate', 'Certificado vencido, inválido o sin correspondencia con su clave.' ); }
        $c['certificate_expires_at'] = gmdate( 'c', $info['validTo_time_t'] );
        return $c;
    }
    private static function client( $wsdl ) {
        if ( ! class_exists( 'SoapClient' ) ) { throw new RuntimeException( 'SOAP unavailable' ); }
        return new SoapClient( $wsdl, array( 'soap_version' => SOAP_1_1, 'trace' => false, 'exceptions' => true, 'cache_wsdl' => WSDL_CACHE_NONE, 'connection_timeout' => 10,
            'stream_context' => stream_context_create( array( 'http' => array( 'timeout' => 25 ), 'ssl' => array( 'verify_peer' => true, 'verify_peer_name' => true ) ) ) ) );
    }
    public function call( $method, $params = array() ) {
        $allowed = array( 'FECAESolicitar', 'FECompConsultar', 'FECompUltimoAutorizado', 'FEParamGetPtosVenta', 'FEParamGetTiposCbte', 'FEParamGetCondicionIvaReceptor' );
        if ( ! in_array( $method, $allowed, true ) ) { return new WP_Error( 'ge_arca_method', 'Operación no permitida.' ); }
        try {
            $ticket = self::ticket( $this->config );
            $params['Auth'] = array( 'Token' => $ticket['token'], 'Sign' => $ticket['sign'], 'Cuit' => $this->config['represented_cuit'] );
            $host = 'production' === $this->environment() ? 'servicios1.afip.gov.ar' : 'wswhomo.afip.gov.ar';
            $result = self::client( 'https://' . $host . '/wsfev1/service.asmx?WSDL' )->__soapCall( $method, array( $params ) );
            $result = json_decode( json_encode( $result ), true );
            return is_array( $result ) && isset( $result[$method . 'Result'] ) ? $result[$method . 'Result'] : new WP_Error( 'ge_arca_response', 'Respuesta ARCA incompleta. Consultá el estado antes de reintentar.' );
        } catch ( Throwable $e ) { return new WP_Error( 'ge_arca_unavailable', 'ARCA no respondió. Consultá el estado antes de reintentar.' ); }
    }
    private static function outside_public_tree( $path ) {
        $real = realpath( $path ); if ( false === $real ) { return false; }
        $real = str_replace( '\\', '/', $real );
        $roots = array( ABSPATH, dirname( __DIR__ ) );
        if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) { $roots[] = $_SERVER['DOCUMENT_ROOT']; }
        foreach ( $roots as $root ) {
            $root = realpath( $root );
            if ( false !== $root && 0 === stripos( $real . '/', rtrim( str_replace( '\\', '/', $root ), '/' ) . '/' ) ) { return false; }
        }
        // Reject any ancestor tracked as a repository, even when outside the public tree.
        $dir = is_dir( $real ) ? $real : dirname( $real );
        while ( dirname( $dir ) !== $dir ) { if ( file_exists( $dir . '/.git' ) ) { return false; } $dir = dirname( $dir ); }
        return $real;
    }
    private static function ticket( $config ) {
        $identity = self::SERVICE . '|' . $config['environment'] . '|' . $config['represented_cuit'] . '|' . hash_file( 'sha256', $config['certificate_path'] );
        $path = $config['runtime_dir'] . '/wsaa-' . hash( 'sha256', $identity ) . '.json';
        // c+ plus an exclusive lock avoids two WSAA requests for the same certificate.
        if ( is_link( $path ) ) { throw new RuntimeException( 'Unsafe ticket path' ); }
        $file = fopen( $path, 'c+' ); if ( ! $file ) { throw new RuntimeException( 'Ticket cache unavailable' ); }
        chmod( $path, 0600 );
        try {
            if ( ! flock( $file, LOCK_EX | LOCK_NB ) ) { throw new RuntimeException( 'Ticket cache busy' ); }
            $cached = json_decode( stream_get_contents( $file ), true );
            if ( is_array( $cached ) && ! empty( $cached['token'] ) && ! empty( $cached['sign'] ) && (int) ( $cached['expires'] ?? 0 ) > time() + 300 ) { return $cached; }
            $ticket = self::authenticate( $config );
            rewind( $file ); ftruncate( $file, 0 ); fwrite( $file, json_encode( $ticket ) ); fflush( $file );
            return $ticket;
        } finally { flock( $file, LOCK_UN ); fclose( $file ); }
    }
    private static function authenticate( $config ) {
        if ( ! function_exists( 'openssl_pkcs7_sign' ) || ! function_exists( 'simplexml_load_string' ) ) { throw new RuntimeException( 'Authentication runtime unavailable' ); }
        $now = time();
        $request = '<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"><header><uniqueId>' . $now . '</uniqueId><generationTime>' . gmdate( 'c', $now - 300 ) . '</generationTime><expirationTime>' . gmdate( 'c', $now + 3600 ) . '</expirationTime></header><service>' . self::SERVICE . '</service></loginTicketRequest>';
        $input = tempnam( $config['runtime_dir'], 'tra-' ); $output = tempnam( $config['runtime_dir'], 'cms-' );
        if ( ! $input || ! $output ) { if ( $input ) { unlink( $input ); } if ( $output ) { unlink( $output ); } throw new RuntimeException( 'Secure temporary files unavailable' ); }
        chmod( $input, 0600 ); chmod( $output, 0600 );
        try {
            if ( false === file_put_contents( $input, $request ) ) { throw new RuntimeException( 'Cannot create login request' ); }
            $key = openssl_pkey_get_private( 'file://' . $config['private_key_path'], (string) ( $config['private_key_passphrase'] ?? '' ) );
            if ( ! $key || ! openssl_pkcs7_sign( $input, $output, 'file://' . $config['certificate_path'], $key, array(), PKCS7_BINARY | PKCS7_NOATTR ) ) { throw new RuntimeException( 'Cannot sign login request' ); }
            $mime = file_get_contents( $output );
            $parts = preg_split( '/\r?\n\r?\n/', $mime, 2 );
            $cms = isset( $parts[1] ) ? preg_replace( '/\s+/', '', $parts[1] ) : '';
            if ( ! $cms || false === base64_decode( $cms, true ) ) { throw new RuntimeException( 'Invalid CMS output' ); }
            $host = 'production' === $config['environment'] ? 'wsaa.afip.gov.ar' : 'wsaahomo.afip.gov.ar';
            $response = self::client( 'https://' . $host . '/ws/services/LoginCms?WSDL' )->__soapCall( 'loginCms', array( array( 'in0' => $cms ) ) );
            $xml = is_object( $response ) ? (string) ( $response->loginCmsReturn ?? '' ) : (string) $response;
            if ( stripos( $xml, '<!DOCTYPE' ) !== false || stripos( $xml, '<!ENTITY' ) !== false ) { throw new RuntimeException( 'Unsafe login response' ); }
            $previous = libxml_use_internal_errors( true );
            try { $ticket = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET ); }
            finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
            $expiry = $ticket ? strtotime( (string) $ticket->header->expirationTime ) : false;
            if ( ! $ticket || ! $expiry || $expiry <= time() + 300 || ! (string) $ticket->credentials->token || ! (string) $ticket->credentials->sign ) { throw new RuntimeException( 'Invalid login ticket' ); }
            return array( 'token' => (string) $ticket->credentials->token, 'sign' => (string) $ticket->credentials->sign, 'expires' => $expiry );
        } finally { unlink( $input ); unlink( $output ); }
    }
}
