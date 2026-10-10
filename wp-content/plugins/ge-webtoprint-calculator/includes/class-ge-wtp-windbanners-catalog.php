<?php

defined('ABSPATH') || exit;

/**
 * Catálogo normalizado de productos promocionales y de exhibición.
 *
 * El archivo distribuible contiene únicamente precios públicos Graph Express.
 * Los costos internos del proveedor nunca forman parte del plugin publicado.
 */
final class GE_WTP_Windbanners_Catalog {
    const SOURCE_NAME = 'Grupo Wind Banners';
    const SOURCE_URL = 'https://grupowindbanners.com.ar/';
    const SOURCE_DATE = '2026-10-10';
    const IMAGE_VERSION = '2026-09-09-clean-v1';

    public static function groups() {
        return array(
            'windbanners-banderas' => array('name' => 'Windbanners y banderas', 'description' => 'Banderas sublimadas y fly banners para interior y exterior.'),
            'bases-windbanners' => array('name' => 'Bases y accesorios para banderas', 'description' => 'Bases y estacas compatibles con distintos tamaños de windbanner.'),
            'carpas-gazebos' => array('name' => 'Carpas y gazebos', 'description' => 'Carpas promocionales, gazebos y repuestos personalizados.'),
            'popup-exhibicion' => array('name' => 'Popup banners y exhibición', 'description' => 'Vallas, tótems, arcos, mostradores y sistemas popup.'),
            'stands-backdrops' => array('name' => 'Stands, backdrops y wall banners', 'description' => 'Fondos y estructuras para eventos, activaciones y puntos de venta.'),
            'promocionales-textiles' => array('name' => 'Promocionales textiles', 'description' => 'Cintas, llaveros y mobiliario textil personalizado.'),
        );
    }

