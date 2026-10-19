<?php
defined('ABSPATH') || exit;

/** Preserve the agreed Deco floor; other catalog promotions remain native. */
final class GE_WTP_Deco_Payments {
    public static function init() {
        add_filter('ge_wtp_checkout_payment_base',array(__CLASS__,'cart_base'),10,3);
        add_filter('ge_wtp_order_payment_base',array(__CLASS__,'order_base'),10,3);
        add_filter('woocommerce_coupon_is_valid_for_product',array(__CLASS__,'coupon'),20,4);
        add_filter('woocommerce_coupon_get_items_to_apply',array(__CLASS__,'coupon_items'),20,3);
        add_filter('woocommerce_gateway_title',array(__CLASS__,'title'),40,2);
        add_filter('woocommerce_gateway_description',array(__CLASS__,'description'),40,2);
    }
    public static function coupon($valid,$product,$coupon,$values) { return !empty($values['ge_deco_snapshot'])||GE_WTP_Deco_Prints::manifest($product->get_id())?false:$valid; }
    public static function coupon_items($items,$coupon,$discounts) {
        return array_values(array_filter($items,static function($item){
            if(is_array($item->object)&&!empty($item->object['ge_deco_snapshot']))return false;
            if(is_object($item->object)&&method_exists($item->object,'get_meta')&&$item->object->get_meta('_ge_deco_snapshot',true))return false;
            return !GE_WTP_Deco_Prints::manifest($item->product->get_id());
        }));
    }
    public static function cart_base($base,$cart,$method) {
        $deco=0;$policy=null;$large=false;
        foreach($cart->get_cart() as $line){$s=$line['ge_deco_snapshot']??array();if(!$s)continue;$deco+=(float)($line['line_total']??0);$policy=$s['freight_policy'];$large=$large||$s['route']==='gran-formato';}
        if(!$policy)return $base;
        if($method==='bacs')return max(0,$base-$deco);
        if(0===strpos($method,'woo-mercado-pago-'))return $base+($policy['frames_cents']+($large?$policy['large_format_cents']:0))/100;
        return $base;
    }
    public static function order_base($base,$order,$method) {
        if($method!=='bacs')return $base;$deco=0;$policy=null;$large=false;
        foreach($order->get_items() as $item){$s=$item->get_meta('_ge_deco_snapshot',true);if(!$s)continue;$deco+=(float)$item->get_total();$policy=$s['freight_policy'];$large=$large||$s['route']==='gran-formato';}
        if(!$policy)return $base;
        return max(0,$base-$deco-($policy['frames_cents']+($large?$policy['large_format_cents']:0))/100);
    }
    private static function has_deco() {
        if(!function_exists('WC')||!WC()->cart)return false;
        foreach(WC()->cart->get_cart() as $line){if(!empty($line['ge_deco_snapshot']))return true;}return false;
    }
    public static function title($title,$id) { return $id==='bacs'&&self::has_deco()?'Transferencia bancaria':$title; }
    public static function description($description,$id) {
        return $id==='bacs'&&self::has_deco()?'Los cuadros conservan el precio indicado. Los descuentos por transferencia se aplican a los demás productos habilitados. La acreditación se verifica antes de producir.':$description;
    }
}
