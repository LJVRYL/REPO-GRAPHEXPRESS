<?php
define('ABSPATH',sys_get_temp_dir().'/deco-public-fixture/');@mkdir(ABSPATH);
define('GE_DECO_PRIVATE_ROOT',sys_get_temp_dir().'/deco-preview-'.uniqid());mkdir(GE_DECO_PRIVATE_ROOT);
define('GE_WTP_PLUGIN_DIR',dirname(__DIR__,3).'/wp-content/plugins/ge-webtoprint-calculator/');
$hooks=array();$owner=true;$checks=0;
function add_action($n,$c,$p=10,$a=1){global $hooks;$hooks[$n]=$c;}function add_filter($n,$c,$p=10,$a=1){add_action($n,$c,$p,$a);}
function rest_url($path){return 'https://fixture.invalid/wp-json/'.$path;}function wp_create_nonce($key){return 'fixture-nonce';}function add_query_arg($key,$value,$url){return $url.'?'.urlencode($key).'='.urlencode($value);}function esc_url($v){return htmlspecialchars($v,ENT_QUOTES);}function esc_attr($v){return htmlspecialchars($v,ENT_QUOTES);}
class GE_WTP_Deco_Uploads {public static function artwork($id){global $owner;if(!$owner)throw new InvalidArgumentException('unknown_upload');return 1;}}
require GE_WTP_PLUGIN_DIR.'includes/class-ge-wtp-deco-gallery.php';
function check($v){global $checks;if(!$v)throw new RuntimeException('Check '.($checks+1));$checks++;}
GE_WTP_Deco_Gallery::init();check(isset($hooks['woocommerce_cart_item_thumbnail'],$hooks['woocommerce_store_api_cart_item_images']));
$legacy=array((object)array('id'=>99));check(GE_WTP_Deco_Gallery::cart_images($legacy,array(),'legacy')===$legacy);
$id=str_repeat('a',32);$line=array('ge_deco_snapshot'=>array('artwork_id'=>'tu-imagen-'.$id));$images=GE_WTP_Deco_Gallery::cart_images($legacy,$line,'private');check($images[0]->id===0&&strpos($images[0]->src,'/uploads/'.$id.'/preview?_wpnonce=')!==false);check($images[0]->src===$images[0]->thumbnail);check(strpos(GE_WTP_Deco_Gallery::cart_thumbnail('old',$line,'private'),'<img ')===0);
$owner=false;check(GE_WTP_Deco_Gallery::cart_images($legacy,$line,'private')===$legacy);check(GE_WTP_Deco_Gallery::cart_thumbnail('old',$line,'private')==='old');
check(GE_WTP_Deco_Gallery::cart_images($legacy,array('ge_deco_snapshot'=>array('artwork_id'=>'../../wp-config.php')),'forged')===$legacy);
echo json_encode(array('passed'=>true,'checks'=>$checks,'legacy_unchanged'=>true,'private_preview_requires_owner'=>true,'public_media_uploads'=>0)).PHP_EOL;
