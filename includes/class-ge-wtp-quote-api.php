<?php
defined('ABSPATH') || exit;

/** Read-only quotations for the two configured sticker products. */
final class GE_WTP_Quote_API {
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route('ge/v1', '/quote', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function handle($request) {
        $input = $request->get_json_params();
        if (!is_array($input)) return new WP_Error('ge_invalid_quote', 'Se requiere un objeto JSON.', array('status' => 400));
        $sku = isset($input['sku']) && is_string($input['sku']) ? $input['sku'] : '';
        if (!in_array($sku, array('ID-STI-001', 'GF-VIN-008'), true)) {
            return new WP_Error('ge_unsupported_product', 'Producto no admitido.', array('status' => 422));
        }
        if (!function_exists('wc_get_product_id_by_sku')) return new WP_Error('ge_catalog_unavailable', 'Catálogo no disponible.', array('status' => 503));
        $product_id = wc_get_product_id_by_sku($sku);
        $product = $product_id ? wc_get_product($product_id) : false;
        if (!$product || 'publish' !== $product->get_status() || $product->get_sku() !== $sku) {
            return new WP_Error('ge_product_unavailable', 'Producto no disponible.', array('status' => 404));
        }
        $options = isset($input['options']) && is_array($input['options']) ? $input['options'] : array();
        $quote = 'ID-STI-001' === $sku
            ? self::paper($product_id, $options)
            : self::vinyl($product_id, $options);
        $quote['product'] = array('id' => $product_id, 'sku' => $sku, 'name' => $product->get_name(), 'url' => get_permalink($product_id));
        $quote['source'] = array('kind' => 'wordpress-configurator', 'checked_at' => gmdate('c'));
        return rest_ensure_response($quote);
    }

    private static function partial($reason) {
        return array('status' => 'partial', 'reason' => $reason, 'currency' => 'ARS', 'amount' => null);
    }

    private static function positive_int($value, $minimum, $maximum) {
        if (!(is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/', $value)))) return null;
        $number = (int) $value;
        return $number >= $minimum && $number <= $maximum ? $number : null;
    }

    private static function paper($product_id, $options) {
        $config = get_post_meta($product_id, '_ge_digital_config', true);
        if (!is_array($config) || ($config['sku'] ?? '') !== 'ID-STI-001') return self::partial('Regla de papel no disponible.');
        $model = $config['pricing_model'] ?? array();
        if (($model['type'] ?? '') !== 'tiered-unit') return self::partial('Modelo de papel no disponible.');
        $quantity = self::positive_int($options['planchas'] ?? null, 5, 10000);
        $cut = $options['corte'] ?? null;
        $complex = $options['complejidad'] ?? null;
        if (!$quantity || !is_string($cut) || !in_array($cut, array('3x3', '5x5', '10x10', '290x440'), true) || !is_bool($complex)) {
            return self::partial('Indicar planchas (mínimo 5), tamaño de corte y complejidad.');
        }
        $key = '290x440|' . $cut;
        $breaks = $model['breaks'] ?? array();
        $rates = $model['supplier_unit_rates'][$key] ?? array();
        if (!is_array($breaks) || !is_array($rates) || count($breaks) !== count($rates) || !$rates) return self::partial('Escala de papel incompleta.');
        $tier = null;
        foreach ($breaks as $index => $minimum) if ($quantity >= (int) $minimum) $tier = $index;
        $rate = null === $tier ? 0 : (float) $rates[$tier];
        $markup = null;
        foreach (($model['commercial_markups'] ?? array()) as $range) {
            if ($quantity >= (int) ($range['min'] ?? 0) && $quantity <= (int) ($range['max'] ?? 0)) {
                $markup = (float) ($range['rate'] ?? -1);
                break;
            }
        }
        $surcharge = null;
        foreach (($config['fields'] ?? array()) as $field) if (($field['key'] ?? '') === 'complejidad') $surcharge = (float) ($field['surcharge'] ?? -1);
        if ($rate <= 0 || null === $markup || $markup < 0 || null === $surcharge || $surcharge < 0) return self::partial('Tarifa de papel incompleta.');
        $amount = round($rate * (1 + $markup) * $quantity * ($complex ? 1 + $surcharge : 1));
        return array('status' => 'quoted', 'amount' => $amount, 'currency' => 'ARS', 'unit' => 'plancha', 'quantity' => $quantity, 'tax_excluded' => true);
    }

    private static function vinyl($product_id, $options) {
        if (($options['transfer'] ?? false) !== false) return self::partial('El transfer requiere cotización personalizada.');
        $quantity = self::positive_int($options['planchas'] ?? null, 1, 10000);
        $size = $options['tamano'] ?? null;
        $base = $options['base'] ?? null;
        $cut = $options['corte'] ?? null;
        if (!$quantity || !in_array($size, array('100x60', '100x100'), true) || !in_array($base, array('blanca', 'clear'), true) || !in_array($cut, array('medio-corte', 'corte-completo'), true)) {
            return self::partial('Indicar planchas, tamaño, base y corte de vinilo.');
        }
        $key = $size . '-base-' . $base . '-' . $cut;
        $costs = get_post_meta($product_id, '_ge_supplier_costs', true);
        $cost = is_array($costs) && isset($costs[$key]) ? (float) $costs[$key] : 0;
        $margin = get_option('ge_wtp_bandurria_margin', 30);
        if ($cost <= 0 || !is_numeric($margin) || (float) $margin < 0) return self::partial('Tarifa de vinilo incompleta.');
        $unit_price = round($cost * (1 + (float) $margin / 100));
        return array('status' => 'quoted', 'amount' => $unit_price * $quantity, 'currency' => 'ARS', 'unit' => 'plancha', 'quantity' => $quantity, 'tax_excluded' => true);
    }
}

GE_WTP_Quote_API::init();
