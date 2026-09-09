<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Catalog {
    const BNA_SOURCE_URL = 'https://www.bna.com.ar/Personas';
    const BNA_CRON_HOOK = 'ge_wtp_refresh_bna_rate';

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
            'fachada-5x1-lona-instalada' => array(
                'number'      => '05C',
                'group'       => 'fachada',
                'name'        => 'Fachada 5 × 1 m — lona instalada',
                'description' => 'Lona front brillo 13 oz para exterior, medida de producción 5,10 × 1,10 m e instalación estándar sobre una estructura existente apta. Sujeto a relevamiento; no incluye altura especial, andamio, hidroelevador, permisos ni reparación del soporte.',
                'category'    => 'Fachada especial',
                'prices'      => array( 1 => 221892 ),
            ),
            'fachada-5x1-estructura-instalada' => array(
                'number'      => '05D',
                'group'       => 'fachada',
                'name'        => 'Fachada 5 × 1 m — lona, estructura e instalación',
                'description' => 'Lona front brillo 13 oz, bastidor modular de caño estructural 20 × 20 mm, fabricación, tensado e instalación estándar en CABA. Sujeto a relevamiento; no incluye altura especial, andamio, hidroelevador, permisos ni trabajos de albañilería.',
                'category'    => 'Fachada especial',
                'prices'      => array( 1 => 509232 ),
            ),
            'fachada-10x1-lona-instalada' => array(
                'number'      => '05E',
                'group'       => 'fachada',
                'name'        => 'Fachada 10 × 1 m — lona instalada',
                'description' => 'Lona front brillo 13 oz para exterior, medida de producción 10,10 × 1,10 m e instalación estándar sobre una estructura existente apta. Sujeto a relevamiento; no incluye altura especial, andamio, hidroelevador, permisos ni reparación del soporte.',
                'category'    => 'Fachada especial',
                'prices'      => array( 1 => 391982 ),
            ),
            'fachada-10x1-estructura-instalada' => array(
                'number'      => '05F',
                'group'       => 'fachada',
                'name'        => 'Fachada 10 × 1 m — lona, estructura e instalación',
                'description' => 'Lona front brillo 13 oz, bastidor modular reforzado de caño estructural 20 × 20 mm, fabricación, tensado e instalación estándar en CABA. Sujeto a relevamiento; no incluye altura especial, andamio, hidroelevador, permisos ni trabajos de albañilería.',
                'category'    => 'Fachada especial',
                'prices'      => array( 1 => 927282 ),
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

    public static function cron_schedules( $schedules ) {
        $schedules['ge_every_four_hours'] = array(
            'interval' => 4 * HOUR_IN_SECONDS,
            'display'  => 'Cada cuatro horas',
        );

        return $schedules;
    }

    public static function ensure_exchange_schedule() {
        if ( ! wp_next_scheduled( self::BNA_CRON_HOOK ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'ge_every_four_hours', self::BNA_CRON_HOOK );
        }
    }

    public static function clear_exchange_schedule() {
        wp_clear_scheduled_hook( self::BNA_CRON_HOOK );
    }

    public static function refresh_exchange_rate( $force = false ) {
        if ( ! $force && get_transient( 'ge_wtp_bna_refresh_lock' ) ) {
            return self::exchange_rate();
        }

        set_transient( 'ge_wtp_bna_refresh_lock', '1', MINUTE_IN_SECONDS );
        $response = wp_remote_get(
            self::BNA_SOURCE_URL,
            array(
                'timeout'     => 20,
                'redirection' => 3,
                'user-agent'  => 'GraphExpress/1.1; ' . home_url( '/' ),
            )
        );

        if ( is_wp_error( $response ) ) {
            update_option( 'ge_wtp_bna_last_error', $response->get_error_message(), false );
            delete_transient( 'ge_wtp_bna_refresh_lock' );
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( 200 !== $status || '' === $body ) {
            $error = new WP_Error( 'bna_http_error', 'Banco Nación respondió HTTP ' . $status . '.' );
            update_option( 'ge_wtp_bna_last_error', $error->get_error_message(), false );
            delete_transient( 'ge_wtp_bna_refresh_lock' );
            return $error;
        }

        $quote = self::parse_bna_billet_quote( $body );
        if ( is_wp_error( $quote ) ) {
            update_option( 'ge_wtp_bna_last_error', $quote->get_error_message(), false );
            delete_transient( 'ge_wtp_bna_refresh_lock' );
            return $quote;
        }

        update_option( 'ge_wtp_bna_sell_rate', $quote['sell'], false );
        update_option( 'ge_wtp_bna_rate_label', 'Dólar billete vendedor Banco Nación', false );
        update_option( 'ge_wtp_bna_rate_updated_at', $quote['updated_at'], false );
        update_option( 'ge_wtp_bna_source_date', $quote['source_date'], false );
        delete_option( 'ge_wtp_bna_last_error' );
        delete_transient( 'ge_wtp_bna_refresh_lock' );

        return $quote['sell'];
    }

    public static function parse_bna_billet_quote( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'bna_dom_missing', 'La extensión DOM de PHP no está disponible.' );
        }

        $previous = libxml_use_internal_errors( true );
        $document = new DOMDocument();
        $loaded = $document->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return new WP_Error( 'bna_invalid_html', 'No se pudo interpretar la página del Banco Nación.' );
        }

        $xpath = new DOMXPath( $document );
        $rows = $xpath->query( '//*[@id="billetes"]//tr[td[contains(normalize-space(.), "Dolar U.S.A")]]' );
        if ( ! $rows || 0 === $rows->length ) {
            return new WP_Error( 'bna_quote_missing', 'No se encontró la cotización del dólar billete.' );
        }

        $cells = $xpath->query( './td', $rows->item( 0 ) );
        if ( ! $cells || $cells->length < 3 ) {
            return new WP_Error( 'bna_sell_missing', 'No se encontró el valor vendedor del Banco Nación.' );
        }

        $raw_sell = preg_replace( '/[^0-9,.]/', '', $cells->item( 2 )->textContent );
        if ( false !== strpos( $raw_sell, ',' ) ) {
            $raw_sell = str_replace( '.', '', $raw_sell );
            $raw_sell = str_replace( ',', '.', $raw_sell );
        }
        $sell = (float) $raw_sell;
        if ( $sell < 500 || $sell > 5000 ) {
            return new WP_Error( 'bna_sell_invalid', 'El valor vendedor recibido está fuera del rango de seguridad.' );
        }

        $date_nodes = $xpath->query( '//*[@id="billetes"]//th[contains(concat(" ", normalize-space(@class), " "), " fechaCot ")]' );
        $source_date = $date_nodes && $date_nodes->length ? trim( $date_nodes->item( 0 )->textContent ) : wp_date( 'd/m/Y' );

        return array(
            'sell'        => $sell,
            'source_date' => $source_date,
            'updated_at'  => current_time( 'mysql' ),
        );
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
