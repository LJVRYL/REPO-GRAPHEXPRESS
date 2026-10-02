<?php
$site=getenv('GE_QA_WP_ROOT'); if (!$site) { throw new Exception('Set GE_QA_WP_ROOT to an isolated restore'); } require $site.'/wp-load.php';
if(DB_NAME!=='graph_restore_v2')throw new Exception('isolation');
$c=json_decode(file_get_contents(__DIR__.'/qa-context.json'),true);wp_set_current_user($c['staff']);$o=wc_get_order($c['order_id']);$before=count(GE_WTP_Documents::get_documents($c['order_id']));$r=GE_WTP_AI_Artwork::execute_action('graph.artwork.select_version',array('order_id'=>$c['order_id'],'version_id'=>$c['candidate_version_id'],'decision'=>'discard'));
function check($ok,$msg){if(!$ok)throw new Exception('FAIL '.$msg);echo 'PASS '.$msg."\n";}
check(!is_wp_error($r)&&$r['decision']==='discard','discard works');check(count(GE_WTP_Documents::get_documents($c['order_id']))===$before,'discard preserves history');$d=GE_WTP_Documents::find_version($c['order_id'],$c['candidate_version_id']);check($d['status']==='discarded','discarded version state');check(hash_file('sha256',$c['source_path'])===$c['checksum'],'original intact after discard');
wp_set_current_user(0);$visible=array_filter(GE_WTP_Documents::get_documents($c['order_id']),array('GE_WTP_Documents','customer_visible'));check(!in_array($d['id'],array_column($visible,'id'),true),'discarded candidate not customer visible');wp_set_current_user($c['staff']);
check(count(GE_WTP_Documents::get_documents($c['order_id']))===3,'second candidate preserves original and first candidate');
$request=new WP_REST_Request('POST','/ge/v1/ai-artwork/result');$request->set_body_params(array('order_id'=>$c['order_id'],'request_id'=>$c['request_id'],'task_id'=>$c['task_id'],'status'=>'failed','message'=>'fixture error'));$r=GE_WTP_AI_Artwork::receive_result($request);check(!is_wp_error($r)&&$r->get_data()['idempotent'],'late callback does not regress completed request');
echo "QA_FINAL_OK\n";
