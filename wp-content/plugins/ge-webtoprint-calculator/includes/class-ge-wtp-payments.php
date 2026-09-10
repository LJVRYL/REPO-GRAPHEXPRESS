<?php

defined( 'ABSPATH' ) || exit;

/**
 * Medios de pago propios de Graph Express.
 *
 * Mantiene los pedidos en ARS. La transferencia recibe un descuento automático
 * y PayPal conserva en el pedido una conversión USD basada en la cotización BNA.
 */
final class GE_WTP_Payments {
    const BANK_DISCOUNT = 10;
    const MP_SURCHARGE = 10;
    const PAYPAL_GATEWAY = 'ge_paypal_usd';
    const MP_FEE_META = '_ge_mp_surcharge';

    public static function init() {
        add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_paypal_gateway' ) );
        add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember_payment_method' ) );
        add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'bank_transfer_discount' ), 30 );
        add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'gateway_title' ), 20, 2 );
        add_filter( 'woocommerce_gateway_description', array( __CLASS__, 'gateway_description' ), 20, 2 );
        add_action( 'woocommerce_thankyou_' . self::PAYPAL_GATEWAY, array( __CLASS__, 'paypal_instructions' ) );
        add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'email_paypal_instructions' ), 20, 4 );
        add_filter( 'woocommerce_valid_order_statuses_for_payment', array( __CLASS__, 'allow_portal_order_payment' ), 20, 2 );
        add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'allow_portal_order_payment' ), 20, 2 );
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'limit_order_pay_to_mercadopago' ), 90 );
        add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'apply_mercadopago_surcharge' ), 20 );
        add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'preserve_production_status_after_payment' ), 20, 3 );
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'record_completed_payment' ), 20 );
        add_action( 'admin_post_ge_portal_upload_receipt', array( __CLASS__, 'handle_receipt_upload' ) );
        add_action( 'admin_post_ge_staff_confirm_transfer', array( __CLASS__, 'handle_staff_confirm_transfer' ) );
        add_action( 'admin_post_ge_staff_send_payment_instructions', array( __CLASS__, 'handle_staff_send_payment_instructions' ) );
    }

    public static function register_paypal_gateway( $gateways ) {
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) { return $gateways; }
        require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-paypal-usd-gateway.php';
        $gateways[] = 'GE_WTP_PayPal_USD_Gateway';
        return $gateways;
    }

    public static function remember_payment_method( $posted_data ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) { return; }
        parse_str( (string) $posted_data, $data );
        if ( ! empty( $data['payment_method'] ) ) {
            WC()->session->set( 'chosen_payment_method', sanitize_key( $data['payment_method'] ) );
        }
    }

    public static function bank_transfer_discount( $cart ) {
        if ( ( is_admin() && ! wp_doing_ajax() ) || ! $cart || $cart->is_empty() ) { return; }
        $method = function_exists( 'WC' ) && WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
        if ( 'bacs' !== $method ) { return; }
        $base = max( 0, (float) $cart->get_cart_contents_total() );
        if ( $base > 0 ) {
            $cart->add_fee( 'Descuento por transferencia (10%)', -round( $base * self::BANK_DISCOUNT / 100, wc_get_price_decimals() ), false );
        }
    }

    public static function gateway_title( $title, $gateway_id ) {
        return 'bacs' === $gateway_id ? 'Transferencia bancaria — 10% de descuento' : $title;
    }

    public static function gateway_description( $description, $gateway_id ) {
        if ( 'bacs' === $gateway_id ) {
            return 'Recibís un 10% de descuento automático sobre los productos. Los datos del Banco Ciudad aparecen al finalizar el pedido y en el correo de confirmación.';
        }
        return $description;
    }

    public static function paypal_instructions( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || self::PAYPAL_GATEWAY !== $order->get_payment_method() ) { return; }
        echo wp_kses_post( self::paypal_message( $order ) );
    }

    public static function email_paypal_instructions( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin || ! $order || self::PAYPAL_GATEWAY !== $order->get_payment_method() ) { return; }
        $usd       = (float) $order->get_meta( '_ge_paypal_usd_amount' );
        $rate      = (float) $order->get_meta( '_ge_paypal_bna_rate' );
        $recipient = sanitize_email( $order->get_meta( '_ge_paypal_recipient' ) );
        if ( $plain_text ) {
            echo "\nPAGO POR PAYPAL\n" . sprintf( "Enviar USD %1$s a %2$s. Cotización BNA fijada: ARS %3$s por USD.\n", number_format( $usd, 2, '.', '' ), $recipient, number_format( $rate, 2, ',', '.' ) );
            return;
        }
        echo wp_kses_post( self::paypal_message( $order ) );
    }

    private static function paypal_message( $order ) {
        $usd       = (float) $order->get_meta( '_ge_paypal_usd_amount' );
        $rate      = (float) $order->get_meta( '_ge_paypal_bna_rate' );
        $recipient = sanitize_email( $order->get_meta( '_ge_paypal_recipient' ) );
        return '<section class="woocommerce-order-details"><h2>Pago por PayPal</h2><p>Enviá <strong>USD ' . esc_html( number_format( $usd, 2, ',', '.' ) ) . '</strong> a <strong>' . esc_html( $recipient ) . '</strong>.</p><p>Conversión fijada al confirmar: dólar vendedor Banco Nación, ARS ' . esc_html( number_format( $rate, 2, ',', '.' ) ) . ' por USD. El pedido quedará pendiente hasta verificar el pago.</p></section>';
    }

    public static function render_portal_order_payment( $order ) {
        if ( ! $order instanceof WC_Order || ! class_exists( 'GE_WTP_Documents' ) || ! GE_WTP_Documents::can_access_order( $order ) ) { return; }
        $payment_state = sanitize_key( (string) $order->get_meta( '_ge_payment_state' ) );
        $is_paid = $order->is_paid() || 'paid' === $payment_state;
        $base_total = self::base_total( $order );
        $mp_surcharge = round( $base_total * self::MP_SURCHARGE / 100, wc_get_price_decimals() );
        $mp_total = $base_total + $mp_surcharge;
        $mp_ready = self::mercadopago_ready();
        $notice = isset( $_GET['ge_payment_notice'] ) ? sanitize_key( wp_unslash( $_GET['ge_payment_notice'] ) ) : '';
        $bank = self::bank_details();
        ?>
        <section class="ge-payment-panel">
            <div class="ge-payment-head"><div><span class="ge-eyebrow">Pago del pedido</span><h2><?php echo $is_paid ? 'Pago confirmado' : 'Elegí cómo pagar'; ?></h2><p><?php echo $is_paid ? 'El pago ya quedó asociado a esta orden.' : 'Podés transferir el importe normal o pagar online mediante Mercado Pago.'; ?></p></div><strong><?php echo wp_kses_post( wc_price( $base_total, array( 'currency' => $order->get_currency() ) ) ); ?></strong></div>
            <?php if ( 'receipt-uploaded' === $notice ) : ?><div class="ge-payment-notice">Comprobante recibido. Graph Express verificará la transferencia y actualizará el estado del pago.</div><?php elseif ( 'receipt-error' === $notice ) : ?><div class="ge-payment-notice is-error">No pudimos guardar el comprobante. Usá PDF, JPG o PNG y volvé a intentar.</div><?php endif; ?>
            <?php if ( $is_paid ) : ?>
                <div class="ge-payment-confirmed"><b>✓</b><span><strong><?php echo esc_html( $order->get_payment_method_title() ?: 'Pago recibido' ); ?></strong><small><?php echo esc_html( $order->get_date_paid() ? 'Acreditado el ' . wc_format_datetime( $order->get_date_paid(), 'd/m/Y H:i' ) : 'Pago registrado por Graph Express' ); ?></small></span></div>
            <?php else : ?>
                <div class="ge-payment-options">
                    <article class="ge-payment-option is-transfer">
                        <div class="ge-payment-option-title"><b>TR</b><span><strong>Transferencia bancaria</strong><small>Sin recargo · acreditación sujeta a verificación</small></span></div>
                        <dl><div><dt>Titular</dt><dd><?php echo esc_html( $bank['holder'] ); ?></dd></div><div><dt>Banco</dt><dd><?php echo esc_html( $bank['bank'] ); ?></dd></div><div><dt>Cuenta</dt><dd><?php echo esc_html( $bank['account'] ); ?></dd></div><div><dt>CUIT</dt><dd><?php echo esc_html( $bank['tax_id'] ); ?></dd></div><div><dt>CBU</dt><dd><?php echo esc_html( $bank['cbu'] ); ?></dd></div><div><dt>Alias</dt><dd><?php echo esc_html( $bank['alias'] ); ?></dd></div></dl>
                        <div class="ge-payment-amount"><span>Total a transferir</span><strong><?php echo wp_kses_post( wc_price( $base_total, array( 'currency' => $order->get_currency() ) ) ); ?></strong></div>
                        <?php if ( 'receipt_uploaded' === $payment_state ) : ?><div class="ge-payment-pending">Comprobante cargado · pendiente de verificación</div><?php endif; ?>
                        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="ge_portal_upload_receipt"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_portal_upload_receipt_' . $order->get_id() ); ?>
                            <label>Adjuntar comprobante<input type="file" name="ge_payment_receipt" accept=".pdf,.jpg,.jpeg,.png" required></label>
                            <button class="ge-button ge-button-secondary ge-button-block" type="submit">Cargar comprobante</button>
                        </form>
                    </article>
                    <article class="ge-payment-option is-mp">
                        <div class="ge-payment-option-title"><b>MP</b><span><strong>Mercado Pago</strong><small>Checkout protegido · recargo del <?php echo esc_html( self::MP_SURCHARGE ); ?>%</small></span></div>
                        <div class="ge-payment-breakdown"><p><span>Pedido</span><strong><?php echo wp_kses_post( wc_price( $base_total, array( 'currency' => $order->get_currency() ) ) ); ?></strong></p><p><span>Recargo Mercado Pago (<?php echo esc_html( self::MP_SURCHARGE ); ?>%)</span><strong><?php echo wp_kses_post( wc_price( $mp_surcharge, array( 'currency' => $order->get_currency() ) ) ); ?></strong></p></div>
                        <div class="ge-payment-amount"><span>Total por Mercado Pago</span><strong><?php echo wp_kses_post( wc_price( $mp_total, array( 'currency' => $order->get_currency() ) ) ); ?></strong></div>
                        <?php if ( $mp_ready ) : ?><a class="ge-button ge-button-primary ge-button-block" href="<?php echo esc_url( self::mercadopago_payment_url( $order ) ); ?>">Pagar con Mercado Pago</a><?php else : ?><button class="ge-button ge-button-primary ge-button-block" type="button" disabled>Mercado Pago pendiente de configurar</button><small class="ge-payment-help">La transferencia ya está disponible. El pago online se habilitará cuando se conecten las credenciales propias de Graph Express.</small><?php endif; ?>
                    </article>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function render_staff_order_payment( $order ) {
        if ( ! $order instanceof WC_Order || ! class_exists( 'GE_WTP_Staff_Portal' ) || ! GE_WTP_Staff_Portal::can_access() ) { return; }
        $state = sanitize_key( (string) $order->get_meta( '_ge_payment_state' ) );
        $paid = $order->is_paid() || 'paid' === $state;
        $labels = array( 'receipt_uploaded' => 'Comprobante recibido · pendiente de verificación', 'paid' => 'Pago confirmado' );
        $documents = class_exists( 'GE_WTP_Documents' ) ? GE_WTP_Documents::get_documents( $order->get_id() ) : array();
        $receipts = array_filter( $documents, function( $document ) { return 'comprobante' === ( $document['category'] ?? '' ); } );
        $status = isset( $_GET['payment_admin'] ) ? sanitize_key( wp_unslash( $_GET['payment_admin'] ) ) : '';
        ?>
        <section class="ge-admin-panel ge-staff-payment">
            <div class="ge-admin-panel-head"><div><span>Cobranza</span><h2>Pago del pedido</h2></div><strong><?php echo esc_html( $paid ? 'Pagado' : ( $labels[ $state ] ?? 'Pendiente' ) ); ?></strong></div>
            <?php if ( 'confirmed' === $status ) : ?><div class="ge-order-notice">Transferencia confirmada y cliente notificado.</div><?php elseif ( 'instructions-sent' === $status ) : ?><div class="ge-order-notice">Instrucciones de pago enviadas al cliente.</div><?php elseif ( 'failed' === $status ) : ?><div class="ge-order-notice is-error">No se pudo completar la acción. Revisá el correo y volvé a intentar.</div><?php endif; ?>
            <div class="ge-admin-meta"><div><small>Total base</small><strong><?php echo wp_kses_post( wc_price( self::base_total( $order ), array( 'currency' => $order->get_currency() ) ) ); ?></strong></div><div><small>Medio</small><strong><?php echo esc_html( $order->get_payment_method_title() ?: 'Sin definir' ); ?></strong></div><div><small>Comprobantes</small><strong><?php echo esc_html( count( $receipts ) ); ?></strong></div></div>
            <div class="ge-staff-payment-actions">
                <?php if ( ! $paid && is_email( $order->get_billing_email() ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_staff_send_payment_instructions"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_staff_send_payment_instructions_' . $order->get_id() ); ?><button class="ge-staff-button" type="submit">Enviar instrucciones de pago</button></form><?php endif; ?>
                <?php if ( ! $paid && 'receipt_uploaded' === $state ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_staff_confirm_transfer"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_staff_confirm_transfer_' . $order->get_id() ); ?><button class="ge-staff-button" type="submit">Confirmar transferencia y avisar</button></form><?php endif; ?>
            </div>
        </section>
        <?php
    }

    public static function allow_portal_order_payment( $statuses, $order ) {
        if ( ! $order instanceof WC_Order || ( 'yes' !== $order->get_meta( '_ge_manual_order' ) && 'yes' !== $order->get_meta( '_ge_markcom_order' ) ) ) { return $statuses; }
        if ( 'paid' === $order->get_meta( '_ge_payment_state' ) || $order->is_paid() ) { return $statuses; }
        if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'completed', 'ge-entregado', 'ge-cobrado' ), true ) ) { return $statuses; }
        $statuses[] = $order->get_status();
        return array_values( array_unique( $statuses ) );
    }

    public static function limit_order_pay_to_mercadopago( $gateways ) {
        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) || 'mercadopago' !== sanitize_key( wp_unslash( $_GET['ge_payment'] ?? '' ) ) ) { return $gateways; }
        foreach ( $gateways as $gateway_id => $gateway ) {
            if ( 0 !== strpos( (string) $gateway_id, 'woo-mercado-pago-' ) ) { unset( $gateways[ $gateway_id ] ); }
        }
        return $gateways;
    }

    public static function apply_mercadopago_surcharge( $order ) {
        $gateway_id = sanitize_key( wp_unslash( $_POST['payment_method'] ?? '' ) );
        if ( ! $order instanceof WC_Order || 'mercadopago' !== sanitize_key( wp_unslash( $_GET['ge_payment'] ?? '' ) ) || 0 !== strpos( $gateway_id, 'woo-mercado-pago-' ) ) { return; }
        self::remove_mercadopago_surcharge( $order );
        $base_total = (float) $order->get_total();
        $surcharge = round( $base_total * self::MP_SURCHARGE / 100, wc_get_price_decimals() );
        if ( $surcharge <= 0 ) { return; }
        $fee = new WC_Order_Item_Fee();
        $fee->set_name( 'Recargo Mercado Pago (' . self::MP_SURCHARGE . '%)' );
        $fee->set_amount( $surcharge );
        $fee->set_total( $surcharge );
        $fee->add_meta_data( self::MP_FEE_META, 'yes', true );
        $order->add_item( $fee );
        $order->update_meta_data( '_ge_payment_base_total', $base_total );
        $order->update_meta_data( '_ge_payment_mp_surcharge', $surcharge );
        $order->update_meta_data( '_ge_payment_requested_at', current_time( 'mysql' ) );
        $order->calculate_totals( false );
        $order->save();
    }

    public static function preserve_production_status_after_payment( $status, $order_id, $order ) {
        if ( $order instanceof WC_Order && 0 === strpos( $order->get_status(), 'ge-' ) ) { return $order->get_status(); }
        return $status;
    }

    public static function record_completed_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return; }
        $order->update_meta_data( '_ge_payment_state', 'paid' );
        $order->update_meta_data( '_ge_payment_confirmed_at', current_time( 'mysql' ) );
        $order->update_meta_data( '_ge_payment_confirmed_total', $order->get_total() );
        $order->add_order_note( 'Pago online acreditado y vinculado automáticamente al pedido.' );
        $order->save();
    }

    public static function handle_receipt_upload() {
        if ( ! is_user_logged_in() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order_id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_portal_upload_receipt_' . $order_id );
        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order || ! GE_WTP_Documents::can_access_order( $order ) ) { wp_die( 'Acceso denegado.', 403 ); }
        self::remove_mercadopago_surcharge( $order );
        $saved = GE_WTP_Documents::handle_uploaded_files( $order_id, 'ge_payment_receipt', 'comprobante' );
        if ( is_wp_error( $saved ) || ! $saved ) { self::payment_redirect( $order, 'receipt-error' ); }
        $order->set_payment_method( 'bacs' );
        $order->set_payment_method_title( 'Transferencia bancaria' );
        $order->update_meta_data( '_ge_payment_state', 'receipt_uploaded' );
        $order->update_meta_data( '_ge_payment_receipt_uploaded_at', current_time( 'mysql' ) );
        $order->add_order_note( 'El cliente cargó un comprobante de transferencia. Pendiente de verificación.' );
        $order->save();
        self::payment_redirect( $order, 'receipt-uploaded' );
    }

    public static function handle_staff_confirm_transfer() {
        self::require_staff();
        $order_id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_staff_confirm_transfer_' . $order_id );
        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order ) { wp_die( 'Pedido inválido.', 404 ); }
        self::remove_mercadopago_surcharge( $order );
        $order->set_payment_method( 'bacs' );
        $order->set_payment_method_title( 'Transferencia bancaria' );
        $order->set_date_paid( current_time( 'timestamp', true ) );
        $order->update_meta_data( '_ge_payment_state', 'paid' );
        $order->update_meta_data( '_ge_payment_confirmed_at', current_time( 'mysql' ) );
        $order->update_meta_data( '_ge_payment_confirmed_total', $order->get_total() );
        $order->add_order_note( 'Transferencia verificada por Gestión.' );
        $order->save();
        $sent = self::send_payment_confirmed_email( $order );
        self::staff_redirect( $order, $sent ? 'confirmed' : 'failed' );
    }

    public static function handle_staff_send_payment_instructions() {
        self::require_staff();
        $order_id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_staff_send_payment_instructions_' . $order_id );
        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order || ! is_email( $order->get_billing_email() ) ) { wp_die( 'Pedido inválido.', 404 ); }
        $bank = self::bank_details();
        $portal_url = class_exists( 'GE_WTP_Portal' ) ? GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) ) : home_url( '/' );
        $base = self::base_total( $order );
        $mp_total = $base + round( $base * self::MP_SURCHARGE / 100, wc_get_price_decimals() );
        $body = '<p>Hola ' . esc_html( $order->get_billing_first_name() ?: '¿cómo estás?' ) . ',</p><p>Tu pedido ya tiene disponibles las opciones de pago.</p><p><strong>Transferencia bancaria:</strong> ' . wp_kses_post( wc_price( $base, array( 'currency' => $order->get_currency() ) ) ) . '<br>CBU: ' . esc_html( $bank['cbu'] ) . '<br>Alias: ' . esc_html( $bank['alias'] ) . '</p><p><strong>Mercado Pago:</strong> ' . wp_kses_post( wc_price( $mp_total, array( 'currency' => $order->get_currency() ) ) ) . ' (incluye recargo del ' . self::MP_SURCHARGE . '%).</p><p style="margin:24px 0"><a href="' . esc_url( $portal_url ) . '" style="display:inline-block;padding:13px 18px;border-radius:9px;background:#6d45ef;color:#fff;text-decoration:none;font-weight:700">Ver pedido y pagar</a></p><p>Si transferís, cargá el comprobante dentro del mismo pedido.</p>';
        $sent = GE_WTP_Notifications::send( $order->get_billing_email(), 'Opciones de pago · ' . GE_WTP_Manual_Orders::reference( $order ), $body, 'payment_instructions', $order->get_id() );
        self::staff_redirect( $order, $sent ? 'instructions-sent' : 'failed' );
    }

    private static function mercadopago_payment_url( $order ) {
        return add_query_arg( 'ge_payment', 'mercadopago', $order->get_checkout_payment_url() );
    }

    private static function mercadopago_ready() {
        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) { return false; }
        foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
            if ( 0 === strpos( (string) $gateway_id, 'woo-mercado-pago-' ) && 'yes' === $gateway->get_option( 'enabled', 'no' ) ) { return true; }
        }
        return false;
    }

    private static function base_total( $order ) {
        $stored = (float) $order->get_meta( '_ge_payment_base_total' );
        if ( $stored > 0 && self::has_mercadopago_surcharge( $order ) ) { return $stored; }
        $total = (float) $order->get_total();
        foreach ( $order->get_items( 'fee' ) as $fee ) {
            if ( 'yes' === $fee->get_meta( self::MP_FEE_META ) ) { $total -= (float) $fee->get_total(); }
        }
        return max( 0, $total );
    }

    private static function has_mercadopago_surcharge( $order ) {
        foreach ( $order->get_items( 'fee' ) as $fee ) { if ( 'yes' === $fee->get_meta( self::MP_FEE_META ) ) { return true; } }
        return false;
    }

    private static function remove_mercadopago_surcharge( $order ) {
        $removed = false;
        foreach ( $order->get_items( 'fee' ) as $item_id => $fee ) {
            if ( 'yes' === $fee->get_meta( self::MP_FEE_META ) ) { $order->remove_item( $item_id ); $removed = true; }
        }
        if ( $removed ) { $order->calculate_totals( false ); $order->save(); }
    }

    private static function bank_details() {
        return apply_filters( 'ge_wtp_bank_details', array( 'holder' => 'Leonardo Javier Ayala', 'bank' => 'Banco Ciudad', 'tax_id' => '23-33692452-9', 'account' => '$000000050200471077', 'cbu' => '0290005610000004710775', 'alias' => 'LJVRYL' ) );
    }

    private static function payment_redirect( $order, $notice ) {
        $url = class_exists( 'GE_WTP_Portal' ) ? GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id(), 'ge_payment_notice' => sanitize_key( $notice ) ) ) : home_url( '/' );
        wp_safe_redirect( $url );
        exit;
    }

    private static function send_payment_confirmed_email( $order ) {
        if ( ! is_email( $order->get_billing_email() ) ) { return false; }
        $portal_url = class_exists( 'GE_WTP_Portal' ) ? GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) ) : home_url( '/' );
        $body = '<p>Hola ' . esc_html( $order->get_billing_first_name() ?: '¿cómo estás?' ) . ',</p><p>Confirmamos la acreditación del pago correspondiente al pedido <strong>' . esc_html( GE_WTP_Manual_Orders::reference( $order ) ) . '</strong>.</p><p><strong>Total acreditado:</strong> ' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</p><p><a href="' . esc_url( $portal_url ) . '">Consultar el pedido</a></p>';
        return GE_WTP_Notifications::send( $order->get_billing_email(), 'Pago confirmado · ' . GE_WTP_Manual_Orders::reference( $order ), $body, 'payment_confirmed', $order->get_id() );
    }

    private static function require_staff() {
        if ( ! class_exists( 'GE_WTP_Staff_Portal' ) || ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
    }

    private static function staff_redirect( $order, $status ) {
        $url = GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id(), 'payment_admin' => sanitize_key( $status ) ) );
        wp_safe_redirect( $url );
        exit;
    }
}
