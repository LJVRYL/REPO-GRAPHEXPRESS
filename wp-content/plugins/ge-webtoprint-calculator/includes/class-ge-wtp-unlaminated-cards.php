<?php
defined('ABSPATH') || exit;

/** Additive view of the existing card configuration. Never writes product metadata or prices. */
final class GE_WTP_Unlaminated_Cards {
    public static function init() {
        add_filter('woocommerce_product_get__ge_digital_config', array(__CLASS__, 'product_config'), 30, 2);
        add_filter('woocommerce_product_get_description', array(__CLASS__, 'description'), 30, 2);
        add_filter('woocommerce_product_get_short_description', array(__CLASS__, 'description'), 30, 2);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 40);
    }
    private static function product($product) {
        return is_object($product) && (int) $product->get_id() === 83 && $product->get_sku() === 'ID-EXP-001';
    }
    public static function product_config($config, $product) {
        if (!self::product($product) || !is_array($config) || ($config['sku'] ?? '') !== 'ID-EXP-001' || empty($config['fields'])) return $config;
        $keys = array_column($config['fields'], 'key');
        if (!in_array('laminado_caras', $keys, true) || !in_array('laminado_acabado', $keys, true)) return $config;
        foreach ($config['fields'] as &$field) {
            if ($field['key'] === 'laminado_caras' && !in_array('sin-laminar', array_column($field['options'], 'value'), true)) {
                // No quote_required flag: exact existing prices may be added later by the approved catalog workflow.
                // Until then the native matrix returns no price, and native checkout already rejects it.
                $field['options'][] = array('value' => 'sin-laminar', 'label' => 'Sin laminar', 'when' => array());
            }
            if ($field['key'] === 'laminado_acabado') {
                $options = array();
                foreach ($field['options'] as $option) {
                    if ($option['value'] === 'no-aplica') continue;
                    if (in_array($option['value'], array('mate', 'brillante'), true)) $option['when']['laminado_caras'] = array('simple', 'doble');
                    $options[] = $option;
                }
                array_unshift($options, array('value' => 'no-aplica', 'label' => 'No aplica', 'when' => array('laminado_caras' => 'sin-laminar')));
                $field['options'] = $options;
            }
        }
        unset($field);
        if (isset($config['description'])) $config['description'] = str_replace('con laminado mate o brillante', 'con o sin laminado', $config['description']);
        return $config;
    }
    public static function description($description, $product) {
        return self::product($product) ? str_replace('con laminado mate o brillante', 'con o sin laminado', $description) : $description;
    }
    public static function enqueue() {
        $file = GE_WTP_PLUGIN_DIR . 'assets/js/unlaminated-cards.js';
        wp_enqueue_script('ge-unlaminated-cards', GE_WTP_PLUGIN_URL . 'assets/js/unlaminated-cards.js', array(), (string) filemtime($file), true);
    }
}
