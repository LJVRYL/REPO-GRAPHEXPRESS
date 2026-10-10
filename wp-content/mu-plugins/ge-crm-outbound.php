<?php
/** Plugin Name: Graphex CRM · Respuestas supervisadas */
defined('ABSPATH') || exit;
add_action('plugins_loaded',function() {
    if (!class_exists('GE_CRM') || !class_exists('GE_CRM_UI')) return;
    require_once __DIR__.'/ge-crm/class-ge-crm-send-policy.php';
    require_once __DIR__.'/ge-crm/class-ge-crm-outbound.php';
    GE_CRM_Outbound::init();
},90);
