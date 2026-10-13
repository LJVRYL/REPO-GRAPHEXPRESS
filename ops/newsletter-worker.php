<?php
// Private CLI only. This file must remain outside the document root.
$load=getenv('GE_WP_LOAD');if(!in_array($load,array('/home/graphexpress/public_html/wp-load.php','/home/graphexpress/job-flow-qa-20261007/site/wp-load.php'),true))exit(2);
define('DISABLE_WP_CRON',true);$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['HTTPS']='on';require $load;wp_set_current_user(1);
$input=json_decode(file_get_contents('php://stdin'),true);$input=is_array($input)?$input:array();$op=$input['operation']??'';
if($op==='inventory'){
    $rows=$wpdb->get_results('SELECT d.id,d.campaign_id,d.message_id,c.email FROM '.$wpdb->prefix.'ge_newsletter_deliveries d JOIN '.$wpdb->prefix."ge_newsletter_contacts c ON c.id=d.contact_id WHERE d.message_id<>'' AND d.status IN ('sent','sending','unknown','bounced')",ARRAY_A);
    foreach($rows as &$r){$r['recipient_sha256']=hash('sha256',strtolower($r['email']));unset($r['email']);}unset($r);
    $out=array('deliveries'=>$rows,'policy_expires_at'=>get_option(GE_WTP_Newsletter_Engine::POLICY,array())['expires_at']??0);
}elseif($op==='reconcile'){$r=GE_WTP_Newsletter_Engine::reconcile_mta($input['events']??array());$out=array('matched'=>is_wp_error($r)?0:$r,'replies'=>GE_WTP_Newsletter_Engine::correlate_replies(),'unsubscribes'=>GE_WTP_Newsletter_Engine::record_unsubscribes(),'failed'=>is_wp_error($r));}
elseif($op==='process'){$out=GE_WTP_Newsletter_Engine::process(1);}
else exit(2);
if($op==='reconcile'){$h=(array)get_option('ge_newsletter_feedback_heartbeat',array());$h['server_verified']=DB_NAME==='graphexpress_wp'&&is_readable('/var/spool/exim/log/mainlog')&&is_dir('/home/graphex/mail/graphexpress.com.ar/servicio/Maildir/new')&&is_dir('/home/graphex/mail/graphexpress.com.ar/servicio/Maildir/cur');update_option('ge_newsletter_feedback_heartbeat',$h,false);}
echo wp_json_encode($out,JSON_UNESCAPED_UNICODE)."\n";
