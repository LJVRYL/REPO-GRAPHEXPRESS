<?php
// Acceptance against the staged private payload, never against customer data.
if(PHP_SAPI!=='cli'||empty($argv[1]))exit(2);
define('ABSPATH',dirname(__DIR__,3).'/');
define('GE_WTP_PLUGIN_DIR',ABSPATH.'wp-content/plugins/ge-webtoprint-calculator/');
define('GE_DECO_PRIVATE_ROOT',realpath($argv[1]));
function rest_url($path){return 'https://qa.invalid/wp-json/'.$path;}
function wp_generate_uuid4(){return bin2hex(random_bytes(16));}
function wp_json_encode($value){return json_encode($value);}
function register_rest_route($ns,$path,$args){$GLOBALS['routes'][]=$path;}
require GE_WTP_PLUGIN_DIR.'includes/class-ge-deco-print-quality.php';
require GE_WTP_PLUGIN_DIR.'includes/class-ge-wtp-deco-gallery.php';
require GE_WTP_PLUGIN_DIR.'includes/class-ge-wtp-deco-prints.php';
$checks=0;
function check($value,$message){global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
function rejects($call,$code){try{$call();}catch(InvalidArgumentException $e){check($e->getMessage()===$code,$e->getMessage());return;}throw new RuntimeException('Expected '.$code);}
$works=GE_WTP_Deco_Gallery::works();$bauhaus=array_filter($works,fn($w)=>str_starts_with($w['id'],'bauhaus-'));
check(count($bauhaus)===51,'51 works');check(count($works)===231,'Existing 180 works preserved');
GE_WTP_Deco_Gallery::routes();
foreach($GLOBALS['routes'] as $path){if(str_contains($path,'(?P<id>'))check(str_contains($path,'bauhaus-'),'Route accepts collection');}
foreach($bauhaus as $id=>$work){
    $record=GE_WTP_Deco_Gallery::record($id,true);check(is_array($record),'Real analyzed master');
    check(GE_WTP_Deco_Gallery::licensed_sales($record),'Scoped license evidence');
    $manifest=array('private_asset_ref'=>$id,'production_sha256'=>$record['source_sha256'],'production_pixels'=>$record['work']['pixels']);
    GE_WTP_Deco_Prints::verify_asset($manifest);check(true,'Production resolver accepts licensed master');
    $public=GE_WTP_Deco_Gallery::public_work($record);check($public['verified']&&$public['sales_enabled'],'Public readiness');
    check(!isset($public['rights_evidence'])&&!isset($public['provenance'])&&!isset($public['source']),'No supplier/private provenance disclosure');
    $bad=$record;$bad['rights_evidence']['physical_print_sales']=false;check(!GE_WTP_Deco_Gallery::licensed_sales($bad),'No implicit sales license');
    $bad=$record;$bad['image_id']=str_repeat('0',64);check(!GE_WTP_Deco_Gallery::licensed_sales($bad),'Image-bound rights');
    $bad=$record;$bad['rights_evidence']['evidence_sha256']=str_repeat('0',64);check(!GE_WTP_Deco_Gallery::licensed_sales($bad),'Evidence checksum');
    $manifest['production_sha256']=str_repeat('0',64);rejects(fn()=>GE_WTP_Deco_Prints::verify_asset($manifest),'original_checksum_mismatch');
}
foreach(array('../bauhaus-001','bauhaus-999','bauhaus-001/../../x','bauhaus-1') as $id)rejects(fn()=>GE_WTP_Deco_Gallery::folder($id),'unknown_artwork');
$id='bauhaus-001';$option=current(array_filter(GE_WTP_Deco_Gallery::options(),fn($o)=>$o['size']===array(20,30)));
check((bool)$option,'Existing frame option');
$state=array('group'=>$option['group'],'model'=>$option['model'],'finish'=>$option['finish'],'size'=>'20x30','orientation'=>'portrait','fit'=>'cover');
$selection=GE_WTP_Deco_Gallery::selection($id,$state);$path=GE_WTP_Deco_Gallery::folder($id).'/selection-'.$selection['selection_id'].'.json';
$saved=json_decode(file_get_contents($path),true);check($saved['work_id']===$id&&!$saved['production_approved'],'Native selection keeps approval gate');
unlink($path); // Only this test's newly-created exact selection file.
$bad=$state;$bad['size']='9999x9999';rejects(fn()=>GE_WTP_Deco_Gallery::selection($id,$bad),'invalid_selection');
rejects(fn()=>GE_Deco_Print_Quality::enforce(array(100,100),array(400,600),'cover'),'insufficient_resolution');
echo json_encode(array('passed'=>true,'checks'=>$checks,'works'=>count($bauhaus),'orders_created'=>0,'payments_created'=>0)).PHP_EOL;
