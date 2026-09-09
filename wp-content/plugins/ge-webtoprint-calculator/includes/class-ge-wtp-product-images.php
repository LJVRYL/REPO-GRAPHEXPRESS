<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Product_Images {
    const VERSION = '3';
    const OPTION = 'ge_wtp_product_reference_images_version';
    const META_ASSET = '_ge_product_reference_asset';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 80 );
        add_action( 'ge_wtp_product_image_sync', array( __CLASS__, 'maybe_sync' ) );
    }

    public static function maybe_schedule() {
        if ( self::VERSION === get_option( self::OPTION ) || ! function_exists( 'wc_get_product' ) ) { return; }
        if ( ! wp_next_scheduled( 'ge_wtp_product_image_sync' ) ) {
            wp_schedule_single_event( time() + 10, 'ge_wtp_product_image_sync' );
        }
    }

    public static function maybe_sync() {
        if ( self::VERSION === get_option( self::OPTION ) || ! function_exists( 'wc_get_product' ) ) { return; }
        if ( get_transient( 'ge_wtp_product_image_sync_lock' ) ) { return; }

        set_transient( 'ge_wtp_product_image_sync_lock', 'yes', 30 * MINUTE_IN_SECONDS );

        try {
            if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 600 ); }
            if ( self::sync() ) { update_option( self::OPTION, self::VERSION, false ); }
        } finally {
            delete_transient( 'ge_wtp_product_image_sync_lock' );
        }
    }

    private static function products() {
        return array(
            'adhesivo-25x25.png' => 'Adhesivo 25 × 25 cm',
            'cartel-46x64.png' => 'Cartel 46 × 64 cm',
            'windflag-completo.png' => 'Windflag completo',
            'tabla-lubricacion.png' => 'Tabla de lubricación',
            'fachada-solo-lona.png' => 'Fachada — solo lona',
            'fachada-lona-bastidor.png' => 'Fachada — lona y bastidor',
            'totem-eliptico-60x190.png' => 'Tótem elíptico 60 × 190 cm',
            'caballete-60x95.png' => 'Caballete 60 × 95 cm',
            'display-corrugado-bidon-4l.png' => 'Display corrugado para bidón 4 L',
            'isla-para-bidones.png' => 'Isla para bidones',
            'lona-front-brillo-9oz.png' => 'Lona front brillo 9 oz',
            'lona-front-brillo-13oz.png' => 'Lona front brillo 13 oz',
            'lona-front-mate-13oz.png' => 'Lona front mate 13 oz',
            'lona-backlight.png' => 'Lona backlight',
            'lona-blackout.png' => 'Lona blackout 15 oz',
            'lona-mesh.png' => 'Lona mesh',
            'vinilo-base-blanca.png' => 'Vinilo base blanca',
            'vinilo-base-gris.png' => 'Vinilo base gris',
            'vinilo-cristal.png' => 'Vinilo cristal',
            'vinilo-microperforado.png' => 'Vinilo microperforado',
            'vinilo-esmerilado-impreso.png' => 'Vinilo esmerilado impreso',
            'vinilo-de-corte.png' => 'Vinilo de corte',
            'vinilo-esmerilado-corte.png' => 'Vinilo esmerilado de corte',
            'vinilo-impreso-troquelado.png' => 'Vinilo impreso y troquelado',
            'papel-fotografico-260g.png' => 'Papel fotográfico 260 g',
            'papel-blueback-150g.png' => 'Papel blueback 150 g',
            'cuerina-plavinil.png' => 'Cuerina Plavinil impresa',
            'lienzo-canvas-270g.png' => 'Lienzo Canvas 270 g',
            'alfombra-impresa-2mm.png' => 'Alfombra impresa 2 mm',
            'iman-impreso-04mm.png' => 'Imán impreso 0,4 mm',
            'portabanner-tensor-simple.png' => 'Portabanner tensor simple 90 × 190 cm',
            'portabanner-tensor-doble.png' => 'Portabanner tensor doble 90 × 190 cm',
            'portabanner-rollup.png' => 'Portabanner Roll Up 85 × 200 cm',
            'back-200x150.png' => 'Back de prensa 200 × 150 cm',
            'back-200x200.png' => 'Back de prensa 200 × 200 cm',
            'back-300x200.png' => 'Back de prensa 300 × 200 cm',
            'placa-pvc-3mm.png' => 'Placa PVC 3 mm impresa',
            'placa-pvc-5mm.png' => 'Placa PVC 5 mm impresa',
            'placa-pai-05mm.png' => 'Placa PAI 0,5 mm impresa',
            'placa-pai-1mm.png' => 'Placa PAI 1 mm impresa',
            'placa-pai-15mm.png' => 'Placa PAI 1,5 mm impresa',
            'placa-pai-2mm.png' => 'Placa PAI 2 mm impresa',
            'plastico-corrugado-22mm.png' => 'Plástico corrugado 2,2 mm impreso',
            'plastico-corrugado-4mm.png' => 'Plástico corrugado 4 mm impreso',
            'cartulina-270g.png' => 'Cartulina 270 g impresa',
            'tickets-lavadero.png' => 'Tickets de lavadero',
            'talonarios-afip.png' => 'Talonarios AFIP',
            'presupuestos-comandas-anotadores.png' => 'Presupuestos, comandas y anotadores',
            'tarjetas-personales.png' => 'Tarjetas personales',
            'etiquetas-impresas.png' => 'Etiquetas impresas',
            'volantes-blanco-negro.png' => 'Volantes blanco y negro',
            'volantes-full-color.png' => 'Volantes full color',
            'imanes-publicitarios.png' => 'Imanes publicitarios',
            'tarjetas-express.png' => 'Tarjetas Express 24–48 h',
            'folletos-express.png' => 'Folletos Express 24–48 h',
            'stickers-publicitarios.png' => 'Stickers publicitarios',
            'bajadas-digitales-color.png' => 'Bajadas digitales color',
        );
    }

    private static function sync() {
        foreach ( self::products() as $filename => $title ) {
            $attachment_id = self::attachment_id( $filename, $title );
            if ( ! $attachment_id ) { return false; }

            $product_id = self::product_id( $title );
            if ( ! $product_id ) { continue; }

            $current_id = (int) get_post_thumbnail_id( $product_id );
            $is_managed = $current_id && (bool) get_post_meta( $current_id, self::META_ASSET, true );
            if ( ! $current_id || $is_managed ) { set_post_thumbnail( $product_id, $attachment_id ); }
        }
        return true;
    }

    private static function product_id( $title ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title = %s LIMIT 1", $title ) );
    }

    private static function attachment_id( $filename, $title ) {
        $existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => self::META_ASSET, 'meta_value' => $filename, 'fields' => 'ids', 'posts_per_page' => 1 ) );
        if ( $existing ) { return (int) $existing[0]; }
        $source_path = GE_WTP_PLUGIN_DIR . 'assets/images/products/generated/' . $filename;
        if ( ! is_file( $source_path ) || ! is_readable( $source_path ) ) { return 0; }
        $upload = wp_upload_bits( 'ge-reference-' . $filename, null, file_get_contents( $source_path ) );
        if ( ! empty( $upload['error'] ) ) { return 0; }
        $filetype = wp_check_filetype( $upload['file'] );
        $attachment_id = wp_insert_attachment( array( 'post_mime_type' => $filetype['type'], 'post_title' => 'Producto — ' . $title, 'post_content' => 'Imagen original generada para el catálogo de Graph Express.', 'post_status' => 'inherit' ), $upload['file'] );
        if ( is_wp_error( $attachment_id ) ) { return 0; }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title . ' — Graph Express' );
        update_post_meta( $attachment_id, self::META_ASSET, $filename );
        update_post_meta( $attachment_id, '_ge_image_source_label', 'Imagen original generada para Graph Express' );
        return (int) $attachment_id;
    }
}
