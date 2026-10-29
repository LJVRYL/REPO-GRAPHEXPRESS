<?php
define('ABSPATH',__DIR__);define('GE_WTP_PLUGIN_URL','https://example.invalid/');
$slug='windbanners-banderas-sublimadas';$removed=array();$added=array();$reference=501;
function add_action($h,$f=null,$p=10){$GLOBALS['added'][]=array($h,$f,$p);}function add_filter(){}function remove_action($h,$f,$p=10){$GLOBALS['removed'][]=array($h,$f,$p);}
function is_product(){return true;}function get_queried_object_id(){return 87;}function get_post_field($f,$id){return $GLOBALS['slug'];}
class GE_Product_Experience{}class GE_WTP_Catalog_Reviewed_Media{const META='_reviewed';}class GE_WTP_Registered_Templates{static function product_panel(){echo '<section data-private-template><a>Descargar plantilla</a></section>';}}
function get_post_meta($id,$key,$single){return array('wb-210'=>array('ids'=>array($GLOBALS['reference'])));}
function wp_get_attachment_image_url($id,$size){return 'https://example.invalid/'.$id.'/'.$size.'.webp';}function esc_url($s){return htmlspecialchars($s,ENT_QUOTES);}function esc_attr($s){return esc_url($s);}function esc_html($s){return esc_url($s);}
function wp_get_attachment_image($id,$size,$icon=false,$attrs=array()){return '<img data-fixture-id="'.$id.'">';}
class FlagsProductFixture{function get_id(){return 87;}function get_slug(){return $GLOBALS['slug'];}function get_image_id(){return 601;}}
require __DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-flags-product-experience.php';$checks=0;function ok($v){global $checks;if(!$v)throw new Exception('Check '.($checks+1));$checks++;}
$product=new FlagsProductFixture();ok(GE_WTP_Flags_Product_Experience::is_product());ok(in_array('gx-flags-product',GE_WTP_Flags_Product_Experience::body_class(array()),true));GE_WTP_Flags_Product_Experience::layout();ok(in_array(array('woocommerce_single_product_summary',array('GE_WTP_Catalog_Reviewed_Media','gallery'),26),$removed,true));ok(in_array(array('woocommerce_single_product_summary',array('GE_WTP_Registered_Templates','product_panel'),28),$removed,true));
ob_start();GE_WTP_Flags_Product_Experience::media();$html=ob_get_clean();ok(strpos($html,'501/full.webp')!==false);ok(strpos($html,'601/full.webp')!==false);ok(substr_count($html,'data-gx-flags-thumb ')===2);ok(strpos($html,'Mockup ilustrativo')!==false);
ob_start();GE_WTP_Flags_Product_Experience::resources($product,array());$resources=ob_get_clean();ok(strpos($resources,'<details')===0);ok(strpos($resources,'Descargar plantilla')!==false);
$slug='another-product';$removed=array();GE_WTP_Flags_Product_Experience::layout();ok(!$removed);ok(!in_array('gx-flags-product',GE_WTP_Flags_Product_Experience::body_class(array()),true));ob_start();GE_WTP_Flags_Product_Experience::resources($product,array());ok(ob_get_clean()==='');
echo json_encode(array('status'=>'PASS','checks'=>$checks,'scope'=>'banderas only','real_records_modified'=>false)).PHP_EOL;
