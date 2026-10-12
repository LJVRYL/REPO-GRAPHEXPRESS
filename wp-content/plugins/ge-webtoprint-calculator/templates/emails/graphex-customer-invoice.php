<?php
defined('ABSPATH') || exit;
$ge_profile=GE_WTP_Email_Branding::profile(); $blogname=$ge_profile['name'];
if(isset($pos_store_name))$pos_store_name=$ge_profile['name'];
if(isset($pos_store_email))$pos_store_email=$ge_profile['email'];
add_filter('bloginfo',array('GE_WTP_Email_WooCommerce','store_name'),90,2);
add_filter('gettext_woocommerce',array('GE_WTP_Email_WooCommerce','language'),90,3);
try { include WC()->plugin_path().'/templates/emails/customer-invoice.php'; }
finally { remove_filter('gettext_woocommerce',array('GE_WTP_Email_WooCommerce','language'),90); remove_filter('bloginfo',array('GE_WTP_Email_WooCommerce','store_name'),90); }