    private static function definitions() {
        $before_vat = 'Precio unitario neto. El tratamiento de IVA depende del emisor del comprobante. En comprobantes C no se agrega IVA.';
        return array(
            'banderas-sublimadas' => array('WB-BAN-001', 'Banderas sublimadas', 'windbanners-banderas', 'Banderas textiles full color para frentes, mástiles, eventos y comunicación institucional.', array('Impresión por sublimación full color.', $before_vat)),
            'bases-estacas' => array('WB-BAS-001', 'Bases y estacas para fly banners', 'bases-windbanners', 'Accesorios para instalar windbanners sobre pisos duros, tierra, arena o pasto.', array('El sobrepeso se entrega vacío.', 'Las estacas de 40 cm se recomiendan hasta 3,20 m y las de 60 cm para alturas mayores.', $before_vat)),
            'carpas-estrella' => array('WB-CAR-001', 'Carpas estrella personalizadas', 'carpas-gazebos', 'Carpas de alto impacto visual confeccionadas en cordura impermeable e impresas full color.', array('Cordura impermeable e impresión full color.', $before_vat)),
            'gazebos-completos' => array('WB-CAR-002', 'Gazebos personalizados completos', 'carpas-gazebos', 'Gazebos plegables con estructura y techo impreso, disponibles también con paredes personalizadas.', array('Estructura de hierro o aluminio según disponibilidad.', 'Techo impermeable y paredes impresas full color.', $before_vat)),
            'gazebo-repuestos' => array('WB-CAR-003', 'Techos y paredes para gazebos', 'carpas-gazebos', 'Repuestos impresos para actualizar o completar estructuras de gazebo existentes.', array('Confirmamos medida y compatibilidad de la estructura antes de producir.', $before_vat)),
            'fly-cube' => array('WB-FLY-001', 'Fly banner Cube', 'windbanners-banderas', 'Bandera promocional sublimada con estructura liviana y bolso de transporte.', array('Disponible simple o doble faz según el tamaño.', 'El precio no incluye base.', 'Plazo de producción y entrega a confirmar antes de producir.', $before_vat)),
            'fly-drop' => array('WB-FLY-002', 'Fly banner Drop', 'windbanners-banderas', 'Bandera promocional de formato recto y curvo con impresión full color.', array('Disponible simple o doble faz según el tamaño.', 'El precio no incluye base.', 'Plazo de producción y entrega a confirmar antes de producir.', $before_vat)),
            'fly-gota' => array('WB-FLY-003', 'Fly banner Gota', 'windbanners-banderas', 'Windbanner de silueta gota para promociones, veredas y eventos.', array('Incluye estructura, bandera y bolso.', 'El precio no incluye base.', 'Plazo de producción y entrega a confirmar antes de producir.', $before_vat)),
            'fly-oval' => array('WB-FLY-004', 'Fly banner Oval', 'windbanners-banderas', 'Windbanner ovalado compacto para exhibición y señalización.', array('Disponible simple o doble faz según el modelo.', 'El precio no incluye base.', $before_vat)),
            'fly-petalo' => array('WB-FLY-005', 'Fly banner Pétalo', 'windbanners-banderas', 'Bandera promocional con remate curvo para comunicación exterior.', array('Disponible simple o doble faz según el tamaño.', 'El precio no incluye base.', $before_vat)),
            'fly-pluma' => array('WB-FLY-006', 'Fly banner Pluma', 'windbanners-banderas', 'Windbanner estilizado de gran altura para eventos y puntos de venta.', array('Disponible simple o doble faz según el tamaño.', 'El precio no incluye base.', $before_vat)),
            'fly-sail' => array('WB-FLY-007', 'Fly banner Sail', 'windbanners-banderas', 'Bandera tipo vela para señalización de marca en interior o exterior.', array('Disponible simple o doble faz según el tamaño.', 'El precio no incluye base.', $before_vat)),
            'fly-surf' => array('WB-FLY-008', 'Fly banner Surf', 'windbanners-banderas', 'Windbanner de contorno dinámico para promociones y eventos.', array('Disponible simple o doble faz según el tamaño.', 'El precio no incluye base.', $before_vat)),
            'popup-circular' => array('WB-POP-001', 'Pop up circular', 'popup-exhibicion', 'Display textil plegable circular para activaciones, eventos y espacios deportivos.', array('Impresión full color.', $before_vat)),
            'popup-tower' => array('WB-POP-002', 'Pop up tower', 'popup-exhibicion', 'Torre textil autoportante para señalización, patrocinio y ambientación.', array('Impresión full color.', $before_vat)),
            'popup-valla' => array('WB-POP-003', 'Pop up valla', 'popup-exhibicion', 'Valla publicitaria plegable para canchas, carreras y eventos.', array('Impresión full color.', $before_vat)),
            'popup-vertical' => array('WB-POP-004', 'Pop up vertical', 'popup-exhibicion', 'Display vertical plegable, liviano y fácil de transportar.', array('Impresión full color.', $before_vat)),
            'banner-bow' => array('WB-POP-005', 'Banner Bow', 'popup-exhibicion', 'Gran display curvo para fondos fotográficos, activaciones y eventos.', array('Impresión full color.', $before_vat)),
            'arcos-totems-vallas' => array('WB-EXP-001', 'Arcos, tótems y vallas promocionales', 'popup-exhibicion', 'Estructuras textiles y autoportantes para señalización de eventos y presencia de marca.', array('Confirmamos la configuración final antes de producir.', $before_vat)),
            'backdrops' => array('WB-BAC-001', 'Backdrops para eventos', 'stands-backdrops', 'Fondos textiles de gran formato para escenarios, prensa, activaciones y fotografía.', array('Confirmamos la medida final y la estructura antes de producir.', $before_vat)),
            'mostradores' => array('WB-MOS-001', 'Mostradores promocionales', 'stands-backdrops', 'Mostradores portátiles personalizados para ferias, degustaciones y puntos de atención.', array('Impresión full color.', $before_vat)),
            'stands' => array('WB-STA-001', 'Stands promocionales', 'stands-backdrops', 'Soluciones modulares para montar espacios de marca en eventos y exposiciones.', array('La configuración final se confirma según el espacio disponible.', $before_vat)),
            'wall-banners' => array('WB-WAL-001', 'Wall banners', 'stands-backdrops', 'Fondos tensados autoportantes para stands, prensa y ambientación.', array('Impresión full color.', $before_vat)),
            'cintas-colgantes' => array('WB-CIN-001', 'Cintas colgantes y llaveros', 'promocionales-textiles', 'Cintas personalizadas para credenciales, medallas, eventos y merchandising.', array('Cantidad mínima y personalización a confirmar.', $before_vat)),
            'puffs-personalizados' => array('WB-PUF-001', 'Puffs y fiacas personalizados', 'promocionales-textiles', 'Mobiliario textil promocional para eventos, espacios de marca y áreas de descanso.', array('Personalización full color.', $before_vat)),
            'puffs-kids' => array('WB-PUF-002', 'Puffs Kids personalizados', 'promocionales-textiles', 'Puffs compactos personalizados para espacios infantiles y activaciones familiares.', array('Personalización full color.', $before_vat)),
        );
    }

