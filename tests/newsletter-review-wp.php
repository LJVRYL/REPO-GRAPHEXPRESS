<?php
$load = getenv('GE_WP_LOAD');
if (!$load || strpos($load, '/job-flow-qa-') === false) { throw new Exception('Isolated QA only'); }
define('DISABLE_WP_CRON', true); define('FS_METHOD', 'direct');
$_SERVER['HTTP_HOST'] = 'graphex.ar'; $_SERVER['HTTPS'] = 'on'; require $load;
if (DB_NAME !== 'graph_job_flow_20261007') { throw new Exception('Wrong QA database'); }
$mail = 0; $checks = 0; $ids = array(); $drafts = array();
add_filter('pre_wp_mail', function() use (&$mail) { ++$mail; return false; }, PHP_INT_MAX);
add_filter('pre_http_request', function() { return new WP_Error('qa', 'External requests disabled'); }, PHP_INT_MAX);
add_filter('wp_die_handler', function() { return function($message) { throw new RuntimeException((string)$message); }; }, PHP_INT_MAX);
function nq($ok, $why) { global $checks; if (!$ok) { throw new Exception($why); } ++$checks; }
$ct = $wpdb->prefix.'ge_newsletter_contacts'; $dt = $wpdb->prefix.'ge_newsletter_deliveries'; $mt = $wpdb->prefix.'ge_newsletter_campaigns';
try {
    wp_set_current_user(0); nq(!GE_WTP_Newsletter_Review::allowed(), 'Anonymous review denied');
    nq(is_wp_error(GE_WTP_Newsletter_Review::create_draft()), 'Anonymous draft denied');
    wp_set_current_user(1); nq(GE_WTP_Newsletter_Review::allowed(), 'Native staff permitted');
    nq(!GE_WTP_Newsletter_Review::sending_allowed(), 'No send authority');
    $draft = GE_WTP_Newsletter_Review::create_draft(); nq(is_int($draft), 'Native draft saved'); $drafts[] = $draft;
    $campaign = GE_WTP_Newsletter_Review::campaign($draft); $html = GE_WTP_Newsletter_Review::render_html($campaign);
    nq(strpos($html, 'GRAPH EXPRESS') === false, 'No old wrapper');
    nq(strpos($html, '<!doctype html>') === 0, 'Full original template');
    nq(strpos($html, '{{') === false, 'Preview placeholders resolved');
    nq(strpos($html, 'falta revisar el aviso legal') !== false, 'Missing legal notice visible');
    nq(strpos($html, '#baja-personalizada-al-enviar') !== false, 'Preview has no live unsubscribe token');
    $per = GE_WTP_Newsletter_Review::render_html($campaign, 'https://graphex.ar/preferencias-email/?token='.str_repeat('a',64));
    nq(strpos($per, '?token='.str_repeat('a',64)) !== false, 'Personalized native unsubscribe');
    nq(!GE_WTP_Newsletter_Review::review_valid($campaign), 'Unreviewed draft invalid');
    foreach (array('subscribed','unsubscribed','bounced','subscribed') as $index=>$status) {
        $wpdb->insert($ct,array('email'=>'newsletter-'.wp_generate_uuid4().'@example.invalid','first_name'=>'Synthetic','last_name'=>'QA', 'status'=>$status,
            'source'=>'isolated-qa','token'=>hash('sha256',wp_generate_uuid4()),'consent_at'=>$index===3?null:current_time('mysql'),
            'unsubscribed_at'=>$index===1?current_time('mysql'):null,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
        $ids[]=(int)$wpdb->insert_id;
    }
    foreach ($ids as $index=>$id) { $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM $ct WHERE id=%d",$id)); nq((bool)GE_WTP_Newsletter_Review::eligible($c)===($index===0), 'Consent/status eligibility '.$index); }
    nq(is_wp_error(GE_WTP_Newsletter_Review::review_batch($draft,array())), 'Empty explicit batch rejected');
    nq(is_wp_error(GE_WTP_Newsletter_Review::review_batch($draft,array(array(1)))), 'Nested selection rejected');
    nq(is_wp_error(GE_WTP_Newsletter_Review::review_batch($draft,array(-$ids[0]))), 'Negative selection rejected');
    nq(is_wp_error(GE_WTP_Newsletter_Review::create_draft(array())), 'Malformed legal notice rejected');
    $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $lock='ge_newsletter_review_'.$draft; $other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$lock));
    $busy=GE_WTP_Newsletter_Review::review_batch($draft,array($ids[0]));
    nq(is_wp_error($busy) && $busy->get_error_code()==='busy','Concurrent review fails closed');
    $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$lock)); $other->close();
    foreach (array($ids[1],$ids[2],$ids[3],999999999) as $bad) { nq(is_wp_error(GE_WTP_Newsletter_Review::review_batch($draft,array($ids[0],$bad))), 'Entire mixed batch rejected'); }
    $review=GE_WTP_Newsletter_Review::review_batch($draft,array($ids[0],$ids[0])); nq(!is_wp_error($review),'Exact batch stored');
    nq(count($review['contacts'])===1,'Recipient deduplication');
    nq($review['send_authority']===false,'Review cannot grant dispatch');
    nq(GE_WTP_Newsletter_Review::review_valid($campaign),'Current review valid');
    nq(GE_WTP_Newsletter_Review::review_batch($draft,array($ids[0]))===$review,'Replay returns same immutable review');
    $meta=GE_WTP_Newsletter_Review::metadata($draft); nq(strpos(wp_json_encode($meta),'@example.invalid')===false,'Review contains no copied email');
    $wpdb->update($ct,array('status'=>'unsubscribed','unsubscribed_at'=>current_time('mysql')),array('id'=>$ids[0]));
    nq(!GE_WTP_Newsletter_Review::review_valid($campaign),'Later unsubscribe invalidates review');
    $wpdb->update($ct,array('status'=>'subscribed','unsubscribed_at'=>null),array('id'=>$ids[0]));
    $wpdb->update($mt,array('subject'=>'Changed after review'),array('id'=>$draft));
    nq(!GE_WTP_Newsletter_Review::review_valid(GE_WTP_Newsletter_Review::campaign($draft)),'Subject change invalidates review');
    $wpdb->update($mt,array('subject'=>$campaign->subject,'content'=>$campaign->content.' '),array('id'=>$draft));
    nq(GE_WTP_Newsletter_Review::render_html(GE_WTP_Newsletter_Review::campaign($draft))==='','Changed HTML cannot masquerade as template version');
    $wpdb->update($mt,array('content'=>$campaign->content,'status'=>'queued'),array('id'=>$draft));
    $wpdb->insert($dt,array('campaign_id'=>$draft,'contact_id'=>$ids[0],'status'=>'pending'));
    GE_WTP_Newsletter::process_queue(); GE_WTP_Newsletter::process_queue();
    nq($wpdb->get_var($wpdb->prepare("SELECT status FROM $dt WHERE campaign_id=%d",$draft))==='pending','Old queue has no dispatch or retry');
    $wpdb->update($dt,array('status'=>'unknown','attempts'=>1),array('campaign_id'=>$draft)); GE_WTP_Newsletter::process_queue();
    nq($wpdb->get_var($wpdb->prepare("SELECT status FROM $dt WHERE campaign_id=%d",$draft))==='unknown','Uncertain transport result is never resent');
    foreach (array('handle_send_test','handle_queue_campaign') as $handler) {
        $blocked=false; try { GE_WTP_Newsletter::$handler(); } catch(RuntimeException $e) { $blocked=true; }
        nq($blocked,'Direct legacy handler blocked '.$handler);
    }
    $wpdb->update($mt,array('status'=>'draft'),array('id'=>$draft)); $_GET=array('newsletter_campaign'=>$draft); ob_start(); GE_WTP_Newsletter_Review::render(); $panel=ob_get_clean();
    nq(strpos($panel,'sandbox=""')!==false,'Preview sandboxed');
    nq(strpos($panel,'name="audience_key"')!==false,'Explicit whole-audience selection');
    nq(strpos($panel,'Enviar prueba')===false,'No unsafe test button');
    nq($mail===0,'Zero transport calls across all scenarios');
    echo wp_json_encode(array('status'=>'passed','checks'=>$checks,'transport_calls'=>$mail,'php'=>PHP_VERSION))."\n";
} finally {
    foreach($drafts as $id) { $wpdb->delete($dt,array('campaign_id'=>$id)); $wpdb->delete($mt,array('id'=>$id)); delete_option(GE_WTP_Newsletter_Review::META.$id); }
    foreach($ids as $id) { $wpdb->delete($ct,array('id'=>$id)); }
}
