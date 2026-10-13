<?php
$load=getenv('GE_WP_LOAD');if(!$load||strpos($load,'/job-flow-qa-')===false)throw new Exception('Isolated QA only');
define('DISABLE_WP_CRON',true);define('FS_METHOD','direct');$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['HTTPS']='on';require $load;
if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('Wrong QA database');
$checks=0;$mail=0;$emails=array();$delivery=0;$ct=$wpdb->prefix.'ge_newsletter_contacts';$dt=$wpdb->prefix.'ge_newsletter_deliveries';$audit=get_option(GE_WTP_Newsletter_Audiences::AUDIT,null);
add_filter('pre_wp_mail',function()use(&$mail){$mail++;return false;},PHP_INT_MAX);add_filter('pre_http_request',function(){return new WP_Error('qa','External calls blocked');},PHP_INT_MAX);
function aq($v,$m){global $checks;if(!$v)throw new Exception($m);$checks++;}
function ac($e){global $wpdb,$ct;return $wpdb->get_row($wpdb->prepare("SELECT * FROM $ct WHERE email=%s",$e));}
try{
 wp_set_current_user(1);aq(GE_WTP_Newsletter_Audiences::ensure_schema(),'Schema prepared');
 $batch=hash('sha256','isolated-audiences-'.wp_generate_uuid4());$authority='qa-owner-selection-20261012';$rows=array();
 for($i=0;$i<4;$i++){$e='audience-'.wp_generate_uuid4().'@example.invalid';$emails[]=$e;$rows[]=array('email'=>$e,'email_sha256'=>hash('sha256',$e),'selection_reasons'=>array('label:YONIO 1'));}
 wp_set_current_user(0);aq(is_wp_error(GE_WTP_Newsletter_Audiences::import_batch(array($rows[0]),'first',$authority,$batch)),'Anonymous import blocked');wp_set_current_user(1);
 aq(is_wp_error(GE_WTP_Newsletter_Audiences::import_batch(array($rows[0]),'first','',$batch)),'Missing authority blocked');
 $bad=$rows[0];$bad['email_sha256']=str_repeat('a',64);aq(is_wp_error(GE_WTP_Newsletter_Audiences::import_batch(array($bad),'first',$authority,$batch)),'Mismatched source identity blocked');
 $r=GE_WTP_Newsletter_Audiences::import_batch(array($rows[0],$rows[1],$rows[3]),'first',$authority,$batch);aq(!is_wp_error($r)&&$r['inserted']===3,'Selected cohort imported');
 $c=ac($emails[0]);aq($c->status==='subscribed'&&$c->audience==='first','Native subscriber and first cohort');aq(empty($c->consent_at),'No recipient consent fabricated');
 aq(GE_WTP_Newsletter_Audiences::owner_authorized($c),'Owner selection separately evidenced');aq(GE_WTP_Newsletter_Review::eligible($c),'Explicitly selected subscriber reviewable');
 $r=GE_WTP_Newsletter_Audiences::import_batch(array($rows[0]),'first',$authority,$batch);aq($r['unchanged']===1,'Import replay idempotent');
 $r=GE_WTP_Newsletter_Audiences::import_batch(array($rows[0],$rows[2]),'second',$authority,$batch);aq($r['overlap_preserved']===1,'First cohort never duplicated into second');
 $p=ac($emails[2]);aq($p->status==='prospect'&&$p->audience==='second','Remainder kept as prospects');aq(!GE_WTP_Newsletter_Review::eligible($p),'Prospect cannot enter send review');
 GE_WTP_Newsletter::unsubscribe_email($emails[1]);$r=GE_WTP_Newsletter_Audiences::import_batch(array($rows[1]),'first',$authority,$batch);aq($r['suppressed']===1&&ac($emails[1])->status==='unsubscribed','Import never restores voluntary unsubscribe');
 $wpdb->insert($dt,array('campaign_id'=>99999999,'contact_id'=>$c->id,'status'=>'pending'));$delivery=(int)$wpdb->insert_id;
 aq(is_wp_error(GE_WTP_Newsletter_Audiences::suppress_delivery($delivery,'temporary_bounce','Mailbox full','qa:dsn:1')),'No bounce accepted for an unsent pending delivery');
 $wpdb->update($dt,array('status'=>'sent'),array('id'=>$delivery));$r=GE_WTP_Newsletter_Audiences::suppress_delivery($delivery,'temporary_bounce','Mailbox full','qa:dsn:1');aq($r===true,'Correlated temporary bounce suspended');
 $c=ac($emails[0]);aq($c->status==='paused'&&!GE_WTP_Newsletter_Review::eligible($c),'Mailbox full excludes future marketing');aq(empty($c->unsubscribed_at),'Transport error not mislabeled voluntary unsubscribe');
 $m=json_decode($c->selection_meta,true);aq($m['suppression']['kind']==='temporary_bounce'&&$m['suppression']['auto_retry']===false,'Reason retained and retries disabled');
 aq(GE_WTP_Newsletter_Audiences::suppress_delivery($delivery,'temporary_bounce','Mailbox full','qa:dsn:1')===true,'Feedback replay idempotent');
 $before=$wpdb->get_var('SELECT COUNT(*) FROM '.GE_CRM::table('events'));
 aq(GE_WTP_Newsletter_Audiences::suppress_delivery($delivery,'temporary_bounce','Repeated report','qa:dsn:repeat')===true,'Repeated failure same classification retained');
 aq($before===$wpdb->get_var('SELECT COUNT(*) FROM '.GE_CRM::table('events')),'Repeated DSNs do not flood timeline');
 aq(GE_WTP_Newsletter_Audiences::suppress_delivery($delivery,'policy_bounce','5.7.1 provider policy','qa:dsn:policy')===true,'Provider block classified separately');
 $c=ac($emails[0]);$m=json_decode($c->selection_meta,true);aq($c->status==='paused'&&$m['suppression']['kind']==='policy_bounce','Provider block is a hold, not invalid mailbox');
 GE_WTP_Newsletter_Audiences::suppress_delivery($delivery,'review_bounce','Generic 5.0.0','qa:dsn:generic');
 aq(json_decode(ac($emails[0])->selection_meta,true)['suppression']['kind']==='policy_bounce','Generic failure cannot overwrite stronger evidence');
 aq(GE_WTP_Newsletter_Audiences::suppress_delivery($delivery,'hard_bounce','5.1.1 invalid recipient','qa:dsn:invalid')===true,'Confirmed invalid address recorded');
 aq(ac($emails[0])->status==='bounced','Only confirmed invalid address marked bounced');
 $r=GE_WTP_Newsletter_Audiences::import_batch(array($rows[0]),'first',$authority,$batch);aq($r['suppressed']===1,'Import cannot reactivate bounced contact');
 GE_WTP_Newsletter::unsubscribe_email($emails[0]);aq(GE_WTP_Newsletter_Audiences::suppress_delivery($delivery,'hard_bounce','Unknown mailbox','qa:dsn:2')===true,'Hard bounce evidence recorded');aq(ac($emails[0])->status==='unsubscribed','Voluntary unsubscribe remains distinct');
 $_GET=array('contact_status'=>'subscribed','audience'=>'first');ob_start();GE_WTP_Newsletter_Audiences::render_contacts();$html=ob_get_clean();aq(strpos($html,'Suscriptores y posibles suscriptores')!==false,'Native contact list rendered');aq(strpos($html,'Selección del titular')!==false,'Accurate authorization displayed');aq(strpos($html,$emails[2])===false,'Prospects excluded by first subscriber filter');
 aq($mail===0,'Zero SMTP calls');aq(!GE_WTP_Newsletter_Review::sending_allowed(),'Sending remains disabled');
 echo wp_json_encode(array('status'=>'passed','checks'=>$checks,'mail_calls'=>$mail,'php'=>PHP_VERSION))."\n";
}finally{if($delivery)$wpdb->delete($dt,array('id'=>$delivery));foreach($emails as $e)$wpdb->delete($ct,array('email'=>$e));if($audit===null)delete_option(GE_WTP_Newsletter_Audiences::AUDIT);else update_option(GE_WTP_Newsletter_Audiences::AUDIT,$audit,false);}
