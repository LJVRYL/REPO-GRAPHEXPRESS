<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Catalog {
    public static function products() {
        return array(
            'adhesivo' => array(
                'number'      => '01',
                'name'        => 'Adhesivo 25 × 25 cm',
                'description' => 'Vinilo impreso full color con corte incluido.',
                'category'    => 'Identidad de punto de venta',
                'prices'      => array( 100 => 1543, 500 => 1482, 1000 => 1379, 5000 => 1349 ),
            ),
            'cartel' => array(
                'number'      => '02',
                'name'        => 'Cartel 46 × 64 cm',
                'description' => 'Cartel impreso con cinta bifaz y colocación incluida.',
                'category'    => 'Cartelería',
                'prices'      => array( 100 => 5031, 500 => 965, 1000 => 746, 5000 => 604 ),
            ),
            'windflag' => array(
                'number'      => '03',
                'name'        => 'Windflag completo',
                'description' => 'Bandera, estructura, bolso, base cruz y sobrepeso.',
                'category'    => 'Exterior',
                'prices'      => array( 100 => 88920, 500 => 80870, 1000 => 70387, 5000 => 60278 ),
            ),
            'tabla-lubricacion' => array(
                'number'      => '04',
                'name'        => 'Tabla de lubricación',
                'description' => 'PAI 0,5 mm impreso con cinta siliconada y colocación.',
                'category'    => 'Material técnico',
                'prices'      => array( 100 => 21752, 500 => 20471, 1000 => 20015, 5000 => 19525 ),
            ),
            'fachada-lona' => array(
                'number'      => '05A',
                'group'       => 'fachada',
                'name'        => 'Fachada — solo lona',
                'description' => 'Lona impresa preparada para instalación. Instalación y flete se coordinan según ubicación.',
                'category'    => 'Fachada',
                'prices'      => array( 100 => 35946, 500 => 34508, 1000 => 33790, 5000 => 33070 ),
            ),
            'fachada-bastidor' => array(
                'number'      => '05B',
                'group'       => 'fachada',
                'name'        => 'Fachada — lona y bastidor',
                'description' => 'Lona, caño, fabricación, insumos y tensado. Instalación y flete se cotizan según ubicación.',
                'category'    => 'Fachada',
                'prices'      => array( 100 => 119926, 500 => 112009, 1000 => 106621, 5000 => 99269 ),
            ),
            'totem' => array(
                'number'      => '06',
                'name'        => 'Tótem elíptico 60 × 190 cm',
                'description' => 'Tótem autoportante de formato oval para punto de venta.',
                'category'    => 'Exhibición',
                'prices'      => array( 100 => 56732, 500 => 51059, 1000 => 34039, 5000 => 28366 ),
            ),
            'caballete' => array(
                'number'      => '07',
                'name'        => 'Caballete 60 × 95 cm',
                'description' => 'Caballete doble cara en PAI, estructura de madera y pintura.',
                'category'    => 'Exhibición',
                'prices'      => array( 100 => 58402, 500 => 52946, 1000 => 50011, 5000 => 47153 ),
            ),
            'display-4l' => array(
                'number'      => '08',
                'name'        => 'Display corrugado para bidón 4 L',
                'description' => 'Display autoarmable de dos piezas en corrugado impreso.',
                'category'    => 'Display',
                'prices'      => array( 100 => 3581, 500 => 3266, 1000 => 3144, 5000 => 3077 ),
            ),
            'isla-bidones' => array(
                'number'      => '09',
                'name'        => 'Isla para bidones',
                'description' => 'Divisoria en X, impresión bifaz, corte electrónico y terminación.',
                'category'    => 'Display',
                'prices'      => array( 100 => 175519, 500 => 159630, 1000 => 151962, 5000 => 148728 ),
            ),
        );
    }

    public static function get( $key ) {
        $products = self::products();
        return isset( $products[ $key ] ) ? $products[ $key ] : null;
    }

    public static function tiers() {
        return array( 100, 500, 1000, 5000 );
    }

    public static function exchange_rate() {
        return (float) get_option( 'ge_wtp_bna_sell_rate', 0 );
    }

    public static function exchange_label() {
        return (string) get_option( 'ge_wtp_bna_rate_label', 'Dólar vendedor Banco Nación' );
    }

    public static function exchange_updated_at() {
        return (string) get_option( 'ge_wtp_bna_rate_updated_at', '' );
    }

    public static function ars_to_usd( $amount, $rate = null ) {
        $rate = null === $rate ? self::exchange_rate() : (float) $rate;
        if ( $rate <= 0 ) {
            return 0;
        }

        return round( (float) $amount / $rate, 2 );
    }

    public static function sync_products() {
        if ( ! class_exists( 'WC_Product_Simple' ) ) {
            return;
        }

        foreach ( self::products() as $key => $data ) {
            $sku = 'MKC-' . strtoupper( $key );
            $ids = get_posts(
                array(
                    'post_type'      => 'product',
                    'post_status'    => array( 'publish', 'draft', 'private' ),
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_key'       => '_ge_markcom_key',
                    'meta_value'     => $key,
                )
            );

            $product_id = $ids ? (int) $ids[0] : 0;
            if ( ! $product_id ) {
                $product_id = (int) wc_get_product_id_by_sku( $sku );
            }

            $product = $product_id ? wc_get_product( $product_id ) : new WC_Product_Simple();
            if ( ! $product ) {
                $product = new WC_Product_Simple();
            }

            $product->set_name( $data['name'] );
            $product->set_slug( 'markcom-' . $key );
            $product->set_status( 'publish' );
            $product->set_catalog_visibility( 'hidden' );
            $product->set_description( $data['description'] );
            $product->set_short_description( $data['category'] );
            $product->set_virtual( true );
            $product->set_regular_price( (string) min( $data['prices'] ) );
            if ( $product->get_sku() !== $sku ) {
                $product->set_sku( $sku );
            }
            $product_id = $product->save();

            update_post_meta( $product_id, '_ge_markcom_key', $key );
            update_post_meta( $product_id, '_ge_markcom_prices', $data['prices'] );
            update_post_meta( $product_id, '_ge_markcom_only', 'yes' );
        }
    }

    public static function product_id( $key ) {
        $ids = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_ge_markcom_key',
                'meta_value'     => $key,
            )
        );

        return $ids ? (int) $ids[0] : 0;
    }
}
