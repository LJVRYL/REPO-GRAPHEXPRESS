<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Review_Requests {
    const URL_OPTION = 'ge_wtp_google_review_url';

    public static function init() {
        add_action( 'admin_post_ge_send_review_email', array( __CLASS__, 'handle_email' ) );
        add_action( 'admin_post_ge_open_review_whatsapp', array( __CLASS__, 'handle_whatsapp' ) );
        add_action( 'admin_post_ge_save_review_url', array( __CLASS__, 'handle_save_url' ) );
    }

    public static function review_url() {
        $saved = esc_url_raw( (string) get_option( self::URL_OPTION, '' ) );
        return $saved ?: 'https://www.google.com/maps/search/?api=1&query=Imprenta%20Graph%20Express%20Oruro%201253%20Buenos%20Aires';
    }

    public static function render_for_order( $order ) {
        if ( ! $order instanceof WC_Order || ! GE_WTP_Staff_Portal::can_access() ) { return; }
        $email = sanitize_email( $order->get_billing_email() );
        $phone = self::phone_for_order( $order );
        $history = (array) $order->get_meta( '_ge_review_request_history' );
        $last = $history ? end( $history ) : array();
        $status = isset( $_GET['review_status'] ) ? sanitize_key( wp_unslash( $_GET['review_status'] ) ) : '';
        ?>
        <section class="ge-review-panel">
            <div class="ge-review-head"><div><span>Experiencia del cliente</span><h2>Pedir reseña</h2><p>Acción manual. No modifica ni condiciona el pedido.</p></div><b>Google</b></div>
            <?php if ( 'sent' === $status ) : ?><div class="ge-review-notice is-success">El email de reseña fue enviado y quedó registrado.</div><?php elseif ( 'failed' === $status ) : ?><div class="ge-review-notice is-error">No se pudo enviar el email. Revisá la configuración de correo.</div><?php elseif ( 'link-saved' === $status ) : ?><div class="ge-review-notice is-success">El enlace de reseñas quedó guardado.</div><?php endif; ?>
            <div class="ge-review-grid">
                <div class="ge-review-contact"><small>Cliente</small><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_company() ?: 'Cliente' ); ?></strong><span><?php echo esc_html( $email ?: 'Sin email' ); ?></span><span><?php echo esc_html( $phone ? '+' . $phone : 'Sin WhatsApp' ); ?></span><?php if ( $last ) : ?><em>Última solicitud: <?php echo esc_html( wp_date( 'd/m/Y H:i', absint( $last['time'] ?? 0 ) ) ); ?> · <?php echo esc_html( 'whatsapp' === ( $last['channel'] ?? '' ) ? 'WhatsApp abierto' : ( ! empty( $last['success'] ) ? 'Email enviado' : 'Email fallido' ) ); ?></em><?php endif; ?></div>
                <div class="ge-review-actions">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_send_review_email"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_send_review_email_' . $order->get_id() ); ?><button class="ge-staff-button" type="submit" <?php disabled( ! $email ); ?>>Enviar por email</button></form>
                    <?php if ( $phone ) : ?><a class="ge-review-whatsapp" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ge_open_review_whatsapp', 'order_id' => $order->get_id() ), admin_url( 'admin-post.php' ) ), 'ge_open_review_whatsapp_' . $order->get_id() ) ); ?>">Abrir WhatsApp ↗</a><?php else : ?><span class="ge-review-disabled">WhatsApp no cargado</span><?php endif; ?>
                </div>
            </div>
            <details class="ge-review-settings"><summary>Configurar enlace de Google</summary><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_save_review_url"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_save_review_url_' . $order->get_id() ); ?><label>Enlace público de reseñas<input type="url" name="review_url" required value="<?php echo esc_attr( self::review_url() ); ?>"></label><button type="submit">Guardar enlace</button></form><small>Usá idealmente el enlace directo “Pedir reseñas” de tu Perfil de Empresa de Google.</small></details>
        </section>
        <?php
    }

    public static function handle_email() {
        self::require_access();
        $order = self::order_from_request();
        check_admin_referer( 'ge_send_review_email_' . $order->get_id() );
        $email = sanitize_email( $order->get_billing_email() );
        if ( ! $email ) { self::redirect( $order, 'failed' ); }
        $name = trim( $order->get_billing_first_name() );
        $body = self::email_body( $name ?: '¿cómo estás?', self::review_url() );
        $sent = GE_WTP_Notifications::send( $email, '¿Cómo fue tu experiencia con Graph Express?', $body, 'review_request', $order->get_id() );
        self::log( $order, 'email', $sent );
        self::redirect( $order, $sent ? 'sent' : 'failed' );
    }

    public static function handle_whatsapp() {
        self::require_access();
        $order = self::order_from_request();
        check_admin_referer( 'ge_open_review_whatsapp_' . $order->get_id() );
        $phone = self::phone_for_order( $order );
        if ( ! $phone ) { self::redirect( $order, 'failed' ); }
        $name = trim( $order->get_billing_first_name() );
        $message = 'Hola ' . ( $name ?: '¿cómo estás?' ) . ', gracias por elegir Graph Express. Si quedaste conforme con nuestro trabajo, ¿nos dejarías una reseña en Google? Nos ayuda muchísimo: ' . self::review_url();
        self::log( $order, 'whatsapp', true );
        wp_redirect( 'https://wa.me/' . rawurlencode( $phone ) . '?text=' . rawurlencode( $message ) );
        exit;
    }

    public static function handle_save_url() {
        self::require_access();
        $order = self::order_from_request();
        check_admin_referer( 'ge_save_review_url_' . $order->get_id() );
        $url = isset( $_POST['review_url'] ) ? esc_url_raw( wp_unslash( $_POST['review_url'] ) ) : '';
        if ( ! $url || ! wp_http_validate_url( $url ) ) { self::redirect( $order, 'failed' ); }
        update_option( self::URL_OPTION, $url, false );
        self::redirect( $order, 'link-saved' );
    }

    private static function phone_for_order( $order ) {
        $phone = method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : '';
        $phone = $phone ?: $order->get_billing_phone();
        if ( ! $phone && $order->get_customer_id() ) { $phone = get_user_meta( $order->get_customer_id(), '_ge_whatsapp', true ); }
        $digits = preg_replace( '/\D+/', '', (string) $phone );
        $digits = ltrim( $digits, '0' );
        if ( 10 === strlen( $digits ) && 0 === strpos( $digits, '11' ) ) { $digits = '549' . $digits; }
        elseif ( 11 === strlen( $digits ) && 0 === strpos( $digits, '9' ) ) { $digits = '54' . $digits; }
        return $digits;
    }

    private static function email_body( $name, $url ) {
        return '<!doctype html><html><body style="margin:0;background:#f3f2f6;font-family:Arial,sans-serif;color:#17152a"><div style="max-width:680px;margin:auto;padding:30px 18px"><div style="padding:20px 26px;border-radius:16px 16px 0 0;background:#111629;color:#fff"><strong style="letter-spacing:.1em">GRAPH EXPRESS</strong></div><div style="padding:34px 28px;border-radius:0 0 16px 16px;background:#fff"><h1 style="font-size:30px;margin-top:0">¿Cómo fue tu experiencia?</h1><p style="font-size:16px;line-height:1.65;color:#4f4b59">Hola ' . esc_html( $name ) . ', gracias por elegir Graph Express. Si quedaste conforme con nuestro trabajo, nos ayudaría muchísimo que compartas tu experiencia en Google.</p><p style="margin:28px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 20px;border-radius:10px;background:#6d45ef;color:#fff;text-decoration:none;font-weight:700">Dejar una reseña</a></p><p style="font-size:13px;line-height:1.55;color:#85818e">La reseña es completamente voluntaria. Gracias por confiar en nosotros.</p><p style="margin-top:30px;color:#898594;font-size:12px">Graph Express · Oruro 1253 · CABA</p></div></div></body></html>';
    }

    private static function log( $order, $channel, $success ) {
        $history = (array) $order->get_meta( '_ge_review_request_history' );
        $history[] = array( 'time' => time(), 'channel' => sanitize_key( $channel ), 'success' => (bool) $success, 'user_id' => get_current_user_id() );
        $order->update_meta_data( '_ge_review_request_history', array_slice( $history, -30 ) );
        $order->save();
    }

    private static function order_from_request() {
        $order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
        $order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
        if ( ! $order ) { wp_die( 'Pedido inválido.', 404 ); }
        return $order;
    }

    private static function require_access() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
    }

    private static function redirect( $order, $status ) {
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id(), 'review_status' => sanitize_key( $status ) ) ) );
        exit;
    }
}