    private static function source_rows() {
        $path = GE_WTP_PLUGIN_DIR . 'data/windbanners-catalog.json';
        if (!is_readable($path)) {
            return array();
        }
        $rows = json_decode((string) file_get_contents($path), true);
        return is_array($rows) ? $rows : array();
    }

    private static function family_key($row) {
        $category = isset($row['category']) ? $row['category'] : '';
        $name = isset($row['name']) ? $row['name'] : '';
        if (0 === strpos($category, 'Fly Banner')) {
            return preg_match('/FLY BANNER\s+([A-Z]+)/i', $name, $match) ? 'fly-' . sanitize_title($match[1]) : '';
        }
        if ('Banderas' === $category) { return 'banderas-sublimadas'; }
        if ('Bases' === $category) { return 'bases-estacas'; }
        if ('Carpas' === $category) {
            if (false !== stripos($name, 'Carpa Estrella')) { return 'carpas-estrella'; }
            if (false !== stripos($name, 'Gazebo') && false !== stripos($name, '+')) { return 'gazebos-completos'; }
            return 'gazebo-repuestos';
        }
        if ('Popup Banner' === $category) {
            if (false !== stripos($name, 'Banner Bow')) { return 'banner-bow'; }
            if (false !== stripos($name, 'Circular')) { return 'popup-circular'; }
            if (false !== stripos($name, 'Tower')) { return 'popup-tower'; }
            if (false !== stripos($name, 'Valla')) { return 'popup-valla'; }
            return 'popup-vertical';
        }
        $map = array(
            'Accesorios' => 'arcos-totems-vallas',
            'Backdrops' => 'backdrops',
            'Mostradores' => 'mostradores',
            'Stands' => 'stands',
            'Wall Banners' => 'wall-banners',
            'Cintas' => 'cintas-colgantes',
            'Puff Fiacas' => 'puffs-personalizados',
            'Puff Kids' => 'puffs-kids',
        );
        return isset($map[$category]) ? $map[$category] : '';
    }

    private static function variant_label($row) {
        $name = trim($row['name']);
        $category = $row['category'];
        if (0 === strpos($category, 'Fly Banner')) {
            $name = preg_replace('/^FLY BANNER\s+[A-Z]+\s*/i', '', $name);
            $name .= false !== stripos($category, 'Doble') ? ' · Doble faz' : ' · Simple faz';
        } elseif ('Banderas' === $category) {
            $name = preg_replace('/^BANDERA\s*/i', '', $name);
        }
        if (!empty($row['variant_name'])) { $name .= ' · ' . $row['variant_name']; }
        return trim($name);
    }

