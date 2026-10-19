<?php
require dirname(__DIR__,3).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-deco-artist-pricing.php';
$count=0;
function ok($x){global $count;if(!$x)throw new RuntimeException('Artist pricing failed');$count++;}
function reject($fn){try{$fn();}catch(InvalidArgumentException $e){ok(true);return;}throw new RuntimeException('Invalid royalty accepted');}
$profile=GE_Deco_Artist_Pricing::profile(array('default'=>array('mode'=>'fixed','value'=>500000),'models'=>array('box'=>array('mode'=>'percent','value'=>1500))),array('box','wood'));
$q=GE_Deco_Artist_Pricing::quote(2000000,'wood',$profile,array('mode'=>'inherit'));ok($q['artist_cents']===500000&&$q['subtotal_cents']===2500000);ok($q['base_cents']===2000000);
$q=GE_Deco_Artist_Pricing::quote(2000000,'box',$profile,array('mode'=>'inherit'));ok($q['artist_cents']===300000);ok(!$q['payment_processing_included']&&!$q['sales_enabled']);
$q=GE_Deco_Artist_Pricing::quote(2000000,'box',$profile,array('mode'=>'fixed','value'=>0));ok($q['artist_cents']===0&&$q['subtotal_cents']===2000000);ok($q['base_cents']===2000000);
$q=GE_Deco_Artist_Pricing::quote(101,'box',$profile,array('mode'=>'percent','value'=>5000));ok($q['artist_cents']===51);
$q=GE_Deco_Artist_Pricing::quote(100000,'box',$profile,array('mode'=>'fixed','value'=>123));ok($q['artist_cents']===123);
reject(static function(){GE_Deco_Artist_Pricing::rule(array('mode'=>'fixed','value'=>-1));});reject(static function(){GE_Deco_Artist_Pricing::rule(array('mode'=>'fixed','value'=>12.34));});reject(static function(){GE_Deco_Artist_Pricing::rule(array('mode'=>'fixed','value'=>'100'));});reject(static function(){GE_Deco_Artist_Pricing::rule(array('mode'=>'percent','value'=>100001));});reject(static function(){GE_Deco_Artist_Pricing::quote(0,'box',array(),array('mode'=>'fixed','value'=>0));});reject(static function(){GE_Deco_Artist_Pricing::quote(100,'box',array(),array('mode'=>'inherit'));});reject(static function(){GE_Deco_Artist_Pricing::profile(array('default'=>array('mode'=>'fixed','value'=>0),'models'=>array('private-supplier-id'=>array('mode'=>'fixed','value'=>10))),array('box'));});
ok(GE_Deco_Artist_Pricing::rule(array('mode'=>'percent','value'=>0))['value']===0);echo json_encode(array('passed'=>$count,'zero_royalty_allowed'=>true,'physical_floor_preserved'=>true)).PHP_EOL;
