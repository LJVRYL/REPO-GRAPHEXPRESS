<?php
define('ABSPATH', __DIR__ . '/');
$hooks = array(); $state = array('ready'=>false, 'path'=>'/', 'paged'=>1);
function add_action($n,$f,$p=10,$a=1){global $hooks;$hooks[$n][]=$f;}
function add_filter($n,$f,$p=10,$a=1){add_action($n,$f,$p,$a);}
function remove_action($n,$f){}
function is_admin(){return false;}
function is_404(){return false;}
function is_search(){return false;}
function is_feed(){return false;}
function is_author(){return false;}
function is_front_page(){return true;}
function is_cart(){return false;}
function is_checkout(){return strpos($_SERVER['REQUEST_URI'],'/checkout/')===0;}
function is_account_page(){return false;}
function is_product(){return strpos($_SERVER['REQUEST_URI'],'/product/')===0;}
function is_product_category(){return false;}
function get_query_var($n){global $state;return $n==='paged'?$state['paged']:0;}
function esc_url($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function get_page_by_path($s){return (object)array('ID'=>strlen($s));}
function current_user_can($c){return false;}
function get_option($n,$fallback=null){global $state;return $n==='wp_page_for_privacy_policy'?3:($state['ready']?'yes':'no');}
function get_privacy_policy_url(){return 'https://graphex.ar/privacy-policy/';}
function get_post_status($n){return 'publish';}
function get_queried_object_id(){return 10;}
function plugins_url($p,$f){return 'https://graphex.ar/wp-content/mu-plugins/'.$p;}
function wp_enqueue_script($h,$u,$deps,$v,$footer){global $state;$state['enqueued']=$h;}
function wp_json_encode($v){return json_encode($v);}
function wp_add_inline_script($h,$s,$pos){global $state;$state['inline']=$s;}
$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['REQUEST_URI']='/';$_GET=array();
require $argv[1].'/wp-content/mu-plugins/ge-multihost.php';
require $argv[1].'/wp-content/mu-plugins/ge-search-readiness.php';
require $argv[1].'/wp-content/mu-plugins/ge-search-measurement.php';
function check($value,$message){if(!$value){throw new Exception($message);}echo "PASS $message\n";}
ob_start();foreach($hooks['wp_head'] as $f){$f();}$head=ob_get_clean();check(strpos($head,'href="https://graphex.ar/"')!==false,'active canonical');check(substr_count($head,'rel="canonical"')===1,'one canonical');
$state['paged']=2;ob_start();$hooks['wp_head'][0]();$page=ob_get_clean();check(strpos($page,'?paged=2')!==false,'pagination preserved');
foreach(array('/gestion/','/checkout/','/my-account/','/tarjetas/demo/','/sample-page/') as $path){$_SERVER['REQUEST_URI']=$path;$r=$hooks['wp_robots'][0](array());check(isset($r['noindex']),'noindex '.$path);}
$_SERVER['REQUEST_URI']='/product/tarjetas-personales/';$r=$hooks['wp_robots'][0](array());check(!isset($r['noindex']),'commercial page indexable');
$q=$hooks['wp_sitemaps_posts_query_args'][0](array('post__not_in'=>array(999)),'page');check(in_array(999,$q['post__not_in']),'preserve existing sitemap exclusions');check(count($q['post__not_in'])>1,'exclude operational pages');check($hooks['wp_sitemaps_add_provider'][0]('provider','users')===false,'remove author provider');check($hooks['wp_sitemaps_add_provider'][0]('provider','posts')==='provider','keep public provider');
$hooks['wp_enqueue_scripts'][0]();check(empty($state['enqueued']),'policy gate off');
$state['ready']=true;$_SERVER['REQUEST_URI']='/checkout/';$hooks['wp_enqueue_scripts'][0]();check(strpos($state['inline'],'begin_checkout')!==false,'checkout observed from actual page');
$state['enqueued']='';$_GET=array('order_key'=>'private');$hooks['wp_enqueue_scripts'][0]();check(empty($state['enqueued']),'private order query excluded');$_GET=array();$_SERVER['REQUEST_URI']='/tarjetas/demo/';$hooks['wp_enqueue_scripts'][0]();check(empty($state['enqueued']),'contact card excluded');
