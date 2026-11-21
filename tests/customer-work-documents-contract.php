<?php
/** Read-only contract tests: real portal ownership, real finance balance, document projection. */
define('ABSPATH', __DIR__.'/');
function add_action(...$a){} function add_filter(...$a){}
function absint($n){return abs((int)$n);} function get_current_user_id(){return $GLOBALS['actor']??0;}
function get_userdata($id){return $GLOBALS['users'][$id]??false;}
function wp_get_current_user(){return get_userdata(get_current_user_id());}
function get_user_meta($id,$key,$single=true){return $GLOBALS['user_meta'][$id][$key]??'';}
function user_can($u,$cap){$u=is_object($u)?$u:get_userdata($u);return !empty($u->caps[$cap]);}
function is_wp_error($v){return $v instanceof WP_Error;}
function wc_format_decimal($v,$d=2){return number_format((float)$v,$d,'.','');}
class WP_Error{public function __construct(public $code,public $message){}public function get_error_message(){return $this->message;}}
class WP_User{public $roles=['customer'];public $caps=[];public function __construct(public $ID){}public function exists(){return true;}}
class GE_Organization{const PRIMARY='graph-express';}
class GE_Organization_Runtime{public static function allowed($domain,$write=false,$actor=0){return in_array($domain,$GLOBALS['permissions'][$actor]??[],true);}}
class GE_WTP_Customer_Invoices{public static function staff($actor,$write=false){return GE_Organization_Runtime::allowed('finance',$write,$actor);}}
class TestOrder{public $meta=[];public $owner=11;public function get_meta($k,$single=true){return $this->meta[$k]??'';}public function get_customer_id(){return $this->owner;}public function get_total(){return '100.00';}public function get_id(){return 71;}public function is_paid(){return false;}public function get_transaction_id(){return '';}public function get_date_paid(){return null;}}
$inc=dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/';
require $inc.'class-ge-wtp-portal.php';
require $inc.'class-ge-wtp-quote-balance.php';
require dirname(__DIR__).'/wp-content/mu-plugins/ge-work-finance.php';
require $inc.'class-ge-wtp-customer-work-documents.php';
$n=0;function check($yes,$label){global $n;if(!$yes)throw new RuntimeException($label);$n++;}
$GLOBALS['users']=[11=>new WP_User(11),12=>new WP_User(12),99=>new WP_User(99)];
$GLOBALS['users'][99]->caps=['manage_woocommerce'=>true];
$GLOBALS['permissions']=[99=>['orders','finance']];$o=new TestOrder();
check(GE_WTP_Customer_Work_Documents::allowed($o,'work',11),'owner work');
check(GE_WTP_Customer_Work_Documents::allowed($o,'receipt',11),'owner receipt');
check(!GE_WTP_Customer_Work_Documents::allowed($o,'work',12),'other customer denied');
check(!GE_WTP_Customer_Work_Documents::allowed($o,'receipt',0),'anonymous denied');
check(GE_WTP_Customer_Work_Documents::allowed($o,'receipt',99),'finance staff');
$GLOBALS['permissions'][99]=['orders'];check(!GE_WTP_Customer_Work_Documents::allowed($o,'receipt',99),'operations not finance');check(GE_WTP_Customer_Work_Documents::allowed($o,'work',99),'operations work');
$o->meta['_ge_organization_id']='other';check(!GE_WTP_Customer_Work_Documents::allowed($o,'work',11),'wrong organization owner');check(!GE_WTP_Customer_Work_Documents::allowed($o,'work',99),'wrong organization staff');unset($o->meta['_ge_organization_id']);
$GLOBALS['user_meta'][11]['_ge_organization_id']='other';check(!GE_WTP_Customer_Work_Documents::allowed($o,'work',11),'foreign customer');unset($GLOBALS['user_meta'][11]);
$o->owner=0;check(!GE_WTP_Customer_Work_Documents::allowed($o,'work',11),'unassociated guest');$o->meta['_ge_verified_guest_customer_id']=11;check(GE_WTP_Customer_Work_Documents::allowed($o,'work',11),'audited guest');
$o->meta['_ge_commercial_payment_order']='yes';check(!GE_WTP_Customer_Work_Documents::allowed($o,'work',11),'attempt customer denied');check(!GE_WTP_Customer_Work_Documents::allowed($o,'receipt',99),'attempt staff denied');$o=new TestOrder();
check(is_wp_error(GE_WTP_Customer_Work_Documents::receipt_rows(GE_Work_Finance::balance($o))),'unpaid no receipt');
$o->meta=['_ge_commercial_quote_id'=>30,'_ge_final_total_cents'=>10000,'_ge_amount_paid_cents'=>7000,'_ge_amount_due_cents'=>3000,'_ge_commercial_credited_attempts'=>[['key'=>'manual:m1','amount_cents'=>2500],['key'=>'historical:h1','amount_cents'=>3000],['key'=>'gateway:1','amount_cents'=>1500]],'_ge_work_manual_payments'=>[['id'=>'m1','amount_cents'=>2500,'received_date'=>'2026-11-20','method'=>'cash']], '_ge_work_historical_payments'=>[['id'=>'h1','amount_cents'=>3000,'balance_confirmed_cents'=>3000,'actor'=>99,'confirmed_at'=>'2026-11-21','declarant'=>'client','source_revision'=>'r1','source_key'=>'s1','received_date'=>null,'method'=>null]]];
$b=GE_Work_Finance::balance($o);check(!is_wp_error($b),'native mixed balance reconciles');$rows=GE_WTP_Customer_Work_Documents::receipt_rows($b);check(count($rows)===3,'three payment sources');check(array_sum(array_column($rows,'amount'))===7000,'receipt equals credited');check($rows[0]['date']==='2026-11-20','actual cash date retained');check($rows[1]['date']===''&&$rows[1]['method']==='historical','historical does not invent date/method');check($rows[2]['date']===''&&$rows[2]['amount']===1500,'gateway remainder no invented date');
$o->meta['_ge_amount_paid_cents']=9000;check(is_wp_error(GE_WTP_Customer_Work_Documents::receipt_rows(GE_Work_Finance::balance($o))),'stored-vs-ledger mismatch fails closed');$o->meta['_ge_amount_paid_cents']=7000;
$o->meta['_ge_work_manual_payments'][0]['amount_cents']=2600;check(is_wp_error(GE_WTP_Customer_Work_Documents::receipt_rows(GE_Work_Finance::balance($o))),'manual ledger mismatch fails closed');$o->meta['_ge_work_manual_payments'][0]['amount_cents']=2500;
$o->meta['_ge_work_historical_payments'][0]['received_date']='2026-11-21';check(is_wp_error(GE_Work_Finance::balance($o)),'historical invented date rejected');
$e=new WP_Error('balance','blocked');check(GE_WTP_Customer_Work_Documents::receipt_rows($e)===$e,'errors propagate');
check(is_wp_error(GE_WTP_Customer_Work_Documents::receipt_rows(['amount_paid_cents'=>10,'ledger'=>[['id'=>'bad','amount_cents'=>20]]])),'projection over-credit rejected');
echo "PASS $n contract assertions\n";

