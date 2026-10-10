<?php
define('ABSPATH',__DIR__);define('GE_WTP_PLUGIN_DIR',__DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/');
function sanitize_title($s){return trim(preg_replace('/[^a-z0-9]+/','-',strtolower($s)),'-');}
function get_post_meta($id,$key,$single=true){global $fixture;return $fixture[$id][$key]??'';}
function wc_tax_enabled(){global $tax_enabled;return $tax_enabled??false;}
function apply_filters($tag,$v,...$args){return $v;}
function is_admin(){return false;}
function WC(){global $cart_fixture;return (object)array('cart'=>$cart_fixture);}
class GE_WTP_Billing_Issuers{public static function all(){global $issuers;return $issuers;}}
require GE_WTP_PLUGIN_DIR.'includes/class-ge-wtp-customer-tax.php';
require GE_WTP_PLUGIN_DIR.'includes/class-ge-wtp-windbanners-catalog.php';
require GE_WTP_PLUGIN_DIR.'includes/class-ge-wtp-storefront.php';
$checks=0;function ok($v,$why){global $checks;if(!$v)throw new Exception($why);$checks++;}
$issuers=array(array('id'=>'common-c','active'=>true,'default_for_scenarios'=>array('common'),'vat_status'=>'monotributo','invoice_types_allowed'=>array('C'),'relationship_confirmed'=>true,'verification_status'=>'verified','verified_at'=>gmdate('c',time()-60),'tax_rate_basis_points'=>0));
$products=GE_WTP_Windbanners_Catalog::products();ok(count($products)===26,'all existing families and gazebo replacements retained');$total=0;$seen=array();
foreach($products as $family=>$data){foreach($data['options'] as $key=>$o){ok(!isset($seen[$key]),'unique variant key '.$key);$seen[$key]=1;ok($o['price']>0&&$o['min_qty']>=1&&$o['step']>=1,'valid option '.$key);$total++;}}
ok($total===164,'164 active mappings');ok(abs($products['banderas-sublimadas']['options']['wb-210']['price']-87750)<.001,'Leo 75000→67500→87750');
ok($products['banderas-sublimadas']['options']['wb-347']['min_qty']===6,'bandera min6');ok($products['banderas-sublimadas']['options']['wb-212']['min_qty']===2,'bandera min2');
ok(count($products['gazebo-repuestos']['options'])===0,'inactive gazebo replacements manual');ok(!isset($products['fly-oval']['options']['wb-187']),'inactive oval unavailable');
ok($products['puffs-kids']['options']['wb-315-v57']['price']===31590.0,'rectangular kid without fill uses variant57');ok($products['puffs-kids']['options']['wb-315-v58']['price']===52650.0,'rectangular kid with fill uses variant58');
ok($products['gazebos-completos']['options']['wb-227-v1']['price']===737100.0,'gazebo iron');ok($products['gazebos-completos']['options']['wb-227-v2']['price']===836550.0,'gazebo aluminum');
ok(count($products['bases-estacas']['options'])===6,'all separate accessories');
$context=GE_WTP_Windbanners_Catalog::tax_context();ok($context['multiplier']===1,'C has no IVA addition');
$issuers[0]['vat_status']='registered';$issuers[0]['invoice_types_allowed']=array('B');$issuers[0]['tax_rate_basis_points']=2100;
ok(abs(GE_WTP_Windbanners_Catalog::tax_context()['multiplier']-1.21)<.00001,'verified actual registered rate');
$issuers[0]['verification_status']='pending';ok(GE_WTP_Windbanners_Catalog::tax_context()===array(),'unverified issuer cannot sell automatically');
$issuers[0]['verification_status']='verified';$issuers[0]['verified_at']='2020-01-01T00:00:00Z';ok(GE_WTP_Windbanners_Catalog::tax_context()===array(),'stale evidence blocked');
$issuers[0]['verified_at']=gmdate('c',time()-60);$tax_enabled=true;ok(GE_WTP_Windbanners_Catalog::tax_context()===array(),'WC tax enabled prevents double taxation');
$fixture=array(42=>array('_ge_public_catalog_key'=>'windbanners-gazebo-repuestos'));ok(GE_WTP_Windbanners_Catalog::storefront_config(42)===array(),'manual family has no checkout');ok(GE_WTP_Windbanners_Catalog::storefront_config(43)===null,'other products keep original configurator');
$frozen=new class {public $price;function set_price($v){$this->price=$v;}};
$cart_fixture=new class($frozen) {private $p;function __construct($p){$this->p=$p;}function get_cart(){return array(array('ge_calculated_price'=>117975,'ge_base_price'=>97500,'ge_configuration_key'=>'s0-r5-c1','data'=>$this->p));}};
GE_WTP_Storefront::apply_cart_prices($cart_fixture);ok($frozen->price===117975.0,'existing cart snapshots retain stored prices');
echo json_encode(array('passed'=>$checks,'families'=>count($products),'active_options'=>$total));
