<?php

defined('ABSPATH') || exit;

/**
 * Catálogo público Graph Express para productos de imprenta offset.
 *
 * Una ficha por producto comercial; medidas, cantidades y terminaciones viven
 * dentro de la ficha para evitar duplicar tarjetas en la tienda.
 */
final class GE_WTP_Mardones_Catalog {
    const SOURCE_NAME = 'Mardones / Sur Colors';
    const SOURCE_DATE = '2026-09-02';

    private static function item($sku, $name, $category, $group, $description, $attributes, $sections, $notes, $minimum, $source_name = '', $source_date = '', $source_files = array()) {
        return compact('sku', 'name', 'category', 'group', 'description', 'attributes', 'sections', 'notes', 'minimum', 'source_name', 'source_date', 'source_files');
    }

    private static function section($title, $columns, $rows) {
        return compact('title', 'columns', 'rows');
    }

    public static function products() {
        return array(
            'ticket-lavadero' => self::item(
                'ID-TAL-001',
                'Tickets · Talonarios troquelados y numerados',
                'imprenta-offset',
                'talonarios-formularios',
                'Talonarios personalizados, numerados, troquelados y abrochados para rifas, entradas, controles de ingreso, lavaderos y servicios.',
                array(
                    'Formato' => array('11 × 22 cm'),
                    'Presentación' => array('Talonarios de 100 hojas'),
                    'Papel' => array('Obra blanco 70 g'),
                    'Incluye' => array('Numerado', 'Hasta 3 puntillados', 'Abrochado'),
                ),
                array(self::section('Talonarios troquelados y numerados 11 × 22 cm', array('Cantidad', 'Precio'), array(
                    array('15', '$ 41.080'), array('30', '$ 72.150'), array('50', '$ 111.670'), array('100', '$ 207.090'),
                ))),
                array('No incluye diseño.', 'Valores + IVA 21%.'),
                41080
            ),
            'talonarios-afip' => self::item(
                'ID-TAL-002',
                'Talonarios ARCA (AFIP)',
                'imprenta-offset',
                'talonarios-formularios',
                'Talonarios fiscales personalizados para comprobantes A, B, C, M, R y X habilitados por ARCA (ex AFIP). Para iniciar la validación necesitamos la constancia de CAI; también podés adjuntar el logo y un modelo anterior.',
                array(
                    'Medida estándar' => array('17 × 22 cm'),
                    'Tipos' => array('A', 'B', 'C', 'M', 'R', 'X'),
                    'Copias' => array('Duplicado', 'Triplicado', 'Cuadruplicado'),
                    'Numeración' => array('25 números por talonario en triplicado'),
                    'Archivos' => array('Constancia de CAI', 'Logo opcional', 'Modelo anterior opcional'),
                ),
                array(self::section('Talonarios ARCA (AFIP) 17 × 22 cm', array('Cantidad', 'Precio'), array(
                    array('1', '$ 10.000'), array('2', '$ 18.000'), array('3', '$ 25.400'), array('4', '$ 31.000'), array('5', '$ 36.400'),
                    array('6', '$ 39.600'), array('8', '$ 52.400'), array('10', '$ 64.600'), array('20', '$ 125.800'), array('Más de 20', 'Cotizar'),
                ))),
                array('La constancia de CAI vigente es necesaria para validar y producir el trabajo.', 'La cantidad, el tipo de comprobante y la numeración deben coincidir con el CAI.', 'Podés cargar por separado el CAI, el logo y una referencia anterior.', 'Triplicado sin cargo.', 'Cuadruplicado: 40% de recargo.', 'Para otras medidas, solicitar cotización.', 'No incluye diseño.', 'Valores + IVA 21%.'),
                10000
            ),
            'presupuestos-comandas-anotadores' => self::item(
                'ID-TAL-003',
                'Presupuestos, comandas y anotadores',
                'imprenta-offset',
                'talonarios-formularios',
                'Blocks encolados para presupuestos, comandas, notas internas y uso comercial, en blanco y negro o full color.',
                array(
                    'Terminaciones' => array('Encolado arriba', 'Duplicado numerado y abrochado'),
                    'Impresión' => array('Tinta negra', 'Full color offset 4/0'),
                    'Papel' => array('Obra blanco 70 g'),
                    'Presentación' => array('100 hojas por block'),
                ),
                array(
                    self::section('Blanco y negro - simple', array('Medida / Cantidad', 'Precio'), array(
                        array('11 × 11 cm · 20', '$ 29.380'), array('11 × 11 cm · 50', '$ 45.760'), array('11 × 11 cm · 100', '$ 80.990'), array('11 × 11 cm · 150', '$ 111.150'), array('11 × 11 cm · 300', '$ 219.050'),
                        array('11 × 17 cm · 10', '$ 20.670'), array('11 × 17 cm · 20', '$ 31.720'), array('11 × 17 cm · 50', '$ 61.100'), array('11 × 17 cm · 100', '$ 106.080'), array('11 × 17 cm · 200', '$ 208.780'),
                        array('10 × 10 cm · 20', '$ 26.000'), array('10 × 10 cm · 50', '$ 37.960'), array('10 × 10 cm · 100', '$ 66.170'), array('10 × 10 cm · 150', '$ 88.140'), array('10 × 10 cm · 300', '$ 166.530'),
                        array('10 × 15 cm · 10', '$ 18.200'), array('10 × 15 cm · 20', '$ 27.950'), array('10 × 15 cm · 50', '$ 50.700'), array('10 × 15 cm · 100', '$ 86.060'), array('10 × 15 cm · 200', '$ 167.830'),
                        array('11 × 22 cm · 10', '$ 28.340'), array('11 × 22 cm · 20', '$ 44.460'), array('11 × 22 cm · 50', '$ 80.600'), array('11 × 22 cm · 100', '$ 151.320'), array('11 × 22 cm · 200', '$ 299.000'),
                        array('17 × 22 cm · 5', '$ 20.670'), array('17 × 22 cm · 10', '$ 31.720'), array('17 × 22 cm · 20', '$ 53.950'), array('17 × 22 cm · 50', '$ 105.170'), array('17 × 22 cm · 100', '$ 207.220'),
                        array('10 × 20 cm · 10', '$ 23.790'), array('10 × 20 cm · 20', '$ 36.920'), array('10 × 20 cm · 50', '$ 73.710'), array('10 × 20 cm · 100', '$ 122.720'), array('10 × 20 cm · 200', '$ 241.800'),
                        array('15 × 20 cm · 5', '$ 18.200'), array('15 × 20 cm · 10', '$ 27.950'), array('15 × 20 cm · 20', '$ 44.720'), array('15 × 20 cm · 50', '$ 85.540'), array('15 × 20 cm · 100', '$ 166.790'),
                    )),
                    self::section('Duplicado, numerado y abrochado', array('Medida / Cantidad', 'Precio'), array(
                        array('11 × 17 cm · 20', '$ 42.510'), array('11 × 17 cm · 50', '$ 94.900'), array('11 × 17 cm · 100', '$ 165.100'), array('11 × 17 cm · 200', '$ 322.790'),
                        array('11 × 22 cm · 12', '$ 34.060'), array('11 × 22 cm · 21', '$ 57.850'), array('11 × 22 cm · 51', '$ 119.210'), array('11 × 22 cm · 102', '$ 214.500'),
                    )),
                    self::section('Full color - 100 hojas por block', array('Medida / Cantidad', 'Precio'), array(
                        array('10 × 10 cm · 20', '$ 42.120'), array('10 × 10 cm · 40', '$ 69.940'), array('10 × 10 cm · 80', '$ 139.880'), array('10 × 10 cm · 200', '$ 348.920'),
                        array('11 × 11 cm · 20', '$ 54.340'), array('11 × 11 cm · 40', '$ 90.480'), array('11 × 11 cm · 80', '$ 180.700'), array('11 × 11 cm · 200', '$ 450.320'),
                        array('10 × 15 cm · 10', '$ 29.900'), array('10 × 15 cm · 20', '$ 49.660'), array('10 × 15 cm · 40', '$ 99.060'), array('10 × 15 cm · 100', '$ 246.740'),
                        array('11 × 17 cm · 10', '$ 38.610'), array('11 × 17 cm · 20', '$ 64.090'), array('11 × 17 cm · 40', '$ 127.790'), array('11 × 17 cm · 100', '$ 318.240'),
                        array('10 × 20 cm · 10', '$ 38.610'), array('10 × 20 cm · 20', '$ 64.090'), array('10 × 20 cm · 40', '$ 127.790'), array('10 × 20 cm · 100', '$ 318.240'),
                        array('11 × 22 cm · 10', '$ 49.920'), array('11 × 22 cm · 20', '$ 82.810'), array('11 × 22 cm · 40', '$ 164.970'), array('11 × 22 cm · 100', '$ 410.800'),
                        array('15 × 20 cm · 10', '$ 49.660'), array('15 × 20 cm · 20', '$ 99.060'), array('15 × 20 cm · 40', '$ 197.340'), array('15 × 20 cm · 100', '$ 489.840'),
                        array('17 × 22 cm · 10', '$ 64.090'), array('17 × 22 cm · 20', '$ 128.310'), array('17 × 22 cm · 40', '$ 254.800'), array('17 × 22 cm · 100', '$ 631.930'),
                    )),
                ),
                array('Blanco y negro simple: sin duplicado y sin numerar.', 'Tinta de color en pedidos B/N: x20 + $ 2.860, x50 + $ 4.290, x100 + $ 6.500', 'Duplicado: original blanco, copia color e impresión negra.', 'Full color: impresión offset 4/0, sin numerado ni duplicado, demora estimada 10 a 15 días.', 'Valores + IVA 21%.'),
                18200
            ),
            'tarjetas-personales' => self::item(
                'ID-TAR-001',
                'Tarjetas personales',
                'imprenta-offset',
                'tarjetas-etiquetas',
                'Tarjetas personales full color sobre papel ilustración mate de 350 g, con laminado brillante sólo al frente.',
                array(
                    'Medidas' => array('5 × 9 cm', '5 × 18 cm', '10 × 9 cm', '15 × 9 cm', '10 × 18 cm'),
                    'Cantidades' => array('1.000', '2.000', '3.000', '4.000', '5.000', '6.000', '7.000', '8.000', '9.000', '10.000'),
                    'Impresión' => array('Full color frente', 'Full color frente y dorso'),
                    'Papel' => array('Ilustración mate 350 g'),
                    'Laminado' => array('Brillante sólo al frente'),
                ),
                array(self::section('Tarjetas personales full color', array('Medida', '1.000', '2.000', '3.000', '4.000', '5.000', '6.000', '7.000', '8.000', '9.000', '10.000'), array(
                    array('5 × 9 cm', '$ 45.000', '$ 90.000', '$ 135.000', '$ 180.000', '$ 225.000', '$ 270.000', '$ 315.000', '$ 360.000', '$ 405.000', '$ 450.000'),
                    array('5 × 18 cm', '$ 90.000', '$ 180.000', '$ 270.000', '$ 360.000', '$ 450.000', '$ 540.000', '$ 630.000', '$ 720.000', '$ 810.000', '$ 900.000'),
                    array('10 × 9 cm', '$ 90.000', '$ 180.000', '$ 270.000', '$ 360.000', '$ 450.000', '$ 540.000', '$ 630.000', '$ 720.000', '$ 810.000', '$ 900.000'),
                    array('15 × 9 cm', '$ 135.000', '$ 270.000', '$ 405.000', '$ 540.000', '$ 675.000', '$ 810.000', '$ 945.000', '$ 1.080.000', '$ 1.215.000', '$ 1.350.000'),
                    array('10 × 18 cm', '$ 180.000', '$ 360.000', '$ 540.000', '$ 720.000', '$ 900.000', '$ 1.080.000', '$ 1.260.000', '$ 1.440.000', '$ 1.620.000', '$ 1.800.000'),
                ))),
                array('Papel ilustración mate 350 g y laminado brillante sólo al frente: opciones fijas.', 'Impresión full color: frente o frente y dorso.', 'Demora aproximada informada por Druck: 15 días.', 'Valores + IVA 21%.'),
                45000,
                'Druck',
                '2026-09-13',
                array('https://www.tienda.graficadruck.com.ar/tarjetas-promocionales-136/')
            ),
            'etiquetas' => self::item(
                'ID-ETI-001',
                'Etiquetas impresas',
                'imprenta-offset',
                'tarjetas-etiquetas',
                'Etiquetas full color laqueadas para productos, packaging e identificación.',
                array('Medidas' => array('4,5 × 5 cm', '2,5 × 9 cm', '3 × 9 cm', '5 × 9 cm', '9 × 4 cm'), 'Papel' => array('Ilustración mate 350 g'), 'Impresión' => array('Frente full color'), 'Terminación' => array('Laqueado brillante', 'Dorso blanco y negro')),
                array(self::section('Etiquetas', array('Medida / Cantidad', 'Precio'), array(
                    array('4,5 × 5 o 2,5 × 9 cm · 2.000', '$ 32.760'), array('4,5 × 5 o 2,5 × 9 cm · 6.000', '$ 98.280'),
                    array('3 × 9 cm · 3.000', '$ 65.520'), array('3 × 9 cm · 6.000', '$ 131.040'),
                    array('5 × 9 cm · 1.000', '$ 32.760'), array('5 × 9 cm · 2.000', '$ 65.520'), array('5 × 9 cm · 3.000', '$ 98.280'), array('5 × 9 cm · 6.000', '$ 196.560'),
                    array('9 × 4 cm · 5.000', '$ 131.040'), array('9 × 4 cm · 10.000', '$ 262.080'),
                ))),
                array('Agujereado: x1.000 $ 3.120; x3.000 $ 9.100; x6.000 $ 17.420', 'Redondeado de puntas: x1.000 $ 3.250; x3.000 $ 9.360; x6.000 $ 18.200', 'Demora estimada: 3 a 7 días.', 'Valores + IVA 21%.'),
                32760
            ),
            'volantes-blanco-negro' => self::item(
                'IO-VOL-001',
                'Volantes blanco y negro',
                'imprenta-offset',
                'volantes',
                'Volantes económicos impresos en negro para comunicación masiva, promociones e información.',
                array('Medidas' => array('7 × 10 cm', '10 × 10 cm', '10 × 15 cm', '10 × 20 cm', '15 × 20 cm', '20 × 30 cm'), 'Cantidades' => array('1.000', '2.000', '5.000', '10.000'), 'Papel' => array('Obra blanco 70 g'), 'Impresión' => array('Blanco y negro')),
                array(self::section('Volantes blanco y negro', array('Medida', '1.000', '2.000', '5.000', '10.000'), array(
                    array('7 × 10 cm', '$ 11.700', '$ 19.110', '$ 33.410', '$ 56.290'),
                    array('10 × 10 cm', '$ 13.520', '$ 20.540', '$ 36.010', '$ 63.050'),
                    array('10 × 15 cm', '$ 14.300', '$ 24.050', '$ 48.750', '$ 78.000'),
                    array('10 × 20 cm', '$ 18.720', '$ 29.510', '$ 61.360', '$ 114.920'),
                    array('15 × 20 cm', '$ 21.840', '$ 37.960', '$ 78.000', '$ 145.600'),
                    array('20 × 30 cm', '$ 37.440', '$ 68.900', '$ 152.230', '$ 286.260'),
                ))),
                array('Dorso: 50% adicional.', 'Papel de color: 100% adicional.', 'Impresión en magenta, azul o rojo: 15% de recargo.', 'No incluye diseño.', 'Valores + IVA 21%.'),
                11700
            ),
            'volantes-full-color' => self::item(
                'IO-VOL-002',
                'Volantes full color',
                'imprenta-offset',
                'volantes',
                'Volantes a todo color frente y dorso para campañas, promociones y comunicación institucional.',
                array('Medidas' => array('10 × 15 cm', '20 × 15 cm', '30 × 15 cm', '20 × 30 cm'), 'Cantidades' => array('1.000', '5.000'), 'Papel' => array('Ilustración 115 g'), 'Impresión' => array('Frente y dorso full color')),
                array(self::section('Volantes full color', array('Medida', '1.000', '5.000'), array(
                    array('10 × 15 cm', '$ 38.870', '$ 103.870'), array('20 × 15 cm', '$ 77.740', '$ 207.740'),
                    array('30 × 15 cm', '$ 116.610', '$ 311.610'), array('20 × 30 cm', '$ 155.480', '$ 415.480'),
                ))),
                array('Demora estimada: 5 a 10 días.', 'Valores + IVA 21%.'),
                38870
            ),
            'imanes-publicitarios' => self::item(
                'ME-IMA-001',
                'Imanes publicitarios',
                'imprenta-offset',
                'imanes-publicitarios',
                'Imanes personalizados a todo color para promociones, contactos y recordatorios de marca.',
                array('Medidas' => array('6 × 4 cm', '7 × 5 cm', '8 × 6 cm', '10 × 7 cm'), 'Cantidades' => array('500', '1.000'), 'Base' => array('Cartulina 300 g'), 'Terminación' => array('Laminado brillante', 'Puntas redondeadas')),
                array(self::section('Imanes full color', array('Medida', '500', '1.000'), array(
                    array('6 × 4 cm', '$ 96.980', '$ 162.500'), array('7 × 5 cm', '$ 128.180', '$ 213.850'),
                    array('8 × 6 cm', '$ 182.910', '$ 305.500'), array('10 × 7 cm', '$ 249.210', '$ 416.780'),
                ))),
                array('Impresión full color.', 'Demora estimada: 12 a 20 días.', 'Valores + IVA 21%.'),
                96980
            ),
        );
    }

