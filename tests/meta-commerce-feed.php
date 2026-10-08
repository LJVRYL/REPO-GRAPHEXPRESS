<?php
define('ABSPATH', __DIR__);
function add_action(){} function add_filter(){}
final class GE_WTP_Roll_Pricing { public static function billable_width($requested,$widths){foreach($widths as $width){if($width >= $requested)return $width;}return 0;} }
require dirname(__DIR__).'/wp-content/mu-plugins/ge-meta-commerce-feed.php';
function verify($test,$label){if(!$test)throw new RuntimeException($label);echo "PASS: $label\n";}
$offer=GE_Meta_Commerce_Feed::storefront_offer(array('min_qty'=>100,'options'=>array('printed'=>array('price'=>23.45,'label'=>'Impreso'))));
verify($offer['amount']===2837.45,'minimum order total includes VAT and preserves unit cents');
$offer=GE_Meta_Commerce_Feed::storefront_offer(array('mode'=>'m2','roll_widths_cm'=>array(104,150),'options'=>array('m2'=>array('price'=>17940,'label'=>'A medida'))));
verify($offer['amount']===22576.18 && strpos($offer['label'],'104 cm')!==false,'vinyl uses full billable roll width');
verify(GE_Meta_Commerce_Feed::storefront_offer(array('options'=>array('zero'=>array('price'=>0))))===null,'zero WooCommerce price never becomes a public offer');
verify(GE_Meta_Commerce_Feed::storefront_offer(array('mode'=>'m2','roll_widths_cm'=>array(60),'options'=>array('m2'=>array('price'=>20))))===null,'unsupported default width is excluded');
$defaults=GE_Meta_Commerce_Feed::digital_defaults(array('fields'=>array(
 array('key'=>'papel','type'=>'select','default'=>'350','options'=>array(array('value'=>'300'),array('value'=>'350'))),
 array('key'=>'laminado','type'=>'select','options'=>array(array('value'=>'none','when'=>array('papel'=>'300')),array('value'=>'mate','when'=>array('papel'=>'350')))),
 array('key'=>'cantidad','type'=>'number','min'=>5,'default'=>5),array('key'=>'complejidad','type'=>'checkbox')
)));
verify($defaults===array('papel'=>'350','laminado'=>'mate','cantidad'=>5,'complejidad'=>false),'digital defaults obey dependent options and optional surcharge stays off');
