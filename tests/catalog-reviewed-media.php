<?php
define('ABSPATH',__DIR__.'/');function add_action(){}function is_user_logged_in(){return $GLOBALS['logged']??false;}function current_user_can($c){return $GLOBALS['cap']??false;}
class GE_Organization_Runtime{public static function allowed($m,$w){return $m==='cost_engine'&&$w&&($GLOBALS['org']??false);}}
class WP_Error{public $code;function __construct($c,$m){$this->code=$c;}}
require __DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-catalog-reviewed-media.php';
$checks=0;function ok($v){global $checks;if(!$v)throw new Exception('Check failed '.($checks+1));$checks++;}
$c=GE_WTP_Catalog_Reviewed_Media::catalog();ok(count($c['assets'])===44);ok(count($c['products'])===18);ok(!GE_WTP_Catalog_Reviewed_Media::can_write());$GLOBALS['logged']=true;$GLOBALS['cap']=true;ok(!GE_WTP_Catalog_Reviewed_Media::can_write());$GLOBALS['org']=true;ok(GE_WTP_Catalog_Reviewed_Media::can_write());$GLOBALS['cap']=false;ok(!GE_WTP_Catalog_Reviewed_Media::can_write());
foreach($c['products'] as $slug=>$p)foreach($p['options'] as $key=>$o){ok((bool)preg_match('/^wb-[0-9]+(?:-v[0-9]+)?$/',$key));foreach($o['assets'] as $name)ok(isset($c['assets'][$name]));}
ok(GE_WTP_Catalog_Reviewed_Media::validate_files(array()) instanceof WP_Error);ok(!GE_WTP_Catalog_Reviewed_Media::validate_asset('evil.php',__FILE__));ok(!GE_WTP_Catalog_Reviewed_Media::validate_asset(array_key_first($c['assets']),__FILE__));
if(!empty($argv[1]))foreach($c['assets'] as $name=>$a){ok(GE_WTP_Catalog_Reviewed_Media::validate_asset($name,$argv[1].'/'.$name));}
echo json_encode(array('status'=>'PASS','checks'=>$checks,'assets'=>44,'product_pages'=>18,'real_data_mutated'=>false)).PHP_EOL;
