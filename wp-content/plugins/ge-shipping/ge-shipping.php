<?php
/**
 * Plugin Name: Graphex Envíos
 * Description: Cotización de envíos en WooCommerce para pedidos de impresión.
 * Version: 0.2.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/calculator.php';
require_once __DIR__ . '/checkout.php';

add_action('woocommerce_shipping_init', function () {
    require_once __DIR__ . '/shipping-method.php';
});

add_filter('woocommerce_shipping_methods', function ($methods) {
    $methods['ge_shipping'] = 'GE_Shipping_Method';
    return $methods;
});

add_filter('woocommerce_cart_shipping_packages', function ($packages) {
    // WooCommerce excludes product objects from its cache key.
    foreach ($packages as &$package) {
        $package['ge_shipping_date'] = wp_date('Y-m-d');
        foreach ($package['contents'] as $key => $line) {
            $product = $line['data'];
            $package['ge_shipping_specs'][$key] = array(
                $product->get_weight(), $product->get_length(), $product->get_width(), $product->get_height(),
                md5(wp_json_encode($product->get_meta('_ge_digital_config'))),
            );
        }
    }
    unset($package);
    return $packages;
});

add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    foreach (WC()->shipping()->get_packages() as $index => $package) {
        $chosen = $data['shipping_method'][$index] ?? '';
        $rate = $package['rates'][$chosen] ?? null;
        if ($rate && $rate->get_method_id() === 'ge_shipping') {
            $destination = $package['destination'];
            if (trim($destination['address_1'] ?? $destination['address'] ?? '') === ''
                || trim($destination['city'] ?? '') === '') {
                $errors->add('ge_shipping_address', 'Ingresá calle, número y localidad para el envío.');
                break;
            }
        }
    }
}, 10, 2);

add_action('wp_enqueue_scripts', function () {
    if (!defined('GE_SHIPPING_MAPS_BROWSER_KEY') || !GE_SHIPPING_MAPS_BROWSER_KEY
        || !function_exists('is_checkout') || !is_checkout() || is_order_received_page()
        || is_wc_endpoint_url('order-pay')) {
        return;
    }
    wp_enqueue_script('ge-shipping-address', plugins_url('address.js', __FILE__), array(), '0.1.0', true);
    wp_add_inline_script('ge-shipping-address', 'window.geShippingMapsKey = '
        . wp_json_encode(GE_SHIPPING_MAPS_BROWSER_KEY) . ';', 'before');
});


