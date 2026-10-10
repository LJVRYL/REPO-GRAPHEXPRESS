<?php
define('ABSPATH',__DIR__.'/');require __DIR__.'/../wp-content/mu-plugins/ge-crm/class-ge-crm-send-policy.php';
$checks=0;
function check($ok,$why){global $checks;if(!$ok)throw new Exception($why);$checks++;}
function rejects($fn,$code){try{$fn();}catch(RuntimeException $e){check($e->getCode()===$code,'Expected rejection');return;}throw new Exception('Expected rejection');}
$now=time();$r=array('kind'=>'thread','organization_id'=>'graph-express','status'=>'needs_review','channel'=>'instagram','meta_event'=>array('channel'=>'instagram','account_ref'=>'instagram:17841407285480956','peer'=>'55555000001','timestamp'=>gmdate('c',$now-60)));
$cfg=array('instagram'=>array('enabled'=>true,'access_token'=>'synthetic-only','asset_id'=>'17841407285480956'));
$p=GE_CRM_Send_Policy::plan($r,$cfg,'Hola',$now);
check($p['recipient']==='55555000001','Recipient from scoped event');check(strpos($p['url'],'https://graph.facebook.com/')===0,'Fixed HTTPS Graph host');check(!isset($p['payload']['access_token']),'No token in payload');
rejects(function()use($r,$cfg,$now){$r['organization_id']='other';GE_CRM_Send_Policy::plan($r,$cfg,'Hola',$now);},403);
rejects(function()use($r,$cfg,$now){$r['meta_event']['account_ref']='instagram:99999';GE_CRM_Send_Policy::plan($r,$cfg,'Hola',$now);},403);
rejects(function()use($r,$cfg,$now){$r['meta_event']['timestamp']=gmdate('c',$now-86400);GE_CRM_Send_Policy::plan($r,$cfg,'Hola',$now);},409);
rejects(function()use($r,$cfg,$now){$r['meta_event']['timestamp']=gmdate('c',$now+600);GE_CRM_Send_Policy::plan($r,$cfg,'Hola',$now);},409);
rejects(function()use($r,$cfg,$now){$r['status']='closed';GE_CRM_Send_Policy::plan($r,$cfg,'Hola',$now);},409);
rejects(function()use($r,$now){GE_CRM_Send_Policy::plan($r,array(),'Hola',$now);},409);
rejects(function()use($r,$cfg,$now){GE_CRM_Send_Policy::plan($r,$cfg,' ',$now);},422);
rejects(function()use($r,$cfg,$now){GE_CRM_Send_Policy::plan($r,$cfg,str_repeat('ñ',1001),$now);},422);
rejects(function()use($r,$cfg,$now){$r['meta_event']['peer']='https://evil.example';GE_CRM_Send_Policy::plan($r,$cfg,'Hola',$now);},422);
foreach(array('messenger'=>'1377222212141366','whatsapp'=>'1354138734939171') as $ch=>$asset){$x=$r;$x['channel']=$ch;$x['meta_event']['channel']=$ch;$x['meta_event']['account_ref']=$ch==='whatsapp'?'wa:735912107316791:'.$asset:$ch.':'.$asset;$c=array($ch=>array('enabled'=>true,'access_token'=>'synthetic-only','asset_id'=>$asset,'waba_id'=>'735912107316791'));$p=GE_CRM_Send_Policy::plan($x,$c,'Hola',$now);check($ch==='whatsapp'?($p['payload']['messaging_product']==='whatsapp'):($p['payload']['messaging_type']==='RESPONSE'),'Correct channel payload');}
foreach(array(array(200,'{"message_id":"mid.1"}','accepted'),array(200,'{"messages":[{"id":"wamid.1"}]}','accepted'),array(200,'{}','unknown'),array(500,'bad json','unknown'),array(400,'{"error":{"code":190,"message":"secret"}}','failed')) as $case){$p=GE_CRM_Send_Policy::result($case[0],$case[1]);check($p['state']===$case[2],'Honest transport result');check(strpos($p['error'],'secret')===false,'Provider details not exposed');}
$email=$r;$email['channel']='email';unset($email['meta_event']);$email['attention_event']=array('from'=>'customer@example.test','to'=>'service@graphex.ar','subject'=>'Re: Presupuesto','sender_is_internal'=>false);
$emailConfig=array('email'=>array('enabled'=>true,'recipients'=>array('service@graphex.ar')));
$emailPlan=GE_CRM_Send_Policy::plan($email,$emailConfig,'Hola',$now);
check($emailPlan['recipient']==='customer@example.test'&&$emailPlan['subject']==='Re: Presupuesto','Email replies preserve original contact and subject');
rejects(function()use($email,$emailConfig,$now){$email['attention_event']['to']='other@example.test';GE_CRM_Send_Policy::plan($email,$emailConfig,'Hola',$now);},422);
rejects(function()use($email,$emailConfig,$now){$email['attention_event']['sender_is_internal']=true;GE_CRM_Send_Policy::plan($email,$emailConfig,'Hola',$now);},422);
rejects(function()use($email,$emailConfig,$now){$email['attention_event']['from']="customer@example.test\r\nBcc: other@example.test";GE_CRM_Send_Policy::plan($email,$emailConfig,'Hola',$now);},422);
echo json_encode(array('passed'=>$checks,'external_calls'=>0)).PHP_EOL;
