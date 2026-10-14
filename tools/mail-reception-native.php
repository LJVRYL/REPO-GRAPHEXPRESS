<?php
/** Private local receiver: never dispatches customer mail. */
define('DISABLE_WP_CRON', true);define('FS_METHOD','direct');
$_SERVER['HTTP_HOST']='graphex.ar'; $_SERVER['HTTPS']='on';
$site=getenv('GE_MAIL_SITE')?:'/home/graphexpress/public_html';
if(!in_array($site,array('/home/graphexpress/public_html','/home/graphexpress/job-flow-qa-20261007/site'),true))exit(2);
require $site.'/wp-load.php'; wp_set_current_user(1);
if (GE_CRM::org() !== 'graph-express' || ($site==='/home/graphexpress/public_html' && DB_NAME !== 'graphexpress_wp') || ($site!=='/home/graphexpress/public_html' && DB_NAME !== 'graph_job_flow_20261007')) exit(2);
global $wpdb;
function reception_metadata($id,$patch){
 return GE_CRM::locked(function()use($id,$patch){global $wpdb;$old=GE_CRM::get($id,'thread');
  $row=$wpdb->get_row($wpdb->prepare('SELECT payload FROM '.GE_CRM::table().' WHERE id=%d AND organization_id=%s',$id,GE_CRM::org()),ARRAY_A);$data=json_decode($row['payload'],true);
  foreach($patch as $k=>$v){if(!in_array($k,['mail_private_attachments','mail_reception_runtime','mail_reception_complete','mail_reception_alert_id','attention_ack'],true))throw new RuntimeException('Invalid metadata field');if($k==='attention_ack' && !in_array($old['attention_ack']['state']??'',array('','prepared','suppressed'),true))continue;$data[$k]=$v;}
  $changed=$wpdb->update(GE_CRM::table(),['payload'=>wp_json_encode($data),'revision'=>$old['revision']+1,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id,'organization_id'=>GE_CRM::org(),'revision'=>$old['revision']]);if($changed!==1)throw new RuntimeException('Concurrent metadata edit',409);
  GE_CRM::event($id,$old['customer_id'],'mail_reception_metadata_saved',['fields'=>array_keys($patch),'revision'=>$old['revision']+1]);return GE_CRM::get($id);
 });
}
$input_path=getenv('GE_MAIL_INPUT');
if(strpos((string)$input_path,'/home/graphexpress/mail-reception/state/')!==0 && !($site!=='/home/graphexpress/public_html' && strpos((string)$input_path,'/home/graphexpress/job-flow-qa-20261007/')===0))exit(2);
$input=json_decode(file_get_contents($input_path),true);
try {
 if (($input['operation']??'')==='health') {
  $channels=get_option('ge_crm_attention_channels',array());
  $channels['email']['consumer_health']=array('checked_at'=>gmdate('c'),'receiver_running'=>!empty($input['receiver_running']),'pending_events'=>absint($input['pending_events']??0),'oldest_pending_age_seconds'=>absint($input['oldest_pending_age_seconds']??0),'poll_failed'=>!empty($input['poll_failed']),'runtime'=>'VPS','error_code'=>sanitize_key($input['error_code']??''));
  if (empty($input['poll_failed'])) $channels['email']['transport_verified_at']=gmdate('c');
  update_option('ge_crm_attention_channels',$channels,false);
  echo wp_json_encode(array('committed'=>true)); exit;
 }
 GE_CRM::admin();
 $packet=$input['packet'];
 // Suppress all automatic external replies on this receiver, including cron retries.
 $packet['external_reply_suppressed']=true;
 $key='message:'.hash('sha256','email:graphex:'.$packet['external_id']);
 $existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.GE_CRM::table().' WHERE organization_id=%s AND dedupe_key=%s',GE_CRM::org(),$key));
 if ($existing && !empty(GE_CRM::get($existing)['mail_reception_complete'])) {
  $record=GE_CRM::get($existing);$task=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.GE_CRM::table().' WHERE organization_id=%s AND dedupe_key=%s',GE_CRM::org(),'operations:attention:'.$existing));
  echo wp_json_encode(array('committed'=>true,'duplicate'=>true,'record_id'=>(int)$existing,'task_id'=>(int)$task,'lead_id'=>$record['lead_id'],'customer_id'=>$record['customer_id'],'alert_id'=>$record['mail_reception_alert_id']??0,'classification'=>$record['attention_classification']['category']??'uncertain','external_sent'=>0,'attachments'=>count($record['mail_private_attachments']??array())));exit;
 }
 $receipt=$existing?array('committed'=>true,'duplicate'=>true,'record_id'=>(int)$existing):GE_CRM::attention_ingest($packet);
 $id=$receipt['record_id'];
 $record=GE_CRM::attention_process($id);
 $attachments=$input['attachments']??array();
 reception_metadata($id,['mail_private_attachments'=>$attachments,'mail_reception_runtime'=>'VPS','attention_ack'=>['state'=>'suppressed','reason'=>'External replies require separate authorization']]);
 $record=GE_CRM::get($id);
 $task=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.GE_CRM::table().' WHERE organization_id=%s AND dedupe_key=%s',GE_CRM::org(),'operations:attention:'.$id));
 $alert=0;$lead=0;
 if (!GE_CRM::server_notice($record) && $record['attention_state']!=='ignored' && $record['status']!=='closed') {
  $commercial=in_array($record['attention_classification']['category']??'',array('quote','special'),true);
  if ($commercial && !$record['customer_id']) {
   $matches=GE_CRM::records('lead',0,$record['email'],20);
   foreach($matches as $m)if(strtolower($m['email'])===strtolower($record['email'])){$lead=$m['id'];break;}
   if(!$lead){$l=GE_CRM::save('lead',array('title'=>$record['title'],'email'=>$record['email'],'source'=>'email','owner_id'=>1,'notes'=>'Consulta recibida; revisar identidad, receptor de facturación y adjuntos antes de presupuestar.','next_action'=>'Preparar presupuesto'));$lead=$l['id'];}
  }
  if($task && GE_CRM::get($task)['status']==='open'){$old=GE_CRM::get($task);$t=$old;$t['owner_id']=1;if($lead)$t['lead_id']=$lead;
   if($commercial)$t['title']='Preparar presupuesto: '.$record['title'];
   $t['notes']='Consulta recibida en el CRM #'.$id.'. Revisar adjuntos y vinculación del cliente. No se envió respuesta externa.';
   GE_CRM::save('task',$t,$old['id']);
  }
  $alert_key='ge_alert_once_'.hash('sha256','crm_mail_received:'.$id);
  if(get_option($alert_key)==='pending')delete_option($alert_key);
  $alert=GE_WTP_Internal_Alerts::create('crm_mail_received','Nueva consulta por correo · '.$record['title'],$id,$record['customer_id'],'warning');
  if(is_wp_error($alert)||!is_numeric($alert)||get_post_type((int)$alert)!==GE_WTP_Internal_Alerts::TYPE)throw new RuntimeException('Internal alert not persisted');
 }
 if($lead){$old=GE_CRM::get($id);$r=$old;$r['lead_id']=$lead;GE_CRM::save('thread',$r,$old['id']);}
 reception_metadata($id,['mail_reception_complete'=>true,'mail_reception_alert_id'=>(int)$alert]);
 $saved=GE_CRM::get($id);
 if($saved['attention_event']['external_id']!==$packet['external_id'])throw new RuntimeException('Persistence identity mismatch');
 echo wp_json_encode(array_merge($receipt,array('task_id'=>(int)$task,'lead_id'=>(int)$lead,'alert_id'=>(int)$alert,'classification'=>$saved['attention_classification']['category'],'customer_id'=>$saved['customer_id'],'external_sent'=>0,'attachments'=>count($attachments))));
} catch(Throwable $e){$error=array('committed'=>false,'error'=>'native_receiver_failed','code'=>(int)$e->getCode());if($site!=='/home/graphexpress/public_html')$error['qa_detail']=$e->getMessage();echo wp_json_encode($error);exit(1);}
