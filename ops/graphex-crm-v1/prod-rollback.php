<?php
// Run AFTER moving only ge-crm-v1.php out of mu-plugins. Preserve CRM tables/data.
define('DISABLE_WP_CRON',true);require '/home/graphexpress/public_html/wp-load.php';
if(ABSPATH!=='/home/graphexpress/public_html/'||class_exists('GE_CRM'))throw new RuntimeException('Disable CRM loader first');
wp_clear_scheduled_hook('ge_crm_daily');echo "CRM loader disabled; own schedule cleared; all tables/data preserved\n";
