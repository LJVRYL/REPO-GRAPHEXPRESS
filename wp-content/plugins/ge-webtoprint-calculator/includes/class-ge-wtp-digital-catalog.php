<?php

defined('ABSPATH') || exit;

/**
 * Catálogo configurable de imprenta digital.
 *
 * Las matrices son deliberadamente explícitas: cada combinación comercial
 * conserva su precio y puede actualizarse sin alterar la interfaz.
 */
final class GE_WTP_Digital_Catalog {
    const SOURCE_NAME = 'Druck';
    const SOURCE_DATE = '2026-09-10';
    const CART_ACTION = 'ge_add_digital_product';
    private static $adding_configured_product = false;

    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'render_calculator'), 29);
        add_action('admin_post_' . self::CART_ACTION, array(__CLASS__, 'add_to_cart'));
        add_action('admin_post_nopriv_' . self::CART_ACTION, array(__CLASS__, 'add_to_cart'));
        add_filter('woocommerce_is_purchasable', array(__CLASS__, 'purchasable'), 9998, 2);
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_cart_source'), 9998, 5);
    }

    public static function purchasable($purchasable, $product) {
        return $product && $product->get_meta('_ge_digital_config') ? true : $purchasable;
    }

    public static function validate_cart_source($valid, $product_id, $quantity, $variation_id = 0, $variations = array()) {
        return get_post_meta($product_id, '_ge_digital_config', true) ? self::$adding_configured_product : $valid;
    }

    private static function validate_selection($config, $input) {
        $values = array(); $labels = array(); $quote_required = false; $surcharge = 0;
        foreach ($config['fields'] as $field) {
            $key = $field['key']; $type = $field['type'];
            if ('checkbox' === $type) {
                $values[$key] = !empty($input[$key]);
                if ($values[$key]) { $surcharge += (float) ($field['surcharge'] ?? 0); $labels[] = $field['label'] . ': Sí'; }
            } elseif ('number' === $type) {
                $value = $input[$key] ?? null;
                if (!is_scalar($value) || !is_numeric($value)) { return new WP_Error('invalid_number', 'Revisá la cantidad elegida.'); }
                $number = (float) $value;
                $min = (float) ($field['min'] ?? 1); $max = (float) ($field['max'] ?? PHP_INT_MAX); $step = (float) ($field['step'] ?? 1);
                if ($number < $min || $number > $max || $step <= 0 || abs((($number - $min) / $step) - round(($number - $min) / $step)) > 0.000001) { return new WP_Error('invalid_number', 'La cantidad no está dentro de las opciones disponibles.'); }
                $values[$key] = $number; $labels[] = $field['label'] . ': ' . $value;
            } else {
                $value = $input[$key] ?? '';
                if (!is_scalar($value)) { return new WP_Error('invalid_option', 'Revisá las opciones elegidas.'); }
                $match = null;
                foreach ($field['options'] as $option) { if ((string) $option['value'] === (string) $value) { $match = $option; break; } }
                if (!$match) { return new WP_Error('invalid_option', 'La opción elegida ya no está disponible.'); }
                $values[$key] = (string) $value;
                $labels[] = $field['label'] . ': ' . $match['label'];
                if (!empty($match['quote_required'])) { $quote_required = true; }
            }
        }
        foreach ($config['fields'] as $field) {
            if ('select' !== $field['type']) { continue; }
            foreach ($field['options'] as $option) {
                if ((string) $option['value'] !== (string) $values[$field['key']]) { continue; }
                foreach (($option['when'] ?? array()) as $key => $expected) {
                    $allowed = is_array($expected) ? array_map('strval', $expected) : array((string) $expected);
                    if (!in_array((string) ($values[$key] ?? ''), $allowed, true)) { return new WP_Error('invalid_dependency', 'Esta combinación ya no está disponible.'); }
                }
            }
        }
        return compact('values', 'labels', 'quote_required', 'surcharge');
    }

    private static function calculated_price($config, $selection) {
        if ($selection['quote_required'] || !empty($config['quote_only']) || 'estimated' === ($config['price_status'] ?? '')) { return 0; }
        $values = $selection['values']; $surcharge = $selection['surcharge'];
        $model = $config['pricing_model'] ?? array();
        if ('tiered-unit' === ($model['type'] ?? '')) {
            $key = implode('|', array_map(function ($dimension) use ($values) { return (string) ($values[$dimension] ?? ''); }, $model['dimension_keys'] ?? array()));
            $rates = $model['public_unit_rates'][$key] ?? array();
            $quantity = max(1, (int) ($values['cantidad'] ?? 1));
            if (!$rates) { return 0; }
            $tier = 0;
            foreach (($model['breaks'] ?? array()) as $index => $minimum) { if ($quantity >= (int) $minimum) { $tier = $index; } }
            $print = (float) ($rates[min($tier, count($rates) - 1)] ?? 0) * $quantity;
            $lamination = $model['lamination'] ?? array(); $lamination_total = 0;
            $selected = $values[$lamination['field'] ?? 'laminado'] ?? '';
            $papers = $lamination['papers'] ?? array($lamination['paper'] ?? '');
            if (in_array($values['papel'] ?? '', $papers, true) && $selected && 'sin-laminar' !== $selected) {
                $sides = (float) ($lamination['sides'][$selected] ?? 0);
                $unit = (float) ($lamination['public_unit_per_side'][$values['tamano'] ?? ''] ?? 0);
                $lamination_total = $unit * $sides * $quantity;
            }
            return max(0, round($print * (1 + $surcharge) + $lamination_total));
        }
        if (empty($config['prices_are_final'])) { return 0; }
        $parts = array();
        foreach ($config['fields'] as $field) { if ('checkbox' !== $field['type']) { $parts[] = $field['key'] . '=' . $values[$field['key']]; } }
        $base = (float) ($config['prices'][implode('|', $parts)] ?? 0);
        return $base > 0 ? max(0, round($base * (1 + $surcharge))) : 0;
    }

    public static function add_to_cart() {
        if (function_exists('wc_load_cart') && (!function_exists('WC') || !WC()->cart)) { wc_load_cart(); }
        WC()->cart->get_cart();
        $product_id = absint($_POST['product_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['ge_digital_nonce'] ?? ''));
        if (!$product_id || !wp_verify_nonce($nonce, self::CART_ACTION . '_' . $product_id)) { wp_die('No pudimos validar la configuración.', 403); }
        $product = wc_get_product($product_id);
        $config = $product ? $product->get_meta('_ge_digital_config') : array();
        if (!$product || !is_array($config) || empty($config['fields'])) { wp_die('Producto no disponible.', 404); }
        $selection = self::validate_selection($config, (array) wp_unslash($_POST['ge_digital'] ?? array()));
        if (is_wp_error($selection)) { wc_add_notice($selection->get_error_message(), 'error'); wp_safe_redirect(get_permalink($product_id)); exit; }
        $price = self::calculated_price(self::public_calculator_config($config), $selection);
        if ($price <= 0) { wc_add_notice('Esta combinación está pendiente de cotización. No se agregó un importe sin validar.', 'error'); wp_safe_redirect(get_permalink($product_id)); exit; }
        $cart_data = array('ge_configuration' => implode(' · ', $selection['labels']), 'ge_calculated_price' => round($price * 1.21, 4), 'ge_base_price' => $price, 'ge_unique' => wp_generate_uuid4());
        if (is_user_logged_in() && !empty($_POST['ge_vps_uploads']) && class_exists('GE_WTP_VPS_Storage')) {
            $claims = json_decode(wp_unslash($_POST['ge_vps_uploads']), true);
            $tokens = is_array($claims) ? wp_list_pluck($claims, 'token') : array();
            $uploads = GE_WTP_VPS_Storage::validate_uploaded_claims($tokens, get_current_user_id());
            if (is_wp_error($uploads)) { wc_add_notice($uploads->get_error_message(), 'error'); wp_safe_redirect(get_permalink($product_id)); exit; }
            if ($uploads) { $cart_data['ge_r2_artworks'] = $uploads; }
        }
        self::$adding_configured_product = true;
        $added = WC()->cart->add_to_cart($product_id, 1, 0, array(), $cart_data);
        self::$adding_configured_product = false;
        if (!$added) { wc_add_notice('No pudimos agregar este producto al carrito.', 'error'); wp_safe_redirect(get_permalink($product_id)); exit; }
        wc_add_notice('Producto agregado al carrito.', 'success');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    private static function field($key, $label, $type, $options = array(), $extra = array()) {
        return array_merge(compact('key', 'label', 'type', 'options'), $extra);
    }

    private static function option($value, $label, $when = array(), $extra = array()) {
        return array_merge(compact('value', 'label', 'when'), $extra);
    }

    public static function products() {
        return array(
            'tarjetas-express' => array(
                'sku' => 'ID-EXP-001', 'name' => 'Tarjetas Express 24–48 h', 'group' => 'productos-express',
                'source_date' => '2026-09-13',
                'description' => 'Tarjetas personales de 9 × 5 cm, impresas a todo color en papel ilustración de 300 o 350 g, con laminado mate o brillante.',
                'fields' => array(
                    self::field('tamano', 'Tamaño', 'select', array(self::option('5x9', '5 × 9 cm'))),
                    self::field('cantidad', 'Cantidad', 'select', array_map(function($quantity) { return self::option((string) $quantity, number_format($quantity, 0, ',', '.')); }, array(100, 200, 300, 400, 500))),
                    self::field('papel', 'Papel', 'select', array(self::option('300', 'Ilustración 300 g'), self::option('350', 'Ilustración 350 g'))),
                    self::field('impresion', 'Impresión', 'select', array(self::option('simple', 'Simple faz'), self::option('doble', 'Doble faz'))),
                    self::field('laminado_caras', 'Laminado', 'select', array(
                        self::option('simple', 'Simple faz'),
                        self::option('doble', 'Doble faz', array('impresion' => 'doble')),
                    )),
                    self::field('laminado_acabado', 'Terminación del laminado', 'select', array(self::option('mate', 'Mate'), self::option('brillante', 'Brillante'))),
                ),
                'prices_are_final' => true,
                'prices' => array(
                    'tamano=5x9|cantidad=100|papel=300|impresion=simple|laminado_caras=simple|laminado_acabado=mate' => 16800,
                    'tamano=5x9|cantidad=100|papel=300|impresion=simple|laminado_caras=simple|laminado_acabado=brillante' => 16800,
                    'tamano=5x9|cantidad=100|papel=350|impresion=simple|laminado_caras=simple|laminado_acabado=mate' => 17800,
                    'tamano=5x9|cantidad=100|papel=350|impresion=simple|laminado_caras=simple|laminado_acabado=brillante' => 17800,
                    'tamano=5x9|cantidad=200|papel=300|impresion=simple|laminado_caras=simple|laminado_acabado=mate' => 33000,
                    'tamano=5x9|cantidad=200|papel=300|impresion=simple|laminado_caras=simple|laminado_acabado=brillante' => 33000,
                    'tamano=5x9|cantidad=200|papel=350|impresion=simple|laminado_caras=simple|laminado_acabado=mate' => 35000,
                    'tamano=5x9|cantidad=200|papel=350|impresion=simple|laminado_caras=simple|laminado_acabado=brillante' => 35000,
                    'tamano=5x9|cantidad=300|papel=300|impresion=simple|laminado_caras=simple|laminado_acabado=mate' => 49000,
                    'tamano=5x9|cantidad=300|papel=300|impresion=simple|laminado_caras=simple|laminado_acabado=brillante' => 49000,
                    'tamano=5x9|cantidad=300|papel=350|impresion=simple|laminado_caras=simple|laminado_acabado=mate' => 51000,
                    'tamano=5x9|cantidad=300|papel=350|impresion=simple|laminado_caras=simple|laminado_acabado=brillante' => 51000,
                    'tamano=5x9|cantidad=400|papel=300|impresion=simple|laminado_caras=simple|laminado_acabado=mate' => 64000,
                    'tamano=5x9|cantidad=400|papel=300|impresion=simple|laminado_caras=simple|laminado_acabado=brillante' => 64000,
                    'tamano=5x9|cantidad=400|papel=350|impresion=simple|laminado_caras=simple|laminado_acabado=mate' => 69000,
                    'tamano=5x9|cantidad=400|papel=350|impresion=simple|laminado_caras=simple|laminado_acabado=brillante' => 69000,
                    'tamano=5x9|cantidad=500|papel=300|impresion=simple|laminado_caras=simple|laminado_acabado=mate' => 81000,
                    'tamano=5x9|cantidad=500|papel=300|impresion=simple|laminado_caras=simple|laminado_acabado=brillante' => 81000,
                    'tamano=5x9|cantidad=500|papel=350|impresion=simple|laminado_caras=simple|laminado_acabado=mate' => 87000,
                    'tamano=5x9|cantidad=500|papel=350|impresion=simple|laminado_caras=simple|laminado_acabado=brillante' => 87000,
                    'tamano=5x9|cantidad=100|papel=300|impresion=doble|laminado_caras=simple|laminado_acabado=mate' => 21200,
                    'tamano=5x9|cantidad=100|papel=300|impresion=doble|laminado_caras=simple|laminado_acabado=brillante' => 21200,
                    'tamano=5x9|cantidad=100|papel=300|impresion=doble|laminado_caras=doble|laminado_acabado=mate' => 26000,
                    'tamano=5x9|cantidad=100|papel=300|impresion=doble|laminado_caras=doble|laminado_acabado=brillante' => 26000,
                    'tamano=5x9|cantidad=100|papel=350|impresion=doble|laminado_caras=simple|laminado_acabado=mate' => 22200,
                    'tamano=5x9|cantidad=100|papel=350|impresion=doble|laminado_caras=simple|laminado_acabado=brillante' => 22200,
                    'tamano=5x9|cantidad=100|papel=350|impresion=doble|laminado_caras=doble|laminado_acabado=mate' => 27000,
                    'tamano=5x9|cantidad=100|papel=350|impresion=doble|laminado_caras=doble|laminado_acabado=brillante' => 27000,
                    'tamano=5x9|cantidad=200|papel=300|impresion=doble|laminado_caras=simple|laminado_acabado=mate' => 42000,
                    'tamano=5x9|cantidad=200|papel=300|impresion=doble|laminado_caras=simple|laminado_acabado=brillante' => 42000,
                    'tamano=5x9|cantidad=200|papel=300|impresion=doble|laminado_caras=doble|laminado_acabado=mate' => 50000,
                    'tamano=5x9|cantidad=200|papel=300|impresion=doble|laminado_caras=doble|laminado_acabado=brillante' => 50000,
                    'tamano=5x9|cantidad=200|papel=350|impresion=doble|laminado_caras=simple|laminado_acabado=mate' => 43600,
                    'tamano=5x9|cantidad=200|papel=350|impresion=doble|laminado_caras=simple|laminado_acabado=brillante' => 43600,
                    'tamano=5x9|cantidad=200|papel=350|impresion=doble|laminado_caras=doble|laminado_acabado=mate' => 53000,
                    'tamano=5x9|cantidad=200|papel=350|impresion=doble|laminado_caras=doble|laminado_acabado=brillante' => 53000,
                    'tamano=5x9|cantidad=300|papel=300|impresion=doble|laminado_caras=simple|laminado_acabado=mate' => 61000,
                    'tamano=5x9|cantidad=300|papel=300|impresion=doble|laminado_caras=simple|laminado_acabado=brillante' => 61000,
                    'tamano=5x9|cantidad=300|papel=300|impresion=doble|laminado_caras=doble|laminado_acabado=mate' => 76000,
                    'tamano=5x9|cantidad=300|papel=300|impresion=doble|laminado_caras=doble|laminado_acabado=brillante' => 76000,
                    'tamano=5x9|cantidad=300|papel=350|impresion=doble|laminado_caras=simple|laminado_acabado=mate' => 64000,
                    'tamano=5x9|cantidad=300|papel=350|impresion=doble|laminado_caras=simple|laminado_acabado=brillante' => 64000,
                    'tamano=5x9|cantidad=300|papel=350|impresion=doble|laminado_caras=doble|laminado_acabado=mate' => 79000,
                    'tamano=5x9|cantidad=300|papel=350|impresion=doble|laminado_caras=doble|laminado_acabado=brillante' => 79000,
                    'tamano=5x9|cantidad=400|papel=300|impresion=doble|laminado_caras=simple|laminado_acabado=mate' => 83000,
                    'tamano=5x9|cantidad=400|papel=300|impresion=doble|laminado_caras=simple|laminado_acabado=brillante' => 83000,
                    'tamano=5x9|cantidad=400|papel=300|impresion=doble|laminado_caras=doble|laminado_acabado=mate' => 100000,
                    'tamano=5x9|cantidad=400|papel=300|impresion=doble|laminado_caras=doble|laminado_acabado=brillante' => 100000,
                    'tamano=5x9|cantidad=400|papel=350|impresion=doble|laminado_caras=simple|laminado_acabado=mate' => 87000,
                    'tamano=5x9|cantidad=400|papel=350|impresion=doble|laminado_caras=simple|laminado_acabado=brillante' => 87000,
                    'tamano=5x9|cantidad=400|papel=350|impresion=doble|laminado_caras=doble|laminado_acabado=mate' => 105000,
                    'tamano=5x9|cantidad=400|papel=350|impresion=doble|laminado_caras=doble|laminado_acabado=brillante' => 105000,
                    'tamano=5x9|cantidad=500|papel=300|impresion=doble|laminado_caras=simple|laminado_acabado=mate' => 103000,
                    'tamano=5x9|cantidad=500|papel=300|impresion=doble|laminado_caras=simple|laminado_acabado=brillante' => 103000,
                    'tamano=5x9|cantidad=500|papel=300|impresion=doble|laminado_caras=doble|laminado_acabado=mate' => 124000,
                    'tamano=5x9|cantidad=500|papel=300|impresion=doble|laminado_caras=doble|laminado_acabado=brillante' => 124000,
                    'tamano=5x9|cantidad=500|papel=350|impresion=doble|laminado_caras=simple|laminado_acabado=mate' => 108000,
                    'tamano=5x9|cantidad=500|papel=350|impresion=doble|laminado_caras=simple|laminado_acabado=brillante' => 108000,
                    'tamano=5x9|cantidad=500|papel=350|impresion=doble|laminado_caras=doble|laminado_acabado=mate' => 132000,
                    'tamano=5x9|cantidad=500|papel=350|impresion=doble|laminado_caras=doble|laminado_acabado=brillante' => 132000,
                ),
                'notes' => array('Entrega estimada: 24 a 48 horas.', 'Precios finales de Graph Express sin IVA.', 'El laminado doble faz está disponible cuando la impresión es doble faz.'),
            ),
            'folletos-express' => array(
                'sku' => 'ID-EXP-002', 'name' => 'Folletos Express 24–48 h', 'group' => 'productos-express',
                'source_date' => '2026-09-13',
                'description' => 'Folletos digitales full color para tiradas cortas, con distintos formatos, gramajes e impresión simple o doble faz.',
                'fields' => array(
                    self::field('tamano', 'Tamaño', 'select', array(self::option('a3', 'A3 · 42 × 29,7 cm'), self::option('a4', 'A4 · 21 × 29,7 cm'), self::option('a5', 'A5 · 14,8 × 21 cm'), self::option('a6', 'A6 · 10,5 × 14,8 cm')), array('default' => 'a4')),
                    self::field('cantidad', 'Cantidad', 'select', array_map(function($quantity) { return self::option((string) $quantity, number_format($quantity, 0, ',', '.')); }, array(100, 200, 300, 500, 1000))),
                    self::field('papel', 'Papel', 'select', array(self::option('115', 'Ilustración 115 g'), self::option('150', 'Ilustración 150 g'), self::option('200', 'Ilustración 200 g'), self::option('300', 'Ilustración 300 g')), array('default' => '150')),
                    self::field('impresion', 'Impresión', 'select', array(self::option('frente', 'Frente solo'), self::option('frente-dorso', 'Frente y dorso'))),
                    self::field('pleno', 'Archivo con pleno', 'checkbox', array(), array('surcharge' => 0.30, 'help' => 'Suma 30% al valor de la combinación.')),
                ),
                'prices_are_final' => true,
                'prices' => self::folletos_express_prices(),
                'notes' => array('Entrega estimada: 24 a 48 horas.', 'Precios finales de Graph Express sin IVA.', 'Los archivos con pleno tienen un 30% adicional.'),
            ),
            'stickers-en-papel' => array(
                'sku' => 'ID-STI-001', 'name' => 'Stickers en papel', 'group' => 'stickers-autoadhesivos',
                'source_date' => '2026-09-13',
                'description' => 'Planchas de stickers sobre papel autoadhesivo con medio corte, listas para entregar con el troquel indicado.',
                'fields' => array(
                    self::field('tamano', 'Área de impresión', 'select', array(self::option('290x440', '29 × 44 cm'))),
                    self::field('cantidad', 'Cantidad de planchas', 'number', array(), array('min' => 5, 'max' => 10000, 'step' => 1, 'default' => 5)),
                    self::field('corte', 'Tamaño de corte', 'select', array(self::option('3x3', 'Hasta 3 × 3 cm'), self::option('5x5', 'Hasta 5 × 5 cm'), self::option('10x10', 'Hasta 10 × 10 cm'), self::option('290x440', 'Hasta 29 × 44 cm'))),
                    self::field('complejidad', 'Archivo con pleno o alta complejidad', 'checkbox', array(), array('surcharge' => 0.30, 'help' => 'Suma 30% al valor de la combinación.')),
                ),
                'pricing_model' => array(
                    'type' => 'tiered-unit',
                    'quantity_key' => 'cantidad',
                    'unit_label' => 'plancha',
                    'dimension_keys' => array('tamano', 'corte'),
                    'breaks' => array(5, 6, 26, 51, 101, 301, 501, 1001),
                    'supplier_unit_rates' => array(
                        '290x440|3x3'     => array(1590, 1540, 1430, 1510, 1330, 1270, 1200, 1140),
                        '290x440|5x5'     => array(1440, 1390, 1280, 1230, 1180, 1120, 1050, 1000),
                        '290x440|10x10'   => array(1340, 1290, 1180, 1130, 1080, 1020, 950, 900),
                        '290x440|290x440' => array(1250, 1200, 1090, 1040, 990, 940, 890, 850),
                    ),
                    'commercial_markups' => array(
                        array('min' => 5, 'max' => 10000, 'rate' => 0.50),
                    ),
                ),
                'notes' => array(
                    'Pedido mínimo: 5 planchas de 29 × 44 cm.',
                    'Escala automática por cantidad: 5; 6–25; 26–50; 51–100; 101–300; 301–500; 501–1.000; 1.001–10.000 planchas.',
                    'Material: papel autoadhesivo con medio corte.',
                    'Terminación disponible: medio corte en plancha. El corte completo se ofrece únicamente en stickers de vinilo.',
                    'El archivo debe venir armado con el troquel.',
                    'Precios finales de Graph Express sin IVA.',
                    'Archivos con pleno o alta complejidad: 30% adicional.',
                ),
            ),
            'bajadas-digitales-color' => array(
                'sku' => 'ID-BAJ-001', 'name' => 'Bajadas digitales color', 'group' => 'impresion-por-pliego',
                'description' => 'Impresión digital color por pliego para piezas especiales, etiquetas, tapas, invitaciones y pequeñas producciones.',
                'fields' => array(
                    self::field('tamano', 'Tamaño', 'select', array(self::option('a3-plus', 'A3+ · 32 × 47 cm'), self::option('a4-plus', 'A4+ · 22 × 31 cm'))),
                    self::field('cantidad', 'Cantidad de pliegos', 'number', array(), array('min' => 1, 'max' => 10000, 'step' => 1, 'default' => 1)),
                    self::field('papel', 'Papel', 'select', array(
                        self::option('ilustracion-115', 'Ilustración 115 g'),
                        self::option('ilustracion-150', 'Ilustración 150 g'),
                        self::option('ilustracion-300', 'Ilustración 300 g'),
                        self::option('obra-80', 'Obra 80 g'),
                        self::option('autoadhesivo', 'Autoadhesivo'),
                    ), array('default' => 'ilustracion-150')),
                    self::field('impresion', 'Impresión', 'select', array(
                        self::option('frente', 'Frente'),
                        self::option('frente-dorso', 'Frente y dorso', array('papel' => array('ilustracion-115', 'ilustracion-150', 'ilustracion-300', 'obra-80'))),
                    )),
                    self::field('laminado', 'Laminado', 'select', array(
                        self::option('sin-laminar', 'Sin laminado'),
                        self::option('frente', 'Frente', array('papel' => array('ilustracion-150', 'ilustracion-300', 'autoadhesivo'))),
                        self::option('frente-dorso', 'Frente y dorso', array('papel' => array('ilustracion-150', 'ilustracion-300'))),
                    )),
                    self::field('terminacion', 'Terminación', 'select', array(
                        self::option('sin-terminacion', 'Sin terminación'),
                        self::option('abrochado', 'Abrochado', array(), array('quote_required' => true)),
                        self::option('anillado', 'Anillado', array(), array('quote_required' => true)),
                    )),
                    self::field('pleno', 'Archivo con pleno', 'checkbox', array(), array('surcharge' => 0.30, 'help' => 'Suma 30% al valor de la combinación.')),
                ),
                'pricing_model' => array(
                    'type' => 'tiered-unit',
                    'quantity_key' => 'cantidad',
                    'dimension_keys' => array('tamano', 'papel', 'impresion'),
                    'breaks' => array(1, 2, 26, 51, 101, 301, 501, 1001),
                    'supplier_unit_rates' => self::digital_color_rates(),
                    'commercial_markups' => array(
                        array('min' => 1, 'max' => 50, 'rate' => 0.40),
                        array('min' => 51, 'max' => 300, 'rate' => 0.30),
                        array('min' => 301, 'max' => 10000, 'rate' => 0.20),
                    ),
                    'lamination' => array(
                        'field' => 'laminado',
                        'papers' => array('ilustracion-150', 'ilustracion-300', 'autoadhesivo'),
                        'supplier_unit_per_side' => array('a3-plus' => 240, 'a4-plus' => 120),
                        'markup' => 0.50,
                        'sides' => array('sin-laminar' => 0, 'frente' => 1, 'frente-dorso' => 2),
                    ),
                ),
                'notes' => array(
                    'Precios finales de Graph Express sin IVA.',
                    'Escala comercial: +40% hasta 50 pliegos, +30% de 51 a 300 y +20% desde 301 pliegos.',
                    'Laminado disponible en Ilustración 150 g, Ilustración 300 g y Autoadhesivo; no disponible en Obra 80 g ni Ilustración 115 g.',
                    'Abrochado y anillado disponibles a cotizar según cantidad de juegos y páginas.',
                    'Archivos con pleno: 30% adicional sobre la impresión.',
                ),
            ),
            'bajadas-digitales-blanco-negro' => array(
                'sku' => 'ID-BAJ-002', 'name' => 'Bajadas digitales blanco y negro', 'group' => 'impresion-por-pliego',
                'description' => 'Impresión digital blanco y negro por pliego para interiores, formularios, apuntes, tapas y pequeñas producciones.',
                'fields' => array(
                    self::field('tamano', 'Tamaño', 'select', array(self::option('a3-plus', 'A3+ · 32 × 47 cm'), self::option('a4-plus', 'A4+ · 22 × 31 cm'))),
                    self::field('cantidad', 'Cantidad de pliegos', 'number', array(), array('min' => 1, 'max' => 10000, 'step' => 1, 'default' => 1)),
                    self::field('papel', 'Papel', 'select', array(
                        self::option('ilustracion-115', 'Ilustración 115 g'),
                        self::option('ilustracion-150', 'Ilustración 150 g'),
                        self::option('ilustracion-300', 'Ilustración 300 g'),
                        self::option('obra-80', 'Obra 80 g'),
                        self::option('autoadhesivo', 'Autoadhesivo'),
                    ), array('default' => 'obra-80')),
                    self::field('impresion', 'Impresión', 'select', array(
                        self::option('frente', 'Frente'),
                        self::option('frente-dorso', 'Frente y dorso', array('papel' => array('ilustracion-115', 'ilustracion-150', 'ilustracion-300', 'obra-80'))),
                    )),
                    self::field('laminado', 'Laminado', 'select', array(
                        self::option('sin-laminar', 'Sin laminado'),
                        self::option('frente', 'Frente', array('papel' => array('ilustracion-150', 'ilustracion-300', 'autoadhesivo'))),
                        self::option('frente-dorso', 'Frente y dorso', array('papel' => array('ilustracion-150', 'ilustracion-300'))),
                    )),
                    self::field('terminacion', 'Terminación', 'select', array(
                        self::option('sin-terminacion', 'Sin terminación'),
                        self::option('abrochado', 'Abrochado', array(), array('quote_required' => true)),
                        self::option('anillado', 'Anillado', array(), array('quote_required' => true)),
                    )),
                    self::field('pleno', 'Archivo con pleno', 'checkbox', array(), array('surcharge' => 0.20, 'help' => 'Suma 20% al valor de la impresión.')),
                ),
                'pricing_model' => array(
                    'type' => 'tiered-unit',
                    'quantity_key' => 'cantidad',
                    'dimension_keys' => array('tamano', 'papel', 'impresion'),
                    'breaks' => array(1, 2, 26, 51, 101, 301, 501, 1001),
                    'supplier_unit_rates' => self::digital_bw_rates(),
                    'commercial_markups' => array(
                        array('min' => 1, 'max' => 50, 'rate' => 0.40),
                        array('min' => 51, 'max' => 300, 'rate' => 0.30),
                        array('min' => 301, 'max' => 10000, 'rate' => 0.20),
                    ),
                    'lamination' => array(
                        'field' => 'laminado',
                        'papers' => array('ilustracion-150', 'ilustracion-300', 'autoadhesivo'),
                        'supplier_unit_per_side' => array('a3-plus' => 240, 'a4-plus' => 120),
                        'markup' => 0.50,
                        'sides' => array('sin-laminar' => 0, 'frente' => 1, 'frente-dorso' => 2),
                    ),
                ),
                'notes' => array(
                    'Precios finales de Graph Express sin IVA.',
                    'Escala comercial: +40% hasta 50 pliegos, +30% de 51 a 300 y +20% desde 301 pliegos.',
                    'Laminado disponible en Ilustración 150 g, Ilustración 300 g y Autoadhesivo; no disponible en Obra 80 g ni Ilustración 115 g.',
                    'Abrochado y anillado disponibles a cotizar según cantidad de juegos y páginas.',
                    'Archivos con pleno: 20% adicional sobre la impresión.',
                ),
            ),
            'sobres-papel-impresion-digital' => array(
                'sku' => 'ID-SOB-001', 'name' => 'Sobres de papel · Impresión digital', 'group' => 'sobres-digitales',
                'source_name' => 'Relevamiento de mercado: MercadoLibre, OX Artística, Diseñobar y Gráfica Croquis',
                'source_date' => '2026-09-14',
                'source_files' => array(
                    'https://articulo.mercadolibre.com.ar/MLA-1790409509-pack-100-sobres-bolsa-a4-negro-una-cara-logo-personalizado-_JM',
                    'https://www.oxartistica.com.ar/productos/100-sobres-bolsa-a4-impresos-en-negro-simple-faz/',
                    'https://eshop.diseniobar.com.ar/productos/sobres-bolsa-a4-impresos-x-100-u/',
                    'https://graficacroquis.com.ar/productos/sobres/',
                ),
                'description' => 'Sobres de papel de 90 g personalizados mediante impresión digital color o blanco y negro, disponibles en formatos comerciales y sobres bolsa.',
                'fields' => array(
                    self::field('formato', 'Formato', 'select', array(
                        self::option('americano-114x162', 'Americano · 114 × 162 mm'),
                        self::option('oficio-ingles-12x235', 'Oficio inglés · 12 × 23,5 cm'),
                        self::option('bolsa-a5', 'Sobre bolsa A5'),
                        self::option('bolsa-a4', 'Sobre bolsa A4'),
                        self::option('bolsa-a3', 'Sobre bolsa A3'),
                    )),
                    self::field('cantidad', 'Cantidad', 'select', array(
                        self::option('100', '100 unidades'),
                        self::option('250', '250 unidades'),
                        self::option('500', '500 unidades'),
                    )),
                    self::field('papel', 'Papel', 'select', array(self::option('90', 'Papel 90 g'))),
                    self::field('color_papel', 'Color del sobre', 'select', array(
                        self::option('blanco', 'Blanco'),
                        self::option('manila', 'Manila', array('formato' => array('bolsa-a5', 'bolsa-a4', 'bolsa-a3'))),
                    )),
                    self::field('impresion', 'Impresión', 'select', array(
                        self::option('blanco-negro', 'Blanco y negro'),
                        self::option('color', 'Color'),
                    )),
                ),
                'prices_are_final' => true,
                'price_status' => 'estimated',
                'prices' => self::envelope_prices(),
                'offset_quote' => array(
                    'title' => '¿Necesitás 2.000 sobres o más?',
                    'text' => 'Para tiradas grandes preparamos una cotización especial de producción offset.',
                    'button' => 'Cotizar sobres en offset',
                    'message' => 'Hola Graph Express, quiero cotizar una producción offset de sobres de 2.000 unidades o más.',
                ),
                'notes' => array(
                    'Papel de 90 g.',
                    'Impresión digital color o blanco y negro.',
                    'Sobres blancos disponibles en todos los formatos.',
                    'Sobres Manila disponibles únicamente en formatos bolsa A5, A4 y A3.',
                    'Cantidades digitales: 100, 250 y 500 unidades.',
                    'Producción offset disponible a partir de 2.000 unidades.',
                    'Precios estimados sin IVA, relevados y redondeados el 14/09/2026. Se confirman al validar disponibilidad, archivo y plazo.',
                    'Entrega digital estimada: 5 a 7 días hábiles desde la aprobación del archivo; confirmar al pedir.',
                ),
            ),
        );
    }

    /**
     * Lista Druck relevada el 13/09/2026. Cada fila sigue este orden:
     * 115 frente/dorso, 150 frente/dorso, 200 frente/dorso y 300 frente/dorso.
     * El precio comercial suma 30% y se redondea a la centena más cercana.
     */
    private static function folletos_express_prices() {
        $supplier = array(
            'a3' => array(
                100  => array(52600, 81000, 54600, 82000, 57000, 86000, 63000, 92000),
                200  => array(99200, 155000, 103000, 160000, 109000, 165000, 120000, 177000),
                300  => array(130800, 212000, 136000, 218000, 145000, 227000, 163000, 244000),
                500  => array(199400, 331000, 209000, 341000, 224000, 356000, 253000, 385000),
                1000 => array(377000, 632000, 397000, 652000, 426000, 681000, 484000, 739000),
            ),
            'a4' => array(
                100  => array(31000, 46200, 32000, 47200, 33500, 48770, 36500, 51700),
                200  => array(52600, 81200, 54600, 83200, 57600, 86200, 63500, 92000),
                300  => array(76000, 118500, 79000, 121600, 83435, 126000, 92200, 134800),
                500  => array(124600, 195200, 129700, 200900, 137000, 207600, 151000, 222200),
                1000 => array(202400, 334500, 212600, 344700, 227100, 359200, 256200, 388300),
            ),
            'a5' => array(
                100  => array(19400, 27600, 19900, 28100, 20700, 29000, 22200, 30500),
                200  => array(31000, 46200, 32000, 47200, 33500, 48700, 36500, 51700),
                300  => array(43500, 66000, 45000, 67500, 47300, 69800, 51700, 74200),
                500  => array(66200, 101800, 68800, 104000, 72500, 108100, 79800, 115400),
                1000 => array(127000, 198200, 132700, 203300, 140000, 210600, 154600, 225260),
            ),
            'a6' => array(
                100  => array(13700, 19730, 14660, 20105, 14585, 20615, 15620, 21665),
                200  => array(20288, 28828, 20988, 29528, 21940, 30508, 23890, 32440),
                300  => array(27388, 39891, 28411, 40916, 29805, 42351, 32675, 45180),
                500  => array(40858, 60130, 42442, 61780, 44775, 64024, 49372, 68644),
                1000 => array(72400, 108000, 75520, 111920, 80070, 116440, 89940, 125440),
            ),
        );
        $papers = array('115', '150', '200', '300');
        $impressions = array('frente', 'frente-dorso');
        $prices = array();

        foreach ($supplier as $size => $quantities) {
            foreach ($quantities as $quantity => $row) {
                foreach ($papers as $paper_index => $paper) {
                    foreach ($impressions as $impression_index => $impression) {
                        $supplier_price = $row[($paper_index * 2) + $impression_index];
                        $key = 'tamano=' . $size . '|cantidad=' . $quantity . '|papel=' . $paper . '|impresion=' . $impression;
                        $prices[$key] = (int) (round(($supplier_price * 1.30) / 100) * 100);
                    }
                }
            }
        }

        return $prices;
    }

    /**
     * Estimación comercial relevada el 14/09/2026.
     * Ancla equivalente A4 blanco 90 g, impresión simple faz:
     * negro $40.000-$59.000 / 100 y color $47.999-$60.000 / 100.
     * Los demás formatos, colores de papel y escalas son estimaciones
     * proporcionales, redondeadas y sujetas a validación operativa.
     */
    private static function envelope_prices() {
        $matrix = array(
            'americano-114x162' => array(
                'blanco' => array(100 => array(30000, 35000), 250 => array(68000, 78000), 500 => array(125000, 145000)),
            ),
            'oficio-ingles-12x235' => array(
                'blanco' => array(100 => array(35000, 40000), 250 => array(80000, 90000), 500 => array(145000, 165000)),
            ),
            'bolsa-a5' => array(
                'blanco' => array(100 => array(45000, 50000), 250 => array(100000, 113000), 500 => array(185000, 205000)),
                'manila' => array(100 => array(50000, 55000), 250 => array(110000, 125000), 500 => array(205000, 225000)),
            ),
            'bolsa-a4' => array(
                'blanco' => array(100 => array(60000, 65000), 250 => array(135000, 145000), 500 => array(250000, 270000)),
                'manila' => array(100 => array(65000, 72000), 250 => array(148000, 160000), 500 => array(275000, 300000)),
            ),
            'bolsa-a3' => array(
                'blanco' => array(100 => array(85000, 95000), 250 => array(195000, 215000), 500 => array(360000, 395000)),
                'manila' => array(100 => array(95000, 105000), 250 => array(215000, 238000), 500 => array(400000, 438000)),
            ),
        );
        $prices = array();
        foreach ($matrix as $format => $paper_colors) {
            foreach ($paper_colors as $paper_color => $quantities) {
                foreach ($quantities as $quantity => $values) {
                    foreach (array('blanco-negro', 'color') as $index => $print) {
                        $key = 'formato=' . $format . '|cantidad=' . $quantity . '|papel=90|color_papel=' . $paper_color . '|impresion=' . $print;
                        $prices[$key] = $values[$index];
                    }
                }
            }
        }
        return $prices;
    }

    private static function digital_color_rates() {
        return array(
            'a3-plus|ilustracion-115|frente'       => array(1430, 720, 680, 630, 590, 530, 490, 470),
            'a3-plus|ilustracion-115|frente-dorso' => array(2420, 1230, 1060, 1000, 950, 870, 820, 790),
            'a4-plus|ilustracion-115|frente'       => array(790, 400, 370, 350, 330, 290, 270, 260),
            'a4-plus|ilustracion-115|frente-dorso' => array(1340, 680, 590, 550, 520, 480, 450, 440),
            'a3-plus|ilustracion-150|frente'       => array(1470, 750, 700, 660, 620, 560, 510, 490),
            'a3-plus|ilustracion-150|frente-dorso' => array(2500, 1250, 1090, 1030, 970, 900, 840, 810),
            'a4-plus|ilustracion-150|frente'       => array(810, 410, 390, 370, 340, 310, 280, 270),
            'a4-plus|ilustracion-150|frente-dorso' => array(1370, 690, 600, 570, 540, 500, 470, 450),
            'a3-plus|ilustracion-300|frente'       => array(1700, 850, 810, 770, 730, 670, 620, 600),
            'a3-plus|ilustracion-300|frente-dorso' => array(2710, 1360, 1200, 1140, 1080, 1010, 950, 920),
            'a4-plus|ilustracion-300|frente'       => array(940, 470, 450, 430, 400, 370, 350, 330),
            'a4-plus|ilustracion-300|frente-dorso' => array(1490, 750, 660, 630, 600, 560, 530, 510),
            'a3-plus|obra-80|frente'               => array(1390, 700, 660, 620, 580, 510, 470, 450),
            'a3-plus|obra-80|frente-dorso'         => array(2400, 1210, 1040, 990, 930, 850, 800, 770),
            'a4-plus|obra-80|frente'               => array(760, 390, 370, 340, 320, 280, 260, 250),
            'a4-plus|obra-80|frente-dorso'         => array(1320, 670, 580, 540, 510, 470, 440, 420),
            'a3-plus|autoadhesivo|frente'           => array(2401, 1200, 1160, 1120, 1080, 1010, 970, 950),
            'a4-plus|autoadhesivo|frente'           => array(1321, 660, 640, 620, 590, 560, 540, 520),
        );
    }

    private static function digital_bw_rates() {
        return array(
            'a3-plus|ilustracion-115|frente'       => array(320, 190, 180, 160, 160, 160, 160, 160),
            'a3-plus|ilustracion-115|frente-dorso' => array(520, 300, 270, 250, 250, 250, 250, 240),
            'a4-plus|ilustracion-115|frente'       => array(180, 110, 100, 90, 90, 90, 90, 90),
            'a4-plus|ilustracion-115|frente-dorso' => array(290, 170, 160, 150, 140, 140, 140, 140),
            'a3-plus|ilustracion-150|frente'       => array(340, 210, 200, 180, 180, 180, 180, 180),
            'a3-plus|ilustracion-150|frente-dorso' => array(550, 330, 290, 270, 270, 270, 270, 260),
            'a4-plus|ilustracion-150|frente'       => array(190, 120, 110, 110, 110, 100, 100, 100),
            'a4-plus|ilustracion-150|frente-dorso' => array(300, 180, 170, 160, 160, 150, 150, 150),
            'a3-plus|ilustracion-300|frente'       => array(470, 330, 320, 300, 300, 300, 290, 290),
            'a3-plus|ilustracion-300|frente-dorso' => array(700, 450, 430, 400, 400, 400, 400, 400),
            'a4-plus|ilustracion-300|frente'       => array(260, 190, 180, 170, 170, 170, 170, 170),
            'a4-plus|ilustracion-300|frente-dorso' => array(390, 260, 240, 230, 230, 220, 220, 220),
            'a3-plus|obra-80|frente'               => array(290, 170, 160, 150, 150, 150, 150, 150),
            'a3-plus|obra-80|frente-dorso'         => array(500, 280, 260, 240, 240, 230, 230, 230),
            'a4-plus|obra-80|frente'               => array(170, 100, 90, 90, 90, 80, 80, 80),
            'a4-plus|obra-80|frente-dorso'         => array(280, 160, 150, 140, 140, 130, 130, 130),
            'a3-plus|autoadhesivo|frente'           => array(790, 650, 630, 620, 620, 620, 620, 620),
            'a4-plus|autoadhesivo|frente'           => array(440, 360, 350, 350, 350, 350, 340, 340),
        );
    }

    public static function sync() {
        if (! class_exists('WC_Product_Simple')) {
            return new WP_Error('woocommerce_required', 'WooCommerce debe estar activo.');
        }
        $categories = self::sync_categories();
        if (is_wp_error($categories)) {
            return $categories;
        }
        $created = 0; $updated = 0; $position = 200;
        foreach (self::products() as $key => $data) {
            $ids = get_posts(array('post_type' => 'product', 'post_status' => array('publish', 'draft', 'private'), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_ge_public_catalog_key', 'meta_value' => 'digital-' . $key));
            $is_new = empty($ids);
            $product = $is_new ? new WC_Product_Simple() : wc_get_product($ids[0]);
            if (! $product) { continue; }
            $product->set_name($data['name']);
            $product->set_slug($key);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_description($data['description']);
            $product->set_short_description($data['description']);
            $product->set_sku($data['sku']);
            $product->set_regular_price('');
            $product->set_category_ids(array($categories['imprenta-digital'], $categories[$data['group']]));
            $product->set_menu_order($position++);
            $product->set_reviews_allowed(false);
            $product_id = $product->save();
            update_post_meta($product_id, '_ge_public_catalog_key', 'digital-' . $key);
            update_post_meta($product_id, '_ge_quote_only', 'yes');
            update_post_meta($product_id, '_ge_show_reference_price', 'no');
            update_post_meta($product_id, '_ge_digital_config', $data);
            update_post_meta($product_id, '_ge_supplier_source', isset($data['source_name']) ? $data['source_name'] : self::SOURCE_NAME);
            update_post_meta($product_id, '_ge_supplier_source_date', isset($data['source_date']) ? $data['source_date'] : self::SOURCE_DATE);
            update_post_meta($product_id, '_ge_supplier_source_files', isset($data['source_files']) ? $data['source_files'] : array());
            if ('bajadas-digitales-blanco-negro' === $key) { self::assign_existing_reference_image($product_id, 'bajadas-digitales-blanco-negro-v2.png'); }
            $is_new ? $created++ : $updated++;
        }
        return array('created' => $created, 'updated' => $updated, 'total' => $created + $updated);
    }

    private static function assign_existing_reference_image($product_id, $asset) {
        $image_id = (int) get_post_thumbnail_id($product_id);
        $gallery = (string) get_post_meta($product_id, '_product_image_gallery', true);
        if ($image_id && $gallery) { return; }
        if (! $image_id) {
            $images = get_posts(array(
                'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids',
                'meta_key' => '_ge_product_reference_asset', 'meta_value' => $asset,
            ));
            $image_id = $images ? (int) $images[0] : 0;
        }
        if (! $image_id) { return; }
        if (! get_post_thumbnail_id($product_id)) { set_post_thumbnail($product_id, $image_id); }
        if (! $gallery) { update_post_meta($product_id, '_product_image_gallery', (string) $image_id); }
    }

    private static function sync_categories() {
        $structure = array(
            'imprenta-digital' => array('name' => 'Imprenta digital', 'parent' => 0),
            'productos-express' => array('name' => 'Productos Express', 'parent' => 'imprenta-digital', 'description' => 'Tarjetas y folletos digitales con producción rápida.'),
            'stickers-autoadhesivos' => array('name' => 'Stickers y autoadhesivos', 'parent' => 'imprenta-digital', 'description' => 'Stickers, etiquetas y piezas autoadhesivas con cortes personalizados.'),
            'impresion-por-pliego' => array('name' => 'Impresión por pliego', 'parent' => 'imprenta-digital', 'description' => 'Bajadas digitales color en distintos papeles y gramajes.'),
            'sobres-digitales' => array('name' => 'Sobres', 'parent' => 'imprenta-digital', 'description' => 'Sobres de papel personalizados en impresión digital color o blanco y negro.'),
        );
        $ids = array();
        foreach ($structure as $slug => $definition) {
            $parent_id = is_string($definition['parent']) ? $ids[$definition['parent']] : 0;
            $term = get_term_by('slug', $slug, 'product_cat');
            $args = array('slug' => $slug, 'parent' => $parent_id, 'description' => isset($definition['description']) ? $definition['description'] : '');
            if (! $term) {
                $result = wp_insert_term($definition['name'], 'product_cat', $args);
                if (is_wp_error($result)) { return $result; }
                $ids[$slug] = (int) $result['term_id'];
            } else {
                wp_update_term($term->term_id, 'product_cat', $args);
                $ids[$slug] = (int) $term->term_id;
            }
        }
        return $ids;
    }

    public static function enqueue_assets() {
        if (! function_exists('is_product') || ! is_product()) { return; }
        global $post;
        if (! $post || ! get_post_meta($post->ID, '_ge_digital_config', true)) { return; }
        $script_file = GE_WTP_PLUGIN_DIR . 'assets/js/digital-calculator.js';
        wp_enqueue_style('ge-digital-calculator', GE_WTP_PLUGIN_URL . 'assets/css/digital-calculator.css', array(), GE_WTP_VERSION);
        wp_enqueue_script('ge-digital-calculator', GE_WTP_PLUGIN_URL . 'assets/js/digital-calculator.js', array(), file_exists($script_file) ? (string) filemtime($script_file) : GE_WTP_VERSION, true);
        if (is_user_logged_in() && class_exists('GE_WTP_VPS_Storage') && GE_WTP_VPS_Storage::ready()) {
            $limits = GE_WTP_VPS_Storage::limits();
            wp_localize_script('ge-digital-calculator', 'geDigitalUpload', array('ajaxUrl' => admin_url('admin-ajax.php'), 'action' => GE_WTP_VPS_Storage::AJAX_ACTION, 'nonce' => wp_create_nonce(GE_WTP_VPS_Storage::AJAX_ACTION), 'maxFiles' => (int) $limits['max_files'], 'maxFileBytes' => (int) $limits['max_file_bytes'], 'maxTotalBytes' => (int) $limits['max_total_bytes']));
        }
    }

    private static function public_calculator_config($config) {
        if (!empty($config['notes']) && is_array($config['notes'])) {
            $config['notes'] = array_values(array_filter($config['notes'], function ($note) {
                return !preg_match('/Escala comercial|margen comercial.*%/iu', (string) $note);
            }));
        }
        if (empty($config['pricing_model']) || 'tiered-unit' !== ($config['pricing_model']['type'] ?? '')) {
            return $config;
        }
        $model = $config['pricing_model'];
        $public_rates = array();
        foreach (($model['supplier_unit_rates'] ?? array()) as $key => $rates) {
            $public_rates[$key] = array();
            foreach ($rates as $index => $supplier_rate) {
                $quantity = (int) ($model['breaks'][$index] ?? 1);
                $markup = 0;
                foreach (($model['commercial_markups'] ?? array()) as $range) {
                    if ($quantity >= (int) $range['min'] && $quantity <= (int) $range['max']) {
                        $markup = (float) $range['rate'];
                        break;
                    }
                }
                $public_rates[$key][] = (float) $supplier_rate * (1 + $markup);
            }
        }
        $model['public_unit_rates'] = $public_rates;
        unset($model['supplier_unit_rates'], $model['commercial_markups']);
        if (!empty($model['lamination']) && is_array($model['lamination'])) {
            $lamination = $model['lamination'];
            $public_lamination = array();
            foreach (($lamination['supplier_unit_per_side'] ?? array()) as $size => $supplier_rate) {
                $public_lamination[$size] = (float) $supplier_rate * (1 + (float) ($lamination['markup'] ?? 0));
            }
            $lamination['public_unit_per_side'] = $public_lamination;
            unset($lamination['supplier_unit_per_side'], $lamination['markup']);
            $model['lamination'] = $lamination;
        }
        $config['pricing_model'] = $model;
        return $config;
    }

    public static function render_calculator() {
        global $product;
        if (! $product) { return; }
        if (class_exists('GE_WTP_Storefront') && GE_WTP_Storefront::config($product->get_id())) { return; }
        $config = $product->get_meta('_ge_digital_config');
        if (! is_array($config) || empty($config['fields'])) { return; }
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        remove_action('woocommerce_single_product_summary', 'graphexpress_quote_only_product_cta', 31);
        $config = self::public_calculator_config($config);
        $has_final_pricing = ! empty($config['pricing_model']) || ! empty($config['prices_are_final']);
        $is_quote_only = ! empty($config['quote_only']);
        $is_estimated = 'estimated' === ($config['price_status'] ?? '');
        ?>
        <form class="ge-digital-calculator" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ge-digital-calculator data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::CART_ACTION); ?>">
            <input type="hidden" name="product_id" value="<?php echo esc_attr($product->get_id()); ?>">
            <?php wp_nonce_field(self::CART_ACTION . '_' . $product->get_id(), 'ge_digital_nonce'); ?>
            <div class="ge-digital-heading"><span>Configurador digital</span><h2>Armá tu producto</h2><p><?php echo esc_html($is_quote_only ? 'Elegí las opciones y envianos la configuración para preparar el presupuesto.' : ($is_estimated ? 'Elegí cada variable y conocé el precio estimado sin IVA; lo confirmamos al validar archivo, disponibilidad y plazo.' : ($has_final_pricing ? 'Elegí cada variable y conocé el precio final sin IVA.' : 'Elegí cada variable. Los valores cargados son provisorios hasta actualizar la lista comercial.'))); ?></p></div>
            <div class="ge-digital-fields">
                <?php foreach ($config['fields'] as $field) : $default = isset($field['default']) ? $field['default'] : ''; ?>
                    <label class="ge-digital-field ge-field-<?php echo esc_attr($field['type']); ?>">
                        <?php if ('checkbox' === $field['type']) : ?>
                            <input type="checkbox" name="ge_digital[<?php echo esc_attr($field['key']); ?>]" value="1" data-ge-field="<?php echo esc_attr($field['key']); ?>" data-surcharge="<?php echo esc_attr(isset($field['surcharge']) ? $field['surcharge'] : 0); ?>">
                            <span><strong><?php echo esc_html($field['label']); ?></strong><?php if (! empty($field['help'])) : ?><small><?php echo esc_html($field['help']); ?></small><?php endif; ?></span>
                        <?php elseif ('number' === $field['type']) : ?>
                            <span><?php echo esc_html($field['label']); ?></span><input type="number" name="ge_digital[<?php echo esc_attr($field['key']); ?>]" data-ge-field="<?php echo esc_attr($field['key']); ?>" min="<?php echo esc_attr($field['min']); ?>" max="<?php echo esc_attr($field['max']); ?>" step="<?php echo esc_attr($field['step']); ?>" value="<?php echo esc_attr($field['default']); ?>">
                        <?php else : ?>
                            <span><?php echo esc_html($field['label']); ?></span><select name="ge_digital[<?php echo esc_attr($field['key']); ?>]" data-ge-field="<?php echo esc_attr($field['key']); ?>">
                                <?php foreach ($field['options'] as $index => $option) : ?>
                                    <option value="<?php echo esc_attr($option['value']); ?>" data-when="<?php echo esc_attr(wp_json_encode($option['when'])); ?>" data-quote-required="<?php echo ! empty($option['quote_required']) ? 'yes' : 'no'; ?>" <?php selected($default ? $default : (0 === $index ? $option['value'] : ''), $option['value']); ?>><?php echo esc_html($option['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if (! empty($config['offset_quote'])) : $offset = $config['offset_quote']; $offset_url = 'https://wa.me/5491151393899?text=' . rawurlencode($offset['message']); ?>
                <aside class="ge-digital-offset-callout">
                    <div><span>Producción offset</span><strong><?php echo esc_html($offset['title']); ?></strong><p><?php echo esc_html($offset['text']); ?></p></div>
                    <a href="<?php echo esc_url($offset_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($offset['button']); ?></a>
                </aside>
            <?php endif; ?>
            <div class="ge-digital-upload"><span>Archivo para imprimir</span>
                <?php if (!is_user_logged_in()) : ?><small><a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">Ingresá o creá una cuenta</a> para subir originales privados. También podés agregarlos luego desde el pedido.</small>
                <?php elseif (!class_exists('GE_WTP_VPS_Storage') || !GE_WTP_VPS_Storage::ready()) : ?><small>La carga privada no está disponible ahora. Podés adjuntar el original desde Mi Cuenta una vez creado el pedido.</small>
                <?php else : ?><input type="file" data-ge-digital-files accept=".pdf,.ai,.eps,.psd,.tif,.tiff,.svg,.cdr,.zip,.jpg,.jpeg,.png" multiple><input type="hidden" name="ge_vps_uploads" value="[]" data-ge-digital-claims><button type="button" data-ge-digital-upload>Subir archivos de forma segura</button><progress data-ge-digital-progress max="100" value="0" hidden></progress><small data-ge-digital-status>Los archivos quedarán vinculados al producto y al pedido.</small><?php endif; ?>
            </div>
            <div class="ge-digital-total"><div><small data-ge-price-state><?php echo esc_html($is_quote_only ? 'Cotización personalizada' : ($is_estimated ? 'Precio estimado sin IVA' : ($has_final_pricing ? 'Precio final sin IVA' : 'Referencia provisoria'))); ?></small><strong data-ge-total>Calculando…</strong><span data-ge-unit></span></div><div><small>IVA 21%</small><strong data-ge-tax>—</strong></div></div>
            <p class="ge-digital-warning" data-ge-warning></p>
            <button class="ge-digital-submit" data-ge-submit type="submit" disabled>Calculando precio…</button>
            <a class="ge-digital-help" data-ge-whatsapp target="_blank" rel="noopener" href="#">Consultar esta configuración por WhatsApp ↗</a>
            <?php if (! empty($config['notes'])) : ?><ul class="ge-digital-notes"><?php foreach ($config['notes'] as $note) : ?><li><?php echo esc_html($note); ?></li><?php endforeach; ?></ul><?php endif; ?>
        </form>
        <?php
    }
}

GE_WTP_Digital_Catalog::init();
