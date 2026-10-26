<?php
// Isolated synthetic pack contract; never loads WordPress or a database.
if(PHP_SAPI!=='cli'||count($argv)!==4)exit(2);
$repo=dirname(__DIR__,3);$tmp=$argv[1];$withdraw=$argv[2];$public=$tmp.'/public';$private=$tmp.'/private';
define('ABSPATH',$public.'/');define('GE_WTP_PLUGIN_DIR',$public.'/');define('GE_DECO_PRIVATE_ROOT',$private);define('GE_DECO_PRINTS_ENABLED',true);
function forbidden($name){throw new RuntimeException('Forbidden effect: '.$name);}
function update_option(...$a){forbidden(__FUNCTION__);}function update_post_meta(...$a){forbidden(__FUNCTION__);}function wp_insert_post(...$a){forbidden(__FUNCTION__);}function wp_mail(...$a){forbidden(__FUNCTION__);}function wp_remote_request(...$a){forbidden(__FUNCTION__);}function wp_remote_post(...$a){forbidden(__FUNCTION__);}function WC(){forbidden(__FUNCTION__);}
function get_option(...$a){throw new InvalidArgumentException('qa_policy_boundary');}
function get_woocommerce_currency(){return 'ARS';}function rest_url($p){return 'https://qa.invalid/'.$p;}function wp_json_encode($v,$f=0){return json_encode($v,$f);}function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0700,true);}function wp_generate_uuid4(){return bin2hex(random_bytes(16));}function register_rest_route($ns,$path,$args){$GLOBALS['routes'][]=$path;}
function get_post_meta(...$a){return $GLOBALS['snapshot'];}
class GE_WTP_Storefront {static function config($id){throw new InvalidArgumentException('qa_config_boundary');}}
class WC_Order {function get_items(){return array(new QAItem);}function get_id(){return $GLOBALS['order_id'];}}
class QAItem {function get_meta(...$a){return $GLOBALS['snapshot'];}function get_id(){return 1;}}
class QAProduct {function get_id(){return 1;}}
foreach(array('class-ge-deco-print-quality.php','class-ge-wtp-deco-gallery.php','class-ge-wtp-deco-prints.php','class-ge-wtp-deco-release.php','class-ge-wtp-deco-proofs.php') as $f)require $repo.'/wp-content/plugins/ge-webtoprint-calculator/includes/'.$f;
$n=0;function check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}function rejects($f,$code){try{$f();}catch(InvalidArgumentException $e){check($e->getMessage()===$code,$e->getMessage().' expected '.$code);return;}throw new RuntimeException('Expected '.$code);}
GE_WTP_Deco_Gallery::routes();foreach($GLOBALS['routes'] as $r)if(str_contains($r,'(?P<id>'))check(str_contains($r,'trendy-[0-9]{4}')&&str_contains($r,'bauhaus-[0-9]{3}'),'Both REST patterns');
foreach(array('bauhaus-001','trendy-0001') as $i=>$id){
 $iswithdrawn=str_starts_with($id,$withdraw);$r=GE_WTP_Deco_Gallery::record($id,true);check(GE_WTP_Deco_Gallery::licensed_sales($r),'License independent of sale availability');
 $s=array('artwork_id'=>$id,'private_asset_ref'=>$id,'production_sha256'=>$r['source_sha256'],'production_pixels'=>$r['work']['pixels'],'composition'=>array('print_mm'=>array(200,300),'fit'=>'cover'),'route'=>'digital');$GLOBALS['snapshot']=$s;
 GE_WTP_Deco_Prints::verify_asset($s);check(true,'Exact prior source');check(GE_WTP_Deco_Gallery::public_work($r)['sales_enabled']===!$iswithdrawn,'Public sale state');
 $state=array('group'=>'frames','model'=>'basic','finish'=>'black','size'=>'20x30','orientation'=>'portrait','fit'=>'cover');
 if($iswithdrawn){foreach(array(fn()=>GE_WTP_Deco_Gallery::prepare($id),fn()=>GE_WTP_Deco_Gallery::selection($id,$state),fn()=>GE_WTP_Deco_Gallery::cart(array('items'=>array(array('workId'=>$id)))),fn()=>GE_WTP_Deco_Release::ensure_product($id),fn()=>GE_WTP_Deco_Prints::resolve(1,'old','old'),fn()=>GE_WTP_Deco_Prints::assert_cart_line(array('product_id'=>1,'ge_deco_snapshot'=>$s))) as $f)rejects($f,'artwork_sales_unavailable');check(!GE_WTP_Deco_Prints::purchasable(true,new QAProduct),'Native purchase blocked');}
 else{check(GE_WTP_Deco_Gallery::prepare($id)['status']==='ready','Active prepare');$sel=GE_WTP_Deco_Gallery::selection($id,$state);check(is_file($private.'/'.$id.'/selection-'.$sel['selection_id'].'.json'),'Active selection');rejects(fn()=>GE_WTP_Deco_Gallery::cart(array('items'=>array(array('workId'=>$id)))),'invalid_selection_reference');rejects(fn()=>GE_WTP_Deco_Release::ensure_product($id),'qa_policy_boundary');rejects(fn()=>GE_WTP_Deco_Prints::resolve(1,'old','old'),'qa_config_boundary');}
 $GLOBALS['order_id']=100+$i;GE_WTP_Deco_Proofs::queue(new WC_Order);GE_WTP_Deco_Proofs::queue(new WC_Order);$q=json_decode(file_get_contents($private.'/.orders/order-'.(100+$i).'/request.json'),true)['items'][0];check($q['source_sha256']===$s['production_sha256']&&hash_file('sha256',$q['source_path'])===$s['production_sha256'],'Prior proof source');check($q['snapshot_sha256']===GE_WTP_Deco_Proofs::fingerprint($s),'Prior proof fingerprint');
 foreach(array('physical_print_sales','evidence_sha256','image_sha256') as $key){$bad=$r;$bad['rights_evidence'][$key]=false;check(!GE_WTP_Deco_Gallery::licensed_sales($bad),'Rights fail closed');}
 $bad=$s;$bad['production_sha256']=str_repeat('0',64);rejects(fn()=>GE_WTP_Deco_Prints::verify_asset($bad),'original_checksum_mismatch');
 // A licensed pack must never fall back to a forged museum source.
 $path=$private.'/'.$id.'/catalog-record.json';$bad=$r;$bad['source']['id']='met';$bad['rights_evidence']['isPublicDomain']=true;file_put_contents($path,json_encode($bad));rejects(fn()=>GE_WTP_Deco_Prints::verify_asset($s),'unapproved_artwork');file_put_contents($path,json_encode($r));
}
foreach(array('trendy-1','trendy-00001','trendy-9999','bauhaus-1','other-0001','../trendy-0001','trendy-0001/../x') as $id)rejects(fn()=>GE_WTP_Deco_Gallery::folder($id),'unknown_artwork');
echo json_encode(array('passed'=>true,'checks'=>$n,'withdrawn'=>$withdraw,'database_writes'=>0,'real_orders'=>0,'payments'=>0,'emails'=>0)).PHP_EOL;
