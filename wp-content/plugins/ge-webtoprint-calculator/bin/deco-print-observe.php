<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
// Invoke through the existing WP-CLI --url context; no independent DB credentials.
if(!defined('ABSPATH')||!class_exists('GE_WTP_Digital_Catalog'))exit(2);
if(get_woocommerce_currency()!=='ARS')exit(3);
$result=array('observed_at'=>time(),'currency'=>'ARS','read_only'=>true);
foreach(array('digital-bajadas-digitales-color','papel-fotografico-260') as $key){
 global $wpdb;$ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID=m.post_id WHERE p.post_type='product' AND p.post_status IN ('publish','draft','private') AND m.meta_key='_ge_public_catalog_key' AND m.meta_value=%s LIMIT 2",$key));
 if(count($ids)!==1)exit(4);$id=(int)$ids[0];
 if($key==='digital-bajadas-digitales-color'){
  foreach(array('a4-plus','a3-plus') as $size){$q=GE_WTP_Digital_Catalog::commercial_quote_price($id,array('tamano'=>$size,'cantidad'=>1,'papel'=>'ilustracion-150','impresion'=>'frente','laminado'=>'sin-laminar','terminacion'=>'sin-terminacion','pleno'=>'1'));if(is_wp_error($q)||!is_numeric($q['price']??null)||$q['price']<=0)exit(5);$result[$size.'_cents']=(int)round($q['price']*100);}
 }else{$config=GE_WTP_Storefront::config($id);$price=$config['options']['m2']['price']??0;if(!is_numeric($price)||$price<=0)exit(6);$result['gf_m2_cents']=(int)round($price*100);}
}
echo "\nGRAPHEX_DECO_PRINT_OBSERVATION\n".wp_json_encode($result)."\n";
