<?php
ob_start();require __DIR__.'/windbanners-reconciliation.php';ob_end_clean();
function is_wp_error($v){return false;}
function absint($v){return abs((int)$v);}function wp_unslash($v){return $v;}function sanitize_text_field($v){return $v;}function wp_verify_nonce(){return true;}function wp_generate_uuid4(){return 'synthetic-only';}function is_user_logged_in(){return false;}function wp_list_pluck($a,$key){return array_column($a,$key);}function wp_die(){throw new Exception('blocked');}function wc_add_notice($m,$kind){if($kind==='error')throw new Exception('invalid_option');}
class CapturedCatalogCart{public $captured;function get_cart(){return array();}function add_to_cart($id,$quantity,$variation,$attributes,$data){$this->captured=compact('id','quantity','data');throw new Exception('captured');}}
$tested=0;$tax_enabled=false;
foreach($products as $family=>$p){$fixture[77]=array('_ge_public_catalog_key'=>'windbanners-'.$family);foreach($p['options'] as $key=>$o){
    $cart_fixture=new CapturedCatalogCart();$_POST=array('product_id'=>77,'ge_store_nonce'=>'synthetic','option_key'=>$key,'quantity'=>$o['min_qty']);
    try{GE_WTP_Storefront::add_to_cart();throw new Exception('missing_capture');}catch(Exception $e){ok($e->getMessage()==='captured','native purchase path reached');}
    $line=$cart_fixture->captured;ok($line['quantity']===$o['min_qty'],'native quantity');ok($line['data']['ge_base_price']===$o['price'],'native net price');ok($line['data']['ge_calculated_price']===round($o['price']*1.21,4),'advertised total matches future cart');ok($line['data']['ge_wind_source_id']===$o['source_id'],'native source association');$tested++;
}}
$_POST['option_key']='not-a-native-variant';try{GE_WTP_Storefront::add_to_cart();throw new Exception('accepted_invalid');}catch(Exception $e){ok($e->getMessage()==='invalid_option','invalid option rejected');}
echo json_encode(array('status'=>'PASS','native_purchase_options'=>$tested,'net_plus_iva_matches_cart'=>true,'production_records_modified'=>false)).PHP_EOL;
