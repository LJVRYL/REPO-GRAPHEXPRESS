<?php
// In-memory WordPress fixtures; fake credentials kept outside the public root.
define('ABSPATH','/home/graphexpress/public_html/');
define('GE_CANVA_CUSTOMER_CREDENTIAL_FILE',sys_get_temp_dir().'/ge-cards-canva-fixture-'.getmypid().'.json');
file_put_contents(GE_CANVA_CUSTOMER_CREDENTIAL_FILE,json_encode(array('client_id'=>'fixture-client','client_secret'=>str_repeat('x',32),'encryption_key'=>base64_encode(random_bytes(32)))));chmod(GE_CANVA_CUSTOMER_CREDENTIAL_FILE,0600);
class Response extends Exception {public $data;function __construct($data,$code){$this->data=$data;parent::__construct('fixture response',$code);}}
class WP_Error {}
class GE_Cards_Experience {static function organization(){return 'graph-express';}static function actor_allowed(){return $GLOBALS['actor']>0;}static function path(){return $GLOBALS['path'];}}
class GE_WTP_Digital_Catalog {static function commercial_quote_price($id,$input){return array('values'=>$input,'price'=>22200);}}
$GLOBALS['actor']=7;$GLOBALS['session']='fixture-session-1';$GLOBALS['path']='/';$GLOBALS['meta']=array();$GLOBALS['transients']=array();$GLOBALS['options']=array('ge_cards_canva_pilot_enabled_v1'=>'yes','ge_cards_canva_pilot_users_v1'=>array(7,8));$GLOBALS['hooks']=array();$GLOBALS['requests']=0;
function add_action($name,$callback){$GLOBALS['hooks'][$name]=$callback;}function get_current_user_id(){return $GLOBALS['actor'];}function wp_get_session_token(){return $GLOBALS['session'];}function wp_salt($s){return 'fixture-salt';}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}function add_option($k,$v){if(isset($GLOBALS['options'][$k]))return false;$GLOBALS['options'][$k]=$v;return true;}function current_user_can($cap){return false;}
function absint($v){return abs((int)$v);}function wp_json_encode($v){return json_encode($v);}function set_transient($k,$v,$ttl){$GLOBALS['transients'][$k]=$v;}function get_transient($k){return $GLOBALS['transients'][$k]??false;}function delete_transient($k){unset($GLOBALS['transients'][$k]);}
function get_user_meta($id,$k,$single=true){return $GLOBALS['meta'][$id][$k]??'';}function update_user_meta($id,$k,$v){$GLOBALS['meta'][$id][$k]=$v;return true;}function delete_user_meta($id,$k){unset($GLOBALS['meta'][$id][$k]);}
function home_url($p){return 'https://graphex.ar'.$p;}function get_permalink($id){return 'https://graphex.ar/product/tarjetas-express/';}function add_query_arg($k,$v,$url){return $url.'?'.$k.'='.$v;}function wp_safe_redirect($url){throw new Response(array('redirect'=>$url),302);}function wp_redirect($url){throw new Response(array('redirect'=>$url),302);}
function wp_die($message,$title='',$args=array()){throw new Response(array('message'=>$message),$args['response']??500);}function check_ajax_referer($action,$key){if(($_POST[$key]??'')!=='good')wp_die('nonce','',array('response'=>403));}function check_admin_referer($a){check_ajax_referer($a,'_wpnonce');}
function nocache_headers(){}function wp_unslash($v){return $v;}function is_wp_error($v){return $v instanceof WP_Error;}function wp_send_json_success($v){throw new Response($v,200);}function wp_send_json_error($v,$code){throw new Response($v,$code);}function wp_remote_retrieve_body($r){return $r['body'];}function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_safe_remote_request($url,$args){$GLOBALS['requests']++;if(($args['redirection']??1)!==0||empty($args['sslverify'])||($args['limit_response_size']??0)!==2097153)throw new RuntimeException('Transport protection missing');
 if(strpos($url,'/designs/')!==false)$data=array('design'=>array('id'=>'design1','page_count'=>2,'owner'=>array('user_id'=>'user1','team_id'=>'team1'),'urls'=>array('edit_url'=>'https://www.canva.com/api/design/fixture/edit')));
 elseif(strpos($url,'/exports/')!==false)$data=array('job'=>array('status'=>'success','urls'=>array('https://export-download.canva.com/private.pdf')));
 elseif(substr($url,-8)==='/exports')$data=array('job'=>array('id'=>'job1'));
 elseif(strpos($url,'/connect/keys')!==false)$data=$GLOBALS['jwks'];
 else throw new RuntimeException('Unexpected fixture request');return array('code'=>200,'body'=>json_encode($data));}
