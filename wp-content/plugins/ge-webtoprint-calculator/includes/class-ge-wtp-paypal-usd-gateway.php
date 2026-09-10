<?php

defined( 'ABSPATH' ) || exit;

final class GE_WTP_PayPal_USD_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id                 = GE_WTP_Payments::PAYPAL_GATEWAY;
        $this->method_title       = 'PayPal en USD';
        $this->method_description = 'Registra el pedido en pesos y fija su equivalente en USD con dólar vendedor Banco Nación.';
        $this->has_fields         = false;
        $this->supports           = array( 'products' );
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled     = $this->get_option( 'enabled', 'no' );
        $this->title       = $this->get_option( 'title', 'PayPal en USD' );
        $this->description = $this->get_option( 'description', 'El importe se convierte a USD con el dólar vendedor Banco Nación vigente al confirmar el pedido.' );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array( 'title' => 'Activar', 'type' => 'checkbox', 'label' => 'Permitir pagos por PayPal en USD', 'default' => 'no' ),
            'title' => array( 'title' => 'Título', 'type' => 'text', 'default' => 'PayPal en USD' ),
            'description' => array( 'title' => 'Descripción', 'type' => 'textarea', 'default' => 'El importe se convierte a USD con el dólar vendedor Banco Nación vigente al confirmar el pedido.' ),
        );
    }

    public function is_available() {
        return parent::is_available() && GE_WTP_Catalog::exchange_rate() > 0 && is_email( get_option( 'ge_wtp_paypal_email', '' ) );
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        $rate  = (float) GE_WTP_Catalog::exchange_rate();
        $email = sanitize_email( get_option( 'ge_wtp_paypal_email', '' ) );
        if ( ! $order || $rate <= 0 || ! $email ) {
            wc_add_notice( 'PayPal no está disponible en este momento. Elegí otro medio de pago.', 'error' );
            return array( 'result' => 'failure' );
        }

        $usd = round( (float) $order->get_total() / $rate, 2 );
        $order->update_meta_data( '_ge_paypal_ars_total', (float) $order->get_total() );
        $order->update_meta_data( '_ge_paypal_usd_amount', $usd );
        $order->update_meta_data( '_ge_paypal_bna_rate', $rate );
        $order->update_meta_data( '_ge_paypal_bna_updated_at', GE_WTP_Catalog::exchange_updated_at() );
        $order->update_meta_data( '_ge_paypal_recipient', $email );
        $order->update_status( 'on-hold', sprintf( 'Pendiente de PayPal: USD %1$s a %2$s. Cotización BNA ARS %3$s.', number_format( $usd, 2, '.', '' ), $email, number_format( $rate, 2, '.', '' ) ) );
        $order->save();
        wc_reduce_stock_levels( $order_id );
        WC()->cart->empty_cart();
        return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
    }
}
