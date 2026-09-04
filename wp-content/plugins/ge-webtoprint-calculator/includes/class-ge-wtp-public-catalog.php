<?php

defined('ABSPATH') || exit;

/**
 * Inventario inicial de la tienda pública.
 *
 * Los costos de proveedor son sólo referencia interna. Los productos se crean
 * sin precio público hasta definir margen, impuestos y reglas de cálculo.
 */
final class GE_WTP_Public_Catalog {
    const SOURCE_NAME = 'Bandurria Deco - Listado de precios gráfica';
    const SOURCE_DATE = '2026-08-20';

    public static function groups() {
        return array(
            'lonas-gigantografias' => array(
                'name'        => 'Lonas y gigantografías',
                'description' => 'Lonas impresas para interiores, exteriores, frentes, cartelería y vía pública.',
            ),
            'vinilos' => array(
                'name'        => 'Vinilos',
                'description' => 'Vinilos impresos, de corte, microperforados y especiales para vidrieras y superficies.',
            ),
            'soportes-especiales' => array(
                'name'        => 'Papeles y soportes especiales',
                'description' => 'Papeles, lienzos, alfombras, imanes y otros materiales flexibles impresos.',
            ),
            'portabanners' => array(
                'name'        => 'Portabanners y back de prensa',
                'description' => 'Sistemas autoportantes completos para eventos, exhibiciones y comunicación institucional.',
            ),
            'placas-rigidas' => array(
                'name'        => 'Placas rígidas',
                'description' => 'Impresión directa simple o bifaz sobre PVC, PAI, plástico corrugado y cartulina.',
            ),
        );
    }

    private static function item($sku, $name, $group, $description, $attributes, $costs, $unit) {
        return compact('sku', 'name', 'group', 'description', 'attributes', 'costs', 'unit');
    }