    public static function products() {
        $definitions = self::definitions();
        $products = array();
        foreach (self::source_rows() as $row) {
            $key = self::family_key($row);
            if (!$key || !isset($definitions[$key])) {
                continue;
            }
            if (!isset($products[$key])) {
                list($sku, $name, $group, $description, $notes) = $definitions[$key];
                $products[$key] = array(
                    'sku' => $sku,
                    'name' => $name,
                    'group' => $group,
                    'description' => $description,
                    'notes' => $notes,
                    'rows' => array(),
                    'prices' => array(),
                    'source_ids' => array(),
                    'options' => array(),
                    'unavailable' => array(),
                );
            }
            if (isset($row['active']) && !$row['active']) { $products[$key]['unavailable'][] = self::variant_label($row); continue; }
            if (!is_numeric($row['price']) || (float) $row['price'] <= 0 || empty($row['option_key'])) { continue; }
            $label = self::variant_label($row);
            $products[$key]['options'][$row['option_key']] = array('label'=>$label, 'price'=>(float)$row['price'], 'min_qty'=>max(1,(int)$row['min_qty']), 'step'=>max(1,(int)$row['step']), 'source_id'=>(int)$row['id'], 'variant_id'=>$row['variant_id'], 'includes'=>trim(($row['variant_description'] ? 'Opción seleccionada: '.$row['variant_description'].' ' : '').$row['includes']), 'description'=>$row['description'], 'source_url'=>$row['source_url']);
            $products[$key]['rows'][] = array($label, (float) $row['price']);
            $products[$key]['prices'][] = (float) $row['price'];
            if (!in_array((int)$row['id'], $products[$key]['source_ids'], true)) { $products[$key]['source_ids'][] = (int) $row['id']; }
        }
        return $products;
    }

    private static function public_sections($product) {
        $rows = array();
        foreach ($product['rows'] as $row) {
            $rows[] = array($row[0], '$ ' . number_format($row[1], round($row[1],2) == floor($row[1]) ? 0 : 2, ',', '.'));
        }
        return array(array('title' => $product['name'], 'columns' => array('Modelo / medida', 'Precio Graph Express'), 'rows' => $rows));
    }

    /** Live fiscal source: never infer an IVA percentage from the supplier. */
    public static function tax_context() {
        if (!class_exists('GE_WTP_Billing_Issuers') || !class_exists('GE_WTP_Customer_Tax')) { return array(); }
        $matches = array();
        foreach (GE_WTP_Billing_Issuers::all() as $issuer) {
            if (!empty($issuer['active']) && in_array('common', (array)($issuer['default_for_scenarios'] ?? array()), true)) { $matches[]=$issuer; }
        }
        if (count($matches)!==1 || (function_exists('wc_tax_enabled') && wc_tax_enabled())) { return array(); }
        $issuer=$matches[0]; $resolution=GE_WTP_Customer_Tax::resolve($issuer, array('vat_status'=>'final_consumer','country'=>'AR'));
        if (empty($resolution['issuer_ready'])) { return array(); }
        $rate=(int)($issuer['tax_rate_basis_points'] ?? 0);
        if ('no_vat'===$resolution['tax_treatment'] && $rate===0) { return array('multiplier'=>1,'rate_basis_points'=>0,'issuer_id'=>$issuer['id'],'label'=>'Total final · comprobante C','note'=>'Comprobante C: no se agrega IVA. Si necesitás factura A, consultanos antes de confirmar.'); }
        if ('vat_applies'===$resolution['tax_treatment'] && $rate>0) { return array('multiplier'=>1+$rate/10000,'rate_basis_points'=>$rate,'issuer_id'=>$issuer['id'],'label'=>'Total final con IVA','note'=>'IVA según el emisor vigente del comprobante.'); }
        return array();
    }

    /** Stable source/variant keys avoid positional matching of different options. */
    public static function storefront_config($product_id) {
        $key=(string)get_post_meta($product_id, '_ge_public_catalog_key', true);
        if (0!==strpos($key, 'windbanners-')) { return null; }
        $data=self::products(); $family=substr($key,strlen('windbanners-')); $tax=self::tax_context();
        if (!isset($data[$family]) || empty($data[$family]['options']) || !$tax) { return array(); }
        return array('label'=>'Modelo y configuración', 'options'=>$data[$family]['options'], 'min_qty'=>1, 'step'=>1, 'tax_context'=>$tax, 'windbanners'=>true);
    }

