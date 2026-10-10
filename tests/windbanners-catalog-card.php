<?php
require __DIR__.'/windbanners-reconciliation.php';
function get_the_terms(){return array();}function is_wp_error(){return false;}function sanitize_html_class($s){return $s;}function wc_product_class(){}function the_permalink(){}function the_title(){}function esc_html($s){return $s;}function wp_kses_post($s){return $s;}function wp_trim_words($s){return $s;}function wc_price($p,$a){return number_format($p,$a['decimals'],'.','');}
class WC_Product {
    public $id;public function __construct($id){$this->id=$id;}public function is_visible(){return true;}public function get_id(){return $this->id;}public function get_attributes(){return array();}public function get_meta($k){return get_post_meta($this->id,$k,true);}public function get_image_id(){return 0;}public function get_short_description(){return '';}
}
function render_card($id){global $product;$product=new WC_Product($id);ob_start();include __DIR__.'/../wp-content/themes/graphexpress-child/woocommerce/content-product.php';return ob_get_clean();}
$issuers=array(array('id'=>'common-c','active'=>true,'default_for_scenarios'=>array('common'),'vat_status'=>'monotributo','invoice_types_allowed'=>array('C'),'relationship_confirmed'=>true,'verification_status'=>'verified','verified_at'=>gmdate('c',time()-60),'tax_rate_basis_points'=>0));$tax_enabled=false;
$fixture[1001]=array('_ge_public_catalog_key'=>'windbanners-banderas-sublimadas');
$html=render_card(1001);ok(strpos($html,'8307.00')!==false,'Wind card preserves two decimals');ok(strpos($html,'Total final · comprobante C')!==false&&strpos($html,'+ IVA')===false,'Wind C card no extra IVA');
$issuers[0]['vat_status']='registered';$issuers[0]['invoice_types_allowed']=array('B');$issuers[0]['tax_rate_basis_points']=2100;
$html=render_card(1001);ok(strpos($html,'10051.47')!==false&&strpos($html,'Total final con IVA')!==false,'registered issuer card applies explicit rate once');
$issuers[0]['verification_status']='unverified';$html=render_card(1001);ok(strpos($html,'gx-product-reference-price')===false,'unverified card hides automatic price');
$fixture[1002]=array('_ge_storefront_config'=>array('options'=>array('legacy'=>array('price'=>1234.56))));$html=render_card(1002);ok(strpos($html,'1235')!==false&&strpos($html,'+ IVA')!==false,'legacy card remains unchanged');
echo "\nPASS catalog card fiscal scenarios\n";
