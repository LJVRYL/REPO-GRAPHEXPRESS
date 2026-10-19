<?php
/** Export immutable native approvals only; output is a private operator manifest. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/deco-cli-context.php';ge_deco_cli_context();
$args=getopt('',array('wp-load:','order:','output:'));$load=realpath($args['wp-load']??'');if(!$load||basename($load)!=='wp-load.php')exit(2);define('DISABLE_WP_CRON',true);require $load;
try{
 $order=wc_get_order(absint($args['order']??0));$projection=GE_WTP_Deco_Proofs::production_projection($order);$output=$args['output']??'';
 $parent=realpath(dirname($output));$private=realpath(defined('GE_WTP_PRIVATE_UPLOAD_DIR')?GE_WTP_PRIVATE_UPLOAD_DIR:getenv('GE_WTP_PRIVATE_UPLOAD_DIR'));
 if(!$parent||!$private||0!==strpos($parent.DIRECTORY_SEPARATOR,$private.DIRECTORY_SEPARATOR)||is_link($output))throw new InvalidArgumentException('private_output_required');
 $handle=fopen($output,'x');if(!$handle)throw new InvalidArgumentException('output_already_exists');fwrite($handle,wp_json_encode($projection,JSON_PRETTY_PRINT));fclose($handle);chmod($output,0600);echo 'Approved composition batch prepared; native payment/production guards remain active.'.PHP_EOL;
}catch(InvalidArgumentException $error){fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
