<?php
/** Actual native receipt path, only in the isolated synthetic WordPress/Woo instance. */
$root=getenv('GE_QA_WP_ROOT');
if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('Synthetic root required.');
require $root.'/wp-load.php';
if(!defined('GE_NATIVE_QA_SYNTHETIC') || GE_NATIVE_QA_SYNTHETIC!==true || !str_starts_with(DB_NAME,'ge_native_qa_') || !str_ends_with((string)wp_parse_url(home_url(),PHP_URL_HOST),'.invalid'))throw new RuntimeException('Refusing non-synthetic environment.');
$actor=username_exists('native_qa_owner');
if(!$actor)$actor=wp_insert_user(array('user_login'=>'native_qa_owner','user_email'=>'owner@native-qa.invalid','user_pass'=>wp_generate_password(40),'role'=>'administrator'));
if(is_wp_error($actor))throw new RuntimeException('Synthetic actor failed.');
$actor=(int)$actor;wp_set_current_user($actor);$user=get_user_by('id',$actor);
foreach(array('manage_options','manage_woocommerce','ge_manage_operations') as $cap)$user->add_cap($cap);
update_option(GE_Organization::ROOT,array(GE_Organization::PRIMARY=>array('organization_id'=>GE_Organization::PRIMARY,'active'=>true,'revision'=>1,'settings'=>GE_Organization::defaults(),'members'=>array((string)$actor=>'owner'))));
if(is_wp_error(GE_Organization_Runtime::bind($actor)))throw new RuntimeException('Synthetic organization failed.');
$checks=0;$mail=0;$http=0;
add_filter('pre_wp_mail',function()use(&$mail){$mail++;return false;},PHP_INT_MAX);
add_filter('pre_http_request',function()use(&$http){$http++;return new WP_Error('synthetic','Network forbidden');},PHP_INT_MAX);
function receipt_check($ok,$label){global $checks;if(!$ok)throw new RuntimeException('QA: '.$label);$checks++;}
function receipt_order(){
 $o=wc_create_order(array('customer_id'=>get_current_user_id()));if(is_wp_error($o))throw new RuntimeException('Synthetic order failed.');
 $o->set_currency('ARS');$o->set_total('100.00');$o->set_status('pending');$o->update_meta_data('_ge_organization_id',GE_Organization::PRIMARY);$o->save();return $o;
}
function receipt_fresh($id){GE_Work_Finance::clear_order_cache($id);$o=wc_get_order($id);$o->read_meta_data(true);return $o;}
function receipt_input($o,$method='transfer',$kind='partial',$amount='25.00'){
 return array('request_id'=>wp_generate_uuid4(),'scope'=>GE_Work_Finance::scope($o,GE_Work_Finance::balance($o)),'kind'=>$kind,'amount'=>$amount,'method'=>$method,'received_date'=>wp_date('Y-m-d'),'reference'=>'','note'=>'Synthetic confirmed receipt','verified'=>1);
}
foreach(array('transfer','other','cash') as $method){
 $o=receipt_order();$input=receipt_input($o,$method);$r=GE_Work_Finance::register($o->get_id(),$input,$actor);
 receipt_check(!is_wp_error($r),'empty reference accepted: '.$method);
 $b=GE_Work_Finance::balance(receipt_fresh($o->get_id()));
 receipt_check($b['amount_paid_cents']===2500 && $b['amount_due_cents']===7500 && count($b['ledger'])===1,'exact partial balance: '.$method);
 receipt_check($b['ledger'][0]['reference']==='' && $b['ledger'][0]['actor']===$actor && $b['ledger'][0]['received_date']===$input['received_date'] && $b['ledger'][0]['method']===$method,'audit retains actual fields without fabricated reference: '.$method);
 $again=GE_Work_Finance::register($o->get_id(),$input,$actor);
 receipt_check(!is_wp_error($again) && $again['duplicate']===true,'same request without reference idempotent: '.$method);
 $changed=$input;$changed['amount']='26.00';$r=GE_Work_Finance::register($o->get_id(),$changed,$actor);
 receipt_check(is_wp_error($r) && $r->get_error_code()==='conflict','same request changed payload rejected: '.$method);
 $stale=$input;$stale['request_id']=wp_generate_uuid4();$r=GE_Work_Finance::register($o->get_id(),$stale,$actor);
 receipt_check(is_wp_error($r) && $r->get_error_code()==='stale','second old form without reference rejected: '.$method);
 $o=receipt_fresh($o->get_id());$r=GE_Work_Finance::register($o->get_id(),receipt_input($o,$method,'full'),$actor);
 $b=GE_Work_Finance::balance(receipt_fresh($o->get_id()));
 receipt_check(!is_wp_error($r) && $b['amount_paid_cents']===10000 && $b['amount_due_cents']===0 && count($b['ledger'])===2,'full remaining balance exact: '.$method);
}
$o=receipt_order();$input=receipt_input($o);$input['reference']='A';
receipt_check(!is_wp_error(GE_Work_Finance::register($o->get_id(),$input,$actor)),'short supplied optional reference retained');
$o=receipt_fresh($o->get_id());$input=receipt_input($o);$input['reference']='a';$r=GE_Work_Finance::register($o->get_id(),$input,$actor);
receipt_check(is_wp_error($r) && $r->get_error_code()==='reference','provided reference duplicate guard retained');
foreach(array('unverified','future','invalid_date','method','zero','over_balance','too_long','request_id') as $case){
 $o=receipt_order();$input=receipt_input($o);
 if($case==='unverified')$input['verified']=0;
 if($case==='future')$input['received_date']='2999-01-01';
 if($case==='invalid_date')$input['received_date']='2026-02-31';
 if($case==='method')$input['method']='gateway';
 if($case==='zero')$input['amount']='0';
 if($case==='over_balance')$input['amount']='100.01';
 if($case==='too_long')$input['reference']=str_repeat('x',161);
 if($case==='request_id')$input['request_id']='invalid';
 receipt_check(is_wp_error(GE_Work_Finance::register($o->get_id(),$input,$actor)),'invalid request remains rejected: '.$case);
 $b=GE_Work_Finance::balance(receipt_fresh($o->get_id()));receipt_check($b['amount_paid_cents']===0 && !$b['ledger'],'invalid request leaves ledger unchanged: '.$case);
}
$o=receipt_order();$input=receipt_input($o);wp_set_current_user(0);
receipt_check(is_wp_error(GE_Work_Finance::register($o->get_id(),$input,0)),'anonymous actor denied');wp_set_current_user($actor);
$o=receipt_order();$o->update_meta_data('_ge_organization_id','synthetic-other');$o->save();
receipt_check(is_wp_error(GE_Work_Finance::register($o->get_id(),receipt_input($o),$actor)),'other organization denied');
$o=receipt_order();$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$name='ge:'.substr(hash('sha256',$wpdb->prefix.GE_Work_Finance::collection_lock($o)),0,55);
receipt_check('1'===(string)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$name)),'independent connection holds collection lock');
try{$r=GE_Work_Finance::register($o->get_id(),receipt_input($o),$actor);receipt_check(is_wp_error($r) && $r->get_error_code()==='busy','concurrent writer rejected without reference');}
finally{$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$name));$other->close();}
$o=receipt_order();$id=$o->get_id();$throw=function($saved)use($id){if($saved->get_id()===$id)throw new RuntimeException('Synthetic save fault');};
add_action('woocommerce_before_order_object_save',$throw,PHP_INT_MAX);
try{$r=GE_Work_Finance::register($id,receipt_input($o),$actor);receipt_check(is_wp_error($r),'save fault fails closed');}
finally{remove_action('woocommerce_before_order_object_save',$throw,PHP_INT_MAX);}
$b=GE_Work_Finance::balance(receipt_fresh($id));receipt_check($b['amount_paid_cents']===0 && !$b['ledger'],'fault does not persist receipt');
require_once WPMU_PLUGIN_DIR.'/ge-work-finance/ui.php';$o=receipt_order();ob_start();GE_Work_Finance_UI::render($o);$html=ob_get_clean();
receipt_check(str_contains($html,'operación (opcional)') && !str_contains($html,'Obligatoria para transferencia'),'UI describes optional reference');
receipt_check(str_contains($html,'name="verified" value="1" required') && str_contains($html,'name="received_date" required') && str_contains($html,'name="method" required'),'UI retains explicit receipt/date/method requirements');
receipt_check($mail===0 && $http===0,'no mail or network effects');
echo wp_json_encode(array('status'=>'PASS','checks'=>$checks,'synthetic'=>true,'mail'=>$mail,'http'=>$http,'storage'=>\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()?'HPOS':'CPT'))."\n";
