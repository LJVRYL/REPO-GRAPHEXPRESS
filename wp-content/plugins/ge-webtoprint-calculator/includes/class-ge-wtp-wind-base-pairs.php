<?php
defined('ABSPATH') || exit;

/** Optional native base product, one unit per flag; never a browser-defined price. */
final class GE_WTP_Wind_Base_Pairs {
    private static $syncing = false;

    public static function init() {
        add_action('ge_storefront_optional_accessories', array(__CLASS__, 'render'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'sync'), 5);
        add_action('woocommerce_after_cart_item_quantity_update', array(__CLASS__, 'quantity_updated'), 10, 4);
        add_action('woocommerce_cart_item_removed', array(__CLASS__, 'removed'), 10, 2);
        add_action('woocommerce_cart_item_restored', array(__CLASS__, 'restored'), 10, 2);
        add_filter('woocommerce_cart_item_quantity', array(__CLASS__, 'quantity_html'), 10, 3);
        add_filter('woocommerce_update_cart_validation', array(__CLASS__, 'quantity_valid'), 10, 4);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'item_data'), 20, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'order_data'), 20, 4);
    }

    public static function supported($product_id) {
        $key = (string) get_post_meta($product_id, '_ge_public_catalog_key', true);
        return in_array($key, array('windbanners-banderas-sublimadas','windbanners-fly-cube','windbanners-fly-drop','windbanners-fly-gota','windbanners-fly-oval','windbanners-fly-petalo','windbanners-fly-pluma','windbanners-fly-sail','windbanners-fly-surf'), true);
    }

    public static function catalog($product_id) {
        if (!self::supported($product_id)) { return array(); }
        $ids = get_posts(array('post_type'=>'product','post_status'=>'publish','posts_per_page'=>2,'fields'=>'ids','meta_key'=>'_ge_public_catalog_key','meta_value'=>'windbanners-bases-estacas'));
        if (count($ids)!==1) { return array(); }
        $product = wc_get_product($ids[0]);
        if (!$product || $product->get_sku()!=='WB-BAS-001' || $product->get_status()!=='publish' || !$product->is_in_stock() || !$product->is_purchasable()) { return array(); }
        $config = GE_WTP_Storefront::config($product->get_id());
        if (empty($config['windbanners']) || empty($config['options'])) { return array(); }
        return array('product'=>$product, 'config'=>$config);
    }

    public static function height($option) {
        return preg_match('/^(\d+)\s*·/u', $option['label'] ?? '', $m) ? (int)$m[1] : 0;
    }

    public static function render($product, $config) {
        $catalog = self::catalog($product->get_id());
        if (!$catalog) { return; }
        ?><fieldset class="gx-flags-resources" style="padding:16px"><legend>Base opcional</legend><label class="ge-storefront-field"><span>Agregar una base por windbanner</span><select name="ge_wind_base" data-ge-wind-base><option value="">Sin base</option><?php foreach($catalog['config']['options'] as $key=>$option){$label=$key==='wb-201'?'Base cruz con estuche de sobrepeso (sin relleno)':$option['label']; ?><option value="<?php echo esc_attr($key); ?>" data-price="<?php echo esc_attr($option['price']); ?>" data-max-height="<?php echo $key==='wb-203'?'320':'0'; ?>"><?php echo esc_html($label.' · + $'.number_format($option['price'],2,',','.').' + IVA por unidad'); ?></option><?php } ?></select></label><p data-ge-wind-base-summary aria-live="polite">Podés comprar sin base. Si agregás una, se suma una por cada windbanner.</p><small><?php echo get_post_meta($product->get_id(),'_ge_public_catalog_key',true)==='windbanners-banderas-sublimadas'?'Las banderas textiles no incluyen mástil. Elegí la base para tu propia estructura compatible.':'Elegí la base según la superficie de instalación. Estaca de 40 cm: hasta 3,20 m; para mayor altura, estaca de 60 cm.'; ?></small></fieldset><?php
    }

    public static function resolve($parent_id, $parent_key, $base_key, $quantity) {
        $catalog = self::catalog($parent_id);
        $parent = GE_WTP_Storefront::config($parent_id);
        if (!$catalog || !isset($parent['options'][$parent_key], $catalog['config']['options'][$base_key])) { throw new InvalidArgumentException('La base elegida ya no está disponible. Volvé a seleccionar la base.'); }
        $base = $catalog['config']['options'][$base_key];
        $flag=$parent['options'][$parent_key];$flag_min=max(1,(int)($flag['min_qty']??1));$flag_step=max(1,(int)($flag['step']??1));
        if($quantity<$flag_min || ($quantity-$flag_min)%$flag_step!==0){throw new InvalidArgumentException('Revisá la cantidad mínima y los múltiplos del windbanner.');}
        if ($base_key==='wb-203' && self::height($parent['options'][$parent_key])>320) { throw new InvalidArgumentException('Para esta altura elegí otra base o la estaca de 60 cm.'); }
        $minimum=max(1,(int)($base['min_qty']??1));$step=max(1,(int)($base['step']??1));
        if ($quantity<$minimum || ($quantity-$minimum)%$step!==0 || (!$catalog['product']->backorders_allowed() && !$catalog['product']->has_enough_stock($quantity))) { throw new InvalidArgumentException('No hay bases disponibles para esa cantidad de windbanners. Podés comprar sin base.'); }
        return array($catalog,$base);
    }

    public static function add_pair($parent_id, $parent_key, $base_key, $quantity, $parent_data, $cart) {
        list($catalog,$base)=self::resolve($parent_id,$parent_key,$base_key,$quantity);
        $group=wp_generate_uuid4();$parent_data['ge_wind_base_group']=$group;$parent_cart_key='';
        try {
            $parent_cart_key=$cart->add_to_cart($parent_id,$quantity,0,array(),$parent_data);
            if (!$parent_cart_key) { throw new RuntimeException('No pudimos agregar el windbanner.'); }
            $base_data=array('ge_configuration_key'=>$base_key,'ge_configuration'=>$base['label'],'ge_base_price'=>(float)$base['price'],'ge_calculated_price'=>round($base['price']*$catalog['config']['tax_context']['multiplier'],4),'ge_unique'=>wp_generate_uuid4(),'ge_wind_source_id'=>$base['source_id'],'ge_wind_variant_id'=>$base['variant_id'],'ge_wind_reconciliation_date'=>GE_WTP_Windbanners_Catalog::SOURCE_DATE,'ge_wind_tax_context'=>$catalog['config']['tax_context'],'ge_wind_base_group'=>$group,'ge_wind_base_parent'=>$parent_cart_key,'ge_wind_base_for'=>get_the_title($parent_id).' · '.$parent_data['ge_configuration']);
            $child=$cart->add_to_cart($catalog['product']->get_id(),$quantity,0,array(),$base_data);
            if (!$child) { throw new RuntimeException('No pudimos agregar la base. No se agregó este conjunto; podés reintentar o comprar sin base.'); }
            return $parent_cart_key;
        } catch (Throwable $error) {
            if ($parent_cart_key) { $cart->remove_cart_item($parent_cart_key); }
            throw $error;
        }
    }

    public static function sync($cart) {
        if (self::$syncing) { return; }
        self::$syncing=true;
        try {
            $items=$cart->get_cart();
            foreach($items as $key=>$item){
                if(empty($item['ge_wind_base_parent']))continue;
                $parent=$items[$item['ge_wind_base_parent']]??null;
                if(!$parent || empty($parent['ge_wind_base_group']) || $parent['ge_wind_base_group']!==($item['ge_wind_base_group']??'')){$cart->remove_cart_item($key);continue;}
                if((int)$item['quantity']!==(int)$parent['quantity'])$cart->set_quantity($key,(int)$parent['quantity'],false);
            }
        } finally { self::$syncing=false; }
    }

    public static function quantity_updated($key,$quantity,$old_quantity,$cart) { self::sync($cart); }

    public static function removed($key,$cart) {
        $children=array();foreach($cart->get_cart() as $child_key=>$item){if(($item['ge_wind_base_parent']??'')===$key){$children[]=$child_key;$cart->remove_cart_item($child_key);}}
        if($children && isset($cart->removed_cart_contents[$key]))$cart->removed_cart_contents[$key]['ge_wind_base_removed_children']=$children;
    }

    public static function restored($key,$cart) {
        $items=$cart->get_cart();$parent=$items[$key]??array();
        foreach(($parent['ge_wind_base_removed_children']??array()) as $child){$removed=$cart->removed_cart_contents[$child]??array();if(($removed['ge_wind_base_parent']??'')===$key && ($removed['ge_wind_base_group']??'')===($parent['ge_wind_base_group']??null))$cart->restore_cart_item($child);}
        self::sync($cart);
    }

    public static function quantity_html($html,$key,$item) { return empty($item['ge_wind_base_parent'])?$html:esc_html((int)$item['quantity'].' · una por windbanner'); }

    public static function quantity_valid($valid,$key,$item,$quantity) {
        if(!empty($item['ge_wind_base_parent']) && (int)$quantity!==(int)$item['quantity']){wc_add_notice('La cantidad de bases se ajusta desde la cantidad del windbanner. Podés quitar la base si no la necesitás.','error');return false;}
        return $valid;
    }

    public static function item_data($data,$item) { if(!empty($item['ge_wind_base_for']))$data[]=array('key'=>'Base para','value'=>wc_clean($item['ge_wind_base_for']));return $data; }
    public static function order_data($item,$key,$values,$order) {
        if(!empty($values['ge_wind_base_group']))$item->update_meta_data('_ge_wind_base_group',$values['ge_wind_base_group']);
        if(!empty($values['ge_wind_base_for']))$item->add_meta_data('Base para',wc_clean($values['ge_wind_base_for']),true);
    }
}
GE_WTP_Wind_Base_Pairs::init();
