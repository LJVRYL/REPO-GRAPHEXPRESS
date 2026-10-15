<?php
/** Isolated renderer regression: no real users, orders, network, mail or storage writes. */
define('ABSPATH', __DIR__ . '/');
function add_action($hook,$callback,$priority=10,$args=1){}
function add_filter($hook,$callback,$priority=10,$args=1){}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return esc_html($v);}
function wp_kses_post($v){return $v;}
function wc_price($n,$args=[]){return '$ '.number_format((float)$n,2,'.',',');}
function get_page_by_path($p){return false;}
function home_url($p=''){return 'https://example.test'.$p;}
function is_user_logged_in(){return false;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
class WC_Order {
 public $meta=[];
 public function get_meta($k,$single=true){return $this->meta[$k]??'';}
 public function get_id(){return 7;}
 public function get_order_number(){return 'H7';}
 public function get_items(){return [new Historical_Test_Item()];}
 public function get_subtotal(){return 10000;}
 public function get_currency(){return 'ARS';}
 public function get_total_tax(){return 2100;}
 public function get_formatted_order_total(){return '$ 12,100.00';}
 public function get_billing_company(){return 'Receptor de prueba';}
}
class Historical_Test_Item {
 public function get_name(){return 'Factura B <script>alert(1)</script>';}
 public function get_quantity(){return 1;}
 public function get_total(){return 10000;}
 public function get_id(){return 9;}
}
class GE_WTP_Documents {
 public static $docs=[];
 public static function get_documents($id){return self::$docs;}
 public static function customer_visible($d){return empty($d['private']);}
 public static function download_url($oid,$id){return 'https://example.test/download?order='.$oid.'&doc='.rawurlencode($id);}
}
class GE_WTP_Customer_Branches {public static function render_order_summary($o){throw new RuntimeException('Historical view must not invoke active logistics forms');}}
require $argv[1] ?? dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-portal.php';
$checks=0;function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$live=new WC_Order();$old=new WC_Order();$old->meta=['_ge_historical_import_case'=>'synthetic-import','_ge_markcom_reference'=>'Histórico <img src=x onerror=alert(1)>','_ge_historical_evidence'=>['entrega'=>'Entrega <script>bad</script>','pagos'=>'PRIVATE BANK DETAIL']];
check(!GE_WTP_Portal::is_historical($live),'Ordinary orders must remain ordinary');
check(GE_WTP_Portal::is_historical($old),'Imported marker must select history');
check(!GE_WTP_Portal::is_historical(null),'Null is not historical');
check(GE_WTP_Portal::historical_needs_payment(true,$live)===true,'Keep ordinary payable orders payable');
check(GE_WTP_Portal::historical_needs_payment(false,$live)===false,'Keep ordinary paid orders nonpayable');
check(GE_WTP_Portal::historical_needs_payment(true,$old)===false,'Imported uncertain history must not request another payment');
check(GE_WTP_Portal::historical_needs_payment(true,null)===true,'Do not alter foreign callers');
$render=new ReflectionMethod('GE_WTP_Portal','render_order_detail');$render->setAccessible(true);
ob_start();$render->invoke(null,$old);$empty=ob_get_clean();
check(strpos($empty,'Factura no localizada; revisar emisión.')!==false,'Missing original is not asserted as unissued');
check(strpos($empty,'No se encontró un archivo original')!==false,'Missing file is visible');
check(strpos($empty,'<script>')===false&&strpos($empty,'<img src=x')===false,'Escape customer/source markup');
check(strpos($empty,'PRIVATE BANK DETAIL')===false,'Do not expose internal payment evidence');
foreach(['<form','order-pay','Mercado Pago','Elegí cómo pagar','Volver a pedir','Confirmá cada archivo','ge_customer_item_artwork_upload'] as $forbidden)check(strpos($empty,$forbidden)===false,'Historical view has no action: '.$forbidden);
GE_WTP_Documents::$docs=[['id'=>'invoice','name'=>'1152.pdf','category'=>'factura'],['id'=>'model','name'=>'MODELO <svg>.pdf','category'=>'arte','order_item_id'=>9],['id'=>'cai','name'=>'CAI.pdf','category'=>'otro','order_item_id'=>9],['id'=>'private','name'=>'INTERNAL-ONLY.pdf','private'=>true,'category'=>'otro']];
$old->meta['_ge_historical_invoice_state']='facturado_original';
ob_start();$render->invoke(null,$old);$with_files=ob_get_clean();
check(strpos($with_files,'Factura original disponible')!==false,'Original invoice is explicit');
check(strpos($with_files,'1152.pdf')!==false&&strpos($with_files,'CAI.pdf')!==false,'Invoice and item CAI are downloadable');
check(strpos($with_files,'MODELO &lt;svg&gt;.pdf')!==false,'Reference model is linked with escaped name');
check(strpos($with_files,'INTERNAL-ONLY.pdf')===false,'Private files remain hidden');
$old->meta['_ge_historical_invoice_state']='factura1211_mencionada_sin_original';check(strpos(GE_WTP_Portal::historical_invoice_label($old),'antes de volver a facturar')!==false,'Known invoice reference prevents double billing');
echo "PASS $checks historical portal checks\n";
