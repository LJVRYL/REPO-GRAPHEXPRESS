<?php
define('DISABLE_WP_CRON',true);require '/home/graphexpress/public_html/wp-load.php';
if(!class_exists('GE_CRM')||ABSPATH!=='/home/graphexpress/public_html/')throw new RuntimeException('Wrong instance or loader');
$checks=array();function ck($name,$v){global $checks;$checks[]=array('name'=>$name,'passed'=>(bool)$v);if(!$v)throw new RuntimeException($name);}
global $wpdb;$baseline=json_decode(file_get_contents(__DIR__.'/baseline.private.json'),true);
ck('schema installed',(int)get_option('ge_crm_schema_version')===1);
foreach(array('records','events') as $s)ck('empty '.$s,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.GE_CRM::table($s))===0);
ck('internal daily schedule',wp_next_scheduled('ge_crm_daily')!==false);
wp_set_current_user(0);ck('anonymous permission denied',!GE_CRM::can());
$org=GE_Organization::get(GE_CRM::org());$owner=0;foreach($org['members'] as $id=>$role)if(in_array($role,array('owner','admin'),true)){$owner=(int)$id;break;}
wp_set_current_user($owner);ck('owner membership access',GE_CRM::can(true));
foreach(array('pipeline','leads','tasks','activity','dashboard','inbox','settings') as $view){$_GET=array('section'=>'crm','view'=>$view);ob_start();GE_CRM_UI::render();$html=ob_get_clean();ck('render '.$view,strpos($html,'RELACIONES COMERCIALES')!==false && strlen($html)>500);}
wp_set_current_user(0);do_action('rest_api_init');$r=rest_get_server()->dispatch(new WP_REST_Request('GET','/graphex-crm/v1/records'));ck('REST anonymous 403',$r->get_status()===403);
$counts=array('users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),'quotes'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='ge_commercial_quote'"),'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order'"),'emails'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='ge_email_log'"));
ck('canonical counts unchanged during rollout',$counts===$baseline['counts']);
ck('protected quote untouched',hash('sha256',serialize(get_post(986)).serialize(get_post_meta(986)))===$baseline['protected_quote_986']);
file_put_contents(__DIR__.'/smoke.json',json_encode(array('checks'=>$checks,'passed'=>count($checks),'failed'=>0,'time'=>gmdate('c')),JSON_PRETTY_PRINT));echo 'PASS '.count($checks)." production checks (read only)\n";
