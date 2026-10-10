<?php
$load=getenv('GE_WP_LOAD');$config=getenv('GE_CRM_OUTBOUND_CONFIG');
if (!$load || strpos($load,'/job-flow-qa-')===false || !$config || strpos($config,'/job-flow-qa-')===false || strpos($config,'/site/')!==false) throw new Exception('Private isolated QA required');
define('DISABLE_WP_CRON',true);require $load;
if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('Wrong database');
wp_set_current_user(1);$checks=0;$calls=0;$mode='ok';$run=wp_generate_uuid4();$mailMode=false;$mails=0;
function oc($ok,$why){global $checks;if(!$ok)throw new Exception($why);$checks++;}
function oreject($fn){try{$fn();}catch(Throwable $e){oc(true,'Rejected');return;}throw new Exception('Expected rejection');}
oc(strpos(GE_CRM_Agent::private_dir(),'/job-flow-qa-')!==false,'QA never uses production AI configuration');
add_filter('pre_wp_mail',function($pre,$atts)use(&$mailMode,&$mails){if(!$mailMode)throw new Exception('QA must not send real mail');$mails++;oc(in_array('customer@example.test',(array)$atts['to'],true),'Email recipient is original contact');return true;},PHP_INT_MAX,2);
add_filter('pre_http_request',function($pre,$args,$url)use(&$calls,&$mode,$run){
 if (strpos($url,'https://graph.facebook.com/v26.0/')!==0) throw new Exception('Unexpected remote URL');
 $calls++;if($mode==='timeout')return new WP_Error('timeout','private synthetic detail');
 $p=json_decode($args['body'],true);oc(($p['recipient']['id'] ?? '')==='55555000001','Recipient fixed to original');
 return array('response'=>array('code'=>$mode==='failed'?400:200),'body'=>$mode==='failed'?'{"error":{"code":190,"message":"private synthetic detail"}}':wp_json_encode(array('message_id'=>'qa-out-'.$run.'-'.$calls)),'headers'=>array());
},PHP_INT_MAX,3);
$orgs=GE_Organization::all();$orgs[GE_Organization::PRIMARY]['members']['1']='owner';$orgs[GE_Organization::PRIMARY]['active']=true;update_option(GE_Organization::ROOT,$orgs,false);$c=GE_CRM::config();$c['enabled']=true;update_option('ge_crm_config_'.GE_CRM::org(),$c,false);GE_CRM::install();
$cfg=array('organization_id'=>'graph-express','outbound'=>array('instagram'=>array('enabled'=>true,'access_token'=>'synthetic-only','asset_id'=>'17841407285480956')));file_put_contents($config,wp_json_encode($cfg));chmod($config,0600);
$event=array('kind'=>'message','channel'=>'instagram','account_ref'=>'instagram:17841407285480956','asset_id'=>'17841407285480956','peer'=>'55555000001','timestamp'=>gmdate('c'),'message_id'=>'qa-reply-'.wp_generate_uuid4(),'body'=>'Quiero cotizar','attachments'=>array(),'media_pending'=>false);
$r=GE_CRM::meta_ingest($event,999);$id=$r['record_id'];$request=array('record_id'=>$id,'request_id'=>wp_generate_uuid4(),'text'=>'Hola, ¿qué cantidad necesitás?');
$answer=GE_CRM_Outbound::send($request);oc($answer['state']==='accepted','HTTP acceptance recorded');oc($calls===1,'One remote call');oc(GE_CRM_Outbound::send($request)['state']==='accepted'&&$calls===1,'Replay never sends twice');
oreject(function()use($request){$request['text']='Another text';GE_CRM_Outbound::send($request);});oc($calls===1,'Conflict blocked before transport');
$delivery=$event;$delivery['kind']='status';$delivery['message_id']=$answer['provider_id'];$delivery['status']='delivered';GE_CRM::whatsapp_transport_event($id,$delivery,999);oc(GE_CRM_Outbound::send($request)['state']==='delivered','Signed transport event reflected');oc($calls===1,'Checking delivery does not send');
$mode='timeout';$request['request_id']=wp_generate_uuid4();$answer=GE_CRM_Outbound::send($request);oc($answer['state']==='unknown','Timeout not marked failed/sent');GE_CRM_Outbound::send($request);oc($calls===2,'Unknown replay never sends twice');
$mode='failed';$request['request_id']=wp_generate_uuid4();$answer=GE_CRM_Outbound::send($request);oc($answer['state']==='failed'&&strpos($answer['error'],'private')===false,'Safe definite provider error');
wp_set_current_user(0);oreject(function()use($request){GE_CRM_Outbound::send($request);});oc($calls===3,'Unauthorized send blocked');
wp_set_current_user(1);$record=GE_CRM::get($id,'thread');ob_start();GE_CRM_Outbound::render($record);$html=ob_get_clean();oc(strpos($html,'Enviar respuesta')!==false&&strpos($html,'Tu respuesta')!==false,'Composer rendered');oc(strpos($html,'synthetic-only')===false,'No credential in page');
$settings=GE_WTP_Notification_Center::settings();$settings['sender_email']='servicio@graphex.ar';update_option(GE_WTP_Notification_Center::OPTION,$settings,false);
update_option('ge_crm_attention_channels',array('email'=>array('accounts'=>array('qa-mailbox'),'recipients'=>array('servicio@graphex.ar'),'senders'=>array('servicio@graphex.ar'))),false);
$cfg['outbound']['email']=array('enabled'=>true,'recipients'=>array('servicio@graphex.ar'));file_put_contents($config,wp_json_encode($cfg));
$mailEvent=array('organization_id'=>'graph-express','channel'=>'email','external_id'=>'qa-mail-'.$run,'conversation_id'=>'qa-conversation-'.$run,'account_ref'=>'qa-mailbox','source_ref'=>'mail:synthetic','received_at'=>gmdate('Y-m-d\TH:i:s\Z'),'from'=>'customer@example.test','to'=>'servicio@graphex.ar','subject'=>'Consulta QA','body'=>'Necesito información','headers'=>array('message-id'=>'<qa-'.$run.'@example.test>'));
$mailRecord=GE_CRM::attention_ingest($mailEvent);$mailMode=true;$mailRequest=array('record_id'=>$mailRecord['record_id'],'request_id'=>wp_generate_uuid4(),'text'=>'Respuesta simulada QA');
$mailAnswer=GE_CRM_Outbound::send($mailRequest);oc(in_array($mailAnswer['state'],array('accepted','simulated'),true)&&$mails===1,'Native mail transport called once');
GE_CRM_Outbound::send($mailRequest);oc($mails===1,'Email replay does not send twice');
global $wpdb;$mailLog=$wpdb->get_var($wpdb->prepare('SELECT post_id FROM '.$wpdb->postmeta.' WHERE meta_key=%s AND meta_value=%s ORDER BY meta_id DESC LIMIT 1','_ge_email_context','crm_reply'));oc((int)$mailLog>0,'Native CRM email trace recorded');
echo wp_json_encode(array('passed'=>$checks,'mock_http_calls'=>$calls,'mock_mail_calls'=>$mails,'real_outbound'=>0,'record_id'=>$id)).PHP_EOL;
