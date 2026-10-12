<?php
$load=getenv('GE_WP_LOAD');$outbound=getenv('GE_CRM_OUTBOUND_CONFIG');$review=getenv('GE_META_REVIEW_CONFIG');
foreach(array($load,$outbound,$review) as $p)if(!$p||strpos($p,'/job-flow-qa-')===false)throw new Exception('Private QA required');
define('DISABLE_WP_CRON',true);require $load;
if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('Wrong database');
$checks=0;$calls=0;$peer='55555'.random_int(100000000,999999999);
function rc($ok,$why){global $checks;if(!$ok)throw new Exception($why);$checks++;}
function rr($fn){try{$fn();}catch(RuntimeException $e){rc(true,'Rejected');return;}throw new Exception('Expected rejection');}
add_filter('pre_http_request',function($pre,$args,$url)use(&$calls,$peer){
 if(strpos($url,'https://graph.facebook.com/v26.0/')!==0)throw new Exception('Unexpected API');
 $p=json_decode($args['body'],true);rc(($p['recipient']['id']??'')===$peer,'Original scoped recipient');$calls++;
 return array('response'=>array('code'=>200),'body'=>'{"message_id":"qa-review-result"}','headers'=>array());
},PHP_INT_MAX,3);
add_filter('pre_wp_mail',function(){throw new Exception('Mail forbidden');},PHP_INT_MAX);
wp_set_current_user(1);$orgs=GE_Organization::all();$orgs[GE_Organization::PRIMARY]['members']['1']='owner';$orgs[GE_Organization::PRIMARY]['active']=true;update_option(GE_Organization::ROOT,$orgs,false);
$c=GE_CRM::config();$c['enabled']=true;update_option('ge_crm_config_'.GE_CRM::org(),$c,false);GE_CRM::install();
$uid=wp_insert_user(array('user_login'=>'qa-review-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(40,true,true),'role'=>'subscriber'));
if(is_wp_error($uid))throw new Exception('QA user failed');
$event=array('kind'=>'message','channel'=>'instagram','account_ref'=>'instagram:17841407285480956','asset_id'=>'17841407285480956','peer'=>$peer,'timestamp'=>gmdate('c'),'message_id'=>'qa-review-'.wp_generate_uuid4(),'body'=>'Scoped synthetic review message','attachments'=>array());
$result=GE_CRM::meta_ingest($event,999);$id=(int)$result['record_id'];$r=GE_CRM::get($id,'thread');
$cfg=array('organization_id'=>'graph-express','enabled'=>true,'reviewer_user_id'=>(int)$uid,'sender_principal_id'=>1,'created_at'=>time(),'expires_at'=>time()+86400,'bindings'=>array((string)$id=>array('channel'=>'instagram','account_ref'=>$event['account_ref'],'peer'=>$event['peer'],'conversation_key'=>$r['conversation_key'])));
function save_review($c){global $review;file_put_contents($review,wp_json_encode($c));chmod($review,0600);}
save_review($cfg);file_put_contents($outbound,wp_json_encode(array('organization_id'=>'graph-express','outbound'=>array('instagram'=>array('enabled'=>true,'access_token'=>'synthetic-only','asset_id'=>'17841407285480956')))));chmod($outbound,0600);
wp_set_current_user($uid);rc(!GE_CRM::can(false),'Subscriber cannot enter general CRM');
rc(GE_Meta_Review_Policy::restricted_actor($cfg,(int)$uid),'Exact reviewer remains restricted');
$disabled=$cfg;$disabled['enabled']=false;$disabled['expires_at']=1;rc(GE_Meta_Review_Policy::restricted_actor($disabled,(int)$uid),'Restriction survives disable and expiry');
rc(!GE_Meta_Review_Policy::restricted_actor($cfg,1),'Owner not restricted');
foreach(array('/cliente-markcom/','/gestion/','/wp-admin/','/wp-json/wp/v2/users/me','/wp-comments-post.php','/meta-review/../cliente-markcom/','/meta-review/?rest_route=/wp/v2/users','/meta-review/?preview=true') as $uri)rc(!GE_Meta_Review_Policy::allowed_path($uri),'Other site surfaces blocked');
rc(GE_Meta_Review_Policy::allowed_path('/meta-review/?record_id=1'),'Review route allowed');rc(GE_Meta_Review_Policy::allowed_path('/wp-login.php?action=logout','logout'),'Logout allowed');rc(!GE_Meta_Review_Policy::allowed_path('/wp-login.php?action=lostpassword','lostpassword'),'No credential changes');
$view=GE_Meta_Review::view($id);rc(count($view['messages'])===1&&$view['messages'][0]['text']===$event['body'],'Only scoped synthetic message');rc(get_current_user_id()===$uid,'View restores reviewer');rc(strpos(wp_json_encode($view),'synthetic-only')===false,'No token exposure');
$request=array('record_id'=>$id,'nonce'=>wp_create_nonce('ge_meta_review_reply'),'request_id'=>wp_generate_uuid4(),'text'=>'Synthetic review response');
$sent=GE_Meta_Review::send($request);rc($sent['state']==='accepted'&&$calls===1,'One mocked send');rc(get_current_user_id()===$uid,'Send restores reviewer');
GE_Meta_Review::send($request);rc($calls===1,'Duplicate request does not resend');
rr(function()use($request){$request['nonce']='invalid';GE_Meta_Review::send($request);});
rr(function()use($id){GE_Meta_Review::view($id+100000);});
$bad=$cfg;$bad['expires_at']=time();save_review($bad);rr(function()use($id){GE_Meta_Review::view($id);});
$bad=$cfg;$bad['bindings'][(string)$id]['peer']=array('bad');save_review($bad);rr(function()use($id){GE_Meta_Review::view($id);});
$bad=$cfg;$bad['bindings'][(string)$id]['account_ref']='instagram:99999';save_review($bad);rr(function()use($id){GE_Meta_Review::view($id);});
foreach(array('disabled','wrong_actor','overlong','malformed_channel','wrong_hash','foreign_org') as $case){
 $bad=$cfg;
 if($case==='disabled')$bad['enabled']='yes';
 if($case==='wrong_actor')$bad['reviewer_user_id']=1;
 if($case==='overlong')$bad['expires_at']=$bad['created_at']+31*86400;
 if($case==='malformed_channel')$bad['bindings'][(string)$id]['channel']=array('instagram');
 if($case==='wrong_hash')$bad['bindings'][(string)$id]['conversation_key']=str_repeat('a',64);
 if($case==='foreign_org')$bad['organization_id']='other';
 save_review($bad);rr(function()use($id){GE_Meta_Review::view($id);});
}
$bad=$cfg;$bad['sender_principal_id']=99999999;save_review($bad);rr(function()use($id){GE_Meta_Review::view($id);});rc(get_current_user_id()===$uid,'Failed delegation restores reviewer');
save_review($cfg);wp_set_current_user(1);rr(function()use($id){GE_Meta_Review::view($id);});wp_set_current_user($uid);
rc($calls===1,'All rejected requests avoid transport');rc(!GE_CRM::can(false),'Reviewer still denied general CRM');
echo wp_json_encode(array('passed'=>$checks,'mock_http_calls'=>$calls,'real_external_effects'=>0,'reviewer_not_organization_member'=>true)).PHP_EOL;
