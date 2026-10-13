<?php
$load=getenv('GE_WP_LOAD');if(!$load||strpos($load,'/job-flow-qa-')===false)throw new Exception('QA only');
define('DISABLE_WP_CRON',true);define('FS_METHOD','direct');require $load;
if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('Wrong DB');wp_set_current_user(1);
$saved=get_option('ge_crm_attention_channels');$ids=array();$checks=0;$calls=0;
add_filter('pre_wp_mail',function()use(&$calls){$calls++;throw new Exception('No QA email permitted');},PHP_INT_MAX);
function sncheck($ok,$why){global $checks;if(!$ok)throw new Exception($why);$checks++;}
update_option('ge_crm_attention_channels',array('email'=>array('accounts'=>array('qa-mailbox'),'recipients'=>array('servicio@graphex.ar'))),false);
try {
 $base=array('organization_id'=>GE_CRM::org(),'channel'=>'email','account_ref'=>'qa-mailbox','source_ref'=>'qa:server-notice','received_at'=>gmdate('c'),'from'=>'mailer-daemon@example.invalid','to'=>'servicio@graphex.ar','subject'=>'Mail delivery failed','body'=>'Delivery report','headers'=>array('content-type'=>'multipart/report; report-type=delivery-status'));
 foreach(array('Delivery report','Problema: original pide presupuesto') as $body){
  $raw=$base;$raw['body']=$body;$raw['external_id']=wp_generate_uuid4();$raw['conversation_id']=wp_generate_uuid4();
  $r=GE_CRM::attention_ingest($raw);$ids[]=(int)$r['record_id'];$id=$r['record_id'];
  sncheck(GE_CRM::attention_ingest($raw)['duplicate'],'Replay retains same message');
  $tasks=array_filter(GE_CRM::records('task',0,'',500),function($t)use($id){return ($t['thread_id']??0)==$id;});
  sncheck(count($tasks)===0,'No commercial task at ingestion');
  $processed=GE_CRM::attention_process($id);
  sncheck($processed['status']==='closed'&&$processed['attention_mailbox']==='server','Technical notice closed and retained');
  sncheck($processed['attention_classification']['category']==='delivery_failure','Root DSN classified as server delivery report');
  sncheck(empty($processed['quote_id']),'DSN quoted content never creates quote');
  GE_CRM::attention_process($id);GE_CRM::attention_stale();
  sncheck(in_array((int)$id,array_column(GE_CRM::records('thread',0,'',500,0,'server'),'id'),true),'Visible in server view');
  sncheck(!in_array((int)$id,array_column(GE_CRM::records('thread',0,'',500,0,'commercial'),'id'),true),'Excluded from commercial view');
 }
 $id=$ids[0];$legacy=GE_CRM::get($id);$legacy['attention_mailbox']='commercial';$legacy['status']='needs_review';$legacy['attention_state']='review';
 // Model an existing reception-generated task and a separate human follow-up.
 $task=GE_CRM::save('task',array('title'=>'Legacy reception task','thread_id'=>$id,'status'=>'open'));$ids[]=$task['id'];
 $wpdb->update(GE_CRM::table(),array('dedupe_key'=>'operations:attention:'.$id),array('id'=>$task['id']));
 $manual=GE_CRM::save('task',array('title'=>'Human follow-up','thread_id'=>$id,'status'=>'open'));$ids[]=$manual['id'];
 $out=GE_CRM::archive_server_notices(array($id));sncheck($out['tasks_closed']===1,'Close only generated task');
 sncheck(GE_CRM::get($manual['id'])['status']==='open','Human follow-up preserved');
 sncheck(GE_CRM::archive_server_notices(array($id))===array('threads'=>0,'tasks_closed'=>0),'Cleanup idempotent');
 sncheck($calls===0,'Zero outgoing emails');
 echo 'PASS '.$checks." server-notice checks; zero outgoing mail\n";
} finally {
 update_option('ge_crm_attention_channels',$saved,false);
 foreach($ids as $id){$wpdb->delete(GE_CRM::table('events'),array('record_id'=>$id));$wpdb->delete(GE_CRM::table(),array('id'=>$id));}
}
