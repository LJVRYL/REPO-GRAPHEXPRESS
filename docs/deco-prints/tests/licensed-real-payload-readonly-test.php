<?php
/** Read-only acceptance of actual staged masters. No WordPress bootstrap. */
if(PHP_SAPI!=='cli')exit(2);
$options=getopt('',array('private-root:','registry-plugin-root:'));
foreach(array('private-root','registry-plugin-root') as $key)if(empty($options[$key])||!is_dir($options[$key]))throw new InvalidArgumentException('Missing directory --'.$key);
$repo=dirname(__DIR__,3);define('ABSPATH',$repo.'/');define('GE_WTP_PLUGIN_DIR',rtrim(realpath($options['registry-plugin-root']),'/\\').'/');define('GE_DECO_PRIVATE_ROOT',realpath($options['private-root']));
function forbidden($name){throw new RuntimeException('Forbidden effect: '.$name);}function get_option(...$a){forbidden(__FUNCTION__);}function get_post_meta(...$a){forbidden(__FUNCTION__);}function update_option(...$a){forbidden(__FUNCTION__);}function update_post_meta(...$a){forbidden(__FUNCTION__);}function wp_insert_post(...$a){forbidden(__FUNCTION__);}function wp_mail(...$a){forbidden(__FUNCTION__);}function wp_remote_request(...$a){forbidden(__FUNCTION__);}function wp_remote_post(...$a){forbidden(__FUNCTION__);}function WC(){forbidden(__FUNCTION__);}
function rest_url($p){return 'https://qa.invalid/'.$p;}function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
require $repo.'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-deco-gallery.php';require $repo.'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-deco-prints.php';
$n=0;function check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}function reject($f){try{$f();}catch(InvalidArgumentException $e){check($e->getMessage()==='unknown_artwork','Negative format fails closed');return;}throw new RuntimeException('Malformed ID accepted');}
$started=microtime(true);$works=GE_WTP_Deco_Gallery::works();check(count($works)>0,'Nonempty registry');$public=array();$sourceBytes=0;
foreach($works as $id=>$w){
 check(GE_WTP_Deco_Gallery::is_licensed_pack_id($id),'Only explicitly supported licensed IDs');$r=GE_WTP_Deco_Gallery::record($id,true);check(is_array($r),'Analyzed catalog record '.$id);check(GE_WTP_Deco_Gallery::licensed_sales($r),'Actual license evidence '.$id);
 $m=array('private_asset_ref'=>$id,'production_sha256'=>$r['source_sha256'],'production_pixels'=>$r['work']['pixels']);GE_WTP_Deco_Prints::verify_asset($m);check(true,'Exact real master/analysis '.$id);$sourceBytes+=filesize(GE_DECO_PRIVATE_ROOT.'/'.$id.'/print-master.jpg');
 $w=GE_WTP_Deco_Gallery::public_work($r);foreach(array('rights_evidence','provenance','local_quality_review','source_sha256','private_asset_ref','download_kind') as $key)check(!array_key_exists($key,$w),'No private metadata '.$key);check(!isset($w['source']),'No private supplier source');$public[]=$w;
}
$verifiedSeconds=microtime(true)-$started;$started=microtime(true);$prepared=array();foreach(GE_WTP_Deco_Gallery::works() as $id=>$unused)if($r=GE_WTP_Deco_Gallery::record($id))$prepared[]=GE_WTP_Deco_Gallery::public_work($r);$bytes=strlen(json_encode(array('works'=>$prepared)));$preparedSeconds=microtime(true)-$started;
foreach(array('../trendy-0001','trendy-1','trendy-00001','bauhaus-1','other-0001','trendy-0001/../x',"trendy-0001\n") as $id)reject(fn()=>GE_WTP_Deco_Gallery::folder($id));
echo json_encode(array('passed'=>true,'checks'=>$n,'works'=>count($works),'source_bytes'=>$sourceBytes,'verification_seconds'=>$verifiedSeconds,'prepared_loop_seconds'=>$preparedSeconds,'prepared_json_bytes'=>$bytes,'peak_memory_bytes'=>memory_get_peak_usage(true),'payload_writes'=>0,'database_writes'=>0,'network_calls'=>0,'real_orders'=>0,'payments'=>0,'emails'=>0),JSON_PRETTY_PRINT).PHP_EOL;
