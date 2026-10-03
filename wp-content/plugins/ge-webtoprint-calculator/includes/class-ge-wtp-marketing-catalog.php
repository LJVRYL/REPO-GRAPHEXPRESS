<?php

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo público de lanyards producido por La Tienda de Cintas.
 *
 * Los precios del proveedor son valores públicos antes de IVA. Graph Express
 * aplica un margen fijo del 50%; WooCommerce incorpora el IVA en el carrito.
 */
final class GE_WTP_Marketing_Catalog {
    const SOURCE_NAME = 'La Tienda de Cintas';
    const SOURCE_URL  = 'https://www.latiendadecintas.com.ar/llaveros-cinta-y-lanyards';
    const SOURCE_DATE = '2026-09-09';
    const MARGIN      = 50;

    private static function products() {
        return array(
            'lanyard-full-black' => array(
                'sku'         => 'MKT-LAN-FB100',
                'name'        => 'Lanyard Full Black personalizado',
                'description' => 'Lanyard negro de alta densidad, personalizado a todo color y confeccionado con herrajes metálicos. Una opción premium para acreditaciones, equipos y eventos.',
                'url'         => 'https://www.latiendadecintas.com.ar/lanyard-premium-full-black-x100',
                'image'       => 'lanyard-full-black.jpg',
                'lead_time'   => '10 a 15 días hábiles desde la aprobación del diseño.',
                'options'     => array(
                    array( '100 unidades · Full print', 132000 ),
                ),
            ),
            'lanyard-premium-anzuelo' => array(
                'sku'         => 'MKT-LAN-PRE100',
                'name'        => 'Lanyard personalizado con mosquetón premium',
                'description' => 'Cinta de poliéster de alta densidad de 20 mm, impresa a todo color y terminada con remache y mosquetón anzuelo metálico premium.',
                'url'         => 'https://www.latiendadecintas.com.ar/lanyards-personalizados-x100',
                'image'       => 'lanyard-premium-anzuelo.png',
                'lead_time'   => '10 a 15 días hábiles desde la aprobación del diseño.',
                'options'     => array(
                    array( '100 unidades · Simple faz', 91800 ),
                    array( '100 unidades · Doble faz', 100350 ),
                ),
            ),
            'lanyard-aro-mosqueton' => array(
                'sku'         => 'MKT-LAN-ARO100',
                'name'        => 'Lanyard personalizado con aro y mosquetón',
                'description' => 'Cinta de poliéster de alta densidad de 20 mm, impresa a todo color y terminada con remache, aro metálico y mosquetón simple.',
                'url'         => 'https://www.latiendadecintas.com.ar/lanyards-personalizados-x100-caro-y-mosq',
                'image'       => 'lanyard-aro-mosqueton.jpg',
                'lead_time'   => '7 a 10 días hábiles desde la aprobación del diseño.',
                'options'     => array(
                    array( '100 unidades · Simple faz', 70200 ),
                    array( '100 unidades · Doble faz', 80910 ),
                ),
            ),
        );
    }

    private static function sale_price( $source_price ) {
        return round( (float) $source_price * ( 1 + self::MARGIN / 100 ), 2 );
    }

    private static function public_sections( $product ) {
        $rows = array();
        foreach ( $product['options'] as $option ) {
            $rows[] = array( $option[0], '$ ' . number_format( self::sale_price( $option[1] ), 2, ',', '.' ) );
        }
        return array(
            array(
                'title'   => '',
                'columns' => array( 'Cantidad e impresión', 'Precio Graph Express' ),
                'rows'    => $rows,
            ),
        );
    }

