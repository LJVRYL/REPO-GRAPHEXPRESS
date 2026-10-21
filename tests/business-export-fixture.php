<?php
/** Pure synthetic fixture: no WordPress, production access, credentials or real records. */
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
$GLOBALS['actor']=1;$GLOBALS['binding_ok']=true;$GLOBALS['disabled']=array();$GLOBALS['nonce']=true;$GLOBALS['reads']=0;$GLOBALS['checks']=0;
function check($condition,$label) { if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++; }
function source_read($source,$page=1){$GLOBALS['wpdb']->last_error='';if(($GLOBALS['fail_source']??'')===$source && (int)($GLOBALS['fail_page']??1)===$page){$GLOBALS['wpdb']->last_error='synthetic PRIVATE SQL detail';return false;}return true;}
function is_user_logged_in(){return $GLOBALS['actor']>0;}
function get_current_user_id(){return $GLOBALS['actor'];}
function get_option($key){return $GLOBALS['binding_ok']?array('organization_id'=>'graph-express'):array('organization_id'=>'foreign');}
function get_userdata($id){return $id>0&&$id<=205?(object)array('ID'=>$id):false;}
function get_user_meta($id,$key,$single=true){if($key==='_ge_organization_id')return $id===204?'foreign':'';return 'dato público áéí';}
function get_users($args){$GLOBALS['reads']++;if(!source_read('customers',$args['paged']))return array();$out=array();$start=($args['paged']-1)*$args['number']+1;for($id=$start;$id<=min(205,$start+$args['number']-1);$id++)$out[]=(object)array('ID'=>$id,'display_name'=>$id===1?'=HYPERLINK("https://invalid")':'Nombre Ñ '.$id,'user_email'=>'synthetic'.$id.'@example.invalid','user_registered'=>'2026-10-21 12:00:00');return $out;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_post_stati(){return array('publish'=>'publish','private'=>'private','trash'=>'trash');}
function wc_get_order_statuses(){return array('wc-processing'=>'Processing','wc-cancelled'=>'Cancelled');}
function wc_format_decimal($v,$n){return number_format((float)$v,$n,'.','');}
function wp_unslash($v){return $v;}
function wp_die($message,$title='',$args=array()){if(defined('GE_EXPORT_HTTP_FIXTURE')){http_response_code($args['response']??400);echo $message;exit;}throw new RuntimeException($message,$args['response']??400);}
function nocache_headers(){header('Pragma: no-cache');}
function check_admin_referer($action){if(!$GLOBALS['nonce'])wp_die('nonce','',array('response'=>403));}
function add_action($hook,$callback){$GLOBALS['hooks'][$hook]=$callback;}
function get_post_meta($id,$key,$single=true){
    if($key==='_ge_organization_id')return $id===1204?'foreign':'';
    if($key==='_ge_quote_request')return array('customer_id'=>1,'status'=>'new','quote_id'=>1001,'notes'=>'SECRET_PRIVATE_REQUEST','items'=>array(array('product_id'=>2,'name'=>'Solicitud Ñ','quantity'=>3,'token'=>'SECRET_REQUEST_TOKEN')));
    if($key==='_ge_commercial_customer_id')return 1;
    if($key==='_ge_commercial_status')return 'accepted';
    if($key==='_ge_commercial_order_id')return 1;
    if($key==='_ge_commercial_current_version')return 2;
    if($key==='_ge_commercial_versions'){
        $s=array('currency'=>'ARS','net_cents'=>10000,'tax_cents'=>2100,'total_cents'=>12100,'notes_internal'=>'SECRET_INTERNAL','issuer_fiscal_snapshot'=>array('legal_name'=>'Emisor','private_key'=>'SECRET_KEY'),'customer_billing_profile'=>array('legal_name'=>'Cliente Ñ','cuit'=>'synthetic'),'items'=>array(array('line_uuid'=>'test-line','name'=>'Ítem Ñ','quantity'=>6,'net_cents'=>10000,'secret'=>'SECRET_ITEM')));
        return array(1=>$s,2=>$s);
    }
    if($key==='_ge_commercial_selection'&&$id===1001){$current=get_post_meta($id,'_ge_commercial_versions',true)[2];$selected=$current;$selected['total_cents']=6050;$selected['net_cents']=5000;$selected['tax_cents']=1050;return array('version'=>2,'proposal_hash'=>hash('sha256',wp_json_encode($current)),'snapshot'=>$selected);}
    if($key==='_ge_commercial_selection')return array();
    if($key==='_ge_customer_invoice')return array('customer_id'=>1,'type'=>'factura','number'=>'00003-TEST-'.$id,'date'=>'2026-10-21','currency'=>'ARS','amount'=>'121.00','quote_id'=>1001,'order_id'=>1,'file'=>array('path'=>'SECRET_PRIVATE_PATH'),'internal_note'=>'SECRET_NOTE','token'=>'SECRET_INVOICE_TOKEN');
    return '';
}
class GE_Organization { const PRIMARY='graph-express'; static function can($o,$a,$write){return $a===1;} static function qa_only($id){return $id===203;} }
class GE_Organization_Runtime { const BINDING='binding';static function binding(){return array('organization_id'=>'graph-express');}static function allowed($m,$write=false){return !in_array($m,$GLOBALS['disabled'],true);} }
class GE_WTP_Portal {static function is_customer_user($u){return $u->ID!==201;} }
class GE_WTP_Customer_Branches {static function profiles($id,$inactive=false){return array(array('id'=>'default','legal_name'=>'Empresa Ñ','cuit'=>'synthetic','token'=>'SECRET_BRANCH'));} }
class GE_WTP_Commercial_Quotes {const POST_TYPE='ge_commercial_quote';const CUSTOMER_META='_ge_commercial_customer_id';const CURRENT_META='_ge_commercial_current_version';const STATUS_META='_ge_commercial_status';const ORDER_META='_ge_commercial_order_id';const VERSIONS_META='_ge_commercial_versions';}
class GE_WTP_Gestion_V3 {static function lookup($key,$id){if(($GLOBALS['fail_source']??'')==='lookup'&&$id===1205){$GLOBALS['wpdb']->last_error='synthetic PRIVATE lookup error';return 0;}return 10000+$id;} }
class GE_WTP_Customer_Invoices {const TYPE='ge_customer_invoice';const META='_ge_customer_invoice';static function types(){return array('factura'=>'Factura','recibo'=>'Recibo');} }
class GE_WTP_Documents {static function get_documents($id){$d=array('id'=>'doc-'.$id,'category'=>'factura','document_number'=>'DOCUMENT-'.$id,'issue_date'=>'2026-10-21','path'=>'SECRET_ORDER_DOC');if($id===1||(($GLOBALS['fail_source']??'')==='journal'&&$id===205))$d['arca_invoice_id']=123;return array($d);} }
class GE_WTP_ARCA_Emission {static function journal($id,$environment){if(($GLOBALS['fail_source']??'')==='journal'&&$id===205){$GLOBALS['wpdb']->last_error='synthetic PRIVATE journal error';return null;}return array('id'=>123,'state'=>'authorized','payload'=>array('detail'=>array('ImpTotal'=>'121.00','ImpNeto'=>'100.00','ImpIVA'=>'21.00','MonId'=>'PES'),'issuer'=>array('legal_name'=>'Emisor','token'=>'SECRET_ARCA_TOKEN')));} }
class GE_CRM {static function table(){return 'wp_ge_crm_records';}static function can($write=false){return true;} }
class WP_Query {
    public $posts=array();public $max_num_pages=3;
    function __construct($args){
        $GLOBALS['reads']++;check($args['posts_per_page']===100,'bounded_posts');
        if(!source_read($args['post_type'],$args['paged'])){$this->max_num_pages=0;return;}
        $offset=$args['post_type']==='ge_commercial_quote'?1000:($args['post_type']==='ge_quote_request'?2000:3000);
        $start=($args['paged']-1)*100+1;
        for($i=$start;$i<=min(205,$start+99);$i++)$this->posts[]=(object)array('ID'=>$offset+$i,'post_title'=>'Trabajo Ñ '.$i,'post_status'=>$i===205?'trash':'private','post_date_gmt'=>'2026-10-21 12:00:00','post_modified_gmt'=>'2026-10-21 13:00:00');
    }
}
class SyntheticOrder {
    private $id;function __construct($id){$this->id=$id;}
    function get_payment_method(){return 'bacs';}function get_date_paid(){return null;}
    function get_id(){return $this->id;}function get_customer_id(){return 1;}function get_order_number(){return 20000+$this->id;}function get_status(){return $this->id===205?'cancelled':'processing';}function get_currency(){return 'ARS';}function get_total(){return '100.10';}function get_total_tax(){return '21.02';}function get_date_created(){return new DateTime('2026-10-21T12:00:00Z');}function get_items(){return array(new SyntheticItem);}
    function get_meta($key,$single=true){
        if($key==='_ge_organization_id')return $this->id===204?'foreign':'';
        if($key==='_ge_commercial_quote_id')return 1001;
        if($key==='_ge_commercial_quote_version')return 2;
        if($key==='_ge_work_manual_payments')return array(array('id'=>'receipt-'.$this->id,'amount_cents'=>10010,'currency'=>'ARS','received_date'=>'2026-10-20','method'=>'cash','note'=>'SECRET_PAYMENT_NOTE','payload'=>'SECRET_PAYMENT_PAYLOAD'));
        if($key==='_ge_commercial_credited_attempts')return array(array('key'=>'manual:receipt-'.$this->id,'amount_cents'=>10010));
        return '';
    }
}
class SyntheticItem {function get_name(){return 'Vinilo Ñ';}function get_product_id(){return 4;}function get_quantity(){return 6;}function get_total(){return '79.08';}function get_total_tax(){return '21.02';}}
function wc_get_orders($args){$GLOBALS['reads']++;check($args['limit']===100,'bounded_orders');if(!source_read('orders',$args['page']))return (object)array('orders'=>array(),'max_num_pages'=>0);$orders=array();$start=($args['page']-1)*100+1;for($i=$start;$i<=min(205,$start+99);$i++)$orders[]=new SyntheticOrder($i);return (object)array('orders'=>$orders,'max_num_pages'=>3);}
class SyntheticDb {
    public $last_error='';public $queries=array();public $fail=false;
    function query($sql){$this->queries[]=$sql;if($this->fail){$this->last_error='synthetic failure';return false;}$this->last_error='';check(in_array($sql,array('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY','ROLLBACK'),true),'no_db_mutations');return 0;}
    function prepare($sql,...$args){foreach($args as $a){$sql=preg_replace('/%[sd]/',is_int($a)?(string)$a:"'".$a."'",$sql,1);}return $sql;}
    function get_results($sql,$format){$GLOBALS['reads']++;check(strpos($sql,"organization_id='graph-express'")!==false,'crm_org_predicate');check(strpos($sql,'payload')===false&&strpos($sql,'SELECT *')===false,'crm_allowlist');preg_match('/id>(\d+)/',$sql,$m);if(!source_read('crm',intdiv((int)$m[1],100)+1))return array();$out=array();for($i=(int)$m[1]+1;$i<=min(205,(int)$m[1]+100);$i++)$out[]=array('id'=>$i,'kind'=>'opportunity','title'=>'Oportunidad Ñ','customer_id'=>1,'quote_id'=>1001,'order_id'=>1,'status'=>'new','created_at'=>'2026-10-21 12:00:00','revision'=>2);return $out;}
}
$wpdb=new SyntheticDb;
require __DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quote-balance.php';
require __DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-business-export.php';
if(defined('GE_EXPORT_HTTP_FIXTURE'))return;
GE_WTP_Business_Export::init();
check(isset($GLOBALS['hooks']['admin_post_ge_organization_business_download']),'authenticated_hook');check(isset($GLOBALS['hooks']['admin_post_nopriv_ge_organization_business_download']),'deny_unauthenticated_hook');
foreach(array(0,2) as $actor){$GLOBALS['actor']=$actor;check(!GE_WTP_Business_Export::allowed(),'actor_denied');try{GE_WTP_Business_Export::build(array('domain'=>'all'));throw new Exception('unexpected access');}catch(RuntimeException $e){check($e->getCode()===403,'fail_closed_actor');}}
$GLOBALS['actor']=1;$GLOBALS['binding_ok']=false;check(!GE_WTP_Business_Export::allowed(),'foreign_binding_denied');$GLOBALS['binding_ok']=true;
$GLOBALS['nonce']=false;$_SERVER['REQUEST_METHOD']='POST';$before=$GLOBALS['reads'];try{GE_WTP_Business_Export::download();throw new Exception('missing_nonce_accepted');}catch(RuntimeException $e){check($e->getMessage()==='nonce'&&$GLOBALS['reads']===$before,'nonce_before_reads');}$GLOBALS['nonce']=true;
$f=GE_WTP_Business_Export::filters(array('domain'=>'all','format'=>'json'));
foreach(array(array('from'=>'2026-02-30'),array('from'=>'2026-11-01','to'=>'2026-10-01'),array('customer_id'=>'1 OR 1=1'),array('status'=>array('x')),array('domain'=>'secrets'),array('format'=>'php')) as $invalid){try{GE_WTP_Business_Export::filters(array_merge($f,$invalid));throw new Exception('invalid filter accepted');}catch(InvalidArgumentException $e){check(true,'invalid_filter_denied');}}
$handle=GE_WTP_Business_Export::build($f);$bytes=stream_get_contents($handle);fclose($handle);$data=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
check($data['result']['complete']===true,'complete_manifest');check($data['manifest']['schema_version']===1,'versioned_schema');check(strpos($bytes,'SECRET_')===false,'no_private_payload_or_secret');check(strpos($bytes,'Nombre Ñ')!==false,'utf8_preserved');
$counts=$data['result']['record_counts'];
check($counts['customers']['exported']===202,'customers_all_pages_scoped');check($counts['crm']['exported']===205,'crm_all_pages');check($counts['requests']['exported']===205,'requests_all_pages');check($counts['quotes']['exported']===204,'quotes_all_pages_scoped');check($counts['orders']['exported']===204,'orders_all_pages_scoped');check($counts['invoices']['exported']===409,'invoice_both_sources_all_pages');check($counts['receipts']['exported']===408,'manual_and_credit_evidence_distinct');
$records=$data['records'];$quotes=array_values(array_filter($records,function($r){return $r['entity']==='quote';}));check(count($quotes[0]['versions'])===2,'historical_versions_preserved');check($quotes[0]['versions'][1]['snapshot']['total_cents']===12100,'exact_frozen_totals');check($quotes[0]['order_id']===1&&$quotes[0]['customer_id']===1,'quote_relations');check($quotes[0]['versions'][1]['snapshot']['receiver']['legal_name']==='Cliente Ñ','fiscal_receiver');
check($quotes[0]['selection_applied']&&$quotes[0]['total_cents']===6050,'accepted_partial_scope_separate_from_full_snapshot');
$fiscal=array_values(array_filter($records,function($r){return $r['entity']==='order_document'&&$r['order_id']===1;}));check($fiscal[0]['amount']==='121.00'&&$fiscal[0]['currency']==='ARS'&&!$fiscal[0]['amount_missing'],'immutable_fiscal_amount_not_current_order_total');
$orders=array_values(array_filter($records,function($r){return $r['entity']==='order';}));check($orders[0]['net']==='79.08','integer_money_arithmetic');check($orders[0]['quote_id']===1001,'order_quote_link');check(end($orders)['status']==='cancelled','cancelled_orders_preserved');
$receipts=array_values(array_filter($records,function($r){return $r['entity']==='receipt';}));check($receipts[0]['bank_reconciliation']==='not_inferred','bank_not_inferred');
$credits=array_values(array_filter($records,function($r){return $r['entity']==='credit_ledger_entry';}));check($credits[0]['not_additive_with_receipts']&&$credits[0]['ledger_reference']===$receipts[0]['id'],'no_double_count_receipts');
$filtered=GE_WTP_Business_Export::filters(array('domain'=>'quotes','format'=>'json','customer_id'=>'1','from'=>'2026-10-21','to'=>'2026-10-21','status'=>'accepted'));$h=GE_WTP_Business_Export::build($filtered);$d=json_decode(stream_get_contents($h),true);fclose($h);check(count($d['records'])===204,'combined_filters_complete');
$empty=$filtered;$empty['from']='2026-10-22';$empty['to']='2026-10-22';$h=GE_WTP_Business_Export::build($empty);$d=json_decode(stream_get_contents($h),true);fclose($h);check($d['records']===array()&&$d['result']['complete'],'empty_export_complete');
$GLOBALS['disabled']=array('finance');$h=GE_WTP_Business_Export::build($f);$d=json_decode(stream_get_contents($h),true);fclose($h);check(isset($d['manifest']['excluded_domains']['invoices'])&&!isset($d['result']['record_counts']['invoices']),'disabled_modules_explicit');try{GE_WTP_Business_Export::build(array_merge($f,array('domain'=>'invoices')));throw new Exception('disabled accepted');}catch(RuntimeException $e){check($e->getCode()===403,'disabled_domain_denied');}$GLOBALS['disabled']=array();
foreach(array('=SUM(1;2)','+test','-test','@SUM(1)'," \t=cmd", "\u{FEFF}=cmd") as $danger)check(strpos(GE_WTP_Business_Export::csv_cell($danger),"'")===0,'csv_formula_neutralized');check(GE_WTP_Business_Export::csv_cell('Nombre Ñ')==='Nombre Ñ','csv_normal_unchanged');
$csv=$f;$csv['format']='csv';$h=GE_WTP_Business_Export::build($csv);check(fread($h,3)==="\xEF\xBB\xBF",'excel_utf8_bom');$header=fgetcsv($h,0,';','"','');$row=fgetcsv($h,0,';','"','');check($row[7][0]==="'",'csv_formula_on_actual_row');$rows=1;$entities=array();while(($r=fgetcsv($h,0,';','"',''))!==false){$last=$r;$rows++;$entities[$r[0]]=($entities[$r[0]]??0)+1;}fclose($h);check($last[0]==='export_manifest','csv_completeness_footer');$meta=json_decode($last[count($last)-1],true);check($meta['complete']===true,'csv_manifest_json');check($rows===$meta['csv_data_rows']+1&&$meta['total_records']===array_sum(array_column($counts,'exported')),'csv_no_truncation');check($entities['quote_version']===408&&$entities['quote_line']===408&&$entities['selection_line']===1,'excel_normalized_versions_and_lines');
foreach(array('customers'=>'customers','crm'=>'crm','ge_commercial_quote'=>'quotes','ge_quote_request'=>'requests','ge_customer_invoice'=>'invoices','orders'=>'orders') as $source=>$domain){
    foreach(array(1,2) as $page){
        $GLOBALS['fail_source']=$source;$GLOBALS['fail_page']=$page;
        try{$unexpected=GE_WTP_Business_Export::build(array_merge($f,array('domain'=>$domain)));if(is_resource($unexpected))fclose($unexpected);throw new Exception('empty_failed_query_accepted');}
        catch(RuntimeException $e){check(strpos($e->getMessage(),'PRIVATE')===false,'query_failure_safe_error');check(end($wpdb->queries)==='ROLLBACK','query_failure_rollback');}
    }
}
foreach(array('lookup'=>'quotes','journal'=>'invoices') as $source=>$domain){
    $GLOBALS['fail_source']=$source;
    try{$unexpected=GE_WTP_Business_Export::build(array_merge($f,array('domain'=>$domain)));if(is_resource($unexpected))fclose($unexpected);throw new Exception('last_lookup_or_journal_failure_accepted');}
    catch(RuntimeException $e){check(strpos($e->getMessage(),'PRIVATE')===false,'last_lookup_or_journal_safe_error');check(end($wpdb->queries)==='ROLLBACK','last_lookup_or_journal_rollback');}
}
$GLOBALS['fail_source']='';
$wpdb->fail=true;try{GE_WTP_Business_Export::build($f);throw new Exception('db_failure_accepted');}catch(RuntimeException $e){check($e->getMessage()==='No se pudo iniciar la lectura consistente.','db_failure_no_success_download');}
echo json_encode(array('checks'=>$GLOBALS['checks'],'business_records_read'=>'synthetic_only','database_writes'=>0,'records'=>array_sum(array_column($counts,'exported')),'status'=>'PASS'),JSON_UNESCAPED_UNICODE)."\n";
