<?php
/** Read-only launch audit. Does not enable commerce or expose credential values. */
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/deco-cli-context.php';ge_deco_cli_context();
$root=getenv('GE_WP_ROOT');
if(!$root||!is_file(rtrim($root,'/\\').'/wp-load.php')){fwrite(STDERR,"Set GE_WP_ROOT to the intended WordPress installation.\n");exit(2);}
require_once rtrim($root,'/\\').'/wp-load.php';
$checks=array();
$checks['site_https']=parse_url(home_url(),PHP_URL_SCHEME)==='https';
$checks['woocommerce']=class_exists('WooCommerce')&&function_exists('wc_get_order');
$checks['currency_ars']=$checks['woocommerce']&&get_woocommerce_currency()==='ARS';
$checks['deco_module']=class_exists('GE_WTP_Deco_Gallery');
$checks['creators_module']=class_exists('GE_WTP_Deco_Creators');
$checks['creators_enabled']=$checks['creators_module']&&GE_WTP_Deco_Creators::enabled();
$checks['commerce_enabled']=defined('GE_DECO_PRINTS_ENABLED')&&GE_DECO_PRINTS_ENABLED===true;
$checks['marketplace_connection']=class_exists('GE_WTP_Deco_Marketplace')&&GE_WTP_Deco_Marketplace::enabled();
$policy=get_option('ge_deco_commercial_release',array());
$checks['commercial_policy']=($policy['approved']??false)===true;
$checks['commercial_policy_current']=false;
if(class_exists('GE_WTP_Deco_Release')){try{GE_WTP_Deco_Release::policy();$checks['commercial_policy_current']=true;}catch(InvalidArgumentException $e){}}
$checks['registration_enabled']=class_exists('GE_WTP_Portal')&&has_action('admin_post_nopriv_ge_customer_register',array('GE_WTP_Portal','handle_registration'))!==false;
$checks['openssl']=function_exists('openssl_encrypt')&&function_exists('openssl_decrypt');
$private=defined('GE_DECO_PRIVATE_ROOT')?realpath(GE_DECO_PRIVATE_ROOT):false;$public=realpath(ABSPATH);
$checks['private_catalog_storage']=$private&&$public&&$private!==$public&&strpos($private.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR)!==0&&is_writable($private);
$uploads=defined('GE_WTP_PRIVATE_UPLOAD_DIR')?realpath(GE_WTP_PRIVATE_UPLOAD_DIR):false;
$checks['private_artist_storage']=$uploads&&$public&&$uploads!==$public&&strpos($uploads.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR)!==0&&is_writable($uploads);
$checks['split_checkout']=class_exists('GE_WTP_Deco_Marketplace_Checkout');
$checks['signed_webhook']=class_exists('GE_WTP_Deco_Marketplace_Webhooks');
$checks['artist_sales_enabled']=class_exists('GE_WTP_Deco_Marketplace_Checkout')&&method_exists('GE_WTP_Deco_Marketplace_Checkout','enabled')&&GE_WTP_Deco_Marketplace_Checkout::enabled();
$pending=array_keys(array_filter($checks,static function($pass){return !$pass;}));
echo wp_json_encode(array('schema'=>'graphex-deco-launch-readiness/v1','site'=>home_url(),'checked_at'=>gmdate('c'),'checks'=>$checks,'pending'=>$pending,'ready'=>!$pending,'writes'=>0),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($pending?1:0);