    public static function products() {
        $lona_finish = 'Doblez para caño (bolsillo), refuerzo para ojales y ojales';
        $vinyl_finish = 'Laminado, laminado de alto tránsito, transfer y refilado por contorno';
        $rigid_finish = 'Termodoblado de PAI, cinta bifaz 3M y pies de apoyo';

        return array(
            'lona-front-brillo-9oz' => self::item('GF-LON-001', 'Lona front brillo 9 oz', 'lonas-gigantografias', 'Lona liviana brillante para banners, comunicación temporal y gráfica de interior.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Anchos imprimibles' => array('158 cm', '318 cm'), 'Terminaciones disponibles' => array($lona_finish)), array('m2' => 11700), 'm²'),
            'lona-front-brillo-13oz' => self::item('GF-LON-002', 'Lona front brillo 13 oz', 'lonas-gigantografias', 'Lona reforzada con acabado brillante para cartelería y aplicaciones de interior o exterior.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Anchos imprimibles' => array('88 cm', '98 cm', '118 cm', '135 cm', '148 cm', '158 cm', '218 cm', '318 cm'), 'Terminaciones disponibles' => array($lona_finish)), array('m2' => 12600), 'm²'),
            'lona-front-mate-13oz' => self::item('GF-LON-003', 'Lona front mate 13 oz', 'lonas-gigantografias', 'Lona reforzada de bajo reflejo, ideal para fondos, eventos y cartelería fotografiable.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Anchos imprimibles' => array('88 cm', '98 cm', '118 cm', '135 cm', '148 cm', '158 cm', '218 cm', '318 cm'), 'Terminaciones disponibles' => array($lona_finish)), array('m2' => 12700), 'm²'),
            'lona-backlight' => self::item('GF-LON-004', 'Lona backlight', 'lonas-gigantografias', 'Lona translúcida para carteles y cajas de luz con iluminación posterior.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Anchos imprimibles' => array('98 cm', '118 cm', '158 cm', '218 cm'), 'Terminaciones disponibles' => array($lona_finish)), array('m2' => 13700), 'm²'),
            'lona-blackout-15oz' => self::item('GF-LON-005', 'Lona blackout 15 oz', 'lonas-gigantografias', 'Lona opaca de alta resistencia para fondos, estructuras y piezas donde no debe pasar la luz.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Anchos imprimibles' => array('98 cm', '150 cm', '158 cm', '218 cm', '318 cm'), 'Terminaciones disponibles' => array($lona_finish)), array('m2' => 14100), 'm²'),
            'lona-mesh' => self::item('GF-LON-006', 'Lona mesh', 'lonas-gigantografias', 'Lona microperforada que permite el paso del aire, pensada para frentes y aplicaciones exteriores.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Anchos imprimibles' => array('158 cm', '318 cm'), 'Terminaciones disponibles' => array($lona_finish)), array('m2' => 14300), 'm²'),

            'vinilo-blanco' => self::item('GF-VIN-001', 'Vinilo base blanca', 'vinilos', 'Vinilo autoadhesivo blanco para gráfica promocional, señalización y decoración de superficies.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Acabado' => array('Brillante', 'Mate'), 'Anchos imprimibles' => array('104 cm', '124 cm', '135 cm', '150 cm'), 'Terminaciones disponibles' => array($vinyl_finish)), array('m2' => 13800), 'm²'),
            'vinilo-base-gris' => self::item('GF-VIN-002', 'Vinilo base gris', 'vinilos', 'Vinilo autoadhesivo con adhesivo gris para mejorar la cobertura sobre fondos existentes.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Acabado' => array('Brillante', 'Mate'), 'Anchos imprimibles' => array('104 cm', '124 cm', '135 cm', '150 cm'), 'Terminaciones disponibles' => array($vinyl_finish)), array('m2' => 14100), 'm²'),
            'vinilo-cristal' => self::item('GF-VIN-003', 'Vinilo cristal', 'vinilos', 'Vinilo transparente para vidrios, exhibidores y aplicaciones que requieren conservar el fondo visible.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Anchos imprimibles' => array('104 cm', '124 cm', '135 cm', '150 cm'), 'Terminaciones disponibles' => array($vinyl_finish)), array('m2' => 14200), 'm²'),
            'vinilo-microperforado' => self::item('GF-VIN-004', 'Vinilo microperforado', 'vinilos', 'Gráfica para vidrieras que permite visibilidad desde el interior y comunicación hacia el exterior.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Anchos imprimibles' => array('104 cm', '124 cm', '150 cm'), 'Terminaciones disponibles' => array($vinyl_finish)), array('m2' => 14600), 'm²'),
            'esmerilado-impreso' => self::item('GF-VIN-005', 'Vinilo esmerilado impreso', 'vinilos', 'Vinilo traslúcido impreso para privacidad y ambientación de vidrios. Incluye corte, despuntillado y transfer.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Anchos disponibles' => array('60 cm', '120 cm'), 'Incluye' => array('Impresión', 'Corte', 'Despuntillado', 'Transfer')), array('m2' => 30000), 'm²'),
            'vinilo-de-corte' => self::item('GF-VIN-006', 'Vinilo de corte', 'vinilos', 'Gráfica calada sin fondo para vidrieras, cartelería, vehículos y señalización.', array('Anchos disponibles' => array('60 cm', '120 cm'), 'Colores' => array('Blanco', 'Negro', 'Gris oscuro'), 'Incluye' => array('Despuntillado', 'Transfer')), array('60 cm' => 19000, '120 cm' => 38000), 'metro lineal'),
            'vinilo-esmerilado-corte' => self::item('GF-VIN-007', 'Vinilo esmerilado de corte', 'vinilos', 'Vinilo esmerilado calado para privacidad, señalética y decoración de superficies vidriadas.', array('Anchos disponibles' => array('60 cm', '120 cm'), 'Incluye' => array('Despuntillado', 'Transfer')), array('60 cm' => 14500, '120 cm' => 28000), 'metro lineal'),
            'vinilo-impreso-troquelado' => self::item('GF-VIN-008', 'Vinilo impreso y troquelado', 'vinilos', 'Plancha de etiquetas, formas y piezas autoadhesivas impresas con corte electrónico.', array('Medidas disponibles' => array('100 × 60 cm', '100 × 100 cm'), 'Material' => array('Vinilo base blanca', 'Vinilo cristal'), 'Adicional' => array('Transfer opcional')), array('100 × 60 cm' => 14500, '100 × 100 cm' => 19000), 'plancha'),

            'papel-fotografico-260' => self::item('GF-ESP-001', 'Papel fotográfico 260 g', 'soportes-especiales', 'Impresión de alta definición sobre papel fotográfico para láminas, exhibiciones y presentaciones.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Ancho imprimible' => array('104 cm'), 'Gramaje' => array('260 g')), array('m2' => 14700), 'm²'),
            'papel-blueback-150' => self::item('GF-ESP-002', 'Papel blueback 150 g', 'soportes-especiales', 'Papel con dorso azul para cartelería y pegado sobre superficies, con buena opacidad.', array('Unidad de cálculo' => array('Metro lineal'), 'Ancho' => array('148 cm'), 'Gramaje' => array('150 g')), array('ml' => 15700), 'metro lineal'),
            'cuerina-plavinil' => self::item('GF-ESP-003', 'Cuerina Plavinil impresa', 'soportes-especiales', 'Impresión personalizada sobre cuerina para decoración, tapicería liviana y piezas especiales.', array('Unidad de cálculo' => array('Metro lineal'), 'Ancho' => array('134 cm')), array('ml' => 28000), 'metro lineal'),
            'lienzo-canvas-270' => self::item('GF-ESP-004', 'Lienzo Canvas 270 g', 'soportes-especiales', 'Lienzo impreso para cuadros, reproducciones, exhibiciones y decoración.', array('Unidad de cálculo' => array('Metro lineal'), 'Ancho' => array('135 cm'), 'Gramaje' => array('270 g')), array('ml' => 24900), 'metro lineal'),
            'alfombra-impresa-2mm' => self::item('GF-ESP-005', 'Alfombra impresa 2 mm', 'soportes-especiales', 'Alfombra personalizada para eventos, vidrieras, señalización y ambientación. Incluye corte.', array('Unidad de cálculo' => array('Metro lineal'), 'Ancho' => array('158 cm'), 'Espesor' => array('2 mm'), 'Incluye' => array('Corte')), array('ml' => 57500), 'metro lineal'),
            'iman-impreso-04mm' => self::item('GF-ESP-006', 'Imán impreso 0,4 mm', 'soportes-especiales', 'Lámina magnética impresa para señalización removible, promociones y aplicaciones vehiculares.', array('Unidad de cálculo' => array('Metro cuadrado'), 'Anchos disponibles' => array('60 cm', '120 cm'), 'Espesor' => array('0,4 mm'), 'Incluye' => array('Corte')), array('m2' => 28000), 'm²'),

            'portabanner-tensor-simple' => self::item('GF-POR-001', 'Portabanner tensor simple 90 × 190 cm', 'portabanners', 'Banner autoportante de tensor simple para eventos y puntos de venta.', array('Medida' => array('90 × 190 cm'), 'Opciones' => array('Gráfica brillo', 'Gráfica mate', 'Sólo estructura'), 'Incluye' => array('Banner impreso', 'Estructura', 'Bolso')), array('brillo' => 46900, 'mate' => 47100, 'estructura' => 23100), 'unidad'),
            'portabanner-tensor-doble' => self::item('GF-POR-002', 'Portabanner tensor doble 90 × 190 cm', 'portabanners', 'Sistema autoportante de mayor estabilidad con doble tensor.', array('Medida' => array('90 × 190 cm'), 'Opciones' => array('Gráfica brillo', 'Gráfica mate', 'Sólo estructura'), 'Incluye' => array('Banner impreso', 'Estructura', 'Bolso')), array('brillo' => 54500, 'mate' => 54700, 'estructura' => 30800), 'unidad'),
            'portabanner-roll-up' => self::item('GF-POR-003', 'Portabanner Roll Up 85 × 200 cm', 'portabanners', 'Display enrollable, compacto y transportable para exposiciones, salones y locales.', array('Medida' => array('85 × 200 cm'), 'Opciones' => array('Gráfica brillo', 'Gráfica mate', 'Sólo estructura'), 'Incluye' => array('Banner impreso', 'Estructura', 'Bolso')), array('brillo' => 63400, 'mate' => 63600, 'estructura' => 36000), 'unidad'),
            'back-prensa-200x150' => self::item('GF-POR-004', 'Back de prensa 200 × 150 cm', 'portabanners', 'Fondo autoportante para prensa, fotos, presentaciones y eventos.', array('Medida' => array('200 × 150 cm'), 'Orientación' => array('Horizontal', 'Vertical'), 'Opciones' => array('Gráfica brillo', 'Gráfica mate', 'Sólo estructura'), 'Incluye' => array('Banner impreso', 'Estructura', 'Bolso')), array('brillo' => 93800, 'mate' => 94200, 'estructura' => 51700), 'unidad'),
            'back-prensa-200x200' => self::item('GF-POR-005', 'Back de prensa 200 × 200 cm', 'portabanners', 'Fondo cuadrado autoportante para eventos, entrevistas y activaciones.', array('Medida' => array('200 × 200 cm'), 'Opciones' => array('Gráfica brillo', 'Gráfica mate', 'Sólo estructura'), 'Incluye' => array('Banner impreso', 'Estructura', 'Bolso')), array('brillo' => 132000, 'mate' => 132300, 'estructura' => 73900), 'unidad'),
            'back-prensa-300x200' => self::item('GF-POR-006', 'Back de prensa 300 × 200 cm', 'portabanners', 'Fondo panorámico autoportante para eventos, lanzamientos y espacios de marca.', array('Medida' => array('300 × 200 cm'), 'Orientación' => array('Horizontal'), 'Opciones' => array('Gráfica brillo', 'Gráfica mate', 'Sólo estructura'), 'Incluye' => array('Banner impreso', 'Estructura', 'Bolso')), array('brillo' => 185000, 'mate' => 185700, 'estructura' => 98200), 'unidad'),

            'placa-pvc-3mm' => self::item('GF-RIG-001', 'Placa PVC 3 mm impresa', 'placas-rigidas', 'Impresión directa sobre PVC espumado, liviano y firme para cartelería interior.', array('Medidas' => array('60 × 80 cm', '120 × 120 cm', '120 × 240 cm'), 'Impresión' => array('Simple faz', 'Bifaz'), 'Terminaciones disponibles' => array($rigid_finish)), array('60×80 simple' => 11900, '60×80 bifaz' => 18200, '120×120 simple' => 33000, '120×120 bifaz' => 50400, '120×240 simple' => 55000, '120×240 bifaz' => 84000), 'placa'),
            'placa-pvc-5mm' => self::item('GF-RIG-002', 'Placa PVC 5 mm impresa', 'placas-rigidas', 'PVC espumado de mayor rigidez para cartelería, displays y señalización durable.', array('Medida' => array('120 × 240 cm'), 'Impresión' => array('Simple faz', 'Bifaz'), 'Terminaciones disponibles' => array($rigid_finish)), array('simple' => 81000, 'bifaz' => 110000), 'placa'),
            'placa-pai-05mm' => self::item('GF-RIG-003', 'Placa PAI 0,5 mm impresa', 'placas-rigidas', 'Placa fina y flexible para cartelería, revestimientos y piezas termoformables.', array('Medida' => array('120 × 240 cm'), 'Espesor' => array('0,5 mm'), 'Impresión' => array('Simple faz', 'Bifaz'), 'Terminaciones disponibles' => array($rigid_finish)), array('simple' => 48800, 'bifaz' => 77800), 'placa'),
            'placa-pai-1mm' => self::item('GF-RIG-004', 'Placa PAI 1 mm impresa', 'placas-rigidas', 'Placa plástica versátil para cartelería, exhibición y piezas promocionales.', array('Medidas' => array('100 × 200 cm', '120 × 240 cm'), 'Espesor' => array('1 mm'), 'Impresión' => array('Simple faz', 'Bifaz'), 'Terminaciones disponibles' => array($rigid_finish)), array('100×200 simple' => 45000, '100×200 bifaz' => 65000, '120×240 simple' => 63000, '120×240 bifaz' => 92000), 'placa'),
            'placa-pai-15mm' => self::item('GF-RIG-005', 'Placa PAI 1,5 mm impresa', 'placas-rigidas', 'Placa plástica semirrígida para comunicación visual y exhibidores.', array('Medida' => array('120 × 240 cm'), 'Espesor' => array('1,5 mm'), 'Impresión' => array('Simple faz', 'Bifaz'), 'Terminaciones disponibles' => array($rigid_finish)), array('simple' => 84800, 'bifaz' => 116000), 'placa'),
            'placa-pai-2mm' => self::item('GF-RIG-006', 'Placa PAI 2 mm impresa', 'placas-rigidas', 'Placa resistente para cartelería, displays y estructuras promocionales.', array('Medida' => array('120 × 240 cm'), 'Espesor' => array('2 mm'), 'Impresión' => array('Simple faz', 'Bifaz'), 'Terminaciones disponibles' => array($rigid_finish)), array('simple' => 108800, 'bifaz' => 144500), 'placa'),
            'plastico-corrugado-22mm' => self::item('GF-RIG-007', 'Plástico corrugado 2,2 mm impreso', 'placas-rigidas', 'Placa liviana y económica para cartelería temporal, vía pública y exhibición.', array('Medida' => array('120 × 240 cm'), 'Espesor' => array('2,2 mm'), 'Impresión' => array('Simple faz', 'Bifaz'), 'Terminaciones disponibles' => array($rigid_finish)), array('simple' => 45900, 'bifaz' => 74800), 'placa'),
            'plastico-corrugado-4mm' => self::item('GF-RIG-008', 'Plástico corrugado 4 mm impreso', 'placas-rigidas', 'Placa corrugada de mayor cuerpo para carteles, exhibidores y piezas autoportantes.', array('Medida' => array('120 × 240 cm'), 'Espesor' => array('4 mm'), 'Impresión' => array('Simple faz', 'Bifaz'), 'Terminaciones disponibles' => array($rigid_finish)), array('simple' => 56300, 'bifaz' => 85300), 'placa'),
            'cartulina-270gr' => self::item('GF-RIG-009', 'Cartulina 270 g impresa', 'placas-rigidas', 'Cartulina impresa en cama plana para exhibidores, colgantes y comunicación en punto de venta.', array('Medida' => array('74 × 110 cm'), 'Gramaje' => array('270 g'), 'Impresión' => array('Simple faz', 'Bifaz'), 'Terminaciones disponibles' => array($rigid_finish)), array('simple' => 10500, 'bifaz' => 19500), 'pliego'),
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
        $position = 0;

        foreach (self::products() as $key => $data) {
            $existing = get_posts(array(
                'post_type'      => 'product',
                'post_status'    => array('publish', 'draft', 'private'),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_ge_public_catalog_key',
                'meta_value'     => $key,
            ));

            $is_new = empty($existing);
            $product = $is_new ? new WC_Product_Simple() : wc_get_product($existing[0]);
            if (! $product) {
                continue;
            }

            $product->set_name($data['name']);
            $product->set_slug('gran-formato-' . $key);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_description($data['description']);
            $product->set_short_description($data['description']);
            $product->set_sku($data['sku']);
            $product->set_regular_price('');
            $product->set_sale_price('');
            $product->set_category_ids(array($category_ids['gran-formato'], $category_ids[$data['group']]));
            $product->set_menu_order($position++);
            $product->set_reviews_allowed(false);
            $product->set_attributes(self::build_attributes($data['attributes']));
            $product_id = $product->save();

            update_post_meta($product_id, '_ge_public_catalog_key', $key);
            update_post_meta($product_id, '_ge_quote_only', 'yes');
            update_post_meta($product_id, '_ge_supplier_costs', $data['costs']);
            update_post_meta($product_id, '_ge_supplier_cost_unit', $data['unit']);
            update_post_meta($product_id, '_ge_supplier_source', self::SOURCE_NAME);
            update_post_meta($product_id, '_ge_supplier_source_date', self::SOURCE_DATE);

            $is_new ? $created++ : $updated++;
        }

        clean_term_cache(array_values($category_ids), 'product_cat');

        return array('created' => $created, 'updated' => $updated, 'total' => $created + $updated);
    }

    private static function sync_categories() {
        $parent = get_term_by('slug', 'gran-formato', 'product_cat');
        if (! $parent) {
            $result = wp_insert_term('Gran formato', 'product_cat', array('slug' => 'gran-formato'));
            if (is_wp_error($result)) {
                return $result;
            }
            $parent_id = (int) $result['term_id'];
        } else {
            $parent_id = (int) $parent->term_id;
        }

        $ids = array('gran-formato' => $parent_id);
        foreach (self::groups() as $slug => $group) {
            $term = get_term_by('slug', $slug, 'product_cat');
            if (! $term) {
                $result = wp_insert_term($group['name'], 'product_cat', array(
                    'slug'        => $slug,
                    'parent'      => $parent_id,
                    'description' => $group['description'],
                ));
                if (is_wp_error($result)) {
                    return $result;
                }
                $ids[$slug] = (int) $result['term_id'];
            } else {
                wp_update_term($term->term_id, 'product_cat', array(
                    'parent'      => $parent_id,
                    'description' => $group['description'],
                ));
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