    public static function sync() {
        if (! class_exists('WC_Product_Simple')) {
            return new WP_Error('woocommerce_required', 'WooCommerce debe estar activo para sincronizar el catálogo.');
        }

        $category_ids = self::sync_categories();
        if (is_wp_error($category_ids)) {
            return $category_ids;
        }

        $created = 0;
        $updated = 0;
        $position = 100;

        foreach (self::products() as $key => $data) {
            $existing = get_posts(array(
                'post_type' => 'product', 'post_status' => array('publish', 'draft', 'private'),
                'posts_per_page' => 1, 'fields' => 'ids',
                'meta_key' => '_ge_public_catalog_key', 'meta_value' => 'mardones-' . $key,
            ));
            $is_new = empty($existing);
            $product = $is_new ? new WC_Product_Simple() : wc_get_product($existing[0]);
            if (! $product) {
                continue;
            }

            $product->set_name($data['name']);
            $product->set_slug($key);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_description($data['description']);
            $product->set_short_description($data['description']);
            $product->set_sku($data['sku']);
            $product->set_regular_price('');
            $product->set_sale_price('');
            $product->set_category_ids(array($category_ids[$data['category']], $category_ids[$data['group']]));
            $product->set_menu_order($position++);
            $product->set_reviews_allowed(false);
            $product->set_attributes(self::build_attributes($data['attributes']));
            $product_id = $product->save();

            update_post_meta($product_id, '_ge_public_catalog_key', 'mardones-' . $key);
            update_post_meta($product_id, '_ge_quote_only', 'yes');
            update_post_meta($product_id, '_ge_show_reference_price', 'yes');
            update_post_meta($product_id, '_ge_reference_price_min', $data['minimum']);
            update_post_meta($product_id, '_ge_public_price_sections', $data['sections']);
            update_post_meta($product_id, '_ge_public_price_notes', $data['notes']);
            update_post_meta($product_id, '_ge_supplier_source', ! empty($data['source_name']) ? $data['source_name'] : self::SOURCE_NAME);
            update_post_meta($product_id, '_ge_supplier_source_date', ! empty($data['source_date']) ? $data['source_date'] : self::SOURCE_DATE);
            update_post_meta($product_id, '_ge_supplier_source_files', ! empty($data['source_files']) ? $data['source_files'] : array(
                'WhatsApp Image 2026-09-02 at 4.22.48 PM.jpeg',
                'WhatsApp Image 2026-09-02 at 4.22.49 PM (1).jpeg',
                'WhatsApp Image 2026-09-02 at 4.22.50 PM (1).jpeg',
                'WhatsApp Image 2026-09-02 at 4.22.50 PM.jpeg',
            ));
            $storefront_config = self::storefront_config($key);
            if ($storefront_config) {
                update_post_meta($product_id, '_ge_storefront_config', $storefront_config);
            } else {
                delete_post_meta($product_id, '_ge_storefront_config');
            }

            $is_new ? $created++ : $updated++;
        }

        clean_term_cache(array_values($category_ids), 'product_cat');
        return array('created' => $created, 'updated' => $updated, 'total' => $created + $updated);
    }

