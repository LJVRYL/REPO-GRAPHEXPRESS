<?php
// A separate process loads a withdrawn registry AFTER source snapshots were issued.
if(PHP_SAPI!=='cli'||count($argv)!==4)exit(2);
$source=dirname(__DIR__,3).'/wp-content/plugins/ge-webtoprint-calculator/';
define('ABSPATH',dirname(__DIR__,3).'/');
define('GE_WTP_PLUGIN_DIR',rtrim(realpath($argv[2]),'/\\').'/');
define('GE_DECO_PRIVATE_ROOT',realpath($argv[1]));
define('GE_DECO_PRINTS_ENABLED',true);
function rest_url($path){return 'https://qa.invalid/wp-json/'.$path;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0700,true);}
function get_woocommerce_currency(){return 'ARS';}
function get_post_meta($id,$key,$single){return $GLOBALS['prior_snapshot'];}
require $source.'includes/class-ge-wtp-deco-gallery.php';
require $source.'includes/class-ge-wtp-deco-prints.php';
require $source.'includes/class-ge-wtp-deco-release.php';
require $source.'includes/class-ge-wtp-deco-proofs.php';
$checks=0;
function check($value,$label){global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function blocked($call){try{$call();}catch(InvalidArgumentException $e){check($e->getMessage()==='artwork_sales_unavailable',$e->getMessage());return;}throw new RuntimeException('New sale was not blocked');}
class FakePriorItem {
    function get_meta($key,$single){return $GLOBALS['prior_snapshot'];}
    function get_id(){return 1;}
}
class WC_Order {
    function get_items(){return array(new FakePriorItem);}
    function get_id(){return 868626001;}
}
class FakeProduct {function get_id(){return 1;}}
$snapshots=json_decode(file_get_contents($argv[3]),true);check(count($snapshots)===51,'51 issued source snapshots');
foreach($snapshots as $snapshot){
    $id=$snapshot['private_asset_ref'];$GLOBALS['prior_snapshot']=$snapshot;
    $record=GE_WTP_Deco_Gallery::record($id,true);
    check(GE_WTP_Deco_Gallery::licensed_sales($record),'Original license preserved');
    GE_WTP_Deco_Prints::verify_asset($snapshot);check(true,'Prior source remains valid');
    check(GE_WTP_Deco_Gallery::public_work($record)['sales_enabled']===false,'Public commerce disabled');
    blocked(fn()=>GE_WTP_Deco_Gallery::prepare($id));
    blocked(fn()=>GE_WTP_Deco_Gallery::selection($id,array()));
    blocked(fn()=>GE_WTP_Deco_Gallery::cart(array('items'=>array(array('workId'=>$id,'serverSelectionId'=>str_repeat('0',32))))));
    blocked(fn()=>GE_WTP_Deco_Release::ensure_product($id));
    blocked(fn()=>GE_WTP_Deco_Prints::resolve(1,'old-option','old-revision'));
    blocked(fn()=>GE_WTP_Deco_Prints::assert_cart_line(array('product_id'=>1,'ge_deco_snapshot'=>$snapshot)));
    check(!GE_WTP_Deco_Prints::purchasable(true,new FakeProduct),'Native Woo cannot purchase withdrawn work');
}
$GLOBALS['prior_snapshot']=$snapshots[0];
$folder=GE_DECO_PRIVATE_ROOT.'/.orders/order-868626001';$target=$folder.'/request.json';
check(!file_exists($target),'Isolated proof fixture absent before test');
GE_WTP_Deco_Proofs::queue(new WC_Order);GE_WTP_Deco_Proofs::queue(new WC_Order);
$request=json_decode(file_get_contents($target),true);$item=$request['items'][0];
check($item['work_id']===$snapshots[0]['artwork_id'],'Prior order proof accepts withdrawn ID');
check(hash_file('sha256',$item['source_path'])===$snapshots[0]['production_sha256'],'Prior proof keeps exact source SHA');
check($item['snapshot_sha256']===GE_WTP_Deco_Proofs::fingerprint($snapshots[0]),'Prior proof fingerprint preserved');
unlink($target);rmdir($folder);if(!glob(GE_DECO_PRIVATE_ROOT.'/.orders/*'))rmdir(GE_DECO_PRIVATE_ROOT.'/.orders');
echo json_encode(array('passed'=>true,'checks'=>$checks,'prior_sources_verified'=>51,'native_proof_queued_idempotently'=>true,'new_prepare_selection_cart_product_checkout_blocked'=>true,'real_database_orders'=>0,'payments_created'=>0)).PHP_EOL;
