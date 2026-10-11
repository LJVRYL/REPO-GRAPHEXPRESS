<?php
/** Plugin Name: Graphex · Guías y plantillas de diseño
 * Description: Guías originales Graphex y bases limpias verificadas, separadas del arte.
 */
defined('ABSPATH') || exit;
final class GE_Design_Guides {
    public static function init() {
        add_shortcode('graphex_plantillas',array(__CLASS__,'index'));
        add_action('wp_enqueue_scripts',array(__CLASS__,'assets'),125);
        add_action('woocommerce_single_product_summary',array(__CLASS__,'wind_panel'),28);
        add_action('woocommerce_after_single_product_summary',array(__CLASS__,'flat_panel'),8);
        add_filter('the_content',array(__CLASS__,'guide_link'),30);
    }
    public static function catalog() {
        static $data;
        if (null===$data) { $data=json_decode(file_get_contents(__DIR__.'/ge-design-guides/catalog.json'),true); }
        return is_array($data)&&isset($data['options'])?$data['options']:array();
    }
    public static function url($filename) { return content_url('/mu-plugins/ge-design-guides/'.rawurlencode($filename)); }
    public static function index_url() { return home_url('/plantillas-de-diseno/'); }
    public static function assets() {
        $product=function_exists('is_product')&&is_product();
        if (!$product&&!is_page('plantillas-de-diseno')) return;
        wp_enqueue_style('ge-design-guides',self::url('guides.css'),array(), '2.1');
        if ($product) wp_enqueue_script('ge-design-guides',self::url('guides.js'),array(),'2.1',true);
    }
    public static function guide_link($content) {
        if (is_singular('ge_guide')&&in_the_loop()&&is_main_query()) $content.='<p><a href="'.esc_url(self::index_url()).'">Guías y plantillas PDF de diseño</a></p>';
        return $content;
    }
    public static function wind_rows($product) {
        if (!$product||!method_exists($product,'get_meta')) return array();
        $key=(string)$product->get_meta('_ge_public_catalog_key');
        if (0!==strpos($key,'windbanners-')) return array();
        $family=substr($key,strlen('windbanners-'));
        return array_values(array_filter(self::catalog(),function($r)use($family){return $r['family']===$family;}));
    }
    public static function wind_panel() {
        global $product;if(!self::wind_rows($product))return;
        echo '<section class="gx-design-downloads"><h2>Prepará el archivo</h2><p>Confirmá la medida y terminación antes de diseñar. La base 1:1 queda disponible cuando su geometría está validada.</p><p><a href="'.esc_url(self::url('guia-textiles-graphex.pdf')).'" target="_blank" rel="noopener">Guía Graphex de piezas textiles</a> · <a href="'.esc_url(home_url('/cliente-markcom/?seccion=personalizado')).'">Solicitar plantilla por medida</a> · <a href="'.esc_url(self::index_url()).'">Plantillas propias Graphex</a></p></section>';
    }
    public static function flat_panel() {
        global $product;if(!$product)return;$slug=$product->get_slug();
        if (in_array($slug,array('tarjetas-personales','tarjetas-express'),true)) {
            echo '<p class="gx-design-crosslink"><a href="'.esc_url(self::index_url()).'">Todas las guías y plantillas PDF</a></p>';return;
        }
        if ($slug==='volantes-full-color') {
            echo '<section class="gx-design-downloads" aria-labelledby="gx-flyer-downloads"><h2 id="gx-flyer-downloads">Descargas para diseñar</h2><p>Base horizontal para <strong>20 × 15 cm</strong>, archivo 21 × 16 cm. Simple faz: página 1; doble faz: páginas 1 y 2.</p><p><a href="'.esc_url(self::url('plantilla-volantes-200x150.pdf')).'" download>Base limpia 1:1</a> · <a href="'.esc_url(self::url('guia-volantes-graphex.pdf')).'" target="_blank" rel="noopener">Guía PDF</a></p><details><summary>Referencia con corte y seguridad</summary><p><a href="'.esc_url(self::url('referencia-volantes-200x150.pdf')).'" target="_blank" rel="noopener">Abrir referencia</a>. Sus líneas no deben salir en el arte. Para otra medida, seguí el contrato de la ficha y recreá el documento: no estires esta base.</p></details><p><a href="'.esc_url(self::index_url()).'">Todas las plantillas</a></p></section>';return;
        }
        if (!self::wind_rows($product)) echo '<p class="gx-design-crosslink"><a href="'.esc_url(self::url('guia-general-graphex.pdf')).'" target="_blank" rel="noopener">Guía general de preparación PDF</a> · <a href="'.esc_url(self::index_url()).'">Plantillas por producto</a></p>';
    }
    public static function index() {
        ob_start(); ?>
        <div class="gx-design-library">
            <p class="gx-design-kicker">GRAPHEX · ARCHIVOS PARA DISEÑAR</p>
            <h2>Elegí la base de tu producto</h2>
            <p>Descargá la guía por separado y trabajá sobre una base a tamaño real. Antes de producir, revisamos y aprobás la prueba del archivo exacto.</p>
            <div class="gx-design-row"><div><h3>Guía general</h3><p>Medidas, color, resolución, fuentes y entrega.</p></div><a href="<?php echo esc_url(self::url('guia-general-graphex.pdf')); ?>" target="_blank" rel="noopener">Descargar guía PDF</a></div>
            <div class="gx-design-row"><div><h3>Tarjetas · 5 × 9 cm</h3><p>Base 95 × 65 mm, corte 90 × 50 mm y seguridad 80 × 40 mm. Dos páginas: frente y dorso.</p></div><div><a href="<?php echo esc_url(self::url('plantilla-tarjetas-95x65.pdf')); ?>" download>Base limpia</a><a href="<?php echo esc_url(self::url('guia-tarjetas-graphex.pdf')); ?>" target="_blank" rel="noopener">Guía PDF</a><a href="<?php echo esc_url(self::url('plantilla-tarjetas-95x65-con-guias.pdf')); ?>" target="_blank" rel="noopener">Referencia con líneas</a></div></div>
            <div class="gx-design-row"><div><h3>Volantes · 20 × 15 cm</h3><p>Base 21 × 16 cm, sangrado y seguridad de 5 mm. Para otras medidas, seguí la guía y el configurador.</p></div><div><a href="<?php echo esc_url(self::url('plantilla-volantes-200x150.pdf')); ?>" download>Base limpia</a><a href="<?php echo esc_url(self::url('guia-volantes-graphex.pdf')); ?>" target="_blank" rel="noopener">Guía PDF</a><a href="<?php echo esc_url(self::url('referencia-volantes-200x150.pdf')); ?>" target="_blank" rel="noopener">Referencia con líneas</a></div></div>
            <div class="gx-design-row"><div><h3>Piezas textiles y otras medidas</h3><p>Guía propia de preparación. Solicitá la plantilla de tu medida y terminación: no reemplaces una base pendiente por otra parecida.</p></div><div><a href="<?php echo esc_url(self::url('guia-textiles-graphex.pdf')); ?>" target="_blank" rel="noopener">Guía Graphex PDF</a><a href="<?php echo esc_url(home_url('/cliente-markcom/?seccion=personalizado')); ?>">Solicitar plantilla por medida</a></div></div>
            <p class="gx-design-small">Versión 2.0 · 11/10/2026. Las bases PDF pueden complementarse con plantillas Canva cuando estén disponibles. La exportación siempre requiere revisión técnica.</p>
        </div>
        <?php return ob_get_clean();
    }
}
GE_Design_Guides::init();

