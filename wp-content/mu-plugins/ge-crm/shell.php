<?php
defined('ABSPATH') || exit;
// Keep the live Gestion shell and replace only its main region; no fork of shared templates.
ob_start();GE_CRM_UI::render();$crm_content=ob_get_clean();
ob_start();include $GLOBALS['ge_crm_original_template'];$shell=ob_get_clean();
$matches=0;
$shell=preg_replace_callback('/(<main\b[^>]*>).*?(<\/main>)/s',function($m)use($crm_content){return $m[1].$crm_content.$m[2];},$shell,1,$matches);
if($matches!==1)wp_die('El shell de Gestión cambió. Revisar integración CRM.','',array('response'=>503));
echo $shell;
