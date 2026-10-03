<?php
define('WP_INSTALLING',true);
$org=getenv('GE_QA_ORG')?:'empresa-qa-v1';$site='/home/leo/ge-organizations/'.$org.'/site';
require $site.'/wp-load.php';
if(DB_NAME!=='ge_org_'.str_replace('-','_',$org))exit('WRONG_DB');
require_once ABSPATH.'wp-admin/includes/upgrade.php';
if(is_blog_installed())exit('ALREADY_INSTALLED');
$r=wp_install('Empresa QA','qa_owner','owner@empresa-qa.invalid',false,'',wp_generate_password(48));
update_option('active_plugins',array('woocommerce/woocommerce.php','ge-webtoprint-calculator/ge-webtoprint-calculator.php'));
update_option('template','storefront');update_option('stylesheet','storefront');
update_option('users_can_register',0);update_option('blog_public',0);
update_option('woocommerce_currency','ARS');update_option('woocommerce_default_country','AR');
update_option('woocommerce_custom_orders_table_enabled','no');
update_option('ge_wtp_needs_product_sync','no');
echo 'QA_WORDPRESS_EMPTY_INSTALLED'.PHP_EOL;
