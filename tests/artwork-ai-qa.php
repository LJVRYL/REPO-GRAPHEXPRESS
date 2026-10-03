<?php
$site=getenv('GE_QA_WP_ROOT'); if (!$site) { throw new Exception('Set GE_QA_WP_ROOT to an isolated restore'); }
define('GE_AI_GRUPO_URL','https://graphex.ar/qa-artwork-never-called');
define('GE_AI_GRUPO_SHARED_SECRET',str_repeat('q',40));
define('GE_AI_GRUPO_RUNTIME_CAPABILITIES',json_encode(array('resize'=>array('runtime_available'=>true,'executor_available'=>true,'checked_at'=>time(),'skill'=>'qa-fixture-executor'))));
require $site.'/wp-load.php';
if (DB_NAME !== 'graph_restore_v2' || strpos(DB_HOST,'graph-restore-test-20261001T204908Z/run/mysql.sock')===false) { throw new Exception('Not isolated'); }
add_filter('pre_wp_mail',function(){return true;},100);
add_filter('pre_http_request',function($pre,$args,$url){if(strpos($url,'qa-artwork-never-called')!==false)return array('headers'=>array(),'body'=>json_encode(array('task_id'=>'TASK-20261001-QAFIX123')),'response'=>array('code'=>201,'message'=>'Created'),'cookies'=>array());return new WP_Error('qa_external','External network blocked');},10,3);
$staffs=get_users(array('role'=>'administrator','number'=>1));wp_set_current_user($staffs[0]->ID);
$n=0; function check($ok,$label){global $n;if(!$ok)throw new Exception('FAIL '.$label);echo 'PASS '.(++$n).' '.$label."\n";}
$order=wc_create_order();$item=new WC_Order_Item_Product();$item->set_name('QA artwork AI – fixture');$item->set_quantity(1);$item->set_total(0);$order->add_item($item);$order->save();$item_id=$item->get_id();
$id=wp_generate_uuid4();$name=$id.'.png';GE_WTP_Documents::ensure_private_directory();$path=GE_WTP_Documents::private_directory().'/'.$name;
file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR9sAAAAASUVORK5CYII='));
$hash=hash_file('sha256',$path);
$doc=array('id'=>$id,'name'=>'fixture-original.png','stored_name'=>$name,'mime'=>'image/png','category'=>'arte','size'=>filesize($path),'order_item_id'=>$item_id,'artwork_side'=>'front','analysis'=>GE_WTP_Documents::analyze_file($path,'image/png'));
$order->update_meta_data(GE_WTP_Documents::META_KEY,array($doc));$order->save();
$input=array('order_id'=>$order->get_id(),'version_id'=>$id,'instruction'=>'Centrar el diseño dejando el original intacto','kind'=>'design_edit','request_id'=>wp_generate_uuid4());
$r=GE_WTP_AI_Artwork::execute_action('graph.artwork.ai_improve.request',$input);check(!is_wp_error($r),'request succeeds while runtime unavailable');check($r['request']['status']==='blocked','truthful unavailable state');check($r['request']['task_pack']['output_contract']==='new_candidate_version','output contract');check($r['request']['task_pack']['checksum_sha256']===$hash,'exact source checksum');check($r['request']['task_pack']['artifact_ref']==='graph://orders/'.$order->get_id().'/artwork/'.$id,'scoped artifact ref');
check(count(GE_WTP_Documents::get_documents($order->get_id()))===1,'no fake output');check(hash_file('sha256',$path)===$hash,'original intact');
$repeat=GE_WTP_AI_Artwork::execute_action('graph.artwork.ai_improve.request',$input);check($repeat['request']['request_id']===$input['request_id'],'request idempotence');$bad=$input;$bad['instruction']='Otra instrucción distinta';check(is_wp_error(GE_WTP_AI_Artwork::execute_action('graph.artwork.ai_improve.request',$bad)),'idempotence conflict rejected');
$bad=$input;$bad['version_id']=wp_generate_uuid4();$bad['request_id']=wp_generate_uuid4();check(is_wp_error(GE_WTP_AI_Artwork::execute_action('graph.artwork.ai_improve.request',$bad)),'cross version rejected');
$input['request_id']=wp_generate_uuid4();$input['kind']='resize';$input['instruction']='Ajustar tamaño a 2 x 2 píxeles para QA';
$queued=GE_WTP_AI_Artwork::execute_action('graph.artwork.ai_improve.request',$input);check($queued['request']['status']==='queued','configured fixture transport confirms task');check($queued['request']['task_id']==='TASK-20261001-QAFIX123','task id validated');
ob_start();GE_WTP_AI_Artwork::render_button($order,$doc);$html=ob_get_clean();check(strpos($html,'Mejorar con IA')!==false,'button rendered');check(strpos($html,'data-ge-ai')!==false,'modal payload rendered');
wp_set_current_user(0);check(is_wp_error(GE_WTP_AI_Artwork::execute_action('graph.artwork.ai_improve.status',array('order_id'=>$order->get_id(),'request_id'=>$input['request_id']))),'unauthorized action denied');wp_set_current_user($staffs[0]->ID);
check(GE_WTP_Documents::version_id($doc)===$id,'historical version fallback');
file_put_contents(__DIR__.'/qa-context.json',json_encode(array('order_id'=>$order->get_id(),'staff'=>$staffs[0]->ID,'item_id'=>$item_id,'version_id'=>$id,'request_id'=>$input['request_id'],'task_id'=>'TASK-20261001-QAFIX123','checksum'=>$hash,'source_path'=>$path)));
echo "QA_FIXTURE_READY\n";
