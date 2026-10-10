<?php
$load=getenv('GE_WP_LOAD');
if (!$load || strpos($load,'/job-flow-qa-') === false) throw new Exception('Isolated QA required');
define('GE_WHATSAPP_PRIVATE_CLI',true); define('DISABLE_WP_CRON',true); require $load;
if (DB_NAME !== 'graph_job_flow_20261007') throw new Exception('Wrong DB');
wp_set_current_user(1); $checks=0; $mails=0;
function mc($ok,$why) { global $checks; if (!$ok) throw new Exception($why); $checks++; }
function reject($fn,$why) { try { $fn(); } catch (Throwable $e) { mc(true,$why); return; } throw new Exception($why); }
add_filter('pre_wp_mail',function() use (&$mails) { $mails++; return true; },PHP_INT_MAX);
$orgs=GE_Organization::all(); $orgs[GE_Organization::PRIMARY]['members']['1']='owner'; $orgs[GE_Organization::PRIMARY]['active']=true; update_option(GE_Organization::ROOT,$orgs,false);
GE_CRM::install(); $c=GE_CRM::config(); $c['enabled']=true; update_option('ge_crm_config_' . GE_CRM::org(),$c,false);
$cfg=array('enabled'=>true,'social_enabled'=>true,'organization_id'=>'graph-express','waba_id'=>'735912107316791','phone_number_id'=>'12345678901','number'=>'5491151393899','app_secret'=>bin2hex(random_bytes(32)),'verify_token'=>bin2hex(random_bytes(32)),'storage_key'=>base64_encode(random_bytes(32)),'page_ids'=>array('1280829488457568'),'instagram_ids'=>array('17841000000001'));
$config=getenv('GE_WHATSAPP_CONFIG');
if (!$config || strpos($config,'/job-flow-qa-') === false || strpos($config,'/site/') !== false) throw new Exception('Private QA config required');
file_put_contents($config,json_encode($cfg)); chmod($config,0600);
GE_WhatsApp_Inbound::install();
$channels=get_option('ge_crm_attention_channels',array()); $channels['whatsapp']['accounts']=array('wa:' . $cfg['waba_id'] . ':' . $cfg['phone_number_id']); update_option('ge_crm_attention_channels',$channels,false);
$prefix='qa-' . wp_generate_uuid4();
function wa_packet($cfg,$mid,$text='Quiero cotizar volantes') { return json_encode(array('object'=>'whatsapp_business_account','entry'=>array(array('id'=>$cfg['waba_id'],'changes'=>array(array('field'=>'messages','value'=>array('metadata'=>array('phone_number_id'=>$cfg['phone_number_id']),'messages'=>array(array('id'=>$mid,'from'=>'5491199900001','timestamp'=>(string)time(),'type'=>'text','text'=>array('body'=>$text)))))))))); }
function signed_req($raw,$cfg,$social=false) { $r=new WP_REST_Request('POST',$social ? '/ge/v1/crm/meta-webhook' : '/ge/v1/crm/whatsapp-webhook'); $r->set_body($raw); $r->set_header('x-hub-signature-256','sha256=' . hash_hmac('sha256',$raw,$cfg['app_secret'])); return $r; }
$raw=wa_packet($cfg,$prefix . '-wa'); $req=signed_req($raw,$cfg);
mc(GE_WhatsApp_Inbound::ready($cfg),'WA ready'); mc(GE_Meta_Social::ready($cfg),'Social independently ready');
mc(GE_WhatsApp_Inbound::verify_signature($raw,$req->get_header('x-hub-signature-256'),$cfg['app_secret']),'Raw signature valid');
mc(!GE_WhatsApp_Inbound::verify_signature($raw . ' ',$req->get_header('x-hub-signature-256'),$cfg['app_secret']),'Modified raw rejected');
mc(!GE_WhatsApp_Inbound::verify_signature($raw,'sha256=' . str_repeat('0',64),$cfg['app_secret']),'Forged signature rejected');
$enc=GE_WhatsApp_Inbound::encrypt($raw,$cfg['storage_key']); mc(strpos($enc,'volantes')===false && GE_WhatsApp_Inbound::decrypt($enc,$cfg['storage_key'])===$raw,'Encrypted durable envelope roundtrip');
reject(function() use ($enc) { GE_WhatsApp_Inbound::decrypt($enc,base64_encode(random_bytes(32))); },'Wrong storage key rejected');
$foreign=$cfg; $foreign['waba_id']='999999'; reject(function() use ($raw,$foreign) { GE_WhatsApp_Inbound::events($raw,$foreign); },'Foreign WABA rejected');
$foreign=$cfg; $foreign['phone_number_id']='999999'; reject(function() use ($raw,$foreign) { GE_WhatsApp_Inbound::events($raw,$foreign); },'Foreign phone rejected');
$bad=$cfg; $bad['app_secret']=array(); mc(!GE_WhatsApp_Inbound::ready($bad),'Invalid secret type rejected');
$r=GE_WhatsApp_Inbound::receive($req); mc(!is_wp_error($r) && $r->get_status()===200,'Durable receipt returns 200');
global $wpdb; $queue=GE_WhatsApp_Inbound::table(); $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $queue");
GE_WhatsApp_Inbound::receive($req); mc((int)$wpdb->get_var("SELECT COUNT(*) FROM $queue")===$count,'Exact delivery replay has one queue row');
GE_WhatsApp_Inbound::drain(); $id=GE_CRM::whatsapp_find_thread('wa:' . $cfg['waba_id'] . ':' . $cfg['phone_number_id'],$prefix . '-wa');
mc($id>0,'Incoming signed WA becomes CRM thread'); $thread=GE_CRM::get($id,'thread');
mc($thread['attention_classification']['category']==='quote' && !$thread['attention_classification']['ack_eligible'],'WA classified without acknowledgement');
mc(!$thread['customer_id'],'Unknown phone not linked to arbitrary customer');
$eventCount=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . GE_CRM::table('events') . ' WHERE record_id=%d',$id));
$events=GE_WhatsApp_Inbound::events($raw,$cfg); GE_CRM::whatsapp_transport_event($id,$events[0],999);
mc((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . GE_CRM::table('events') . ' WHERE record_id=%d',$id))===$eventCount,'Transport replay does not duplicate audit');
foreach (array('instagram'=>'17841000000001','page'=>'1280829488457568') as $object=>$asset) {
    $channel=$object==='page' ? 'messenger' : 'instagram';
    $data=array('object'=>$object,'entry'=>array(array('id'=>$asset,'messaging'=>array(array('sender'=>array('id'=>'55555000001'),'recipient'=>array('id'=>$asset),'timestamp'=>time()*1000,'message'=>array('mid'=>$prefix . '-' . $channel,'text'=>'No llegó mi pedido'))))));
    $body=json_encode($data); $r=GE_WhatsApp_Inbound::social_receive(signed_req($body,$cfg,true));
    mc(!is_wp_error($r) && $r->get_status()===200,$channel . ' signed durable receipt');
    mc(is_wp_error(GE_WhatsApp_Inbound::receive(signed_req($body,$cfg))),$channel . ' wrong route rejected');
    GE_WhatsApp_Inbound::drain(); $tid=GE_CRM::whatsapp_find_thread($channel . ':' . $asset,$prefix . '-' . $channel,'',$channel);
    mc($tid>0,$channel . ' received in same CRM'); $t=GE_CRM::get($tid,'thread');
    mc($t['meta_classification']['category']==='incident' && !$t['meta_classification']['ack_eligible'],$channel . ' incident classified and no auto reply');
    mc(!$t['customer_id'],$channel . ' scoped identity not guessed');
    $receipt=GE_CRM::meta_ingest($t['meta_event'],999); mc($receipt['duplicate'],$channel . ' replay idempotent');
    $data['entry'][0]['id']='999999'; reject(function() use ($data,$cfg) { GE_Meta_Social::events($data,$cfg); },$channel . ' foreign asset blocked');
}
$badReq=signed_req($raw,$cfg); $badReq->set_header('x-hub-signature-256','sha256=' . str_repeat('0',64)); mc(is_wp_error(GE_WhatsApp_Inbound::receive($badReq)),'Unsigned input rejected before queue');
$challenge=new WP_REST_Request('GET','/ge/v1/crm/whatsapp-webhook'); $challenge->set_param('hub.mode','subscribe'); $challenge->set_param('hub.verify_token',$cfg['verify_token']); $challenge->set_param('hub.challenge','123456');
mc(GE_WhatsApp_Inbound::challenge($challenge)->get_data()==='123456','Challenge data literal');
$challenge->set_param('hub.verify_token','wrong'); mc(is_wp_error(GE_WhatsApp_Inbound::challenge($challenge)),'Challenge wrong token rejected');
$disabled=$cfg; $disabled['enabled']=false; mc(GE_Meta_Social::ready($disabled) && !GE_WhatsApp_Inbound::ready($disabled),'Social works independently of WA API access');
// Recover a crashed lease and retry an event after a committed receipt without replacing originals.
$next=wa_packet($cfg,$prefix . '-lease'); GE_WhatsApp_Inbound::receive(signed_req($next,$cfg));
$lease=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $queue WHERE payload_hash=%s",hash('sha256',$next)));
$wpdb->query($wpdb->prepare("UPDATE $queue SET state='processing',lease_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) WHERE id=%d",$lease));
GE_WhatsApp_Inbound::drain(); mc($wpdb->get_var($wpdb->prepare("SELECT state FROM $queue WHERE id=%d",$lease))==='done','Stale lease recovered');
$poison=GE_WhatsApp_Inbound::encrypt('{"object":"invalid"}',$cfg['storage_key']);
$wpdb->insert($queue,array('payload_hash'=>hash('sha256',$prefix . '-poison'),'payload'=>$poison,'state'=>'queued','attempts'=>0,'created_at'=>gmdate('Y-m-d H:i:s'),'next_at'=>gmdate('Y-m-d H:i:s'))); $pid=(int)$wpdb->insert_id;
GE_WhatsApp_Inbound::drain(); $p=$wpdb->get_row($wpdb->prepare("SELECT * FROM $queue WHERE id=%d",$pid),ARRAY_A);
mc($p['state']==='queued' && (int)$p['attempts']===1 && strtotime($p['next_at'])>time(),'Failure backoff scheduled durably');
$wpdb->query($wpdb->prepare("UPDATE $queue SET attempts=7,next_at=UTC_TIMESTAMP() WHERE id=%d",$pid)); GE_WhatsApp_Inbound::drain();
mc($wpdb->get_var($wpdb->prepare("SELECT state FROM $queue WHERE id=%d",$pid))==='review','Exhausted attempts become review, not discarded');
mc((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . GE_CRM::table() . ' WHERE kind=%s AND dedupe_key=%s','task','wa-review:' . hash('sha256','queue:' . $pid)))===1,'Failed receipt owns human review task');
$changed=GE_CRM::get($id,'thread'); $changed['meta_transport']=array('outbound_enabled'=>true); $saved=GE_CRM::save('thread',$changed,$id);
mc(empty($saved['meta_transport']['outbound_enabled']),'Manual editing cannot forge transport enablement');
mc($mails===0,'No external email or Meta send path used');
wp_set_current_user(0);
foreach (array('/ge/v1/crm/whatsapp-webhook','/ge/v1/crm/meta-webhook') as $route) {
    $public=new WP_REST_Request('POST',$route);
    mc(!is_wp_error(GE_Organization_Runtime::guard_rest(null,rest_get_server(),$public)),'Signed webhook reaches module gate without WP login');
}
wp_set_current_user(1);
echo json_encode(array('checks'=>$checks,'outbound_calls'=>0,'isolated_qa'=>true,'channels'=>array('whatsapp','instagram','messenger'))) . "\n";
