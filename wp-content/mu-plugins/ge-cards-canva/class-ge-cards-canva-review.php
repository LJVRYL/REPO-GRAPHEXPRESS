<?php
/** A dedicated review account can test Canva and cart, without creating purchases. */
defined('ABSPATH') || exit;
final class GE_Cards_Canva_Review {
    const META='_ge_canva_review_account_v1';
    public static function init() {
        add_filter('authenticate',array(__CLASS__,'authenticate'),99,3);
        add_action('template_redirect',array(__CLASS__,'front_guard'),-100);
        add_action('admin_init',array(__CLASS__,'write_guard'),-100);
        add_filter('rest_pre_dispatch',array(__CLASS__,'rest_guard'),5,3);
        add_action('woocommerce_after_checkout_validation',array(__CLASS__,'checkout_guard'),5,2);
    }
    public static function is_review($id=null) { $record=get_user_meta($id===null ? get_current_user_id() : $id,self::META,true); return is_array($record) ? $record : array(); }
    public static function authenticate($user,$username='',$password='') {
        if ($user instanceof WP_User) { $r=self::is_review($user->ID); if ($r && (empty($r['expires_at']) || $r['expires_at']<time())) { return new WP_Error('ge_canva_review_expired','La cuenta temporal de revisión venció. Contactá a Graphex.'); } }
        return $user;
    }
    private static function deny() { wp_die('Esta cuenta de demostración permite probar Canva y el carrito, pero no crear pedidos ni modificar datos del negocio.','Cuenta de prueba',array('response'=>403)); }
    public static function front_guard() {
        $r=self::is_review();if(!$r)return;
        $path='/' . trim((string)wp_parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH),'/') . '/';
        $checkout=function_exists('wc_get_checkout_url') ? wp_parse_url(wc_get_checkout_url(),PHP_URL_PATH) : '/checkout/';
        $checkout='/' . trim((string)$checkout,'/') . '/';
        if (empty($r['expires_at']) || $r['expires_at']<time() || strpos($path,$checkout)===0)self::deny();
        if (!in_array(strtoupper($_SERVER['REQUEST_METHOD']??'GET'),array('GET','HEAD'),true) && (!isset($_POST['action']) || !in_array($_POST['action'],array('ge_customer_cards_design_authorize','ge_add_digital_product'),true)))self::deny();
    }
    public static function write_guard() {
        $r=self::is_review();if(!$r || in_array(strtoupper($_SERVER['REQUEST_METHOD']??'GET'),array('GET','HEAD'),true))return;
        $action=is_string($_REQUEST['action']??null) ? $_REQUEST['action'] : '';
        $allowed=array('ge_customer_cards_design_authorize','ge_add_digital_product','ge_vps_prepare_upload','ge_vps_receive_upload','ge_customer_cards_design_status','ge_customer_cards_design_designs','ge_customer_cards_design_edit','ge_customer_cards_design_export','ge_customer_cards_design_export_status','ge_customer_cards_design_forget','ge_customer_cards_design_pdf','ge_customer_cards_design_preflight');
        if (empty($r['expires_at']) || $r['expires_at']<time() || !in_array($action,$allowed,true))self::deny();
    }
    public static function rest_guard($result,$server,$request) {
        if (self::is_review() && !in_array($request->get_method(),array('GET','HEAD'),true))return new WP_Error('ge_canva_review_readonly','La cuenta de prueba no permite operaciones comerciales.',array('status'=>403));
        return $result;
    }
    public static function checkout_guard($data,$errors) { if (self::is_review())$errors->add('ge_canva_review_no_orders','Esta cuenta de prueba no permite crear pedidos.'); }
}