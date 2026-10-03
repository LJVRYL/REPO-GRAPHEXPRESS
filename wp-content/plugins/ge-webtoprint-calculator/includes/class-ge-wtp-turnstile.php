<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cloudflare Turnstile protection for the public customer access forms.
 */
final class GE_WTP_Turnstile {
    const OPTION = 'ge_wtp_turnstile';

    public static function init() {
        add_action( 'admin_post_ge_save_turnstile', array( __CLASS__, 'save_settings' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
    }

    public static function defaults() {
        return array( 'enabled' => 'no', 'site_key' => '', 'secret_key' => '' );
    }

    public static function settings() {
        return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
    }

    public static function enabled() {
        $settings = self::settings();
        return 'yes' === $settings['enabled'] && '' !== $settings['site_key'] && '' !== $settings['secret_key'];
    }

    public static function assets() {
        if ( self::enabled() && is_page( 'cliente-markcom' ) && ! is_user_logged_in() ) {
            wp_enqueue_script( 'cloudflare-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
    }

    public static function render_widget( $action ) {
        if ( ! self::enabled() ) { return; }
        $settings = self::settings();
        echo '<div class="ge-turnstile-wrap"><div class="cf-turnstile" data-sitekey="' . esc_attr( $settings['site_key'] ) . '" data-theme="light" data-size="flexible" data-action="' . esc_attr( sanitize_key( $action ) ) . '"></div><small>Protegido por Cloudflare Turnstile.</small></div>';
    }

    public static function verify( $expected_action ) {
        if ( ! self::enabled() ) { return true; }
        $token = isset( $_POST['cf-turnstile-response'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) ) : '';
        if ( '' === $token ) { return false; }
        $settings = self::settings();
        $response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
            'timeout' => 10,
            'body'    => array(
                'secret'   => $settings['secret_key'],
                'response' => $token,
                'remoteip' => self::remote_ip(),
            ),
        ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return false; }
        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $result ) || empty( $result['success'] ) ) { return false; }
        return empty( $result['action'] ) || hash_equals( sanitize_key( $expected_action ), sanitize_key( $result['action'] ) );
    }

    private static function remote_ip() {
        $candidates = array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' );
        foreach ( $candidates as $key ) {
            if ( empty( $_SERVER[ $key ] ) ) { continue; }
            $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) { return $ip; }
        }
        return '';
    }

    public static function save_settings() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        check_admin_referer( 'ge_save_turnstile' );
        $current = self::settings();
        $site_key = isset( $_POST['site_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) ) : '';
        $secret_key = isset( $_POST['secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) ) : '';
        if ( '' === $secret_key ) { $secret_key = $current['secret_key']; }
        $enabled = ! empty( $_POST['enabled'] ) ? 'yes' : 'no';
        update_option( self::OPTION, array( 'enabled' => $enabled, 'site_key' => $site_key, 'secret_key' => $secret_key ), false );
        $status = 'yes' === $enabled && ( '' === $site_key || '' === $secret_key ) ? 'missing' : 'saved';
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'settings', array( 'category' => 'integrations', 'turnstile_status' => $status ) ) );
        exit;
    }

    public static function render_settings() {
        $settings = self::settings();
        $status = isset( $_GET['turnstile_status'] ) ? sanitize_key( wp_unslash( $_GET['turnstile_status'] ) ) : '';
        ?>
        <?php if ( 'saved' === $status ) : ?><div class="ge-production-notice">La protección de Cloudflare se guardó.</div><?php elseif ( 'missing' === $status ) : ?><div class="ge-production-notice is-error">Completá Site Key y Secret Key antes de activar Turnstile.</div><?php endif; ?>
        <section class="ge-integration-card <?php echo self::enabled() ? 'is-connected' : ''; ?> ge-turnstile-settings">
            <div class="ge-integration-head"><b class="ge-cloudflare-mark">CF</b><div><span>Seguridad</span><h3>Cloudflare Turnstile</h3><p>Protección anti-bots para ingreso y registro con email.</p></div><strong><?php echo self::enabled() ? 'Protegiendo formularios' : ( $settings['site_key'] ? 'Desactivado' : 'Pendiente de configurar' ); ?></strong></div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_save_turnstile"><?php wp_nonce_field( 'ge_save_turnstile' ); ?>
                <label class="ge-integration-toggle"><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'], 'yes' ); ?>><span><strong>Habilitar protección anti-bots</strong><small>Se exigirá una validación silenciosa o interactiva al ingresar y registrarse.</small></span></label>
                <label class="ge-integration-field">Site Key<input type="text" name="site_key" value="<?php echo esc_attr( $settings['site_key'] ); ?>" autocomplete="off" placeholder="0x4AAAA..."></label>
                <label class="ge-integration-field">Secret Key<input type="password" name="secret_key" value="" autocomplete="new-password" placeholder="<?php echo $settings['secret_key'] ? 'Guardada · dejar vacío para conservar' : '0x4AAAA...'; ?>"><small>La clave secreta queda guardada en WordPress y nunca se muestra en el navegador.</small></label>
                <div class="ge-google-setup"><h4>Dominios del widget</h4><p>En Cloudflare agregá <code>graphexpress.com.ar</code>, <code>www.graphexpress.com.ar</code> y <code>localhost</code> para poder probarlo antes de publicar.</p></div>
                <div class="ge-notification-actions"><button class="ge-staff-button" type="submit">Guardar protección</button></div>
            </form>
        </section>
        <?php
    }
}
