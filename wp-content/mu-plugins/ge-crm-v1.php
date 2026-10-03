<?php
/** Plugin Name: Graphex CRM v1 */
defined('ABSPATH') || exit;
add_action('plugins_loaded', function () {
    if (!class_exists('GE_WTP_Staff_Portal') || !class_exists('GE_Organization')) return;
    require_once __DIR__.'/ge-crm/class-ge-crm.php';
    require_once __DIR__.'/ge-crm/class-ge-crm-ui.php';
    GE_CRM::init(); GE_CRM_UI::init();
}, 60);
