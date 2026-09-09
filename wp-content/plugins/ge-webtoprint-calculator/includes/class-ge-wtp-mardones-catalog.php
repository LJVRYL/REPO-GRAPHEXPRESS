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

    private static function item($sku, $name, $category, $group, $description, $attributes, $sections, $notes, $minimum) {
        return compact('sku', 'name', 'category', 'group', 'description', 'attributes', 'sections', 'notes', 'minimum');
    }

    private static function section($title, $columns, $rows) {
        return compact('title', 'columns', 'rows');
    }

    public static function products() {
        return array(
            'ticket-lavadero' => self::item(
                'ID-TAL-001',
                'Tickets de lavadero',
                'imprenta-offset',
                'talonarios-formularios',
                'Tickets numerados y abrochados para lavaderos, controles de ingreso y servicios.',
                array(
                    'Medida' => array('11 × 22 cm'),
                    'Presentación' => array('Talonarios de 100 hojas'),
                    'Papel' => array('Obra blanco 70 g'),
                    'Incluye' => array('Numerado', 'Hasta 3 puntillados', 'Abrochado'),
                ),
                array(self::section('Tickets de lavadero 11 × 22 cm', array('Cantidad', 'Precio'), array(
                    array('15', '$ 41.080'), array('30', '$ 72.150'), array('50', '$ 111.670'), array('100', '$ 207.090'),
                ))),
                array('Dorso: 50% adicional.', 'Papel de color: 80% adicional.', 'No incluye diseño.', 'Valores + IVA 21%.'),
                41080
            ),
            'talonarios-afip' => self::item(
                'ID-TAL-002',
                'Talonarios AFIP',
                'imprenta-offset',
                'talonarios-formularios',
                'Talonarios fiscales personalizados para comprobantes A, B, C, M, R y X.',
                array(
                    'Medida estándar' => array('17 × 22 cm'),
                    'Tipos' => array('A', 'B', 'C', 'M', 'R', 'X'),
                    'Copias' => array('Duplicado', 'Triplicado', 'Cuadruplicado'),
                    'Numeración' => array('25 números por talonario en triplicado'),
                ),
                array(self::section('Talonarios AFIP 17 × 22 cm', array('Cantidad', 'Precio'), array(
                    array('1', '$ 6.500'), array('2', '$ 11.700'), array('3', '$ 16.510'), array('4', '$ 20.150'), array('5', '$ 23.660'),
                    array('6', '$ 25.740'), array('8', '$ 34.060'), array('10', '$ 41.990'), array('20', '$ 81.770'), array('Más de 20', 'Cotizar'),
                ))),
                array('Triplicado sin cargo.', 'Cuadruplicado: 40% de recargo.', 'Para otras medidas, solicitar cotización.', 'No incluye diseño.', 'Valores + IVA 21%.'),
                6500
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
                'Tarjetas personales full color sobre ilustración mate con laca UV brillante.',
                array('Medida' => array('9 × 5 cm'), 'Cantidad' => array('1.000 unidades'), 'Impresión' => array('4/1', '4/4'), 'Papel' => array('Ilustración mate 350 g'), 'Terminación' => array('Laca UV brillante')),
                array(self::section('Tarjetas 9 × 5 cm', array('Impresión', '1.000 unidades'), array(array('4/1', '$ 32.760'), array('4/4', '$ 32.760')))),
                array('Redondeado de puntas para 1.000 unidades: $ 3.250', 'Demora estimada: 3 a 7 días.', 'Valores + IVA 21%.'),
                32760
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
            update_post_meta($product_id, '_ge_supplier_source', self::SOURCE_NAME);
            update_post_meta($product_id, '_ge_supplier_source_date', self::SOURCE_DATE);
            update_post_meta($product_id, '_ge_supplier_source_files', array(
                'WhatsApp Image 2026-09-02 at 4.22.48 PM.jpeg',
                'WhatsApp Image 2026-09-02 at 4.22.49 PM (1).jpeg',
                'WhatsApp Image 2026-09-02 at 4.22.50 PM (1).jpeg',
                'WhatsApp Image 2026-09-02 at 4.22.50 PM.jpeg',
            ));

            $is_new ? $created++ : $updated++;
        }

        clean_term_cache(array_values($category_ids), 'product_cat');
        return array('created' => $created, 'updated' => $updated, 'total' => $created + $updated);
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
