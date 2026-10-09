<?php
define('ABSPATH',__DIR__.'/');
require getenv('GE_CANVA_PREFLIGHT') ?: dirname(__DIR__).'/wp-content/mu-plugins/ge-cards-canva/class-ge-cards-canva-preflight.php';
$passed=0;function check_profile($value){global $passed;if(!$value)throw new RuntimeException('Card profile fixture failed');$passed++;}
$base=array('status'=>'complete','facts'=>array('page_count'=>2,'page_sizes'=>array(array('page'=>1,'mm'=>array(95,65)),array('page'=>2,'mm'=>array(95,65))),'encrypted'=>false,'fonts_embedded'=>true,'images'=>array()));
$r=GE_Cards_Canva_Preflight::evaluate($base,2);check_profile($r['status']==='review_required');check_profile($r['production_approved']===false);check_profile(in_array('safe_area',$r['unverified'],true));
$bad=$base;$bad['facts']['page_sizes'][1]['mm']=array(88.9,50.8);check_profile(in_array('page_dimensions',GE_Cards_Canva_Preflight::evaluate($bad,2)['blockers'],true));
$bad=$base;array_pop($bad['facts']['page_sizes']);check_profile(in_array('page_geometry_unverified',GE_Cards_Canva_Preflight::evaluate($bad,2)['blockers'],true));
check_profile(in_array('page_count',GE_Cards_Canva_Preflight::evaluate($base,1)['blockers'],true));
$bad=$base;$bad['facts']['images']=array(array('type'=>'image','effective_dpi'=>array(299,300)));check_profile(in_array('image_resolution_below_300',GE_Cards_Canva_Preflight::evaluate($bad,2)['blockers'],true));
$bad=$base;$bad['facts']['fonts_embedded']=false;check_profile(in_array('fonts_not_embedded',GE_Cards_Canva_Preflight::evaluate($bad,2)['blockers'],true));
$bad=$base;$bad['facts']['fonts_embedded']=null;check_profile(in_array('fonts_embedded',GE_Cards_Canva_Preflight::evaluate($bad,2)['unverified'],true));
check_profile(GE_Cards_Canva_Preflight::evaluate(array('status'=>'queued'),2)['status']==='pending');
check_profile(GE_Cards_Canva_Preflight::evaluate(array('status'=>'failed'),2)['status']==='blocked');
echo json_encode(array('passed'=>$passed,'fixture_only'=>true,'network_calls'=>0)).PHP_EOL;