    /** Selective refresh: preserve identity, images, attributes and all historical sales. */
    public static function sync_prices() {
        $result=array('updated'=>array(),'manual'=>array());
        foreach (self::products() as $family=>$data) {
            $ids=get_posts(array('post_type'=>'product','post_status'=>array('publish','draft','private'),'posts_per_page'=>2,'fields'=>'ids','meta_key'=>'_ge_public_catalog_key','meta_value'=>'windbanners-'.$family));
            if (!$ids && !$data['options']) { $result['manual'][]=$family; continue; }
            if (count($ids)!==1) { return new WP_Error('wind_mapping','Mapping inexistente o duplicado: '.$family); }
            $id=(int)$ids[0]; $product=wc_get_product($id);
            if (!$product || $product->get_sku()!==$data['sku']) { return new WP_Error('wind_sku','SKU no coincide: '.$family); }
            $sections=$data['rows'] ? self::public_sections($data) : array(array('title'=>$data['name'],'columns'=>array('Modelo / medida','Disponibilidad'),'rows'=>array_map(function($label){return array($label,'Consultar disponibilidad');},$data['unavailable'])));
            update_post_meta($id,'_ge_public_price_sections',$sections);
            $notes=$data['notes'];
            if ($data['unavailable']) { $notes[]='Opciones sin disponibilidad publicada: '.implode('; ',$data['unavailable']).'. Consultanos.'; }
            $notes[]='Respetamos cantidades mínimas y múltiplos por opción. Stock físico, envío y fecha final a confirmar.';
            update_post_meta($id,'_ge_public_price_notes',$notes);
            update_post_meta($id,'_ge_reference_price_min',$data['prices'] ? min($data['prices']) : 0);
            update_post_meta($id,'_ge_show_reference_price',$data['prices'] ? 'yes' : 'no');
            update_post_meta($id,'_ge_supplier_source_date',self::SOURCE_DATE);
            update_post_meta($id,'_ge_supplier_item_ids',$data['source_ids']);
            update_post_meta($id,'_ge_wind_reconciliation_version','2026-10-10-v1');
            wc_delete_product_transients($id); clean_post_cache($id);
            $result[$data['prices'] ? 'updated' : 'manual'][]=$id;
        }
        return $result;
    }

