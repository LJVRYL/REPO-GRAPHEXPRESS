<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Google_Auth {
    const OPTION = 'ge_wtp_google_auth';
    const META_SUB = '_ge_google_sub';
    private static $loader_rendered = false;
    private static $notice_rendered = false;

    public static function init() {
        add_action( 'admin_post_ge_save_google_auth', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_nopriv_ge_google_auth', array( __CLASS__, 'handle_callback' ) );
        add_action( 'admin_post_ge_google_auth', array( __CLASS__, 'handle_callback' ) );
        add_action( 'woocommerce_login_form_end', array( __CLASS__, 'render_login_button' ) );
        add_action( 'woocommerce_register_form_end', array( __CLASS__, 'render_register_button' ) );
        add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_account_connection' ), 35 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 35 );
    }

    public static function defaults() { return array( 'enabled' => 'no', 'client_id' => '', 'drive_enabled' => 'no', 'drive_api_key' => '', 'drive_app_id' => '' ); }
    public static function settings() { return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() ); }
    public static function enabled() { $settings = self::settings(); return 'yes' === $settings['enabled'] && self::valid_client_id( $settings['client_id'] ); }
    public static function drive_enabled() { $settings = self::settings(); return 'yes' === $settings['drive_enabled'] && self::valid_client_id( $settings['client_id'] ) && self::valid_api_key( $settings['drive_api_key'] ) && self::valid_app_id( $settings['drive_app_id'] ); }

    private static function valid_client_id( $client_id ) {
        return (bool) preg_match( '/^[0-9]+-[a-zA-Z0-9_-]+\.apps\.googleusercontent\.com$/', (string) $client_id );
    }

    private static function valid_api_key( $api_key ) { return (bool) preg_match( '/^AIza[a-zA-Z0-9_-]{30,}$/', (string) $api_key ); }
    private static function valid_app_id( $app_id ) { return (bool) preg_match( '/^[0-9]{6,30}$/', (string) $app_id ); }

    public static function callback_url() { return admin_url( 'admin-post.php?action=ge_google_auth' ); }

    public static function save_settings() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        check_admin_referer( 'ge_save_google_auth' );
        $client_id = isset( $_POST['client_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) ) : '';
        $enabled = ! empty( $_POST['enabled'] ) ? 'yes' : 'no';
        $drive_enabled = ! empty( $_POST['drive_enabled'] ) ? 'yes' : 'no';
        $drive_api_key = isset( $_POST['drive_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['drive_api_key'] ) ) ) : '';
        $drive_app_id = isset( $_POST['drive_app_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['drive_app_id'] ) ) ) : '';
        if ( $client_id && ! self::valid_client_id( $client_id ) ) {
            self::settings_redirect( 'invalid' );
        }
        if ( ( $drive_api_key && ! self::valid_api_key( $drive_api_key ) ) || ( $drive_app_id && ! self::valid_app_id( $drive_app_id ) ) ) { self::settings_redirect( 'drive-invalid' ); }
        update_option( self::OPTION, array( 'enabled' => $enabled, 'client_id' => $client_id, 'drive_enabled' => $drive_enabled, 'drive_api_key' => $drive_api_key, 'drive_app_id' => $drive_app_id ), false );
        if ( 'yes' === $drive_enabled && ( ! $client_id || ! $drive_api_key || ! $drive_app_id ) ) { self::settings_redirect( 'drive-missing' ); }
        self::settings_redirect( $enabled && ! $client_id ? 'missing' : 'saved' );
    }

    private static function settings_redirect( $status ) {
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'settings', array( 'category' => 'integrations', 'google_status' => $status ) ) );
        exit;
    }

    public static function render_settings() {
        $settings = self::settings();
        $status = isset( $_GET['google_status'] ) ? sanitize_key( wp_unslash( $_GET['google_status'] ) ) : '';
        $ready = self::enabled() && self::library_available();
        wp_enqueue_style( 'ge-google-auth', GE_WTP_PLUGIN_URL . 'assets/css/google-auth.css', array( 'ge-settings-center' ), GE_WTP_VERSION );
        ?>
        <div class="ge-settings-section-heading"><span>Servicios conectados</span><h2>Integraciones</h2><p>Administrá accesos, almacenamiento, diseño y servicios externos.</p></div>
        <?php if ( 'saved' === $status ) : ?><div class="ge-production-notice">La configuración de Google se guardó.</div><?php elseif ( 'invalid' === $status ) : ?><div class="ge-production-notice is-error">El Client ID no tiene un formato válido.</div><?php elseif ( 'missing' === $status ) : ?><div class="ge-production-notice is-error">Para activar Google primero tenés que cargar el Client ID.</div><?php elseif ( 'drive-invalid' === $status ) : ?><div class="ge-production-notice is-error">Revisá la API Key y el número de proyecto de Google Drive.</div><?php elseif ( 'drive-missing' === $status ) : ?><div class="ge-production-notice is-error">Para activar Drive completá el Client ID, la API Key y el número de proyecto.</div><?php endif; ?>
        <section class="ge-integration-card <?php echo $ready ? 'is-connected' : ''; ?>">
            <div class="ge-integration-head"><b class="ge-google-mark">G</b><div><span>Identidad</span><h3>Ingreso con Google</h3><p>Registro e ingreso rápido para clientes de la tienda.</p></div><strong><?php echo $ready ? 'Listo para usar' : ( $settings['client_id'] ? 'Desactivado' : 'Pendiente de configurar' ); ?></strong></div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_save_google_auth"><?php wp_nonce_field( 'ge_save_google_auth' ); ?>
                <label class="ge-integration-toggle"><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'], 'yes' ); ?>><span><strong>Habilitar ingreso con Google</strong><small>El botón aparecerá en el acceso y registro de clientes.</small></span></label>
                <label class="ge-integration-field">Client ID de aplicación web<input type="text" name="client_id" value="<?php echo esc_attr( $settings['client_id'] ); ?>" placeholder="000000000000-xxxxxxxx.apps.googleusercontent.com" autocomplete="off"><small>No cargues aquí el Client Secret. Esta integración de identidad solamente necesita el Client ID.</small></label>
                <div class="ge-google-setup"><h4>Datos para Google Cloud</h4><dl><div><dt>Origen local autorizado</dt><dd><code>http://localhost</code></dd></div><div><dt>Orígenes públicos autorizados</dt><dd><code>https://graphexpress.com.ar</code><code>https://www.graphexpress.com.ar</code></dd></div><div><dt>URI de redirección local</dt><dd><code><?php echo esc_html( self::callback_url() ); ?></code></dd></div><div><dt>URI pública al publicar</dt><dd><code>https://graphexpress.com.ar/wp-admin/admin-post.php?action=ge_google_auth</code></dd></div></dl><p>Creá en Google Cloud un cliente OAuth de tipo “Aplicación web” y copiá únicamente su Client ID.</p></div>
                <div class="ge-drive-config">
                    <div class="ge-drive-config-head"><b>DR</b><span><strong>Google Drive</strong><small>Elegir originales pesados sin copiarlos al VPS.</small></span><em><?php echo self::drive_enabled() ? 'Listo para usar' : 'Pendiente'; ?></em></div>
                    <label class="ge-integration-toggle"><input type="checkbox" name="drive_enabled" value="1" <?php checked( $settings['drive_enabled'], 'yes' ); ?>><span><strong>Habilitar selector de Google Drive</strong><small>Aparecerá al registrar una ficha en la Biblioteca.</small></span></label>
                    <div class="ge-drive-fields"><label class="ge-integration-field">API Key restringida<input type="text" name="drive_api_key" value="<?php echo esc_attr( $settings['drive_api_key'] ); ?>" placeholder="AIza..." autocomplete="off"><small>Restringila a Google Picker API, Drive API, tus dominios y https://docs.google.com/*.</small></label><label class="ge-integration-field">Número de proyecto / App ID<input type="text" name="drive_app_id" value="<?php echo esc_attr( $settings['drive_app_id'] ); ?>" placeholder="123456789012" inputmode="numeric" autocomplete="off"><small>Es el número del proyecto, no su nombre.</small></label></div>
                    <div class="ge-google-setup"><h4>APIs requeridas</h4><p>Activá Google Picker API y Google Drive API dentro del mismo proyecto. El selector solicitará acceso solamente cuando una persona pulse “Elegir desde Drive”.</p></div>
                </div>
                <?php if ( ! self::library_available() ) : ?><div class="ge-integration-warning">Este servidor no tiene disponible OpenSSL para validar la firma de Google. El botón permanecerá inactivo.</div><?php endif; ?>
                <div class="ge-notification-actions"><button class="ge-staff-button" type="submit">Guardar integraciones de Google</button></div>
            </form>
        </section>
        <?php GE_WTP_Canva::render_settings(); ?>
        <section class="ge-integrations-coming"><article><b>DB</b><span><strong>Dropbox</strong><small>Selección de archivos · siguiente etapa</small></span></article><article><b>FP</b><span><strong>Freepik</strong><small>Recursos visuales e IA · próxima etapa</small></span></article><article><b>AD</b><span><strong>Adobe</strong><small>Vista y procesamiento PDF · pendiente</small></span></article><article><b>PDF</b><span><strong>Herramientas PDF</strong><small>Conversión y correcciones · pendiente</small></span></article></section>
        <?php
    }

    public static function assets() {
        $account_page = function_exists( 'is_account_page' ) && is_account_page();
        $library_page = is_page( 'gestion' );
        if ( self::enabled() && self::library_available() && $account_page ) {
            wp_enqueue_script( 'google-identity-services', 'https://accounts.google.com/gsi/client', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
            wp_enqueue_style( 'ge-google-auth-front', GE_WTP_PLUGIN_URL . 'assets/css/google-auth.css', array(), GE_WTP_VERSION );
        }
        if ( self::drive_enabled() && $library_page ) {
            $settings = self::settings();
            wp_enqueue_script( 'google-api-loader', 'https://apis.google.com/js/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
            wp_enqueue_script( 'google-identity-services', 'https://accounts.google.com/gsi/client', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
            wp_enqueue_script( 'ge-google-drive-picker', GE_WTP_PLUGIN_URL . 'assets/js/google-drive-picker.js', array(), GE_WTP_VERSION, true );
            wp_localize_script( 'ge-google-drive-picker', 'geGoogleDrive', array( 'clientId' => $settings['client_id'], 'apiKey' => $settings['drive_api_key'], 'appId' => $settings['drive_app_id'], 'scope' => 'https://www.googleapis.com/auth/drive.file' ) );
        }
    }

    public static function render_login_button() { self::render_button( 'signin_with', 'signin' ); }
    public static function render_register_button() { self::render_button( 'signup_with', 'signup' ); }

    private static function render_button( $text, $context ) {
        if ( ! self::enabled() || ! self::library_available() ) { return; }
        $settings = self::settings();
        ?><div class="ge-google-login"><span>o continuá con</span><?php self::render_loader( $settings['client_id'], $context ); ?><div class="g_id_signin" data-type="standard" data-shape="rectangular" data-theme="outline" data-text="<?php echo esc_attr( $text ); ?>" data-size="large" data-logo_alignment="left" data-width="320"></div><?php self::render_notice(); ?></div><?php
    }

    private static function render_loader( $client_id, $context ) {
        if ( self::$loader_rendered ) { return; }
        self::$loader_rendered = true;
        ?><div id="g_id_onload" data-client_id="<?php echo esc_attr( $client_id ); ?>" data-context="<?php echo esc_attr( $context ); ?>" data-ux_mode="redirect" data-login_uri="<?php echo esc_url( self::callback_url() ); ?>" data-auto_prompt="false"></div><?php
    }

    public static function render_account_connection() {
        if ( ! self::enabled() || ! self::library_available() || ! is_user_logged_in() ) { return; }
        $sub = get_user_meta( get_current_user_id(), self::META_SUB, true );
        if ( $sub ) { echo '<div class="ge-google-account-state"><b>G</b><span><strong>Google conectado</strong><small>Podés usar tu cuenta de Google para ingresar la próxima vez.</small></span></div>'; return; }
        $settings = self::settings();
        echo '<div class="ge-google-account-connect"><div><strong>Conectá tu cuenta de Google</strong><span>Después vas a poder ingresar sin contraseña.</span></div>';
        self::render_loader( $settings['client_id'], 'use' );
        echo '<div class="g_id_signin" data-type="standard" data-shape="rectangular" data-theme="outline" data-text="continue_with" data-size="medium" data-logo_alignment="left"></div></div>';
    }

    private static function render_notice() {
        if ( self::$notice_rendered ) { return; }
        self::$notice_rendered = true;
        $status = isset( $_GET['google_login'] ) ? sanitize_key( wp_unslash( $_GET['google_login'] ) ) : '';
        $messages = array( 'failed' => 'No pudimos validar el acceso con Google.', 'email-link-required' => 'Ese email ya tiene una cuenta. Ingresá con tu contraseña una vez y conectá Google desde tu perfil.', 'conflict' => 'Esa cuenta de Google ya está vinculada a otro usuario.' );
        if ( isset( $messages[ $status ] ) ) { echo '<small class="ge-google-error">' . esc_html( $messages[ $status ] ) . '</small>'; }
    }

    private static function library_available() { return function_exists( 'openssl_verify' ); }

    public static function handle_callback() {
        $cookie_csrf = isset( $_COOKIE['g_csrf_token'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['g_csrf_token'] ) ) : '';
        $body_csrf = isset( $_POST['g_csrf_token'] ) ? sanitize_text_field( wp_unslash( $_POST['g_csrf_token'] ) ) : '';
        $credential = isset( $_POST['credential'] ) ? trim( wp_unslash( $_POST['credential'] ) ) : '';
        if ( ! self::enabled() || ! $cookie_csrf || ! $body_csrf || ! hash_equals( $cookie_csrf, $body_csrf ) || ! $credential ) { self::login_redirect( 'failed' ); }
        $payload = self::verify_token( $credential );
        $email_verified = isset( $payload['email_verified'] ) && ( true === $payload['email_verified'] || 'true' === $payload['email_verified'] );
        if ( ! is_array( $payload ) || empty( $payload['sub'] ) || empty( $payload['email'] ) || ! $email_verified ) { self::login_redirect( 'failed' ); }
        $email = strtolower( sanitize_email( $payload['email'] ) );
        if ( ! is_email( $email ) ) { self::login_redirect( 'failed' ); }
        $by_sub = get_users( array( 'meta_key' => self::META_SUB, 'meta_value' => sanitize_text_field( $payload['sub'] ), 'number' => 1, 'fields' => 'ids' ) );
        $sub_user_id = $by_sub ? (int) $by_sub[0] : 0;
        $current_user_id = get_current_user_id();
        if ( $current_user_id ) {
            if ( $sub_user_id && $sub_user_id !== $current_user_id ) { self::login_redirect( 'conflict' ); }
            update_user_meta( $current_user_id, self::META_SUB, sanitize_text_field( $payload['sub'] ) );
            update_user_meta( $current_user_id, '_ge_google_connected_at', current_time( 'mysql' ) );
            self::authenticate( $current_user_id );
        }
        if ( $sub_user_id ) { self::authenticate( $sub_user_id ); }
        $email_user_id = (int) email_exists( $email );
        if ( $email_user_id ) {
            $google_authoritative = '@gmail.com' === substr( $email, -10 ) || ! empty( $payload['hd'] );
            if ( ! $google_authoritative ) { self::login_redirect( 'email-link-required' ); }
            update_user_meta( $email_user_id, self::META_SUB, sanitize_text_field( $payload['sub'] ) );
            update_user_meta( $email_user_id, '_ge_google_connected_at', current_time( 'mysql' ) );
            update_user_meta( $email_user_id, '_ge_email_verified', 'yes' );
            self::authenticate( $email_user_id );
        }
        $user_id = self::create_customer( $payload, $email );
        if ( is_wp_error( $user_id ) || ! $user_id ) { self::login_redirect( 'failed' ); }
        self::authenticate( $user_id );
    }

    private static function verify_token( $credential ) {
        if ( ! self::library_available() ) { return false; }
        $segments = explode( '.', $credential );
        if ( 3 !== count( $segments ) ) { return false; }
        $header = json_decode( self::base64url_decode( $segments[0] ), true );
        $payload = json_decode( self::base64url_decode( $segments[1] ), true );
        $signature = self::base64url_decode( $segments[2] );
        if ( ! is_array( $header ) || ! is_array( $payload ) || false === $signature || 'RS256' !== ( $header['alg'] ?? '' ) || empty( $header['kid'] ) ) { return false; }
        $certificates = self::google_certificates();
        $kid = sanitize_text_field( $header['kid'] );
        if ( ! isset( $certificates[ $kid ] ) ) {
            delete_transient( 'ge_google_identity_certs' );
            $certificates = self::google_certificates();
        }
        if ( ! isset( $certificates[ $kid ] ) || 1 !== openssl_verify( $segments[0] . '.' . $segments[1], $signature, $certificates[ $kid ], OPENSSL_ALGO_SHA256 ) ) { return false; }
        $settings = self::settings();
        $issuer = isset( $payload['iss'] ) ? (string) $payload['iss'] : '';
        $audience = isset( $payload['aud'] ) ? $payload['aud'] : '';
        $audience_ok = is_array( $audience ) ? in_array( $settings['client_id'], $audience, true ) : hash_equals( $settings['client_id'], (string) $audience );
        $now = time();
        if ( ! in_array( $issuer, array( 'accounts.google.com', 'https://accounts.google.com' ), true ) || ! $audience_ok || empty( $payload['exp'] ) || (int) $payload['exp'] < $now || ( ! empty( $payload['iat'] ) && (int) $payload['iat'] > $now + 300 ) ) { return false; }
        return $payload;
    }

    private static function base64url_decode( $value ) {
        $value = strtr( (string) $value, '-_', '+/' );
        $padding = strlen( $value ) % 4;
        if ( $padding ) { $value .= str_repeat( '=', 4 - $padding ); }
        return base64_decode( $value, true );
    }

    private static function google_certificates() {
        $cached = get_transient( 'ge_google_identity_certs' );
        if ( is_array( $cached ) && $cached ) { return $cached; }
        $response = wp_remote_get( 'https://www.googleapis.com/oauth2/v1/certs', array( 'timeout' => 8, 'sslverify' => true ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return array(); }
        $certificates = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $certificates ) || ! $certificates ) { return array(); }
        set_transient( 'ge_google_identity_certs', $certificates, 6 * HOUR_IN_SECONDS );
        return $certificates;
    }

    private static function create_customer( $payload, $email ) {
        $base = sanitize_user( strtok( $email, '@' ), true ) ?: 'cliente';
        $username = $base; $suffix = 1;
        while ( username_exists( $username ) ) { $username = $base . $suffix; $suffix++; }
        $first_name = isset( $payload['given_name'] ) ? sanitize_text_field( $payload['given_name'] ) : '';
        $last_name = isset( $payload['family_name'] ) ? sanitize_text_field( $payload['family_name'] ) : '';
        $display_name = trim( $first_name . ' ' . $last_name ) ?: $email;
        $role = get_role( 'customer' ) ? 'customer' : 'subscriber';
        $user_id = wp_insert_user( array( 'user_login' => $username, 'user_email' => $email, 'user_pass' => wp_generate_password( 64, true, true ), 'display_name' => $display_name, 'first_name' => $first_name, 'last_name' => $last_name, 'role' => $role ) );
        if ( is_wp_error( $user_id ) ) { return $user_id; }
        update_user_meta( $user_id, self::META_SUB, sanitize_text_field( $payload['sub'] ) );
        update_user_meta( $user_id, '_ge_google_connected_at', current_time( 'mysql' ) );
        update_user_meta( $user_id, '_ge_email_verified', 'yes' );
        update_user_meta( $user_id, '_ge_registration_source', 'google' );
        if ( class_exists( 'GE_WTP_Notifications' ) ) { GE_WTP_Notifications::send_new_customer_admin( get_userdata( $user_id ) ); }
        return $user_id;
    }

    private static function authenticate( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) { self::login_redirect( 'failed' ); }
        wp_set_current_user( $user_id ); wp_set_auth_cookie( $user_id, true, is_ssl() ); do_action( 'wp_login', $user->user_login, $user );
        $target = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
        wp_safe_redirect( add_query_arg( 'google_login', 'success', $target ) ); exit;
    }

    private static function login_redirect( $status ) {
        $target = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
        wp_safe_redirect( add_query_arg( 'google_login', $status, $target ) ); exit;
    }
}
