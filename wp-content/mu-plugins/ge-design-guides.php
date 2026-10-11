<?php
/** Plugin Name: Graphex · Guías y plantillas de diseño
 * Description: Descargas de preparación y referencias originales por opción, sin reglas de precio ni cambios de integración.
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
        wp_enqueue_style('ge-design-guides',self::url('guides.css'),array(), '1.0');
        if ($product) wp_enqueue_script('ge-design-guides',self::url('guides.js'),array(),'1.0',true);
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
        global $product;$rows=self::wind_rows($product);if(!$rows)return;
        $first=$rows[0];$family=$first['family'];
        echo '<section class="gx-design-downloads" data-gx-design-options="'.esc_attr(wp_json_encode($rows)).'" aria-labelledby="gx-design-heading"><h2 id="gx-design-heading">Prepará el archivo</h2>';
        echo '<p><a href="'.esc_url(self::url('guia-'.$family.'.pdf')).'" target="_blank" rel="noopener">Guía PDF de este producto</a> · <a href="'.esc_url(self::index_url()).'">Todas las plantillas</a></p>';
        echo '<details><summary>Plantilla de la opción elegida</summary><p data-gx-design-label>'.esc_html($first['label']).'</p><p data-gx-design-note role="status">'.esc_html($first['note']).'</p>';
        echo '<a data-gx-design-source href="'.esc_url($first['supplier_template_url']?:$first['source_url']).'" target="_blank" rel="noopener noreferrer">'.esc_html($first['supplier_template_url']?'PDF original Grupo Wind Banners':'Ver modelo en la fuente').'</a>';
        echo '<p class="gx-design-small">Confirmá matriz, escala y caras antes de exportar. El PDF original conserva autoría del proveedor; la guía Graphex se entrega por separado.</p></details></section>';
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
            <div class="gx-design-row"><div><h3>Windbanners y textiles</h3><p>Elegí forma, medida y caras. Las matrices originales conservan la autoría de Grupo Wind Banners; confirmá el archivo técnico antes de diseñar.</p></div><a href="<?php echo esc_url(self::url('guia-windbanners-graphex.pdf')); ?>" target="_blank" rel="noopener">Guía PDF de textiles</a></div>
            <h2>Buscá tu modelo textil</h2>
            <p>Las opciones sin matriz o con diferencias de rotulado se identifican abajo. No escales una plantilla similar para reemplazarlas.</p>
            <?php $groups=array();foreach(self::catalog() as $row)$groups[$row['family']][]=$row;
            $definitions=class_exists('GE_WTP_Windbanners_Catalog')?GE_WTP_Windbanners_Catalog::products():array();
            foreach($groups as $family=>$rows):$name=isset($definitions[$family]['name'])?$definitions[$family]['name']:ucwords(str_replace('-',' ',$family)); ?>
                <details class="gx-design-family"><summary><?php echo esc_html($name); ?> <span><?php echo count($rows); ?> opciones</span></summary><p><a href="<?php echo esc_url(self::url('guia-'.$family.'.pdf')); ?>" target="_blank" rel="noopener">Descargar guía de <?php echo esc_html($name); ?></a></p>
                <ul><?php foreach($rows as $row): ?><li><strong><?php echo esc_html($row['label']); ?></strong><p><?php echo esc_html($row['note']); ?></p><?php if($row['supplier_template_url']): ?><a href="<?php echo esc_url($row['supplier_template_url']); ?>" target="_blank" rel="noopener noreferrer">PDF original Grupo Wind Banners<?php echo $row['status']==='supplier_label_conflict'?' · por confirmar':''; ?></a><?php elseif($row['status']!=='not_applicable'): ?><a href="<?php echo esc_url(home_url('/cliente-markcom/?seccion=personalizado')); ?>">Solicitar la matriz exacta</a><?php endif; ?></li><?php endforeach; ?></ul></details>
            <?php endforeach; ?>
            <p class="gx-design-small">Versión 1.0 · 11/10/2026. Las bases PDF pueden complementarse con plantillas Canva cuando estén disponibles. La exportación siempre requiere revisión técnica.</p>
        </div>
        <?php return ob_get_clean();
    }
}
GE_Design_Guides::init();