    private static function ticket_storefront_config() {
        $base_totals = array(15 => 41080, 30 => 72150, 50 => 111670, 100 => 207090);
        $options = array();
        $map = array();
        foreach ($base_totals as $quantity => $total) {
            foreach (array('frente' => array('Frente', 0), 'frente-dorso' => array('Frente y dorso', .50)) as $print_key => $print) {
                foreach (array('blanco' => array('Papel blanco', 0), 'color' => array('Papel de color', .80)) as $paper_key => $paper) {
                    $surcharge = 1 + $print[1] + $paper[1];
                    $option_key = 'ticket-' . $quantity . '-' . $print_key . '-' . $paper_key;
                    $options[$option_key] = array(
                        'label' => '11 × 22 cm · ' . $print[0] . ' · ' . $paper[0],
                        'price' => round(($total * $surcharge) / $quantity, 4),
                        'fixed_qty' => $quantity,
                        'min_qty' => $quantity,
                        'step' => $quantity,
                    );
                    $map['11x22|' . $print_key . '|' . $paper_key . '|' . $quantity] = $option_key;
                }
            }
        }
        return array(
            'label' => 'Formato',
            'options' => $options,
            'selectors' => array(
                array('key' => 'formato', 'label' => 'Formato', 'options' => array('11x22' => '11 × 22 cm')),
                array('key' => 'impresion', 'label' => 'Impresión', 'options' => array('frente' => 'Frente', 'frente-dorso' => 'Frente y dorso (+50%)')),
                array('key' => 'papel', 'label' => 'Papel', 'options' => array('blanco' => 'Blanco', 'color' => 'De color (+80%)')),
                array('key' => 'cantidad', 'label' => 'Cantidad', 'options' => array('15' => '15', '30' => '30', '50' => '50', '100' => '100')),
            ),
            'option_map' => $map,
            'fixed_quantity_selector' => true,
        );
    }

