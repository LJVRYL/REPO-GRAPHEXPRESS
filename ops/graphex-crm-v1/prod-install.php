<?php
define('DISABLE_WP_CRON',true);require '/home/graphexpress/public_html/wp-load.php';
if(ABSPATH!=='/home/graphexpress/public_html/' || GE_Organization::PRIMARY!=='graph-express')throw new RuntimeException('Wrong instance');
require_once '/home/graphexpress/public_html/wp-content/mu-plugins/ge-crm/class-ge-crm.php';
GE_CRM::install();
global $wpdb;foreach(array('records','events') as $s)if((int)$wpdb->get_var('SELECT COUNT(*) FROM '.GE_CRM::table($s))!==0)throw new RuntimeException('Unexpected CRM data');
echo "SCHEMA_OK two empty InnoDB tables, organization config, internal daily schedule\n";
