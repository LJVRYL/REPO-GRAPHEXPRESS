<?php
if(PHP_SAPI!=='cli-server' || !getenv('GE_RECON_TEST_STATE')){http_response_code(403);exit;}
define('GE_RECON_HTTP_FIXTURE',true);define('MINUTE_IN_SECONDS',60);
require __DIR__.'/work-reconciliation-fixture.php';
$state_file=getenv('GE_RECON_TEST_STATE');if(is_file($state_file))$GLOBALS['state']=json_decode(file_get_contents($state_file),true);
$GLOBALS['actor']=(int)($_GET['actor']??1);$GLOBALS['disabled_module']=$_GET['disabled']??'';$GLOBALS['uuid']=count($GLOBALS['state']['revisions']??array())+100;
function wp_die($message,$title='',$args=array()){http_response_code($args['response']??400);echo $message;exit;}
function check_admin_referer($action){if(($_POST['_wpnonce']??'')!=='synthetic-nonce')wp_die('Nonce inválido','',array('response'=>403));}
function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="synthetic-nonce">';}
function wp_unslash($v){return $v;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',$v);}
function set_transient($key,$value,$ttl){return file_put_contents(getenv('GE_RECON_TEST_STATE').'.preview',json_encode(array($key=>$value)))!==false;}
function get_transient($key){$p=getenv('GE_RECON_TEST_STATE').'.preview';$v=is_file($p)?json_decode(file_get_contents($p),true):array();return $v[$key]??false;}
function delete_transient($key){$p=getenv('GE_RECON_TEST_STATE').'.preview';if(is_file($p))unlink($p);}
function wp_safe_redirect($url){header('Location: '.$url, true, 303);}
function admin_url($path){return '/'.$path;}
function nocache_headers(){header('Cache-Control: private, no-store');}
function is_page($name){return true;}
function get_header(){echo '<!doctype html><meta charset="utf-8"><body>';}
function get_footer(){echo '</body>';}
try {
    $op=$_GET['route']??'page';
    if($op==='handle')GE_WTP_Work_Reconciliation::handle();
    elseif($op==='download')GE_WTP_Work_Reconciliation::download();
    else {$_GET['ge_work_reconciliation']=1;GE_WTP_Work_Reconciliation::front();}
}catch(Throwable $e){http_response_code(500);echo 'Fixture error: '.get_class($e);}
