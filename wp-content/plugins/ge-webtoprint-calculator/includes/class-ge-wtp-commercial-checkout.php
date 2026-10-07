<?php

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-ge-wtp-billing-issuers.php';

/** Financial payment orders for accepted commercial quote snapshots. */
final class GE_WTP_Commercial_Checkout {
    const PAYMENT_META = '_ge_commercial_payment_order';
    const QUOTE_META = '_ge_commercial_quote_id';
    const KIND_META = '_ge_commercial_payment_kind';
    const AMOUNT_META = '_ge_commercial_intended_cents';
    const METHOD_META = '_ge_commercial_method';

    public static function init() {
        add_action( 'admin_post_ge_commercial_start_payment', array( __CLASS__, 'handle_start_payment' ) );
        add_action( 'admin_post_ge_commercial_start_balance', array( __CLASS__, 'handle_start_balance' ) );
        add_action( 'admin_post_ge_commercial_upload_receipt', array( __CLASS__, 'handle_receipt' ) );
        add_action( 'admin_post_ge_commercial_confirm_payment', array( __CLASS__, 'handle_staff_confirm' ) );
        add_action( 'admin_post_ge_commercial_confirm_cash_balance', array( __CLASS__, 'handle_cash_balance' ) );
        add_action( 'admin_post_ge_commercial_cancel_balance_attempt', array( __CLASS__, 'handle_cancel_balance_attempt' ) );
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'reconcile_payment' ), 30 );
        add_action( 'woocommerce_thankyou', array( __CLASS__, 'render_receipt_thankyou' ), 15 );
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'limit_gateways' ), 95 );
    }

    public static function enabled() { return 'yes' === get_option( 'ge_commercial_checkout_enabled', 'no' ); }

    /** Read-only service for staff and future structured Graph actions. */
    public static function payment_status( $quote_id, $actor_id ) {
        $quote = GE_WTP_Commercial_Quotes::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        $order = $quote['converted_order_id'] ? wc_get_order( $quote['converted_order_id'] ) : false;
        $attempt_id = absint( get_post_meta( $quote_id, '_ge_commercial_initial_payment_order', true ) );
        $attempt = $attempt_id ? wc_get_order( $attempt_id ) : false;
        $final_total = (int) get_post_meta( $quote_id, '_ge_commercial_final_total_cents', true );
        return array(
            'quote_id' => $quote['id'],
            'quote_version' => $quote['version'],
            'quote_status' => $quote['status'],
            'order_id' => $order ? $order->get_id() : null,
            'final_total_cents' => $order ? (int) $order->get_meta( '_ge_final_total_cents', true ) : ( $final_total ?: (int) ( $quote['snapshot']['total_cents'] ?? 0 ) ),
            'amount_paid_cents' => $order ? (int) $order->get_meta( '_ge_amount_paid_cents', true ) : 0,
            'amount_due_cents' => $order ? (int) $order->get_meta( '_ge_amount_due_cents', true ) : null,
            'payment_status' => $order ? (string) $order->get_meta( '_ge_payment_state', true ) : ( $attempt && in_array( $attempt->get_status(), array( 'failed', 'cancelled' ), true ) ? 'failed' : 'pending' ),
            'receipt_file_ids' => array_values( array_filter( array_column( GE_WTP_Commercial_Quote_Files::all( $quote['id'], 'comprobante' ), 'id' ) ) ),
        );
    }

    public static function render_quote_checkout( $quote ) {
        if ( ! is_array( $quote ) || ! in_array( $quote['status'], array( 'sent', 'viewed', 'accepted', 'converted' ), true ) ) { return; }
        $snapshot = $quote['snapshot'];
        if ( GE_WTP_Quote_Selection::has_choices( $snapshot ) && ( empty( $snapshot['customer_selection'] ) || ! in_array( $quote['status'], array( 'accepted', 'converted' ), true ) ) ) { return; }
        if ( empty( $snapshot['total_cents'] ) || 'pending' === ( $snapshot['fiscal_status'] ?? '' ) ) { return; }
        if ( 'converted' === $quote['status'] ) {
            $order = $quote['converted_order_id'] ? wc_get_order( $quote['converted_order_id'] ) : false;
            if ( $order && (int) $order->get_meta( '_ge_amount_paid_cents', true ) > 0 ) { self::render_order_balance( $order ); return; }
        }
        echo '<section class="ge-panel" id="ge-quote-payment-' . esc_attr( $quote['id'] ) . '"><span class="ge-eyebrow">Pago del presupuesto</span><h3>Revisá cómo querés pagar</h3><p>Podés iniciar el pago independientemente de la aceptación comercial. El contenido y los precios corresponden a la versión vigente.</p>';
        if ( GE_WTP_Portal::is_staff_preview() ) { echo '<p>Vista previa: el cliente verá aquí sus opciones de pago.</p></section>'; return; }
        if ( 'converted' !== $quote['status'] && ! empty( $snapshot['valid_until'] ) && $snapshot['valid_until'] < wp_date( 'Y-m-d' ) ) { echo '<p>La validez del presupuesto terminó. Pedí una versión actualizada para pagar.</p></section>'; return; }
        if ( ! self::enabled() ) { echo '<p>Para coordinar el pago, contactá a Graph Express.</p></section>'; return; }
        $active = absint( get_post_meta( $quote['id'], '_ge_commercial_initial_payment_order', true ) );
        $payment_order = $active ? wc_get_order( $active ) : false;
        if ( $payment_order ) {
            echo '<p>Ya hay un cobro iniciado por ' . esc_html( $payment_order->get_formatted_order_total() ) . '.</p><a class="ge-button ge-button-primary" href="' . esc_url( $payment_order->get_checkout_payment_url() ) . '">Continuar al pago</a>';
            self::render_receipt_form( $payment_order );
            echo '</section>';
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_start_payment"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '">';
        wp_nonce_field( 'ge_commercial_start_payment_' . $quote['id'] );
        $deposit_enabled = ! array_key_exists( 'deposit_enabled', $snapshot ) || $snapshot['deposit_enabled'];
        echo '<fieldset><legend>Importe</legend><label><input type="radio" name="payment_kind" value="full" checked> Pagar total</label>';
        if ( $deposit_enabled ) { echo ' <label><input type="radio" name="payment_kind" value="deposit"> Pagar ' . esc_html( $snapshot['deposit_percent'] ) . '% de seña</label>'; }
        echo '</fieldset>';
        echo '<fieldset><legend>Medio</legend><label><input type="radio" name="payment_method" value="bacs" checked> Transferencia bancaria</label> <label><input type="radio" name="payment_method" value="mercadopago"> Mercado Pago</label></fieldset>';
        $base = (int) $snapshot['total_cents'];
        foreach ( array( 'bacs' => 'Transferencia', 'mercadopago' => 'Mercado Pago' ) as $method => $label ) {
            $final = $base + ( 'converted' === $quote['status'] ? 0 : self::adjustment_cents( $base, $method ) );
            if ( ! $deposit_enabled ) { echo '<p><strong>' . esc_html( $label ) . ':</strong> total ' . esc_html( GE_WTP_Quote_Balance::decimal( $final ) ) . ' ARS.</p>'; continue; }
            $deposit = GE_WTP_Quote_Balance::deposit( $final, (int) $snapshot['deposit_percent'] * 100 );
            echo '<p><strong>' . esc_html( $label ) . ':</strong> total ' . esc_html( GE_WTP_Quote_Balance::decimal( $final ) ) . ' ARS · seña ' . esc_html( GE_WTP_Quote_Balance::decimal( $deposit['deposit_cents'] ) ) . ' ARS · saldo ' . esc_html( GE_WTP_Quote_Balance::decimal( $deposit['remaining_cents'] ) ) . ' ARS.</p>';
        }
        echo '<p>El saldo se solicita cuando el trabajo está listo para entregar; podés abonarlo al recibirlo.</p><button class="ge-button ge-button-primary" type="submit">Continuar al pago</button></form></section>';
    }

    public static function render_order_balance( $order ) {
        if ( ! $order instanceof WC_Order || ! $order->get_meta( self::QUOTE_META, true ) ) { return; }
        $paid = (int) $order->get_meta( '_ge_amount_paid_cents', true );
        $due = (int) $order->get_meta( '_ge_amount_due_cents', true );
        echo '<section class="ge-panel" id="ge-quote-payment-' . esc_attr( $order->get_meta( self::QUOTE_META, true ) ) . '"><span class="ge-eyebrow">Pago del pedido</span><h3>' . esc_html( $due > 0 ? 'Seña recibida' : 'Pago completo' ) . '</h3><p>Total: ' . esc_html( GE_WTP_Quote_Balance::decimal( (int) $order->get_meta( '_ge_final_total_cents', true ) ) ) . ' ARS · Abonado: ' . esc_html( GE_WTP_Quote_Balance::decimal( $paid ) ) . ' ARS · Saldo: <strong>' . esc_html( GE_WTP_Quote_Balance::decimal( $due ) ) . ' ARS</strong></p>';
        if ( $due > 0 && 'ready' === $order->get_meta( '_ge_production_status', true ) && self::enabled() && GE_WTP_Documents::can_access_order( $order ) && ! GE_WTP_Portal::is_staff_preview() ) {
            $balance_id = absint( $order->get_meta( '_ge_commercial_balance_payment_order', true ) );
            $balance_order = $balance_id ? wc_get_order( $balance_id ) : false;
            if ( $balance_order ) { echo '<a class="ge-button ge-button-primary" href="' . esc_url( $balance_order->get_checkout_payment_url() ) . '">Pagar saldo</a>'; self::render_receipt_form( $balance_order ); }
            else {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_start_balance"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
                wp_nonce_field( 'ge_commercial_start_balance_' . $order->get_id() );
                echo '<button class="ge-button ge-button-primary" type="submit">Pagar saldo</button></form>';
            }
        } elseif ( $due > 0 ) { echo '<p>El saldo se solicita cuando el trabajo esté listo para entregar. Podés coordinar el pago al recibirlo.</p>'; }
        echo '</section>';
    }

    private static function render_receipt_form( $payment ) {
        if ( 'bacs' !== $payment->get_meta( self::METHOD_META, true ) || $payment->is_paid() ) { return; }
        echo '<p>Si pagaste por transferencia, podés subir el comprobante ahora o volver a esta sección más tarde. No es obligatorio para continuar; Graph Express verificará la acreditación bancaria antes de registrar el pago.</p><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_upload_receipt"><input type="hidden" name="payment_order_id" value="' . esc_attr( $payment->get_id() ) . '">';
        wp_nonce_field( 'ge_commercial_upload_receipt_' . $payment->get_id() );
        echo '<label>Comprobante<input type="file" name="ge_payment_receipt" accept=".pdf,.jpg,.jpeg,.png" required></label><button class="ge-button ge-button-secondary" type="submit">Enviar comprobante</button></form>';
    }

    public static function render_receipt_thankyou( $order_id ) {
        $payment = wc_get_order( absint( $order_id ) );
        if ( ! $payment || 'yes' !== $payment->get_meta( self::PAYMENT_META, true ) || 'bacs' !== $payment->get_meta( self::METHOD_META, true ) || $payment->is_paid() || ! is_user_logged_in() || (int) $payment->get_customer_id() !== get_current_user_id() ) { return; }
        echo '<section class="ge-panel"><h3>Comprobante de transferencia</h3>';
        self::render_receipt_form( $payment );
        echo '<p><a href="' . esc_url( GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => absint( $payment->get_meta( self::QUOTE_META, true ) ) ) ) ) . '">Volver a mi presupuesto</a></p></section>';
    }

    public static function handle_receipt() {
        if ( ! is_user_logged_in() || GE_WTP_Portal::is_staff_preview() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $id = absint( $_POST['payment_order_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_upload_receipt_' . $id );
        $payment = wc_get_order( $id );
        if ( ! $payment || 'yes' !== $payment->get_meta( self::PAYMENT_META, true ) || 'bacs' !== $payment->get_meta( self::METHOD_META, true ) || (int) $payment->get_customer_id() !== get_current_user_id() || $payment->is_paid() ) { wp_die( 'Cobro inválido.', '', array( 'response' => 403 ) ); }
        $result = GE_WTP_Documents::handle_uploaded_files( $id, 'ge_payment_receipt', 'comprobante' );
        if ( is_wp_error( $result ) || ! $result ) { wp_die( 'No se pudo guardar el comprobante.', '', array( 'response' => 422 ) ); }
        $quote_id = absint( $payment->get_meta( self::QUOTE_META, true ) );
        $receipts = GE_WTP_Commercial_Quote_Files::all( $quote_id, 'comprobante' );
        $receipts = array_merge( $receipts, $result );
        update_post_meta( $quote_id, GE_WTP_Commercial_Quote_Files::RECEIPT_META, $receipts );
        $payment->update_meta_data( '_ge_commercial_receipt_state', 'uploaded' );
        $payment->add_order_note( 'Comprobante enviado por el cliente; transferencia pendiente de verificación manual.' );
        $payment->save();
        GE_WTP_Commercial_Quotes::record_event( $quote_id, 'receipt_uploaded', get_current_user_id(), array( 'payment_order_id' => $id ) );
        wp_safe_redirect( GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => absint( $payment->get_meta( self::QUOTE_META, true ) ) ) ) ); exit;
    }

    public static function render_staff_payment( $quote ) {
        $ids = array( absint( get_post_meta( $quote['id'], '_ge_commercial_initial_payment_order', true ) ) );
        if ( $quote['converted_order_id'] ) { $main = wc_get_order( $quote['converted_order_id'] ); if ( $main ) { $ids[] = absint( $main->get_meta( '_ge_commercial_balance_payment_order', true ) ); } }
        foreach ( array_filter( $ids ) as $id ) {
            $payment = wc_get_order( $id );
            if ( ! $payment ) { continue; }
            echo '<section class="ge-production-card"><h3>' . esc_html( ucfirst( $payment->get_meta( self::KIND_META, true ) ) ) . ' · ' . esc_html( $payment->get_formatted_order_total() ) . '</h3><p>Estado: ' . esc_html( $payment->get_status() ) . ' · Comprobante: ' . esc_html( $payment->get_meta( '_ge_commercial_receipt_state', true ) ?: 'sin cargar' ) . '</p>';
            foreach ( GE_WTP_Documents::get_documents( $id ) as $document ) {
                if ( 'comprobante' === ( $document['category'] ?? '' ) ) { echo '<p><a href="' . esc_url( GE_WTP_Documents::download_url( $id, $document['id'] ) ) . '">Ver comprobante</a></p>'; }
            }
            if ( 'bacs' === $payment->get_meta( self::METHOD_META, true ) && ! $payment->is_paid() ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_confirm_payment"><input type="hidden" name="payment_order_id" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( 'ge_commercial_confirm_payment_' . $id );
                echo '<label><input type="checkbox" name="bank_verified" value="yes" required> Verifiqué la acreditación bancaria del importe exacto</label><button class="ge-staff-button" type="submit">Confirmar pago</button></form>';
            }
            if ( 'balance' === $payment->get_meta( self::KIND_META, true ) && ! $payment->is_paid() && 'cancelled' !== $payment->get_status() ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_cancel_balance_attempt"><input type="hidden" name="payment_order_id" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( 'ge_commercial_cancel_balance_attempt_' . $id );
                echo '<button class="ge-staff-button" type="submit">Cancelar intento de saldo para cobrar al entregar</button></form>';
            }
            echo '</section>';
        }
        if ( $quote['converted_order_id'] ) {
            $main = wc_get_order( $quote['converted_order_id'] );
            if ( $main && 'ready' === $main->get_meta( '_ge_production_status', true ) && (int) $main->get_meta( '_ge_amount_due_cents', true ) > 0 ) {
                if ( $main->get_meta( '_ge_commercial_balance_payment_order', true ) ) { echo '<p>Hay un cobro de saldo en curso. Verificá o cancelá ese intento antes de registrar efectivo.</p>'; return; }
                echo '<section class="ge-production-card"><h3>Cobro al entregar</h3><p>Saldo pendiente: ' . esc_html( GE_WTP_Quote_Balance::decimal( (int) $main->get_meta( '_ge_amount_due_cents', true ) ) ) . ' ARS.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_confirm_cash_balance"><input type="hidden" name="order_id" value="' . esc_attr( $main->get_id() ) . '">';
                wp_nonce_field( 'ge_commercial_confirm_cash_balance_' . $main->get_id() );
                echo '<label><input type="checkbox" name="cash_received" value="yes" required> Recibí el saldo exacto al entregar</label><button class="ge-staff-button" type="submit">Registrar saldo en efectivo</button></form></section>';
            }
        }
    }

    public static function handle_cash_balance() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_confirm_cash_balance_' . $id );
        if ( 'yes' !== sanitize_key( wp_unslash( $_POST['cash_received'] ?? '' ) ) ) { wp_die( 'Confirmá la recepción del efectivo.', '', array( 'response' => 422 ) ); }
        $main = wc_get_order( $id );
        $quote_id = $main ? absint( $main->get_meta( self::QUOTE_META, true ) ) : 0;
        if ( ! $quote_id ) { wp_die( 'Pedido inválido.', '', array( 'response' => 409 ) ); }
        $lock = 'ge_commercial_reconcile_' . $quote_id;
        if ( ! self::acquire_lock( $lock ) ) { wp_die( 'El cobro se está procesando.', '', array( 'response' => 409 ) ); }
        $failure = '';
        try {
            $order = wc_get_order( $id );
            if ( ! $order || ! $order->get_meta( self::QUOTE_META, true ) || 'ready' !== $order->get_meta( '_ge_production_status', true ) ) { throw new DomainException( 'Pedido inválido.' ); }
            if ( $order->get_meta( '_ge_commercial_balance_payment_order', true ) ) { throw new DomainException( 'Hay un cobro de saldo en curso; concilialo antes de registrar efectivo.' ); }
            $due = (int) $order->get_meta( '_ge_amount_due_cents', true );
            if ( $due <= 0 ) { throw new DomainException( 'El saldo ya está abonado.' ); }
            $attempts = $order->get_meta( '_ge_commercial_credited_attempts', true );
            $attempts = is_array( $attempts ) ? $attempts : array();
            $attempts[] = array( 'key' => 'cash:order:' . $id, 'amount_cents' => $due );
            $state = GE_WTP_Quote_Balance::reconcile( (int) $order->get_meta( '_ge_final_total_cents', true ), $attempts );
            $order->update_meta_data( '_ge_commercial_credited_attempts', $attempts );
            $order->update_meta_data( '_ge_amount_paid_cents', $state['amount_paid_cents'] );
            $order->update_meta_data( '_ge_amount_due_cents', $state['amount_due_cents'] );
            $order->update_meta_data( '_ge_payment_state', $state['payment_status'] );
            if ( 0 === $state['amount_due_cents'] ) { $order->set_date_paid( current_time( 'timestamp', true ) ); }
            $order->add_order_note( 'Saldo en efectivo recibido al entregar por el usuario interno #' . get_current_user_id() . ': ' . GE_WTP_Quote_Balance::decimal( $due ) . ' ARS.' );
            $order->save();
        } catch ( DomainException $error ) { $failure = $error->getMessage();
        } finally { self::release_lock( $lock ); }
        if ( $failure ) { wp_die( esc_html( $failure ), '', array( 'response' => 409 ) ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote_id ) ) ); exit;
    }

    public static function handle_cancel_balance_attempt() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $id = absint( $_POST['payment_order_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_cancel_balance_attempt_' . $id );
        $payment = wc_get_order( $id );
        $main_id = $payment ? absint( $payment->get_meta( '_ge_commercial_operational_order_id', true ) ) : 0;
        $main = $main_id ? wc_get_order( $main_id ) : false;
        if ( ! $payment || ! $main || 'balance' !== $payment->get_meta( self::KIND_META, true ) || 'yes' !== $payment->get_meta( self::PAYMENT_META, true ) || $payment->is_paid() || $id !== absint( $main->get_meta( '_ge_commercial_balance_payment_order', true ) ) ) { wp_die( 'Intento inválido.', '', array( 'response' => 409 ) ); }
        $lock = 'ge_commercial_reconcile_' . absint( $main->get_meta( self::QUOTE_META, true ) );
        if ( ! self::acquire_lock( $lock ) ) { wp_die( 'El cobro se está procesando.', '', array( 'response' => 409 ) ); }
        $failure = '';
        try {
            $payment = wc_get_order( $id );
            $main = wc_get_order( $main_id );
            if ( ! $payment || ! $main || $payment->is_paid() || $id !== absint( $main->get_meta( '_ge_commercial_balance_payment_order', true ) ) ) { throw new DomainException( 'El intento ya cambió.' ); }
            $payment->update_status( 'cancelled', 'Intento de saldo cancelado por el usuario interno #' . get_current_user_id() . ' para coordinar otro cobro.' );
            $main->delete_meta_data( '_ge_commercial_balance_payment_order' );
            $main->add_order_note( 'Intento de cobro de saldo #' . $id . ' cancelado por el usuario interno #' . get_current_user_id() . '.' );
            $main->save();
        } catch ( DomainException $error ) { $failure = $error->getMessage();
        } finally { self::release_lock( $lock ); }
        if ( $failure ) { wp_die( esc_html( $failure ), '', array( 'response' => 409 ) ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => absint( $main->get_meta( self::QUOTE_META, true ) ) ) ) ); exit;
    }

    public static function handle_staff_confirm() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $id = absint( $_POST['payment_order_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_confirm_payment_' . $id );
        if ( 'yes' !== sanitize_key( wp_unslash( $_POST['bank_verified'] ?? '' ) ) ) { wp_die( 'Confirmá la acreditación bancaria.', '', array( 'response' => 422 ) ); }
        $payment = wc_get_order( $id );
        if ( ! $payment || 'yes' !== $payment->get_meta( self::PAYMENT_META, true ) || 'bacs' !== $payment->get_meta( self::METHOD_META, true ) || $payment->is_paid() ) { wp_die( 'Cobro inválido.', '', array( 'response' => 409 ) ); }
        $payment->add_order_note( 'Transferencia bancaria verificada por el usuario interno #' . get_current_user_id() . '.' );
        $payment->payment_complete();
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => absint( $payment->get_meta( self::QUOTE_META, true ) ) ) ) ); exit;
    }

    public static function handle_start_payment() {
        if ( ! is_user_logged_in() || GE_WTP_Portal::is_staff_preview() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $quote_id = absint( $_POST['quote_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_start_payment_' . $quote_id );
        $kind = sanitize_key( wp_unslash( $_POST['payment_kind'] ?? '' ) );
        $method = sanitize_key( wp_unslash( $_POST['payment_method'] ?? '' ) );
        $order = self::start_initial( $quote_id, $kind, $method, get_current_user_id() );
        if ( is_wp_error( $order ) ) { wp_die( esc_html( $order->get_error_message() ), '', array( 'response' => 409 ) ); }
        wp_safe_redirect( $order->get_checkout_payment_url() ); exit;
    }

    public static function start_initial( $quote_id, $kind, $method, $actor_id ) {
        if ( ! self::enabled() ) { return new WP_Error( 'ge_quote_payment_disabled', 'El pago de presupuestos aún no está habilitado.' ); }
        if ( ! in_array( $kind, array( 'deposit', 'full' ), true ) || ! in_array( $method, array( 'bacs', 'mercadopago' ), true ) ) {
            return new WP_Error( 'ge_quote_payment_method', 'Elegí un importe y medio válidos.' );
        }
        $quote = GE_WTP_Commercial_Quotes::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( ! empty( $quote['snapshot']['draft_incomplete'] ) || ( 'deposit' === $kind && array_key_exists( 'deposit_enabled', $quote['snapshot'] ) && ! $quote['snapshot']['deposit_enabled'] ) ) { return new WP_Error( 'ge_quote_payment_state', 'Este presupuesto no admite ese pago.' ); }
        if ( (int) $actor_id !== $quote['customer_id'] || ! in_array( $quote['status'], array( 'sent', 'viewed', 'accepted', 'converted' ), true ) || empty( $quote['snapshot']['total_cents'] ) ) {
            return new WP_Error( 'ge_quote_payment_state', 'El presupuesto no está listo para pagar.' );
        }
        $snapshot = $quote['snapshot'];
        if ( 'converted' !== $quote['status'] && ! empty( $snapshot['valid_until'] ) && $snapshot['valid_until'] < wp_date( 'Y-m-d' ) ) {
            return new WP_Error( 'ge_quote_expired', 'El presupuesto venció.' );
        }
        if ( GE_WTP_Quote_Selection::has_choices( $quote['snapshot'] ) && empty( $quote['snapshot']['customer_selection'] ) ) { return new WP_Error( 'ge_quote_selection_required', 'Elegí y aceptá los ítems desde tu portal antes de pagar.' ); }
        $billing = GE_WTP_Commercial_Quotes::check_billing_snapshot( $quote );
        if ( is_wp_error( $billing ) ) { return $billing; }
        $lock = 'ge_commercial_start_' . $quote_id;
        if ( ! self::acquire_lock( $lock ) ) { return new WP_Error( 'ge_quote_busy', 'El pago se está preparando. Volvé a intentar.' ); }
        try {
            $existing_id = absint( get_post_meta( $quote_id, '_ge_commercial_initial_payment_order', true ) );
            if ( $existing_id ) {
                $existing = wc_get_order( $existing_id );
                if ( $existing ) { return $existing; }
            }
            $base = (int) $snapshot['total_cents'];
            $adjustment = 'converted' === $quote['status'] ? 0 : self::adjustment_cents( $base, $method );
            $final_total = $base + $adjustment;
            if ( $final_total <= 0 ) { return new WP_Error( 'ge_quote_total', 'Total de cobro inválido.' ); }
            $payable = 'full' === $kind ? $final_total : GE_WTP_Quote_Balance::deposit( $final_total, (int) $snapshot['deposit_percent'] * 100 )['deposit_cents'];
            $payment_order = self::create_payment_order( $quote, $kind, $method, $payable, $final_total );
            if ( is_wp_error( $payment_order ) ) { return $payment_order; }
            update_post_meta( $quote_id, '_ge_commercial_initial_payment_order', $payment_order->get_id() );
            GE_WTP_Commercial_Quote_Files::link_receipts_to_payment( $quote_id, $payment_order );
            update_post_meta( $quote_id, '_ge_commercial_final_total_cents', $final_total );
            update_post_meta( $quote_id, '_ge_commercial_adjustment_cents', $adjustment );
            update_post_meta( $quote_id, '_ge_commercial_method', $method );
            if ( 'converted' === $quote['status'] && $quote['converted_order_id'] ) {
                $operational = wc_get_order( $quote['converted_order_id'] );
                if ( $operational ) {
                    $operational->set_payment_method( 'bacs' === $method ? 'bacs' : 'ge_commercial_mercadopago' );
                    $operational->set_payment_method_title( 'bacs' === $method ? 'Transferencia bancaria' : 'Mercado Pago' );
                    $operational->save();
                }
            }
            GE_WTP_Commercial_Quotes::record_event( $quote_id, 'payment_started', $actor_id, array( 'payment_order_id' => $payment_order->get_id(), 'method' => $method, 'kind' => $kind ) );
            return $payment_order;
        } finally { self::release_lock( $lock ); }
    }

    public static function handle_start_balance() {
        if ( ! is_user_logged_in() || GE_WTP_Portal::is_staff_preview() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $order_id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_start_balance_' . $order_id );
        $order = self::start_balance( $order_id, get_current_user_id() );
        if ( is_wp_error( $order ) ) { wp_die( esc_html( $order->get_error_message() ), '', array( 'response' => 409 ) ); }
        wp_safe_redirect( $order->get_checkout_payment_url() ); exit;
    }

    public static function start_balance( $order_id, $actor_id ) {
        if ( ! self::enabled() ) { return new WP_Error( 'ge_quote_payment_disabled', 'El pago del saldo aún no está habilitado.' ); }
        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order || (int) $order->get_customer_id() !== (int) $actor_id || 'ready' !== $order->get_meta( '_ge_production_status', true ) ) {
            return new WP_Error( 'ge_quote_balance_state', 'El saldo estará disponible cuando el trabajo esté listo para entregar.' );
        }
        $quote_id = absint( $order->get_meta( self::QUOTE_META, true ) );
        $quote = $quote_id ? GE_WTP_Commercial_Quotes::get( $quote_id, $actor_id ) : null;
        if ( ! $quote || is_wp_error( $quote ) || $quote['converted_order_id'] !== $order->get_id() ) { return new WP_Error( 'ge_quote_balance', 'Pedido inválido.' ); }
        $billing = GE_WTP_Commercial_Quotes::check_billing_snapshot( $quote );
        if ( is_wp_error( $billing ) ) { return $billing; }
        $due = (int) $order->get_meta( '_ge_amount_due_cents', true );
        if ( $due <= 0 ) { return new WP_Error( 'ge_quote_balance_paid', 'El saldo ya está abonado.' ); }
        $lock = 'ge_commercial_balance_' . $order_id;
        if ( ! self::acquire_lock( $lock ) ) { return new WP_Error( 'ge_quote_busy', 'El saldo se está preparando.' ); }
        try {
            $existing_id = absint( $order->get_meta( '_ge_commercial_balance_payment_order', true ) );
            if ( $existing_id ) { $existing = wc_get_order( $existing_id ); if ( $existing ) { return $existing; } }
            $method = (string) get_post_meta( $quote_id, '_ge_commercial_method', true );
            $payment = self::create_payment_order( $quote, 'balance', $method, $due, (int) $order->get_meta( '_ge_final_total_cents', true ) );
            if ( is_wp_error( $payment ) ) { return $payment; }
            $order->update_meta_data( '_ge_commercial_balance_payment_order', $payment->get_id() );
            $order->save();
            return $payment;
        } finally { self::release_lock( $lock ); }
    }

    private static function create_payment_order( $quote, $kind, $method, $amount_cents, $final_total_cents ) {
        if ( $amount_cents <= 0 || $amount_cents > $final_total_cents ) { return new WP_Error( 'ge_quote_payment_amount', 'Importe de cobro inválido.' ); }
        $customer = get_userdata( $quote['customer_id'] );
        $order = wc_create_order( array( 'customer_id' => $quote['customer_id'] ) );
        if ( is_wp_error( $order ) ) { return $order; }
        $order->set_currency( 'ARS' );
        $order->set_created_via( 'ge_commercial_quote_payment' );
        GE_WTP_Billing_Issuers::inherit( $order, $quote['snapshot'] );
        $order->set_billing_email( $customer->user_email );
        $order->set_billing_first_name( $customer->first_name ?: $customer->display_name );
        $order->set_billing_last_name( $customer->last_name );
        $order->set_billing_phone( get_user_meta( $customer->ID, '_ge_whatsapp', true ) ?: get_user_meta( $customer->ID, 'billing_phone', true ) );
        $item = new WC_Order_Item_Product();
        $item->set_name( ( 'balance' === $kind ? 'Saldo' : ( 'deposit' === $kind ? 'Seña' : 'Pago total' ) ) . ' · ' . $quote['number'] );
        $item->set_quantity( 1 );
        $item->set_subtotal( GE_WTP_Quote_Balance::decimal( $amount_cents ) );
        $item->set_total( GE_WTP_Quote_Balance::decimal( $amount_cents ) );
        $order->add_item( $item );
        $order->update_meta_data( self::PAYMENT_META, 'yes' );
        $order->update_meta_data( self::QUOTE_META, $quote['id'] );
        $order->update_meta_data( '_ge_commercial_quote_version', $quote['version'] );
        $order->update_meta_data( self::KIND_META, $kind );
        $order->update_meta_data( self::METHOD_META, $method );
        $order->update_meta_data( self::AMOUNT_META, $amount_cents );
        if ( 'balance' === $kind ) { $order->update_meta_data( '_ge_commercial_operational_order_id', absint( $quote['converted_order_id'] ) ); }
        $order->update_meta_data( '_ge_commercial_final_total_cents', $final_total_cents );
        $order->calculate_totals( false );
        $order->save();
        return $order;
    }

    public static function limit_gateways( $gateways ) {
        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) { return $gateways; }
        $order_id = absint( get_query_var( 'order-pay' ) );
        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order || 'yes' !== $order->get_meta( self::PAYMENT_META, true ) ) { return $gateways; }
        $method = $order->get_meta( self::METHOD_META, true );
        foreach ( $gateways as $id => $gateway ) {
            $allowed = 'bacs' === $method ? 'bacs' === $id : ( 'mercadopago' === $method && 0 === strpos( (string) $id, 'woo-mercado-pago-' ) );
            if ( ! $allowed ) { unset( $gateways[ $id ] ); }
        }
        return $gateways;
    }

    public static function adjustment_cents( $base_cents, $method ) {
        if ( ! is_int( $base_cents ) || $base_cents <= 0 ) { throw new InvalidArgumentException( 'Total inválido.' ); }
        if ( 'bacs' === $method ) { return -intdiv( $base_cents * GE_WTP_Payments::BANK_DISCOUNT + 50, 100 ); }
        if ( 'mercadopago' === $method ) { return intdiv( $base_cents * GE_WTP_Payments::MP_SURCHARGE + 50, 100 ); }
        throw new InvalidArgumentException( 'Medio de pago inválido.' );
    }

    /** Called only for credited WooCommerce payment orders. */
    public static function reconcile_payment( $payment_order_id ) {
        $payment = wc_get_order( $payment_order_id );
        if ( ! $payment || 'yes' !== $payment->get_meta( self::PAYMENT_META, true ) ) { return; }
        $quote_id = absint( $payment->get_meta( self::QUOTE_META, true ) );
        $lock = 'ge_commercial_reconcile_' . $quote_id;
        if ( ! $quote_id || ! self::acquire_lock( $lock ) ) { return; }
        try {
            $intended = (int) $payment->get_meta( self::AMOUNT_META, true );
            $received = GE_WTP_Quote_Balance::cents( wc_format_decimal( $payment->get_total(), 2 ) );
            if ( $received !== $intended || ! $payment->is_paid() ) {
                $payment->add_order_note( 'El cobro no coincide con el importe esperado; requiere conciliación manual.' );
                return;
            }
            if ( 'yes' === $payment->get_meta( '_ge_commercial_reconciled', true ) ) { return; }
            $quote = GE_WTP_Commercial_Quotes::get( $quote_id );
            if ( is_wp_error( $quote ) || (int) $payment->get_meta( '_ge_commercial_quote_version', true ) !== $quote['version'] ) { return; }
            $order = $quote['converted_order_id'] ? wc_get_order( $quote['converted_order_id'] ) : false;
            if ( ! $order ) {
                $order = self::create_operational_order( $quote, $payment );
                if ( is_wp_error( $order ) ) { $payment->add_order_note( 'Pago acreditado; falta crear pedido operativo: ' . $order->get_error_message() ); return; }
                update_post_meta( $quote_id, GE_WTP_Commercial_Quotes::ORDER_META, $order->get_id() );
                update_post_meta( $quote_id, GE_WTP_Commercial_Quotes::STATUS_META, 'converted' );
                GE_WTP_Commercial_Quotes::record_event( $quote_id, 'converted', get_current_user_id(), array( 'order_id' => $order->get_id(), 'source' => 'payment' ) );
            }
            $credited = $order->get_meta( '_ge_commercial_credited_attempts', true );
            $credited = is_array( $credited ) ? $credited : array();
            $credited[] = array( 'key' => 'wc:payment:' . $payment->get_id(), 'amount_cents' => $received );
            $total = (int) $order->get_meta( '_ge_final_total_cents', true );
            $state = GE_WTP_Quote_Balance::reconcile( $total, $credited );
            $order->update_meta_data( '_ge_commercial_credited_attempts', $credited );
            $order->update_meta_data( '_ge_amount_paid_cents', $state['amount_paid_cents'] );
            $order->update_meta_data( '_ge_amount_due_cents', $state['amount_due_cents'] );
            $order->update_meta_data( '_ge_payment_state', $state['payment_status'] );
            if ( 0 === $state['amount_due_cents'] ) { $order->set_date_paid( current_time( 'timestamp', true ) ); }
            $order->add_order_note( 'Cobro acreditado: ' . GE_WTP_Quote_Balance::decimal( $received ) . ' ARS. Saldo: ' . GE_WTP_Quote_Balance::decimal( $state['amount_due_cents'] ) . ' ARS.' );
            $order->save();
            $payment->update_meta_data( '_ge_commercial_reconciled', 'yes' );
            $payment->update_meta_data( '_ge_commercial_operational_order_id', $order->get_id() );
            $payment->save();
            GE_WTP_Commercial_Quotes::record_event( $quote_id, 'payment_confirmed', get_current_user_id(), array( 'payment_order_id' => $payment->get_id(), 'order_id' => $order->get_id(), 'amount_cents' => $received ) );
        } catch ( Throwable $error ) {
            $payment->add_order_note( 'La conciliación automática requiere revisión. No repetir el cobro.' );
        } finally { self::release_lock( $lock ); }
    }

    /** MySQL owns the lock for the connection lifetime: crashed requests cannot orphan it. */
    private static function acquire_lock( $name ) {
        global $wpdb;
        $key = 'ge:' . substr( hash( 'sha256', $wpdb->prefix . $name ), 0, 55 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) { return false; }
        // Legacy option locks are obsolete once all writers use this connection lock.
        delete_option( $name );
        return true;
    }

    private static function release_lock( $name ) {
        global $wpdb;
        $key = 'ge:' . substr( hash( 'sha256', $wpdb->prefix . $name ), 0, 55 );
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
    }

    /** Staff may create the same operational order before any customer portal action. */
    public static function convert_staff( $quote_id, $args, $actor_id ) {
        if (class_exists('GE_Organization_Runtime') && (!GE_Organization_Runtime::allowed('quotes',true,$actor_id)||!GE_Organization_Runtime::allowed('orders',true,$actor_id)))return new WP_Error('ge_org_permission','Conversión no habilitada para este rol o módulo.');
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) { return new WP_Error( 'ge_quote_forbidden', 'Acceso denegado.' ); }
        $quote = GE_WTP_Commercial_Quotes::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( $quote['converted_order_id'] ) {
            $existing = wc_get_order( $quote['converted_order_id'] );
            return $existing ?: new WP_Error( 'ge_quote_missing_order', 'El pedido vinculado no está disponible; requiere conciliación.' );
        }
        $recovered = self::find_operational_order( $quote );
        if ( $recovered ) {
            if ( is_wp_error( $recovered ) ) { return $recovered; }
            update_post_meta( $quote_id, GE_WTP_Commercial_Quotes::ORDER_META, $recovered->get_id() );
            update_post_meta( $quote_id, GE_WTP_Commercial_Quotes::STATUS_META, 'converted' );
            GE_WTP_Commercial_Quotes::record_event( $quote_id, 'conversion_recovered', $actor_id, array( 'order_id' => $recovered->get_id() ) );
            return $recovered;
        }
        if ( isset( $args['expected_version'] ) && (int) $args['expected_version'] !== (int) $quote['version'] ) { return new WP_Error( 'ge_quote_version_changed', 'El presupuesto cambió. Volvé a abrirlo antes de crear el pedido.' ); }
        $lock = 'ge_commercial_reconcile_' . $quote_id;
        if ( ! self::acquire_lock( $lock ) ) { return new WP_Error( 'ge_quote_busy', 'El presupuesto se está procesando.' ); }
        try {
            $quote = GE_WTP_Commercial_Quotes::prepare_for_conversion( $quote_id, $actor_id );
            if ( is_wp_error( $quote ) ) { return $quote; }
            if ( $quote['converted_order_id'] ) { return wc_get_order( $quote['converted_order_id'] ); }
            if ( isset( $args['expected_version'] ) && (int) $args['expected_version'] !== (int) $quote['version'] ) { return new WP_Error( 'ge_quote_version_changed', 'El presupuesto cambió. Volvé a abrirlo.' ); }
            if ( in_array( $quote['status'], array( 'rejected', 'cancelled' ), true ) ) { return new WP_Error( 'ge_quote_state', 'Este presupuesto no se puede convertir.' ); }
            if ( in_array( 'billing_identity_changed_requires_reconciliation', (array) ( $quote['snapshot']['fiscal_blockers'] ?? array() ), true ) ) { return new WP_Error( 'ge_billing_reconcile', 'Revisá expresamente los importes fiscales antes de convertir.' ); }
            if ( empty( $quote['snapshot']['total_cents'] ) ) { return new WP_Error( 'ge_quote_total', 'Falta resolver el total fiscal.' ); }
            $initial = absint( get_post_meta( $quote_id, '_ge_commercial_initial_payment_order', true ) );
            $payment = $initial ? wc_get_order( $initial ) : false;
            $final = $payment ? (int) get_post_meta( $quote_id, '_ge_commercial_final_total_cents', true ) : (int) $quote['snapshot']['total_cents'];
            if ( $final <= 0 ) { return new WP_Error( 'ge_quote_total', 'El total final no es válido.' ); }
            $method = sanitize_key( $args['confirmation_method'] ?? 'staff' );
            if ( ! in_array( $method, array( 'staff', 'portal', 'whatsapp', 'email', 'phone', 'in_person', 'other' ), true ) ) { return new WP_Error( 'ge_quote_confirmation', 'Elegí cómo se confirmó el presupuesto.' ); }
            $reason = sanitize_textarea_field( $args['reason'] ?? '' );
            if ( 'portal' === $method && 'portal' !== get_post_meta( $quote_id, '_ge_commercial_accept_source', true ) ) { return new WP_Error( 'ge_quote_confirmation', 'No figura una aceptación del cliente en el portal.' ); }
            $payment_claim = sanitize_key( $args['payment_state'] ?? 'unregistered' );
            if ( ! in_array( $payment_claim, array( 'unregistered', 'deposit', 'paid' ), true ) ) { $payment_claim = 'unregistered'; }
            $amount_received = sanitize_text_field( $args['amount_received'] ?? '' );
            $amount_cents = null;
            if ( '' !== $amount_received ) {
                try { $amount_cents = GE_WTP_Quote_Balance::cents( $amount_received ); }
                catch ( InvalidArgumentException $error ) { return new WP_Error( 'ge_quote_amount', 'Importe recibido inválido.' ); }
                if ( $amount_cents < 0 || $amount_cents > $final ) { return new WP_Error( 'ge_quote_amount', 'El importe informado supera el total.' ); }
            }
            if ( ! $payment ) {
                update_post_meta( $quote_id, '_ge_commercial_final_total_cents', $final );
                update_post_meta( $quote_id, '_ge_commercial_adjustment_cents', 0 );
                update_post_meta( $quote_id, '_ge_commercial_method', 'bacs' );
            }
            $order = self::create_operational_order( $quote, $payment );
            if ( is_wp_error( $order ) ) { return $order; }
            $audit = array( 'actor_id' => (int) $actor_id, 'at' => gmdate( 'c' ), 'source' => 'staff', 'confirmation_method' => $method, 'payment_claim' => $payment_claim, 'amount_received_cents' => $amount_cents, 'reason' => $reason, 'override' => 'portal' !== $method || ! $payment || ! GE_WTP_Commercial_Quote_Files::all( $quote_id ) );
            $order->update_meta_data( '_ge_commercial_staff_conversion', $audit );
            $order->update_meta_data( '_ge_commercial_staff_payment_claim', $payment_claim );
            $supplier = sanitize_key( $args['supplier_key'] ?? '' );
            $suppliers = GE_WTP_Production::suppliers();
            if ( $supplier && isset( $suppliers[ $supplier ] ) ) {
                $order->update_meta_data( '_ge_production_supplier', $supplier );
                $order->update_meta_data( '_ge_production_assignment_reason', 'Proveedor elegido en la conversión del presupuesto.' );
                foreach ( $order->get_items( 'line_item' ) as $item ) { $item->update_meta_data( '_ge_production_supplier', $supplier ); $item->save(); }
            }
            $order->update_meta_data( '_ge_production_notes', sanitize_textarea_field( $args['production_notes'] ?? '' ) );
            $order->save();
            update_post_meta( $quote_id, GE_WTP_Commercial_Quotes::ORDER_META, $order->get_id() );
            update_post_meta( $quote_id, GE_WTP_Commercial_Quotes::STATUS_META, 'converted' );
            if ( ! get_post_meta( $quote_id, '_ge_commercial_accepted_at', true ) ) {
                update_post_meta( $quote_id, '_ge_commercial_accepted_at', gmdate( 'c' ) );
                update_post_meta( $quote_id, '_ge_commercial_accepted_by', $actor_id );
                update_post_meta( $quote_id, '_ge_commercial_accept_source', 'staff:' . $method );
                GE_WTP_Commercial_Quotes::record_event( $quote_id, 'accepted_staff', $actor_id, array( 'method' => $method, 'reason' => $reason ) );
            }
            GE_WTP_Commercial_Quotes::record_event( $quote_id, 'converted', $actor_id, array( 'order_id' => $order->get_id(), 'override' => $audit['override'], 'confirmation_method' => $method ) );
            return $order;
        } catch ( Throwable $error ) {
            return new WP_Error( 'ge_quote_conversion_failed', 'La conversión requiere revisión. Volvé al presupuesto; el reintento localizará cualquier pedido ya creado.' );
        } finally { self::release_lock( $lock ); }
    }

    private static function find_operational_order( $quote ) {
        $orders = wc_get_orders( array( 'limit' => 50, 'meta_key' => self::QUOTE_META, 'meta_value' => $quote['id'] ) );
        $orders = array_values( array_filter( $orders, static function ( $order ) { return 'yes' !== $order->get_meta( self::PAYMENT_META, true ); } ) );
        if ( count( $orders ) > 1 ) { return new WP_Error( 'ge_quote_multiple_orders', 'Hay más de un pedido vinculado. Revisá la conciliación antes de continuar.' ); }
        if ( ! $orders ) { return false; }
        $candidate = $orders[0];
        $expected = (int) get_post_meta( $quote['id'], '_ge_commercial_final_total_cents', true ) ?: (int) ( $quote['snapshot']['total_cents'] ?? 0 );
        $actual = GE_WTP_Quote_Balance::cents( wc_format_decimal( $candidate->get_total(), 2 ) );
        if ( $actual !== $expected || ! $candidate->get_meta( '_ge_production_initialized', true ) ) { return new WP_Error( 'ge_quote_incomplete_order', 'Hay un pedido incompleto para este presupuesto; requiere conciliación antes de continuar. No se creará otro.' ); }
        return $candidate;
    }

    private static function create_operational_order( $quote, $payment ) {
        $snapshot = $quote['snapshot'];
        $rate_id = absint( get_option( 'ge_commercial_tax_rate_id', 0 ) );
        $tax_total = (int) ( $snapshot['tax_cents'] ?? 0 );
        if ( $tax_total > 0 && ! $rate_id ) { return new WP_Error( 'ge_quote_tax_rate', 'Falta configurar la tasa de IVA para la orden.' ); }
        $existing = wc_get_orders( array( 'limit' => 50, 'meta_key' => self::QUOTE_META, 'meta_value' => $quote['id'] ) );
        foreach ( $existing as $candidate ) {
            if ( 'yes' === $candidate->get_meta( self::PAYMENT_META, true ) ) { continue; }
            $expected = (int) get_post_meta( $quote['id'], '_ge_commercial_final_total_cents', true );
            $actual = GE_WTP_Quote_Balance::cents( wc_format_decimal( $candidate->get_total(), 2 ) );
            if ( $actual !== $expected || ! $candidate->get_meta( '_ge_production_initialized', true ) ) {
                return new WP_Error( 'ge_quote_incomplete_order', 'Hay un pedido incompleto para este presupuesto; requiere conciliación manual antes de continuar.' );
            }
            return $candidate;
        }
        $customer = get_userdata( $quote['customer_id'] );
        $order = wc_create_order( array( 'customer_id' => $quote['customer_id'] ) );
        if ( is_wp_error( $order ) ) { return $order; }
        $order->set_created_via( 'ge_commercial_quote' );
        // Persist provenance before constructing lines, so interrupted builds are found on retry.
        $order->update_meta_data( self::QUOTE_META, $quote['id'] );
        $order->update_meta_data( '_ge_source_quote_id', $quote['id'] );
        $order->save();
        $order->set_currency( 'ARS' );
        $order->set_billing_email( $customer->user_email );
        $order->set_billing_first_name( $customer->first_name ?: $customer->display_name );
        $order->set_billing_last_name( $customer->last_name );
        $billing_profile = GE_WTP_Quote_Billing_Control::receiver( $snapshot );
        $delivery = $snapshot['delivery'] ?? array();
        if ( ! empty( $billing_profile['legal_name'] ) ) { $order->set_billing_company( $billing_profile['legal_name'] ); }
        if ( ! empty( $billing_profile['fiscal_address'] ) ) { $order->set_billing_address_1( $billing_profile['fiscal_address'] ); }
        if ( ! empty( $delivery['street'] ) ) {
            $order->set_shipping_company( $delivery['label'] ?? '' );
            $order->set_shipping_first_name( $delivery['recipient'] ?? '' );
            $order->set_shipping_address_1( $delivery['street'] );
            $order->set_shipping_city( $delivery['city'] ?? '' );
            $order->set_shipping_state( $delivery['province'] ?? '' );
            $order->set_shipping_postcode( $delivery['postal_code'] ?? '' );
        }
        $method = (string) get_post_meta( $quote['id'], '_ge_commercial_method', true );
        $order->set_payment_method( 'bacs' === $method ? 'bacs' : 'ge_commercial_mercadopago' );
        $order->set_payment_method_title( 'bacs' === $method ? 'Transferencia bancaria' : 'Mercado Pago' );
        $line_count = count( $snapshot['items'] );
        $allocated_tax = 0;
        foreach ( $snapshot['items'] as $index => $line ) {
            $item = new WC_Order_Item_Product();
            if ( $line['product_id'] && wc_get_product( $line['product_id'] ) ) { $item->set_product_id( $line['product_id'] ); }
            $item->set_name( $line['name'] );
            $item->set_quantity( $line['quantity'] );
            $item->set_subtotal( GE_WTP_Quote_Balance::decimal( $line['net_cents'] ) );
            $item->set_total( GE_WTP_Quote_Balance::decimal( $line['taxable_base_cents'] ?? $line['net_cents'] ) );
            if ( $tax_total > 0 ) {
                $line_tax = isset( $line['tax_cents'] ) ? (int) $line['tax_cents'] : ( $index === $line_count - 1 ? $tax_total - $allocated_tax : intdiv( $tax_total * $line['net_cents'] + intdiv( $snapshot['net_cents'], 2 ), $snapshot['net_cents'] ) );
                $allocated_tax += $line_tax;
                $item->set_taxes( array( 'total' => array( $rate_id => GE_WTP_Quote_Balance::decimal( $line_tax ) ), 'subtotal' => array( $rate_id => GE_WTP_Quote_Balance::decimal( $line_tax ) ) ) );
            }
            $specifications = implode( ' · ', array_filter( array( GE_WTP_Commercial_Quote_UI::customer_configuration_label( $line ), $line['details'] ?? '', $line['notes'] ?? '' ) ) );
            if ( $specifications ) { $item->add_meta_data( 'Especificaciones', $specifications, true ); }
            if ( 'u' !== ( $line['unit'] ?? 'u' ) ) { $item->add_meta_data( 'Unidad', $line['unit'], true ); }
            $item->update_meta_data('_ge_quote_line_uuid', GE_WTP_Quote_Artwork_V2::line_id($quote['id'], $index, $line));
            $item->update_meta_data( '_ge_quote_source_type', $line['source_type'] ?? ( ! empty( $line['product_id'] ) ? 'catalog_product' : 'custom' ) );
            $item->update_meta_data( '_ge_quote_unit', $line['unit'] ?? 'u' );
            $item->update_meta_data( '_ge_quote_unit_net_cents', (int) $line['unit_net_cents'] );
            if ( ! empty( $line['configuration']['roll_width_cm'] ) ) { $item->update_meta_data( '_ge_internal_roll_width_cm', $line['configuration']['roll_width_cm'] ); }
            if ( ! empty( $line['finishes'] ) ) { $item->update_meta_data( GE_WTP_Workflow::FINISHES_META, $line['finishes'] ); }
            $item->update_meta_data( '_ge_item_status', 'pending' );
            $order->add_item( $item );
        }
        if ( $tax_total > 0 ) {
            $tax_item = new WC_Order_Item_Tax();
            $tax_item->set_rate_id( $rate_id );
            $tax_item->set_label( 'IVA según presupuesto' );
            $tax_item->set_tax_total( GE_WTP_Quote_Balance::decimal( $tax_total ) );
            $order->add_item( $tax_item );
        }
        $adjustment = (int) get_post_meta( $quote['id'], '_ge_commercial_adjustment_cents', true );
        if ( $adjustment ) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name( $adjustment < 0 ? 'Descuento por transferencia' : 'Recargo Mercado Pago' );
            $fee->set_amount( GE_WTP_Quote_Balance::decimal( abs( $adjustment ) ) * ( $adjustment < 0 ? -1 : 1 ) );
            $fee->set_total( GE_WTP_Quote_Balance::decimal( abs( $adjustment ) ) * ( $adjustment < 0 ? -1 : 1 ) );
            $order->add_item( $fee );
        }
        $final = (int) get_post_meta( $quote['id'], '_ge_commercial_final_total_cents', true );
        $order->update_meta_data( self::QUOTE_META, $quote['id'] );
        $order->update_meta_data( '_ge_source_quote_id', $quote['id'] );
        $order->update_meta_data( '_ge_commercial_quote_version', $quote['version'] );
        GE_WTP_Billing_Issuers::inherit( $order, $snapshot );
        $order->update_meta_data( '_ge_commercial_quote_snapshot', $snapshot );
        $order->update_meta_data( '_ge_commercial_discounts', $snapshot['discounts'] ?? array() );
        $order->update_meta_data( '_ge_commercial_snapshot_hash', $snapshot['snapshot_hash'] ?? '' );
        $order->update_meta_data( '_ge_commercial_billing_snapshot', $snapshot['billing'] );
        $order->update_meta_data( '_ge_billing_profile_snapshot', $billing_profile );
        $order->update_meta_data( '_ge_billing_profile_id', $snapshot['billing_profile_id'] ?? 'default' );
        $order->update_meta_data( '_ge_delivery_snapshot', $delivery );
        $order->update_meta_data( '_ge_final_total_cents', $final );
        $order->update_meta_data( '_ge_deposit_percent', $snapshot['deposit_percent'] );
        $order->update_meta_data( '_ge_amount_paid_cents', 0 );
        $order->update_meta_data( '_ge_amount_due_cents', $final );
        $order->update_meta_data( '_ge_payment_state', 'pending' );
        $order->calculate_totals( false );
        $constructed_net = 0;
        foreach ( $order->get_items( 'line_item' ) as $line_item ) {
            $constructed_net += GE_WTP_Quote_Balance::cents( wc_format_decimal( $line_item->get_total(), 2 ) );
        }
        if ( $constructed_net + $tax_total + $adjustment !== $final ) {
            $order->add_order_note( 'Los ítems construidos no coinciden con el presupuesto aceptado; no liberar producción.' );
            $order->save();
            return new WP_Error( 'ge_quote_total_mismatch', 'Los ítems de la orden no coinciden con el presupuesto.' );
        }
        // WooCommerce may have global tax calculation disabled. The accepted
        // snapshot remains authoritative for this order's tax and final total.
        $order->set_cart_tax( GE_WTP_Quote_Balance::decimal( $tax_total ) );
        $order->set_total( GE_WTP_Quote_Balance::decimal( $final ) );
        $calculated = GE_WTP_Quote_Balance::cents( wc_format_decimal( $order->get_total(), 2 ) );
        if ( $calculated !== $final ) { $order->add_order_note( 'Total inconsistente con presupuesto aceptado; no liberar producción.' ); $order->save(); return new WP_Error( 'ge_quote_total_mismatch', 'El total de la orden no coincide con el presupuesto.' ); }
        $order->save();
        GE_WTP_Workflow::enable( $order );
        GE_WTP_Commercial_Quote_Files::inherit( $quote['id'], $order );
        $order->set_status( 'ge-confirmado', $payment ? 'Pedido creado tras acreditarse el primer cobro del presupuesto.' : 'Pedido creado manualmente desde presupuesto; pago y arte pendientes de verificación.' );
        $order->save();
        GE_WTP_Production::ensure_order( $order );
        return $order;
    }
}
