<?php
$graph=(getenv('GE_AUTH_FIXTURE')==='graph');$_SERVER['REQUEST_METHOD']='GET';$site=$graph?'/home/leo/graph-org-foundation-v1-minimal/site':'/home/leo/ge-organizations/empresa-qa/site';
$_SERVER['HTTP_HOST']=$graph?'127.0.0.1:18816':'localhost:19050';require $site.'/wp-load.php';
if(!in_array(DB_NAME,array('graph_org_foundation_20261002','ge_org_empresa_qa'),true))exit('WRONG_QA');wp_set_current_user(1);
$file=$graph?'/home/leo/graph-org-foundation-v1-minimal/auth-test.json':'/home/leo/ge-organizations/empresa-qa/auth-test.json';
if(!is_file($file)) {
 $suffix=substr(str_replace('-','',wp_generate_uuid4()),0,8);$password=wp_generate_password(48,false);
 if($graph){$login='graph_boundary_'.$suffix;$uid=wp_insert_user(array('user_login'=>$login,'user_email'=>$login.'@graph-fixture.invalid','user_pass'=>$password,'role'=>'customer'));}
 else{$login='qa_boundary_'.$suffix;$o=GE_Organization::get(GE_Organization::PRIMARY);$r=GE_Organization::save(GE_Organization::PRIMARY,1,$o['revision'],'users',array('new_login'=>$login,'new_email'=>$login.'@empresa-qa.invalid','new_name'=>'Owner de prueba QA','role'=>'owner'));if(is_wp_error($r))throw new Exception($r->get_error_message());$u=get_user_by('login',$login);$uid=$u->ID;wp_set_password($password,$uid);}
 if(is_wp_error($uid))throw new Exception($uid->get_error_message());
 $data=array('login'=>$login,'password'=>$password,'user_id'=>$uid,'cookie'=>wp_generate_auth_cookie($uid,time()+3600,'logged_in'));
 file_put_contents($file,wp_json_encode($data));chmod($file,0600);
}
$data=json_decode(file_get_contents($file),true);$data['cookie']=wp_generate_auth_cookie($data['user_id'],time()+3600,'logged_in');file_put_contents($file,wp_json_encode($data));chmod($file,0600);$u=wp_authenticate($data['login'],$data['password']);$checks=array('own_credentials_work'=>!is_wp_error($u)&&$u->ID===$data['user_id'],'own_signed_cookie_valid'=>wp_validate_auth_cookie($data['cookie'],'logged_in')===$data['user_id']);
$other=$graph?'/home/leo/ge-organizations/empresa-qa/auth-test.json':'/home/leo/graph-org-foundation-v1-minimal/auth-test.json';
if(is_file($other)){$foreign=json_decode(file_get_contents($other),true);$checks['foreign_credentials_denied']=is_wp_error(wp_authenticate($foreign['login'],$foreign['password']));$checks['foreign_cookie_denied']=!wp_validate_auth_cookie($foreign['cookie'],'logged_in');}
foreach($checks as $ok)if(!$ok)throw new Exception('AUTH_ISOLATION_FAILURE');
file_put_contents(dirname(__DIR__).'/outputs/'.($graph?'graph':'qa').'-auth-isolation-results.json',wp_json_encode($checks,JSON_PRETTY_PRINT));echo 'AUTH_QA_PASS '.($graph?'graph':'empresa-qa').' '.count($checks).PHP_EOL;
