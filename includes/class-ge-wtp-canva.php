<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canva Connect integration for the staff artwork library.
 * Credentials live in WordPress options and OAuth tokens are scoped per user.
 */
final class GE_WTP_Canva {
    const OPTION = 'ge_wtp_canva';
    const TOKEN_META = '_ge_canva_tokens';
    const API_BASE = 'https://api.canva.com/rest/v1';

    public static function init() {
        add_action( 'admin_post_ge_save_canva', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_ge_canva_connect', array( __CLASS__, 'start_oauth' ) );
        add_action( 'admin_post_ge_canva_callback', array( __CLASS__, 'oauth_callback' ) );
        add_action( 'admin_post_ge_canva_disconnect', array( __CLASS__, 'disconnect' ) );
        add_action( 'admin_post_ge_canva_open_design', array( __CLASS__, 'open_design' ) );
        add_action( 'wp_ajax_ge_canva_create_design', array( __CLASS__, 'ajax_create_design' ) );
        add_action( 'wp_ajax_ge_canva_export_start', array( __CLASS__, 'ajax_export_start' ) );
        add_action( 'wp_ajax_ge_canva_export_status', array( __CLASS__, 'ajax_export_status' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 36 );
    }

    public static function defaults() { return array( 'enabled' => 'no', 'client_id' => '', 'client_secret' => '' ); }
    public static function settings() { return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() ); }
    public static function configured() { $s = self::settings(); return 'yes' === $s['enabled'] && '' !== $s['client_id'] && '' !== $s['client_secret']; }
    public static function callback_url() { return admin_url( 'admin-post.php?action=ge_canva_callback' ); }
    public static function scopes() { return 'design:content:read design:content:write design:meta:read profile:read'; }

    public static function save_settings() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        check_admin_referer( 'ge_save_canva' );
        $current = self::settings();
        $client_id = isset( $_POST['client_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) ) : '';
        $secret = isset( $_POST['client_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) ) : '';
        if ( '' === $secret ) { $secret = $current['client_secret']; }
        update_option( self::OPTION, array(
            'enabled' => empty( $_POST['enabled'] ) ? 'no' : 'yes',
            'client_id' => $client_id,
            'client_secret' => $secret,
        ), false );
        self::redirect_settings( ( ! empty( $_POST['enabled'] ) && ( ! $client_id || ! $secret ) ) ? 'missing' : 'saved' );
    }

    public static function render_settings() {
        $s = self::settings();
        $connected = self::connected();
        $status = isset( $_GET['canva_status'] ) ? sanitize_key( wp_unslash( $_GET['canva_status'] ) ) : '';
        $messages = array(
            'saved' => 'La configuración de Canva se guardó.',
            'missing' => 'Para activar Canva completá el Client ID y el Client Secret.',
            'connected' => 'Canva quedó conectado correctamente.',
            'disconnected' => 'La cuenta de Canva se desconectó.',
            'denied' => 'Canva no autorizó la conexión o fue cancelada.',
            'failed' => 'No pudimos completar la conexión con Canva. Revisá las credenciales y la URL de retorno.',
        );
        if ( isset( $messages[ $status ] ) ) { echo '<div class="ge-production-notice ' . ( in_array( $status, array( 'missing', 'denied', 'failed' ), true ) ? 'is-error' : '' ) . '">' . esc_html( $messages[ $status ] ) . '</div>'; }
        ?>
        <section class="ge-integration-card ge-canva-card <?php echo $connected ? 'is-connected' : ''; ?>">
            <div class="ge-integration-head"><b class="ge-canva-mark">Canva</b><div><span>Diseño conectado</span><h3>Canva Connect</h3><p>Crear diseños con medidas de producción y traer el PDF a la biblioteca.</p></div><strong><?php echo $connected ? 'Cuenta conectada' : ( self::configured() ? 'Lista para conectar' : 'Pendiente de configurar' ); ?></strong></div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_save_canva"><?php wp_nonce_field( 'ge_save_canva' ); ?>
                <label class="ge-integration-toggle"><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'], 'yes' ); ?>><span><strong>Habilitar Canva</strong><small>Muestra las herramientas de diseño en la Biblioteca.</small></span></label>
                <div class="ge-drive-fields"><label class="ge-integration-field">Client ID<input type="text" name="client_id" value="<?php echo esc_attr( $s['client_id'] ); ?>" autocomplete="off" placeholder="ID de la integración"><small>Se obtiene en Canva Developers.</small></label><label class="ge-integration-field">Client Secret<input type="password" name="client_secret" value="" autocomplete="new-password" placeholder="<?php echo $s['client_secret'] ? 'Guardado · dejá vacío para conservarlo' : 'cnvca…'; ?>"><small>Nunca se envía al navegador ni se guarda en Git.</small></label></div>
                <div class="ge-google-setup"><h4>Datos para Canva Developers</h4><dl><div><dt>URL de retorno local</dt><dd><code><?php echo esc_html( self::callback_url() ); ?></code></dd></div><div><dt>URL pública futura</dt><dd><code>https://graphexpress.com.ar/wp-admin/admin-post.php?action=ge_canva_callback</code></dd></div><div><dt>Permisos</dt><dd><code><?php echo esc_html( self::scopes() ); ?></code></dd></div></dl><p>Canva exige OAuth 2.0 con PKCE. El sistema genera y valida el estado y el verificador en el servidor.</p></div>
                <div class="ge-notification-actions"><button class="ge-staff-button" type="submit">Guardar Canva</button><?php if ( self::configured() && ! $connected ) : ?><a class="ge-staff-button is-secondary" href="<?php echo esc_url( self::connect_url() ); ?>">Conectar cuenta de Canva</a><?php elseif ( $connected ) : ?><a class="ge-staff-button is-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ge_canva_disconnect' ), 'ge_canva_disconnect' ) ); ?>">Desconectar</a><?php endif; ?></div>
            </form>
        </section>
        <?php
    }

    public static function render_library_panel( $artwork_id = 0 ) {
        $design = $artwork_id ? get_post_meta( $artwork_id, '_ge_canva_design', true ) : array();
        $design = is_array( $design ) ? $design : array();
        $guide = class_exists( 'GE_WTP_Knowledge_Base' ) ? GE_WTP_Knowledge_Base::guide_url( 'como-disenar-en-canva-para-imprimir' ) : home_url( '/guias/' );
        ?>
        <section class="ge-canva-library" data-artwork-id="<?php echo esc_attr( $artwork_id ); ?>">
            <div class="ge-canva-library-head"><b>CA</b><span><strong>Diseñar con Canva</strong><small>Creá el documento, editalo en Canva y traé el PDF a esta ficha.</small></span><a href="<?php echo esc_url( $guide ); ?>" target="_blank" rel="noopener">Cómo funciona ↗</a></div>
            <?php if ( ! self::configured() ) : ?><p class="ge-canva-message">Canva todavía no está configurado. Completalo en <a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'settings', array( 'category' => 'integrations' ) ) ); ?>">Integraciones</a>.</p>
            <?php elseif ( ! self::connected() ) : ?><p class="ge-canva-message">Primero conectá una cuenta de Canva. <a href="<?php echo esc_url( self::connect_url() ); ?>">Conectar ahora</a></p>
            <?php else : ?>
                <div class="ge-canva-size"><label>Ancho<input type="number" min="1" max="8000" step="0.1" data-canva-width value="<?php echo esc_attr( isset( $design['physical_width'] ) ? $design['physical_width'] : '' ); ?>"></label><label>Alto<input type="number" min="1" max="8000" step="0.1" data-canva-height value="<?php echo esc_attr( isset( $design['physical_height'] ) ? $design['physical_height'] : '' ); ?>"></label><label>Unidad<select data-canva-unit><option value="cm" <?php selected( isset( $design['unit'] ) ? $design['unit'] : 'cm', 'cm' ); ?>>cm</option><option value="mm" <?php selected( isset( $design['unit'] ) ? $design['unit'] : '', 'mm' ); ?>>mm</option><option value="px" <?php selected( isset( $design['unit'] ) ? $design['unit'] : '', 'px' ); ?>>px</option></select></label><button type="button" class="ge-staff-button" data-canva-create><?php echo ! empty( $design['id'] ) ? 'Crear otro diseño' : 'Crear diseño'; ?></button></div>
                <p class="ge-canva-hint">Conservamos la proporción y usamos la mayor resolución admitida por Canva. La medida física queda registrada para el control de impresión.</p>
                <div class="ge-canva-current <?php echo empty( $design['id'] ) ? 'is-empty' : ''; ?>" data-canva-current><span><?php echo empty( $design['id'] ) ? 'Todavía no hay un diseño vinculado.' : '<strong>' . esc_html( $design['title'] ?? 'Diseño en Canva' ) . '</strong><small>ID: ' . esc_html( $design['id'] ) . '</small>'; ?></span><?php if ( $artwork_id && ! empty( $design['id'] ) ) : ?><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ge_canva_open_design&artwork_id=' . $artwork_id ), 'ge_canva_open_' . $artwork_id ) ); ?>" target="_blank" rel="noopener">Editar en Canva ↗</a><button type="button" data-canva-export>Traer PDF</button><?php elseif ( ! empty( $design['edit_url'] ) ) : ?><a href="<?php echo esc_url( $design['edit_url'] ); ?>" target="_blank" rel="noopener">Editar en Canva ↗</a><?php endif; ?></div>
                <div class="ge-canva-status" data-canva-status aria-live="polite"></div>
                <input type="hidden" name="canva_design_id" value="<?php echo esc_attr( $design['id'] ?? '' ); ?>"><input type="hidden" name="canva_title" value="<?php echo esc_attr( $design['title'] ?? '' ); ?>"><input type="hidden" name="canva_edit_url" value="<?php echo esc_attr( $design['edit_url'] ?? '' ); ?>"><input type="hidden" name="canva_view_url" value="<?php echo esc_attr( $design['view_url'] ?? '' ); ?>"><input type="hidden" name="canva_physical_width" value="<?php echo esc_attr( $design['physical_width'] ?? '' ); ?>"><input type="hidden" name="canva_physical_height" value="<?php echo esc_attr( $design['physical_height'] ?? '' ); ?>"><input type="hidden" name="canva_unit" value="<?php echo esc_attr( $design['unit'] ?? '' ); ?>"><input type="hidden" name="canva_pixel_width" value="<?php echo esc_attr( $design['pixel_width'] ?? '' ); ?>"><input type="hidden" name="canva_pixel_height" value="<?php echo esc_attr( $design['pixel_height'] ?? '' ); ?>">
            <?php endif; ?>
        </section>
        <?php
    }

    public static function assets() {
        if ( ! is_page( 'gestion' ) ) { return; }
        wp_enqueue_style( 'ge-canva', GE_WTP_PLUGIN_URL . 'assets/css/canva.css', array( 'ge-artwork-library' ), GE_WTP_VERSION );
        wp_enqueue_script( 'ge-canva', GE_WTP_PLUGIN_URL . 'assets/js/canva.js', array(), GE_WTP_VERSION, true );
        wp_localize_script( 'ge-canva', 'geCanva', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ge_canva_ajax' ) ) );
    }

    public static function connect_url() { return wp_nonce_url( admin_url( 'admin-post.php?action=ge_canva_connect' ), 'ge_canva_connect' ); }

    public static function start_oauth() {
        if ( ! GE_WTP_Staff_Portal::can_access() || ! self::configured() ) { wp_die( 'Canva no está configurado.', 403 ); }
        check_admin_referer( 'ge_canva_connect' );
        $state = self::random_urlsafe( 48 ); $verifier = self::random_urlsafe( 72 );
        $challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
        set_transient( 'ge_canva_oauth_' . hash( 'sha256', $state ), array( 'user_id' => get_current_user_id(), 'verifier' => $verifier ), 15 * MINUTE_IN_SECONDS );
        $s = self::settings();
        $url = add_query_arg( array( 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => self::scopes(), 'response_type' => 'code', 'client_id' => $s['client_id'], 'state' => $state, 'redirect_uri' => self::callback_url() ), 'https://www.canva.com/api/oauth/authorize' );
        wp_redirect( $url ); exit; // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
    }

    public static function oauth_callback() {
        if ( ! is_user_logged_in() || ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Ingresá nuevamente para conectar Canva.', 403 ); }
        if ( ! empty( $_GET['error'] ) ) { self::redirect_settings( 'denied' ); }
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        $key = 'ge_canva_oauth_' . hash( 'sha256', $state ); $flow = get_transient( $key ); delete_transient( $key );
        if ( ! $state || ! $code || ! is_array( $flow ) || (int) $flow['user_id'] !== get_current_user_id() ) { self::redirect_settings( 'failed' ); }
        $tokens = self::token_request( array( 'grant_type' => 'authorization_code', 'code_verifier' => $flow['verifier'], 'code' => $code, 'redirect_uri' => self::callback_url() ) );
        if ( is_wp_error( $tokens ) || empty( $tokens['access_token'] ) || empty( $tokens['refresh_token'] ) ) { self::redirect_settings( 'failed' ); }
        self::save_tokens( get_current_user_id(), $tokens );
        self::redirect_settings( 'connected' );
    }

    public static function disconnect() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        check_admin_referer( 'ge_canva_disconnect' ); delete_user_meta( get_current_user_id(), self::TOKEN_META ); self::redirect_settings( 'disconnected' );
    }

    public static function open_design() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $artwork_id = absint( $_GET['artwork_id'] ?? 0 ); check_admin_referer( 'ge_canva_open_' . $artwork_id );
        $saved = get_post_meta( $artwork_id, '_ge_canva_design', true );
        if ( ! is_array( $saved ) || empty( $saved['id'] ) ) { wp_die( 'No hay un diseño de Canva vinculado.', 404 ); }
        $response = self::api( 'GET', '/designs/' . rawurlencode( $saved['id'] ) );
        $edit_url = is_array( $response ) && ! empty( $response['design']['urls']['edit_url'] ) ? $response['design']['urls']['edit_url'] : ( $saved['edit_url'] ?? '' );
        $host = $edit_url ? wp_parse_url( $edit_url, PHP_URL_HOST ) : '';
        if ( ! $edit_url || ( 'www.canva.com' !== $host && 'canva.com' !== $host ) ) { wp_die( 'Canva no devolvió un enlace de edición válido.', 502 ); }
        $saved['edit_url'] = $edit_url; $saved['view_url'] = $response['design']['urls']['view_url'] ?? ( $saved['view_url'] ?? '' ); update_post_meta( $artwork_id, '_ge_canva_design', $saved );
        wp_redirect( $edit_url ); exit; // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
    }

    public static function connected( $user_id = 0 ) { $tokens = get_user_meta( $user_id ?: get_current_user_id(), self::TOKEN_META, true ); return is_array( $tokens ) && ( ! empty( $tokens['access_token'] ) || ! empty( $tokens['refresh_token'] ) ); }

    public static function ajax_create_design() {
        self::ajax_guard();
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : 'Diseño Graph Express';
        $width = isset( $_POST['width'] ) ? (float) $_POST['width'] : 0; $height = isset( $_POST['height'] ) ? (float) $_POST['height'] : 0;
        $unit = isset( $_POST['unit'] ) ? sanitize_key( $_POST['unit'] ) : 'cm';
        if ( $width <= 0 || $height <= 0 || ! in_array( $unit, array( 'cm', 'mm', 'px' ), true ) ) { wp_send_json_error( array( 'message' => 'Ingresá ancho, alto y unidad.' ), 400 ); }
        list( $pixel_width, $pixel_height ) = self::pixel_dimensions( $width, $height, $unit );
        if ( $pixel_width < 40 || $pixel_height < 40 ) { wp_send_json_error( array( 'message' => 'La medida es demasiado pequeña para Canva.' ), 400 ); }
        $response = self::api( 'POST', '/designs', array( 'type' => 'type_and_asset', 'design_type' => array( 'type' => 'custom', 'width' => $pixel_width, 'height' => $pixel_height ), 'title' => mb_substr( $title ?: 'Diseño Graph Express', 0, 255 ) ) );
        if ( is_wp_error( $response ) || empty( $response['design']['id'] ) ) { wp_send_json_error( array( 'message' => is_wp_error( $response ) ? $response->get_error_message() : 'Canva no devolvió el diseño.' ), 502 ); }
        $d = $response['design'];
        wp_send_json_success( array( 'id' => $d['id'], 'title' => $d['title'] ?? $title, 'edit_url' => $d['urls']['edit_url'] ?? '', 'view_url' => $d['urls']['view_url'] ?? '', 'physical_width' => $width, 'physical_height' => $height, 'unit' => $unit, 'pixel_width' => $pixel_width, 'pixel_height' => $pixel_height ) );
    }

    public static function ajax_export_start() {
        self::ajax_guard(); $artwork_id = absint( $_POST['artwork_id'] ?? 0 );
        if ( ! $artwork_id || GE_WTP_Artwork_Library::POST_TYPE !== get_post_type( $artwork_id ) ) { wp_send_json_error( array( 'message' => 'Primero guardá la ficha.' ), 400 ); }
        $design = get_post_meta( $artwork_id, '_ge_canva_design', true );
        if ( empty( $design['id'] ) ) { wp_send_json_error( array( 'message' => 'No hay un diseño de Canva vinculado.' ), 400 ); }
        $response = self::api( 'POST', '/exports', array( 'design_id' => $design['id'], 'format' => array( 'type' => 'pdf', 'export_quality' => 'regular' ) ) );
        if ( is_wp_error( $response ) || empty( $response['job']['id'] ) ) { wp_send_json_error( array( 'message' => is_wp_error( $response ) ? $response->get_error_message() : 'No se pudo iniciar la exportación.' ), 502 ); }
        wp_send_json_success( array( 'job_id' => $response['job']['id'] ) );
    }

    public static function ajax_export_status() {
        self::ajax_guard(); $artwork_id = absint( $_POST['artwork_id'] ?? 0 ); $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( ! $artwork_id || ! $job_id ) { wp_send_json_error( array( 'message' => 'Exportación inválida.' ), 400 ); }
        $response = self::api( 'GET', '/exports/' . rawurlencode( $job_id ) );
        if ( is_wp_error( $response ) || empty( $response['job']['status'] ) ) { wp_send_json_error( array( 'message' => is_wp_error( $response ) ? $response->get_error_message() : 'No se pudo consultar la exportación.' ), 502 ); }
        $job = $response['job'];
        if ( 'failed' === $job['status'] ) { wp_send_json_error( array( 'message' => $job['error']['message'] ?? 'Canva no pudo exportar el diseño.' ), 422 ); }
        if ( 'success' === $job['status'] && ! empty( $job['urls'][0] ) ) {
            $stored = GE_WTP_Artwork_Library::store_canva_export( $artwork_id, $job['urls'][0] );
            if ( is_wp_error( $stored ) ) { wp_send_json_error( array( 'message' => $stored->get_error_message() ), 500 ); }
            wp_send_json_success( array( 'status' => 'success', 'message' => 'PDF guardado en la ficha y marcado para revisión.' ) );
        }
        wp_send_json_success( array( 'status' => 'in_progress' ) );
    }

    private static function ajax_guard() { if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_send_json_error( array( 'message' => 'Acceso denegado.' ), 403 ); } check_ajax_referer( 'ge_canva_ajax', 'nonce' ); }

    private static function pixel_dimensions( $width, $height, $unit ) {
        if ( 'px' === $unit ) { $w = $width; $h = $height; } else { $inches = 'mm' === $unit ? 25.4 : 2.54; $w = $width / $inches * 150; $h = $height / $inches * 150; }
        $scale = min( 1, 8000 / max( $w, $h ), sqrt( 25000000 / max( 1, $w * $h ) ) );
        return array( max( 40, (int) round( $w * $scale ) ), max( 40, (int) round( $h * $scale ) ) );
    }

    private static function api( $method, $path, $body = null ) {
        $token = self::access_token(); if ( is_wp_error( $token ) ) { return $token; }
        $args = array( 'method' => $method, 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ) );
        if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }
        $response = wp_remote_request( self::API_BASE . $path, $args );
        if ( is_wp_error( $response ) ) { return $response; }
        $data = json_decode( wp_remote_retrieve_body( $response ), true ); $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'canva_api', isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : 'Canva respondió con un error.' ); }
        return is_array( $data ) ? $data : array();
    }

    private static function access_token() {
        $user_id = get_current_user_id(); $tokens = get_user_meta( $user_id, self::TOKEN_META, true );
        if ( ! is_array( $tokens ) ) { return new WP_Error( 'canva_not_connected', 'Conectá tu cuenta de Canva desde Integraciones.' ); }
        if ( ! empty( $tokens['access_token'] ) && (int) ( $tokens['expires_at'] ?? 0 ) > time() + 120 ) { return $tokens['access_token']; }
        if ( empty( $tokens['refresh_token'] ) ) { return new WP_Error( 'canva_expired', 'La conexión de Canva venció. Volvé a conectarla.' ); }
        $fresh = self::token_request( array( 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'] ) );
        if ( is_wp_error( $fresh ) || empty( $fresh['access_token'] ) ) { delete_user_meta( $user_id, self::TOKEN_META ); return new WP_Error( 'canva_refresh', 'La conexión de Canva venció. Volvé a conectarla.' ); }
        self::save_tokens( $user_id, $fresh ); return $fresh['access_token'];
    }

    private static function token_request( $body ) {
        $s = self::settings(); $response = wp_remote_post( self::API_BASE . '/oauth/token', array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $s['client_id'] . ':' . $s['client_secret'] ), 'Content-Type' => 'application/x-www-form-urlencoded' ), 'body' => $body ) );
        if ( is_wp_error( $response ) ) { return $response; } $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'canva_token', isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : 'No se pudo obtener el acceso de Canva.' ); }
        return is_array( $data ) ? $data : array();
    }

    private static function save_tokens( $user_id, $tokens ) {
        update_user_meta( $user_id, self::TOKEN_META, array( 'access_token' => sanitize_text_field( $tokens['access_token'] ), 'refresh_token' => sanitize_text_field( $tokens['refresh_token'] ), 'expires_at' => time() + absint( $tokens['expires_in'] ?? 14400 ), 'scope' => sanitize_text_field( $tokens['scope'] ?? '' ) ) );
    }
    private static function random_urlsafe( $bytes ) { return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); }
    private static function redirect_settings( $status ) { wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'settings', array( 'category' => 'integrations', 'canva_status' => $status ) ) ); exit; }
}
