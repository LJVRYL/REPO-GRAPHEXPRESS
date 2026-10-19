<?php
define('ABSPATH',__DIR__);require __DIR__.'/../../../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-deco-payments.php';
require __DIR__.'/../../../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-payments.php';
$checks=0;function ok($x,$name){global $checks;if(!$x)throw new RuntimeException($name);$checks++;}
function apply_filters($name,$value,...$args){return $name==='ge_wtp_order_payment_base'?GE_WTP_Deco_Payments::order_base($value,...$args):$value;}
function wc_get_price_decimals(){return 2;}
class WC_Order_Item_Fee{public $name='',$total=0;function set_name($x){$this->name=$x;}function get_name(){return $this->name;}function set_total($x){$this->total=$x;}function get_total(){return $this->total;}function set_amount($x){}function add_meta_data(...$x){}}
class Item{public $snapshot,$total;function __construct($s,$v){$this->snapshot=$s;$this->total=$v;}function get_meta(...$x){return $this->snapshot;}function get_total(){return $this->total;}}
class WC_Order{public $lines,$fees=[],$shipping,$method,$total;function __construct($lines,$shipping=0,$method='bacs'){$this->lines=$lines;$this->shipping=$shipping;$this->method=$method;$this->calculate_totals();}function get_items($type='line_item'){return $type==='fee'?$this->fees:$this->lines;}function get_total(){return $this->total;}function get_payment_method(){return $this->method;}function calculate_totals(...$x){$this->total=$this->shipping;foreach(array_merge($this->lines,$this->fees)as $item)$this->total+=$item->get_total();}function remove_item($id){unset($this->fees[$id]);}function add_item($fee){$this->fees[]=$fee;}function save(){}}
class Cart{public $lines;function __construct($x){$this->lines=$x;}function get_cart(){return $this->lines;}}
$policy=['checkout_pricing'=>['rule'=>'transfer-net-base-v1','approval_ref'=>'synthetic-reviewed-test']];
foreach([1,2,8,9,10,999,1000000,3267000,2000000000]as $base){$regular=GE_WTP_Deco_Payments::regular_cents($base,$policy);$net=(int)round($regular*.9);ok($net>=$base&&$net<=$base+1,'Rounded transfer preserves base');}
ok(GE_WTP_Deco_Payments::regular_cents(100,[])===100,'Legacy policy unchanged');
try{GE_WTP_Deco_Payments::regular_cents(100,['checkout_pricing'=>['rule'=>'wrong']]);throw new RuntimeException('Invalid rule accepted');}catch(InvalidArgumentException $e){ok(true,'Unreviewed rule rejected');}
$s=['retail_pricing'=>'transfer-net-base-v1','freight_policy'=>['frames_cents'=>0,'large_format_cents'=>0],'route'=>'digital'];$old=$s;unset($old['retail_pricing']);
$pure=new Cart([['ge_deco_snapshot'=>$s,'line_total'=>100]]);
ok(GE_WTP_Deco_Payments::cart_base(110,$pure,'bacs')===100.0,'Transfer discounts print only, preserves delivery');
ok(GE_WTP_Deco_Payments::cart_base(110,$pure,'woo-mercado-pago-basic')===0,'No second MP surcharge');
$mixed=new Cart([['ge_deco_snapshot'=>$s,'line_total'=>100],['line_total'=>50]]);
ok(GE_WTP_Deco_Payments::cart_base(160,$mixed,'bacs')===160.0,'Other goods retain native bank discount');
ok(GE_WTP_Deco_Payments::cart_base(160,$mixed,'woo-mercado-pago-basic')===60.0,'Other goods and delivery retain native MP rule');
ok(GE_WTP_Deco_Payments::cart_base(100,new Cart([['ge_deco_snapshot'=>$old,'line_total'=>100]]),'bacs')===0,'Legacy protected price unchanged');
$order=new WC_Order([new Item($s,100)],10);
GE_WTP_Payments::finalize_checkout_payment_adjustment($order);ok(abs($order->get_total()-100)<.001,'Bank net exact with delivery');
GE_WTP_Payments::finalize_checkout_payment_adjustment($order);ok(count($order->fees)===1&&abs($order->get_total()-100)<.001,'Repeated bank finalization idempotent');
$totals=GE_WTP_Deco_Payments::order_totals($order);ok($totals['bank_total']===100.0&&$totals['mp_total']===110.0&&$totals['mp_surcharge']===0.0,'Portal quotes restore regular price without surcharge');
$order->method='woo-mercado-pago-basic';GE_WTP_Payments::finalize_checkout_payment_adjustment($order);ok(abs($order->get_total()-110)<.001&&count($order->fees)===0,'Switch to MP removes bank discount exactly');
$order->method='bacs';GE_WTP_Payments::finalize_checkout_payment_adjustment($order);ok(abs($order->get_total()-100)<.001,'Switch back restores bank discount');
$ordinary=new WC_Order([new Item([],100)],10,'woo-mercado-pago-basic');GE_WTP_Payments::finalize_checkout_payment_adjustment($ordinary);ok(abs($ordinary->get_total()-121)<.001,'Unrelated MP orders unchanged');
ok(GE_WTP_Deco_Payments::order_totals($ordinary)===null,'Unrelated portal unchanged');
$artist=$s;$artist['artist_id']=3;ok(GE_WTP_Deco_Payments::cart_base(100,new Cart([['ge_deco_snapshot'=>$artist,'line_total'=>100]]),'bacs')===0,'Artist split pricing not bank discounted');
function WC(){global $wc;return $wc;}$wc=(object)['cart'=>$pure];
$gateways=['bacs'=>true,'woo-mercado-pago-basic'=>true];ok(GE_WTP_Deco_Payments::gateways($gateways)===['bacs'=>true],'Retail MP stays gated until real acceptance');
$wc->cart=new Cart([['line_total'=>100]]);ok(GE_WTP_Deco_Payments::gateways($gateways)===$gateways,'Other catalog gateways unchanged');
function is_cart(){global $screen;return $screen==='cart';}function is_checkout(){global $screen;return $screen==='checkout';}function is_order_received_page(){return false;}function is_checkout_pay_page(){return false;}function do_shortcode($x){return 'native:'.$x;}
$screen='cart';ok(GE_WTP_Deco_Payments::server_checkout_content('blocks')==='blocks','Unrelated cart remains unchanged');
$wc->cart=$pure;ok(GE_WTP_Deco_Payments::server_checkout_content('blocks')==='native:[woocommerce_cart]','Deco cart uses native server rendering');
$screen='checkout';ok(GE_WTP_Deco_Payments::server_checkout_content('blocks')==='native:[woocommerce_checkout]','Deco checkout uses native server rendering');
$screen='shop';ok(GE_WTP_Deco_Payments::server_checkout_content('product')==='product','Other pages unchanged');
echo json_encode(['passed'=>true,'checks'=>$checks,'network'=>false,'real_orders'=>0]).PHP_EOL;
