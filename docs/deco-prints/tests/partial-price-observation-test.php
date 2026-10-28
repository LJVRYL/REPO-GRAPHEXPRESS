<?php
/** Synthetic contract and real Woo adapter entry points, no WP/DB/network. */
define('ABSPATH',sys_get_temp_dir().'/ge-partial-public-'.uniqid().'/');mkdir(ABSPATH);
define('GE_DECO_PRIVATE_ROOT',sys_get_temp_dir().'/ge-partial-private-'.uniqid());mkdir(GE_DECO_PRIVATE_ROOT);
define('MB_IN_BYTES',1048576);define('GE_DECO_PRINTS_ENABLED',true);
$checks=0;$case=0;$policy=[];$metadata=[];$config=[];$notices=[];
function get_option($name,$default=[]){global $policy;return $policy;}
function get_post_meta($id,$key,$single){global $metadata;return $metadata[$key]??[];}
function get_current_user_id(){return 0;}function get_woocommerce_currency(){return 'ARS';}
function wc_add_notice($message,$type){global $notices;$notices[]=$message;}
class GE_WTP_Deco_Gallery{static function is_licensed_pack_id($id){return false;}}
class GE_WTP_Storefront{static function config($id){global $config;return $config;}}
require __DIR__.'/../../../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-deco-observations.php';
require __DIR__.'/../../../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-deco-release.php';
require __DIR__.'/../../../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-deco-prints.php';
function ok($value,$message){global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
function rejected($action){try{$action();}catch(InvalidArgumentException $error){ok(true,$error->getMessage());return;}throw new RuntimeException('Unsafe option accepted');}
function fixture($modify=null){
 global $case,$policy;$now=time();$case++;
 $policy=['approved'=>true,'currency'=>'ARS','revision'=>'fixture-'.$case,'geometry_approval_ref'=>'qa','pricing_approval_ref'=>'qa','tax_approval_ref'=>'qa','frame_asset_use_ref'=>'qa','frame_observed_at'=>$now-700000,'print_observed_at'=>$now-700000,'tax_context'=>['multiplier'=>1,'label'=>'Price'],'options'=>[],'frame_source_map'=>[]];
 $rows=[];foreach([1,2,3]as $n){$id='opcion-'.$n;$policy['options'][$id]=['variant_ref'=>'variant:'.$n,'frame_cost_cents'=>1000000,'printing_cents'=>147400,'available'=>true,'print_dimensions_verified'=>true,'unit_base_cents'=>2000000];$policy['frame_source_map'][]=['public_id'=>$id,'supplier_variant_id'=>$n,'source'=>'https://fixture.invalid/'.$n,'nominal_variant'=>['20x30','Negro']];
  $rows[$id]=['status'=>'verified','unchanged'=>true,'variant_id'=>(string)$n,'source'=>'https://fixture.invalid/'.$n,'nominal_variant'=>['20x30','Negro'],'frame_observed_at'=>$now,'print_observed_at'=>$now,'source_sha256'=>str_repeat('a',64),'frame_cost_cents'=>1000000,'printing_cents'=>147400,'available'=>true,'reference_catalog_cents'=>null];
 }
 $rows['opcion-2']=['status'=>'unknown','unchanged'=>false,'variant_id'=>'2','source'=>'https://fixture.invalid/2','nominal_variant'=>['20x30','Negro'],'reason'=>'source_unavailable'];
 $rows['opcion-3']['status']='changed';$rows['opcion-3']['unchanged']=false;$rows['opcion-3']['frame_cost_cents']++;
 $data=['schema_version'=>1,'kind'=>'graphex-price-evidence-per-option','complete'=>false,'source_complete'=>false,'approved_revision'=>$policy['revision'],'observed_at'=>$now,'source_digest'=>str_repeat('b',64),'printing_source_digest'=>str_repeat('c',64),'options'=>$rows];
 if($modify)$modify($data,$policy);
 file_put_contents(GE_DECO_PRIVATE_ROOT.'/latest-pricing-partial-observation.json',json_encode($data));return $data;
}
fixture();$saved=$policy;
ok(GE_WTP_Deco_Release::policy()===$policy,'Global stale dates may not override valid per-option evidence');
ok(GE_WTP_Deco_Observations::allowed('opcion-1',$policy),'Verified option available');
ok(!GE_WTP_Deco_Observations::allowed('opcion-2',$policy),'Unknown held');
ok(!GE_WTP_Deco_Observations::allowed('opcion-3',$policy),'Changed held');
ok($saved===$policy,'Approved policy unchanged');
$entry=['cost_version_ref'=>$policy['revision'],'supplier_variant_ref'=>'variant:1'];
ok(count(GE_WTP_Deco_Observations::verify('opcion-1',$entry))===2,'Selection receives real dates');
rejected(fn()=>GE_WTP_Deco_Observations::verify('opcion-2',['cost_version_ref'=>$policy['revision'],'supplier_variant_ref'=>'variant:2']));
rejected(fn()=>GE_WTP_Deco_Observations::verify('opcion-1',['cost_version_ref'=>'other','supplier_variant_ref'=>'variant:1']));
foreach(['frame_observed_at','print_observed_at']as $field){fixture(function(&$data)use($field){$data['options']['opcion-1'][$field]=time()-700000;});ok(!GE_WTP_Deco_Observations::allowed('opcion-1',$policy),'Expired selected source held');}
foreach(['frame_observed_at','print_observed_at']as $field){fixture(function(&$data)use($field){$data['options']['opcion-1'][$field]=time()+1000;});ok(!GE_WTP_Deco_Observations::allowed('opcion-1',$policy),'Future source held');}
foreach(['frame_cost_cents','printing_cents']as $field){fixture(function(&$data)use($field){$data['options']['opcion-1'][$field]++;});ok(!GE_WTP_Deco_Observations::allowed('opcion-1',$policy),'Counterfeit unchanged flag cannot authorize changed costs');}
fixture(function(&$data){$data['options']['opcion-1']['nominal_variant']=['40x60','Negro'];});ok(!GE_WTP_Deco_Observations::allowed('opcion-1',$policy),'Nominal mismatch held');
fixture(function(&$data){$data['options']['opcion-1']['source_sha256']='bad';});ok(!GE_WTP_Deco_Observations::allowed('opcion-1',$policy),'Missing source hash held');
foreach(['revision','schema','identity','future','missing','unknown_stamp']as $fault){
 fixture(function(&$data)use($fault){if($fault==='revision')$data['approved_revision']='other';if($fault==='schema')$data['schema_version']=99;if($fault==='identity')$data['options']['opcion-1']['variant_id']='999';if($fault==='future')$data['observed_at']=time()+1000;if($fault==='missing')unset($data['options']['opcion-1']);if($fault==='unknown_stamp')$data['options']['opcion-2']['frame_observed_at']=time();});
 rejected(fn()=>GE_WTP_Deco_Release::policy());
}
fixture();$folder=GE_DECO_PRIVATE_ROOT.'/nga-1';mkdir($folder);file_put_contents($folder.'/print-master.jpg','Synthetic bytes');$sha=hash_file('sha256',$folder.'/print-master.jpg');
file_put_contents($folder.'/analysis.json',json_encode(['checksum_sha256'=>$sha,'status'=>'analyzed','blockers'=>[],'page_count'=>1,'pixel_dimensions'=>[4000,5000]]));
file_put_contents($folder.'/catalog-record.json',json_encode(['work'=>['id'=>'nga-1','pixels'=>[4000,5000]],'source_sha256'=>$sha,'image_id'=>'fixture','rights_evidence'=>['openAccess'=>['openAccess'=>true,'imageUuid'=>'fixture']],'source'=>['id'=>'nga']]));
$options=[];foreach([1,2]as $n)$options['key'.$n]=['enabled'=>true,'geometry_verified'=>true,'cost_version_ref'=>$policy['revision'],'price_approval_ref'=>'qa','supplier_variant_ref'=>'variant:'.$n,'catalog_option_id'=>'opcion-'.$n,'orientation'=>'portrait','print_mm'=>[200,300],'outer_mm'=>null,'fit'=>'cover','paper_finish'=>'mate','crop_anchor'=>[.5,.5],'frame_observed_at'=>time()-700000,'print_observed_at'=>time()-700000,'available'=>true,'valid_until'=>time()-700000+GE_WTP_Deco_Prints::PRICE_MAX_AGE,'approved_base_price'=>20000];
$metadata['_ge_deco_print']=['revision'=>'manifest-qa','rights_status'=>'approved','rights_evidence_ref'=>'qa','artwork_id'=>'nga-1','production_sha256'=>$sha,'production_pixels'=>[4000,5000],'private_asset_ref'=>'nga-1','analyzer_ref'=>'qa','frame_asset_use_ref'=>'qa','options'=>$options,'freight_policy'=>['frames_cents'=>0,'large_format_cents'=>0,'taxable'=>false,'tax_class'=>'','approval_ref'=>'qa']];
$config=['mode'=>'fixed','tax_context'=>['multiplier'=>1],'options'=>['key1'=>['price'=>20000,'label'=>'Verified'],'key2'=>['price'=>20000,'label'=>'Unknown']]];
ok(GE_WTP_Deco_Prints::resolve(1,'key1','manifest-qa')['catalog_option_id']==='opcion-1','Direct selection verified option accepted');
rejected(fn()=>GE_WTP_Deco_Prints::resolve(1,'key2','manifest-qa'));
ok(!GE_WTP_Deco_Prints::validate(true,1,1,0,[],['ge_configuration_key'=>'key2','ge_deco_revision'=>'manifest-qa']),'Direct Woo add rejects unknown');
rejected(fn()=>GE_WTP_Deco_Prints::cart_data(['ge_configuration_key'=>'key2','ge_deco_revision'=>'manifest-qa'],1));
rejected(fn()=>GE_WTP_Deco_Prints::assert_cart_line(['product_id'=>1,'ge_deco_snapshot'=>['option_key'=>'key2','manifest_revision'=>'manifest-qa']]));
fixture();unlink(GE_DECO_PRIVATE_ROOT.'/latest-pricing-partial-observation.json');
file_put_contents(GE_DECO_PRIVATE_ROOT.'/latest-pricing-observation.json',json_encode(['complete'=>true,'approved_revision'=>$policy['revision'],'observed_at'=>time(),'frame_observed_at'=>time(),'print_observed_at'=>time(),'source_digest'=>str_repeat('d',64),'options'=>['opcion-1'=>['unchanged'=>true]]]));
ok(GE_WTP_Deco_Observations::allowed('opcion-1',$policy),'Legacy full snapshot remains compatible');
ok(count(GE_WTP_Deco_Observations::times($policy))===2,'Legacy full dates remain');
echo json_encode(['passed'=>true,'checks'=>$checks,'production_requests'=>0,'orders_created'=>0,'approved_policy_unchanged'=>true]).PHP_EOL;