// Encoding regression: normalize text operands, preserve PDF escapes and non-text bytes.
$encoded=new ReflectionMethod(GE_WTP_Customer_Work_Documents::class,'encoded_page');
$ascii='BT /F1 10 Tf (Cliente ASCII) Tj ET';check($encoded->invoke(null,$ascii)===$ascii,'ASCII stable');
$utf='BT (Conformidad: impresión, año, €) Tj ET';$ansi="BT (Conformidad: impresi\xf3n, a\xf1o, \x80) Tj ET";
check($encoded->invoke(null,$utf)===$ansi,'UTF8 accents converted to WinAnsi');
check($encoded->invoke(null,$ansi)===$ansi,'existing WinAnsi stable');
$escaped='BT (Revisión \\(A\\) C:\\\\archivos) Tj ET';
check($encoded->invoke(null,$escaped)===str_replace('ó',"\xf3",$escaped),'parenthesis and backslash escapes stable');
$outside="% revisión fuera del texto\nBT (acción) Tj ET\n/Im0 Do";
check($encoded->invoke(null,$outside)===str_replace('(acción)',"(acci\xf3n)",$outside),'non-text commands remain untouched');

// Thumbnail rejection occurs before image decoding or external process execution.
function wc_get_order($id){return $GLOBALS['orders'][$id]??false;}
function trailingslashit($p){return rtrim($p,'/').'/';}function wp_basename($p){return basename($p);}
class GE_WTP_Documents{public static function get_documents($id){return $GLOBALS['docs'][$id]??[];}public static function customer_visible($d){return true;}public static function private_directory(){return dirname(__DIR__).'/tests';}}
class GE_WTP_Artwork_Library{public static function get_order_ids($o){return [];}}
class GE_WTP_File_Analysis{public static $allow=false;public static function safe_path($p){return self::$allow?$p:'';}}
$GLOBALS['orders'][71]=new TestOrder();$GLOBALS['docs'][71]=[['id'=>'test-doc','category'=>'arte','name'=>'fixture','provider'=>'local','stored_name'=>basename(__FILE__),'analysis'=>['sha256'=>str_repeat('0',64)]]];
$f=GE_WTP_Customer_Work_Documents::files($GLOBALS['orders'][71])[0];
check(GE_WTP_Customer_Work_Documents::thumbnail(999,$f)===false,'missing order thumbnail denied');
$forged=$f;$forged['id']='document:unlinked';check(GE_WTP_Customer_Work_Documents::thumbnail(71,$forged)===false,'unlinked thumbnail denied');
check(GE_WTP_Customer_Work_Documents::thumbnail(71,$f)===false,'unsafe path denied');
GE_WTP_File_Analysis::$allow=true;check(GE_WTP_Customer_Work_Documents::thumbnail(71,$f)===false,'wrong byte hash denied');
echo "PASS $n total contract assertions including encoding and thumbnail denials\n";
