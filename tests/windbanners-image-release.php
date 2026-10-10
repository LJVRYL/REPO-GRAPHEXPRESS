<?php
define('ABSPATH', __DIR__.'/');
define('GE_WTP_PLUGIN_DIR', __DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/');
function sanitize_title($s){return trim(preg_replace('/[^a-z0-9]+/','-',strtolower($s)),'-');}
function apply_filters($tag,$value,...$args){return $value;}
class WP_Error {public $code; function __construct($code,$message){$this->code=$code;}}
function get_posts($args){global $ids,$ambiguous; $key=$args['meta_value'];return $key===$ambiguous?array(1,2):array($ids[$key]);}
function get_post_meta($id,$key,$single=true){global $skus;return $skus[$id];}
function get_post_thumbnail_id($id){return $id+1000;}
function wp_upload_bits(...$args){throw new Exception('Preflight must finish before uploading any image');}
require GE_WTP_PLUGIN_DIR.'includes/class-ge-wtp-windbanners-catalog.php';
$ids=$skus=$expected=array();$last='';$i=0;
foreach(GE_WTP_Windbanners_Catalog::products() as $key=>$data){if(empty($data['options']))continue;$i++;$last='windbanners-'.$key;$ids[$last]=$i;$skus[$i]=$data['sku'];$expected[$i]=$i+1000;}
function check($error,$code){if(!($error instanceof WP_Error)||$error->code!==$code)throw new Exception('Expected '.$code);}
$ambiguous=$last;check(GE_WTP_Windbanners_Catalog::sync_images($expected),'ge_image_mapping');
$ambiguous='';$original=$skus[$i];$skus[$i]='wrong';check(GE_WTP_Windbanners_Catalog::sync_images($expected),'ge_image_drift');$skus[$i]=$original;
$expected[$i]++;check(GE_WTP_Windbanners_Catalog::sync_images($expected),'ge_image_drift');unset($expected[$i]);check(GE_WTP_Windbanners_Catalog::sync_images($expected),'ge_image_drift');
echo "PASS 4 image release guards, including late-family drift before any uploads\n";
