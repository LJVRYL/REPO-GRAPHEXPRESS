<?php

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo amplio de productos promocionales y ecológicos.
 *
 * El snapshot incluido ya contiene precios Graph Express y rutas de imágenes
 * locales. Ningún recurso público depende del sitio del proveedor.
 */
final class GE_WTP_Promotional_Catalog {
    const DATA_FILE   = 'data/marketing-catalog.json';
    const CATALOG_KEY = 'marketing-promotional-';
    const SOURCE_NAME = 'Elementi';
    const SOURCE_DATE = '2026-09-10';
    const MARGIN      = 30;

    private static function category_definitions() {
        return array(
            'merchandising' => array(
                'name'        => 'Merchandising',
                'parent'      => '',
                'legacy_slug' => 'marketing',
                'description' => 'Productos promocionales, acreditaciones, regalos y soluciones para eventos.',
            ),
            'merchandising-drinkware' => array(
                'name'        => 'Drinkware',
                'parent'      => 'merchandising',
                'legacy_slug' => 'marketing-drinkware',
                'description' => 'Botellas, vasos, mates y artículos reutilizables para bebidas.',
            ),
            'merchandising-escritorio' => array(
                'name'        => 'Escritorio',
                'parent'      => 'merchandising',
                'legacy_slug' => 'marketing-escritorio',
                'description' => 'Accesorios corporativos para escritorios, oficinas y espacios de trabajo.',
            ),
            'merchandising-escritura' => array(
                'name'        => 'Escritura',
                'parent'      => 'merchandising',
                'legacy_slug' => 'marketing-escritura',
                'description' => 'Bolígrafos y artículos de escritura personalizados.',
            ),
            'merchandising-expo-congreso' => array(
                'name'        => 'Expo y congresos',
                'parent'      => 'merchandising',
                'legacy_slug' => 'marketing-expo-congreso',
                'description' => 'Productos para acreditaciones, ferias, congresos y acciones promocionales.',
            ),
            'merchandising-libretas' => array(
                'name'        => 'Libretas',
                'parent'      => 'merchandising',
                'legacy_slug' => 'marketing-libretas',
                'description' => 'Libretas, cuadernos y anotadores para regalos corporativos.',
            ),
            'merchandising-tecnologia' => array(
                'name'        => 'Tecnología',
                'parent'      => 'merchandising',
                'legacy_slug' => 'marketing-tecnologia',
                'description' => 'Accesorios tecnológicos y soluciones de carga personalizadas.',
            ),
            'linea-ecologica' => array(
                'name'        => 'Línea ecológica',
                'parent'      => '',
                'description' => 'Productos reutilizables y materiales con menor impacto ambiental.',
            ),
        );
    }

    private static function products() {
        $path = GE_WTP_PLUGIN_DIR . self::DATA_FILE;
        if ( ! is_readable( $path ) ) { return array(); }
        $products = json_decode( (string) file_get_contents( $path ), true );
        return is_array( $products ) ? $products : array();
    }

