<?php
require_once __DIR__.'/../../../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-deco-marketplace-settlement.php';
$checks=0;
function check_settlement($yes) { global $checks; if(!$yes)throw new RuntimeException('settlement assertion failed');$checks++; }
function rejected_settlement($callback) { try{$callback();}catch(InvalidArgumentException $e){check_settlement(true);return;}throw new RuntimeException('expected rejection'); }
$fee=array('rate_bps'=>1000,'fixed_cents'=>0,'verified_at'=>100000,'valid_until'=>100100,'evidence_ref'=>'qa-only','revision'=>'qa-v1','collector_id'=>'123456','payment_method_id'=>'account_money');
$quote=GE_Deco_Marketplace_Settlement::quote(2000000,500000,350000,$fee,100000);
check_settlement($quote['total_cents']===3166667);
check_settlement($quote['processing_cents']===316667);
check_settlement($quote['artist_cents']===500000);
check_settlement($quote['graphex_cents']===2350000);
check_settlement($quote['processing_payer']==='graphex');
$actual=array('total_cents'=>$quote['total_cents'],'processing_cents'=>$quote['processing_cents'],'marketplace_cents'=>$quote['graphex_cents'],'refunded_cents'=>0,'currency'=>'ARS','collector_id'=>'123456','payment_method_id'=>'account_money','status'=>'approved','accredited_at'=>'verified-date');
check_settlement(GE_Deco_Marketplace_Settlement::reconcile($quote,$actual)==='paid');
foreach(array('collector_id'=>'999999','currency'=>'USD','total_cents'=>$quote['total_cents']+1,'marketplace_cents'=>1,'processing_cents'=>1,'payment_method_id'=>'visa') as $key=>$value){$bad=$actual;$bad[$key]=$value;rejected_settlement(static function()use($quote,$bad){GE_Deco_Marketplace_Settlement::reconcile($quote,$bad);});}
$actual['refunded_cents']=1;check_settlement(GE_Deco_Marketplace_Settlement::reconcile($quote,$actual)==='reversed');
$actual['refunded_cents']=0;$actual['status']='pending';check_settlement(GE_Deco_Marketplace_Settlement::reconcile($quote,$actual)==='pending');
$actual['status']='approved';$actual['accredited_at']=null;check_settlement(GE_Deco_Marketplace_Settlement::reconcile($quote,$actual)==='unpaid');
rejected_settlement(static function()use($fee){GE_Deco_Marketplace_Settlement::quote(2000000,500000,350000,$fee,100101);});
foreach(array('rate_bps'=>5000,'verified_at'=>100001,'fixed_cents'=>-1,'payment_method_id'=>'visa','evidence_ref'=>'') as $key=>$value){$bad=$fee;$bad[$key]=$value;rejected_settlement(static function()use($bad){GE_Deco_Marketplace_Settlement::quote(1000,100,0,$bad,100000);});}
$free=GE_Deco_Marketplace_Settlement::quote(1000,0,0,$fee,100000);check_settlement(!$free['split_required']&&$free['graphex_cents']>=1000);
$zero=$fee;$zero['rate_bps']=0;check_settlement(GE_Deco_Marketplace_Settlement::quote(1000,100,0,$zero,100000)['total_cents']===1100);
for($i=1;$i<=100;$i++){$q=GE_Deco_Marketplace_Settlement::quote($i*137,299,350000,$fee,100000);check_settlement($q['graphex_cents']>=$i*137+350000);}
echo json_encode(array('passed'=>$checks,'live_payments'=>0,'fee_policy'=>'synthetic QA only')).PHP_EOL;
