<?php
// Isolated execution of the actual access guard; no WordPress/database/network.
define('ABSPATH', __DIR__ . '/');
define('GE_ORGANIZATION_INSTANCE_ID','graph-express');
function get_current_user_id(){return $GLOBALS['actor'];}
function is_page($page){return $page==='gestion';}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function user_can($actor,$cap){return $actor===99 && $cap==='manage_options';}
function get_option($key,$default=array()){
 if($key!==GE_Organization::ROOT)return $default;
 return array('graph-express'=>array('members'=>$GLOBALS['members'],'settings'=>array('modules'=>array_fill_keys(GE_Organization::modules(),$GLOBALS['enabled']))));
}
function wp_die($message,$title='',$args=array()){throw new RuntimeException($message,$args['response']??500);}
require $argv[1].'/wp-content/mu-plugins/ge-organization/class-ge-organization.php';
require $argv[1].'/wp-content/mu-plugins/ge-organization/class-ge-organization-runtime.php';
$members=array('1'=>'owner','2'=>'admin','3'=>'comercial','4'=>'produccion','5'=>'administracion','6'=>'staff','7'=>'read-only');
$enabled=true;$results=array();
foreach(array(0,1,2,3,4,5,6,7,99) as $actor){
 $_GET=array('section'=>'candidates');$status=200;
 try{GE_Organization_Runtime::guard_page();}catch(RuntimeException $e){$status=$e->getCode();}
 $results[(string)$actor]=array('role'=>$members[(string)$actor]??($actor===99?'platform-admin':'anonymous'),'status'=>$status);
}
$enabled=false;$actor=1;$disabled=200;
try{GE_Organization_Runtime::guard_page();}catch(RuntimeException $e){$disabled=$e->getCode();}
$enabled=true;$actor=1;$_GET['section']='unknown';$unknown=200;
try{GE_Organization_Runtime::guard_page();}catch(RuntimeException $e){$unknown=$e->getCode();}
$result=array('roles'=>$results,'module_disabled'=>$disabled,'unknown_section'=>$unknown,'runtime'=>PHP_VERSION);
if(($argv[2]??'')==='fixed'){
 foreach($results as $id=>$row){$expected=in_array((int)$id,array(1,2,3,5,7,99),true)?200:403;if($row['status']!==$expected)throw new Exception('Unexpected status for '.$row['role']);}
 if($disabled!==403 || $unknown!==403)throw new Exception('Boundary failed');
 $result['assertions']='PASS: 9 roles plus module disabled and unknown route';
}else{if($results['1']['status']!==403 || $results['99']['status']!==403)throw new Exception('Baseline not reproduced');$result['assertions']='REPRODUCED: owner and platform admin denied';}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;