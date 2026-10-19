<?php
/** CLI-only creator-source consumer. Existing global Analyzer performs the analysis. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/deco-cli-context.php';ge_deco_cli_context();
$args=getopt('',array('wp-load:','analyze-local'));$load=realpath($args['wp-load']??'');if(!$load||basename($load)!=='wp-load.php')exit(2);define('DISABLE_WP_CRON',true);require $load;
if(!GE_WTP_Deco_Creators::enabled())exit(3);
$jobs=GE_WTP_Deco_Creators::worker();
if(isset($args['analyze-local'])){
 if(wp_get_environment_type()!=='local'||DB_NAME!=='graphex_creator_local_qa')exit(4);
 for($i=0;$i<10;$i++){if(!GE_WTP_File_Analysis::run_next())break;}
}
echo wp_json_encode(array('creator_jobs'=>$jobs,'production_released'=>false)).PHP_EOL;
