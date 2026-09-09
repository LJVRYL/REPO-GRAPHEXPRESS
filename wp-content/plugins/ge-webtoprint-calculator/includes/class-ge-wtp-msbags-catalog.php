<?php

defined('ABSPATH') || exit;

/**
 * Catálogo público de bolsas producido por MS Bags.
 *
 * Los importes de source_price son precios públicos relevados del proveedor.
 * El precio Graph Express se calcula siempre con el margen centralizado.
 */
final class GE_WTP_MSBags_Catalog {
    const SOURCE_NAME = 'MS Bags';
    const SOURCE_URL = 'https://msbags.com.ar/';
    const SOURCE_DATE = '2026-09-09';
    const MARGIN = 50;

    public static function groups() {
        return array(
            'bolsas-friselina' => array('name' => 'Bolsas de friselina', 'description' => 'Bolsas reutilizables lisas o personalizadas para comercios, eventos y entregas.'),
            'bolsas-ecommerce' => array('name' => 'Bolsas e-commerce', 'description' => 'Bolsas resistentes para despachos, envíos y packaging de tiendas online.'),
            'bolsas-lienzo' => array('name' => 'Bolsas de lienzo', 'description' => 'Bolsas textiles reutilizables, lisas o personalizadas.'),
        );
    }

    private static function products() {
        $friselina_notes = array(
            'Pedido mínimo: 100 unidades y luego múltiplos de 100.',
            'La opción estampada incluye impresión de hasta 6 colores.',
            'Las medidas pueden variar ± 1 cm.',
            'Producción estimada: 7 a 10 días hábiles desde la aprobación.',
            'Valores Graph Express antes de IVA.',
        );
        $standard_notes = array(
            'Pedido mínimo: 100 unidades y luego múltiplos de 100.',
            'Producción estimada: 7 a 10 días hábiles desde la aprobación.',
            'Valores Graph Express antes de IVA.',
        );
        return array(
            'lienzo-35x40' => array(
                'sku' => 'MSB-LIE-3540', 'name' => 'Bolsa de lienzo 35 × 40 cm', 'group' => 'bolsas-lienzo',
                'description' => 'Bolsa de lienzo reutilizable con manijas largas reforzadas, disponible lisa o personalizada.',
                'url' => 'https://msbags.com.ar/producto/bolsa-35x40-lienzo/', 'image' => 'https://msbags.com.ar/wp/wp-content/uploads/2026/04/bolsa-lienzo-35x40-lisa.jpg',
                'min' => 10, 'step' => 10,
                'notes' => array('Pedido mínimo: 10 unidades y luego múltiplos de 10.', 'Producción estimada: 7 a 10 días hábiles desde la aprobación.', 'Valores Graph Express antes de IVA.'),
                'options' => array(array('Natural · Lisa', 2500), array('Natural · Estampado A4', 4500)),
            ),
            'friselina-auto' => array(
                'sku' => 'MSB-FRI-AUTO', 'name' => 'Bolsa de friselina para auto', 'group' => 'bolsas-friselina',
                'description' => 'Bolsa compacta para colgar dentro del vehículo, útil como organizador o contenedor de pequeños residuos.',
                'url' => 'https://msbags.com.ar/producto/bolsa-friselina-para-auto/', 'image' => 'https://msbags.com.ar/wp/wp-content/uploads/2026/04/auto.webp',
                'min' => 100, 'step' => 100, 'notes' => $friselina_notes,
                'options' => array(array('Blanca · Lisa', 270.50), array('Blanca · Estampado A4', 405.50)),
            ),
            'friselina-60x40' => self::friselina('MSB-FRI-6040', 'Bolsa de friselina 60 × 40 × 10 cm', '60x40', 'https://msbags.com.ar/wp/wp-content/uploads/2026/04/40x60.webp', $friselina_notes, array(
                array('Negra · Lisa', 470.50), array('Negra · Estampado A4', 900.50), array('Natural · Lisa', 470.50), array('Natural · Estampado A4', 700.50), array('Blanca · Lisa', 470.50), array('Blanca · Estampado A4', 700.50),
            ), 'Productos grandes, textiles, cajas, regalos empresariales y entregas de gran volumen.'),
            'friselina-20x40' => self::friselina('MSB-FRI-2040', 'Bolsa de friselina 20 × 40 × 10 cm', '20x40', 'https://msbags.com.ar/wp/wp-content/uploads/2026/04/40x20.webp', $friselina_notes, array(
                array('Negra · Lisa', 290.50), array('Negra · Estampado A4', 610.50), array('Natural · Lisa', 290.50), array('Natural · Estampado A4', 410.50), array('Blanca · Lisa', 290.50), array('Blanca · Estampado A4', 410.50),
            ), 'Formato alto para botellas, vinos y productos alargados.'),
            'friselina-15x20' => self::friselina('MSB-FRI-1520', 'Bolsa de friselina 15 × 20 × 10 cm', '15x20', 'https://msbags.com.ar/wp/wp-content/uploads/2026/04/20x15.webp', $friselina_notes, array(
                array('Negra · Lisa', 215.50), array('Negra · Estampado A4', 590.50), array('Natural · Lisa', 215.50), array('Natural · Estampado A4', 390.50), array('Blanca · Lisa', 215.50), array('Blanca · Estampado A4', 390.50),
            ), 'Formato compacto para accesorios, cosmética, velas, bijouterie y souvenirs.'),
            'ecommerce-50x70' => self::ecommerce('MSB-ECO-5070', 'Bolsa e-commerce 50 × 70 cm', '50x70', 'https://msbags.com.ar/wp/wp-content/uploads/2026/04/bolsa-e-commerce-50x70-2.webp', $standard_notes, 790, 'Productos grandes o voluminosos, indumentaria, textiles y ropa de cama.'),
            'ecommerce-42x54' => self::ecommerce('MSB-ECO-4254', 'Bolsa e-commerce 42 × 54 cm', '42x54', 'https://msbags.com.ar/wp/wp-content/uploads/2026/04/bolsa-e-commerce-42x54-2.webp', $standard_notes, 750, 'Indumentaria, textiles, calzado liviano y artículos medianos o grandes.'),
            'ecommerce-30x45' => self::ecommerce('MSB-ECO-3045', 'Bolsa e-commerce 30 × 45 cm', '30x45', 'https://msbags.com.ar/wp/wp-content/uploads/2026/04/bolsa-e-commerce-30x45-2.webp', $standard_notes, 650, 'Indumentaria, accesorios, belleza, papelería y productos medianos.'),
            'ecommerce-20x32' => self::ecommerce('MSB-ECO-2032', 'Bolsa e-commerce 20 × 32 cm', '20x32', 'https://msbags.com.ar/wp/wp-content/uploads/2026/04/bolsa-e-commerce-20x32-2.webp', $standard_notes, 595, 'Accesorios, cosmética, papelería y otros artículos compactos.'),
            'friselina-30x40' => self::friselina('MSB-FRI-3040', 'Bolsa de friselina 30 × 40 × 10 cm', '30x40', 'https://msbags.com.ar/wp/wp-content/uploads/2021/04/Medida-30x40_galeria.jpg', $friselina_notes, array(
                array('Negra · Lisa', 355.50), array('Negra · Estampado A4', 695.50), array('Natural · Lisa', 355.50), array('Natural · Estampado A4', 495.50), array('Natural · Estampado A3', 545.50), array('Blanca · Lisa', 355.50), array('Blanca · Estampado A4', 495.50), array('Blanca · Estampado A3', 545.50),
            )),
            'friselina-20x30' => self::friselina('MSB-FRI-2030', 'Bolsa de friselina 20 × 30 × 10 cm', '20x30', 'https://msbags.com.ar/wp/wp-content/uploads/2021/04/Medida-20x30_galeria.jpg', $friselina_notes, array(
                array('Negra · Lisa', 270.50), array('Negra · Estampado A4', 600.50), array('Natural · Lisa', 270.50), array('Natural · Estampado A4', 400.50), array('Blanca · Lisa', 270.50), array('Blanca · Estampado A4', 400.50),
            )),
            'friselina-50x40' => self::friselina('MSB-FRI-5040', 'Bolsa de friselina 50 × 40 × 10 cm', '50x40', 'https://msbags.com.ar/wp/wp-content/uploads/2021/04/Medida-50x40_galeria.jpg', $friselina_notes, array(
                array('Negra · Lisa', 440.50), array('Negra · Estampado A4', 800.50), array('Natural · Lisa', 440.50), array('Natural · Estampado A4', 600.50), array('Natural · Estampado A3', 650.50), array('Blanca · Lisa', 440.50), array('Blanca · Estampado A4', 600.50), array('Blanca · Estampado A3', 650.50),
            )),
            'friselina-45x40' => self::friselina('MSB-FRI-4540', 'Bolsa de friselina 45 × 40 × 10 cm', '45x40', 'https://msbags.com.ar/wp/wp-content/uploads/2021/04/Medida-45x40_galeria.jpg', $friselina_notes, array(
                array('Negra · Lisa', 400.50), array('Negra · Estampado A4', 780.50), array('Natural · Lisa', 400.50), array('Natural · Estampado A4', 580.50), array('Natural · Estampado A3', 630.50), array('Blanca · Lisa', 400.50), array('Blanca · Estampado A4', 580.50), array('Blanca · Estampado A3', 630.50),
            )),
            'friselina-40x40' => self::friselina('MSB-FRI-4040', 'Bolsa de friselina 40 × 40 × 10 cm', '40x40', 'https://msbags.com.ar/wp/wp-content/uploads/2021/04/Medida-40x40-ppal.jpg', $friselina_notes, array(
                array('Negra · Lisa', 390.50), array('Negra · Estampado A4', 750.50), array('Natural · Lisa', 390.50), array('Natural · Estampado A4', 550.50), array('Natural · Estampado A3', 600.50), array('Blanca · Lisa', 390.50), array('Blanca · Estampado A4', 550.50), array('Blanca · Estampado A3', 600.50),
            )),
        );
    }

