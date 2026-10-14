<?php
/** Isolated QA only. No mail transport is called. */
define('DISABLE_WP_CRON',true);define('FS_METHOD','direct');$_SERVER['HTTP_HOST']='graphex.ar';require '/home/graphexpress/job-flow-qa-20261007/site/wp-load.php';wp_set_current_user(1);
if(DB_NAME!=='graph_job_flow_20261007')exit(2);
function check($ok,$label){if(!$ok)throw new RuntimeException($label);}
function deliver_fixture($suffix,$from,$truncated=false){
 $packet=['organization_id'=>'graph-express','channel'=>'email','account_ref'=>'graphex','external_id'=>hash('sha256','reception-qa-20261013-v3-'.$suffix),'conversation_id'=>hash('sha256',$suffix),'source_ref'=>'fixture:'.$suffix,'received_at'=>gmdate('Y-m-d\TH:i:s\Z'),'from'=>$from,'to'=>'servicio@graphex.ar','subject'=>'Presupuesto de dos corporeos','body'=>'Necesitamos presupuesto de dos corporeos y colocacion.','headers'=>['message-id'=>'<'.$suffix.'@example.test>'],'attachment_refs'=>[],'historical_backfill'=>false,'body_truncated'=>$truncated,'reply_ambiguous'=>false];
 $path='/home/graphexpress/job-flow-qa-20261007/mail-reception-fixture-'.$suffix.'.json';file_put_contents($path,wp_json_encode(['packet'=>$packet,'attachments'=>[['filename'=>'pending-fixture.pdf','content_type'=>'application/pdf','stored'=>false]]]));chmod($path,0600);
 if(getenv('GE_MAIL_QA_PREPARE'))return [];
 static $calls=[];$n=$calls[$suffix]??0;$calls[$suffix]=$n+1;
 $proof=json_decode(file_get_contents('/home/graphexpress/job-flow-qa-20261007/mail-reception-qa-results.json'),true);
 $r=$proof[$suffix.($n?'-duplicate':'')];check(!empty($r['committed']),'Fixture not committed');return $r;
}
if(getenv('GE_MAIL_QA_PREPARE')){
 $backup='/home/graphexpress/job-flow-qa-20261007/mail-reception-qa-channels-before.json';if(!file_exists($backup)){file_put_contents($backup,wp_json_encode(get_option('ge_crm_attention_channels')));chmod($backup,0600);}
 $cfg=get_option('ge_crm_attention_channels',[]);$cfg['email']['accounts']=['graphex'];$cfg['email']['recipients']=['servicio@graphex.ar'];$cfg['email']['senders']=['servicio@graphex.ar'];update_option('ge_crm_attention_channels',$cfg,false);
 deliver_fixture('unmatched','reception-qa@example.test');deliver_fixture('known',get_userdata(37)->user_email);deliver_fixture('partial','reception-partial@example.test',true);echo '{"prepared":true}';exit;}
$new=deliver_fixture('unmatched','reception-qa@example.test');$r=GE_CRM::get($new['record_id']);check($r['customer_id']===0,'False customer match');check($r['attention_ack']['state']==='suppressed','External reply prepared');check(!empty($r['attention_event']['external_reply_suppressed'])&&empty($r['attention_event']['reply_ambiguous']),'Suppression confused with reply ambiguity');check(!empty($r['lead_id']),'Missing lead');
$duplicate=deliver_fixture('unmatched','reception-qa@example.test');check($new['record_id']===$duplicate['record_id']&&!empty($duplicate['duplicate']),'Duplicate record');
$r=GE_CRM::get($new['record_id']);$r=GE_CRM::save('thread',$r,$r['id']);check(count($r['mail_private_attachments'])===1,'Attachment metadata lost on edit');
$task=GE_CRM::get($new['task_id']);check($task['owner_id']===1&&$task['thread_id']===$new['record_id']&&$task['status']==='open','Task not linked');
check(get_post_type($new['alert_id'])==='ge_internal_alert','Missing native internal alert');$url=GE_WTP_Internal_Alerts::url(get_post_meta($new['alert_id'],'_ge_alert',true));check(strpos($url,'record_id='.$new['record_id'])!==false,'Wrong alert link');
$u=get_userdata(37);check((bool)$u,'Missing QA customer');$known=deliver_fixture('known',$u->user_email);$r=GE_CRM::get($known['record_id']);check($r['customer_id']===37,'Existing customer not matched');check(empty($r['lead_id']),'Duplicate lead for customer');
$partial=deliver_fixture('partial','reception-partial@example.test',true);check(GE_CRM::get($partial['record_id'])['attention_ack']['state']==='suppressed','Truncated source autoresponse');
check(!GE_CRM_Mail_Reception::health_state(['checked_at'=>gmdate('c',time()-360),'receiver_running'=>true])['ok'],'Stale marked healthy');check(!GE_CRM_Mail_Reception::health_state(['checked_at'=>gmdate('c'),'receiver_running'=>true,'pending_events'=>1])['ok'],'Backlog marked healthy');
$before_cuit=get_user_meta(37,'_ge_cuit',true);update_user_meta(37,'_ge_cuit','30700000001');$match=GE_CRM::match(['cuit'=>'30700000001']);check(in_array(37,array_column($match,'id'),true),'Current customer CUIT field ignored');update_user_meta(37,'_ge_cuit',$before_cuit);
$policy=get_option(GE_WTP_Newsletter_Engine::POLICY);$channels=get_option('ge_crm_attention_channels');$feedback=get_option('ge_newsletter_feedback_heartbeat');
try{
 update_option(GE_WTP_Newsletter_Engine::POLICY,['enabled'=>true,'provider_evidence_ref'=>'qa','reception_evidence_ref'=>'qa','verified_by'=>1,'expires_at'=>time()+300,'hourly_limit'=>1,'daily_limit'=>1]);update_option('ge_newsletter_feedback_heartbeat',['server_verified'=>true,'at'=>gmdate('c')]);
 $ch=$channels;$ch['email']['consumer_health']=['checked_at'=>gmdate('c',time()-360),'receiver_running'=>true];update_option('ge_crm_attention_channels',$ch);check(!GE_WTP_Newsletter_Engine::enabled(),'Feedback hides stale CRM');
 $ch['email']['consumer_health']=['checked_at'=>gmdate('c'),'receiver_running'=>true,'pending_events'=>1];update_option('ge_crm_attention_channels',$ch);check(!GE_WTP_Newsletter_Engine::enabled(),'Feedback hides backlog');
 $ch['email']['consumer_health']['pending_events']=0;update_option('ge_crm_attention_channels',$ch);check(GE_WTP_Newsletter_Engine::enabled(),'Healthy CRM disabled');
}finally{update_option(GE_WTP_Newsletter_Engine::POLICY,$policy);update_option('ge_crm_attention_channels',json_decode(file_get_contents('/home/graphexpress/job-flow-qa-20261007/mail-reception-qa-channels-before.json'),true));update_option('ge_newsletter_feedback_heartbeat',$feedback);}
echo wp_json_encode(['passed'=>true,'checks'=>14,'external_sent'=>0,'unmatched'=>$new,'known'=>$known,'partial'=>$partial]);
