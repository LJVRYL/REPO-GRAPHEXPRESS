<?php
defined('ABSPATH') || exit;
/** Product-family galleries share the native storefront configurator and private template gate. */
final class GE_WTP_Flags_Product_Experience {
    const SLUG='windbanners-banderas-sublimadas';
    const VERSION='20261029.5';
    public static function init(){add_filter('body_class',array(__CLASS__,'body_class'));add_action('wp',array(__CLASS__,'layout'),150);add_action('wp_enqueue_scripts',array(__CLASS__,'assets'),160);add_action('ge_storefront_product_resources',array(__CLASS__,'resources'),10,2);}
    public static function supported($slug){return isset(GE_WTP_Catalog_Reviewed_Media::catalog()['products'][$slug]);}
    public static function is_product(){return function_exists('is_product')&&is_product()&&self::supported(get_post_field('post_name',get_queried_object_id()));}
    public static function body_class($classes){if(self::is_product())$classes[]='gx-flags-product';return $classes;}
    public static function layout(){
        if(!self::is_product())return;
        remove_action('woocommerce_single_product_summary',array('GE_WTP_Catalog_Reviewed_Media','gallery'),26);
        remove_action('woocommerce_single_product_summary',array('GE_WTP_Registered_Templates','product_panel'),28);
        remove_action('woocommerce_before_single_product_summary','woocommerce_show_product_images',20);
        add_action('woocommerce_before_single_product_summary',array(__CLASS__,'media'),20);
        if(class_exists('GE_Product_Experience')){remove_action('woocommerce_before_single_product',array('GE_Product_Experience','intro'),20);add_action('woocommerce_after_single_product_summary',array(__CLASS__,'more'),4);}
        add_action('woocommerce_before_single_product',array(__CLASS__,'heading'),20);
    }
    public static function assets(){if(!self::is_product())return;wp_enqueue_style('gx-flags-product',GE_WTP_PLUGIN_URL.'assets/css/flags-product.css',array('ge-storefront'),self::VERSION);wp_enqueue_script('gx-flags-product',GE_WTP_PLUGIN_URL.'assets/js/flags-product.js',array('ge-storefront'),self::VERSION,true);}
    public static function heading(){echo '<header class="gx-flags-heading"><span class="gx-flags-eyebrow">GRAPHEX · PRODUCTOS PERSONALIZADOS</span>';woocommerce_template_single_title();woocommerce_template_single_excerpt();echo '</header>';}
    public static function more(){echo '<section class="gx-flags-more"><h2>Información para preparar tu diseño</h2>';if(class_exists('GE_WTP_Knowledge_Base'))GE_WTP_Knowledge_Base::quick_help();echo '<p>Confirmamos el diseño y las terminaciones antes de producir. Elegí el modelo en el configurador y adjuntá tu archivo de impresión.</p></section>';}
    public static function items($product){
        $items=array();$mockup=(int)$product->get_image_id();
        if($mockup)$items[$mockup]=array('id'=>$mockup,'kind'=>'mockup','label'=>'Mockup ilustrativo','keys'=>array());
        $map=get_post_meta($product->get_id(),GE_WTP_Catalog_Reviewed_Media::META,true);if(!is_array($map))$map=array();
        $catalog=GE_WTP_Catalog_Reviewed_Media::catalog();$row=$catalog['products'][$product->get_slug()]??array();$static=array();$hashes=array();
        foreach(($row['options']??array()) as $key=>$option)foreach(($option['assets']??array()) as $name){
            $asset=$catalog['assets'][$name]??array();if(!in_array($asset['kind']??'',array('render','photo'),true))continue;
            if(!isset($static[$name])){$static[$name]=array('id'=>0,'kind'=>$asset['kind'],'label'=>($asset['kind']==='photo'?'Foto de referencia · ':'Render y medidas · ').$asset['title'],'keys'=>array(),'url'=>GE_WTP_Catalog_Reviewed_Assets::url($name),'thumbnail'=>GE_WTP_Catalog_Reviewed_Assets::url($asset['thumbnail']['name']??$name));$hashes[]=$asset['sha256'];}
            if(!in_array($key,$static[$name]['keys'],true))$static[$name]['keys'][]=$key;
        }
        foreach(($row['family_assets']??array()) as $name){
            $asset=$catalog['assets'][$name]??array();if(($asset['kind']??'')!=='photo')continue;
            $static[$name]=array('id'=>0,'kind'=>'photo','label'=>'Foto de referencia de personalización · '.$asset['title'],'keys'=>array(),'url'=>GE_WTP_Catalog_Reviewed_Assets::url($name),'thumbnail'=>GE_WTP_Catalog_Reviewed_Assets::url($asset['thumbnail']['name']??$name));
        }
        foreach($map as $key=>$option)foreach(($option['ids']??array()) as $id){
            $id=(int)$id;if(!$id||$id===$mockup)continue;
            if(!isset($items[$id])){
                $kind=get_post_meta($id,'_ge_catalog_reviewed_kind',true)==='render'?'render':'photo';
                if(in_array(get_post_meta($id,'_ge_catalog_reviewed_sha256',true),$hashes,true))continue;
                $items[$id]=array('id'=>$id,'kind'=>$kind,'label'=>$kind==='render'?'Render y medidas':'Foto de referencia','keys'=>array());
            }
            if(!in_array($key,$items[$id]['keys'],true))$items[$id]['keys'][]=$key;
        }
        foreach($static as $name=>$item)$items['asset-'.$name]=$item;
        $out=array();foreach(array('mockup','photo','render') as $kind)foreach($items as $item)if($item['kind']===$kind)$out[]=$item;
        return $out;
    }
    public static function item_url($item,$size='full'){return !empty($item['url'])?$item['url']:wp_get_attachment_image_url($item['id'],$size);}
    public static function item_image($item,$size,$attrs=array()){
        if($item['id'])return wp_get_attachment_image($item['id'],$size,false,$attrs);
        $url=$size==='thumbnail'?$item['thumbnail']:$item['url'];$html='<img src="'.esc_url($url).'"';foreach($attrs as $name=>$value)$html.=' '.esc_attr($name).'="'.esc_attr($value).'"';return $html.'>';
    }
    public static function media(){
        global $product;if(!$product)return;$items=self::items($product);if(!$items)return;
        $title=$product->get_name();$first=$items[0];$url=self::item_url($first);
        $is_flag=$product->get_slug()===self::SLUG;
        ?><section class="gx-flags-media" aria-label="<?php echo esc_attr('Imágenes de '.$title); ?>" data-gx-flags-media><a class="gx-flags-main" data-gx-flags-full href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo self::item_image($first,'large',array('data-gx-flags-image'=>'','loading'=>'eager','fetchpriority'=>'high','alt'=>$title.' — '.$first['label'])); ?></a><?php if($is_flag)self::diagram(); ?><div class="gx-flags-thumbnails" role="group" aria-label="Elegir imagen"><?php $counts=array('photo'=>0,'render'=>0);foreach($items as $i=>$item){$thumb_label=$item['label'];if(isset($counts[$item['kind']])){$counts[$item['kind']]++;$thumb_label=($item['kind']==='photo'?'Foto ':'Medidas ').$counts[$item['kind']];}$full=self::item_url($item);$large=self::item_url($item,'large');if(!$full)continue; ?><button type="button" data-gx-flags-thumb data-kind="<?php echo esc_attr($item['kind']); ?>" data-gx-options="<?php echo esc_attr(implode(' ',$item['keys'])); ?>" data-src="<?php echo esc_url($large); ?>" data-full="<?php echo esc_url($full); ?>" data-label="<?php echo esc_attr($item['label']); ?>" aria-pressed="<?php echo $i===0?'true':'false'; ?>"><?php echo self::item_image($item,'thumbnail',array('alt'=>'','loading'=>'lazy')); ?><span><?php echo esc_html($thumb_label); ?></span></button><?php } if($is_flag){ ?><button type="button" data-gx-flags-thumb data-mode="diagram" data-kind="render" data-label="Esquema de medidas" aria-pressed="false"><svg viewBox="0 0 60 60" aria-hidden="true"><rect x="14" y="10" width="32" height="40" fill="#eeeafa" stroke="#6d45ef"/><path d="M8 10v40M14 55h32" stroke="#667085"/></svg><span>Esquema de medidas</span></button><?php } ?></div><p class="gx-flags-media-caption" aria-live="polite"><span data-gx-flags-caption><?php echo esc_html($first['label']); ?></span><span data-gx-flags-context> · Personalizamos según el modelo y el diseño que elijas.</span></p><noscript><p>Las miniaturas muestran referencias de los distintos modelos. Elegí tu configuración a la derecha.</p></noscript></section><?php
    }
    public static function diagram(){
        ?><div class="gx-flags-diagram" data-gx-flags-diagram hidden><svg viewBox="0 0 600 460" role="img" aria-label="Esquema de medidas de la bandera"><defs><linearGradient id="gxFlagsCloth" x2="1" y2="1"><stop stop-color="#f9f8ff"/><stop offset=".5" stop-color="#ded8f4"/><stop offset="1" stop-color="#f4f1ff"/></linearGradient><marker id="gxFlagsArrow" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse"><path d="M0 0L10 5L0 10Z" fill="#6d45ef"/></marker></defs><text x="300" y="36" text-anchor="middle" data-gx-diagram-size>Medida seleccionada</text><rect data-gx-diagram-cloth x="180" y="90" width="240" height="240" fill="url(#gxFlagsCloth)" stroke="#667085"/><line data-gx-diagram-width stroke="#6d45ef" marker-start="url(#gxFlagsArrow)" marker-end="url(#gxFlagsArrow)"/><line data-gx-diagram-height stroke="#6d45ef" marker-start="url(#gxFlagsArrow)" marker-end="url(#gxFlagsArrow)"/><text data-gx-diagram-width-label text-anchor="middle"/><text data-gx-diagram-height-label text-anchor="middle"/><text x="300" y="435" text-anchor="middle" class="gx-diagram-note">Esquema orientativo · no reemplaza la plantilla de impresión</text></svg></div><?php
    }
    public static function resources($product,$config){
        if(!$product||!self::supported($product->get_slug()))return;
        ?><details class="gx-flags-resources" data-gx-flags-resource><summary>Descargar plantilla y preparar el archivo</summary><div class="gx-flags-resources-body"><?php GE_WTP_Registered_Templates::product_panel(); ?></div></details><?php
    }
}
GE_WTP_Flags_Product_Experience::init();
