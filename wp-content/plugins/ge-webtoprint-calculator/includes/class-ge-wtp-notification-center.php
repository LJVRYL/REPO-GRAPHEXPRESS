<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Notification_Center {
    const OPTION = 'ge_wtp_notification_settings';
    const LAST_DIGEST = 'ge_wtp_last_operations_digest';
    const CRON_HOOK = 'ge_wtp_operations_digest_check';

    public static function init() {
        add_action( 'admin_post_ge_notification_settings', array( __CLASS__, 'handle_settings' ) );
        add_action( 'admin_post_ge_notification_test', array( __CLASS__, 'handle_test' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_send_digest' ) );
        add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 35 );
        add_filter( 'wp_mail_from', array( __CLASS__, 'mail_from' ) );
        add_filter( 'wp_mail_from_name', array( __CLASS__, 'mail_from_name' ) );
    }

    public static function defaults() {
        return array( 'sender_email' => 'servicio@graphexpress.com.ar', 'sender_name' => 'Graph Express', 'recipients' => sanitize_email( get_option( 'admin_email' ) ), 'new_order' => 'yes', 'new_customer' => 'yes', 'new_candidate' => 'yes', 'new_incident' => 'yes', 'supplier_failure' => 'yes', 'production_digest' => 'yes', 'digest_hour' => 8 );
    }

    public static function settings() { return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() ); }
    public static function enabled( $key ) { $settings = self::settings(); return 'yes' === ( $settings[ $key ] ?? 'no' ); }

    public static function recipients() {
        $raw = preg_split( '/[,;\s]+/', (string) self::settings()['recipients'] ); $emails = array();
        foreach ( $raw as $email ) { $email = sanitize_email( $email ); if ( is_email( $email ) ) { $emails[] = $email; } }
        return array_values( array_unique( $emails ) );
    }

    public static function mail_from( $email ) {
        $configured = sanitize_email( self::settings()['sender_email'] ?? '' );
        return is_email( $configured ) ? $configured : $email;
    }

    public static function mail_from_name( $name ) {
        $configured = sanitize_text_field( self::settings()['sender_name'] ?? '' );
        return $configured ?: $name;
    }

    public static function send_internal( $event, $subject, $html, $object_id = 0 ) {
        if ( ! self::enabled( $event ) ) { return null; } $ok = true; $sent = 0;
        foreach ( self::recipients() as $email ) { $sent++; if ( ! GE_WTP_Notifications::send( $email, $subject, $html, 'internal_' . sanitize_key( $event ), $object_id ) ) { $ok = false; } }
        return $sent ? $ok : false;
    }

    public static function render( $show_heading = true ) {
        wp_enqueue_style( 'ge-notification-center', GE_WTP_PLUGIN_URL . 'assets/css/notifications.css', array( 'ge-staff-portal' ), GE_WTP_VERSION );
        $settings = self::settings(); $logs = GE_WTP_Notifications::get_logs( 80 ); $sent = 0; $failed = 0;
        foreach ( $logs as $log ) { 'sent' === get_post_meta( $log->ID, '_ge_email_result', true ) ? $sent++ : $failed++; }
        ?>
        <?php if ( $show_heading ) : ?><div class="ge-staff-heading"><div><span>Centro de avisos</span><h1>Notificaciones</h1><p>Elegí qué avisos internos recibe Graph Express y controlá su entrega.</p></div></div><?php else : ?><div class="ge-settings-section-heading"><span>Centro de avisos</span><h2>Notificaciones</h2><p>Elegí qué avisos internos recibe Graph Express y controlá su entrega.</p></div><?php endif; ?>
        <?php if ( ! empty( $_GET['notification_saved'] ) ) : ?><div class="ge-production-notice">La configuración de notificaciones quedó guardada.</div><?php endif; ?><?php if ( isset( $_GET['notification_test'] ) ) : ?><div class="ge-production-notice <?php echo 'sent' === $_GET['notification_test'] ? '' : 'is-error'; ?>"><?php echo 'sent' === $_GET['notification_test'] ? 'El correo de prueba fue enviado.' : 'No se pudo enviar la prueba. Habrá que revisar SMTP.'; ?></div><?php endif; ?>
        <div class="ge-notification-metrics"><article><span>Últimos registros</span><strong><?php echo esc_html( count( $logs ) ); ?></strong></article><article><span>Enviados</span><strong><?php echo esc_html( $sent ); ?></strong></article><article class="<?php echo $failed ? 'is-warning' : ''; ?>"><span>Fallidos</span><strong><?php echo esc_html( $failed ); ?></strong></article></div>
        <form class="ge-notification-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_notification_settings"><?php wp_nonce_field( 'ge_notification_settings' ); ?><section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Correo saliente</span><h2>Identidad del remitente</h2></div></div><div class="ge-notification-sender"><label>Email remitente<input type="email" name="sender_email" value="<?php echo esc_attr( $settings['sender_email'] ); ?>" required></label><label>Nombre visible<input type="text" name="sender_name" value="<?php echo esc_attr( $settings['sender_name'] ); ?>" maxlength="100" required></label></div><p class="ge-notification-help">Los clientes verán esta identidad en confirmaciones, cambios de estado, verificaciones y avisos del portal.</p></section><section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Destinatarios</span><h2>¿Quién recibe los avisos internos?</h2></div></div><label class="ge-notification-recipient">Emails separados por coma<textarea name="recipients" rows="2" required><?php echo esc_textarea( $settings['recipients'] ); ?></textarea><small>Podés cargar una o varias casillas. Los correos operativos de los clientes no se modifican desde acá.</small></label></section><section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Eventos</span><h2>Avisos configurables</h2></div></div><div class="ge-notification-switches"><?php foreach ( self::event_labels() as $key => $data ) : ?><label><input type="checkbox" name="events[<?php echo esc_attr( $key ); ?>]" value="yes" <?php checked( $settings[ $key ], 'yes' ); ?>><span><strong><?php echo esc_html( $data[0] ); ?></strong><small><?php echo esc_html( $data[1] ); ?></small></span></label><?php endforeach; ?></div><label class="ge-digest-hour">Hora del resumen diario<select name="digest_hour"><?php for ( $hour = 0; $hour < 24; $hour++ ) : ?><option value="<?php echo esc_attr( $hour ); ?>" <?php selected( (int) $settings['digest_hour'], $hour ); ?>><?php echo esc_html( sprintf( '%02d:00', $hour ) ); ?></option><?php endfor; ?></select></label><div class="ge-notification-actions"><button class="ge-staff-button" type="submit">Guardar configuración</button></div></section></form>
        <form class="ge-notification-test" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_notification_test"><?php wp_nonce_field( 'ge_notification_test' ); ?><div><strong>Comprobar correo</strong><span>Envía una prueba a todos los destinatarios configurados.</span></div><button type="submit">Enviar email de prueba</button></form>
        <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Trazabilidad</span><h2>Últimos correos</h2></div><strong><?php echo esc_html( count( $logs ) ); ?></strong></div><?php self::render_logs( $logs ); ?></section>
        <?php
    }

    public static function handle_settings() {
        self::guard(); check_admin_referer( 'ge_notification_settings' ); $defaults = self::defaults(); $events = (array) ( $_POST['events'] ?? array() );
        $emails = array(); foreach ( preg_split( '/[,;\s]+/', (string) wp_unslash( $_POST['recipients'] ?? '' ) ) as $email ) { $email = sanitize_email( $email ); if ( is_email( $email ) ) { $emails[] = $email; } }
        $sender_email = sanitize_email( wp_unslash( $_POST['sender_email'] ?? '' ) );
        $sender_name = sanitize_text_field( wp_unslash( $_POST['sender_name'] ?? '' ) );
        $clean = array( 'sender_email' => is_email( $sender_email ) ? $sender_email : $defaults['sender_email'], 'sender_name' => $sender_name ?: $defaults['sender_name'], 'recipients' => implode( ', ', array_unique( $emails ) ), 'digest_hour' => min( 23, absint( $_POST['digest_hour'] ?? 8 ) ) );
        foreach ( self::event_labels() as $key => $unused ) { $clean[ $key ] = ! empty( $events[ $key ] ) ? 'yes' : 'no'; }
        if ( ! $clean['recipients'] ) { $clean['recipients'] = $defaults['recipients']; }
        update_option( self::OPTION, $clean, false ); wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'settings', array( 'category' => 'notifications', 'notification_saved' => 1 ) ) ); exit;
    }

    public static function handle_test() {
        self::guard(); check_admin_referer( 'ge_notification_test' ); $ok = true; $sent = 0;
        foreach ( self::recipients() as $email ) { $sent++; if ( ! GE_WTP_Notifications::send( $email, 'Prueba de notificaciones · Graph Express', '<p>El centro de notificaciones está conectado correctamente.</p><p>Fecha: ' . esc_html( current_time( 'd/m/Y H:i' ) ) . '</p>', 'internal_test' ) ) { $ok = false; } }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'settings', array( 'category' => 'notifications', 'notification_test' => $sent && $ok ? 'sent' : 'failed' ) ) ); exit;
    }

    public static function ensure_schedule() { if ( ! wp_next_scheduled( self::CRON_HOOK ) ) { wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK ); } }

    public static function maybe_send_digest() {
        if ( ! self::enabled( 'production_digest' ) ) { return; } $settings = self::settings(); $today = wp_date( 'Y-m-d' );
        if ( (int) wp_date( 'G' ) < (int) $settings['digest_hour'] || get_option( self::LAST_DIGEST ) === $today ) { return; }
        $orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( array( 'limit' => 300, 'orderby' => 'date', 'order' => 'DESC' ) ) : array(); $active = 0; $due = 0; $delayed = 0; $pending_supplier = 0;
        foreach ( $orders as $order ) { if ( in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed', 'ge-entregado', 'ge-cobrado' ), true ) || 'ready' === $order->get_meta( '_ge_production_status' ) ) { continue; } $active++; $date = $order->get_meta( '_ge_production_promised_date' ); if ( $date === $today ) { $due++; } elseif ( $date && $date < $today ) { $delayed++; } if ( in_array( $order->get_meta( '_ge_production_supplier' ), array( '', 'pending', 'multiple', 'merch-pending', 'sublimation-pending' ), true ) ) { $pending_supplier++; } }
        if ( ! $active && ! $delayed && ! $due ) { update_option( self::LAST_DIGEST, $today, false ); return; }
        $url = GE_WTP_Staff_Portal::portal_url( 'production' ); $html = '<h2>Resumen de producción</h2><ul><li>Trabajos activos: <strong>' . absint( $active ) . '</strong></li><li>Vencen hoy: <strong>' . absint( $due ) . '</strong></li><li>Demorados: <strong>' . absint( $delayed ) . '</strong></li><li>Proveedor pendiente: <strong>' . absint( $pending_supplier ) . '</strong></li></ul><p><a href="' . esc_url( $url ) . '">Abrir Producción</a></p>';
        self::send_internal( 'production_digest', 'Resumen diario de producción · Graph Express', $html ); update_option( self::LAST_DIGEST, $today, false );
    }

    private static function event_labels() { return array( 'new_order' => array( 'Pedido nuevo del portal o mostrador', 'La tienda conserva además sus avisos propios de WooCommerce.' ), 'new_customer' => array( 'Cliente nuevo', 'Avisa cuando una persona crea su cuenta.' ), 'new_candidate' => array( 'Postulación nueva', 'Avisa cuando llega un perfil desde Trabajá con nosotros.' ), 'new_incident' => array( 'Incidencia nueva', 'Avisa por problemas de máquinas o proveedores.' ), 'supplier_failure' => array( 'Fallo al contactar proveedor', 'Avisa si una orden automática o manual no pudo enviarse.' ), 'production_digest' => array( 'Resumen diario de producción', 'Incluye vencimientos, demoras y proveedores pendientes.' ) ); }
    private static function render_logs( $logs ) { if ( ! $logs ) { echo '<div class="ge-admin-empty">Todavía no hay correos registrados.</div>'; return; } echo '<div class="ge-admin-table-scroll"><table class="ge-admin-table"><thead><tr><th>Fecha</th><th>Destinatario</th><th>Asunto</th><th>Tipo</th><th>Resultado</th></tr></thead><tbody>'; foreach ( $logs as $log ) { $result = get_post_meta( $log->ID, '_ge_email_result', true ); echo '<tr><td>' . esc_html( get_the_date( 'd/m/Y H:i', $log ) ) . '</td><td>' . esc_html( get_post_meta( $log->ID, '_ge_email_to', true ) ) . '</td><td><strong>' . esc_html( $log->post_title ) . '</strong></td><td>' . esc_html( str_replace( '_', ' ', get_post_meta( $log->ID, '_ge_email_context', true ) ) ) . '</td><td><span class="ge-mail-result is-' . esc_attr( $result ) . '">' . esc_html( 'sent' === $result ? 'Enviado' : 'Fallido' ) . '</span></td></tr>'; } echo '</tbody></table></div>'; }
    private static function guard() { if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); } }
}
