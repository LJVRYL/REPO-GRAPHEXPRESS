<?php
// Private staff maintenance; no SMTP or mailbox deletion operation.
define('DISABLE_WP_CRON',true);$_SERVER['HTTP_HOST']='graphex.ar';require '/home/graphexpress/public_html/wp-load.php';wp_set_current_user(1);
if(DB_NAME!=='graphexpress_wp')exit(2);GE_CRM::admin();
$release='/home/graphexpress/releases/newsletter-hygiene-20261013-0310';
if(hash_file('sha256',$release.'/native-before-full.json')!=='b0acbea0519ca8f1c270533852794f5c5f495c63c85ca3c89b248485243ad137')exit(3);
$dt=$wpdb->prefix.'ge_newsletter_deliveries';$ct=$wpdb->prefix.'ge_newsletter_contacts';
if((int)$wpdb->get_var("SELECT COUNT(*) FROM $dt WHERE campaign_id=4 AND status IN ('pending','sending','unknown')")!==0)exit(4);
$out=array('at'=>gmdate('c'),'applied'=>false);
if(getenv('GE_HYGIENE_APPLY')==='1'){
 $contexts=json_decode(file_get_contents($release.'/crm-contexts.json'),true);
 $out['archive']=GE_CRM::archive_server_notices(null,$contexts);
 $events=json_decode(file_get_contents($release.'/verified-feedback.json'),true);
 $out['feedback']=GE_WTP_Newsletter_Engine::reconcile_mta($events);
 $out['manual_review_holds']=0;
 foreach($events as $e)if($e['kind']==='review_bounce'&&preg_match('/^maildir_review:[a-f0-9]{64}$/D',$e['evidence_ref'])){
  $d=$wpdb->get_row($wpdb->prepare("SELECT d.message_id,c.email FROM $dt d JOIN $ct c ON c.id=d.contact_id WHERE d.id=%d AND d.campaign_id=4",$e['delivery_id']));
  if(!$d||!hash_equals($d->message_id,$e['message_id'])||!hash_equals(hash('sha256',strtolower($d->email)),$e['recipient_sha256']))continue;
  $r=GE_WTP_Newsletter_Audiences::suppress_delivery($e['delivery_id'],'review_bounce','Revisión autorizada: aviso conservado en casilla local; origen pendiente de verificar.',$e['evidence_ref']);if(!is_wp_error($r))$out['manual_review_holds']++;
 }
 GE_WTP_Newsletter_Engine::refresh(4);
 $out['applied']=true;
}
$out['campaign']=$wpdb->get_row("SELECT status,total_count,sent_count,failed_count FROM {$wpdb->prefix}ge_newsletter_campaigns WHERE id=4",ARRAY_A);
$out['ledger']=$wpdb->get_results("SELECT status,COUNT(*) total FROM $dt WHERE campaign_id=4 GROUP BY status",ARRAY_A);
$out['accepted']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $dt WHERE campaign_id=4 AND sent_at IS NOT NULL");
$out['contact_statuses']=$wpdb->get_results("SELECT audience,status,COUNT(*) total FROM $ct GROUP BY audience,status",ARRAY_A);
$out['suppression_kinds']=array();foreach($wpdb->get_col("SELECT selection_meta FROM $ct WHERE audience='first'") as $json){$m=json_decode($json,true);if(isset($m['suppression']['kind'])){$kind=$m['suppression']['kind'];$out['suppression_kinds'][$kind]=($out['suppression_kinds'][$kind]??0)+1;}}
$out['server_threads']=count(GE_CRM::records('thread',0,'',500,0,'server'));
$out['commercial_threads']=count(GE_CRM::records('thread',0,'',500,0,'commercial'));
$out['server_open_tasks']=0;$out['task_keys']=array();$server=array_column(GE_CRM::records('thread',0,'',500,0,'server'),'id');
foreach(GE_CRM::records('task',0,'',500) as $t){if(in_array((int)($t['thread_id']??0),$server,true)&&$t['status']==='open'){$out['server_open_tasks']++;$key=preg_replace('/[0-9]+/','#',$t['dedupe_key']??'');$out['task_keys'][$key]=($out['task_keys'][$key]??0)+1;}}
$out['max_attempts']=(int)$wpdb->get_var("SELECT MAX(attempts) FROM $dt WHERE campaign_id=4");
$out['second_audience_deliveries']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $dt d JOIN $ct c ON c.id=d.contact_id WHERE d.campaign_id=4 AND c.audience='second'");
echo wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
