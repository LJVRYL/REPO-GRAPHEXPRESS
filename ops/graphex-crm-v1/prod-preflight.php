<?php
define('DISABLE_WP_CRON',true);
require '/home/graphexpress/public_html/wp-load.php';
$release=dirname(__FILE__);global $wpdb;
if(ABSPATH!=='/home/graphexpress/public_html/' || GE_Organization::PRIMARY!=='graph-express')throw new RuntimeException('Wrong instance');
if(class_exists('GE_CRM'))throw new RuntimeException('CRM already active; review upgrade');
foreach(array('GE_WTP_Staff_Portal','GE_WTP_Commercial_Quotes','GE_WTP_Commercial_Checkout','GE_WTP_Quote_Requests','GE_WTP_Notifications') as $c)if(!class_exists($c))throw new RuntimeException('Missing dependency '.$c);
foreach(array('users','usermeta','posts','postmeta') as $suffix){$t=$wpdb->prefix.$suffix;$r=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($t)),ARRAY_A);if(!$r||$r['Engine']!=='InnoDB')throw new RuntimeException('Non transactional canonical table');}
foreach(array('records','events') as $suffix)if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'ge_crm_'.$suffix))))throw new RuntimeException('CRM table already exists');
$org=GE_Organization::get('graph-express');if(!$org||empty($org['active'])||empty($org['members']))throw new RuntimeException('Organization not ready');
$snapshot=array('created_at'=>gmdate('c'),'prefix'=>$wpdb->prefix,'new_tables_absent'=>true,'options'=>array());
foreach(array('ge_crm_schema_version','ge_crm_config_graph-express','cron') as $k){$row=$wpdb->get_row($wpdb->prepare("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name=%s",$k),ARRAY_A);$snapshot['options'][$k]=$row;}
$snapshot['counts']=array('users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),'quotes'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='ge_commercial_quote'"),'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order'"),'emails'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='ge_email_log'"));
$snapshot['protected_quote_986']=hash('sha256',serialize(get_post(986)).serialize(get_post_meta(986)));
file_put_contents($release.'/baseline.private.json',json_encode($snapshot,JSON_PRETTY_PRINT));chmod($release.'/baseline.private.json',0600);
if(!is_array(json_decode(file_get_contents($release.'/baseline.private.json'),true)))throw new RuntimeException('Backup unreadable');
echo "PREFLIGHT_OK transactional tables, active organization, private options backup verified\n";
