<?php
defined('ABSPATH') || exit;

/** Preserve the agreed Deco floor; other catalog promotions remain native. */
final class GE_WTP_Deco_Payments {
    const RETAIL_RULE = 'transfer-net-base-v1';
    public static function regular_cents($base,$policy) {
        if(empty($policy['checkout_pricing']))return $base;
        if(($policy['checkout_pricing']['rule']??'')!==self::RETAIL_RULE||empty($policy['checkout_pricing']['approval_ref'])||!is_int($base)||$base<=0||$base>2000000000)throw new InvalidArgumentException('retail_price_review_required');
        return intdiv($base*10+8,9);
    }
    private static function regular($snapshot) { return ($snapshot['retail_pricing']??'')===self::RETAIL_RULE&&empty($snapshot['artist_id']); }
    public static function init() {
        add_filter('ge_wtp_checkout_payment_base',array(__CLASS__,'cart_base'),10,3);
        add_filter('ge_wtp_order_payment_base',array(__CLASS__,'order_base'),10,3);
        add_filter('woocommerce_coupon_is_valid_for_product',array(__CLASS__,'coupon'),20,4);
        add_filter('woocommerce_coupon_get_items_to_apply',array(__CLASS__,'coupon_items'),20,3);
        add_filter('woocommerce_gateway_title',array(__CLASS__,'title'),40,2);
        add_filter('woocommerce_gateway_description',array(__CLASS__,'description'),40,2);
        add_filter('woocommerce_available_payment_gateways',array(__CLASS__,'gateways'),80);
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
        $deco=0;$regular=0;$all_regular=true;$policy=null;$large=false;
        foreach($cart->get_cart() as $line){$s=$line['ge_deco_snapshot']??array();if(!self::regular($s))$all_regular=false;if(!$s)continue;$deco+=(float)($line['line_total']??0);if(self::regular($s))$regular+=(float)($line['line_total']??0);$policy=$s['freight_policy'];$large=$large||$s['route']==='gran-formato';}
        if(!$policy)return $base;
        if($all_regular)return $method==='bacs'?$regular:(0===strpos($method,'woo-mercado-pago-')?0:$base);
        if($method==='bacs')return max(0,$base-$deco+$regular);
        if(0===strpos($method,'woo-mercado-pago-'))return max(0,$base-$regular)+($policy['frames_cents']+($large?$policy['large_format_cents']:0))/100;
        return $base;
    }
    public static function order_base($base,$order,$method) {
        $deco=0;$regular=0;$all_regular=true;$policy=null;$large=false;
        foreach($order->get_items() as $item){$s=$item->get_meta('_ge_deco_snapshot',true);if(!self::regular($s))$all_regular=false;if(!$s)continue;$deco+=(float)$item->get_total();if(self::regular($s))$regular+=(float)$item->get_total();$policy=$s['freight_policy'];$large=$large||$s['route']==='gran-formato';}
        if(!$policy)return $base;
        if($all_regular)return $method==='bacs'?$regular:(0===strpos($method,'woo-mercado-pago-')?0:$base);
        if(0===strpos($method,'woo-mercado-pago-'))return max(0,$base-$regular);
        if($method!=='bacs')return $base;
        return max(0,$base-$deco+$regular-($policy['frames_cents']+($large?$policy['large_format_cents']:0))/100);
    }
    public static function order_totals($order) {
        $has=false;foreach($order->get_items()as $item){if(self::regular($item->get_meta('_ge_deco_snapshot',true)))$has=true;}if(!$has)return null;
        $base=(float)$order->get_total();foreach($order->get_items('fee')as $fee){if(in_array($fee->get_name(),array('Descuento por transferencia (10%)','Recargo Mercado Pago (10%)'),true))$base-=(float)$fee->get_total();}
        $discount=round(self::order_base($base,$order,'bacs')*.1,2);$surcharge=round(self::order_base($base,$order,'woo-mercado-pago-basic')*.1,2);
        return array('base_total'=>$base,'bank_total'=>$base-$discount,'mp_total'=>$base+$surcharge,'mp_surcharge'=>$surcharge);
    }
    private static function has_deco() {
        if(!function_exists('WC')||!WC()->cart)return false;
        foreach(WC()->cart->get_cart() as $line){if(!empty($line['ge_deco_snapshot']))return true;}return false;
    }
    private static function has_regular_cart() {if(function_exists('WC')&&WC()->cart)foreach(WC()->cart->get_cart()as $line)if(self::regular($line['ge_deco_snapshot']??array()))return true;return false;}
    public static function retail_mp_enabled() {return defined('GE_DECO_RETAIL_MP_ENABLED')&&GE_DECO_RETAIL_MP_ENABLED===true;}
    public static function gateways($gateways) {
        $retail=self::has_regular_cart();
        if(!$retail&&function_exists('get_query_var')&&($id=(int)get_query_var('order-pay'))){$order=wc_get_order($id);$retail=$order&&self::order_totals($order)!==null;}
        if($retail&&!self::retail_mp_enabled())foreach(array_keys($gateways)as $id)if(0===strpos($id,'woo-mercado-pago-'))unset($gateways[$id]);
        return $gateways;
    }
    public static function title($title,$id) { return $id==='bacs'&&self::has_deco()&&!self::has_regular_cart()?'Transferencia bancaria':$title; }
    public static function description($description,$id) {
        return $id==='bacs'&&self::has_deco()&&!self::has_regular_cart()?'Los cuadros conservan el precio indicado. Los descuentos por transferencia se aplican a los demás productos habilitados. La acreditación se verifica antes de producir.':$description;
    }
}