    public static function sync() {
        if (!class_exists('WC_Product_Simple')) {
            return new WP_Error('woocommerce_required', 'WooCommerce debe estar activo para sincronizar el catálogo.');
        }
        $category_ids = self::sync_categories();
        if (is_wp_error($category_ids)) {
            return $category_ids;
        }
        $created = 0;
        $updated = 0;
        $position = 300;
        foreach (self::products() as $key => $data) {
            $existing = get_posts(array('post_type' => 'product', 'post_status' => array('publish', 'draft', 'private'), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_ge_public_catalog_key', 'meta_value' => 'windbanners-' . $key));
            $is_new = empty($existing);
            $product = $is_new ? new WC_Product_Simple() : wc_get_product($existing[0]);
            if (!$product) { continue; }
            $product->set_name($data['name']);
            $product->set_slug('windbanners-' . $key);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_description($data['description']);
            $product->set_short_description($data['description']);
            if ($product->get_sku() !== $data['sku']) { $product->set_sku($data['sku']); }
            $product->set_regular_price('');
            $product->set_sale_price('');
            $product->set_category_ids(array($category_ids['gran-formato'], $category_ids['windbanners'], $category_ids[$data['group']]));
            $product->set_menu_order($position++);
            $product->set_reviews_allowed(false);
            $product->set_attributes(self::build_attributes(array('Modelos y medidas' => array_column($data['rows'], 0))));
            $product_id = $product->save();
            update_post_meta($product_id, '_ge_public_catalog_key', 'windbanners-' . $key);
            update_post_meta($product_id, '_ge_quote_only', 'yes');
            update_post_meta($product_id, '_ge_show_reference_price', 'yes');
            update_post_meta($product_id, '_ge_reference_price_min', min($data['prices']));
            update_post_meta($product_id, '_ge_public_price_sections', self::public_sections($data));
            update_post_meta($product_id, '_ge_public_price_notes', $data['notes']);
            delete_post_meta($product_id, '_ge_supplier_costs');
            delete_post_meta($product_id, '_ge_supplier_margin');
            update_post_meta($product_id, '_ge_supplier_source', self::SOURCE_NAME);
            update_post_meta($product_id, '_ge_supplier_source_url', self::SOURCE_URL);
            update_post_meta($product_id, '_ge_supplier_source_date', self::SOURCE_DATE);
            update_post_meta($product_id, '_ge_supplier_item_ids', $data['source_ids']);
            if (self::IMAGE_VERSION !== get_post_meta($product_id, '_ge_wind_catalog_image_version', true)) {
                if (self::attach_catalog_image($key . '.png', $product_id, $data['name'])) {
                    update_post_meta($product_id, '_ge_wind_catalog_image_version', self::IMAGE_VERSION);
                }
            }
            $is_new ? $created++ : $updated++;
        }
        clean_term_cache(array_values($category_ids), 'product_cat');
        return array('created' => $created, 'updated' => $updated, 'total' => $created + $updated, 'source_items' => count(self::source_rows()));
    }

    /**
     * Copia al Media Library la versión editorial limpia incluida en el plugin.
     * Nunca enlaza archivos del proveedor ni expone su identidad en el frente.
     */
    private static function attach_catalog_image($filename, $product_id, $title) {
        $source = GE_WTP_PLUGIN_DIR . 'assets/images/windbanners/clean/' . basename($filename);
        if (!is_readable($source)) {
            return false;
        }
        $contents = file_get_contents($source);
        if (false === $contents) {
            return false;
        }
        $upload = wp_upload_bits('ge-wind-' . basename($filename), null, $contents);
        if (!empty($upload['error'])) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $mime = wp_check_filetype($upload['file']);
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => $mime['type'],
            'post_title' => sanitize_text_field($title),
            'post_status' => 'inherit',
        ), $upload['file'], $product_id);
        if (is_wp_error($attachment_id)) {
            return false;
        }
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($title));
        update_post_meta($attachment_id, '_ge_catalog_asset', 'windbanners/clean/' . basename($filename));
        set_post_thumbnail($product_id, $attachment_id);
        update_post_meta($product_id, '_product_image_gallery', '');
        return true;
    }

    private static function sync_categories() {
        $parent = get_term_by('slug', 'gran-formato', 'product_cat');
        if (!$parent) {
            $inserted = wp_insert_term('Gran formato', 'product_cat', array('slug' => 'gran-formato'));
            if (is_wp_error($inserted)) { return $inserted; }
            $parent_id = (int) $inserted['term_id'];
        }
        $parent_id = isset($parent_id) ? $parent_id : (int) $parent->term_id;
        $windbanners = get_term_by('slug', 'windbanners', 'product_cat');
        if (!$windbanners) {
            $inserted = wp_insert_term('Windbanners', 'product_cat', array(
                'slug' => 'windbanners',
                'description' => 'Windbanners, banderas, bases, carpas, exhibidores y promocionales textiles.',
            ));
            if (is_wp_error($inserted)) { return $inserted; }
            $windbanners_id = (int) $inserted['term_id'];
        } else {
            $windbanners_id = (int) $windbanners->term_id;
            wp_update_term($windbanners_id, 'product_cat', array(
                'parent' => 0,
                'description' => 'Windbanners, banderas, bases, carpas, exhibidores y promocionales textiles.',
            ));
        }
        $ids = array('gran-formato' => $parent_id, 'windbanners' => $windbanners_id);
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
