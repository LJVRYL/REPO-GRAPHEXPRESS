<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Supplier_Dispatch {
    const OPTION = 'ge_wtp_supplier_profiles';

    public static function init() {
        add_action( 'admin_post_ge_supplier_profiles_save', array( __CLASS__, 'handle_profiles' ) );
        add_action( 'admin_post_ge_supplier_add', array( __CLASS__, 'handle_add' ) );
        add_action( 'admin_post_ge_supplier_email', array( __CLASS__, 'handle_email' ) );
        add_action( 'admin_post_ge_supplier_whatsapp', array( __CLASS__, 'handle_whatsapp' ) );
    }

    public static function profiles() {
        $saved = get_option( self::OPTION, array() ); $profiles = array();
        foreach ( GE_WTP_Production::suppliers() as $key => $supplier ) {
            if ( in_array( $key, array( 'multiple', 'pending', 'merch-pending', 'sublimation-pending', 'internal' ), true ) ) { continue; }
            $current = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();
            $profiles[ $key ] = wp_parse_args( $current, array( 'name' => $supplier['name'], 'email' => $supplier['email'] ?? '', 'whatsapp' => $supplier['whatsapp'] ?? '', 'channel' => 'manual', 'auto_email' => 'no', 'notes' => $supplier['detail'] ) );
        }
        return $profiles;
    }

    public static function profile( $key ) { $profiles = self::profiles(); return $profiles[ $key ] ?? array(); }

    public static function render_settings() {
        $profiles = self::profiles();
        ?>
        <div class="ge-staff-heading"><div><span>Red de producción</span><h1>Proveedores</h1><p>Contactos, canales e instrucciones para emitir órdenes.</p></div></div>
        <?php if ( ! empty( $_GET['supplier_saved'] ) ) : ?><div class="ge-production-notice">La configuración de proveedores quedó guardada.</div><?php endif; ?>
        <?php if ( ! empty( $_GET['supplier_added'] ) ) : ?><div class="ge-production-notice">Se agregó una ficha nueva. Completá sus datos y guardá los proveedores.</div><?php endif; ?>
        <form class="ge-supplier-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_supplier_profiles_save"><?php wp_nonce_field( 'ge_supplier_profiles_save' ); ?><div class="ge-supplier-settings-grid">
        <?php foreach ( $profiles as $key => $profile ) : ?><article><div class="ge-supplier-title"><span><?php echo esc_html( strtoupper( str_replace( '-', ' ', $key ) ) ); ?></span><h2><?php echo esc_html( $profile['name'] ); ?></h2></div><div class="ge-supplier-fields"><label>Nombre visible<input type="text" name="profiles[<?php echo esc_attr( $key ); ?>][name]" maxlength="120" value="<?php echo esc_attr( $profile['name'] ); ?>"></label><label>Email de pedidos<input type="email" name="profiles[<?php echo esc_attr( $key ); ?>][email]" maxlength="190" value="<?php echo esc_attr( $profile['email'] ); ?>" placeholder="pedidos@proveedor.com"></label><label>WhatsApp<input type="tel" name="profiles[<?php echo esc_attr( $key ); ?>][whatsapp]" maxlength="40" value="<?php echo esc_attr( $profile['whatsapp'] ); ?>" placeholder="+54 9 11..."></label><label>Canal preferido<select name="profiles[<?php echo esc_attr( $key ); ?>][channel]"><option value="manual" <?php selected( $profile['channel'], 'manual' ); ?>>Manual / sin configurar</option><option value="email" <?php selected( $profile['channel'], 'email' ); ?>>Email</option><option value="whatsapp" <?php selected( $profile['channel'], 'whatsapp' ); ?>>WhatsApp</option></select></label><label class="is-wide">Instrucciones<textarea name="profiles[<?php echo esc_attr( $key ); ?>][notes]" rows="3" maxlength="1200"><?php echo esc_textarea( $profile['notes'] ); ?></textarea></label><label class="ge-auto-email is-wide"><input type="checkbox" name="profiles[<?php echo esc_attr( $key ); ?>][auto_email]" value="yes" <?php checked( $profile['auto_email'], 'yes' ); ?>><span><strong>Enviar automáticamente por email</strong><small>Sólo funciona si hay un email válido. Dejalo apagado durante la configuración.</small></span></label></div></article><?php endforeach; ?>
        </div><div class="ge-production-submit"><button class="ge-staff-button" type="submit">Guardar proveedores</button></div></form>
        <form class="ge-supplier-add" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_supplier_add"><?php wp_nonce_field( 'ge_supplier_add' ); ?><div><strong>¿Trabajás con otro proveedor?</strong><span>Agregá una ficha nueva y después completá sus datos y canal de contacto.</span></div><button type="submit">＋ Agregar otro proveedor</button></form>
        <?php
    }

    public static function render_order( $order ) {
        $key = (string) $order->get_meta( '_ge_production_supplier' ); $profile = self::profile( $key ); $history = (array) $order->get_meta( '_ge_supplier_dispatch_history' ); $last = $history ? end( $history ) : array();
        ?>
        <section class="ge-production-card ge-dispatch-card"><div class="ge-production-section-head"><div><span>Salida a proveedor</span><h2>Orden al proveedor</h2></div><?php if ( $last ) : ?><b><?php echo esc_html( self::history_label( $last ) ); ?></b><?php endif; ?></div>
        <?php if ( ! $profile ) : ?><div class="ge-dispatch-empty"><strong>Asigná un proveedor concreto.</strong><span>Los pedidos con proveedor pendiente o múltiple no se envían automáticamente.</span></div><?php else : ?><div class="ge-dispatch-grid"><div><small>Destino</small><strong><?php echo esc_html( $profile['name'] ); ?></strong><span><?php echo esc_html( $profile['email'] ?: 'Sin email' ); ?></span><span><?php echo esc_html( $profile['whatsapp'] ?: 'Sin WhatsApp' ); ?></span></div><div class="ge-dispatch-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_supplier_email"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_supplier_email_' . $order->get_id() ); ?><button class="ge-staff-button" type="submit" <?php disabled( ! is_email( $profile['email'] ) ); ?>>Enviar orden por email</button></form><?php if ( self::normalize_phone( $profile['whatsapp'] ) ) : ?><a target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ge_supplier_whatsapp', 'order_id' => $order->get_id() ), admin_url( 'admin-post.php' ) ), 'ge_supplier_whatsapp_' . $order->get_id() ) ); ?>">Abrir orden en WhatsApp ↗</a><?php else : ?><span>WhatsApp sin configurar</span><?php endif; ?></div></div><?php if ( $profile['notes'] ) : ?><p class="ge-dispatch-notes"><?php echo nl2br( esc_html( $profile['notes'] ) ); ?></p><?php endif; ?><?php endif; ?>
        <?php if ( $history ) : ?><details class="ge-dispatch-history"><summary>Historial de envíos (<?php echo esc_html( count( $history ) ); ?>)</summary><ul><?php foreach ( array_reverse( $history ) as $entry ) : ?><li><?php echo esc_html( wp_date( 'd/m/Y H:i', absint( $entry['time'] ?? 0 ) ) . ' · ' . self::history_label( $entry ) ); ?></li><?php endforeach; ?></ul></details><?php endif; ?></section>
        <?php
    }

    public static function maybe_auto_dispatch( $order ) {
        if ( ! $order instanceof WC_Order || $order->get_meta( '_ge_supplier_auto_dispatch_at' ) ) { return; }
        $profile = self::profile( $order->get_meta( '_ge_production_supplier' ) );
        if ( ! $profile || 'yes' !== $profile['auto_email'] || ! is_email( $profile['email'] ) ) { return; }
        $sent = self::send_email( $order, $profile ); self::log( $order, 'auto-email', $sent ); if ( ! $sent ) { self::notify_failure( $order, $profile ); }
        $order->update_meta_data( '_ge_supplier_auto_dispatch_at', current_time( 'mysql' ) ); $order->save();
    }

    public static function handle_profiles() {
        self::guard(); check_admin_referer( 'ge_supplier_profiles_save' ); $incoming = (array) ( $_POST['profiles'] ?? array() ); $allowed = self::profiles(); $clean = array();
        foreach ( $allowed as $key => $profile ) { $row = isset( $incoming[ $key ] ) ? (array) $incoming[ $key ] : array(); $channel = sanitize_key( wp_unslash( $row['channel'] ?? 'manual' ) ); $clean[ $key ] = array( 'name' => sanitize_text_field( wp_unslash( $row['name'] ?? $profile['name'] ) ), 'email' => sanitize_email( wp_unslash( $row['email'] ?? '' ) ), 'whatsapp' => sanitize_text_field( wp_unslash( $row['whatsapp'] ?? '' ) ), 'channel' => in_array( $channel, array( 'manual', 'email', 'whatsapp' ), true ) ? $channel : 'manual', 'auto_email' => ! empty( $row['auto_email'] ) ? 'yes' : 'no', 'notes' => sanitize_textarea_field( wp_unslash( $row['notes'] ?? '' ) ) ); }
        update_option( self::OPTION, $clean, false ); wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'view' => 'suppliers', 'supplier_saved' => 1 ) ) ); exit;
    }

    public static function handle_add() {
        self::guard(); check_admin_referer( 'ge_supplier_add' );
        $profiles = get_option( self::OPTION, array() ); $profiles = is_array( $profiles ) ? $profiles : array();
        $key = 'custom-' . time(); while ( isset( $profiles[ $key ] ) ) { $key .= '-1'; }
        $profiles[ $key ] = array( 'name' => 'Nuevo proveedor', 'email' => '', 'whatsapp' => '', 'channel' => 'manual', 'auto_email' => 'no', 'notes' => 'Completá acá los productos, tiempos y condiciones de trabajo.' );
        update_option( self::OPTION, $profiles, false );
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'view' => 'suppliers', 'supplier_added' => 1 ) ) ); exit;
    }

    public static function handle_email() {
        self::guard(); $order = self::order(); check_admin_referer( 'ge_supplier_email_' . $order->get_id() ); $profile = self::profile( $order->get_meta( '_ge_production_supplier' ) ); $sent = $profile && is_email( $profile['email'] ) ? self::send_email( $order, $profile ) : false; self::log( $order, 'email', $sent ); if ( ! $sent ) { self::notify_failure( $order, $profile ); } self::redirect_order( $order, $sent ? 'supplier-sent' : 'supplier-failed' );
    }

    public static function handle_whatsapp() {
        self::guard(); $order = self::order(); check_admin_referer( 'ge_supplier_whatsapp_' . $order->get_id() ); $profile = self::profile( $order->get_meta( '_ge_production_supplier' ) ); $phone = $profile ? self::normalize_phone( $profile['whatsapp'] ) : ''; if ( ! $phone ) { self::redirect_order( $order, 'supplier-failed' ); }
        self::log( $order, 'whatsapp', true ); wp_redirect( 'https://wa.me/' . rawurlencode( $phone ) . '?text=' . rawurlencode( self::whatsapp_message( $order ) ) ); exit;
    }

    private static function send_email( $order, $profile ) {
        $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : ( $order->get_meta( '_ge_markcom_reference' ) ?: '#' . $order->get_id() ); $rows = '';
        foreach ( $order->get_items( 'line_item' ) as $item ) { $rows .= '<tr><td style="padding:10px;border-bottom:1px solid #ddd"><strong>' . esc_html( $item->get_name() ) . '</strong><br>' . wp_kses_post( wc_display_item_meta( $item, array( 'echo' => false, 'separator' => ' · ' ) ) ) . '</td><td style="padding:10px;border-bottom:1px solid #ddd;text-align:right">' . esc_html( $item->get_quantity() ) . '</td></tr>'; }
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#17152a"><div style="max-width:680px;margin:auto"><div style="padding:18px 22px;background:#17152a;color:#fff"><strong>GRAPH EXPRESS · ORDEN A PROVEEDOR</strong></div><div style="padding:25px;border:1px solid #ddd"><h1>' . esc_html( $reference ) . '</h1><p>Solicitamos producir los siguientes trabajos para la fecha <strong>' . esc_html( GE_WTP_Production::date_label_public( $order->get_meta( '_ge_production_promised_date' ) ) ) . '</strong>.</p><table style="width:100%;border-collapse:collapse"><tr><th style="padding:10px;text-align:left;background:#eee">Producto</th><th style="padding:10px;text-align:right;background:#eee">Cantidad</th></tr>' . $rows . '</table><h3>Indicaciones</h3><p style="white-space:pre-wrap">' . esc_html( $order->get_meta( '_ge_production_technical_notes' ) ?: 'Sin indicaciones adicionales.' ) . '</p><p style="color:#777;font-size:12px">Confirmar recepción y fecha posible de entrega respondiendo este correo.</p></div></div></body></html>';
        return GE_WTP_Notifications::send( $profile['email'], 'Orden de producción ' . $reference . ' · Graph Express', $html, 'supplier_order', $order->get_id() );
    }

    private static function whatsapp_message( $order ) {
        $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : ( $order->get_meta( '_ge_markcom_reference' ) ?: '#' . $order->get_id() ); $lines = array( 'Hola, enviamos la orden de producción ' . $reference . '.', 'Fecha solicitada: ' . GE_WTP_Production::date_label_public( $order->get_meta( '_ge_production_promised_date' ) ) . '.', 'Trabajos:' ); foreach ( $order->get_items( 'line_item' ) as $item ) { $lines[] = '- ' . $item->get_name() . ' · cantidad ' . $item->get_quantity(); } if ( $order->get_meta( '_ge_production_technical_notes' ) ) { $lines[] = 'Indicaciones: ' . $order->get_meta( '_ge_production_technical_notes' ); } $lines[] = 'Por favor confirmar recepción y fecha de entrega.'; return implode( "\n", $lines );
    }

    private static function log( $order, $channel, $success ) { $history = (array) $order->get_meta( '_ge_supplier_dispatch_history' ); $history[] = array( 'time' => time(), 'channel' => sanitize_key( $channel ), 'success' => (bool) $success, 'user_id' => get_current_user_id() ); $order->update_meta_data( '_ge_supplier_dispatch_history', array_slice( $history, -50 ) ); $order->save(); }
    private static function notify_failure( $order, $profile ) { if ( ! class_exists( 'GE_WTP_Notification_Center' ) ) { return; } $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id(); GE_WTP_Notification_Center::send_internal( 'supplier_failure', 'No se pudo enviar al proveedor · ' . $reference, '<p>Falló el envío de la orden <strong>' . esc_html( $reference ) . '</strong> a ' . esc_html( $profile['name'] ?? 'el proveedor asignado' ) . '.</p><p><a href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id() ) ) ) . '">Revisar orden</a></p>', $order->get_id() ); }
    private static function history_label( $entry ) { $channel = $entry['channel'] ?? ''; if ( 'whatsapp' === $channel ) { return 'WhatsApp abierto'; } if ( 'auto-email' === $channel ) { return ! empty( $entry['success'] ) ? 'Email automático enviado' : 'Email automático fallido'; } return ! empty( $entry['success'] ) ? 'Email enviado' : 'Email fallido'; }
    private static function normalize_phone( $phone ) { $digits = ltrim( preg_replace( '/\D+/', '', (string) $phone ), '0' ); if ( 10 === strlen( $digits ) && 0 === strpos( $digits, '11' ) ) { $digits = '549' . $digits; } elseif ( 11 === strlen( $digits ) && 0 === strpos( $digits, '9' ) ) { $digits = '54' . $digits; } return $digits; }
    private static function guard() { if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); } }
    private static function order() { $order = wc_get_order( absint( $_REQUEST['order_id'] ?? 0 ) ); if ( ! $order ) { wp_die( 'Pedido inválido.', 404 ); } return $order; }
    private static function redirect_order( $order, $status ) { wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id(), 'dispatch_status' => $status ) ) ); exit; }
}