    private static function friselina($sku, $name, $source_slug, $image, $notes, $options, $use = '') {
        return array(
            'sku' => $sku, 'name' => $name, 'group' => 'bolsas-friselina',
            'description' => 'Bolsa reutilizable de friselina, disponible lisa o personalizada.' . ($use ? ' Recomendada para ' . lcfirst($use) : ''),
            'url' => 'https://msbags.com.ar/producto/bolsa-' . $source_slug . '/', 'image' => $image,
            'min' => 100, 'step' => 100, 'notes' => $notes, 'options' => $options,
        );
    }

    private static function ecommerce($sku, $name, $source_slug, $image, $notes, $price, $use) {
        return array(
            'sku' => $sku, 'name' => $name, 'group' => 'bolsas-ecommerce',
            'description' => 'Bolsa negra resistente para envíos de e-commerce. Recomendada para ' . lcfirst($use),
            'url' => 'https://msbags.com.ar/producto/bolsa-e-commerce-' . $source_slug . '/', 'image' => $image,
            'min' => 100, 'step' => 100, 'notes' => $notes, 'options' => array(array('Negra · ' . str_replace('x', ' × ', $source_slug) . ' cm', $price)),
        );
    }

    private static function sale_price($source_price) {
        return round((float) $source_price * (1 + self::MARGIN / 100), 2);
    }

