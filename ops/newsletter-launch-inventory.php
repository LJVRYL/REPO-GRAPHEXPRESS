<?php
define('DISABLE_WP_CRON',true);$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['HTTPS']='on';require getenv('GE_WP_LOAD');wp_set_current_user(1);
$p=GE_WTP_Email_Branding::profile();$s=GE_Organization_Runtime::settings();
if(!class_exists('PHPMailer\\PHPMailer\\PHPMailer')){require_once ABSPATH.WPINC.'/PHPMailer/PHPMailer.php';require_once ABSPATH.WPINC.'/PHPMailer/SMTP.php';require_once ABSPATH.WPINC.'/PHPMailer/Exception.php';}
$smtp=new PHPMailer\PHPMailer\PHPMailer(true);do_action('phpmailer_init',$smtp);
$out=array('site'=>home_url('/'),'database'=>DB_NAME,'php'=>PHP_VERSION,'identity'=>array_intersect_key($p,array_flip(array('name','email','address','website'))),'organization_general'=>array_intersect_key($s['general']??array(),array_flip(array('display_name','legal_name','address','email'))),'transport'=>array('mailer'=>$smtp->Mailer,'host'=>$smtp->Host,'port'=>$smtp->Port,'from'=>$smtp->From,'encryption'=>$smtp->SMTPSecure,'auth'=>(bool)$smtp->SMTPAuth),'engine_scheduled'=>wp_next_scheduled(GE_WTP_Newsletter::CRON_HOOK));
$out['issuers']=array();foreach(GE_WTP_Billing_Issuers::all() as $issuer)$out['issuers'][]=array_intersect_key($issuer,array_flip(array('id','display_name','legal_name','fiscal_address','locality','province','active','verification_status')));
$out['newsletter_counts']=array();foreach(array('contacts','campaigns','deliveries') as $suffix)$out['newsletter_counts'][$suffix]=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'ge_newsletter_'.$suffix);
$out['prefix']=$wpdb->prefix;$out['campaigns']=$wpdb->get_results('SELECT id,status FROM '.$wpdb->prefix.'ge_newsletter_campaigns',ARRAY_A);
echo wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
