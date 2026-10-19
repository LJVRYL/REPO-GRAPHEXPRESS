<?php
defined('ABSPATH') || exit;
require_once __DIR__.'/class-ge-deco-mp-preference.php';

/** Native order payment service. Never accepts prices, seller IDs or tokens from a buyer. */
final class GE_WTP_Deco_Marketplace_Checkout {
    const SALE='_ge_deco_marketplace_sale_v1';
    const PAYMENT='_ge_deco_marketplace_payment_v1';
    public static function init() {
        add_filter('woocommerce_payment_gateways',array(__CLASS__,'gateways'));
        add_filter('woocommerce_available_payment_gateways',array(__CLASS__,'available_gateways'),100);
        add_action('woocommerce_blocks_payment_method_type_registration',array(__CLASS__,'blocks'));
    }
    public static function gateways($gateways) {
        require_once __DIR__.'/class-ge-deco-marketplace-gateway.php';
        if(class_exists('GE_Deco_Marketplace_Gateway'))$gateways[]='GE_Deco_Marketplace_Gateway';return $gateways;
    }
    public static function blocks($registry) {
        if(!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType'))return;
        require_once __DIR__.'/class-ge-deco-marketplace-blocks.php';$registry->register(new GE_Deco_Marketplace_Blocks());
    }
    public static function artist_checkout() {
        if(function_exists('WC')&&WC()->cart)foreach(WC()->cart->get_cart()as $line)if(!empty($line['ge_deco_snapshot']['artist_id'])&&($line['ge_deco_snapshot']['artist_quote']['artist_cents']??0)>0)return true;
        if(function_exists('get_query_var')&&($id=absint(get_query_var('order-pay')))){$order=wc_get_order($id);return $order&&(bool)$order->get_meta(self::SALE,true);}
        return false;
    }
    public static function available_gateways($gateways) {
        if(self::artist_checkout())return isset($gateways['ge_deco_marketplace'])?array('ge_deco_marketplace'=>$gateways['ge_deco_marketplace']):array();
        unset($gateways['ge_deco_marketplace']);return $gateways;
    }
    public static function enabled() {
        return defined('GE_DECO_MP_SALES_ENABLED')&&GE_DECO_MP_SALES_ENABLED===true
            && GE_WTP_Deco_Marketplace::enabled()
            && defined('GE_DECO_MP_WEBHOOK_SECRET')&&strlen((string)GE_DECO_MP_WEBHOOK_SECRET)>=16
            && parse_url(home_url(),PHP_URL_SCHEME)==='https';
    }
    public static function lock($id) {
        $handle=fopen(GE_WTP_Deco_Gallery::root().'/.mp-order-'.absint($id).'.lock','c');
        if(!$handle||!flock($handle,LOCK_EX|LOCK_NB))throw new InvalidArgumentException('payment_busy');return $handle;
    }
    public static function unlock($handle) { if($handle){flock($handle,LOCK_UN);fclose($handle);} }
    public static function request($method,$path,$token,$body=null) {
        $allowed=($method==='POST'&&$path==='/checkout/preferences')
            ||($method==='GET'&&($path==='/v1/payment_methods'||preg_match('~^/(v1/payments|merchant_orders)/[1-9][0-9]{0,29}$~D',$path)));
        if(!$allowed||!is_string($token)||$token==='')throw new InvalidArgumentException('invalid_mp_endpoint');
        $args=array('method'=>$method,'redirection'=>0,'timeout'=>20,'limit_response_size'=>262144,
            'headers'=>array('Accept'=>'application/json','Authorization'=>'Bearer '.$token));
        if($body!==null){$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($body);}
        $response=wp_remote_request('https://api.mercadopago.com'.$path,$args);
        if(is_wp_error($response)||!in_array(wp_remote_retrieve_response_code($response),array(200,201),true))throw new InvalidArgumentException('payment_provider_unavailable');
        $data=json_decode(wp_remote_retrieve_body($response),true);if(!is_array($data))throw new InvalidArgumentException('invalid_mp_response');return $data;
    }
    public static function cents($value) {
        if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>20000000)throw new InvalidArgumentException('invalid_payment_amount');
        $cents=(int)round((float)$value*100);
        if(abs($cents/100-(float)$value)>.000001)throw new InvalidArgumentException('invalid_payment_amount');return $cents;
    }
    public static function sale($order) {
        if(!$order instanceof WC_Order||$order->get_currency()!=='ARS')throw new InvalidArgumentException('invalid_marketplace_order');
        $sale=$order->get_meta(self::SALE,true);$split=$sale['settlement']??array();$artist=$sale['artist_id']??0;
        if(!is_array($sale)||($sale['order_id']??null)!==$order->get_id()||!is_int($artist)||$artist<1
            ||($sale['buyer_id']??null)!==(int)$order->get_customer_id()||!preg_match('/^[a-f0-9]{64}$/D',$sale['reference']??'')
            ||($sale['application_id']??'')!==GE_WTP_Deco_Marketplace::config()['client_id']
            ||($sale['mode']??'')!==GE_WTP_Deco_Marketplace::config()['mode']
            ||($split['total_cents']??null)!==self::cents($order->get_total()))throw new InvalidArgumentException('marketplace_order_changed');
        // Onboarding and pricing changes must not redirect an existing sale to another account.
        $account=GE_WTP_Deco_Marketplace::status($artist);
        if(!$account['connected']||$account['account_id']!==($split['collector_id']??''))throw new InvalidArgumentException('seller_connection_changed');
        return $sale;
    }
    public static function create($order) {
        if(!self::enabled())throw new InvalidArgumentException('artist_sales_pending');
        if(!$order instanceof WC_Order||!is_user_logged_in()||GE_WTP_Portal::is_staff_preview()
            ||(int)$order->get_customer_id()!==get_current_user_id())throw new InvalidArgumentException('authentication_required');
        $lock=self::lock($order->get_id());
        try{
            $order=wc_get_order($order->get_id());if(!$order->needs_payment())throw new InvalidArgumentException('order_not_payable');
            $sale=self::sale($order);if(($sale['settlement']['valid_until']??0)<=time())throw new InvalidArgumentException('payment_quote_expired');
            $previous=$order->get_meta(self::PAYMENT,true);
            if($previous){if(($previous['reference']??'')!==$sale['reference'])throw new InvalidArgumentException('payment_review_required');
                if(!empty($previous['preference']))return GE_Deco_MP_Preference::redirect($previous['preference']);
                throw new InvalidArgumentException('payment_reconciliation_required');}
            $token=GE_WTP_Deco_Marketplace::access_token($sale['artist_id']);
            $methods=self::request('GET','/v1/payment_methods',$token);
            $input=$sale;$input['return_url']=$order->get_checkout_order_received_url();
            $input['notification_url']=add_query_arg('purchase',$sale['reference'],rest_url(GE_WTP_Deco_Gallery::NS.'/marketplace-webhook'));
            $body=GE_Deco_MP_Preference::build($input,$methods,time());
            // Keep an uncertain creation blocked instead of submitting it twice after a timeout.
            $order->update_meta_data(self::PAYMENT,array('reference'=>$sale['reference'],'state'=>'creating'));$order->save();
            $mapping='ge_deco_mp_purchase_'.hash('sha256',$sale['reference']);$mapped=get_option($mapping);
            if($mapped&&(int)$mapped!==$order->get_id())throw new InvalidArgumentException('payment_reference_conflict');
            if(!$mapped&&!add_option($mapping,$order->get_id(),'','no'))throw new InvalidArgumentException('payment_reference_conflict');
            $response=self::request('POST','/checkout/preferences',$token,$body);$redirect=GE_Deco_MP_Preference::redirect($response);
            $order->update_meta_data(self::PAYMENT,array('reference'=>$sale['reference'],'state'=>'pending','preference'=>array('id'=>$redirect['preference_id'],'init_point'=>$redirect['init_point'])));$order->save();return $redirect;
        }finally{self::unlock($lock);}
    }
    public static function reconcile($order_id,$payment_id) {
        if(!self::enabled()||!preg_match('/^[1-9][0-9]{0,29}$/D',(string)$payment_id))throw new InvalidArgumentException('payment_unavailable');
        $lock=self::lock($order_id);
        try{
            $order=wc_get_order($order_id);$sale=self::sale($order);$pending=$order->get_meta(self::PAYMENT,true);
            $token=GE_WTP_Deco_Marketplace::access_token($sale['artist_id']);$payment=self::request('GET','/v1/payments/'.$payment_id,$token);
            if((string)($payment['id']??'')!==(string)$payment_id||($payment['external_reference']??'')!==$sale['reference']
                ||($payment['live_mode']??null)!==($sale['mode']==='live')||($payment['currency_id']??'')!=='ARS'
                ||(string)($payment['collector_id']??'')!==$sale['settlement']['collector_id'])throw new InvalidArgumentException('payment_identity_mismatch');
            $merchant_id=(string)($payment['order']['id']??'');if(!preg_match('/^[1-9][0-9]{0,29}$/D',$merchant_id))throw new InvalidArgumentException('payment_identity_mismatch');
            $merchant=self::request('GET','/merchant_orders/'.$merchant_id,$token);
            if(($merchant['external_reference']??'')!==$sale['reference']||($merchant['preference_id']??'')!==($pending['preference']['id']??null))throw new InvalidArgumentException('payment_identity_mismatch');
            $existing=(string)$order->get_transaction_id();
            if($existing!==''&&$existing!==(string)$payment_id)throw new InvalidArgumentException('duplicate_payment_review_required');
            $refunded=self::cents($payment['transaction_amount_refunded']??0);
            if($refunded>0||in_array($payment['status']??'',array('refunded','charged_back'),true)){
                // Refunds can change fee_details; stop production before examining revised fees.
                if(self::cents($payment['transaction_amount']??null)!==$sale['settlement']['total_cents']||$refunded>$sale['settlement']['total_cents'])throw new InvalidArgumentException('payment_identity_mismatch');
                $order->update_meta_data('_ge_deco_marketplace_settlement_verified','no');
                $pending['state']='reversed';$pending['payment_id']=(string)$payment_id;$pending['reconciled_at']=time();
                $order->update_meta_data(self::PAYMENT,$pending);$order->save();
                if(!in_array($order->get_status(),array('on-hold','refunded'),true))$order->update_status('on-hold','Mercado Pago informó una reversión. Revisar el reparto y detener producción.');
                return 'reversed';
            }
            $processing=0;$platform=0;
            if(!is_array($payment['fee_details']??null))throw new InvalidArgumentException('payment_fee_review_required');
            foreach($payment['fee_details'] as $fee){$amount=self::cents($fee['amount']??null);
                if(($fee['type']??'')==='application_fee')$platform+=$amount;
                elseif(in_array($fee['type']??'',array('mercadopago_fee','financing_fee'),true))$processing+=$amount;
                elseif($amount>0)throw new InvalidArgumentException('payment_fee_review_required');
            }
            $projection=array('total_cents'=>self::cents($payment['transaction_amount']??null),'processing_cents'=>$processing,'marketplace_cents'=>$platform,
                'refunded_cents'=>self::cents($payment['transaction_amount_refunded']??0),'currency'=>$payment['currency_id'],
                'collector_id'=>(string)$payment['collector_id'],'payment_method_id'=>$payment['payment_method_id']??'',
                'status'=>$payment['status']??'','accredited_at'=>$payment['date_approved']??null);
            $state=GE_Deco_Marketplace_Settlement::reconcile($sale['settlement'],$projection);
            if($state==='paid'&&!$order->is_paid()){$order->payment_complete((string)$payment_id);}
            elseif($state==='reversed'&&!in_array($order->get_status(),array('on-hold','refunded'),true)){$order->update_status('on-hold','Mercado Pago informó una reversión. Revisar el reparto y detener producción.');}
            $pending['state']=$state;$pending['payment_id']=(string)$payment_id;$pending['reconciled_at']=time();
            $order->update_meta_data(self::PAYMENT,$pending);$order->update_meta_data('_ge_deco_marketplace_settlement_verified',$state==='paid'?'yes':'no');$order->delete_meta_data('_ge_deco_marketplace_review');$order->save();return $state;
        }finally{self::unlock($lock);}
    }
}
