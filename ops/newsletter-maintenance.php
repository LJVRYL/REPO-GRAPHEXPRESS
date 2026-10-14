<?php
define('DISABLE_WP_CRON',true);$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['HTTPS']='on';require '/home/graphexpress/public_html/wp-load.php';wp_set_current_user(1);if(DB_NAME!=='graphexpress_wp')exit(2);$id=(int)get_option('ge_newsletter_launch_20261012_first',0);
$mode=getenv('GE_NEWSLETTER_MAINTENANCE');if($mode==='pause'){$lock=GE_WTP_Newsletter_Engine::lock_name();if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock)))exit(3);try{GE_WTP_Newsletter_Engine::pause($id,'Pausa breve de mantenimiento del control de rebotes del proveedor.');}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}$result=true;}
elseif($mode==='resume')$result=GE_WTP_Newsletter_Engine::resume($id);else exit(2);
echo wp_json_encode(array('campaign_id'=>$id,'operation'=>$mode,'success'=>!is_wp_error($result),'error'=>is_wp_error($result)?$result->get_error_code():null))."\n";