    public static function sync() {
        if ( ! class_exists( 'WC_Product_Simple' ) ) {
            return new WP_Error( 'woocommerce_required', 'WooCommerce debe estar activo para sincronizar el catálogo.' );
        }

        $products = self::products();
        if ( ! $products ) {
            return new WP_Error( 'catalog_data_missing', 'No se pudo leer el catálogo promocional.' );
        }

        $category_ids = self::sync_categories();
        if ( is_wp_error( $category_ids ) ) { return $category_ids; }

        self::retire_removed_products( wp_list_pluck( $products, 'key' ) );
        $created = 0;
        $updated = 0;
        $position = 700;

        foreach ( $products as $data ) {
            if ( empty( $data['key'] ) || empty( $data['name'] ) || empty( $data['options'] ) ) { continue; }

            $catalog_key = self::CATALOG_KEY . sanitize_key( $data['key'] );
            $existing    = get_posts(
                array(
                    'post_type'      => 'product',
                    'post_status'    => array( 'publish', 'draft', 'private' ),
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                    'meta_key'       => '_ge_public_catalog_key',
                    'meta_value'     => $catalog_key,
                )
            );
            if ( count( $existing ) > 1 ) {
                self::retire_duplicates( array_slice( $existing, 1 ) );
                $existing = array( reset( $existing ) );
            }
            $is_new  = empty( $existing );
            $product = $is_new ? new WC_Product_Simple() : wc_get_product( $existing[0] );
            if ( ! $product ) { continue; }

            $options = array_values( array_filter( (array) $data['options'], function ( $option ) {
                return isset( $option['price'] ) && is_numeric( $option['price'] ) && (float) $option['price'] > 0;
            } ) );
            if ( ! $options ) { continue; }

            $product->set_name( sanitize_text_field( $data['name'] ) );
            $product->set_slug( 'merchandising-' . sanitize_title( $data['key'] ) );
            $product->set_status( 'publish' );
            $product->set_catalog_visibility( 'visible' );
            $description = wp_kses_post( self::clean_public_text( $data['description'] ?? '' ) );
            $product->set_description( wpautop( $description ) );
            $product->set_short_description( $description );
            $sku_base = strtoupper( substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $data['key'] ), 0, 18 ) );
            $sku = 'GE-MKT-' . $sku_base . '-' . strtoupper( substr( md5( (string) $data['key'] ), 0, 7 ) );
            if ( $product->get_sku() !== $sku ) { $product->set_sku( $sku ); }
            $product->set_regular_price( '' );
            $product->set_sale_price( '' );
            $product->set_category_ids( self::product_category_ids( (array) ( $data['categories'] ?? array() ), $category_ids ) );
            $product->set_menu_order( $position++ );
            $product->set_reviews_allowed( false );
            $product->set_attributes( self::build_attributes( $options ) );
            $product_id = $product->save();

            $sections = self::public_sections( $options );
            $rules    = array();
            foreach ( $options as $index => $option ) {
                $rules[ 's0-r' . $index . '-c1' ] = array(
                    'min_qty' => max( 1, (int) ( $option['min'] ?? 1 ) ),
                    'step'    => max( 1, (int) ( $option['step'] ?? 1 ) ),
                );
            }

            update_post_meta( $product_id, '_ge_public_catalog_key', $catalog_key );
            delete_post_meta( $product_id, '_ge_quote_only' );
            update_post_meta( $product_id, '_ge_show_reference_price', 'yes' );
            update_post_meta( $product_id, '_ge_reference_price_min', min( wp_list_pluck( $options, 'price' ) ) );
            update_post_meta( $product_id, '_ge_public_price_sections', $sections );
            update_post_meta( $product_id, '_ge_public_price_notes', array(
                'Elegí la combinación de color, medida y personalización disponible.',
                'La cantidad mínima y el múltiplo de compra se actualizan según la variante.',
                'Producción sujeta a aprobación del archivo y confirmación de disponibilidad.',
                'Valores Graph Express antes de IVA.',
            ) );
            update_post_meta( $product_id, '_ge_option_label', 'Variante' );
            update_post_meta( $product_id, '_ge_minimum_quantity', max( 1, (int) ( $options[0]['min'] ?? 1 ) ) );
            update_post_meta( $product_id, '_ge_quantity_step', max( 1, (int) ( $options[0]['step'] ?? 1 ) ) );
            update_post_meta( $product_id, '_ge_option_rules', $rules );
            delete_post_meta( $product_id, '_ge_supplier_costs' );
            update_post_meta( $product_id, '_ge_supplier_margin', self::MARGIN );
            update_post_meta( $product_id, '_ge_supplier_source', self::SOURCE_NAME );
            update_post_meta( $product_id, '_ge_supplier_source_date', self::SOURCE_DATE );
            delete_post_meta( $product_id, '_ge_supplier_source_url' );

            if ( ! $product->get_image_id() ) {
                self::attach_catalog_images( (array) ( $data['images'] ?? array() ), $product_id, $data['name'] );
            }

