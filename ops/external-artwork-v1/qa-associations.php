<?php
require __DIR__.'/qa/site/wp-load.php';if(DB_NAME!=='graph_external_artwork_20261002'){exit(2);}wp_set_current_user(1);add_filter('pre_wp_mail','__return_true',100);
function check($ok,$name){echo($ok?'PASS ':'FAIL ').$name."\n";if(!$ok){exit(1);}}
$ctx=json_decode(file_get_contents(__DIR__.'/qa-browser-context.json'),true);$q=GE_WTP_Commercial_Quotes::get($ctx['quote_id']);$files=GE_WTP_Commercial_Quote_Files::all($q['id']);
$link=array_values(array_filter($files,function($r)use($ctx){return$r['id']===$ctx['link_id'];}))[0];
check(is_wp_error(GE_WTP_Commercial_Quote_Files::approve_file($q['id'],$link['id'],'',2,false,1)),'external reference cannot approve bytes');
$order=GE_WTP_Commercial_Checkout::convert_staff($q['id'],array('expected_version'=>$q['version'],'confirmation_method'=>'staff','payment_state'=>'unregistered','reason'=>'QA EXTERNAL LINKS isolated'),1);if(is_wp_error($order)){exit($order->get_error_message());}
$docs=GE_WTP_Documents::get_documents($order->get_id());check(count($docs)===6,'conversion preserves all six active references');
$map=array();foreach($order->get_items()as$item){$map[$item->get_meta('_ge_quote_line_uuid',true)]=$item->get_id();}
foreach($docs as$d){check($d['order_item_id']===$map[$d['line_uuid']],'reference targets stable order line');if(GE_WTP_External_Artwork::is_link($d)){check(GE_WTP_Documents::download_url($order->get_id(),$d['id'])===$d['url'],'order opens original share URL');check(!isset(GE_WTP_Artwork_Library::order_sources($order)['document:'.$d['id']]),'external link excluded from releasable production bytes');}else{check(!empty($d['file_analysis_ref']),'local files keep analyzer reference');}}
GE_WTP_Commercial_Quote_Files::inherit($q['id'],$order);check(count(GE_WTP_Documents::get_documents($order->get_id()))===6,'inherit retry does not duplicate');
$method=new ReflectionMethod('GE_WTP_Production','render_document_item');$method->setAccessible(true);
$item_id=array_values($map)[2];$item_docs=array_values(array_filter($docs,function($d)use($item_id){return$d['order_item_id']===$item_id;}));ob_start();$method->invoke(null,$order,$item_id,'QA production',$item_docs,false);$html=ob_get_clean();check(strpos($html,'Abrir archivo externo')!==false,'production renders original external action');check(strpos($html,'Importado · origen conservado')!==false,'production shows provenance state');
foreach($order->get_items()as$item){check(!$item->get_meta('_ge_item_artwork_release_hash',true),'no automatic artwork release');}
$ctx['order_id']=$order->get_id();file_put_contents(__DIR__.'/qa-converted.json',json_encode($ctx));
echo "ASSOCIATIONS_QA_COMPLETE\n";
