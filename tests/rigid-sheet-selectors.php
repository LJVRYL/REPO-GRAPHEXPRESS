<?php
define('ABSPATH',__DIR__);define('GE_WTP_PLUGIN_DIR',__DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/');
function get_post_meta($id,$key,$single=true){return $GLOBALS['fixture'][$id][$key]??'';}
function get_option($key,$default=null){return $GLOBALS['settings'][$key]??$default;}
function sanitize_title($s){return trim(preg_replace('/[^a-z0-9]+/','-',strtolower(str_replace('×','x',$s))),'-');}
// Portable QA PHP lacks mbstring; these fixture labels contain ASCII words and the unchanged × symbol.
if(!function_exists('mb_convert_case')){define('MB_CASE_TITLE',0);function mb_convert_case($s,$mode,$encoding){return ucwords($s);}}
class GE_WTP_Roll_Pricing{static function widths_for_catalog_key($k){return array();}}
require GE_WTP_PLUGIN_DIR.'includes/class-ge-wtp-public-catalog.php';require GE_WTP_PLUGIN_DIR.'includes/class-ge-wtp-storefront.php';
$fixture=array();$out=array();$count=0;
function check_sheet($v,$why){if(!$v)throw new Exception($why);}
foreach(GE_WTP_Public_Catalog::products() as $key=>$p){if($p['group']!=='placas-rigidas')continue;
    $fixture[99]=array('_ge_public_catalog_key'=>$key,'_ge_supplier_costs'=>$p['costs'],'_ge_supplier_cost_unit'=>$p['unit']);$config=GE_WTP_Storefront::config(99);
    check_sheet(array_column($config['selectors'],'label')===array('Medida','Impresión'),'separate fields '.$key);check_sheet(count($config['option_map'])===count($p['costs']),'every native combination '.$key);
    foreach($p['costs'] as $native=>$cost){$k=sanitize_title($native);check_sheet($config['options'][$k]['price']===round($cost*1.3),'native price retained '.$k);check_sheet(in_array($k,$config['option_map'],true),'native key reachable');$count++;}
    $out[$key]=$config;
}
check_sheet(count($out)===9&&$count===24,'nine sheets and 24 purchase options');
$fixture[99]=array('_ge_public_catalog_key'=>'placa-pvc-3mm','_ge_supplier_costs'=>array('unknown-shape'=>100),'_ge_supplier_cost_unit'=>'placa');check_sheet(empty(GE_WTP_Storefront::config(99)['selectors']),'unknown format not guessed');
$fixture[99]=array('_ge_public_catalog_key'=>'placa-pvc-3mm','_ge_supplier_costs'=>array('simple'=>100),'_ge_supplier_cost_unit'=>'placa');check_sheet(empty(GE_WTP_Storefront::config(99)['selectors']),'multiple fixed measures cannot be guessed');
$fixture[99]=array('_ge_public_catalog_key'=>'placa-pvc-3mm','_ge_supplier_costs'=>array('60×80 simple'=>11900),'_ge_supplier_cost_unit'=>'placa','_ge_storefront_selectors'=>array('fields'=>array('existing'),'map'=>array('native'=>'60x80-simple')));check_sheet(GE_WTP_Storefront::config(99)['selectors']===array('existing'),'explicit selectors preserved');
$fixture[99]=array('_ge_public_catalog_key'=>'vinilo-blanco','_ge_supplier_costs'=>array('simple'=>100),'_ge_supplier_cost_unit'=>'unidad');check_sheet(empty(GE_WTP_Storefront::config(99)['selectors']),'unrelated product unchanged');
if(($argv[1]??'')==='--export'){echo json_encode($out,JSON_UNESCAPED_UNICODE);}else{echo json_encode(array('status'=>'PASS','families'=>9,'native_options'=>24,'unknown_and_unrelated_configs_preserved'=>true));}
