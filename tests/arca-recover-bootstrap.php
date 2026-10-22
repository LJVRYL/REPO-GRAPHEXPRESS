<?php
// Fresh-process recovery bootstrap fixture; no WordPress, transport or business writes.
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');
class WP_Error {function __construct(public $code,public $message){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function user_can($actor,$cap){return $actor===8;}
function get_option($key){return $key==='ge_arca_schema_version'?'3':false;}
function wc_get_order($id){return false;}
class RecoveryOrder {function get_id(){return 700;}function get_meta($key,$single){return '';}}
class RecoveryDB {
 public $prefix='wp_';public $writes=0;
 public $row=['id'=>7,'state'=>'authorized','payload'=>'{"order_id":700}','authorization'=>'{"cae":"11111111111111"}'];
 function prepare($query,...$args){return $query;}
 function get_row($query,$format){return $this->row;}
 function get_var($query){if(str_contains($query,'GET_LOCK')||str_contains($query,'RELEASE_LOCK'))return '1';throw new Exception('Unexpected query');}
 function query($query){$this->writes++;throw new Exception('Write forbidden');}
}
$wpdb=new RecoveryDB();$before=serialize($wpdb->row);
require dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-arca-emission.php';
if(class_exists('GE_WTP_ARCA_Invoice_PDF',false))throw new Exception('Fixture must begin without PDF class');
$order=new RecoveryOrder();$denied=GE_WTP_ARCA_Emission::recover($order,'production',9);
if(!is_wp_error($denied)||$denied->code!=='ge_arca_access'||class_exists('GE_WTP_ARCA_Invoice_PDF',false))throw new Exception('Access guard failed');
$result=GE_WTP_ARCA_Emission::recover($order,'production',8);
if(!class_exists('GE_WTP_ARCA_Invoice_PDF',false)||!is_wp_error($result)||$result->code!=='ge_arca_pdf_order')throw new Exception('Authorized recovery did not reach native publisher');
if(defined('GE_WTP_ARCA_EMISSION_CONFIG_FILE'))throw new Exception('Must run without transport configuration');
if($wpdb->writes||serialize($wpdb->row)!==$before)throw new Exception('Fiscal state mutated');
echo json_encode(['status'=>'PASS','checks'=>4,'pdf_loaded_lazily'=>true,'authorized_path_reaches_publisher_without_transport'=>true,'denied_actor_preserved'=>true,'fiscal_state_unchanged'=>true]).PHP_EOL;
