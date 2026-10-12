<?php
// Private pre-release backup only. No option/database restoration is performed.
define('DISABLE_WP_CRON',true); $_SERVER['HTTP_HOST']='graphex.ar'; $_SERVER['HTTPS']='on';
$destination=$argv[1]??'';
if (!preg_match('~^/home/graphexpress/releases/email-identity-[a-z0-9-]+/options-backup\.json$~',$destination) || file_exists($destination)) throw new Exception('Explicit new private backup required');
require '/home/graphexpress/public_html/wp-load.php';
global $wpdb;
$names=array('ge_organizations_v1','ge_wtp_notification_settings','blogname','woocommerce_email_footer_text','woocommerce_email_from_name','woocommerce_email_from_address');
foreach(WC()->mailer()->get_emails() as $email)$names[]='woocommerce_'.$email->id.'_settings';
$names=array_merge($names,$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('ge_email_templates_v1_').'%')));
$rows=array();foreach(array_unique($names) as $name) {
    $row=$wpdb->get_row($wpdb->prepare("SELECT option_value,autoload FROM {$wpdb->options} WHERE option_name=%s",$name),ARRAY_A);
    if($row)$rows[$name]=array('value_base64'=>base64_encode($row['option_value']),'autoload'=>$row['autoload'],'sha256'=>hash('sha256',$row['option_value']));
}
$payload=json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);$handle=fopen($destination,'x');fwrite($handle,$payload);fclose($handle);chmod($destination,0600);
$decoded=json_decode(file_get_contents($destination),true);
foreach($decoded as $row)if(!hash_equals($row['sha256'],hash('sha256',base64_decode($row['value_base64'],true))))throw new Exception('Option backup drill failed');
echo json_encode(array('options_backed_up'=>count($rows),'backup_verified'=>true,'decode_drill_verified'=>true,'database_modified'=>false));