            $is_new ? $created++ : $updated++;
        }

        clean_term_cache( array_values( $category_ids ), 'product_cat' );
        return array( 'created' => $created, 'updated' => $updated, 'total' => $created + $updated );
    }

    private static function sync_categories() {
        $definitions = self::category_definitions();
        $ids = array();

        $bags = get_term_by( 'slug', 'bolsas', 'product_cat' );
        if ( $bags && ! is_wp_error( $bags ) ) { $ids['bolsas'] = (int) $bags->term_id; }

        foreach ( $definitions as $slug => $definition ) {
            if ( $definition['parent'] ) { continue; }
            $result = self::upsert_category( $slug, $definition, 0 );
            if ( is_wp_error( $result ) ) { return $result; }
            $ids[ $slug ] = $result;
        }
        self::move_category_products( 'marketing', 'merchandising' );
        foreach ( $definitions as $slug => $definition ) {
            if ( ! $definition['parent'] ) { continue; }
            $result = self::upsert_category( $slug, $definition, $ids[ $definition['parent'] ] );
            if ( is_wp_error( $result ) ) { return $result; }
            $ids[ $slug ] = $result;
        }

        $legacy_parent = get_term_by( 'slug', 'marketing', 'product_cat' );
        if ( $legacy_parent && ! is_wp_error( $legacy_parent ) ) {
            wp_delete_term( (int) $legacy_parent->term_id, 'product_cat' );
        }

        if ( empty( $ids['bolsas'] ) ) {
            $result = self::upsert_category( 'bolsas', array( 'name' => 'Bolsas', 'description' => 'Bolsas reutilizables y packaging.', 'parent' => '' ), 0 );
            if ( is_wp_error( $result ) ) { return $result; }
            $ids['bolsas'] = $result;
        }
        return $ids;
    }

    private static function upsert_category( $slug, $definition, $parent_id ) {
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( ! $term && ! empty( $definition['legacy_slug'] ) ) {
            $term = get_term_by( 'slug', $definition['legacy_slug'], 'product_cat' );
        }
        $args = array( 'slug' => $slug, 'parent' => $parent_id, 'description' => $definition['description'] );
        if ( ! $term ) {
            $inserted = wp_insert_term( $definition['name'], 'product_cat', $args );
            return is_wp_error( $inserted ) ? $inserted : (int) $inserted['term_id'];
        }
        wp_update_term( $term->term_id, 'product_cat', $args );
        return (int) $term->term_id;
    }

    private static function product_category_ids( $categories, $category_ids ) {
        $legacy_map = array(
            'marketing-drinkware'      => 'merchandising-drinkware',
            'marketing-escritorio'     => 'merchandising-escritorio',
            'marketing-escritura'      => 'merchandising-escritura',
            'marketing-expo-congreso'  => 'merchandising-expo-congreso',
            'marketing-libretas'       => 'merchandising-libretas',
            'marketing-tecnologia'     => 'merchandising-tecnologia',
        );
        $ids = isset( $category_ids['merchandising'] ) ? array( $category_ids['merchandising'] ) : array();
        foreach ( $categories as $slug ) {
            $slug = $legacy_map[ $slug ] ?? $slug;
            if ( isset( $category_ids[ $slug ] ) ) { $ids[] = $category_ids[ $slug ]; }
            if ( 0 === strpos( (string) $slug, 'merchandising-' ) && isset( $category_ids['merchandising'] ) ) { $ids[] = $category_ids['merchandising']; }
        }
        return array_values( array_unique( array_map( 'absint', $ids ) ) );
    }

    private static function move_category_products( $from_slug, $to_slug ) {
        $from = get_term_by( 'slug', $from_slug, 'product_cat' );
        $to   = get_term_by( 'slug', $to_slug, 'product_cat' );
        if ( ! $from || is_wp_error( $from ) || ! $to || is_wp_error( $to ) ) { return; }
        $product_ids = get_objects_in_term( (int) $from->term_id, 'product_cat' );
        if ( is_wp_error( $product_ids ) ) { return; }
        foreach ( $product_ids as $product_id ) {
            wp_set_object_terms( (int) $product_id, (int) $to->term_id, 'product_cat', true );
            wp_remove_object_terms( (int) $product_id, (int) $from->term_id, 'product_cat' );
        }
    }

    private static function public_sections( $options ) {
        $rows = array();
        foreach ( $options as $option ) {
            $rows[] = array( self::option_label( (array) ( $option['attributes'] ?? array() ) ), '$ ' . number_format( (float) $option['price'], 2, ',', '.' ) );
        }
        return array( array( 'title' => '', 'columns' => array( 'Variante', 'Precio unitario' ), 'rows' => $rows ) );
    }

    private static function option_label( $attributes ) {
        $parts = array();
        foreach ( $attributes as $key => $value ) {
            $label = self::attribute_label( $key );
            $parts[] = $label . ': ' . self::humanize( $value );
        }
        return $parts ? implode( ' · ', $parts ) : 'Opción disponible';
    }

    private static function attribute_label( $key ) {
        $key = str_replace( array( 'attribute_pa_', 'attribute_' ), '', (string) $key );
        $known = array( 'color' => 'Color', 'medidas' => 'Medida', 'personalizacion' => 'Personalización', 'capacidad' => 'Capacidad', 'modelo' => 'Modelo' );
        return $known[ $key ] ?? self::humanize( $key );
    }

    private static function humanize( $value ) {
        $value = str_replace( array( '-', '_' ), ' ', (string) $value );
        return mb_convert_case( trim( preg_replace( '/\s+/', ' ', $value ) ), MB_CASE_TITLE, 'UTF-8' );
    }

    private static function build_attributes( $options ) {
        $definitions = array();
        foreach ( $options as $option ) {
            foreach ( (array) ( $option['attributes'] ?? array() ) as $key => $value ) {
                $label = self::attribute_label( $key );
                $definitions[ $label ][] = self::humanize( $value );
            }
        }
        $attributes = array();
        $position = 0;
        foreach ( $definitions as $name => $values ) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id( 0 );
            $attribute->set_name( $name );
            $attribute->set_options( array_values( array_unique( array_filter( $values ) ) ) );
            $attribute->set_position( $position++ );
            $attribute->set_visible( true );
            $attribute->set_variation( false );
            $attributes[] = $attribute;
        }
        return $attributes;
    }

    private static function attach_catalog_images( $filenames, $product_id, $title ) {
        $attachment_ids = array();
        foreach ( array_slice( array_values( array_unique( $filenames ) ), 0, 6 ) as $index => $filename ) {
            $source = GE_WTP_PLUGIN_DIR . 'assets/images/marketing/catalog/' . basename( $filename );
            if ( ! is_readable( $source ) ) { continue; }
            $contents = file_get_contents( $source );
            if ( false === $contents ) { continue; }
            $upload = wp_upload_bits( basename( $filename ), null, $contents );
            if ( ! empty( $upload['error'] ) ) { continue; }

            require_once ABSPATH . 'wp-admin/includes/image.php';
            $mime = wp_check_filetype( $upload['file'] );
            $attachment_id = wp_insert_attachment( array(
                'post_mime_type' => $mime['type'],
                'post_title'     => sanitize_text_field( $title . ( $index ? ' ' . ( $index + 1 ) : '' ) ),
                'post_status'    => 'inherit',
            ), $upload['file'], $product_id );
            if ( is_wp_error( $attachment_id ) ) { continue; }
            wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );
            $attachment_ids[] = (int) $attachment_id;
        }

        if ( ! $attachment_ids ) { return; }
        set_post_thumbnail( $product_id, array_shift( $attachment_ids ) );
        update_post_meta( $product_id, '_product_image_gallery', implode( ',', $attachment_ids ) );
    }

    private static function clean_public_text( $text ) {
        $text = preg_replace( '/elementi(?:\s+s\.?a\.?)?/i', 'Graph Express', (string) $text );
        $text = preg_replace( '/https?:\/\/\S+/i', '', $text );
        return trim( $text );
    }

    private static function retire_removed_products( $active_keys ) {
        $active = array_map( function ( $key ) { return self::CATALOG_KEY . sanitize_key( $key ); }, $active_keys );
        $existing = get_posts( array(
            'post_type'      => 'product',
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_ge_supplier_source',
            'meta_value'     => self::SOURCE_NAME,
        ) );
        foreach ( $existing as $product_id ) {
            if ( in_array( get_post_meta( $product_id, '_ge_public_catalog_key', true ), $active, true ) ) { continue; }
            $product = wc_get_product( $product_id );
            if ( ! $product ) { continue; }
            $product->set_status( 'draft' );
            $product->set_catalog_visibility( 'hidden' );
            $product->save();
        }
    }

    private static function retire_duplicates( $product_ids ) {
        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) { continue; }
            $product->set_status( 'draft' );
            $product->set_catalog_visibility( 'hidden' );
            $product->save();
            update_post_meta( $product_id, '_ge_catalog_duplicate_retired', current_time( 'mysql' ) );
        }
    }
}
