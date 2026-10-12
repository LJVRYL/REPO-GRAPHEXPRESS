<?php
define('ABSPATH',__DIR__.'/');
require __DIR__.'/../wp-content/mu-plugins/ge-crm/class-ge-whatsapp-inbound.php';
require __DIR__.'/../wp-content/mu-plugins/ge-crm/class-ge-crm-send-policy.php';
$checks=0;
function tc($ok,$why){global $checks;if(!$ok)throw new Exception($why);$checks++;}
function tr($fn,$why){try{$fn();}catch(RuntimeException $e){tc(true,$why);return;}catch(InvalidArgumentException $e){tc(true,$why);return;}throw new Exception($why);}
$cfg=array('enabled'=>true,'mode'=>'meta-test','organization_id'=>'graph-express','waba_id'=>GE_Meta_Test_Assets::WABA,'phone_number_id'=>GE_Meta_Test_Assets::PHONE,'number'=>GE_Meta_Test_Assets::NUMBER,'app_secret'=>str_repeat('x',32),'verify_token'=>str_repeat('v',32),'storage_key'=>base64_encode(str_repeat('k',32)));
tc(GE_WhatsApp_Inbound::ready($cfg),'Exact test configuration accepted');
foreach(array('mode'=>'production','organization_id'=>'foreign','waba_id'=>'735912107316791','phone_number_id'=>'1354138734939171','number'=>'5491151393899','enabled'=>false) as $field=>$value){$bad=$cfg;$bad[$field]=$value;tc(!GE_WhatsApp_Inbound::ready($bad),'Wrong test '.$field.' rejected');}
$real=$cfg;unset($real['mode']);$real['waba_id']='735912107316791';$real['phone_number_id']='1354138734939171';$real['number']='5491151393899';
tc(GE_WhatsApp_Inbound::ready($real),'Existing production readiness retained');$real['mode']='meta-test';tc(!GE_WhatsApp_Inbound::ready($real),'Test mode never authorizes real assets');
$message=array('id'=>'wamid.synthetic-test','from'=>GE_Meta_Test_Assets::RECIPIENT,'timestamp'=>(string)time(),'type'=>'text','text'=>array('body'=>'Synthetic review test'));
$packet=array('object'=>'whatsapp_business_account','entry'=>array(array('id'=>GE_Meta_Test_Assets::WABA,'changes'=>array(array('field'=>'messages','value'=>array('metadata'=>array('phone_number_id'=>GE_Meta_Test_Assets::PHONE),'messages'=>array($message)))))));
$raw=json_encode($packet);$events=GE_WhatsApp_Inbound::events($raw,$cfg);
tc(count($events)===1&&$events[0]['account_ref']===GE_Meta_Test_Assets::account()&&!empty($events[0]['test_mode']),'Test event tagged and scoped');
tc(GE_WhatsApp_Inbound::verify_signature($raw,'sha256='.hash_hmac('sha256',$raw,$cfg['app_secret']),$cfg['app_secret']),'Actual signature required');
tc(!GE_WhatsApp_Inbound::verify_signature($raw.'x','sha256='.hash_hmac('sha256',$raw,$cfg['app_secret']),$cfg['app_secret']),'Altered payload rejected');
$bad=$packet;$bad['entry'][0]['changes'][0]['value']['messages'][0]['from']='5491100000000';tr(function()use($bad,$cfg){GE_WhatsApp_Inbound::events(json_encode($bad),$cfg);},'Foreign test sender rejected');
$bad=$packet;$bad['entry'][0]['changes'][0]['value']['metadata']['phone_number_id']='1354138734939171';tr(function()use($bad,$cfg){GE_WhatsApp_Inbound::events(json_encode($bad),$cfg);},'Real phone cannot enter test receiver');
$bad=$packet;$bad['entry'][0]['id']='735912107316791';tr(function()use($bad,$cfg){GE_WhatsApp_Inbound::events(json_encode($bad),$cfg);},'Real WABA cannot enter test receiver');
$bad=$packet;$bad['entry'][0]['changes'][0]['field']='history';tr(function()use($bad,$cfg){GE_WhatsApp_Inbound::events(json_encode($bad),$cfg);},'Test path cannot import personal history');
$status=$packet;unset($status['entry'][0]['changes'][0]['value']['messages']);$status['entry'][0]['changes'][0]['value']['statuses']=array(array('id'=>'wamid.synthetic-out','recipient_id'=>GE_Meta_Test_Assets::RECIPIENT,'timestamp'=>(string)time(),'status'=>'delivered'));
tc(GE_WhatsApp_Inbound::events(json_encode($status),$cfg)[0]['status']==='delivered','Own delivery event supported');
$status['entry'][0]['changes'][0]['value']['statuses'][0]['recipient_id']='5491100000000';tr(function()use($status,$cfg){GE_WhatsApp_Inbound::events(json_encode($status),$cfg);},'Foreign delivery event rejected');
$r=array('kind'=>'thread','organization_id'=>'graph-express','status'=>'needs_review','channel'=>'whatsapp','meta_event'=>$events[0]);
$out=array('whatsapp_test'=>array('enabled'=>true,'mode'=>'meta-test','waba_id'=>GE_Meta_Test_Assets::WABA,'asset_id'=>GE_Meta_Test_Assets::PHONE,'access_token'=>'synthetic-only'));
$p=GE_CRM_Send_Policy::plan($r,$out,'Synthetic reply',time());
tc($p['channel']==='whatsapp_test','Test token separated from real sender');tc($p['url']==='https://graph.facebook.com/v26.0/'.GE_Meta_Test_Assets::PHONE.'/messages','Only official test sender used');
tc($p['payload']['to']===GE_Meta_Test_Assets::SEND_RECIPIENT&&$p['payload']['messaging_product']==='whatsapp','Verified Meta test list dial form used');
tc($p['recipient']===GE_Meta_Test_Assets::RECIPIENT,'Signed original owner identity retained');
tc($p['message_channel']==='whatsapp','Delivery events use the original message channel, not the credential key');
$future=$out;$future['whatsapp_test']['usable_until']=time()+3600;tc(GE_CRM_Send_Policy::plan($r,$future,'Hola',time())['channel']==='whatsapp_test','Authorized local credential window accepted');
$expired=$out;$expired['whatsapp_test']['usable_until']=time();tr(function()use($r,$expired){GE_CRM_Send_Policy::plan($r,$expired,'Hola',time());},'Credential cut off at exact authorized expiry');
$invalid=$out;$invalid['whatsapp_test']['usable_until']='tomorrow';tr(function()use($r,$invalid){GE_CRM_Send_Policy::plan($r,$invalid,'Hola',time());},'Malformed credential expiry fails closed');
tr(function()use($r){GE_CRM_Send_Policy::plan($r,array('whatsapp'=>array('enabled'=>true,'asset_id'=>'1354138734939171','waba_id'=>'735912107316791','access_token'=>'synthetic-only')),'Reply',time());},'Test message cannot use real credential');
$bad=$r;$bad['meta_event']['peer']='5491100000000';tr(function()use($bad,$out){GE_CRM_Send_Policy::plan($bad,$out,'Reply',time());},'Test replies cannot reach others');
$bad=$out;$bad['whatsapp_test']['asset_id']='1354138734939171';tr(function()use($r,$bad){GE_CRM_Send_Policy::plan($r,$bad,'Reply',time());},'Real phone cannot replace test sender');
$bad=$r;$bad['meta_event']['timestamp']=gmdate('c',time()-86401);tr(function()use($bad,$out){GE_CRM_Send_Policy::plan($bad,$out,'Reply',time());},'24-hour window applies to test replies');
echo json_encode(array('passed'=>$checks,'external_calls'=>0)).PHP_EOL;
