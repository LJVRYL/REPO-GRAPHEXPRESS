<?php
$load=getenv('GE_WP_LOAD');$config=getenv('GE_CRM_OUTBOUND_CONFIG');
if (!$load || strpos($load,'/job-flow-qa-')===false || !$config || strpos($config,'/job-flow-qa-')===false || strpos($config,'/site/')!==false) throw new Exception('Private isolated QA required');
define('DISABLE_WP_CRON',true);require $load;
if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('Wrong database');
wp_set_current_user(1);$checks=0;$calls=0;$mode='ok';$run=wp_generate_uuid4();
function oc($ok,$why){global $checks;if(!$ok)throw new Exception($why);$checks++;}
function oreject($fn){try{$fn();}catch(Throwable $e){oc(true,'Rejected');return;}throw new Exception('Expected rejection');}
add_filter('pre_wp_mail',function(){throw new Exception('QA must not send real mail');},PHP_INT_MAX);
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
echo wp_json_encode(array('passed'=>$checks,'mock_http_calls'=>$calls,'real_outbound'=>0,'record_id'=>$id)).PHP_EOL;
