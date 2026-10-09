<?php
/** Plugin Name: Graphex · Canva para tarjetas (piloto controlado) */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/ge-cards-canva/class-ge-cards-canva-client.php';
require_once __DIR__ . '/ge-cards-canva/class-ge-cards-canva-preflight.php';
final class GE_Cards_Canva {
    const OPTION = 'ge_cards_canva_pilot_enabled_v1';
    const META = '_ge_cards_canva_connection_v1';
    const NONCE = 'ge_customer_cards_design';
    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'route' ), -90 );
        add_action( 'admin_post_ge_customer_cards_design_authorize', array( __CLASS__, 'authorize' ) );
        add_action( 'admin_post_nopriv_ge_customer_cards_design_authorize', array( __CLASS__, 'deny' ) );
        foreach ( array( 'status', 'designs', 'edit', 'export', 'export_status', 'forget' ) as $op ) { add_action( 'wp_ajax_ge_customer_cards_design_' . $op, function() use ( $op ) { self::ajax( $op ); } ); add_action( 'wp_ajax_nopriv_ge_customer_cards_design_' . $op, array( __CLASS__, 'deny' ) ); }
        add_action( 'wp_ajax_ge_customer_cards_design_pdf', array( __CLASS__, 'pdf' ) );
        add_action( 'wp_ajax_nopriv_ge_customer_cards_design_pdf', array( __CLASS__, 'deny' ) );
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'panel' ), 30 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
        add_action('wp_ajax_ge_customer_cards_design_preflight',array(__CLASS__,'preflight'));
        add_action('wp_ajax_nopriv_ge_customer_cards_design_preflight',array(__CLASS__,'deny'));
        add_action('admin_post_ge_add_digital_product',array(__CLASS__,'cart_guard'),5);
        add_action('admin_post_nopriv_ge_add_digital_product',array(__CLASS__,'cart_guard'),5);
    }
    public static function deny() { wp_die( 'Ingresá a tu cuenta de Graphex para conectar Canva.', '', array( 'response' => 403 ) ); }
    public static function credentials() {
        $path = defined( 'GE_CANVA_CUSTOMER_CREDENTIAL_FILE' ) ? GE_CANVA_CUSTOMER_CREDENTIAL_FILE : '/home/graphexpress/.credentials/canva-customer.json';
        $real = realpath( $path ); $public = realpath( ABSPATH );
        if ( ! $real || ! $public || 0 === strpos( $real, $public . DIRECTORY_SEPARATOR ) || ! is_readable( $real ) || is_link( $path ) || filesize( $real ) > 8192 || ( fileperms( $real ) & 0027 ) ) { throw new RuntimeException( 'Falta la configuración segura de Canva.' ); }
        $c = json_decode( file_get_contents( $real ), true );
        $key = is_array( $c ) ? base64_decode( $c['encryption_key'] ?? '', true ) : false;
        if ( ! $key || strlen( $key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || empty( $c['client_id'] ) || empty( $c['client_secret'] ) ) { throw new RuntimeException( 'La configuración segura de Canva no es válida.' ); }
        $c['key'] = $key; return $c;
    }
    public static function available() {
        if ( 'yes' !== get_option( self::OPTION, 'no' ) || ! function_exists( 'sodium_crypto_secretbox' ) || ! class_exists( 'GE_Cards_Experience' ) || ! GE_Cards_Experience::actor_allowed() ) { return false; }
        $pilot = array_map( 'absint', (array) get_option( 'ge_cards_canva_pilot_users_v1', array() ) );
        if ( ! in_array( get_current_user_id(), $pilot, true ) ) { return false; }
        try { self::credentials(); return (bool) wp_get_session_token(); } catch ( RuntimeException $e ) { return false; }
    }
    private static function guard( $nonce = true ) {
        if ( ! self::available() ) { self::deny(); }
        if ( $nonce && ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { self::deny(); }
        if ( $nonce ) { check_ajax_referer( self::NONCE, 'nonce' ); }
    }
    public static function callback_url() { return home_url( '/tarjetas/canva/callback/' ); }
    public static function return_url() { return home_url( '/tarjetas/canva/return/' ); }
    private static function session() { return hash_hmac( 'sha256', wp_get_session_token(), wp_salt( 'auth' ) ); }
    private static function binding( $session ) { return array( 'organization' => GE_Cards_Experience::organization(), 'user' => get_current_user_id(), 'session' => $session ? self::session() : '' ); }
    public static function seal( $payload, $session = true ) {
        $c = self::credentials(); $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $json = wp_json_encode( array( 'binding' => self::binding( $session ), 'payload' => $payload ) );
        if ( strlen( $json ) > 262144 ) { throw new RuntimeException( 'La sesión Canva supera el límite permitido.' ); }
        return base64_encode( $nonce . sodium_crypto_secretbox( $json, $nonce, $c['key'] ) );
    }
    public static function open( $cipher, $session = true ) {
        if ( ! is_string( $cipher ) || strlen( $cipher ) > 400000 ) { throw new RuntimeException( 'La sesión Canva no es válida.' ); }
        $raw = base64_decode( $cipher, true ); $c = self::credentials();
        if ( ! $raw || strlen( $raw ) < 40 ) { throw new RuntimeException( 'La sesión Canva no es válida.' ); }
        $plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), $c['key'] );
        $decoded = false === $plain ? null : json_decode( $plain, true, 32 );
        if ( ! is_array( $decoded ) || ( $decoded['binding'] ?? null ) !== self::binding( $session ) ) { throw new RuntimeException( 'La sesión Canva no corresponde a esta cuenta.' ); }
        return $decoded['payload'];
    }
    private static function key( $kind, $id = '' ) { return 'ge_cc_' . $kind . '_' . hash( 'sha256', get_current_user_id() . ':' . self::session() . ':' . $id ); }
    private static function put( $kind, $id, $payload, $ttl ) { set_transient( self::key( $kind, $id ), self::seal( $payload ), $ttl ); }
    private static function get( $kind, $id = '' ) {
        $cipher = get_transient( self::key( $kind, $id ) );
        if ( ! $cipher ) { throw new RuntimeException( 'La sesión de edición o exportación venció. Volvé a intentarlo.' ); }
        return self::open( $cipher );
    }
    private static function connection() { return self::open( get_user_meta( get_current_user_id(), self::META, true ), false ); }
    private static function save_connection( $context ) { update_user_meta( get_current_user_id(), self::META, self::seal( $context, false ) ); }
    private static function client() { $c = self::credentials(); return new GE_Cards_Canva_Client( $c['client_id'], $c['client_secret'], self::callback_url(), array( __CLASS__, 'transport' ) ); }
    public static function transport( $method, $url, $headers, $body ) {
        if ( ! GE_Cards_Canva_Client::https_host( $url, array( 'api.canva.com' ) ) ) { throw new RuntimeException( 'Destino API no permitido.' ); }
        $args = array( 'method' => $method, 'headers' => $headers, 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 2097153 );
        if ( null !== $body ) { $args['body'] = $body; }
        $r = wp_safe_remote_request( $url, $args );
        if ( is_wp_error( $r ) ) { throw new RuntimeException( 'No pudimos comunicarnos con Canva. Podés descargar el PDF y subirlo manualmente.' ); }
        $code = wp_remote_retrieve_response_code( $r ); $raw = wp_remote_retrieve_body( $r );
        if ( $code < 200 || $code >= 300 || strlen( $raw ) > 2097152 ) { throw new RuntimeException( 'Canva rechazó la solicitud (HTTP ' . (int) $code . '). Revisá la conexión y permisos.', (int) $code ); }
        $json = json_decode( $raw, true, 32 );
        if ( ! is_array( $json ) ) { throw new RuntimeException( 'Respuesta Canva inválida.' ); }
        return $json;
    }
    private static function selection( $input ) {
        if ( ! is_array( $input ) || count( $input ) > 20 ) { throw new RuntimeException( 'Configuración inválida.' ); }
        foreach ( $input as $value ) { if ( ! is_scalar( $value ) || strlen( (string) $value ) > 128 ) { throw new RuntimeException( 'Configuración inválida.' ); } }
        $quote = GE_WTP_Digital_Catalog::commercial_quote_price( 83, $input );
        if ( is_wp_error( $quote ) || ( $quote['values']['tamano'] ?? '' ) !== '5x9' ) { throw new RuntimeException( 'Elegí una configuración válida de tarjetas de 5 × 9 cm.' ); }
        return array( 'product_id' => 83, 'values' => $quote['values'], 'pages' => ( $quote['values']['impresion'] ?? '' ) === 'doble' ? 2 : 1 );
    }
    public static function authorize() {
        self::guard( false ); check_admin_referer( self::NONCE );
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { self::deny(); }
        try {
            $selection = self::selection( json_decode( wp_unslash( $_POST['selection'] ?? '' ), true ) );
            $start = self::client()->start(); $flow = $start['flow']; $flow['selection'] = $selection;
            self::put( 'oauth', $flow['state'], $flow, 600 );
            nocache_headers(); header( 'Referrer-Policy: no-referrer' ); wp_redirect( $start['url'] ); exit;
        } catch ( RuntimeException $e ) { self::fail( 'connect' ); }
    }
    private static function fail( $status ) { nocache_headers(); header( 'Referrer-Policy: no-referrer' ); wp_safe_redirect( add_query_arg( 'canva_status', $status, get_permalink( 83 ) ) ); exit; }
    public static function route() {
        $path = GE_Cards_Experience::path();
        if ( ! in_array( $path, array( '/tarjetas/canva/callback/', '/tarjetas/canva/return/' ), true ) ) { return; }
        self::guard( false ); nocache_headers(); header( 'Referrer-Policy: no-referrer' ); header( 'X-Robots-Tag: noindex, nofollow' );
        try {
            $client = self::client();
            if ( '/tarjetas/canva/callback/' === $path ) {
                $state = GE_Cards_Canva_Client::id( $_GET['state'] ?? '' );
                $flow = self::get( 'oauth', $state );
                if ( ! add_option( 'ge_cc_used_' . hash( 'sha256', 'oauth:' . $state ), time() + 600, '', false ) ) { throw new RuntimeException( 'Esta autorización ya fue utilizada.' ); }
                delete_transient( self::key( 'oauth', $state ) );
                if ( ! empty( $_GET['error'] ) ) { self::fail( 'denied' ); }
                $context = $client->callback( $flow, $_GET['state'] ?? '', $_GET['code'] ?? '' ); $context['connection_id'] = GE_Cards_Canva_Client::random( 24 ); self::save_connection( $context );
                self::put( 'selection', '', $flow['selection'], 86400 ); self::fail( 'connected' );
            }
            $jwt = $_GET['correlation_jwt'] ?? ''; if ( ! is_string( $jwt ) || strlen( $jwt ) > 16384 ) { throw new RuntimeException( 'Regreso inválido.' ); }
            $parts = explode( '.', $jwt ); $peek = count( $parts ) === 3 ? json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ), true ), true, 8 ) : null;
            $correlation = is_array( $peek ) ? ( $peek['correlation_state'] ?? '' ) : '';
            if ( ! is_string( $correlation ) || ! preg_match( '/^[A-Za-z0-9_-]{32}$/D', $correlation ) ) { throw new RuntimeException( 'Regreso inválido.' ); }
            // Unverified claim is used only to look up this user's encrypted session;
            // all decisions depend on the signature and bound original state below.
            $pending = self::get( 'edit', $correlation ); $context = self::connection();
            if ( ( $pending['connection_id'] ?? '' ) !== ( $context['connection_id'] ?? null ) ) { throw new RuntimeException( 'La conexión original cambió.' ); }
            $keys = get_transient( 'ge_cards_canva_public_keys_v1' );
            if ( ! is_array( $keys ) ) { $keys = self::transport( 'GET', GE_Cards_Canva_Client::API . '/connect/keys', array(), null ); set_transient( 'ge_cards_canva_public_keys_v1', $keys, 300 ); }
            $verified = $client->verify_return( $context, $jwt, $keys, $pending );
            $used = 'ge_cc_used_' . hash( 'sha256', $verified['jti'] );
            if ( ! add_option( $used, $verified['expires'], '', false ) ) { throw new RuntimeException( 'Regreso ya utilizado.' ); }
            delete_transient( self::key( 'edit', $correlation ) ); self::save_connection( $context );
            self::put( 'returned', '', array( 'design_id' => $verified['design_id'], 'selection' => $pending['selection'], 'connection_id' => $context['connection_id'] ), 600 );
            self::fail( 'returned' );
        } catch ( RuntimeException $e ) { self::fail( 'invalid_return' ); }
    }
    public static function ajax( $op ) {
        self::guard();
        try {
            $client = self::client();
            if ( 'forget' === $op ) { delete_user_meta( get_current_user_id(), self::META ); wp_send_json_success( array( 'connected' => false ) ); }
            if ( 'status' === $op ) {
                $connected = false; try { $context = self::connection(); $connected = true; } catch ( RuntimeException $e ) {}
                $returned = null; if ( $connected ) { try { $r = self::get( 'returned' ); if ( $r['connection_id'] === $context['connection_id'] ) { $returned = array( 'design_id' => $r['design_id'], 'selection' => $r['selection'] ); } } catch ( RuntimeException $e ) {} }
                wp_send_json_success( array( 'connected' => $connected, 'returned' => $returned ) );
            }
            $context = self::connection();
            if ( 'designs' === $op ) { $result = $client->designs( $context, wp_unslash( $_POST['continuation'] ?? '' ) ); }
            elseif ( 'edit' === $op ) {
                $selection = self::selection( json_decode( wp_unslash( $_POST['selection'] ?? '' ), true ) );
                $edit = $client->edit( $context, wp_unslash( $_POST['design_id'] ?? '' ) ); $pending = $edit['pending']; $pending['selection'] = $selection; $pending['connection_id'] = $context['connection_id'];
                self::put( 'edit', $pending['correlation_state'], $pending, 86400 ); $result = array( 'url' => $edit['url'] );
            } elseif ( 'export' === $op ) {
                $selection = self::selection( json_decode( wp_unslash( $_POST['selection'] ?? '' ), true ) );
                $design_id = GE_Cards_Canva_Client::id(wp_unslash($_POST['design_id'] ?? ''));
                $request_id = GE_Cards_Canva_Client::id(wp_unslash($_POST['request_id'] ?? GE_Cards_Canva_Client::random(24)));
                $cache_key = self::key('export_request',$context['connection_id'].':'.$request_id);
                $existing = get_transient($cache_key);
                if ($existing) {
                    $cached=self::open($existing);
                    if ($cached['selection']!==$selection || $cached['design_id']!==$design_id) { throw new RuntimeException('El reintento no corresponde al mismo diseño y configuración.'); }
                    $previous=self::get('job',$cached['job']);
                    if ($previous['expires']<time()) { throw new RuntimeException('La exportación anterior venció. Iniciá otra importación.'); }
                    wp_send_json_success(array('job'=>$cached['job'],'status'=>'in_progress'));
                }
                $job = $client->export( $context, $design_id, $selection['pages'] ); $job['connection_id'] = $context['connection_id']; $job['selection'] = $selection; $id = GE_Cards_Canva_Client::random( 24 );
                self::put( 'job', $id, $job, 600 );
                set_transient($cache_key,self::seal(array('job'=>$id,'selection'=>$selection,'design_id'=>$design_id)),600);
                delete_transient( self::key( 'returned' ) );
                $result = array( 'job' => $id, 'status' => 'in_progress' );
            } elseif ( 'export_status' === $op ) {
                $id = GE_Cards_Canva_Client::id( $_POST['job'] ?? '' ); $job = self::get( 'job', $id ); if ( $job['connection_id'] !== $context['connection_id'] ) { throw new RuntimeException( 'La conexión original cambió.' ); } $poll = $client->poll( $context, $job );
                self::put( 'job', $id, $job, max( 1, $job['expires'] - time() ) ); $result = array( 'status' => $poll['status'] );
            } else { throw new RuntimeException( 'Acción desconocida.' ); }
            self::save_connection( $context ); wp_send_json_success( $result );
        } catch ( RuntimeException $e ) { if ( $e->getCode() === 401 ) { delete_user_meta( get_current_user_id(), self::META ); } wp_send_json_error( array( 'message' => $e->getMessage() ), 422 ); }
    }
    public static function pdf() {
        self::guard();
        try {
            $job = self::get( 'job', GE_Cards_Canva_Client::id( $_POST['job'] ?? '' ) );
            $context = self::connection(); if ( $job['connection_id'] !== $context['connection_id'] ) { throw new RuntimeException( 'La conexión original cambió.' ); }
            if ( $job['expires'] < time() || ( $job['result']['status'] ?? '' ) !== 'ready' || ! GE_Cards_Canva_Client::https_host( $job['result']['url'] ?? '', array( 'export-download.canva.com', 'document-export.canva.com' ) ) ) { throw new RuntimeException( 'La exportación no está disponible.' ); }
            $r = wp_safe_remote_get( $job['result']['url'], array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 20971521 ) );
            if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { throw new RuntimeException( 'No pudimos descargar el PDF de Canva.' ); }
            $bytes = wp_remote_retrieve_body( $r );
            if ( strlen( $bytes ) > 20971520 || substr( $bytes, 0, 5 ) !== '%PDF-' ) { throw new RuntimeException( 'La exportación debe ser un PDF válido de hasta 20 MB.' ); }
            $job['pdf_sha256']=hash('sha256',$bytes); $job['expires']=time()+1800;
            self::put('job',GE_Cards_Canva_Client::id($_POST['job'] ?? ''),$job,1800);
            nocache_headers(); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: application/pdf' ); header( 'Content-Disposition: attachment; filename="canva-' . $job['design_id'] . '.pdf"' ); header( 'Referrer-Policy: no-referrer' ); echo $bytes; exit;
        } catch ( RuntimeException $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 422 ); }
    }
    private static function checked_file($id, $claims, $input) {
        $job = self::get('job', GE_Cards_Canva_Client::id($id));
        $context = self::connection();
        $selection = self::selection($input);
        if (($job['connection_id'] ?? '') !== $context['connection_id'] || ($job['expires'] ?? 0)<time() || empty($job['pdf_sha256']) || ($job['selection'] ?? null)!==$selection) { throw new RuntimeException('El archivo o su configuración cambiaron. Volvé a importar desde Canva.'); }
        if (!class_exists('GE_WTP_VPS_Storage') || !class_exists('GE_WTP_File_Analysis') || !is_array($claims) || count($claims)!==1 || !is_array($claims[0]) || !is_string($claims[0]['token'] ?? null)) { throw new RuntimeException('Falta completar la carga privada del PDF.'); }
        $files = GE_WTP_VPS_Storage::validate_uploaded_claims(array($claims[0]['token']),get_current_user_id());
        if (is_wp_error($files) || count($files)!==1 || $files[0]['mime']!=='application/pdf') { throw new RuntimeException('El PDF no está disponible para esta cuenta. Volvé a subirlo.'); }
        $root=defined('GE_WTP_PRIVATE_UPLOAD_DIR') ? GE_WTP_PRIVATE_UPLOAD_DIR : getenv('GE_WTP_PRIVATE_UPLOAD_DIR');
        $base=$root ? realpath($root) : false;
        $path=$base ? GE_WTP_File_Analysis::safe_path($base.DIRECTORY_SEPARATOR.$files[0]['relative_path']) : '';
        if (!$path || strpos($path,$base.DIRECTORY_SEPARATOR)!==0 || filesize($path)>20971520 || !hash_equals($job['pdf_sha256'],hash_file('sha256',$path))) { throw new RuntimeException('El archivo privado no coincide con el PDF exportado desde Canva.'); }
        $analysis=GE_WTP_File_Analysis::ingest($path,'application/pdf','technical','canva-cards-v1');
        $row=GE_WTP_File_Analysis::from_ref($analysis['file_analysis_ref'] ?? '');
        if (!$row || ($row['sha256'] ?? '')!==$job['pdf_sha256']) { throw new RuntimeException('La revisión técnica no pudo iniciarse. Reintentá.'); }
        return GE_Cards_Canva_Preflight::evaluate($row,$selection['pages']);
    }
    public static function preflight() {
        self::guard();
        try {
            $result=self::checked_file($_POST['job'] ?? '',json_decode(wp_unslash($_POST['claims'] ?? ''),true),json_decode(wp_unslash($_POST['selection'] ?? ''),true));
            wp_send_json_success($result);
        } catch (RuntimeException $e) { wp_send_json_error(array('message'=>$e->getMessage()),422); }
    }
    public static function cart_guard() {
        if (empty($_POST['ge_canva_job'])) { return; }
        try {
            self::guard(false);
            if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST' || (int)($_POST['product_id'] ?? 0)!==83 || !wp_verify_nonce(wp_unslash($_POST['ge_digital_nonce'] ?? ''),'ge_add_digital_product_83')) { throw new RuntimeException('Ingresá de nuevo al producto para validar la configuración.'); }
            $result=self::checked_file(wp_unslash($_POST['ge_canva_job']),json_decode(wp_unslash($_POST['ge_vps_uploads'] ?? ''),true),(array)wp_unslash($_POST['ge_digital'] ?? array()));
            if ($result['status']==='pending') { throw new RuntimeException('El PDF todavía se está analizando. Esperá la revisión antes de agregarlo al carrito.'); }
            if ($result['status']==='blocked') { throw new RuntimeException('El PDF tiene errores técnicos. Corregí las advertencias e importalo de nuevo.'); }
            // The existing cart associates the same validated private claim with its item.
            // Production approval remains a separate customer proof workflow.
        } catch (RuntimeException $e) { wc_add_notice($e->getMessage(),'error'); wp_safe_redirect(get_permalink(83)); exit; }
    }
    public static function assets() {
        if ( ! self::available() || ! function_exists( 'is_product' ) || ! is_product() || (int) get_queried_object_id() !== 83 ) { return; }
        wp_enqueue_style('ge-cards-canva',content_url('/mu-plugins/ge-cards-canva/customer.css'),array('ge-cards-experience'),(string)filemtime(__DIR__.'/ge-cards-canva/customer.css'));
        wp_enqueue_script( 'ge-cards-canva', content_url( '/mu-plugins/ge-cards-canva/customer.js' ), array( 'ge-cards-product-preview' ), (string) filemtime( __DIR__ . '/ge-cards-canva/customer.js' ), true );
        wp_localize_script( 'ge-cards-canva', 'geCardsCanva', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::NONCE ) ) );
    }
    public static function panel() {
        if ( ! self::available() || ! is_product() || (int) get_queried_object_id() !== 83 ) { return; }
        include __DIR__ . '/ge-cards-canva/panel.php';
    }
}
GE_Cards_Canva::init();
