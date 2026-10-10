<?php
/** Install outside public_html. Runs as the private server principal, never via HTTP. */
$site=getenv('GE_CRM_SITE');
if (!in_array($site,array('/home/graphexpress/public_html','/home/graphexpress/job-flow-qa-20261007/site'),true)) exit(2);
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== '') exit(2);
define('GE_WHATSAPP_PRIVATE_CLI',true); define('DISABLE_WP_CRON',true);
$_SERVER['HTTP_HOST']='graphex.ar'; $_SERVER['HTTPS']='on'; $_SERVER['REQUEST_URI']='/';
require $site . '/wp-load.php'; wp_set_current_user(1);
try {
    if (!GE_CRM::can(true) || GE_CRM::org() !== 'graph-express') throw new RuntimeException('Private principal unavailable');
    if ((int)get_option('ge_whatsapp_inbound_schema',0) !== GE_WhatsApp_Inbound::VERSION) GE_WhatsApp_Inbound::install();
    $cfg=GE_WhatsApp_Inbound::config();
    if (GE_WhatsApp_Inbound::ready($cfg)) {
        $channels=get_option('ge_crm_attention_channels',array());
        $channels['whatsapp']['accounts']=array('wa:' . $cfg['waba_id'] . ':' . $cfg['phone_number_id']);
        update_option('ge_crm_attention_channels',$channels,false);
    }
    $result=GE_WhatsApp_Inbound::drain();
    if (GE_WhatsApp_Inbound::ready($cfg) || GE_Meta_Social::ready($cfg)) GE_CRM::attention_stale();
    echo wp_json_encode($result) . "\n";
} catch (Throwable $e) { error_log('Graphex Meta worker requires review'); echo '{"error":"worker_review_required"}'; exit(1); }
