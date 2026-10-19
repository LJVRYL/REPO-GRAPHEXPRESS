<?php
require_once __DIR__.'/../../../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-deco-mp-signature.php';
$checks=0;
function signature_ok($yes){global $checks;if(!$yes)throw new RuntimeException('signature assertion failed');$checks++;}
function signature_denied($fn){try{$fn();}catch(InvalidArgumentException $e){signature_ok(true);return;}throw new RuntimeException('signature accepted unexpectedly');}
$secret='synthetic-private-webhook-key';$now=1792390000;$id='123456';$request='qa-request-1';
function signed_header($id,$request,$ts,$key){return 'ts='.$ts.',v1='.hash_hmac('sha256','id:'.$id.';request-id:'.$request.';ts:'.$ts.';',$key);}
$header=signed_header($id,$request,$now,$secret);$delivery=GE_Deco_MP_Signature::verify($header,$request,$id,$secret,$now);
signature_ok(strlen($delivery)===64);
signature_ok(GE_Deco_MP_Signature::verify($header,$request,$id,$secret,$now)===$delivery);
signature_ok(GE_Deco_MP_Signature::verify(signed_header($id,'qa-request-2',$now,$secret),'qa-request-2',$id,$secret,$now)!==$delivery);
signature_ok(strlen(GE_Deco_MP_Signature::verify(signed_header($id,$request,$now*1000,$secret),$request,$id,$secret,$now))===64);
foreach(array('',str_repeat('x',513),$header.',ts='.$now,'ts='.$now.',v1=x',signed_header($id,$request,$now-301,$secret),signed_header($id,$request,$now+301,$secret))as $bad){signature_denied(static function()use($bad,$request,$id,$secret,$now){GE_Deco_MP_Signature::verify($bad,$request,$id,$secret,$now);});}
foreach(array('654321','1;request-id:x','https://example.com','01','0')as $bad){signature_denied(static function()use($bad,$header,$request,$secret,$now){GE_Deco_MP_Signature::verify($header,$request,$bad,$secret,$now);});}
signature_denied(static function()use($header,$id,$secret,$now){GE_Deco_MP_Signature::verify($header,'another-request',$id,$secret,$now);});
signature_denied(static function()use($header,$request,$id,$now){GE_Deco_MP_Signature::verify($header,$request,$id,'another-secret-key',$now);});
echo json_encode(array('passed'=>$checks,'live_webhooks'=>0)).PHP_EOL;
