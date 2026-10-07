<?php
// Persistence boundaries exercise the real quote model with an isolated in-memory WP adapter.
require __DIR__ . '/commercial-quote-snapshot.php';
$qa_meta = array(); $qa_posts = array(); $qa_profiles = array(); $qa_checks = 0;
function map_deep($value, $callback) { if (is_array($value)) { return array_map(function($v)use($callback){return map_deep($v,$callback);},$value); } return $callback($value); }
function user_can($id,$cap) { return $id === 1; }
function get_current_user_id() { return 1; }
function get_userdata($id) { return $id === 2 ? (object)array('ID'=>2,'user_email'=>'qa@example.invalid','display_name'=>'QA cliente') : false; }
function is_email($email) { return filter_var($email,FILTER_VALIDATE_EMAIL); }
function get_post($id) { global $qa_posts; return $qa_posts[$id] ?? false; }
function get_post_meta($id,$key,$single=true) { global $qa_meta; return $qa_meta[$id][$key] ?? ''; }
function update_post_meta($id,$key,$value) { global $qa_meta; $qa_meta[$id][$key]=$value; }
function wp_insert_post($data,$error=false) { global $qa_posts; $id=count($qa_posts)+10; $qa_posts[$id]=(object)array_merge($data,array('ID'=>$id)); return $id; }
class GE_WTP_Customer_Branches {
 public static function profiles($id) { global $qa_profiles; return $id===2 ? $qa_profiles : array(); }
 public static function find($id,$profile) { foreach(self::profiles($id) as $p) { if($p['id']===$profile)return $p; } return null; }
 public static function delivery($id,$address) { return $id===2 && $address==='qa-address' ? array('id'=>$address) : null; }
}
class GE_WTP_Customer_Tax { public static function vat_status($profile) { return $profile['vat_status'] ?? 'unknown'; } }
function ck_step($condition,$label) { global $qa_checks; if(!$condition)throw new RuntimeException($label); $qa_checks++; }
function code_is($result,$code) { return is_wp_error($result) && $result->get_error_code()===$code; }
$qa_profiles=array(array('id'=>'default','legal_name'=>'QA principal','vat_status'=>''),array('id'=>'qa-branch','legal_name'=>'QA sucursal','vat_status'=>''));
$q=GE_WTP_Commercial_Quotes::create_draft(2,array(),array('deposit_enabled'=>false),1);
ck_step(!is_wp_error($q),'Minimal draft persists');
ck_step($q['status']==='draft' && $q['customer_id']===2,'Customer linked; draft only');
ck_step($q['snapshot']['billing_profile_id']==='' && empty($q['snapshot']['issuer_snapshot']),'Multiple profiles never inferred; issuer not invented');
ck_step($q['snapshot']['draft_incomplete'] && !isset($q['snapshot']['total_cents']),'Unfinished draft has no payable total');
ck_step(!$q['snapshot']['deposit_enabled'],'Deposit off persists');
ck_step(code_is(GE_WTP_Commercial_Quotes::send($q['id'],1),'ge_quote_incomplete'),'Minimal draft cannot send');
ck_step(code_is(GE_WTP_Commercial_Quotes::check_billing_snapshot($q),'ge_quote_incomplete'),'Incomplete cannot enter fiscal operations');
ck_step(code_is(GE_WTP_Commercial_Quotes::prepare_for_conversion($q['id'],1),'ge_quote_incomplete'),'Incomplete cannot resolve itself into an order');
ck_step(code_is(GE_WTP_Commercial_Quotes::create_draft(2,array(),array('billing_profile_id'=>'other-customer'),1),'ge_quote_profile'),'Foreign receiver rejected');
ck_step(code_is(GE_WTP_Commercial_Quotes::create_draft(2,array(),array('delivery_address_id'=>'foreign'),1),'ge_quote_delivery'),'Foreign address rejected');
ck_step(code_is(GE_WTP_Commercial_Quotes::create_draft(2,array(),array(),3),'ge_quote_forbidden'),'Unauthorized staff rejected');
ck_step(code_is(GE_WTP_Commercial_Quotes::create_draft(999,array(),array(),1),'ge_quote_customer'),'Missing customer rejected');
$args=array('expected_version'=>$q['version'],'expected_hash'=>GE_WTP_Quote_Billing_Control::hash($q['snapshot']),'notes_internal'=>'PRIVATE QA','deposit_enabled'=>false);
$revised=GE_WTP_Commercial_Quotes::revise($q['id'],array(),$args,1);
ck_step(!is_wp_error($revised) && $revised['snapshot']['notes_internal']==='PRIVATE QA','Minimal draft edits persist');
ck_step(code_is(GE_WTP_Commercial_Quotes::revise($q['id'],array(),$args,1),'ge_quote_version_changed'),'Concurrent draft edits reject stale hash');
update_post_meta($q['id'],GE_WTP_Commercial_Quotes::STATUS_META,'sent');
$older=$revised['snapshot'];
$new=GE_WTP_Commercial_Quotes::revise($q['id'],array(),array('expected_version'=>$q['version'],'notes_customer'=>'QA revised'),1);
ck_step(!is_wp_error($new) && $new['version']===$q['version']+1,'Sent revision increments version');
$history=get_post_meta($q['id'],GE_WTP_Commercial_Quotes::VERSIONS_META,true);
ck_step($history[$q['version']]===$older,'Prior snapshot unchanged');
$partial=array(array('source_type'=>'custom','name'=>'QA item','quantity'=>'','unit_net'=>'','line_uuid'=>wp_generate_uuid4()));
$partial_q=GE_WTP_Commercial_Quotes::create_draft(2,$partial,array('allow_partial_draft'=>true),1);
ck_step(!is_wp_error($partial_q) && count($partial_q['snapshot']['draft_lines'])===1 && empty($partial_q['snapshot']['items']),'Partial item retained without invented price');
ck_step(code_is(GE_WTP_Commercial_Quotes::build_snapshot($partial),'ge_quote_line'),'Partial item not accepted for complete snapshot');
ck_step(code_is(GE_WTP_Commercial_Quotes::build_snapshot(array(),array()),'ge_quote_lines'),'Empty normal snapshot remains invalid');
$off=GE_WTP_Commercial_Quotes::build_snapshot(array(),array('allow_empty_draft'=>true,'deposit_enabled'=>false,'deposit_percent'=>0));
ck_step(!is_wp_error($off),'Deposit off has no percentage requirement');
$on=GE_WTP_Commercial_Quotes::build_snapshot(array(),array('allow_empty_draft'=>true,'deposit_enabled'=>true,'deposit_percent'=>0));
ck_step(code_is($on,'ge_quote_deposit'),'Deposit on validates percentage');
$money_line=array(array('source_type'=>'custom','name'=>'QA trabajo','quantity'=>'10','unit_net'=>'100.00'));
$percentage=GE_WTP_Commercial_Quotes::build_snapshot($money_line,array('discount_type'=>'percent','discount_value'=>'10','discount_reason'=>'QA descuento','applied_by'=>1,'quote_vat_mode'=>'final'));
ck_step(!is_wp_error($percentage) && $percentage['subtotal_cents']===100000 && $percentage['discount_cents']===10000 && $percentage['net_cents']===90000,'Percentage discount uses commercial base');
$fixed=GE_WTP_Commercial_Quotes::build_snapshot($money_line,array('discount_type'=>'fixed','discount_value'=>'50','discount_reason'=>'QA descuento','applied_by'=>1,'quote_vat_mode'=>'added'));
ck_step(!is_wp_error($fixed) && $fixed['discount_cents']===5000 && $fixed['net_cents']===95000,'Fixed discount independent from IVA mode');
$too_much=GE_WTP_Commercial_Quotes::build_snapshot($money_line,array('discount_type'=>'percent','discount_value'=>'101','discount_reason'=>'QA','applied_by'=>1));
ck_step(is_wp_error($too_much),'Discount over allowed base rejected');
$qa_profiles=array(array('id'=>'default','legal_name'=>'QA current profile','vat_status'=>''));
$stored=$new['snapshot'];$stored['billing_profile_id']='default';$stored['receiver_snapshot']=array('id'=>'default','legal_name'=>'QA OLD snapshot','vat_status'=>'');
$stored['issuer_snapshot']=array('id'=>'qa-fixed','legal_name'=>'QA OLD issuer','snapshot_hash'=>'qa-immutable');
$qa_meta[$q['id']][GE_WTP_Commercial_Quotes::VERSIONS_META][$new['version']]=$stored;
$preserved=GE_WTP_Commercial_Quotes::revise($q['id'],array(),array('expected_version'=>$new['version']),1);
ck_step(!is_wp_error($preserved) && $preserved['snapshot']['receiver_snapshot']===$stored['receiver_snapshot'],'Ordinary incomplete edit preserves receiver snapshot');
ck_step($preserved['snapshot']['issuer_snapshot']===$stored['issuer_snapshot'],'Ordinary incomplete edit preserves issuer snapshot');
echo 'quote-steps-draft: '.$qa_checks." checks OK\n";
