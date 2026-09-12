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

    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'render_calculator'), 29);
    }

    private static function field($key, $label, $type, $options = array(), $extra = array()) {
        return array_merge(compact('key', 'label', 'type', 'options'), $extra);
    }

    private static function option($value, $label, $when = array()) {
        return compact('value', 'label', 'when');
    }

    public static function products() {
        return array(
            'tarjetas-express' => array(
                'sku' => 'ID-EXP-001', 'name' => 'Tarjetas Express 24–48 h', 'group' => 'productos-express',
                'description' => 'Tarjetas personales a todo color con papeles y laminados configurables, listas en 24 a 48 horas.',
                'fields' => array(
                    self::field('tamano', 'Tamaño', 'select', array(self::option('5x9', '5 × 9 cm'))),
                    self::field('cantidad', 'Cantidad', 'select', array_map(function($quantity) { return self::option((string) $quantity, number_format($quantity, 0, ',', '.')); }, array(100, 200, 300, 400, 500, 1000))),
                    self::field('impresion', 'Impresión', 'select', array(self::option('frente', 'Frente solo'), self::option('frente-dorso', 'Frente y dorso'))),
                    self::field('papel', 'Papel', 'select', array(self::option('300', 'Ilustración 300 g'), self::option('350', 'Ilustración 350 g'))),
                    self::field('laminado', 'Laminado', 'select', array(
                        self::option('sin-laminar', 'Sin laminar'), self::option('mate-frente', 'Mate frente'), self::option('brillo-frente', 'Brillo frente'),
                        self::option('mate-doble', 'Mate frente y dorso', array('impresion' => 'frente-dorso')),
                        self::option('brillo-doble', 'Brillo frente y dorso', array('impresion' => 'frente-dorso')),
                    )),
                    self::field('pleno', 'Archivo con pleno', 'checkbox', array(), array('surcharge' => 0.30, 'help' => 'Suma 30% al valor de la combinación.')),
                ),
                'prices' => array(
                    'tamano=5x9|cantidad=100|impresion=frente|papel=300|laminado=sin-laminar' => 6000,
                    'tamano=5x9|cantidad=100|impresion=frente-dorso|papel=350|laminado=mate-doble' => 27000,
                    'tamano=5x9|cantidad=200|impresion=frente-dorso|papel=350|laminado=mate-doble' => 54000,
                    'tamano=5x9|cantidad=300|impresion=frente-dorso|papel=350|laminado=mate-doble' => 81000,
                    'tamano=5x9|cantidad=400|impresion=frente-dorso|papel=350|laminado=mate-doble' => 108000,
                    'tamano=5x9|cantidad=500|impresion=frente-dorso|papel=350|laminado=mate-doble' => 135000,
                    'tamano=5x9|cantidad=1000|impresion=frente-dorso|papel=350|laminado=mate-doble' => 270000,
                    'tamano=5x9|cantidad=100|impresion=frente-dorso|papel=350|laminado=brillo-doble' => 27000,
                    'tamano=5x9|cantidad=200|impresion=frente-dorso|papel=350|laminado=brillo-doble' => 54000,
                    'tamano=5x9|cantidad=300|impresion=frente-dorso|papel=350|laminado=brillo-doble' => 81000,
                    'tamano=5x9|cantidad=400|impresion=frente-dorso|papel=350|laminado=brillo-doble' => 108000,
                    'tamano=5x9|cantidad=500|impresion=frente-dorso|papel=350|laminado=brillo-doble' => 135000,
                    'tamano=5x9|cantidad=1000|impresion=frente-dorso|papel=350|laminado=brillo-doble' => 270000,
                ),
                'notes' => array('Entrega estimada: 24 a 48 horas.', 'Valores de referencia sin IVA.', 'Tarjetas corporativas en 350 g, full color doble faz y doble laminado: $27.000 cada 100 unidades por diseño.'),
            ),
            'folletos-express' => array(
                'sku' => 'ID-EXP-002', 'name' => 'Folletos Express 24–48 h', 'group' => 'productos-express',
                'description' => 'Folletos digitales full color para tiradas cortas, con distintos formatos, gramajes e impresión simple o doble faz.',
                'fields' => array(
                    self::field('tamano', 'Tamaño', 'select', array(self::option('a3', 'A3 · 42 × 29,7 cm'), self::option('a4', 'A4 · 21 × 29,7 cm'), self::option('a5', 'A5 · 14,8 × 21 cm'), self::option('a6', 'A6 · 10,5 × 14,8 cm')), array('default' => 'a4')),
                    self::field('cantidad', 'Cantidad', 'select', array_map(function($quantity) { return self::option((string) $quantity, number_format($quantity, 0, ',', '.')); }, array(100, 200, 300, 500, 1000))),
                    self::field('papel', 'Papel', 'select', array(self::option('115', 'Ilustración 115 g'), self::option('150', 'Ilustración 150 g'), self::option('200', 'Ilustración 200 g'), self::option('300', 'Ilustración 300 g')), array('default' => '150')),
                    self::field('impresion', 'Impresión', 'select', array(self::option('frente', 'Frente solo'), self::option('frente-dorso', 'Frente y dorso'))),
                    self::field('pleno', 'Archivo con pleno', 'checkbox', array(), array('surcharge' => 0.30, 'help' => 'Suma 30% al valor de la combinación.')),
                ),
                'prices' => array('tamano=a4|cantidad=100|papel=150|impresion=frente' => 32000),
                'notes' => array('Entrega estimada: 24 a 48 horas.', 'Valores de referencia sin IVA.', 'La matriz completa de precios está pendiente de actualización.'),
            ),
            'stickers-publicitarios' => array(
                'sku' => 'ID-STI-001', 'name' => 'Stickers publicitarios', 'group' => 'stickers-autoadhesivos',
                'description' => 'Planchas de stickers sobre papel autoadhesivo con medio corte, listas para entregar con el troquel indicado.',
                'fields' => array(
                    self::field('tamano', 'Área de impresión', 'select', array(self::option('290x440', '29 × 44 cm'))),
                    self::field('cantidad', 'Cantidad de planchas', 'number', array(), array('min' => 1, 'max' => 10000, 'step' => 1, 'default' => 1)),
                    self::field('corte', 'Tamaño de corte', 'select', array(self::option('3x3', 'Hasta 3 × 3 cm'), self::option('5x5', 'Hasta 5 × 5 cm'), self::option('10x10', 'Hasta 10 × 10 cm'), self::option('290x440', 'Hasta 29 × 44 cm'))),
                    self::field('complejidad', 'Archivo con pleno o alta complejidad', 'checkbox', array(), array('surcharge' => 0.30, 'help' => 'Suma 30% al valor de la combinación.')),
                ),
                'prices' => array('tamano=290x440|cantidad=1|corte=3x3' => 2561),
                'notes' => array('Material: papel autoadhesivo.', 'Incluye medio corte.', 'El archivo debe venir armado con el troquel.', 'Valores de referencia sin IVA.'),
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
                    'Archivos con pleno: 20% adicional sobre la impresión.',
                ),
            ),
        );
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
            update_post_meta($product_id, '_ge_supplier_source', self::SOURCE_NAME);
            update_post_meta($product_id, '_ge_supplier_source_date', self::SOURCE_DATE);
            if ('bajadas-digitales-blanco-negro' === $key) { self::assign_existing_reference_image($product_id, 'volantes-blanco-negro.png'); }
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
        wp_enqueue_style('ge-digital-calculator', GE_WTP_PLUGIN_URL . 'assets/css/digital-calculator.css', array(), GE_WTP_VERSION);
        wp_enqueue_script('ge-digital-calculator', GE_WTP_PLUGIN_URL . 'assets/js/digital-calculator.js', array(), GE_WTP_VERSION, true);
    }

    public static function render_calculator() {
        global $product;
        if (! $product) { return; }
        if (class_exists('GE_WTP_Storefront') && GE_WTP_Storefront::config($product->get_id())) { return; }
        $config = $product->get_meta('_ge_digital_config');
        if (! is_array($config) || empty($config['fields'])) { return; }
        $has_final_pricing = ! empty($config['pricing_model']);
        ?>
        <section class="ge-digital-calculator" data-ge-digital-calculator data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="ge-digital-heading"><span>Configurador digital</span><h2>Armá tu producto</h2><p><?php echo esc_html($has_final_pricing ? 'Elegí cada variable y conocé el precio final sin IVA.' : 'Elegí cada variable. Los valores cargados son provisorios hasta actualizar la lista comercial.'); ?></p></div>
            <div class="ge-digital-fields">
                <?php foreach ($config['fields'] as $field) : $default = isset($field['default']) ? $field['default'] : ''; ?>
                    <label class="ge-digital-field ge-field-<?php echo esc_attr($field['type']); ?>">
                        <?php if ('checkbox' === $field['type']) : ?>
                            <input type="checkbox" data-ge-field="<?php echo esc_attr($field['key']); ?>" data-surcharge="<?php echo esc_attr(isset($field['surcharge']) ? $field['surcharge'] : 0); ?>">
                            <span><strong><?php echo esc_html($field['label']); ?></strong><?php if (! empty($field['help'])) : ?><small><?php echo esc_html($field['help']); ?></small><?php endif; ?></span>
                        <?php elseif ('number' === $field['type']) : ?>
                            <span><?php echo esc_html($field['label']); ?></span><input type="number" data-ge-field="<?php echo esc_attr($field['key']); ?>" min="<?php echo esc_attr($field['min']); ?>" max="<?php echo esc_attr($field['max']); ?>" step="<?php echo esc_attr($field['step']); ?>" value="<?php echo esc_attr($field['default']); ?>">
                        <?php else : ?>
                            <span><?php echo esc_html($field['label']); ?></span><select data-ge-field="<?php echo esc_attr($field['key']); ?>">
                                <?php foreach ($field['options'] as $index => $option) : ?>
                                    <option value="<?php echo esc_attr($option['value']); ?>" data-when="<?php echo esc_attr(wp_json_encode($option['when'])); ?>" <?php selected($default ? $default : (0 === $index ? $option['value'] : ''), $option['value']); ?>><?php echo esc_html($option['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <label class="ge-digital-upload"><span>Archivo para imprimir</span><input type="file" data-ge-file accept=".pdf,.ai,.eps,.psd,.svg,.cdr,.tif,.tiff,.jpg,.jpeg,.png,.zip"><small data-ge-file-name>PDF, AI, EPS, PSD, SVG, CDR, TIFF, JPG, PNG o ZIP · capacidad prevista hasta 1 GB. El envío se conectará al almacenamiento externo del pedido.</small></label>
            <div class="ge-digital-total"><div><small data-ge-price-state><?php echo esc_html($has_final_pricing ? 'Precio final sin IVA' : 'Referencia provisoria'); ?></small><strong data-ge-total>Calculando…</strong><span data-ge-unit></span></div><div><small>IVA 21%</small><strong data-ge-tax>—</strong></div></div>
            <p class="ge-digital-warning" data-ge-warning></p>
            <a class="ge-digital-submit" data-ge-submit target="_blank" rel="noopener" href="#">Enviar configuración por WhatsApp ↗</a>
            <?php if (! empty($config['notes'])) : ?><ul class="ge-digital-notes"><?php foreach ($config['notes'] as $note) : ?><li><?php echo esc_html($note); ?></li><?php endforeach; ?></ul><?php endif; ?>
        </section>
        <?php
    }
}

GE_WTP_Digital_Catalog::init();
