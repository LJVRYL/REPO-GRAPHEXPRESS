<?php
/** CLI synchronizer. Reuses the deployed global Analyzer queue; no web execution. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/deco-cli-context.php';ge_deco_cli_context();
$args=getopt('',array('wp-load:','analyze-qa'));
$load=realpath($args['wp-load']??'');if(!$load||basename($load)!=='wp-load.php')exit(2);
define('DISABLE_WP_CRON',true);require $load;
if(!class_exists('GE_WTP_Deco_Proofs'))exit(3);
$synced=GE_WTP_Deco_Proofs::sync();
if(isset($args['analyze-qa'])){
 if(!defined('DB_NAME')||DB_NAME!=='graph_deco_qa_20261017'||wp_get_environment_type()==='production')exit(4);
 for($i=0;$i<20;$i++){if(!GE_WTP_File_Analysis::run_next())break;}
}
echo wp_json_encode(array('proofs_attached'=>$synced,'production_released'=>false)).PHP_EOL;
