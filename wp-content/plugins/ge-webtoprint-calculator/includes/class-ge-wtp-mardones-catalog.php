<?php

defined('ABSPATH') || exit;

/**
 * Catálogo inicial del proveedor Mardones / Sur Colors.
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
                    array('15', '$ 31.600'), array('30', '$ 55.500'), array('50', '$ 85.900'), array('100', '$ 159.300'),
                ))),
                array('Dorso: 50% adicional.', 'Papel de color: 80% adicional.', 'No incluye diseño.', 'Valores + IVA 21%.'),
                31600
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
                    array('1', '$ 5.000'), array('2', '$ 9.000'), array('3', '$ 12.700'), array('4', '$ 15.500'), array('5', '$ 18.200'),
                    array('6', '$ 19.800'), array('8', '$ 26.200'), array('10', '$ 32.300'), array('20', '$ 62.900'), array('Más de 20', 'Cotizar'),
                ))),
                array('Triplicado sin cargo.', 'Cuadruplicado: 40% de recargo.', 'Para otras medidas, solicitar cotización.', 'No incluye diseño.', 'Valores + IVA 21%.'),
                5000
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
                        array('11 × 11 cm · 20', '$ 22.600'), array('11 × 11 cm · 50', '$ 35.200'), array('11 × 11 cm · 100', '$ 62.300'), array('11 × 11 cm · 150', '$ 85.500'), array('11 × 11 cm · 300', '$ 168.500'),
                        array('11 × 17 cm · 10', '$ 15.900'), array('11 × 17 cm · 20', '$ 24.400'), array('11 × 17 cm · 50', '$ 47.000'), array('11 × 17 cm · 100', '$ 81.600'), array('11 × 17 cm · 200', '$ 160.600'),
                        array('10 × 10 cm · 20', '$ 20.000'), array('10 × 10 cm · 50', '$ 29.200'), array('10 × 10 cm · 100', '$ 50.900'), array('10 × 10 cm · 150', '$ 67.800'), array('10 × 10 cm · 300', '$ 128.100'),
                        array('10 × 15 cm · 10', '$ 14.000'), array('10 × 15 cm · 20', '$ 21.500'), array('10 × 15 cm · 50', '$ 39.000'), array('10 × 15 cm · 100', '$ 66.200'), array('10 × 15 cm · 200', '$ 129.100'),
                        array('11 × 22 cm · 10', '$ 21.800'), array('11 × 22 cm · 20', '$ 34.200'), array('11 × 22 cm · 50', '$ 62.000'), array('11 × 22 cm · 100', '$ 116.400'), array('11 × 22 cm · 200', '$ 230.000'),
                        array('17 × 22 cm · 5', '$ 15.900'), array('17 × 22 cm · 10', '$ 24.400'), array('17 × 22 cm · 20', '$ 41.500'), array('17 × 22 cm · 50', '$ 80.900'), array('17 × 22 cm · 100', '$ 159.400'),
                        array('10 × 20 cm · 10', '$ 18.300'), array('10 × 20 cm · 20', '$ 28.400'), array('10 × 20 cm · 50', '$ 56.700'), array('10 × 20 cm · 100', '$ 94.400'), array('10 × 20 cm · 200', '$ 186.000'),
                        array('15 × 20 cm · 5', '$ 14.000'), array('15 × 20 cm · 10', '$ 21.500'), array('15 × 20 cm · 20', '$ 34.400'), array('15 × 20 cm · 50', '$ 65.800'), array('15 × 20 cm · 100', '$ 128.300'),
                    )),
                    self::section('Duplicado, numerado y abrochado', array('Medida / Cantidad', 'Precio'), array(
                        array('11 × 17 cm · 20', '$ 32.700'), array('11 × 17 cm · 50', '$ 73.000'), array('11 × 17 cm · 100', '$ 127.000'), array('11 × 17 cm · 200', '$ 248.300'),
                        array('11 × 22 cm · 12', '$ 26.200'), array('11 × 22 cm · 21', '$ 44.500'), array('11 × 22 cm · 51', '$ 91.700'), array('11 × 22 cm · 102', '$ 165.000'),
                    )),
                    self::section('Full color - 100 hojas por block', array('Medida / Cantidad', 'Precio'), array(
                        array('10 × 10 cm · 20', '$ 32.400'), array('10 × 10 cm · 40', '$ 53.800'), array('10 × 10 cm · 80', '$ 107.600'), array('10 × 10 cm · 200', '$ 268.400'),
                        array('11 × 11 cm · 20', '$ 41.800'), array('11 × 11 cm · 40', '$ 69.600'), array('11 × 11 cm · 80', '$ 139.000'), array('11 × 11 cm · 200', '$ 346.400'),
                        array('10 × 15 cm · 10', '$ 23.000'), array('10 × 15 cm · 20', '$ 38.200'), array('10 × 15 cm · 40', '$ 76.200'), array('10 × 15 cm · 100', '$ 189.800'),
                        array('11 × 17 cm · 10', '$ 29.700'), array('11 × 17 cm · 20', '$ 49.300'), array('11 × 17 cm · 40', '$ 98.300'), array('11 × 17 cm · 100', '$ 244.800'),
                        array('10 × 20 cm · 10', '$ 29.700'), array('10 × 20 cm · 20', '$ 49.300'), array('10 × 20 cm · 40', '$ 98.300'), array('10 × 20 cm · 100', '$ 244.800'),
                        array('11 × 22 cm · 10', '$ 38.400'), array('11 × 22 cm · 20', '$ 63.700'), array('11 × 22 cm · 40', '$ 126.900'), array('11 × 22 cm · 100', '$ 316.000'),
                        array('15 × 20 cm · 10', '$ 38.200'), array('15 × 20 cm · 20', '$ 76.200'), array('15 × 20 cm · 40', '$ 151.800'), array('15 × 20 cm · 100', '$ 376.800'),
                        array('17 × 22 cm · 10', '$ 49.300'), array('17 × 22 cm · 20', '$ 98.700'), array('17 × 22 cm · 40', '$ 196.000'), array('17 × 22 cm · 100', '$ 486.100'),
                    )),
                ),
                array('Blanco y negro simple: sin duplicado y sin numerar.', 'Tinta de color en pedidos B/N: x20 + $2.200, x50 + $3.300, x100 + $5.000.', 'Duplicado: original blanco, copia color e impresión negra.', 'Full color: impresión offset 4/0, sin numerado ni duplicado, demora estimada 10 a 15 días.', 'Valores + IVA 21%.'),
                14000
            ),
            'tarjetas-personales' => self::item(
                'ID-TAR-001',
                'Tarjetas personales',
                'imprenta-offset',
                'tarjetas-etiquetas',
                'Tarjetas personales full color sobre ilustración mate con laca UV brillante.',
                array('Medida' => array('9 × 5 cm'), 'Cantidad' => array('1.000 unidades'), 'Impresión' => array('4/1', '4/4'), 'Papel' => array('Ilustración mate 350 g'), 'Terminación' => array('Laca UV brillante')),
                array(self::section('Tarjetas 9 × 5 cm', array('Impresión', '1.000 unidades'), array(array('4/1', '$ 25.200'), array('4/4', '$ 25.200')))),
                array('Redondeado de puntas para 1.000 unidades: $ 2.500.', 'Demora estimada: 3 a 7 días.', 'Valores + IVA 21%.'),
                25200
            ),
            'etiquetas' => self::item(
                'ID-ETI-001',
                'Etiquetas impresas',
                'imprenta-offset',
                'tarjetas-etiquetas',
                'Etiquetas full color laqueadas para productos, packaging e identificación.',
                array('Medidas' => array('4,5 × 5 cm', '2,5 × 9 cm', '3 × 9 cm', '5 × 9 cm', '9 × 4 cm'), 'Papel' => array('Ilustración mate 350 g'), 'Impresión' => array('Frente full color'), 'Terminación' => array('Laqueado brillante', 'Dorso blanco y negro')),
                array(self::section('Etiquetas', array('Medida / Cantidad', 'Precio'), array(
                    array('4,5 × 5 o 2,5 × 9 cm · 2.000', '$ 25.200'), array('4,5 × 5 o 2,5 × 9 cm · 6.000', '$ 75.600'),
                    array('3 × 9 cm · 3.000', '$ 50.400'), array('3 × 9 cm · 6.000', '$ 100.800'),
                    array('5 × 9 cm · 1.000', '$ 25.200'), array('5 × 9 cm · 2.000', '$ 50.400'), array('5 × 9 cm · 3.000', '$ 75.600'), array('5 × 9 cm · 6.000', '$ 151.200'),
                    array('9 × 4 cm · 5.000', '$ 100.800'), array('9 × 4 cm · 10.000', '$ 201.600'),
                ))),
                array('Agujereado: x1.000 $2.400; x3.000 $7.000; x6.000 $13.400.', 'Redondeado de puntas: x1.000 $2.500; x3.000 $7.200; x6.000 $14.000.', 'Demora estimada: 3 a 7 días.', 'Valores + IVA 21%.'),
                25200
            ),
            'volantes-blanco-negro' => self::item(
                'IO-VOL-001',
                'Volantes blanco y negro',
                'imprenta-offset',
                'volantes',
                'Volantes económicos impresos en negro para comunicación masiva, promociones e información.',
                array('Medidas' => array('7 × 10 cm', '10 × 10 cm', '10 × 15 cm', '10 × 20 cm', '15 × 20 cm', '20 × 30 cm'), 'Cantidades' => array('1.000', '2.000', '5.000', '10.000'), 'Papel' => array('Obra blanco 70 g'), 'Impresión' => array('Blanco y negro')),
                array(self::section('Volantes blanco y negro', array('Medida', '1.000', '2.000', '5.000', '10.000'), array(
                    array('7 × 10 cm', '$ 9.000', '$ 14.700', '$ 25.700', '$ 43.300'),
                    array('10 × 10 cm', '$ 10.400', '$ 15.800', '$ 27.700', '$ 48.500'),
                    array('10 × 15 cm', '$ 11.000', '$ 18.500', '$ 37.500', '$ 60.000'),
                    array('10 × 20 cm', '$ 14.400', '$ 22.700', '$ 47.200', '$ 88.400'),
                    array('15 × 20 cm', '$ 16.800', '$ 29.200', '$ 60.000', '$ 112.000'),
                    array('20 × 30 cm', '$ 28.800', '$ 53.000', '$ 117.100', '$ 220.200'),
                ))),
                array('Dorso: 50% adicional.', 'Papel de color: 100% adicional.', 'Impresión en magenta, azul o rojo: 15% de recargo.', 'No incluye diseño.', 'Valores + IVA 21%.'),
                9000
            ),
            'volantes-full-color' => self::item(
                'IO-VOL-002',
                'Volantes full color',
                'imprenta-offset',
                'volantes',
                'Volantes a todo color frente y dorso para campañas, promociones y comunicación institucional.',
                array('Medidas' => array('10 × 15 cm', '20 × 15 cm', '30 × 15 cm', '20 × 30 cm'), 'Cantidades' => array('1.000', '5.000'), 'Papel' => array('Ilustración 115 g'), 'Impresión' => array('Frente y dorso full color')),
                array(self::section('Volantes full color', array('Medida', '1.000', '5.000'), array(
                    array('10 × 15 cm', '$ 29.900', '$ 79.900'), array('20 × 15 cm', '$ 59.800', '$ 159.800'),
                    array('30 × 15 cm', '$ 89.700', '$ 239.700'), array('20 × 30 cm', '$ 119.600', '$ 319.600'),
                ))),
                array('Demora estimada: 5 a 10 días.', 'Valores + IVA 21%.'),
                29900
            ),
            'imanes-publicitarios' => self::item(
                'ME-IMA-001',
                'Imanes publicitarios',
                'imprenta-offset',
                'imanes-publicitarios',
                'Imanes personalizados a todo color para promociones, contactos y recordatorios de marca.',
                array('Medidas' => array('6 × 4 cm', '7 × 5 cm', '8 × 6 cm', '10 × 7 cm'), 'Cantidades' => array('500', '1.000'), 'Base' => array('Cartulina 300 g'), 'Terminación' => array('Laminado brillante', 'Puntas redondeadas')),
                array(self::section('Imanes full color', array('Medida', '500', '1.000'), array(
                    array('6 × 4 cm', '$ 74.600', '$ 125.000'), array('7 × 5 cm', '$ 98.600', '$ 164.500'),
                    array('8 × 6 cm', '$ 140.700', '$ 235.000'), array('10 × 7 cm', '$ 191.700', '$ 320.600'),
                ))),
                array('Impresión full color.', 'Demora estimada: 12 a 20 días.', 'Valores + IVA 21%.'),
                74600
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
