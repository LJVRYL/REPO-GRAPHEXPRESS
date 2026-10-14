<?php
/** No database, mail, network or real customer records. Runs native payment arithmetic. */
define('ABSPATH', __DIR__); define('DAY_IN_SECONDS', 86400);
$actor=1; $checks=0;
function add_action() {} function is_wp_error($v) { return $v instanceof WP_Error; }
function absint($v) { return abs((int)$v); } function wp_json_encode($v) { return json_encode($v); }
function wc_format_decimal($v,$n=2) { return number_format((float)$v,$n,'.',''); }
function sanitize_textarea_field($v) { return trim(strip_tags($v)); }
function get_current_user_id() { return $GLOBALS['actor']; }
function user_can($id,$cap) { return $id===1; }
function get_user_meta() { return ''; }
class WP_Error { public $code; public $message; function __construct($c,$m) {$this->code=$c;$this->message=$m;} }
class WC_Order {
    public $meta=array(), $total='330000.00', $id=9001, $status='ge-confirmado', $saved=0, $notes=array();
    function get_id(){return $this->id;} function get_customer_id(){return 37;}
    function get_currency(){return 'ARS';} function get_total(){return $this->total;}
    function get_status(){return $this->status;} function get_meta($k,$single=true){return $this->meta[$k]??'';}
    function update_meta_data($k,$v){$this->meta[$k]=$v;} function save(){$this->saved++;}
    function add_order_note($v,$private=false){$this->notes[]=$v;}
    function is_paid(){return false;} function get_transaction_id(){return '';}
    function get_date_paid(){return null;}
    function get_items($type='line_item'){return array();}
}
class GE_Organization {const PRIMARY='graph-express';}
class GE_Organization_Runtime {static function allowed($area,$write=false,$id=0){return ($id?:get_current_user_id())===1;}}
class GE_WTP_Production_Cards {static function access($o,$write=false){return $o->get_meta('_ge_organization_id')==='other'?new WP_Error('tenant','Foreign tenant'):true;}}
class GE_WTP_Production {static function is_closed($o){return $o->get_meta('closed')===true;}}
class GE_WTP_Staff_Portal {static function can_access(){return get_current_user_id()===1;}}
$root=dirname(__DIR__);
require $root.'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quote-balance.php';
require $root.'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-payment-policy.php';
require $root.'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-job-flow.php';
require $root.'/wp-content/mu-plugins/ge-production-payment-guard.php';
function check($v,$name){$GLOBALS['checks']++;if(!$v)throw new RuntimeException($name);}
function grant($o,$reason='Autorización supervisada de prueba',$days=7){return GE_Production_Payment_Guard::save($o,get_current_user_id(),$reason,time()+$days*DAY_IN_SECONDS,GE_Production_Payment_Guard::scope($o));}
$o=new WC_Order(); $before=$o->meta;
check(is_wp_error(GE_Production_Payment_Guard::check($o)),'Unpaid order allowed');
check($o->meta===$before && $o->saved===0,'Read check mutated order');
$o->meta['_ge_payment_state']='receipt_uploaded';
check(is_wp_error(GE_Production_Payment_Guard::check($o)),'Receipt treated as accreditation');
$o->meta['_ge_payment_state']='paid';
check(is_wp_error(GE_Production_Payment_Guard::check($o)),'Status alone treated as money');
$o->meta['_ge_payment_confirmed_by']=1;$o->meta['_ge_payment_confirmed_at']=gmdate('c');$o->meta['_ge_payment_confirmed_total']='330000.00';
check(GE_Production_Payment_Guard::check($o)===true,'Verified full manual credit rejected');
$o->meta['_ge_payment_confirmed_total']='329999.00';
check(is_wp_error(GE_Production_Payment_Guard::check($o)),'Wrong confirmed amount allowed');
$q=new WC_Order();$q->meta['_ge_commercial_quote_id']=975;$q->meta['_ge_final_total_cents']=33000000;
$q->meta['_ge_commercial_credited_attempts']=array(array('key'=>'fixture:credited:01','amount_cents'=>16500000));$q->meta['_ge_amount_paid_cents']=16500000;
check(GE_Production_Payment_Guard::check($q)===true,'Exact 50 percent deposit rejected');
$q->meta['_ge_commercial_credited_attempts'][0]['amount_cents']=16499999;$q->meta['_ge_amount_paid_cents']=16499999;
check(is_wp_error(GE_Production_Payment_Guard::check($q)),'Deposit below threshold allowed');
$q->meta['_ge_amount_paid_cents']=16500000;
check(is_wp_error(GE_Production_Payment_Guard::check($q)),'Ledger mismatch allowed');
$q->meta['_ge_commercial_credited_attempts']=array(array('key'=>'fixture:credited:01','amount_cents'=>16500000),array('key'=>'fixture:credited:01','amount_cents'=>16500000));
check(GE_Production_Payment_Guard::check($q)===true,'Idempotent credit replay rejected');
$q->meta['_ge_commercial_credited_attempts'][1]['amount_cents']=16500001;
check(is_wp_error(GE_Production_Payment_Guard::check($q)),'Conflicting credit replay allowed');
$o=new WC_Order(); $actor=2;
check(is_wp_error(grant($o)),'Production role granted exception'); $actor=1;
check(is_wp_error(GE_Production_Payment_Guard::save($o,2,'Valid reason for approval',time()+86400,GE_Production_Payment_Guard::scope($o))),'Actor spoof accepted');
check(is_wp_error(grant($o,'short')),'Short reason accepted');
check(is_wp_error(grant($o,'Valid reason for approval',31)),'Excessive expiry allowed');
check(is_wp_error(grant($o,'Valid reason for approval',0)),'Expired grant allowed');
check(is_wp_error(GE_Production_Payment_Guard::save($o,1,'Valid reason for approval',time()+86400,'stale')),'Stale scope allowed');
$o->meta['_ge_organization_id']='other';check(is_wp_error(grant($o)),'Cross tenant grant accepted');
check(is_wp_error(GE_Production_Payment_Guard::check($o)),'Cross tenant check accepted'); unset($o->meta['_ge_organization_id']);
$o->status='cancelled';check(is_wp_error(grant($o)),'Cancelled grant accepted'); $o->status='ge-confirmado';
check(!is_wp_error(grant($o)),'Authorized exception rejected');
check(GE_Production_Payment_Guard::check($o)===true,'Valid exception rejected');
check(empty($o->meta['_ge_payment_state']) && empty($o->meta['_ge_amount_paid_cents']),'Exception fabricated payment');
check(count($o->meta['_ge_production_payment_exception_history_v1'])===1 && count($o->notes)===1,'Missing audit history');
$original=$o->meta;
foreach(array('total','version','snapshot','policy','order') as $change){
    $o->meta=$original;$o->total='330000.00';$o->id=9001;
    if($change==='total')$o->total='330001.00';
    if($change==='version')$o->meta['_ge_commercial_quote_version']=2;
    if($change==='snapshot')$o->meta['_ge_commercial_quote_snapshot']=array('quantity'=>200);
    if($change==='policy')$o->meta[GE_WTP_Payment_Policy::META]=GE_WTP_Payment_Policy::defaults();
    if($change==='order')$o->id=9002;
    check(!GE_Production_Payment_Guard::valid_exception($o),'Changed '.$change.' retained approval');
}
$o->meta=$original;$o->total='330000.00';$o->id=9001;$o->meta[GE_Production_Payment_Guard::META]['expires']=time()-1;
check(is_wp_error(GE_Production_Payment_Guard::check($o)),'Expired exception allowed');
$o->meta=$original;
check(!is_wp_error(GE_Production_Payment_Guard::save($o,1,'Revocación auditada de prueba',time()+86400,GE_Production_Payment_Guard::scope($o),true)),'Revocation failed');
check(is_wp_error(GE_Production_Payment_Guard::check($o)),'Revoked exception allowed');
check(count($o->meta['_ge_production_payment_exception_history_v1'])===2,'Revocation lost audit');
echo json_encode(array('passed'=>true,'checks'=>$checks,'actual_emails'=>0,'actual_payments'=>0,'real_records_modified'=>0));
