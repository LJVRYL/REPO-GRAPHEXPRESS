<?php
// Protocol fixtures only: no WordPress, database, network or real credentials.
define('ABSPATH',__DIR__.'/');
require getenv('GE_CARDS_CANVA_CLIENT') ?: dirname(__DIR__).'/wp-content/mu-plugins/ge-cards-canva/class-ge-cards-canva-client.php';
$clock=1700000000;$calls=0;$owner='user1';$team='team1';$pages=2;$download='https://export-download.canva.com/test.pdf';$export_calls=0;
$transport=function($method,$url,$headers,$body)use(&$calls,&$owner,&$team,&$pages,&$download,&$export_calls){
 $calls++;if(parse_url($url,PHP_URL_HOST)!=='api.canva.com')throw new RuntimeException('External host');
 if(strpos($url,'/oauth/token')!==false)return array('access_token'=>'fixture-access','refresh_token'=>'fixture-refresh','expires_in'=>14400,'token_type'=>'Bearer');
 if(strpos($url,'/users/me')!==false)return array('team_user'=>array('user_id'=>'user1','team_id'=>'team1'));
 if(strpos($url,'/designs?')!==false)return array('items'=>array(array('id'=>'design1','title'=>'Prueba','owner'=>array('user_id'=>'user1')),array('id'=>'design2','title'=>'Ajeno','owner'=>array('user_id'=>'other'))));
 if(strpos($url,'/designs/')!==false)return array('design'=>array('id'=>'design1','page_count'=>$pages,'owner'=>array('user_id'=>$owner,'team_id'=>$team),'urls'=>array('edit_url'=>'https://www.canva.com/api/design/fixture/edit?existing=1')));
 if($method==='POST'&&substr($url,-8)==='/exports'){$export_calls++;$data=json_decode($body,true);if($data['format']!==array('type'=>'pdf','export_quality'=>'pro','pages'=>array(1,2)))throw new RuntimeException('Unexpected export format');return array('job'=>array('id'=>'job1'));}
 if(strpos($url,'/exports/')!==false)return array('job'=>array('status'=>'success','urls'=>array($download)));
 throw new RuntimeException('Unexpected fixture request');
};
$client=new GE_Cards_Canva_Client('fixture-client',str_repeat('x',32),'https://graphex.ar/tarjetas/canva/callback/',$transport,function()use(&$clock){return $clock;});
$passed=0;function ok($value,$name){global $passed;if(!$value)throw new RuntimeException($name);$passed++;}
function denied($callback,$name){try{$callback();throw new LogicException($name);}catch(RuntimeException $e){ok(true,$name);}}
$start=$client->start();parse_str(parse_url($start['url'],PHP_URL_QUERY),$auth);
ok($auth['scope']==='design:meta:read design:content:read','Only approved scopes');ok($auth['code_challenge_method']==='S256','PKCE S256');
ok($auth['code_challenge']===rtrim(strtr(base64_encode(hash('sha256',$start['flow']['verifier'],true)),'+/','-_'),'='),'PKCE valid');
$before=$calls;denied(function()use($client,$start){$client->callback($start['flow'],'wrong','code');},'CSRF denied');ok($calls===$before,'Invalid state makes no request');
$expired=$start['flow'];$expired['expires']=$clock-1;denied(function()use($client,$expired){$client->callback($expired,$expired['state'],'code');},'Expired OAuth denied');
$context=$client->callback($start['flow'],$start['flow']['state'],'fixture-code');ok($context['identity']['user_id']==='user1','Identity verified');
$list=$client->designs($context);ok(count($list['items'])===1,'Foreign designs filtered');denied(function()use($client,&$context){$client->designs($context,str_repeat('a',2049));},'Pagination bounded');
$edit=$client->edit($context,'design1');ok(strpos($edit['url'],'existing=1&correlation_state=')!==false,'Correlation preserves existing query');
$owner='other';denied(function()use($client,&$context){$client->edit($context,'design1');},'Other owner denied');$owner='user1';
$team='other';denied(function()use($client,&$context){$client->edit($context,'design1');},'Other team denied');$team='team1';
$keypair=sodium_crypto_sign_seed_keypair(str_repeat('s',32));$secret=sodium_crypto_sign_secretkey($keypair);$public=sodium_crypto_sign_publickey($keypair);
function b64($data){return rtrim(strtr(base64_encode($data),'+/','-_'),'=');}
function signed($payload,$header=null){global $secret;$header=$header?:array('alg'=>'EdDSA','kid'=>'test-key');$a=b64(json_encode($header));$b=b64(json_encode($payload));return $a.'.'.$b.'.'.b64(sodium_crypto_sign_detached($a.'.'.$b,$secret));}
$jwks=array('keys'=>array(array('kid'=>'test-key','kty'=>'OKP','crv'=>'Ed25519','alg'=>null,'x'=>b64($public))));
$payload=array('aud'=>'fixture-client','exp'=>$clock+600,'sub'=>'user1','team_id'=>'team1','type'=>'rti','jti'=>'fixture-jti','design_id'=>'design1','correlation_state'=>$edit['pending']['correlation_state']);
$return=$client->verify_return($context,signed($payload),$jwks,$edit['pending']);ok($return['design_id']==='design1','Real Ed25519 signature accepted');
foreach(array('aud'=>'other','exp'=>$clock-1,'sub'=>'other','team_id'=>'other','type'=>'wrong','correlation_state'=>'wrong','design_id'=>'other') as $claim=>$value){$bad=$payload;$bad[$claim]=$value;denied(function()use($client,&$context,$bad,$jwks,$edit){$client->verify_return($context,signed($bad),$jwks,$edit['pending']);},'Wrong claim '.$claim);}
$bad=$payload;unset($bad['jti']);denied(function()use($client,&$context,$bad,$jwks,$edit){$client->verify_return($context,signed($bad),$jwks,$edit['pending']);},'Missing required claim');
foreach(array(array('alg'=>'none','kid'=>'test-key'),array('alg'=>'RS256','kid'=>'test-key'),array('alg'=>'EdDSA','kid'=>'unknown'),array('alg'=>'EdDSA','kid'=>'test-key','crit'=>array('x'))) as $header){denied(function()use($client,&$context,$payload,$header,$jwks,$edit){$client->verify_return($context,signed($payload,$header),$jwks,$edit['pending']);},'Unsupported JWT header');}
$jwt=signed($payload);$parts=explode('.',$jwt);$parts[2]=b64(str_repeat('0',64));denied(function()use($client,&$context,$parts,$jwks,$edit){$client->verify_return($context,implode('.',$parts),$jwks,$edit['pending']);},'Altered signature denied');
$pages=1;denied(function()use($client,&$context){$client->export($context,'design1',2);},'Page count matches configuration');ok($export_calls===0,'Invalid pages never start export');$pages=2;
$job=$client->export($context,'design1',2);ok($export_calls===1,'Exactly one export');$result=$client->poll($context,$job);ok($result['status']==='ready','Export ready');
$before=$calls;ok($client->poll($context,$job)===$result&&$calls===$before,'Ready job does not poll again');
$pending=$job;$pending['result']=null;$pending['last_poll']=$clock;ok($client->poll($context,$pending)['status']==='in_progress'&&$calls===$before,'Polling limited to three seconds');
foreach(array('http://export-download.canva.com/file.pdf','https://attacker.example/file.pdf','https://user:secret@export-download.canva.com/file.pdf','https://export-download.canva.com:8443/file.pdf') as $url){$download=$url;$job['result']=null;$job['last_poll']=0;denied(function()use($client,&$context,&$job){$client->poll($context,$job);},'Download host rejected');}
$job['expires']=$clock-1;denied(function()use($client,&$context,&$job){$client->poll($context,$job);},'Expired export denied');
$context['tokens']['expires']=$clock-1;$before=$calls;$client->designs($context);ok($calls===$before+2,'Expired token refreshes before request');
denied(function(){GE_Cards_Canva_Client::id('../other');},'Path injection denied');
ok(!GE_Cards_Canva_Client::https_host('https://api.canva.com.evil.example/a',array('api.canva.com')),'Host suffix denied');
echo json_encode(array('passed'=>$passed,'network_calls'=>0,'real_credentials_used'=>false,'wordpress_loaded'=>false)).PHP_EOL;
