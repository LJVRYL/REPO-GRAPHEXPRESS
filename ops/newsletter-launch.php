<?php
// Private operator entry point; never publish this file into WordPress.
define('DISABLE_WP_CRON',true);$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['HTTPS']='on';require '/home/graphexpress/public_html/wp-load.php';wp_set_current_user(1);
if(DB_NAME!=='graphexpress_wp'||home_url('/')!=='https://graphex.ar/')exit(2);
$input=json_decode(file_get_contents('php://stdin'),true);if(!is_array($input))exit(2);$op=$input['operation']??'';$key='ge_newsletter_launch_20261012_first';$id=(int)get_option($key,0);
if($op==='prepare'){
    GE_WTP_Newsletter_Engine::ensure_schema();$snapshot=GE_WTP_Campaign_Actions::snapshot('first');
    if(is_wp_error($snapshot)||$snapshot['total']!==2103||$snapshot['eligible']!==2103)throw new RuntimeException('First audience changed: review exclusions and membership.');
    if(!$id){$id=GE_WTP_Newsletter_Review::create_draft($input['notice']??'',$input['notice_source']??'');if(is_wp_error($id))throw new RuntimeException('Draft creation failed');update_option($key,$id,false);}
    $review=GE_WTP_Campaign_Actions::select_audience($id,'first');if(is_wp_error($review))throw new RuntimeException('Whole audience review failed');
    update_option('ge_newsletter_reply_cursor',(int)$wpdb->get_var('SELECT MAX(id) FROM '.GE_CRM::table()),false);
    $out=array('campaign_id'=>$id,'audience'=>$review['audience_key'],'total'=>$review['total'],'eligible'=>$review['eligible'],'batch_sha256'=>$review['batch_sha256'],'content_sha256'=>$review['content_sha256'],'engine_enabled'=>GE_WTP_Newsletter_Engine::enabled());
}elseif($op==='test'){
    $r=GE_WTP_Newsletter_Engine::test($id,$input['review_email']??'');if(is_wp_error($r))throw new RuntimeException($r->get_error_message());$out=array('campaign_id'=>$id,'test'=>$r);
}elseif($op==='launch'){
    $policy=$input['policy']??array();if(($policy['audience_key']??'')!=='first'||($policy['hourly_limit']??0)>400||($policy['daily_limit']??0)>2500||empty($policy['authority_ref']))throw new RuntimeException('Policy outside authorized scope');
    $policy['authorized_campaign_ids']=array($id);$policy['verified_by']=get_current_user_id();update_option(GE_WTP_Newsletter_Engine::POLICY,$policy,false);
    $r=GE_WTP_Newsletter_Engine::queue($id);if(is_wp_error($r))throw new RuntimeException($r->get_error_message());$out=array('campaign_id'=>$id,'queued'=>$r,'first_execution'=>GE_WTP_Newsletter_Engine::process(1));
}elseif($op==='policy_end_of_day'){
    $p=get_option(GE_WTP_Newsletter_Engine::POLICY,array());if(($p['audience_key']??'')!=='first'||($p['authorized_campaign_ids']??array())!==array($id))exit(2);$p['expires_at']=strtotime('2026-10-13T02:59:59Z');update_option(GE_WTP_Newsletter_Engine::POLICY,$p,false);$out=array('campaign_id'=>$id,'expires_at'=>gmdate('c',$p['expires_at']));
}elseif($op==='status'){
    $c=GE_WTP_Newsletter_Review::campaign($id);$rows=$wpdb->get_results($wpdb->prepare('SELECT status,COUNT(*) total FROM '.$wpdb->prefix.'ge_newsletter_deliveries WHERE campaign_id=%d GROUP BY status',$id),ARRAY_A);
    $out=array('campaign_id'=>$id,'status'=>$c?$c->status:null,'total'=>$c?(int)$c->total_count:0,'accepted'=>$c?(int)$c->sent_count:0,'deliveries'=>$rows,'engine_enabled'=>GE_WTP_Newsletter_Engine::enabled(),'heartbeat'=>get_option('ge_newsletter_engine_heartbeat',array()),'feedback'=>get_option('ge_newsletter_feedback_heartbeat',array()),'reception'=>get_option('ge_crm_attention_channels',array())['email']['consumer_health']??array());
}else exit(2);
$out['test']=GE_WTP_Newsletter_Review::metadata($id)['test']??array();
echo wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
