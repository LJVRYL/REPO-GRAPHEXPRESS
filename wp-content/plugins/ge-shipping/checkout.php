<?php
defined('ABSPATH') || exit;

final class GE_Shipping_Checkout {
    const QUOTE = 'ge_shipping_quote';
    const VERSION = '0.2.0';
    const QUOTE_MESSAGE = 'Te informaremos el costo del envío por separado. El total de este pedido todavía no lo incluye.';

    private static $intent = '';

    public static function init() {
        add_action('woocommerce_before_checkout_customer_details', array(__CLASS__, 'render_choice'));
        add_action('woocommerce_checkout_update_order_review', array(__CLASS__, 'read_review'));
        add_action('woocommerce_checkout_process', array(__CLASS__, 'read_checkout'), 1);
        add_filter('pre_option_woocommerce_ship_to_destination', array(__CLASS__, 'shipping_destination'));
        add_filter('woocommerce_cart_shipping_packages', array(__CLASS__, 'mark_packages'), 20);
        add_filter('woocommerce_package_rates', array(__CLASS__, 'rates'), 100);
        add_filter('woocommerce_cart_shipping_method_full_label', array(__CLASS__, 'rate_label'), 10, 2);
        add_action('woocommerce_after_checkout_validation', array(__CLASS__, 'validate'), 10, 2);
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'classic_order'), 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'record_quote'), 20);
        add_action('woocommerce_admin_order_data_after_shipping_address', array(__CLASS__, 'admin_notice'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    private static function is_checkout() {
        return did_action('wp') && function_exists('is_checkout') && is_checkout()
            && !is_wc_endpoint_url('order-pay') && !is_order_received_page();
    }

    private static function classic_form() {
        if (!self::is_checkout()) {
            return false;
        }
        $page = get_post(wc_get_page_id('checkout'));
        return !$page || !has_block('woocommerce/checkout', $page);
    }

    private static function intent() {
        if (self::$intent !== '') {
            return self::$intent;
        }
        if (self::classic_form() && WC()->session) {
            return WC()->session->get('ge_delivery_intent', 'pickup');
        }
        return '';
    }

    private static function set_intent($value) {
        if (!in_array($value, array('pickup', 'delivery'), true)) {
            return;
        }
        self::$intent = $value;
        WC()->session->set('ge_delivery_intent', $value);
    }

    public static function read_review($serialized) {
        parse_str($serialized, $data);
        self::set_intent($data['ge_delivery_intent'] ?? '');
    }

    public static function read_checkout() {
        // WC_Checkout verifies its nonce before firing this hook.
        self::set_intent(wp_unslash($_POST['ge_delivery_intent'] ?? ''));
        if (self::$intent !== '') {
            $_POST['ship_to_different_address'] = self::$intent === 'delivery' ? '1' : '0';
        }
    }

    public static function shipping_destination($destination) {
        // Even a checkout configured as billing-only needs a shipping address
        // when this checkout's delivery choice is active.
        if (self::$intent !== '' || self::classic_form()) {
            return self::intent() === 'delivery' ? 'shipping' : 'billing';
        }
        return $destination;
    }

    public static function render_choice() {
        if (!WC()->cart->needs_shipping()) {
            return;
        }
        $intent = self::intent();
        ?>
        <fieldset class="ge-delivery-choice">
            <legend>¿Cómo querés recibir tu pedido?</legend>
            <label><input type="radio" name="ge_delivery_intent" value="pickup" <?php checked($intent, 'pickup'); ?>> Retiro en tienda</label>
            <label><input type="radio" name="ge_delivery_intent" value="delivery" <?php checked($intent, 'delivery'); ?>> Quiero que me lo envíen</label>
        </fieldset>
        <?php
    }

    public static function mark_packages($packages) {
        foreach ($packages as &$package) {
            $package['ge_shipping_version'] = self::VERSION;
            $package['ge_delivery_intent'] = self::intent();
        }
        unset($package);
        return $packages;
    }

    private static function is_pickup($rate) {
        return in_array($rate->get_method_id(), array('local_pickup', 'legacy_local_pickup', 'pickup_location'), true);
    }

    private static function has_destination($destination) {
        $province = $destination['state'] ?? '';
        $postal = strtoupper(trim($destination['postcode'] ?? ''));
        $states = WC()->countries->get_states('AR');
        return ($destination['country'] ?? '') === 'AR' && isset($states[$province])
            && GE_Shipping_Calculator::postcode($postal) > 0
            && (strlen($postal) !== 8 || $postal[0] === $province);
    }

    public static function rates($rates, $package) {
        $intent = $package['ge_delivery_intent'] ?? '';
        $pickup = array_filter($rates, array(__CLASS__, 'is_pickup'));
        $delivery = array_diff_key($rates, $pickup);

        if ($intent === 'pickup') {
            return $pickup;
        }
        if (!$delivery && self::has_destination($package['destination'] ?? array())) {
            $rate = new WC_Shipping_Rate(self::QUOTE, 'Envío a cotizar', 0, array(), self::QUOTE);
            if (method_exists($rate, 'set_description')) {
                $rate->set_description(self::QUOTE_MESSAGE);
            }
            $delivery[self::QUOTE] = $rate;
        }
        return $intent === 'delivery' ? $delivery : $rates + $delivery;
    }

    public static function rate_label($label, $rate) {
        if ($rate->get_method_id() === self::QUOTE) {
            return esc_html($rate->get_label()) . '<br><small>' . esc_html(self::QUOTE_MESSAGE) . '</small>';
        }
        return $label;
    }

    public static function validate($data, $errors) {
        $intent = self::intent();
        if (!WC()->cart->needs_shipping() || $intent === '') {
            return;
        }
        $packages = WC()->shipping()->get_packages();
        if ($intent === 'delivery') {
            $destination = array(
                'country' => $data['shipping_country'] ?? '', 'state' => $data['shipping_state'] ?? '',
                'postcode' => $data['shipping_postcode'] ?? '',
            );
            if (!self::has_destination($destination) || trim($data['shipping_address_1'] ?? '') === ''
                || trim($data['shipping_city'] ?? '') === '') {
                $errors->add('ge_shipping_address', 'Ingresá calle y número, localidad, provincia y código postal de Argentina para el envío.');
                return;
            }
        }
        if (!$packages) {
            $errors->add('ge_shipping_missing', 'No pudimos validar la entrega. Revisá los datos e intentá nuevamente.');
            return;
        }
        foreach ($packages as $index => $package) {
            $chosen = wc_get_chosen_shipping_method_for_package($index, $package);
            $rate = $package['rates'][$chosen] ?? null;
            if (!$rate || self::is_pickup($rate) !== ($intent === 'pickup')) {
                $errors->add('ge_shipping_missing', 'No pudimos validar la opción de entrega. Revisá los datos e intentá nuevamente.');
                return;
            }
            $submitted = $data['shipping_method'][$index] ?? '';
            if ($submitted !== $chosen) {
                $errors->add('ge_shipping_changed', 'La opción de entrega cambió. Revisá el costo actualizado antes de confirmar.');
                return;
            }
        }
    }

    public static function classic_order($order_id, $data, $order) {
        self::record_quote($order);
    }

    public static function record_quote($order) {
        $pending = false;
        foreach ($order->get_items('shipping') as $line) {
            if ($line->get_method_id() === self::QUOTE) {
                $pending = true;
                break;
            }
        }
        $recorded = $order->get_meta('_ge_shipping_quote_pending') === 'yes';
        if ($pending === $recorded) {
            return;
        }
        if (!$pending) {
            $order->delete_meta_data('_ge_shipping_quote_pending');
            $order->save_meta_data();
            return;
        }

        $order->update_meta_data('_ge_shipping_quote_pending', 'yes');
        $order->save_meta_data();
        $order->add_order_note('Envío a cotizar. El domicilio está en el pedido. Cotizar el envío y confirmar el importe con el cliente antes del despacho.');

        $subject = 'Cotizar envío · Pedido #' . $order->get_order_number();
        $message = '<p>El cliente eligió envío a domicilio y el pedido quedó con el costo de envío pendiente.</p>'
            . '<p><a href="' . esc_url($order->get_edit_order_url()) . '">Abrir pedido en WooCommerce</a></p>';
        $sent = class_exists('GE_WTP_Notification_Center')
            ? GE_WTP_Notification_Center::send_internal('new_order', $subject, $message, $order->get_id())
            : wp_mail(get_option('admin_email'), $subject, $message, array('Content-Type: text/html; charset=UTF-8'));

        if ($sent === false) {
            $order->add_order_note('El correo interno de envío a cotizar no pudo enviarse. La alerta queda registrada en el pedido.');
        }
    }

    public static function admin_notice($order) {
        if ($order->get_meta('_ge_shipping_quote_pending') === 'yes') {
            echo '<p><strong>Envío a cotizar</strong><br>El costo de envío está pendiente.</p>';
        }
    }

    public static function assets() {
        if (!self::is_checkout()) {
            return;
        }
        wp_enqueue_style('ge-shipping-checkout', plugins_url('checkout.css', __FILE__), array(), self::VERSION);
        if (self::classic_form()) {
            wp_enqueue_script('ge-shipping-choice', plugins_url('checkout.js', __FILE__), array('wc-checkout'), self::VERSION, true);
        }
    }
}
GE_Shipping_Checkout::init();

