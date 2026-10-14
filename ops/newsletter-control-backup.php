<?php
define('DISABLE_WP_CRON',true);$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['HTTPS']='on';require '/home/graphexpress/public_html/wp-load.php';wp_set_current_user(1);
if(DB_NAME!=='graphexpress_wp'||home_url('/')!=='https://graphex.ar/')exit(2);
$dir=getenv('GE_RELEASE');if(!$dir||strpos($dir,'/home/graphexpress/releases/newsletter-control-')!==0||!is_dir($dir))exit(2);
$data=array('site'=>home_url('/'),'tables'=>array(),'metadata'=>array(),'options'=>array());
foreach(array('contacts','campaigns','deliveries') as $suffix){$table=$wpdb->prefix.'ge_newsletter_'.$suffix;$data['tables'][$suffix]=$wpdb->get_results('SELECT * FROM '.$table.' ORDER BY id',ARRAY_A);}
foreach($data['tables']['campaigns'] as $c)$data['metadata'][$c['id']]=get_option(GE_WTP_Newsletter_Review::META.$c['id'],null);
foreach(array('ge_newsletter_engine_policy_v1','ge_newsletter_engine_schema_v2','ge_newsletter_reply_cursor','ge_newsletter_last_attempt_at','ge_newsletter_engine_heartbeat','ge_newsletter_feedback_heartbeat') as $key)$data['options'][$key]=get_option($key,null);
if(count($data['tables']['deliveries']))exit(3); // Initial release must not race another active dispatcher.
$path=$dir.'/native-before.json';file_put_contents($path,wp_json_encode($data,JSON_UNESCAPED_UNICODE));chmod($path,0600);
echo wp_json_encode(array('sha256'=>hash_file('sha256',$path),'counts'=>array_map('count',$data['tables']),'campaign_ids'=>array_column($data['tables']['campaigns'],'id')))."\n";
