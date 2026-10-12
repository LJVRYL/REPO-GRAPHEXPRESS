<?php
$load=getenv('GE_WP_LOAD');$path=getenv('GE_CRM_OUTBOUND_CONFIG');$review=getenv('GE_META_REVIEW_CONFIG');
foreach(array($load,$path,$review) as $p)if(!$p||strpos($p,'/job-flow-qa-')===false)throw new Exception('Private QA required');
define('DISABLE_WP_CRON',true);require $load;
if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('Wrong database');
$checks=0;$calls=0;$mode='valid';$secret=str_repeat('syntheticPrivate',4);
function mc($ok,$why){global $checks;if(!$ok)throw new Exception($why);$checks++;}
function mr($fn){try{$fn();}catch(RuntimeException $e){mc(strpos($e->getMessage(),'syntheticPrivate')===false,'Error does not leak a key');return;}throw new Exception('Expected rejection');}
add_filter('pre_wp_mail',function(){throw new Exception('No mail in asset verification');},PHP_INT_MAX);
add_filter('pre_http_request',function($pre,$args,$url)use(&$calls,&$mode,$secret){
 mc(($args['method']??'')==='GET','Reads only');mc($args['redirection']===0&&$args['sslverify']===true,'No redirects and verified TLS');$calls++;
 $routes=array(
 '1811159106704793?fields=id,name'=>array('id'=>'1811159106704793','name'=>'Graphex'),
 'me/accounts?fields=id&limit=100'=>array('data'=>array(array('id'=>'99999999','name'=>'foreign page must not be retained'),array('id'=>'1377222212141366'))),
 '1377222212141366?fields=id,name,instagram_business_account'=>array('id'=>'1377222212141366','name'=>'Graphex','instagram_business_account'=>array('id'=>'17841407285480956')),
 '17841407285480956?fields=id,username'=>array('id'=>'17841407285480956','username'=>'graphex.ar'),
 '1377222212141366/subscribed_apps?fields=id,subscribed_fields&limit=100'=>array('data'=>array(array('id'=>'2684180385332773','subscribed_fields'=>array('messages','message_reads','message_deliveries','message_echoes')))),
 '1588731999397867/phone_numbers?fields=id,display_phone_number&limit=25'=>array('data'=>array(array('id'=>'1338419139362975','display_phone_number'=>'+1 555-650-5576'))),
 '1588731999397867/message_templates?fields=id,name,status,category&limit=25'=>array('data'=>array(array('name'=>'hello_world','status'=>'APPROVED','category'=>'UTILITY','access_token'=>$secret),array('name'=>'graphex_prueba_qa','status'=>'APPROVED','category'=>'UTILITY'),array('name'=>'unrelated_template','status'=>'APPROVED','category'=>'MARKETING'))));
 $prefix='https://graph.facebook.com/v26.0/';mc(strpos($url,$prefix)===0,'Meta endpoint only');$route=substr($url,strlen($prefix));mc(isset($routes[$route]),'Only allowlisted own-asset routes');$data=$routes[$route];
 if($mode==='api_error')return array('response'=>array('code'=>400),'body'=>wp_json_encode(array('error'=>array('message'=>$secret))),'headers'=>array());
 if($mode==='bad_json')return array('response'=>array('code'=>200),'body'=>'invalid','headers'=>array());
 if($mode==='business'&&strpos($route,'181115')===0)$data['id']='99999999';
 if($mode==='page_missing'&&strpos($route,'me/accounts')===0)$data['data']=array();
 if($mode==='page_link'&&strpos($route,'1377222212141366?')===0)$data['instagram_business_account']['id']='99999999';
 if($mode==='instagram'&&strpos($route,'178414')===0)$data['username']='another_account';
 if($mode==='app'&&strpos($route,'1377222212141366/subscribed')===0)$data['data'][0]['id']='99999999';
 if($mode==='subscription'&&strpos($route,'1377222212141366/subscribed')===0)$data['data'][0]['subscribed_fields']=array('messages');
 if($mode==='phone'&&strpos($route,'1588731999397867/phone')===0)$data['data'][0]['id']='99999999';
 if($mode==='number'&&strpos($route,'1588731999397867/phone')===0)$data['data'][0]['display_phone_number']='+1 111 222 3333';
 if($mode==='templates_list'&&strpos($route,'1588731999397867/message')===0)$data['data']='invalid';
 $data['access_token']=$secret;return array('response'=>array('code'=>200),'body'=>wp_json_encode($data),'headers'=>array());
},PHP_INT_MAX,3);
wp_set_current_user(1);$orgs=GE_Organization::all();$orgs[GE_Organization::PRIMARY]['members']['1']='owner';$orgs[GE_Organization::PRIMARY]['active']=true;update_option(GE_Organization::ROOT,$orgs,false);$c=GE_CRM::config();$c['enabled']=true;update_option('ge_crm_config_'.GE_CRM::org(),$c,false);
$cfg=array('organization_id'=>'graph-express','outbound'=>array('whatsapp_test'=>array('enabled'=>true,'mode'=>'meta-test','asset_id'=>'1338419139362975','waba_id'=>'1588731999397867','access_token'=>$secret,'usable_until'=>time()+86400),'messenger'=>array('enabled'=>true,'asset_id'=>'1377222212141366','access_token'=>$secret)));
function save_mc($cfg){global $path;file_put_contents($path,wp_json_encode($cfg));chmod($path,0600);}save_mc($cfg);
$snapshot=GE_CRM_Meta_Assets::inspect();mc($snapshot['verified']&&$snapshot['read_only'],'Verified read-only snapshot');mc($calls===7,'Seven reads, no writes');mc(count($snapshot['templates'])===2,'Only own synthetic templates retained');$json=wp_json_encode($snapshot);mc(strpos($json,$secret)===false&&strpos($json,'foreign page')===false&&strpos($json,'unrelated_template')===false,'No key or foreign metadata stored');
update_option(GE_CRM_Meta_Assets::OPTION,$snapshot,false);ob_start();GE_CRM_Meta_Assets::render();$html=ob_get_clean();mc(strpos($html,'Verificar activos con Meta')!==false&&strpos($html,'@graphex.ar')!==false,'Real owner verification UI');mc(strpos($html,$secret)===false,'No key in rendered UI');
foreach(array('business','page_missing','page_link','instagram','app','subscription','phone','number','templates_list','api_error','bad_json') as $case){$mode=$case;mr(function(){GE_CRM_Meta_Assets::inspect();});}
$mode='valid';$before=$calls;
foreach(array('expired','wrong_page','wrong_waba','missing_token') as $case){$bad=$cfg;if($case==='expired')$bad['outbound']['whatsapp_test']['usable_until']=time();if($case==='wrong_page')$bad['outbound']['messenger']['asset_id']='99999999';if($case==='wrong_waba')$bad['outbound']['whatsapp_test']['waba_id']='99999999';if($case==='missing_token')$bad['outbound']['whatsapp_test']['access_token']='';save_mc($bad);mr(function(){GE_CRM_Meta_Assets::inspect();});}mc($before===$calls,'Invalid private scope blocked before HTTP');save_mc($cfg);
wp_set_current_user(0);mr(function(){GE_CRM_Meta_Assets::inspect();});mc($before===$calls,'Unauthenticated caller blocked before HTTP');
$uid=wp_insert_user(array('user_login'=>'qa-assets-review-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(40),'role'=>'subscriber'));if(is_wp_error($uid))throw new Exception('QA subscriber failed');
$b=array('channel'=>'instagram','account_ref'=>'instagram:17841407285480956','peer'=>'55555000001');$b['conversation_key']=hash('sha256',$b['channel'].':'.$b['account_ref'].':'.$b['peer']);
$r=array('organization_id'=>'graph-express','enabled'=>true,'reviewer_user_id'=>(int)$uid,'sender_principal_id'=>1,'created_at'=>time(),'expires_at'=>time()+86400,'bindings'=>array('1'=>$b));file_put_contents($review,wp_json_encode($r));chmod($review,0600);
wp_set_current_user($uid);mr(function(){GE_CRM_Meta_Assets::inspect();});ob_start();GE_CRM_Meta_Assets::render_summary(true);$html=ob_get_clean();mc(strpos($html,'Linked Instagram')!==false,'Scoped reviewer sees only verified metadata');mc(strpos($html,'Official WhatsApp test')===false&&strpos($html,'hello_world')===false,'Unbound channel metadata excluded');mc($before===$calls,'Reviewer summary never calls Meta');mc(!GE_CRM::can(false),'No general CRM access granted');
$r['expires_at']=time();file_put_contents($review,wp_json_encode($r));mr(function(){GE_CRM_Meta_Assets::render_summary(true);});
echo wp_json_encode(array('passed'=>$checks,'mock_http_reads'=>$calls,'real_external_effects'=>0,'reviewer_cannot_verify_or_modify_assets'=>true)).PHP_EOL;
