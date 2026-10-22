<?php
/** Real domain consumers with synthetic Woo storage; never connects to WordPress or production. */
define('ARRAY_A','ARRAY_A');define('ABSPATH',__DIR__.'/');define('MINUTE_IN_SECONDS',60);define('DAY_IN_SECONDS',86400);
$GLOBALS['orders']=array();$GLOBALS['options']=array();$GLOBALS['actor']=1;$GLOBALS['checks']=0;$GLOBALS['notes']=array();$GLOBALS['uuid']=0;
function check_native($yes,$label){if(!$yes)throw new RuntimeException($label);$GLOBALS['checks']++;}
function reject_native($f,$label){try{$f();}catch(Throwable $e){$GLOBALS['checks']++;return;}throw new RuntimeException('Unexpectedly allowed: '.$label);}
function native_copy($x){return unserialize(serialize($x));}
function add_action($h,$f,$p=10,$a=1){}function add_filter($h,$f,$p=10,$a=1){if($h==='query')$GLOBALS['query_guard']=$f;}function remove_filter($h,$f,$p=10){if($h==='query')unset($GLOBALS['query_guard']);}function wp_cache_delete($key,$group=''){}
function get_current_user_id(){return $GLOBALS['actor'];}
function get_option($key,$default=false){return $key===GE_Organization_Runtime::BINDING?array('bound'=>true):($GLOBALS['options'][$key]??$default);}
function get_user_meta($id,$key,$single=true){return $id===99?'foreign':'';}
function absint($x){return abs((int)$x);}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($x,$f=0){return json_encode($x,$f);}
function wc_format_decimal($x,$n=2){return number_format((float)$x,$n,'.','');}
function clean_post_cache($id){}
function wp_generate_uuid4(){return sprintf('00000000-0000-4000-8000-%012d',++$GLOBALS['uuid']);}
class WP_Error{public $code,$message;function __construct($c,$m){$this->code=$c;$this->message=$m;}function get_error_message(){return $this->message;}}
class GE_Organization{const PRIMARY='graph-express';static function can($org,$actor,$write){return $actor===1;}}
class GE_Organization_Runtime{const BINDING='binding';static function binding(){return array('bound'=>true);}static function allowed($module,$write=false,$actor=0){return ($actor?:$GLOBALS['actor'])===1 && $module!==($GLOBALS['disabled']??'');}}
class GE_WTP_Staff_Portal{static function can_access(){return $GLOBALS['actor']===1;}}
class GE_WTP_Customer_Invoices{static function staff($actor,$write=false){return $actor===1;}}
class GE_WTP_Payment_Policy{const META='_policy';static function order($order){return array('kind'=>'deposit','percent'=>50);}}
class SyntheticNativeItem {
    public $id,$owner,$meta=array(),$quantity=1,$total='123.45',$name='Trabajo sintÃƒÆ’Ã‚Â©tico';
    function __construct($id,$owner){$this->id=$id;$this->owner=$owner;}
    function get_id(){return $this->id;}function get_name(){return $this->name;}function get_product_id(){return 0;}
    function get_quantity(){return $this->quantity;}function get_total(){return $this->total;}
    function get_meta($key,$single=true){return $this->meta[$key]??'';}function meta_exists($key){return array_key_exists($key,$this->meta);}
    function update_meta_data($key,$v){$this->meta[$key]=$v;}function delete_meta_data($key){unset($this->meta[$key]);}
    function read_meta_data($force=false){}
    function save_meta_data(){return $this->save();}
    function save(){$GLOBALS['orders'][$this->owner]['items'][$this->id]=native_copy($this);return $this->id;}
}
class WC_Order {
    public $id,$d;
    function __construct($id){$this->id=$id;$this->d=native_copy($GLOBALS['orders'][$id]);}
    function get_id(){return $this->id;}function get_customer_id(){return $this->d['customer'];}function get_currency(){return $this->d['currency'];}
    function get_created_via(){return $this->d['created_via']??'';}function get_total(){return $this->d['total'];}function get_status(){return $this->d['status'];}
    function get_meta($key,$single=true){return $this->d['meta'][$key]??'';}function meta_exists($key){return array_key_exists($key,$this->d['meta']);}
    function update_meta_data($key,$v){$this->d['meta'][$key]=$v;}function delete_meta_data($key){unset($this->d['meta'][$key]);}
    function get_items($type='line_item'){return $this->d['items'];}function get_item($id){return $this->d['items'][$id]??false;}
    function is_paid(){return !empty($this->d['is_paid']);}function get_transaction_id(){return $this->d['transaction_id']??'';}
    function get_date_paid(){return !empty($this->d['paid_date'])?new DateTime('@'.$this->d['paid_date']):null;}
    function get_date_modified(){return new DateTime('@100');}
    function read_meta_data($force=false){}
    function save_meta_data(){return $this->save();}
    function save(){if(!empty($GLOBALS['fail_order_save']))throw new RuntimeException('Synthetic storage failure');$GLOBALS['orders'][$this->id]=native_copy($this->d);return $this->id;}
    function add_order_note($text,$customer=false,$manual=false){if($customer)throw new RuntimeException('Forbidden customer notification');$GLOBALS['notes'][]=array('order'=>$this->id,'text'=>$text);}
    function set_status(){throw new RuntimeException('Forbidden Woo status transition');}function set_date_paid(){throw new RuntimeException('Forbidden invented receipt date');}
    protected function handle_exception($error,$message='Error'){throw $error;}
}
function wc_get_order($id){return isset($GLOBALS['orders'][$id])?new WC_Order($id):false;}
function wc_get_orders($args){if(!empty($GLOBALS['fail_children_read'])){$GLOBALS['wpdb']->last_error='Synthetic child read failure';return array();}$rows=array();foreach($GLOBALS['orders'] as $id=>$data)if(($data['meta'][$args['meta_key']]??'')==$args['meta_value'])$rows[]=wc_get_order($id);return array_slice($rows,0,$args['limit']);}
class NativeSyntheticDB {
    public $prefix='native_test_',$posts='native_test_posts',$postmeta='native_test_postmeta',$options='native_test_options',$last_error='',$backup=null,$dbh;
    function __construct(){$this->dbh=(object)array('server_status'=>0);}
    function prepare($sql,...$args){return $sql;}
    function guarded($sql){if(isset($GLOBALS['query_guard']))call_user_func($GLOBALS['query_guard'],$sql);}
    function get_var($sql){$this->guarded($sql);if(strpos($sql,'CONNECTION_ID')!==false)return '77';if(strpos($sql,'autocommit')!==false)return '1';return !empty($GLOBALS['locked']) && strpos($sql,'RELEASE_LOCK')===false?'0':'1';}
    function get_col($sql){$this->guarded($sql);return array();}
    function get_results($sql,$format=null){$this->guarded($sql);$out=array();foreach(array('posts','postmeta','options','woocommerce_order_items','woocommerce_order_itemmeta') as $table)$out[]=array('TABLE_NAME'=>$this->prefix.$table,'ENGINE'=>'InnoDB');return $out;}
    function query($sql){$this->guarded($sql);$this->last_error='';if($sql==='START TRANSACTION'){$this->backup=native_copy($GLOBALS['orders']);$this->dbh->server_status=1;}if($sql==='ROLLBACK'){$GLOBALS['orders']=$this->backup;$this->dbh->server_status=0;}if($sql==='COMMIT'){$this->backup=null;$this->dbh->server_status=0;}return 1;}
}
$GLOBALS['wpdb']=new NativeSyntheticDB();
$root=dirname(__DIR__).'/wp-content/';
require $root.'plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quote-balance.php';
require $root.'plugins/ge-webtoprint-calculator/includes/class-ge-wtp-order-lifecycle.php';
require $root.'plugins/ge-webtoprint-calculator/includes/class-ge-wtp-customer-quotes.php';
require $root.'plugins/ge-webtoprint-calculator/includes/class-ge-wtp-production.php';
require $root.'plugins/ge-webtoprint-calculator/includes/class-ge-wtp-job-flow.php';
require $root.'mu-plugins/ge-production-payment-guard.php';
require $root.'mu-plugins/ge-work-finance.php';
require $root.'plugins/ge-webtoprint-calculator/includes/class-ge-wtp-operations-finance.php';
function make_native_order($id,$total='123.45',$meta=array()) {
    $item=new SyntheticNativeItem($id*10,$id);$item->total=$total;
    $GLOBALS['orders'][$id]=array('customer'=>1,'currency'=>'ARS','total'=>$total,'status'=>'ge-confirmado','meta'=>$meta,'items'=>array($item->id=>$item));
    return wc_get_order($id);
}
function native_source($id,$payment='fully_paid',$production='completed',$delivery='completed') {
    $r=array('key'=>'synthetic-'.$id,'customer'=>'Cliente sintÃƒÆ’Ã‚Â©tico','order_id'=>$id,'quote_id'=>0,'commercial'=>'performed','production'=>$production,'delivery'=>$delivery,'payment'=>$payment,'currency'=>'ARS','reference_cents'=>GE_WTP_Quote_Balance::cents($GLOBALS['orders'][$id]['total']),'collected_cents'=>null,'declarant'=>'Leo','note'=>'ConfirmaciÃƒÆ’Ã‚Â³n sintÃƒÆ’Ã‚Â©tica');
    $GLOBALS['options']['ge_work_reconciliation_v1']=array('organization_id'=>'graph-express','revisions'=>array(array('id'=>'synthetic-revision-1','rows'=>array($r),'source_fingerprints'=>array('order_id:'.$id=>GE_Work_Historical_Reconciliation::source_fingerprint('order_id',$id)))));return GE_Work_Historical_Reconciliation::source(wc_get_order($id));
}
$order=make_native_order(1);$source=native_source(1);$before=GE_Work_Historical_Reconciliation::snapshot($order);$plan=GE_Work_Historical_Reconciliation::plan($order,$source);
check_native($plan['paid_target']===12345,'full balance target');check_native($plan['stage']==='entregado','actual delivered stage target');
$GLOBALS['actor']=0;reject_native(function()use($plan){GE_Work_Historical_Reconciliation::apply(1,$plan);},'anonymous writer');$GLOBALS['actor']=1;
$GLOBALS['disabled']='finance';check_native(!GE_Work_Historical_Reconciliation::allowed(),'finance module required');$GLOBALS['disabled']='';
$GLOBALS['locked']=true;reject_native(function()use($plan){GE_Work_Historical_Reconciliation::apply(1,$plan);},'native financial lock');$GLOBALS['locked']=false;
$GLOBALS['fail_order_save']=true;reject_native(function()use($plan){GE_Work_Historical_Reconciliation::apply(1,$plan);},'storage failure');$GLOBALS['fail_order_save']=false;check_native(GE_Work_Historical_Reconciliation::snapshot(wc_get_order(1))===$before,'failed transaction restores items and metadata');
$saved=GE_Work_Historical_Reconciliation::apply(1,$plan);$order=wc_get_order(1);$balance=GE_Work_Finance::balance($order);
check_native(!is_wp_error($balance),'real finance consumer accepts historical credit');check_native($balance['amount_paid_cents']===12345 && $balance['amount_due_cents']===0,'native paid and due changed');
check_native(GE_WTP_Order_Lifecycle::stage($order)==='entregado','native lifecycle changed');check_native($order->get_meta('_ge_production_status')==='ready','native production changed');check_native($order->get_item(10)->get_meta('_ge_item_status')==='ready','native item changed');
check_native($order->get_date_paid()===null && $order->get_transaction_id()==='','no invented bank transaction/date');check_native($order->get_meta(GE_WTP_Order_Lifecycle::DELIVERED_META)==='','no invented actual delivery date');
check_native($balance['historical'][0]['received_date']===null && $balance['historical'][0]['method']===null,'actual date and method explicitly unknown');
check_native(GE_WTP_Job_Flow::payment_check($order,true)===true,'real payment gate consumes credited history');check_native(GE_WTP_Job_Flow::delivery_check($order)===true,'real delivery consumer recognizes delivered history');
$replay=GE_Work_Historical_Reconciliation::apply(1,$plan);check_native(!empty($replay['duplicate']),'replay idempotent');check_native(count(GE_Work_Finance::balance(wc_get_order(1))['historical'])===1,'no duplicate credit');
$audit=$order->get_meta(GE_Work_Historical_Reconciliation::AUDIT);$event=end($audit);GE_Work_Historical_Reconciliation::rollback(1,$event['id'],$event['after_hash']);check_native(GE_Work_Historical_Reconciliation::snapshot(wc_get_order(1))===$before,'selective audited rollback restores exact before-image');check_native(count(wc_get_order(1)->get_meta(GE_Work_Historical_Reconciliation::AUDIT))===2,'rollback preserves audit');
$order=make_native_order(2,'819.00');$source=native_source(2,'partially_paid','in_progress','not_delivered');$source['row']['collected_cents']=50000;$GLOBALS['options']['ge_work_reconciliation_v1']['revisions'][0]['rows']=array($source['row']);$plan=GE_Work_Historical_Reconciliation::plan($order,$source);GE_Work_Historical_Reconciliation::apply(2,$plan);$order=wc_get_order(2);$balance=GE_Work_Finance::balance($order);check_native($balance['amount_paid_cents']===50000 && $balance['amount_due_cents']===31900,'partial actual balance');check_native(GE_WTP_Order_Lifecycle::stage($order)==='produccion','actual in-production stage');check_native(GE_WTP_Job_Flow::payment_check($order)===true && is_wp_error(GE_WTP_Job_Flow::payment_check($order,true)),'partial deposit satisfies only deposit gate');
$order=make_native_order(3);$source=native_source(3,'unpaid');$plan=GE_Work_Historical_Reconciliation::plan($order,$source);GE_Work_Historical_Reconciliation::apply(3,$plan);$order=wc_get_order(3);$balance=GE_Work_Finance::balance($order);check_native($balance['amount_paid_cents']===0 && $balance['amount_due_cents']===12345,'delivered unpaid retains real debt');check_native(GE_WTP_Job_Flow::delivery_check($order)===true,'historical unpaid delivery is a fact, not a new dispatch');
$order=make_native_order(4);$source=native_source(4,'fully_paid','completed','ready');$plan=GE_Work_Historical_Reconciliation::plan($order,$source);GE_Work_Historical_Reconciliation::apply(4,$plan);$order=wc_get_order(4);check_native(GE_WTP_Order_Lifecycle::stage($order)==='listo' && $order->get_meta('_ge_production_operational_status')==='active','ready not delivered stays open');
$order=make_native_order(5);$source=native_source(5);$source['row']['commercial']='partial';reject_native(function()use($order,$source){GE_Work_Historical_Reconciliation::plan($order,$source);},'partial scope cannot credit full quotation');
$source=native_source(5);$source['row']['currency']='USD';reject_native(function()use($order,$source){GE_Work_Historical_Reconciliation::plan($order,$source);},'currency mismatch');
$source=native_source(5);$source['row']['reference_cents']=999;reject_native(function()use($order,$source){GE_Work_Historical_Reconciliation::plan($order,$source);},'price mismatch');
$source=native_source(5);$plan=GE_Work_Historical_Reconciliation::plan($order,$source);$changed=wc_get_order(5);$changed->update_meta_data('_ge_amount_due_cents',1);$changed->save();reject_native(function()use($plan){GE_Work_Historical_Reconciliation::apply(5,$plan);},'stale native before-image');
$order=make_native_order(6);$GLOBALS['orders'][6]['is_paid']=true;$GLOBALS['orders'][6]['transaction_id']='verified-existing';$GLOBALS['orders'][6]['paid_date']=1000000;$order=wc_get_order(6);$source=native_source(6);$plan=GE_Work_Historical_Reconciliation::plan($order,$source);check_native($plan['delta_cents']===0,'verified previous payment not added again');GE_Work_Historical_Reconciliation::apply(6,$plan);check_native(GE_Work_Finance::balance(wc_get_order(6))['amount_paid_cents']===12345,'verified payment counted once');
$order=make_native_order(7,'123.45',array(GE_WTP_Customer_Quotes::KEY_META=>'synthetic-legacy'));$child=make_native_order(8,'123.45',array('_ge_work_order'=>'yes','_ge_source_quote_order_id'=>7,'_ge_source_quote_item_id'=>70));$GLOBALS['orders'][7]['items'][70]->meta['_ge_work_order_id']=8;$order=wc_get_order(7);$source=native_source(7);$plan=GE_Work_Historical_Reconciliation::plan($order,$source);$before=$plan['before'];GE_Work_Historical_Reconciliation::apply(7,$plan);$order=wc_get_order(7);$child=wc_get_order(8);
check_native(GE_WTP_Production::is_production_order($order),'realized legacy source accepted by production domain');check_native(GE_WTP_Order_Lifecycle::stage($child)==='entregado','linked child actual stage reconciled');check_native($child->get_meta('_ge_financial_parent_order_id')===7,'child points to one financial source');
$eligible=new ReflectionMethod('GE_WTP_Operations_Finance','eligible');$eligible->setAccessible(true);check_native($eligible->invoke(null,$order)===true && $eligible->invoke(null,$child)===false,'native revenue consumer counts realized scope once');
$audit=$order->get_meta(GE_Work_Historical_Reconciliation::AUDIT);$event=end($audit);GE_Work_Historical_Reconciliation::rollback(7,$event['id'],$event['after_hash']);check_native(GE_Work_Historical_Reconciliation::snapshot(wc_get_order(7))===$before,'group rollback restores child and source');
$order=make_native_order(9,'123.45',array('_ge_payment_confirmed_by'=>1,'_ge_payment_confirmed_at'=>'2026-09-01 12:00:00','_ge_payment_confirmed_total'=>'123.45','_ge_payment_state'=>'paid'));$source=native_source(9);$plan=GE_Work_Historical_Reconciliation::plan($order,$source);check_native($plan['delta_cents']===0,'existing native confirmation is not added twice');GE_Work_Historical_Reconciliation::apply(9,$plan);$order=wc_get_order(9);check_native(GE_Work_Finance::balance($order)['amount_paid_cents']===12345,'existing confirmation remains one full balance');
$order=make_native_order(10,'123.45',array('_ge_commercial_credited_attempts'=>'invalid'));$source=native_source(10);reject_native(function()use($order,$source){GE_Work_Historical_Reconciliation::plan($order,$source);},'malformed existing credit ledger');
$order=make_native_order(11);$source=native_source(11);$plan=GE_Work_Historical_Reconciliation::plan($order,$source);GE_Work_Historical_Reconciliation::apply(11,$plan);$order=wc_get_order(11);$audit=$order->get_meta(GE_Work_Historical_Reconciliation::AUDIT);$event=end($audit);$order->update_meta_data('_ge_delivery_confirmed_at','2026-10-21 12:00:00');$order->save();reject_native(function()use($event){GE_Work_Historical_Reconciliation::rollback(11,$event['id'],$event['after_hash']);},'rollback cannot overwrite a later real delivery event');
$order=make_native_order(12);$source=native_source(12);$source['row']['performed_cents']=100;reject_native(function()use($order,$source){GE_Work_Historical_Reconciliation::plan($order,$source);},'performed amount mismatch cannot become full sale');
$order=make_native_order(13);$source=native_source(13);$GLOBALS['fail_children_read']=true;reject_native(function()use($order,$source){GE_Work_Historical_Reconciliation::plan($order,$source);},'failed child read cannot look like zero children');$GLOBALS['fail_children_read']=false;$GLOBALS['wpdb']->last_error='';
$order=make_native_order(14);$source=native_source(14);for($i=1000;$i<1101;$i++)make_native_order($i,'1.00',array('_ge_source_quote_order_id'=>14));reject_native(function()use($order,$source){GE_Work_Historical_Reconciliation::plan($order,$source);},'candidate truncation rejected before filtering non-work records');
$order=make_native_order(15,'123.45',array('_ge_commercial_quote_id'=>123,'_ge_commercial_quote_version'=>1));$typed=GE_Work_Historical_Reconciliation::snapshot($order);$GLOBALS['orders'][15]['meta']['_ge_commercial_quote_id']='123';$GLOBALS['orders'][15]['meta']['_ge_commercial_quote_version']='1';check_native(GE_Work_Historical_Reconciliation::hash($typed)===GE_Work_Historical_Reconciliation::hash(GE_Work_Historical_Reconciliation::snapshot(wc_get_order(15))),'native scalar quote identifier persistence is semantically stable');
$promise=GE_Work_Historical_Reconciliation::payment_promise(array('payment'=>'expected_deposit','note'=>'Seña del 50% esperada el 22/10/2026','reference_cents'=>24690,'declarant'=>'Synthetic QA'));check_native($promise['due_on']==='2026-10-22' && $promise['amount_cents']===12345,'explicit deposit promise amount and expected day preserved without a receipt');$promise=GE_Work_Historical_Reconciliation::payment_promise(array('payment'=>'promised','note'=>'Promesa de pago para el jueves 22/10/2026','reference_cents'=>99900,'declarant'=>'Synthetic QA'));check_native($promise['due_on']==='2026-10-22' && $promise['amount_cents']===null,'promise with unknown amount does not become a full payment claim');
echo 'PASS '.$GLOBALS['checks']." native domain checks; synthetic only, no live data or external effects.\n";