    private static function public_sections($product) {
        $rows = array();
        foreach ($product['options'] as $option) {
            $rows[] = array($option[0], '$ ' . number_format(self::sale_price($option[1]), 2, ',', '.'));
        }
        return array(array('title' => '', 'columns' => array('Color y terminación', 'Precio unitario'), 'rows' => $rows));
    }

    public static function sync() {
        if (!class_exists('WC_Product_Simple')) {
            return new WP_Error('woocommerce_required', 'WooCommerce debe estar activo para sincronizar el catálogo.');
        }
        $products = self::products();
        self::retire_removed_products(array_keys($products));
        self::retire_removed_groups();
        $category_ids = self::sync_categories();
        if (is_wp_error($category_ids)) { return $category_ids; }
        $created = 0; $updated = 0; $position = 500;
        foreach ($products as $key => $data) {
            $existing = get_posts(array('post_type' => 'product', 'post_status' => array('publish', 'draft', 'private'), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_ge_public_catalog_key', 'meta_value' => 'msbags-' . $key));
            $is_new = empty($existing);
            $product = $is_new ? new WC_Product_Simple() : wc_get_product($existing[0]);
            if (!$product) { continue; }
            $sale_prices = array_map(function ($option) { return self::sale_price($option[1]); }, $data['options']);
            $product->set_name($data['name']);
            $product->set_slug('bolsas-' . $key);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_description($data['description']);
            $product->set_short_description($data['description']);
            if ($product->get_sku() !== $data['sku']) { $product->set_sku($data['sku']); }
            $product->set_regular_price('');
            $product->set_sale_price('');
            $product->set_category_ids(array($category_ids['bolsas'], $category_ids[$data['group']]));
            $product->set_menu_order($position++);
            $product->set_reviews_allowed(false);
            $product->set_attributes(self::build_attributes(array('Color y terminación' => array_column($data['options'], 0))));
            $product_id = $product->save();
            update_post_meta($product_id, '_ge_public_catalog_key', 'msbags-' . $key);
            delete_post_meta($product_id, '_ge_quote_only');
            update_post_meta($product_id, '_ge_show_reference_price', 'yes');
            update_post_meta($product_id, '_ge_reference_price_min', min($sale_prices));
            update_post_meta($product_id, '_ge_public_price_sections', self::public_sections($data));
            update_post_meta($product_id, '_ge_public_price_notes', $data['notes']);
            update_post_meta($product_id, '_ge_option_label', 'Color y terminación');
            update_post_meta($product_id, '_ge_minimum_quantity', (int) $data['min']);
            update_post_meta($product_id, '_ge_quantity_step', (int) $data['step']);
            delete_post_meta($product_id, '_ge_supplier_costs');
            update_post_meta($product_id, '_ge_supplier_margin', self::MARGIN);
            update_post_meta($product_id, '_ge_supplier_source', self::SOURCE_NAME);
            update_post_meta($product_id, '_ge_supplier_source_url', $data['url']);
            update_post_meta($product_id, '_ge_supplier_source_date', self::SOURCE_DATE);
            if (!$product->get_image_id()) { self::sideload_image($data['image'], $product_id, $data['name']); }
            $is_new ? $created++ : $updated++;
        }
        clean_term_cache(array_values($category_ids), 'product_cat');
        return array('created' => $created, 'updated' => $updated, 'total' => $created + $updated);
    }

    /**
     * Retira de forma reversible productos que dejaron de formar parte del
     * catálogo comercial. Se conservan como borrador para no perder su ficha.
     */
    private static function retire_removed_products($active_keys) {
        $active_catalog_keys = array_map(function ($key) { return 'msbags-' . $key; }, $active_keys);
        $existing = get_posts(array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'private'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_ge_supplier_source',
            'meta_value' => self::SOURCE_NAME,
        ));
        foreach ($existing as $product_id) {
            if (in_array(get_post_meta($product_id, '_ge_public_catalog_key', true), $active_catalog_keys, true)) { continue; }
            $product = wc_get_product($product_id);
            if (!$product) { continue; }
            $product->set_status('draft');
            $product->set_catalog_visibility('hidden');
            $product->save();
        }
    }

    private static function retire_removed_groups() {
        foreach (array('bolsas-consorcio') as $slug) {
            $term = get_term_by('slug', $slug, 'product_cat');
            if ($term && !is_wp_error($term)) {
                wp_delete_term((int) $term->term_id, 'product_cat');
            }
        }
    }

    private static function sync_categories() {
        $parent = get_term_by('slug', 'bolsas', 'product_cat');
        if (!$parent) {
            $inserted = wp_insert_term('Bolsas', 'product_cat', array('slug' => 'bolsas', 'description' => 'Bolsas textiles, para e-commerce y packaging.'));
            if (is_wp_error($inserted)) { return $inserted; }
            $parent_id = (int) $inserted['term_id'];
        } else {
            $parent_id = (int) $parent->term_id;
            wp_update_term($parent_id, 'product_cat', array('parent' => 0, 'description' => 'Bolsas textiles, para e-commerce y packaging.'));
        }
        $ids = array('bolsas' => $parent_id);
        foreach (self::groups() as $slug => $group) {
            $term = get_term_by('slug', $slug, 'product_cat');
            $args = array('slug' => $slug, 'parent' => $parent_id, 'description' => $group['description']);
            if (!$term) {
                $inserted = wp_insert_term($group['name'], 'product_cat', $args);
                if (is_wp_error($inserted)) { return $inserted; }
                $ids[$slug] = (int) $inserted['term_id'];
            } else {
                wp_update_term($term->term_id, 'product_cat', $args);
                $ids[$slug] = (int) $term->term_id;
            }
        }
        return $ids;
    }

    private static function sideload_image($url, $product_id, $title) {
        if ('msbags.com.ar' !== strtolower((string) wp_parse_url($url, PHP_URL_HOST))) { return; }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_sideload_image(esc_url_raw($url), $product_id, $title, 'id');
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($product_id, (int) $attachment_id);
            update_post_meta((int) $attachment_id, '_ge_supplier_image_source', esc_url_raw($url));
        }
    }

    private static function build_attributes($definitions) {
        $attributes = array(); $position = 0;
        foreach ($definitions as $name => $options) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(0); $attribute->set_name($name); $attribute->set_options(array_values($options));
            $attribute->set_position($position++); $attribute->set_visible(true); $attribute->set_variation(false);
            $attributes[] = $attribute;
        }
        return $attributes;
    }
}
