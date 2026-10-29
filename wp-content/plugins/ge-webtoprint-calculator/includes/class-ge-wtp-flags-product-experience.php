<?php
defined('ABSPATH') || exit;
/** Banderas pilot: existing native calculator, reviewed family reference and in-product resources. */
final class GE_WTP_Flags_Product_Experience {
    const SLUG='windbanners-banderas-sublimadas';
    public static function init(){add_filter('body_class',array(__CLASS__,'body_class'));add_action('wp',array(__CLASS__,'layout'),150);add_action('wp_enqueue_scripts',array(__CLASS__,'assets'),160);add_action('ge_storefront_product_resources',array(__CLASS__,'resources'),10,2);}
    public static function is_product(){return function_exists('is_product')&&is_product()&&get_post_field('post_name',get_queried_object_id())===self::SLUG;}
    public static function body_class($classes){if(self::is_product())$classes[]='gx-flags-product';return $classes;}
    public static function layout(){
        if(!self::is_product())return;
        remove_action('woocommerce_single_product_summary',array('GE_WTP_Catalog_Reviewed_Media','gallery'),26);
        remove_action('woocommerce_single_product_summary',array('GE_WTP_Registered_Templates','product_panel'),28);
        remove_action('woocommerce_before_single_product_summary','woocommerce_show_product_images',20);
        add_action('woocommerce_before_single_product_summary',array(__CLASS__,'media'),20);
        if(class_exists('GE_Product_Experience')){remove_action('woocommerce_before_single_product',array('GE_Product_Experience','intro'),20);add_action('woocommerce_after_single_product_summary',array(__CLASS__,'more'),4);}
        add_action('woocommerce_single_product_summary',array(__CLASS__,'heading'),4);
    }
    public static function assets(){if(!self::is_product())return;wp_enqueue_style('gx-flags-product',GE_WTP_PLUGIN_URL.'assets/css/flags-product.css',array('ge-storefront'),'20261028.1');wp_enqueue_script('gx-flags-product',GE_WTP_PLUGIN_URL.'assets/js/flags-product.js',array('ge-storefront'),'20261028.1',true);}
    public static function heading(){echo '<div class="gx-flags-heading"><span class="gx-flags-eyebrow">TEXTILES PERSONALIZADOS</span>';woocommerce_template_single_title();woocommerce_template_single_excerpt();echo '</div>';}
    public static function more(){echo '<section class="gx-flags-more"><h2>Información para preparar tu bandera</h2>';if(class_exists('GE_WTP_Knowledge_Base'))GE_WTP_Knowledge_Base::quick_help();echo '<p>Confirmamos el diseño y las terminaciones antes de producir. Elegí la medida en el configurador y adjuntá tu archivo de impresión.</p></section>';}
    public static function reference_id($product){$map=get_post_meta($product->get_id(),GE_WTP_Catalog_Reviewed_Media::META,true);return !empty($map['wb-210']['ids'][0])?(int)$map['wb-210']['ids'][0]:0;}
    public static function media(){global $product;if(!$product)return;$reference=self::reference_id($product);$mockup=$product->get_image_id();$items=array();
        if($reference)$items[]=array('id'=>$reference,'label'=>'Referencia de bandera');
        if($mockup && $mockup!==$reference)$items[]=array('id'=>$mockup,'label'=>'Mockup ilustrativo');
        if(!$items)return;
        ?><section class="gx-flags-media" aria-label="Imágenes de banderas sublimadas" data-gx-flags-media><?php $first=$items[0];$url=wp_get_attachment_image_url($first['id'],'full'); ?><a class="gx-flags-main" data-gx-flags-full href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo wp_get_attachment_image($first['id'],'large',false,array('data-gx-flags-image'=>'','loading'=>'eager','fetchpriority'=>'high','alt'=>'Banderas sublimadas — referencia visual')); ?></a><div class="gx-flags-thumbnails" role="group" aria-label="Elegir imagen"><?php foreach($items as $i=>$item){$full=wp_get_attachment_image_url($item['id'],'full');$large=wp_get_attachment_image_url($item['id'],'large'); ?><button type="button" data-gx-flags-thumb data-src="<?php echo esc_url($large); ?>" data-full="<?php echo esc_url($full); ?>" data-label="<?php echo esc_attr($item['label']); ?>" aria-pressed="<?php echo $i===0?'true':'false'; ?>"><?php echo wp_get_attachment_image($item['id'],'thumbnail',false,array('alt'=>'')); ?><span><?php echo esc_html($item['label']); ?></span></button><?php } ?></div><p class="gx-flags-media-caption"><span data-gx-flags-caption><?php echo esc_html($first['label']); ?></span> · Personalizamos según la medida y el diseño que elijas.</p></section><?php
    }
    public static function resources($product,$config){
        if(!$product||$product->get_slug()!==self::SLUG)return;
        ?><details class="gx-flags-resources" data-gx-flags-resource><summary>Descargar plantilla y preparar el archivo</summary><div class="gx-flags-resources-body"><?php GE_WTP_Registered_Templates::product_panel(); ?></div></details><?php
    }
}
GE_WTP_Flags_Product_Experience::init();
