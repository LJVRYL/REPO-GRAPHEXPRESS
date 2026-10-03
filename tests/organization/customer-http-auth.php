<?php
$_SERVER['HTTP_HOST']='localhost:19050';$_SERVER['REQUEST_METHOD']='GET';require '/home/leo/ge-organizations/empresa-qa/site/wp-load.php';
if(DB_NAME!=='ge_org_empresa_qa')exit('WRONG_QA');$f=get_option('ge_qa_acceptance_fixture');$u=get_userdata($f['customer_id']);
if(!$u || substr($u->user_email,-19)!=='@empresa-qa.invalid')exit('NOT_SYNTHETIC');
$password=wp_generate_password(48,false);wp_set_password($password,$u->ID);$file='/home/leo/ge-organizations/empresa-qa/customer-auth-test.json';file_put_contents($file,wp_json_encode(array('login'=>$u->user_login,'password'=>$password)));chmod($file,0600);echo "CUSTOMER_QA_AUTH_READY\n";