require getenv('GE_CARDS_CANVA_MODULE')?:dirname(__DIR__).'/wp-content/mu-plugins/ge-cards-canva.php';
$passed=0;function ok($v,$name){global $passed;if(!$v)throw new RuntimeException($name);$passed++;}function denied($fn,$name){try{$fn();throw new LogicException($name);}catch(RuntimeException $e){ok(true,$name);}}
function response($op,$post=array()){$_SERVER['REQUEST_METHOD']='POST';$_POST=array_merge(array('nonce'=>'good'),$post);try{GE_Cards_Canva::ajax($op);throw new LogicException('Missing response');}catch(Response $r){return $r;}}
function private_call($method,$args=array()){$r=new ReflectionMethod('GE_Cards_Canva',$method);$r->setAccessible(true);return $r->invokeArgs(null,$args);}
ok(GE_Cards_Canva::available(),'Allowed pilot account');$GLOBALS['options']['ge_cards_canva_pilot_enabled_v1']='no';ok(!GE_Cards_Canva::available(),'Disabled flag denies all');$GLOBALS['options']['ge_cards_canva_pilot_enabled_v1']='yes';
$cipher=GE_Cards_Canva::seal(array('secret'=>'fixture-secret'));ok(strpos($cipher,'fixture-secret')===false,'Encrypted state');ok(GE_Cards_Canva::open($cipher)['secret']==='fixture-secret','Round trip');
$GLOBALS['actor']=8;denied(function()use($cipher){GE_Cards_Canva::open($cipher);},'Cross-account cipher denied');$GLOBALS['actor']=7;
$GLOBALS['session']='other-session';denied(function()use($cipher){GE_Cards_Canva::open($cipher);},'Cross-session flow denied');$GLOBALS['session']='fixture-session-1';
$raw=base64_decode($cipher);$raw[30]=$raw[30]^chr(1);denied(function()use($raw){GE_Cards_Canva::open(base64_encode($raw));},'Altered ciphertext denied');
$connection=array('tokens'=>array('access_token'=>'fixture-access','refresh_token'=>'fixture-refresh','expires'=>time()+14400),'identity'=>array('user_id'=>'user1','team_id'=>'team1'),'connection_id'=>'connection1');private_call('save_connection',array($connection));
$GLOBALS['session']='same-user-session-2';ok(private_call('connection')['identity']['user_id']==='user1','Connection available to same account sessions');$GLOBALS['session']='fixture-session-1';
ok(response('status')->data['connected']===true,'Status connected');ok(response('status',array('nonce'=>'bad'))->getCode()===403,'Invalid nonce denied');
$GLOBALS['actor']=9;ok(response('status')->getCode()===403,'Account outside pilot denied');$GLOBALS['actor']=0;ok(response('status')->getCode()===403,'Anonymous denied');$GLOBALS['actor']=7;
$selection=array('tamano'=>'5x9','cantidad'=>'100','papel'=>'350','impresion'=>'doble','laminado_caras'=>'simple','laminado_acabado'=>'mate');
$edit=response('edit',array('design_id'=>'design1','selection'=>json_encode($selection)));ok($edit->getCode()===200,'Edit authorised');parse_str(parse_url($edit->data['url'],PHP_URL_QUERY),$params);$state=$params['correlation_state'];ok(strlen($state)===32,'Opaque correlation');
$export=response('export',array('design_id'=>'design1','selection'=>json_encode($selection)));$job=$export->data['job'];ok(strlen($job)===32,'Opaque job');
$before=$GLOBALS['requests'];$GLOBALS['actor']=8;ok(response('export_status',array('job'=>$job))->getCode()===422,'Other client cannot poll job');ok($GLOBALS['requests']===$before,'Foreign job makes no Canva request');$GLOBALS['actor']=7;
$poll=response('export_status',array('job'=>$job));ok($poll->data===array('status'=>'ready'),'Only public job state returned');ok(strpos(json_encode($poll->data),'canva.com')===false,'Private download URL never returned');
$keys=sodium_crypto_sign_seed_keypair(str_repeat('s',32));$secret=sodium_crypto_sign_secretkey($keys);$public=sodium_crypto_sign_publickey($keys);function b64($v){return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
$GLOBALS['jwks']=array('keys'=>array(array('kid'=>'key1','kty'=>'OKP','crv'=>'Ed25519','x'=>b64($public))));
$payload=array('aud'=>'fixture-client','exp'=>time()+600,'sub'=>'user1','team_id'=>'team1','type'=>'rti','jti'=>'fixture-jti','design_id'=>'design1','correlation_state'=>$state);$a=b64(json_encode(array('alg'=>'EdDSA','kid'=>'key1')));$b=b64(json_encode($payload));$jwt=$a.'.'.$b.'.'.b64(sodium_crypto_sign_detached($a.'.'.$b,$secret));
$GLOBALS['path']='/tarjetas/canva/return/';$_GET=array('correlation_jwt'=>$jwt);try{GE_Cards_Canva::route();}catch(Response $r){ok(strpos($r->data['redirect'],'canva_status=returned')!==false,'Signed return redirects to product');}
$returned=response('status')->data['returned'];ok($returned['design_id']==='design1'&&$returned['selection']['values']===$selection,'Return restores original product selection');
try{GE_Cards_Canva::route();}catch(Response $r){ok(strpos($r->data['redirect'],'invalid_return')!==false,'Signed return cannot replay');}
response('forget');ok(response('status')->data['connected']===false,'Disconnect forgets encrypted credentials');ok(response('status')->data['returned']===null,'Old return hidden after disconnect');
$_POST=array('nonce'=>'good','job'=>$job);try{GE_Cards_Canva::pdf();}catch(Response $r){ok($r->getCode()===422,'Old export denied after disconnect');}
private_call('save_connection',array(array_merge($connection,array('connection_id'=>'connection2'))));ok(response('export_status',array('job'=>$job))->getCode()===422,'New connection cannot reuse previous export');
// Separate OAuth tabs must not replace each other's encrypted pending state.
$oauthA = str_repeat('a',43); $oauthB = str_repeat('b',43);
private_call('put',array('oauth',$oauthA,array('state'=>$oauthA,'expires'=>time()+600),600));
private_call('put',array('oauth',$oauthB,array('state'=>$oauthB,'expires'=>time()+600),600));
ok(private_call('get',array('oauth',$oauthA))['state']===$oauthA,'First OAuth tab preserved');
ok(private_call('get',array('oauth',$oauthB))['state']===$oauthB,'Second OAuth tab preserved');
$GLOBALS['path']='/tarjetas/canva/callback/'; $_GET=array('state'=>$oauthA,'error'=>'access_denied');
try{GE_Cards_Canva::route();}catch(Response $r){ok(strpos($r->data['redirect'],'canva_status=denied')!==false,'Cancelled OAuth is actionable');}
ok(private_call('get',array('oauth',$oauthB))['state']===$oauthB,'Cancellation preserves other OAuth tab');
$before=$GLOBALS['requests'];
try{GE_Cards_Canva::route();}catch(Response $r){ok(strpos($r->data['redirect'],'invalid_return')!==false,'Consumed OAuth cannot replay');}
ok($GLOBALS['requests']===$before,'Cancelled and repeated OAuth do not call Canva');
echo json_encode(array('passed'=>$passed,'wordpress_database_loaded'=>false,'network_calls'=>0,'real_credentials_used'=>false,'fixture_only'=>true)).PHP_EOL;