    private static function storefront_config($key) {
        switch ($key) {
            case 'ticket-lavadero': return self::ticket_storefront_config();
            case 'talonarios-afip': return self::arca_storefront_config();
            case 'presupuestos-comandas-anotadores': return self::blocks_storefront_config();
            case 'tarjetas-personales': return self::cards_storefront_config();
            case 'etiquetas': return self::labels_storefront_config();
            case 'volantes-blanco-negro': return self::bw_flyers_storefront_config();
            case 'volantes-full-color': return self::color_flyers_storefront_config();
            case 'imanes-publicitarios': return self::magnets_storefront_config();
        }
        return array();
    }

    private static function fixed_config($fields, $entries) {
        $options = array();
        $map = array();
        foreach ($entries as $index => $entry) {
            $values = array();
            $labels = array();
            foreach ($fields as $field_key => $field) {
                $value = isset($entry['values'][$field_key]) ? (string) $entry['values'][$field_key] : '';
                $values[] = $value;
                $labels[] = $field['label'] . ': ' . (isset($field['options'][$value]) ? $field['options'][$value] : $value);
            }
            $quantity = max(1, (int) $entry['quantity']);
            $option_key = 'offset-' . substr(md5(implode('|', $values)), 0, 16);
            $options[$option_key] = array(
                'label' => implode(' · ', $labels),
                'price' => round((float) $entry['total'] / $quantity, 4),
                'fixed_qty' => $quantity,
                'min_qty' => $quantity,
                'step' => $quantity,
            );
            $map[implode('|', $values)] = $option_key;
        }
        $selectors = array();
        foreach ($fields as $field_key => $field) {
            $selectors[] = array('key' => $field_key, 'label' => $field['label'], 'options' => $field['options']);
        }
        return array('label' => 'Configuración', 'options' => $options, 'selectors' => $selectors, 'option_map' => $map, 'fixed_quantity_selector' => true);
    }

