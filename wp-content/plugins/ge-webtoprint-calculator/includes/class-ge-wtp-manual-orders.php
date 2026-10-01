<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Manual_Orders {
    public static function init() {
        add_action( 'admin_post_ge_manual_order_create', array( __CLASS__, 'handle_create' ) );
        add_action( 'admin_post_ge_manual_invite_email', array( __CLASS__, 'handle_invite_email' ) );
        add_action( 'admin_post_ge_manual_invite_whatsapp', array( __CLASS__, 'handle_invite_whatsapp' ) );
        add_action( 'woocommerce_created_customer', array( __CLASS__, 'link_guest_orders' ), 35 );
    }

    public static function render() {
        wp_enqueue_script( 'ge-manual-orders', GE_WTP_PLUGIN_URL . 'assets/js/manual-orders.js', array(), GE_WTP_VERSION, true );
        $same_customer_order = absint( $_GET['same_customer_order'] ?? 0 );
        $source_order = $same_customer_order && GE_WTP_Staff_Portal::can_access() ? wc_get_order( $same_customer_order ) : false;
        $source_customer = ! $source_order && GE_WTP_Staff_Portal::can_access() ? get_userdata( absint( $_GET['customer_id'] ?? 0 ) ) : false;
        $customer_name = $source_order ? trim( $source_order->get_billing_first_name() . ' ' . $source_order->get_billing_last_name() ) : '';
        if ( $source_customer ) { $customer_name = $source_customer->display_name; }
        if ( $source_order && ! $customer_name ) { $customer_name = $source_order->get_billing_company() ?: $source_order->get_meta( '_ge_manual_contact_name', true ); }
        $products = self::products(); $catalog = array();
        foreach ( $products as $product ) {
            $label = $product->get_name() . ' (#' . $product->get_id() . ')';
            $catalog[ $label ] = array( 'id' => $product->get_id(), 'price' => (float) wc_get_price_to_display( $product ) );
        }
        ?>
        <div class="ge-staff-heading"><div><span>Mostrador</span><h1><?php echo $source_order ? 'Nuevo pedido al mismo cliente' : 'Nuevo trabajo manual'; ?></h1><p><?php echo $source_order ? 'Los datos de contacto están precargados. El pedido y sus productos serán nuevos.' : 'Tomá un pedido sin obligar al cliente a crear una cuenta.'; ?></p></div></div>
        <?php self::render_error(); ?>
        <form class="ge-manual-order" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_manual_order_create"><?php if ( $source_customer ) : ?><input type="hidden" name="source_customer_id" value="<?php echo esc_attr( $source_customer->ID ); ?>"><?php endif; ?><?php wp_nonce_field( 'ge_manual_order_create' ); ?>
            <section class="ge-production-card"><div class="ge-production-section-head"><div><span>01 · Contacto</span><h2>¿Quién encarga el trabajo?</h2></div><p>No hace falta registrarlo.</p></div><div class="ge-manual-contact-grid"><label>Nombre o razón social<input type="text" name="customer_name" required maxlength="160" value="<?php echo esc_attr( $customer_name ); ?>" placeholder="Nombre del cliente"></label><label>Email<input type="email" name="customer_email" maxlength="190" value="<?php echo esc_attr( $source_order ? $source_order->get_billing_email() : ( $source_customer ? $source_customer->user_email : '' ) ); ?>" placeholder="cliente@email.com"></label><label>WhatsApp / teléfono<input type="tel" name="customer_phone" maxlength="50" value="<?php echo esc_attr( $source_order ? $source_order->get_billing_phone() : ( $source_customer ? get_user_meta( $source_customer->ID, '_ge_whatsapp', true ) : '' ) ); ?>" placeholder="11 0000-0000"></label><label>Modalidad<select name="delivery_method"><option value="coordinate" <?php selected( $source_order ? $source_order->get_meta( '_ge_manual_delivery_method', true ) : '', 'coordinate' ); ?>>A coordinar</option><option value="pickup" <?php selected( $source_order ? $source_order->get_meta( '_ge_manual_delivery_method', true ) : '', 'pickup' ); ?>>Retira por Graph Express</option><option value="delivery" <?php selected( $source_order ? $source_order->get_meta( '_ge_manual_delivery_method', true ) : '', 'delivery' ); ?>>Requiere envío</option></select></label></div><p class="ge-manual-help">Completá al menos un email o un teléfono. Si carga un email, recibirá el detalle del pedido y un enlace opcional para registrarse.</p></section>
            <section class="ge-production-card" data-ge-branch-picker data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ge_customer_branch_options' ) ); ?>"><div class="ge-production-section-head"><div><span>Facturación y entrega</span><h2>Sucursal del cliente</h2></div></div><div class="ge-manual-contact-grid"><label>Facturar a<select name="billing_profile_id" data-ge-billing-profile><option value="default">Perfil principal</option></select></label><label>Entregar en<select name="delivery_address_id" data-ge-delivery-address><option value="">A coordinar</option></select></label></div></section>
            <section class="ge-production-card"><div class="ge-production-section-head"><div><span>02 · Trabajo</span><h2>Productos y especificaciones</h2></div><button class="ge-manual-add-line" type="button" data-ge-add-line>＋ Agregar producto</button></div><div class="ge-manual-lines" data-ge-lines><?php self::line_markup( 0 ); ?></div><datalist id="ge-manual-products"><?php foreach ( array_keys( $catalog ) as $label ) : ?><option value="<?php echo esc_attr( $label ); ?>"></option><?php endforeach; ?></datalist><script type="application/json" id="ge-manual-catalog"><?php echo wp_json_encode( $catalog ); ?></script><template id="ge-manual-line-template"><?php self::line_markup( '__INDEX__' ); ?></template></section>
            <section class="ge-production-card"><div class="ge-production-section-head"><div><span>03 · Planificación</span><h2>Precio, plazo y observaciones</h2></div></div><div class="ge-manual-plan-grid"><label>Fecha prometida<input type="date" name="promised_date" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"></label><label>Prioridad<select name="priority"><option value="normal">Normal</option><option value="urgent">Urgente</option><option value="critical">Crítica</option></select></label><label>Estado del cobro<select name="payment_state"><option value="pending">Pendiente</option><option value="paid">Pagado</option><option value="account">Cuenta corriente</option></select></label><label class="is-wide">Notas del pedido<textarea name="order_notes" rows="4" maxlength="3000" placeholder="Material, medidas, colores, terminaciones, forma de entrega..."></textarea></label></div></section>
            <div class="ge-manual-summary"><div><strong>El pedido entrará en revisión y planificación</strong><span>Podrás revisar los archivos, confirmar el proveedor y decidir si necesita preproducción antes de liberarlo.</span></div><button class="ge-staff-button" type="submit">Crear pedido y revisar →</button></div>
        </form>
        <?php
    }

    public static function reference( $order ) {
        if ( ! $order instanceof WC_Order ) { return ''; }
        return $order->get_meta( '_ge_markcom_reference' ) ?: ( $order->get_meta( '_ge_manual_reference' ) ?: '#' . $order->get_id() );
    }

    private static function line_markup( $index ) {
        ?><div class="ge-manual-line" data-ge-line><label class="is-product">Buscar producto o escribir uno personalizado<input type="search" name="lines[<?php echo esc_attr( $index ); ?>][label]" list="ge-manual-products" required maxlength="200" placeholder="Ej.: Talonario AFIP"><input type="hidden" name="lines[<?php echo esc_attr( $index ); ?>][product_id]" value=""></label><label>Cantidad<input type="number" name="lines[<?php echo esc_attr( $index ); ?>][quantity]" required min="1" step="1" value="1"></label><label>Precio unitario ARS<input type="number" name="lines[<?php echo esc_attr( $index ); ?>][unit_price]" min="0" step="0.01" value="0"></label><label class="is-detail">Medida y especificaciones<input type="text" name="lines[<?php echo esc_attr( $index ); ?>][details]" maxlength="500" placeholder="Medida, material, impresión..."></label><label class="is-file">Archivo recibido (opcional)<input type="file" name="artwork_files[<?php echo esc_attr( $index ); ?>][]" accept=".pdf,.jpg,.jpeg,.png,.zip,.ai,.eps,.psd,.tif,.tiff,.svg,.cdr" multiple></label><button type="button" data-ge-remove-line aria-label="Quitar producto">×</button></div><?php
    }

    public static function handle_create() {
        self::guard(); check_admin_referer( 'ge_manual_order_create' );
        $name = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) ); $email = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) ); $phone = sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) );
        if ( ! $name || ( ! $email && ! $phone ) || ( ! empty( $_POST['customer_email'] ) && ! is_email( $email ) ) ) { self::redirect_error( 'contact' ); }
        $lines = self::clean_lines( (array) ( $_POST['lines'] ?? array() ) ); if ( ! $lines ) { self::redirect_error( 'items' ); }
        $source_customer_id = absint( $_POST['source_customer_id'] ?? 0 );
        $source_customer = $source_customer_id ? get_userdata( $source_customer_id ) : false;
        if ( $source_customer_id && ( ! $source_customer || ! $email || 0 !== strcasecmp( $source_customer->user_email, $email ) ) ) { self::redirect_error( 'contact' ); }
        $customer_id = $source_customer_id ?: ( $email ? absint( email_exists( $email ) ) : 0 );
        $profile_id = sanitize_text_field( wp_unslash( $_POST['billing_profile_id'] ?? 'default' ) );
        $delivery_id = sanitize_text_field( wp_unslash( $_POST['delivery_address_id'] ?? '' ) );
        $profile = $customer_id ? GE_WTP_Customer_Branches::find( $customer_id, $profile_id ) : null;
        $delivery = $customer_id && '' !== $delivery_id ? GE_WTP_Customer_Branches::delivery( $customer_id, $delivery_id ) : null;
        if ( $customer_id && ! $profile || '' !== $delivery_id && ! $delivery ) { wp_die( 'Perfil o dirección de este cliente inválidos.', 422 ); }
        $order = wc_create_order( array( 'customer_id' => $customer_id ) ); if ( is_wp_error( $order ) ) { self::redirect_error( 'create' ); }
        $parts = preg_split( '/\s+/', trim( $name ), 2 ); $order->set_billing_first_name( $parts[0] ?? $name ); $order->set_billing_last_name( $parts[1] ?? '' ); $order->set_billing_email( $email ); $order->set_billing_phone( $phone ); $order->set_currency( 'ARS' );
        if ( $profile ) {
            $order->set_billing_company( $profile['legal_name'] );
            $order->set_billing_address_1( $profile['fiscal_address'] );
            $order->update_meta_data( '_ge_billing_profile_id', $profile_id );
            $order->update_meta_data( '_ge_billing_profile_snapshot', $profile );
        }
        if ( $delivery ) {
            $order->set_shipping_company( $delivery['label'] );
            $order->set_shipping_first_name( $delivery['recipient'] );
            $order->set_shipping_address_1( $delivery['street'] );
            $order->set_shipping_city( $delivery['city'] );
            $order->set_shipping_state( $delivery['province'] );
            $order->set_shipping_postcode( $delivery['postal_code'] );
            $order->update_meta_data( '_ge_delivery_snapshot', $delivery );
        }
        $initial_payment_state = sanitize_key( wp_unslash( $_POST['payment_state'] ?? 'pending' ) );
        $order->set_payment_method_title( self::payment_label( $initial_payment_state ) );
        $order->set_customer_note( sanitize_textarea_field( wp_unslash( $_POST['order_notes'] ?? '' ) ) );
        $order->update_meta_data( '_ge_manual_order', 'yes' ); $order->update_meta_data( '_ge_manual_source', 'counter' ); $order->update_meta_data( '_ge_manual_contact_name', $name ); $order->update_meta_data( '_ge_manual_delivery_method', sanitize_key( wp_unslash( $_POST['delivery_method'] ?? 'coordinate' ) ) );
        foreach ( $lines as $line ) { self::add_line( $order, $line ); }
        $order->calculate_totals( false );
        if ( 'paid' === $initial_payment_state ) { $order->set_date_paid( current_time( 'timestamp', true ) ); $order->update_meta_data( '_ge_payment_state', 'paid' ); $order->update_meta_data( '_ge_payment_confirmed_at', current_time( 'mysql' ) ); $order->update_meta_data( '_ge_payment_confirmed_total', $order->get_total() ); $order->update_meta_data( '_ge_payment_confirmed_by', get_current_user_id() ); }
        GE_WTP_Workflow::enable( $order ); $order->set_status( 'ge-confirmado', 'Trabajo cargado manualmente para revisión.' ); $order->save();
        $reference = sprintf( 'GE-MAN-%s-%05d', current_time( 'Y' ), $order->get_id() ); $order->update_meta_data( '_ge_manual_reference', $reference ); $order->save();
        if ( class_exists( 'GE_WTP_Order_Lifecycle' ) ) { GE_WTP_Order_Lifecycle::set_stage( $order, 'recibido' ); }
        GE_WTP_Production::ensure_order( $order );
        self::save_initial_artwork( $order, $lines );
        $date = sanitize_text_field( wp_unslash( $_POST['promised_date'] ?? '' ) ); if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { $order->update_meta_data( '_ge_production_promised_date', $date ); $order->update_meta_data( '_ge_estimated_date', $date ); $order->update_meta_data( '_ge_production_assignment_reason', $order->get_meta( '_ge_production_assignment_reason' ) . ' Fecha ajustada manualmente al tomar el pedido.' ); }
        $priority = sanitize_key( wp_unslash( $_POST['priority'] ?? 'normal' ) ); $order->update_meta_data( '_ge_production_priority', array_key_exists( $priority, GE_WTP_Production::priorities() ) ? $priority : 'normal' ); $order->save();
        if ( $email && class_exists( 'GE_WTP_Notifications' ) ) { GE_WTP_Notifications::send_order_created( $order ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id(), 'step' => 'review', 'manual_created' => 1 ) ) ); exit;
    }

    public static function render_order_contact( $order ) {
        if ( 'yes' !== $order->get_meta( '_ge_manual_order' ) ) { return; }
        $email = $order->get_billing_email(); $phone = $order->get_billing_phone();
        ?><section class="ge-production-card ge-manual-customer"><div><span>Pedido de mostrador</span><h2><?php echo esc_html( $order->get_meta( '_ge_manual_contact_name' ) ?: $order->get_formatted_billing_full_name() ); ?></h2><p><?php echo esc_html( implode( ' · ', array_filter( array( $email, $phone ) ) ) ); ?></p></div><div class="ge-manual-invite-actions"><?php if ( $email ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_manual_invite_email"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_manual_invite_' . $order->get_id() ); ?><button type="submit">Enviar invitación por email</button></form><?php endif; ?><?php if ( self::normalize_phone( $phone ) ) : ?><a target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ge_manual_invite_whatsapp', 'order_id' => $order->get_id() ), admin_url( 'admin-post.php' ) ), 'ge_manual_invite_' . $order->get_id() ) ); ?>">Invitar por WhatsApp ↗</a><?php endif; ?></div></section><?php
    }

    public static function handle_invite_email() {
        self::guard(); $order = self::order(); check_admin_referer( 'ge_manual_invite_' . $order->get_id() ); $email = $order->get_billing_email(); $url = wc_get_page_permalink( 'myaccount' );
        $html = '<p>Hola ' . esc_html( $order->get_billing_first_name() ?: '¿cómo estás?' ) . ',</p><p>Podés crear tu cuenta de Graph Express para consultar pedidos y repetir trabajos más rápido.</p><p><a href="' . esc_url( $url ) . '">Crear mi cuenta</a></p><p>Registrate con el mismo email utilizado en tu pedido.</p>';
        $sent = $email ? GE_WTP_Notifications::send( $email, 'Guardá tus pedidos · Graph Express', $html, 'manual_customer_invite', $order->get_id() ) : false;
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id(), 'manual_invite' => $sent ? 'sent' : 'failed' ) ) ); exit;
    }

    public static function handle_invite_whatsapp() {
        self::guard(); $order = self::order(); check_admin_referer( 'ge_manual_invite_' . $order->get_id() ); $phone = self::normalize_phone( $order->get_billing_phone() ); if ( ! $phone ) { wp_die( 'El pedido no tiene un teléfono válido.' ); }
        $message = 'Hola ' . ( $order->get_billing_first_name() ?: '' ) . ', tu pedido ya está registrado en Graph Express. Si querés guardar el historial y repetir trabajos más rápido, podés crear tu cuenta con el mismo email acá: ' . wc_get_page_permalink( 'myaccount' );
        wp_redirect( 'https://wa.me/' . rawurlencode( $phone ) . '?text=' . rawurlencode( $message ) ); exit;
    }

    public static function link_guest_orders( $customer_id ) {
        $user = get_userdata( absint( $customer_id ) ); if ( ! $user || ! is_email( $user->user_email ) ) { return; }
        $orders = wc_get_orders( array( 'customer_id' => 0, 'billing_email' => $user->user_email, 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );
        foreach ( $orders as $order ) { if ( 'yes' !== $order->get_meta( '_ge_manual_order' ) ) { continue; } $order->set_customer_id( $customer_id ); $order->add_order_note( 'Pedido de mostrador vinculado automáticamente al registrarse el cliente.' ); $order->save(); }
    }

    private static function products() { return function_exists( 'wc_get_products' ) ? wc_get_products( array( 'status' => 'publish', 'limit' => 500, 'orderby' => 'name', 'order' => 'ASC' ) ) : array(); }
    private static function clean_lines( $posted ) { $clean = array(); foreach ( array_slice( $posted, 0, 20, true ) as $index => $line ) { $label = sanitize_text_field( wp_unslash( $line['label'] ?? '' ) ); $quantity = absint( $line['quantity'] ?? 0 ); if ( ! $label || ! $quantity ) { continue; } $clean[] = array( 'source_index' => absint( $index ), 'label' => $label, 'product_id' => absint( $line['product_id'] ?? 0 ), 'quantity' => $quantity, 'price' => max( 0, (float) ( $line['unit_price'] ?? 0 ) ), 'details' => sanitize_text_field( wp_unslash( $line['details'] ?? '' ) ) ); } return $clean; }
    private static function save_initial_artwork( $order, $lines ) {
        if ( empty( $_FILES['artwork_files']['name'] ) || ! is_array( $_FILES['artwork_files']['name'] ) ) { return; }
        $item_ids = array_keys( $order->get_items( 'line_item' ) );
        foreach ( $lines as $position => $line ) {
            $index = $line['source_index']; $item_id = absint( $item_ids[ $position ] ?? 0 );
            if ( ! $item_id || empty( $_FILES['artwork_files']['name'][ $index ] ) ) { continue; }
            $field = 'ge_manual_artwork_' . $index;
            $_FILES[ $field ] = array(
                'name' => $_FILES['artwork_files']['name'][ $index ],
                'type' => $_FILES['artwork_files']['type'][ $index ] ?? array(),
                'tmp_name' => $_FILES['artwork_files']['tmp_name'][ $index ] ?? array(),
                'error' => $_FILES['artwork_files']['error'][ $index ] ?? array(),
                'size' => $_FILES['artwork_files']['size'][ $index ] ?? array(),
            );
            GE_WTP_Documents::handle_uploaded_files( $order->get_id(), $field, 'arte', array( 'order_item_id' => $item_id, 'artwork_side' => 'general' ) );
            unset( $_FILES[ $field ] );
        }
    }
    private static function add_line( $order, $line ) { $product = $line['product_id'] ? wc_get_product( $line['product_id'] ) : false; $total = round( $line['price'] * $line['quantity'], 2 ); if ( $product ) { $item_id = $order->add_product( $product, $line['quantity'], array( 'subtotal' => $total, 'total' => $total ) ); $item = $order->get_item( $item_id ); } else { $item = new WC_Order_Item_Product(); $item->set_name( preg_replace( '/\s*\(#\d+\)$/', '', $line['label'] ) ); $item->set_quantity( $line['quantity'] ); $item->set_subtotal( $total ); $item->set_total( $total ); $order->add_item( $item ); } if ( $item && $line['details'] ) { $item->add_meta_data( 'Especificaciones', $line['details'], true ); $item->save(); } }
    private static function payment_label( $state ) { return array( 'paid' => 'Pagado en el local', 'account' => 'Cuenta corriente', 'pending' => 'Pago pendiente' )[ $state ] ?? 'Pago pendiente'; }
    private static function normalize_phone( $phone ) { $digits = ltrim( preg_replace( '/\D+/', '', (string) $phone ), '0' ); if ( 10 === strlen( $digits ) && 0 === strpos( $digits, '11' ) ) { return '549' . $digits; } if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '9' ) ) { return '54' . $digits; } return $digits; }
    private static function render_error() { $error = sanitize_key( wp_unslash( $_GET['manual_error'] ?? '' ) ); if ( ! $error ) { return; } $messages = array( 'contact' => 'Completá el nombre y al menos un email o teléfono válido.', 'items' => 'Agregá por lo menos un producto o trabajo personalizado.', 'create' => 'No se pudo crear el pedido. Volvé a intentarlo.' ); echo '<div class="ge-production-notice is-error">' . esc_html( $messages[ $error ] ?? 'Revisá los datos del pedido.' ) . '</div>'; }
    private static function redirect_error( $code ) { wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'view' => 'new', 'manual_error' => $code ) ) ); exit; }
    private static function guard() { if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); } }
    private static function order() { $order = wc_get_order( absint( $_REQUEST['order_id'] ?? 0 ) ); if ( ! $order || 'yes' !== $order->get_meta( '_ge_manual_order' ) ) { wp_die( 'Pedido inválido.', 404 ); } return $order; }
}
