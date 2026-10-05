<?php
/**
 * Plugin Name: Graphex · Preparación de tarjetas
 * Description: Plantillas y guía de tarjetas sobre el configurador y la carga privada existentes.
 */
defined( 'ABSPATH' ) || exit;

final class GE_Business_Cards {
    const ASSET_OPTION = 'ge_business_cards_image_id_v1';

    public static function init() {
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render' ), 28 );
        add_filter( 'woocommerce_product_get_image_id', array( __CLASS__, 'image_id' ), 30, 2 );
    }

    private static function applies( $product ) {
        return $product instanceof WC_Product && in_array( $product->get_slug(), array( 'tarjetas-personales', 'tarjetas-express' ), true );
    }

    public static function image_id( $image_id, $product ) {
        if ( ! self::applies( $product ) ) { return $image_id; }
        $replacement = absint( get_option( self::ASSET_OPTION, 0 ) );
        return $replacement && 'attachment' === get_post_type( $replacement ) && wp_attachment_is_image( $replacement ) ? $replacement : $image_id;
    }

    public static function render() {
        global $product;
        if ( ! self::applies( $product ) ) { return; }
        $base = content_url( '/mu-plugins/ge-business-cards/' );
        $catalog = 'https://www.canva.com/s/templates?query=&adj=eyJFIjp7IkEiOiJ0QUNaQ3NIdzBwQSJ9fQ';
        ?>
        <section class="ge-storefront-upload ge-business-cards" aria-labelledby="ge-cards-heading">
            <h3 id="ge-cards-heading">Prepará tu diseño de 5 × 9 cm</h3>
            <p>Esta plantilla corresponde a la medida <strong>5 × 9 cm</strong> del configurador: tarjeta terminada de <strong>90 × 50 mm</strong>, archivo de <strong>95 × 65 mm</strong> y área segura de <strong>80 × 40 mm</strong>, centrada. Para las otras medidas, solicitá la plantilla correspondiente antes de diseñar.</p>
            <p>Para doble faz, entregá un PDF de dos páginas: frente y dorso. Las imágenes deben tener 300 dpi al tamaño de impresión; los textos pueden conservarse vectoriales, con las fuentes incrustadas o convertidas a curvas.</p>
            <p><a class="button" href="<?php echo esc_url( $catalog ); ?>" target="_blank" rel="noopener noreferrer">Abrir catálogo en Canva</a></p>
            <p>Elegí una plantilla, ajustá el tamaño y editá tu diseño. Descargá <strong>PDF para impresión</strong>, sin agregar marcas de corte ni sangrado extra, y cargalo en «Archivos de producción» de esta ficha. Revisaremos el archivo antes de producir.</p>
            <p><a href="<?php echo esc_url( $base . 'plantilla-tarjetas-95x65.pdf' ); ?>" download>Descargar plantilla de dos páginas</a> · <a href="<?php echo esc_url( $base . 'guia-tarjetas-graphex.pdf' ); ?>" target="_blank" rel="noopener">Ver guía de preparación</a></p>
            <details><summary>Ver medidas y zonas de seguridad</summary><p><a href="<?php echo esc_url( $base . 'plantilla-tarjetas-95x65-con-guias.pdf' ); ?>" target="_blank" rel="noopener">Descargar referencia con guías</a>. Usala para consultar las medidas; sus líneas de referencia no deben quedar en el archivo final.</p></details>
        </section>
        <?php
    }
}
GE_Business_Cards::init();
