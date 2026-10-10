<?php
/** Plugin Name: Graphex CRM · Agente supervisado y consumo */
defined('ABSPATH') || exit;
add_action('plugins_loaded', function() {
    if (!class_exists('GE_CRM') || !class_exists('GE_CRM_UI')) return;
    require_once __DIR__ . '/ge-crm-agent/budget.php';
    require_once __DIR__ . '/ge-crm-agent/agent.php';
    GE_CRM_Agent::init();
}, 85);