    private static function arca_storefront_config() {
        $fields = array(
            'formato' => array('label' => 'Formato', 'options' => array('17x22' => '17 × 22 cm')),
            'comprobante' => array('label' => 'Comprobante', 'options' => array('a' => 'A', 'b' => 'B', 'c' => 'C', 'm' => 'M', 'r' => 'R', 'x' => 'X')),
            'copias' => array('label' => 'Copias', 'options' => array('duplicado' => 'Duplicado', 'triplicado' => 'Triplicado (sin cargo)', 'cuadruplicado' => 'Cuadruplicado (+40%)')),
            'cantidad' => array('label' => 'Cantidad de talonarios', 'options' => array('1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '8' => '8', '10' => '10', '20' => '20')),
        );
        $base_totals = array(1 => 10000, 2 => 18000, 3 => 25400, 4 => 31000, 5 => 36400, 6 => 39600, 8 => 52400, 10 => 64600, 20 => 125800);
        $entries = array();
        foreach (array('a', 'b', 'c', 'm', 'r', 'x') as $document) {
            foreach (array('duplicado' => 1, 'triplicado' => 1, 'cuadruplicado' => 1.4) as $copies => $multiplier) {
                foreach ($base_totals as $quantity => $total) {
                    $entries[] = array('values' => array('formato' => '17x22', 'comprobante' => $document, 'copias' => $copies, 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => round($total * $multiplier));
                }
            }
        }
        $config = self::fixed_config($fields, $entries);
        $config['upload_title'] = 'Cargar CAI, logo y referencias';
        $config['upload_description'] = 'Adjuntá la constancia de CAI en PDF. También podés sumar el logotipo y un talonario anterior como referencia.';
        $config['upload_hint'] = 'Podés cargar varios archivos por separado. La constancia de CAI es necesaria para iniciar la validación del trabajo.';
        $config['comments_label'] = 'Comentarios o datos adicionales';
        $config['comments_placeholder'] = 'Ej.: numeración inicial, nombre de fantasía, actividad, teléfono, email o indicaciones de diseño.';
        return $config;
    }

    private static function blocks_storefront_config() {
        $formats = array('10x10' => '10 × 10 cm', '10x15' => '10 × 15 cm', '10x20' => '10 × 20 cm', '11x11' => '11 × 11 cm', '11x17' => '11 × 17 cm', '11x22' => '11 × 22 cm', '15x20' => '15 × 20 cm', '17x22' => '17 × 22 cm');
        $quantities = array(5, 10, 12, 20, 21, 40, 50, 51, 80, 100, 102, 150, 200, 300);
        $quantity_options = array(); foreach ($quantities as $quantity) { $quantity_options[(string) $quantity] = number_format($quantity, 0, ',', '.'); }
        $fields = array(
            'modalidad' => array('label' => 'Tipo de trabajo', 'options' => array('simple' => 'Block simple', 'duplicado' => 'Duplicado, numerado y abrochado', 'full-color' => 'Full color')),
            'formato' => array('label' => 'Formato', 'options' => $formats),
            'tinta' => array('label' => 'Impresión', 'options' => array('negro' => 'Negro', 'magenta' => 'Magenta', 'azul' => 'Azul', 'rojo' => 'Rojo', 'full-color' => 'Full color 4/0')),
            'cantidad' => array('label' => 'Cantidad de blocks', 'options' => $quantity_options),
        );
        $simple = array(
            '11x11' => array(20 => 29380, 50 => 45760, 100 => 80990, 150 => 111150, 300 => 219050), '11x17' => array(10 => 20670, 20 => 31720, 50 => 61100, 100 => 106080, 200 => 208780),
            '10x10' => array(20 => 26000, 50 => 37960, 100 => 66170, 150 => 88140, 300 => 166530), '10x15' => array(10 => 18200, 20 => 27950, 50 => 50700, 100 => 86060, 200 => 167830),
            '11x22' => array(10 => 28340, 20 => 44460, 50 => 80600, 100 => 151320, 200 => 299000), '17x22' => array(5 => 20670, 10 => 31720, 20 => 53950, 50 => 105170, 100 => 207220),
            '10x20' => array(10 => 23790, 20 => 36920, 50 => 73710, 100 => 122720, 200 => 241800), '15x20' => array(5 => 18200, 10 => 27950, 20 => 44720, 50 => 85540, 100 => 166790),
        );
        $duplicated = array('11x17' => array(20 => 42510, 50 => 94900, 100 => 165100, 200 => 322790), '11x22' => array(12 => 34060, 21 => 57850, 51 => 119210, 102 => 214500));
        $color = array(
            '10x10' => array(20 => 42120, 40 => 69940, 80 => 139880, 200 => 348920), '11x11' => array(20 => 54340, 40 => 90480, 80 => 180700, 200 => 450320),
            '10x15' => array(10 => 29900, 20 => 49660, 40 => 99060, 100 => 246740), '11x17' => array(10 => 38610, 20 => 64090, 40 => 127790, 100 => 318240),
            '10x20' => array(10 => 38610, 20 => 64090, 40 => 127790, 100 => 318240), '11x22' => array(10 => 49920, 20 => 82810, 40 => 164970, 100 => 410800),
            '15x20' => array(10 => 49660, 20 => 99060, 40 => 197340, 100 => 489840), '17x22' => array(10 => 64090, 20 => 128310, 40 => 254800, 100 => 631930),
        );
        $entries = array();
        foreach ($simple as $format => $prices) { foreach ($prices as $quantity => $total) {
            $entries[] = array('values' => array('modalidad' => 'simple', 'formato' => $format, 'tinta' => 'negro', 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => $total);
            $extra = array(20 => 2860, 50 => 4290, 100 => 6500);
            if (isset($extra[$quantity])) { foreach (array('magenta', 'azul', 'rojo') as $ink) { $entries[] = array('values' => array('modalidad' => 'simple', 'formato' => $format, 'tinta' => $ink, 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => $total + $extra[$quantity]); } }
        } }
        foreach ($duplicated as $format => $prices) { foreach ($prices as $quantity => $total) { $entries[] = array('values' => array('modalidad' => 'duplicado', 'formato' => $format, 'tinta' => 'negro', 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => $total); } }
        foreach ($color as $format => $prices) { foreach ($prices as $quantity => $total) { $entries[] = array('values' => array('modalidad' => 'full-color', 'formato' => $format, 'tinta' => 'full-color', 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => $total); } }
        return self::fixed_config($fields, $entries);
    }

    private static function cards_storefront_config() {
        $fields = array(
            'formato' => array('label' => 'Medida', 'options' => array('5x9' => '5 × 9 cm', '5x18' => '5 × 18 cm', '10x9' => '10 × 9 cm', '15x9' => '15 × 9 cm', '10x18' => '10 × 18 cm')),
            'impresion' => array('label' => 'Impresión', 'options' => array('frente' => 'Full color frente', 'frente-dorso' => 'Full color frente y dorso')),
            'cantidad' => array('label' => 'Cantidad', 'options' => array('1000' => '1.000', '2000' => '2.000', '3000' => '3.000', '4000' => '4.000', '5000' => '5.000', '6000' => '6.000', '7000' => '7.000', '8000' => '8.000', '9000' => '9.000', '10000' => '10.000')),
        );
        $druck_per_thousand = array('5x9' => 30000, '5x18' => 60000, '10x9' => 60000, '15x9' => 90000, '10x18' => 120000);
        $entries = array();
        foreach ($druck_per_thousand as $format => $base) {
            foreach (array('frente', 'frente-dorso') as $print) {
                for ($quantity = 1000; $quantity <= 10000; $quantity += 1000) {
                    $entries[] = array(
                        'values' => array('formato' => $format, 'impresion' => $print, 'cantidad' => (string) $quantity),
                        'quantity' => $quantity,
                        'total' => round($base * ($quantity / 1000) * 1.5),
                    );
                }
            }
        }
        return self::fixed_config($fields, $entries);
    }

    private static function labels_storefront_config() {
        $formats = array('4-5x5-o-2-5x9' => '4,5 × 5 o 2,5 × 9 cm', '3x9' => '3 × 9 cm', '5x9' => '5 × 9 cm', '9x4' => '9 × 4 cm');
        $fields = array(
            'formato' => array('label' => 'Formato', 'options' => $formats),
            'terminacion' => array('label' => 'Terminación', 'options' => array('estandar' => 'Estándar', 'agujereado' => 'Agujereado', 'redondeado' => 'Puntas redondeadas', 'ambas' => 'Agujereado + puntas redondeadas')),
            'cantidad' => array('label' => 'Cantidad', 'options' => array('1000' => '1.000', '2000' => '2.000', '3000' => '3.000', '5000' => '5.000', '6000' => '6.000', '10000' => '10.000')),
        );
        $prices = array('4-5x5-o-2-5x9' => array(2000 => 32760, 6000 => 98280), '3x9' => array(3000 => 65520, 6000 => 131040), '5x9' => array(1000 => 32760, 2000 => 65520, 3000 => 98280, 6000 => 196560), '9x4' => array(5000 => 131040, 10000 => 262080));
        $hole = array(1000 => 3120, 3000 => 9100, 6000 => 17420); $round = array(1000 => 3250, 3000 => 9360, 6000 => 18200);
        $entries = array(); foreach ($prices as $format => $quantities) { foreach ($quantities as $quantity => $total) {
            $entries[] = array('values' => array('formato' => $format, 'terminacion' => 'estandar', 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => $total);
            if (isset($hole[$quantity])) { $entries[] = array('values' => array('formato' => $format, 'terminacion' => 'agujereado', 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => $total + $hole[$quantity]); }
            if (isset($round[$quantity])) { $entries[] = array('values' => array('formato' => $format, 'terminacion' => 'redondeado', 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => $total + $round[$quantity]); }
            if (isset($hole[$quantity], $round[$quantity])) { $entries[] = array('values' => array('formato' => $format, 'terminacion' => 'ambas', 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => $total + $hole[$quantity] + $round[$quantity]); }
        } }
        return self::fixed_config($fields, $entries);
    }

    private static function bw_flyers_storefront_config() {
        $fields = array(
            'formato' => array('label' => 'Formato', 'options' => array('7x10' => '7 × 10 cm', '10x10' => '10 × 10 cm', '10x15' => '10 × 15 cm', '10x20' => '10 × 20 cm', '15x20' => '15 × 20 cm', '20x30' => '20 × 30 cm')),
            'impresion' => array('label' => 'Impresión', 'options' => array('frente' => 'Frente', 'frente-dorso' => 'Frente y dorso (+50%)')),
            'papel' => array('label' => 'Papel', 'options' => array('blanco' => 'Blanco', 'color' => 'De color (+100%)')),
            'tinta' => array('label' => 'Tinta', 'options' => array('negro' => 'Negro', 'magenta' => 'Magenta (+15%)', 'azul' => 'Azul (+15%)', 'rojo' => 'Rojo (+15%)')),
            'cantidad' => array('label' => 'Cantidad', 'options' => array('1000' => '1.000', '2000' => '2.000', '5000' => '5.000', '10000' => '10.000')),
        );
        $prices = array('7x10' => array(1000 => 11700, 2000 => 19110, 5000 => 33410, 10000 => 56290), '10x10' => array(1000 => 13520, 2000 => 20540, 5000 => 36010, 10000 => 63050), '10x15' => array(1000 => 14300, 2000 => 24050, 5000 => 48750, 10000 => 78000), '10x20' => array(1000 => 18720, 2000 => 29510, 5000 => 61360, 10000 => 114920), '15x20' => array(1000 => 21840, 2000 => 37960, 5000 => 78000, 10000 => 145600), '20x30' => array(1000 => 37440, 2000 => 68900, 5000 => 152230, 10000 => 286260));
        $entries = array(); foreach ($prices as $format => $quantities) { foreach ($quantities as $quantity => $base) { foreach (array('frente' => 0, 'frente-dorso' => .5) as $print => $print_extra) { foreach (array('blanco' => 0, 'color' => 1) as $paper => $paper_extra) { foreach (array('negro' => 0, 'magenta' => .15, 'azul' => .15, 'rojo' => .15) as $ink => $ink_extra) { $entries[] = array('values' => array('formato' => $format, 'impresion' => $print, 'papel' => $paper, 'tinta' => $ink, 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => round($base * (1 + $print_extra + $paper_extra + $ink_extra))); } } } } }
        return self::fixed_config($fields, $entries);
    }

    private static function color_flyers_storefront_config() {
        $fields = array('formato' => array('label' => 'Formato', 'options' => array('10x15' => '10 × 15 cm', '20x15' => '20 × 15 cm', '30x15' => '30 × 15 cm', '20x30' => '20 × 30 cm')), 'cantidad' => array('label' => 'Cantidad', 'options' => array('1000' => '1.000', '5000' => '5.000')));
        $prices = array('10x15' => array(1000 => 38870, 5000 => 103870), '20x15' => array(1000 => 77740, 5000 => 207740), '30x15' => array(1000 => 116610, 5000 => 311610), '20x30' => array(1000 => 155480, 5000 => 415480));
        $entries = array(); foreach ($prices as $format => $quantities) { foreach ($quantities as $quantity => $total) { $entries[] = array('values' => array('formato' => $format, 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => $total); } }
        return self::fixed_config($fields, $entries);
    }

    private static function magnets_storefront_config() {
        $fields = array('formato' => array('label' => 'Formato', 'options' => array('6x4' => '6 × 4 cm', '7x5' => '7 × 5 cm', '8x6' => '8 × 6 cm', '10x7' => '10 × 7 cm')), 'cantidad' => array('label' => 'Cantidad', 'options' => array('500' => '500', '1000' => '1.000')));
        $prices = array('6x4' => array(500 => 96980, 1000 => 162500), '7x5' => array(500 => 128180, 1000 => 213850), '8x6' => array(500 => 182910, 1000 => 305500), '10x7' => array(500 => 249210, 1000 => 416780));
        $entries = array(); foreach ($prices as $format => $quantities) { foreach ($quantities as $quantity => $total) { $entries[] = array('values' => array('formato' => $format, 'cantidad' => (string) $quantity), 'quantity' => $quantity, 'total' => $total); } }
        return self::fixed_config($fields, $entries);
    }

    private static function sync_categories() {
        $structure = array(
            'imprenta-offset' => array('name' => 'Imprenta offset', 'parent' => 0),
            'talonarios-formularios' => array('name' => 'Talonarios y formularios', 'parent' => 'imprenta-offset', 'description' => 'Talonarios AFIP, tickets, comandas, presupuestos y anotadores.'),
            'tarjetas-etiquetas' => array('name' => 'Tarjetas y etiquetas', 'parent' => 'imprenta-offset', 'description' => 'Tarjetas personales y etiquetas impresas con distintas terminaciones.'),
            'volantes' => array('name' => 'Volantes', 'parent' => 'imprenta-offset', 'description' => 'Volantes blanco y negro o full color en diferentes medidas y cantidades.'),
            'imanes-publicitarios' => array('name' => 'Imanes publicitarios', 'parent' => 'imprenta-offset', 'description' => 'Imanes personalizados para promociones y comunicación de marca.'),
        );
        $ids = array();

        foreach ($structure as $slug => $definition) {
            $parent_id = is_string($definition['parent']) ? $ids[$definition['parent']] : 0;
            $term = get_term_by('slug', $slug, 'product_cat');
            $args = array('slug' => $slug, 'parent' => $parent_id);
            if (! empty($definition['description'])) {
                $args['description'] = $definition['description'];
            }
            if (! $term) {
                $result = wp_insert_term($definition['name'], 'product_cat', $args);
                if (is_wp_error($result)) {
                    return $result;
                }
                $ids[$slug] = (int) $result['term_id'];
            } else {
                wp_update_term($term->term_id, 'product_cat', $args);
                $ids[$slug] = (int) $term->term_id;
            }
        }

        return $ids;
    }

    private static function build_attributes($definitions) {
        $attributes = array();
        $position = 0;
        foreach ($definitions as $name => $options) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(0);
            $attribute->set_name($name);
            $attribute->set_options(array_values($options));
            $attribute->set_position($position++);
            $attribute->set_visible(true);
            $attribute->set_variation(false);
            $attributes[] = $attribute;
        }
        return $attributes;
    }
}
