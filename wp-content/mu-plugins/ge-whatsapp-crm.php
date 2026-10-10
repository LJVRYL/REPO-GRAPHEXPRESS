<?php
/** Plugin Name: Graphex official WhatsApp inbound connector */
defined('ABSPATH') || exit;
require_once __DIR__ . '/ge-crm/class-ge-meta-social.php';
require_once __DIR__ . '/ge-crm/class-ge-whatsapp-inbound.php';
add_action('plugins_loaded', function () {
    if (!class_exists('GE_CRM') || GE_CRM::org() !== 'graph-express') return;
    GE_WhatsApp_Inbound::init();
}, 85);
