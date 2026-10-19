<?php
/** Build Checkout Pro requests exclusively from the server's frozen sale. */
final class GE_Deco_MP_Preference {
    public static function build(array $sale, array $methods, $now) {
        $split=$sale['settlement']??array();
        if(($split['schema']??'')!=='graphex-marketplace-settlement/v1'||($split['currency']??'')!=='ARS'
            ||!is_int($split['total_cents']??null)||$split['total_cents']<=0
            ||!is_int($split['graphex_cents']??null)||$split['graphex_cents']<=0||$split['graphex_cents']>=$split['total_cents']
            ||!is_int($split['valid_until']??null)||$split['valid_until']<=$now
            ||($split['payment_method_id']??'')!=='account_money'||($split['split_required']??false)!==true
            ||!preg_match('/^[a-f0-9]{64}$/D',$sale['reference']??''))throw new InvalidArgumentException('invalid_marketplace_sale');
        foreach(array('return_url','notification_url')as $key){$url=$sale[$key]??'';$parts=is_string($url)?parse_url($url):false;
            if(!$parts||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||isset($parts['port']))throw new InvalidArgumentException('public_https_required');
        }
        if(parse_url($sale['return_url'],PHP_URL_HOST)!==parse_url($sale['notification_url'],PHP_URL_HOST))throw new InvalidArgumentException('callback_origin_mismatch');
        $excluded=array();$account=false;
        foreach($methods as $method){$id=$method['id']??'';if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$id))throw new InvalidArgumentException('invalid_payment_methods');
            if($id==='account_money'){$account=true;continue;}$excluded[$id]=array('id'=>$id);
        }
        if(!$account||count($methods)>500)throw new InvalidArgumentException('account_money_unavailable');
        return array('items'=>array(array('id'=>'graphex-print','title'=>'Cuadro Graphex · obra y enmarcado','currency_id'=>'ARS','quantity'=>1,'unit_price'=>$split['total_cents']/100)),
            'marketplace_fee'=>$split['graphex_cents']/100,'external_reference'=>$sale['reference'],
            'back_urls'=>array('success'=>$sale['return_url'],'pending'=>$sale['return_url'],'failure'=>$sale['return_url']),
            'notification_url'=>$sale['notification_url'],'auto_return'=>'approved',
            'payment_methods'=>array('default_payment_method_id'=>'account_money','installments'=>1,'excluded_payment_methods'=>array_values($excluded)),
            'expires'=>true,'expiration_date_from'=>gmdate('Y-m-d\TH:i:s\Z',$now),'expiration_date_to'=>gmdate('Y-m-d\TH:i:s\Z',min($now+600,$split['valid_until'])));
    }
    public static function redirect($response) {
        $url=$response['init_point']??null;$parts=is_string($url)?parse_url($url):false;
        if(!is_string($response['id']??null)||$response['id']===''||!$parts||($parts['scheme']??'')!=='https'
            ||!in_array($parts['host']??'',array('www.mercadopago.com.ar','mercadopago.com.ar'),true)
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['fragment']))throw new InvalidArgumentException('invalid_checkout_redirect');
        return array('preference_id'=>$response['id'],'init_point'=>$url);
    }
}
