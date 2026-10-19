<?php
require_once __DIR__.'/../../../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-deco-mp-preference.php';
$checks=0;
function preference_check($ok){global $checks;if(!$ok)throw new RuntimeException('preference assertion failed');$checks++;}
function preference_denied($fn){try{$fn();}catch(InvalidArgumentException $e){preference_check(true);return;}throw new RuntimeException('expected rejection');}
$sale=array('reference'=>str_repeat('a',64),'return_url'=>'https://graphex.ar/order-received/','notification_url'=>'https://graphex.ar/wp-json/graphex-deco/v1/marketplace-webhook','settlement'=>array('schema'=>'graphex-marketplace-settlement/v1','currency'=>'ARS','total_cents'=>2000000,'graphex_cents'=>1500000,'valid_until'=>1792391000,'payment_method_id'=>'account_money','split_required'=>true));
$methods=array(array('id'=>'account_money'),array('id'=>'visa'),array('id'=>'master'));$now=1792390000;
$body=GE_Deco_MP_Preference::build($sale,$methods,$now);
preference_check($body['marketplace_fee']===15000);preference_check($body['items'][0]['unit_price']===20000);
preference_check($body['payment_methods']['excluded_payment_methods']===array(array('id'=>'visa'),array('id'=>'master')));
preference_check(!isset($body['collector_id'])&&!isset($body['payer'])&&!isset($body['application_fee']));
preference_check($body['external_reference']===$sale['reference']);
foreach(array('http://localhost:8780/','https://user:password@graphex.ar/','https://graphex.ar:8443/','javascript:alert(1)')as $url){$bad=$sale;$bad['notification_url']=$url;preference_denied(static function()use($bad,$methods,$now){GE_Deco_MP_Preference::build($bad,$methods,$now);});}
$bad=$sale;$bad['notification_url']='https://example.com/hook';preference_denied(static function()use($bad,$methods,$now){GE_Deco_MP_Preference::build($bad,$methods,$now);});
preference_denied(static function()use($sale,$now){GE_Deco_MP_Preference::build($sale,array(array('id'=>'visa')),$now);});
preference_denied(static function()use($sale,$methods){GE_Deco_MP_Preference::build($sale,$methods,1792391001);});
$valid=array('id'=>'synthetic-preference','init_point'=>'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=synthetic');preference_check(GE_Deco_MP_Preference::redirect($valid)['init_point']===$valid['init_point']);
foreach(array('http://www.mercadopago.com.ar/x','https://mercadopago.com.ar.evil.example/x','https://evil.example/x','https://user@mercadopago.com.ar/x')as $url){preference_denied(static function()use($url){GE_Deco_MP_Preference::redirect(array('id'=>'synthetic','init_point'=>$url));});}
echo json_encode(array('passed'=>$checks,'real_preferences'=>0)).PHP_EOL;
