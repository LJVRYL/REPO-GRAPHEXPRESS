<?php
define('DISABLE_WP_CRON',true);
$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['SERVER_NAME']='graphex.ar';$_SERVER['HTTPS']='on';$_SERVER['REQUEST_URI']='/gestion/?section=crm&view=pipeline';$_SERVER['REQUEST_METHOD']='GET';$_GET=array('section'=>'crm','view'=>'pipeline');
define('WP_USE_THEMES',true);require '/home/graphexpress/public_html/wp-load.php';
if(ABSPATH!=='/home/graphexpress/public_html/'||!class_exists('GE_CRM'))throw new RuntimeException('Wrong instance');
$o=GE_Organization::get(GE_CRM::org());foreach($o['members'] as $id=>$role)if(in_array($role,array('owner','admin'),true)){wp_set_current_user((int)$id);if(GE_CRM::can())break;}
wp();GE_CRM_UI::guard();$template=apply_filters('template_include',get_page_template());
ob_start();include $template;$html=ob_get_clean();
$checks=array('live shell retained'=>strpos($html,'ge-v3-nav')!==false,'CRM main rendered'=>strpos($html,'RELACIONES COMERCIALES')!==false,'CRM menu extension'=>strpos($html,'id="ge-crm-nav"')!==false,'pipeline rendered'=>strpos($html,'ge-crm-board')!==false,'single main'=>substr_count($html,'</main>')===1);
foreach($checks as $v)if(!$v)throw new RuntimeException('Full shell check failed');
file_put_contents(__DIR__.'/shell-smoke.json',json_encode(array('checks'=>$checks,'passed'=>count($checks),'failed'=>0,'scope'=>'authenticated server render, no business writes'),JSON_PRETTY_PRINT));echo 'PASS '.count($checks)." full production shell checks\n";
