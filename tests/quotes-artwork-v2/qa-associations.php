<?php
require '/home/leo/graph-quotes-artwork-v2-qa-20261002/site/wp-load.php';
if(DB_NAME!=='graph_quotes_artwork_v2')exit('WRONG_QA_DB');
add_filter('pre_wp_mail',function(){return true;},100);
$data=json_decode(file_get_contents(__DIR__.'/qa-upload-records.json'),true);$ctx=$data['ctx'];wp_set_current_user($ctx['staff']);
function check($ok,$name){echo($ok?'PASS ':'FAIL ').$name."\n";if(!$ok)exit(1);}
$q=GE_WTP_Commercial_Quotes::get($ctx['quote_id']);$lines=array();
foreach($ctx['uuids']as$i=>$uuid){$refs=array($data['ids'][$i]);if(0===$i){$refs[]=$data['ids'][3];$refs[]=$data['ids'][4];}$lines[]=array('line_uuid'=>$uuid,'artwork_refs'=>$refs,'source_type'=>'custom','name'=>'QA Item '.($i+1),'quantity'=>1,'unit'=>'u','unit_net'=>'100');}
$prepared=GE_WTP_Quote_Artwork_V2::prepare($q,$lines,array($ctx['legacy_id']),$ctx['session_id'],$ctx['staff']);check(!is_wp_error($prepared),'3 items multiple references plus global prepared');
GE_WTP_Quote_Artwork_V2::commit($q['id'],$prepared,$ctx['staff']);
$q=GE_WTP_Commercial_Quotes::revise($q['id'],$lines,array('expected_version'=>$q['version']),$ctx['staff']);check(!is_wp_error($q),'quote save with reference-only payload');
$files=GE_WTP_Commercial_Quote_Files::all($q['id']);check(count($files)===6,'all five item files plus legacy retained');
$counts=array_count_values(array_column($files,'quote_item_id'));check(($counts[$ctx['uuids'][0]]??0)===3&&($counts[$ctx['uuids'][1]]??0)===1&&($counts[$ctx['uuids'][2]]??0)===1,'correct per-item associations');
foreach($data['records']as$expected){$f=array_values(array_filter($files,function($f)use($expected){return$f['id']===$expected['id'];}))[0];$path=trailingslashit(GE_WTP_Documents::private_directory()).$f['stored_name'];check(hash_file('sha256',$path)===$expected['sha256'],'exact checksum '.$expected['name']);check(!empty($f['file_analysis_ref']),'version analyzer ref '.$expected['name']);}
$removed=$lines;$removed[0]['artwork_refs']=array($data['ids'][0],$data['ids'][4]);$history=GE_WTP_Quote_Artwork_V2::prepare($q,$removed,array($ctx['legacy_id']),$ctx['session_id'],$ctx['staff']);check(!is_wp_error($history),'edit can detach one file');$detached=array_values(array_filter($history,function($f)use($data){return$f['id']===$data['ids'][3];}))[0];check($detached['association_status']==='detached','detached original kept in history');
check(is_wp_error(GE_WTP_Quote_Artwork_V2::prepare($q,array(array('line_uuid'=>$ctx['uuids'][0],'artwork_refs'=>array($data['ids'][0])),array('line_uuid'=>$ctx['uuids'][1],'artwork_refs'=>array($data['ids'][0]))),array(),$ctx['session_id'],$ctx['staff'])),'ambiguous multi-item file rejected');
$order=GE_WTP_Commercial_Checkout::convert_staff($q['id'],array('expected_version'=>$q['version'],'confirmation_method'=>'staff','payment_state'=>'unregistered','reason'=>'QA isolated associations'),$ctx['staff']);
if(is_wp_error($order)){echo $order->get_error_code().' '.$order->get_error_message()."\n";exit(1);}check(true,'quote converts to order');
$docs=GE_WTP_Documents::get_documents($order->get_id());check(count($docs)===6,'order inherits all files without physical duplication');
$map=array();foreach($order->get_items()as$item){$map[$item->get_meta('_ge_quote_line_uuid',true)]=$item->get_id();}
check(count($map)===3,'order lines retain stable UUID');
foreach($docs as$d){if(!empty($d['quote_item_id'])){check($d['order_item_id']===$map[$d['quote_item_id']],'document targets correct order item');$item=$order->get_item($d['order_item_id']);check(in_array('document:'.$d['id'],(array)$item->get_meta('_ge_item_artwork_sources',true),true),'production artwork source linked');check(!$item->get_meta('_ge_item_artwork_release_hash',true),'association never auto approves production');}else{check(empty($d['order_item_id']),'legacy global not assigned arbitrarily');}}
$before=array_column($docs,'stored_name');GE_WTP_Commercial_Quote_Files::inherit($q['id'],$order);check(array_column(GE_WTP_Documents::get_documents($order->get_id()),'stored_name')===$before,'conversion retry no duplicate files');
$ctx['order_id']=$order->get_id();file_put_contents(__DIR__.'/qa-converted-context.json',json_encode($ctx));
echo "ASSOCIATIONS_QA_COMPLETE\n";