    public static function sync() {
        if ( ! class_exists( 'WC_Product_Simple' ) ) {
            return new WP_Error( 'woocommerce_required', 'WooCommerce debe estar activo para sincronizar el catálogo.' );
        }

        $category_ids = self::sync_categories();
        if ( is_wp_error( $category_ids ) ) { return $category_ids; }

        $created = 0;
        $updated = 0;
        $position = 600;

        foreach ( self::products() as $key => $data ) {
            $existing = get_posts(
                array(
                    'post_type'      => 'product',
                    'post_status'    => array( 'publish', 'draft', 'private' ),
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_key'       => '_ge_public_catalog_key',
                    'meta_value'     => 'marketing-lanyards-' . $key,
                )
            );
            $is_new  = empty( $existing );
            $product = $is_new ? new WC_Product_Simple() : wc_get_product( $existing[0] );
            if ( ! $product ) { continue; }

            $sale_prices = array_map(
                function ( $option ) { return self::sale_price( $option[1] ); },
                $data['options']
            );

            $product->set_name( $data['name'] );
            $product->set_slug( $key );
            $product->set_status( 'publish' );
            $product->set_catalog_visibility( 'visible' );
            $product->set_description( $data['description'] );
            $product->set_short_description( $data['description'] );
            if ( $product->get_sku() !== $data['sku'] ) { $product->set_sku( $data['sku'] ); }
            $product->set_regular_price( '' );
            $product->set_sale_price( '' );
            $product->set_category_ids( array( $category_ids['merchandising'], $category_ids['lanyards-credenciales'] ) );
            $product->set_menu_order( $position++ );
            $product->set_reviews_allowed( false );
            $product->set_attributes( self::build_attributes( array( 'Cantidad e impresión' => array_column( $data['options'], 0 ) ) ) );
            $product_id = $product->save();

            $notes = array(
                'El precio corresponde a un pack de 100 unidades.',
                'Cinta de poliéster de alta densidad, 20 mm de ancho y aproximadamente 45 cm de largo armada.',
                'Impresión full color en una o ambas caras, según la opción seleccionada.',
                'Cantidades de 500, 1.000 y 5.000 unidades: solicitar cotización.',
                'Producción estimada: ' . $data['lead_time'],
                'Valores Graph Express antes de IVA.',
            );

            update_post_meta( $product_id, '_ge_public_catalog_key', 'marketing-lanyards-' . $key );
            delete_post_meta( $product_id, '_ge_quote_only' );
            update_post_meta( $product_id, '_ge_show_reference_price', 'yes' );
            update_post_meta( $product_id, '_ge_reference_price_min', min( $sale_prices ) );
            update_post_meta( $product_id, '_ge_public_price_sections', self::public_sections( $data ) );
            update_post_meta( $product_id, '_ge_public_price_notes', $notes );
            update_post_meta( $product_id, '_ge_option_label', 'Cantidad e impresión' );
            update_post_meta( $product_id, '_ge_minimum_quantity', 1 );
            update_post_meta( $product_id, '_ge_quantity_step', 1 );
            delete_post_meta( $product_id, '_ge_supplier_costs' );
            update_post_meta( $product_id, '_ge_supplier_margin', self::MARGIN );
            update_post_meta( $product_id, '_ge_supplier_source', self::SOURCE_NAME );
            update_post_meta( $product_id, '_ge_supplier_source_url', $data['url'] );
            update_post_meta( $product_id, '_ge_supplier_source_date', self::SOURCE_DATE );

            if ( ! $product->get_image_id() ) {
                self::attach_catalog_image( $data['image'], $product_id, $data['name'] );
            }

            $is_new ? $created++ : $updated++;
        }

        clean_term_cache( array_values( $category_ids ), 'product_cat' );
        return array( 'created' => $created, 'updated' => $updated, 'total' => $created + $updated );
    }

    private static function sync_categories() {
        $parent = get_term_by( 'slug', 'merchandising', 'product_cat' );
        if ( ! $parent ) {
            $inserted = wp_insert_term(
                'Merchandising',
                'product_cat',
                array(
                    'slug'        => 'merchandising',
                    'description' => 'Objetos y productos personalizados para campañas, equipos, eventos y regalos corporativos.',
                )
            );
            if ( is_wp_error( $inserted ) ) { return $inserted; }
            $parent_id = (int) $inserted['term_id'];
        } else {
            $parent_id = (int) $parent->term_id;
            wp_update_term( $parent_id, 'product_cat', array( 'parent' => 0 ) );
        }

        $child = get_term_by( 'slug', 'lanyards-credenciales', 'product_cat' );
        $args  = array(
            'slug'        => 'lanyards-credenciales',
            'parent'      => $parent_id,
            'description' => 'Lanyards, cintas y accesorios personalizados para credenciales y eventos.',
        );
        if ( ! $child ) {
            $inserted = wp_insert_term( 'Lanyards y credenciales', 'product_cat', $args );
            if ( is_wp_error( $inserted ) ) { return $inserted; }
            $child_id = (int) $inserted['term_id'];
        } else {
            wp_update_term( $child->term_id, 'product_cat', $args );
            $child_id = (int) $child->term_id;
        }

        return array( 'merchandising' => $parent_id, 'lanyards-credenciales' => $child_id );
    }

    private static function attach_catalog_image( $filename, $product_id, $title ) {
        $source = GE_WTP_PLUGIN_DIR . 'assets/images/marketing/' . basename( $filename );
        if ( ! is_readable( $source ) ) { return; }

        $contents = file_get_contents( $source );
        if ( false === $contents ) { return; }

        $upload = wp_upload_bits( basename( $filename ), null, $contents );
        if ( ! empty( $upload['error'] ) ) { return; }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $mime          = wp_check_filetype( $upload['file'] );
        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => $mime['type'],
                'post_title'     => sanitize_text_field( $title ),
                'post_status'    => 'inherit',
            ),
            $upload['file'],
            $product_id
        );
        if ( is_wp_error( $attachment_id ) ) { return; }

        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );
        update_post_meta( $attachment_id, '_ge_catalog_asset', 'marketing/' . basename( $filename ) );
        set_post_thumbnail( $product_id, $attachment_id );
    }

    private static function build_attributes( $definitions ) {
        $attributes = array();
        $position   = 0;
        foreach ( $definitions as $name => $options ) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id( 0 );
            $attribute->set_name( $name );
            $attribute->set_options( array_values( $options ) );
            $attribute->set_position( $position++ );
            $attribute->set_visible( true );
            $attribute->set_variation( false );
            $attributes[] = $attribute;
        }
        return $attributes;
    }
}
