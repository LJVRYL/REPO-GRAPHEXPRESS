<?php
// Synthetic local-only HTTP fixture. No WordPress connection or real fiscal actions.
define('ABSPATH', __DIR__); define('MB_IN_BYTES',1048576);
class WP_Error { public function __construct(public $code,public $message){} public function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function get_current_user_id(){return (int)($_GET['actor']??1);}
function get_userdata($id){return in_array((int)$id,[1,2,100,101],true)?(object)['ID'=>(int)$id]:false;}
function get_user_meta($id,$key,$single){return 'graph-express';}
function get_option($key){return false;}
function get_posts($args){return [];}
function get_post_type($id){return '';}
function get_post_meta($id,$key,$single){return '';}
function sanitize_text_field($s){return (string)$s;} function wp_unslash($s){return $s;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');} function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);} function number_format_i18n($n,$p){return number_format($n,$p,',','.');}
function trailingslashit($s){return rtrim($s,'/\\').DIRECTORY_SEPARATOR;} function wp_basename($s){return basename($s);}
function sanitize_file_name($s){return basename($s);} function admin_url($s){return '/'.$s;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function wp_nonce_url($url,$action){return $url.'&_wpnonce=synthetic-valid';}
function check_admin_referer($action){if(($_GET['_wpnonce']??'')!=='synthetic-valid'){http_response_code(403);exit('invalid nonce');}}
function nocache_headers(){}
function wp_die($msg,$title='',$args=[]){http_response_code($args['response']??500);exit((string)$msg);}
class GE_Organization_Runtime {static function allowed($module,$write,$actor){return (int)$actor===1;}}
class GE_WTP_Portal {static function is_customer_user($u){return $u&&in_array($u->ID,[100,101],true);}}
class GE_WTP_Customer_Branches {static function find($id,$profile,$archived){return $id===100&&$profile==='default';}}
class SyntheticOrder {function get_id(){return 700;} function get_customer_id(){return 100;} function get_meta($key,$single){return !empty($_GET['wrong_org'])?'another-org':'graph-express';}}
function wc_get_order($id){return (int)$id===700?new SyntheticOrder():false;}
function wc_get_orders($args){return (int)$args['customer_id']===100?[new SyntheticOrder()]:[];}
class GE_WTP_Documents {
 static function private_directory(){return getenv('GE_SYNTHETIC_INVOICE_DIR');}
 static function customer_visible($d){return true;}
 static function get_documents($id){
  $name=!empty($_GET['missing'])?'missing.pdf':'synthetic-original.pdf';
  return [['id'=>'arca-7','version_id'=>'arca-7','category'=>'factura','arca_invoice_id'=>7,'issued_by_graphex'=>true,'document_number'=>'00011-00000007','issue_date'=>'2026-01-01','stored_name'=>$name,'name'=>'synthetic-original.pdf','mime'=>'application/pdf','sha256'=>!empty($_GET['corrupt'])?str_repeat('0',64):hash('sha256',"%PDF-1.4\nSYNTHETIC TEST ONLY\n%%EOF\n"),'billing_profile_snapshot'=>['id'=>'default','cuit'=>'SYNTHETIC-RECEIVER','legal_name'=>'Synthetic Receiver'],'uploaded_at'=>'2026-01-01','superseded_at'=>!empty($_GET['historical'])?'2026-01-02':'']];
 }
}
class GE_WTP_ARCA_Emission {
 static function journal($order,$env){return ['id'=>!empty($_GET['wrong_journal'])?8:7,'number'=>7,'state'=>'authorized','payload'=>['header'=>['PtoVta'=>11],'issuer'=>['legal_name'=>'Synthetic Issuer'],'receiver'=>['cuit'=>'SYNTHETIC-RECEIVER'],'detail'=>['ImpTotal'=>'25.50','MonId'=>'PES']],'authorization'=>['cae'=>'SYNTHETIC-CAE','expires'=>'20260111']];}
 static function execute(){throw new Exception('MUST NEVER EMIT');}
}
class GE_WTP_Staff_Portal {static function portal_url($section,$args=[]){return '/gestion/?'.http_build_query(array_merge(['section'=>$section],$args));}}
require __DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-customer-invoices.php';
if(($_GET['operation']??'')==='download'){GE_WTP_Customer_Invoices::download();}
elseif(($_GET['operation']??'')==='resolve'){
 $r=GE_WTP_Customer_Invoices::resolve($_GET['invoice']??'order:700:arca-7',get_current_user_id(),(int)($_GET['preview']??0));
 header('Content-Type: application/json');echo json_encode(is_wp_error($r)?['error'=>$r->code]:$r);
}else{
 $m=new ReflectionMethod('GE_WTP_Customer_Invoices','listing');$m->setAccessible(true);$m->invoke(null,100,get_current_user_id(),true);
}
