<?php
/** Isolated hook/contract tests. No WordPress bootstrap, database, emails or real orders. */
define('ABSPATH', __DIR__ . '/fictional-public-root/');
@mkdir(ABSPATH);
define('GE_DECO_PRINTS_ENABLED', !in_array('--disabled', $argv, true));
define('GE_DECO_PRIVATE_ROOT', sys_get_temp_dir() . '/ge-deco-fixture-' . uniqid());
mkdir(GE_DECO_PRIVATE_ROOT); mkdir(GE_DECO_PRIVATE_ROOT . '/nga-1');
$folder = GE_DECO_PRIVATE_ROOT . '/nga-1/';
file_put_contents($folder . 'print-master.jpg', 'isolated byte-integrity fixture, not production art');
$sha=hash_file('sha256',$folder.'print-master.jpg');
file_put_contents($folder.'analysis.json',json_encode(array('checksum_sha256'=>$sha,'status'=>'analyzed','blockers'=>array(),'page_count'=>1,'pixel_dimensions'=>array(4000,5000))));
file_put_contents($folder.'catalog-record.json',json_encode(array('work'=>array('id'=>'nga-1','pixels'=>array(4000,5000)),'source_sha256'=>$sha,'image_id'=>'fixture','rights_evidence'=>array('openAccess'=>array('openAccess'=>true,'imageUuid'=>'fixture')),'source'=>array('id'=>'nga'))));
$hooks=array();$notices=array();$metadata=array();$configs=array();$checks=0;
function add_action($name,$callback,$priority=10,$args=1){global $hooks;$hooks[$name]=array($callback,$priority,$args);}
function add_filter($name,$callback,$priority=10,$args=1){add_action($name,$callback,$priority,$args);}
function get_post_meta($id,$key,$single){global $metadata;return $metadata[$id][$key]??'';}
function wc_add_notice($message,$type){global $notices;$notices[]=$message;}
function get_woocommerce_currency(){return 'ARS';}
class GE_WTP_Storefront {public static function config($id){global $configs;return $configs[$id]??array();}}
require dirname(__DIR__,3).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-deco-prints.php';
function check($value){global $checks;if(!$value)throw new RuntimeException('check '.($checks+1));$checks++;}
function reject($action,$reason){global $checks;try{$action();}catch(InvalidArgumentException $e){if($e->getMessage()===$reason){$checks++;return;}throw $e;}throw new RuntimeException('Expected '.$reason);}
if (!GE_DECO_PRINTS_ENABLED) { reject(function(){GE_WTP_Deco_Prints::resolve(1,'anything','anything');},'catalog_not_released'); echo 'Disabled catalog rejects sales'.PHP_EOL; exit; }
$entry=array('enabled'=>true,'geometry_verified'=>true,'cost_version_ref'=>'test-only','price_approval_ref'=>'test-only','supplier_variant_ref'=>'fixture','print_mm'=>array(200,300),'outer_mm'=>array(220,320),'fit'=>'cover','paper_finish'=>'mate','crop_anchor'=>array(.5,.5),'frame_observed_at'=>time(),'print_observed_at'=>time(),'available'=>true,'valid_until'=>time()+3600,'approved_base_price'=>20000);
$manifest=array('revision'=>'fixture-v1','rights_status'=>'approved','rights_evidence_ref'=>'test-only','artwork_id'=>'nga-1','production_sha256'=>$sha,'production_pixels'=>array(4000,5000),'private_asset_ref'=>'nga-1','analyzer_ref'=>'fixture-1.1.0','frame_image_permission_ref'=>'test-only','options'=>array('a'=>$entry),'freight_policy'=>array('frames_cents'=>350000,'large_format_cents'=>1000000,'taxable'=>false,'tax_class'=>'','approval_ref'=>'test-only'));
$manifest['options']['a']['catalog_option_id']='opcion-1';$manifest['options']['a']['orientation']='portrait';
$metadata[1]['_ge_deco_print']=$manifest;$configs[1]=array('mode'=>'fixed','options'=>array('a'=>array('label'=>'Fixture','price'=>20000)),'tax_context'=>array('multiplier'=>1));
class FakeProduct {private $id;function __construct($id){$this->id=$id;}function get_id(){return $this->id;}}
check(GE_WTP_Deco_Prints::purchasable(true,new FakeProduct(1)));check(!GE_WTP_Deco_Prints::purchasable(false,new FakeProduct(1)));check(GE_WTP_Deco_Prints::purchasable(true,new FakeProduct(999)));
GE_WTP_Deco_Prints::init();check(count($hooks)===6);check($hooks['woocommerce_add_to_cart_validation'][1]===PHP_INT_MAX);
$request=array('ge_configuration_key'=>'a','ge_deco_revision'=>'fixture-v1','ge_base_price'=>1,'ge_calculated_price'=>1,'ge_deco_snapshot'=>array('forged'=>true));
check(GE_WTP_Deco_Prints::validate(true,1,1,0,array(),$request));
check(!GE_WTP_Deco_Prints::validate(true,1,1));
check(!GE_WTP_Deco_Prints::validate(true,1,201,0,array(),$request));
check(!GE_WTP_Deco_Prints::validate(false,1,1,0,array(),$request));
check(GE_WTP_Deco_Prints::validate(true,999,1));
check(GE_WTP_Deco_Prints::cart_data(array('existing'=>'untouched'),999)===array('existing'=>'untouched'));
$data=GE_WTP_Deco_Prints::cart_data($request,1);check($data['ge_base_price']===20000);check($data['ge_calculated_price']===20000.0);check(empty($data['ge_deco_snapshot']['forged']));
$line=$data+array('product_id'=>1,'quantity'=>2);check(GE_WTP_Deco_Prints::assert_cart_line($line)['production_approved']===false);
$bad=$line;$bad['ge_calculated_price']=1;reject(function()use($bad){GE_WTP_Deco_Prints::assert_cart_line($bad);},'cart_snapshot_changed');
$bad=$line;$bad['quantity']=201;reject(function()use($bad){GE_WTP_Deco_Prints::assert_cart_line($bad);},'invalid_quantity');
$metadata[1]['_ge_deco_print']['options']['a']['frame_observed_at']=time()-GE_WTP_Deco_Prints::PRICE_MAX_AGE-1;
reject(function(){GE_WTP_Deco_Prints::resolve(1,'a','fixture-v1');},'stale_price_snapshot');$metadata[1]['_ge_deco_print']=$manifest;
$metadata[1]['_ge_deco_print']['options']['a']['available']=false;
reject(function(){GE_WTP_Deco_Prints::resolve(1,'a','fixture-v1');},'option_unavailable');$metadata[1]['_ge_deco_print']=$manifest;
reject(function(){GE_WTP_Deco_Prints::resolve(1,'a','old');},'stale_revision');
$configs[1]['options']['a']['price']=1;
reject(function(){GE_WTP_Deco_Prints::resolve(1,'a','fixture-v1');},'price_changed');$configs[1]['options']['a']['price']=20000;
$metadata[1]['_ge_deco_print']['frame_image_permission_ref']='';
reject(function(){GE_WTP_Deco_Prints::resolve(1,'a','fixture-v1');},'missing_release_evidence');$metadata[1]['_ge_deco_print']=$manifest;
class FakeCart {public $lines;public $fees=array();function __construct($lines){$this->lines=$lines;}function get_cart(){return $this->lines;}function add_fee($label,$amount,$taxable,$tax_class){$this->fees[]=array($amount,$taxable,$tax_class);}}
$cart=new FakeCart(array($line,$line));GE_WTP_Deco_Prints::freight($cart);check($cart->fees===array(array(3500,false,'')));
$metadata[2]['_ge_deco_print']=$manifest;$metadata[2]['_ge_deco_print']['options']['a']['print_mm']=array(400,500);$metadata[2]['_ge_deco_print']['options']['a']['outer_mm']=array(420,520);$metadata[2]['_ge_deco_print']['options']['a']['paper_finish']='catalogo-fotografico';$configs[2]=$configs[1];
$large=GE_WTP_Deco_Prints::cart_data($request,2)+array('product_id'=>2,'quantity'=>5);
$cart=new FakeCart(array($line,$large));GE_WTP_Deco_Prints::freight($cart);check($cart->fees===array(array(13500,false,'')));
class FakeItem {public $meta=array();function add_meta_data($key,$value,$unique){$this->meta[$key]=$value;}}
$item=new FakeItem();GE_WTP_Deco_Prints::order_item($item,'fixture',$line,null);check($item->meta['_ge_deco_snapshot']===$line['ge_deco_snapshot']);check($item->meta['_ge_deco_snapshot']['production_approved']===false);
define('MB_IN_BYTES',1048576);$test_policy=array('revision'=>'observe-1');function get_option($name,$default=array()){global $test_policy;return $test_policy;}
require dirname(__DIR__,3).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-deco-observations.php';
$metadata[1]['_ge_deco_print']['options']['a']['frame_observed_at']=time()-7*86400;check(GE_WTP_Deco_Prints::resolve(1,'a','fixture-v1')['production_approved']===false);$metadata[1]['_ge_deco_print']=$manifest;
$old=time()-90000;$metadata[1]['_ge_deco_print']['options']['a']['frame_observed_at']=$old;$metadata[1]['_ge_deco_print']['options']['a']['print_observed_at']=$old;$metadata[1]['_ge_deco_print']['options']['a']['valid_until']=$old+86400;
$observation=array('complete'=>true,'approved_revision'=>'observe-1','observed_at'=>time(),'frame_observed_at'=>time(),'print_observed_at'=>time(),'source_digest'=>str_repeat('a',64),'options'=>array('opcion-1'=>array('unchanged'=>true)));
file_put_contents(GE_DECO_PRIVATE_ROOT.'/latest-pricing-observation.json',json_encode($observation));check(GE_WTP_Deco_Prints::resolve(1,'a','fixture-v1')['production_approved']===false);
$metadata[1]['_ge_deco_print']['options']['a']['valid_until']=time()-1;reject(function(){GE_WTP_Deco_Prints::resolve(1,'a','fixture-v1');},'option_unavailable');
$test_policy['revision']='observe-2';$observation['approved_revision']='observe-2';$observation['options']['opcion-1']['unchanged']=false;file_put_contents(GE_DECO_PRIVATE_ROOT.'/latest-pricing-observation.json',json_encode($observation));reject(function(){GE_WTP_Deco_Prints::resolve(1,'a','fixture-v1');},'price_review_required');
$test_policy['revision']='observe-3';$observation['approved_revision']='observe-3';$observation['frame_observed_at']=time()-GE_WTP_Deco_Prints::PRICE_MAX_AGE-1;file_put_contents(GE_DECO_PRIVATE_ROOT.'/latest-pricing-observation.json',json_encode($observation));reject(function(){GE_WTP_Deco_Prints::resolve(1,'a','fixture-v1');},'stale_price_snapshot');
$test_policy['revision']='observe-4';unlink(GE_DECO_PRIVATE_ROOT.'/latest-pricing-observation.json');$metadata[1]['_ge_deco_print']=$manifest;
file_put_contents($folder.'print-master.jpg','corrupt');reject(function(){GE_WTP_Deco_Prints::resolve(1,'a','fixture-v1');},'original_checksum_mismatch');
echo json_encode(array('checks'=>$checks,'database_used'=>false,'orders_created'=>0,'production_approved'=>false)).PHP_EOL;
