<?php
$_SERVER['HTTP_HOST']='localhost:19050';$_SERVER['SERVER_NAME']='localhost';
$org=getenv('GE_QA_ORG')?:'empresa-qa-v1';require '/home/leo/ge-organizations/'.$org.'/site/wp-load.php';
if(DB_NAME!=='ge_org_'.str_replace('-','_',$org)||GE_Organization::PRIMARY!==$org)exit('WRONG_INSTANCE');
wp_set_current_user(1);
$seed=GE_Organization::seed(1);if(is_wp_error($seed))throw new Exception($seed->get_error_message());
$binding=GE_Organization_Runtime::bind(1);if(is_wp_error($binding))throw new Exception($binding->get_error_message());
WC_Install::install();GE_WTP_Plugin::activate();
update_option('ge_operations_enabled','yes');
foreach(array('stock','finance','suppliers') as $module)update_option('ge_operations_'.$module.'_enabled','yes');
GE_WTP_Operations::install();
update_option('ge_wtp_needs_product_sync','no');
update_option('ge_customer_tax_resolver_stage',3);
echo wp_json_encode(array('organization'=>GE_Organization::PRIMARY,'database'=>DB_NAME,'users'=>count(get_users()),'quotes'=>count(get_posts(array('post_type'=>'ge_commercial_quote','numberposts'=>-1))),'orders'=>count(wc_get_orders(array('limit'=>-1,'return'=>'ids'))),'instance_binding'=>!empty($binding))).PHP_EOL;
