<?php
/** Plugin Name: Organization Foundation v1 */
defined('ABSPATH') || exit;
add_action('plugins_loaded', function () {
    if (!class_exists('GE_WTP_Staff_Portal')) { return; }
    require_once __DIR__ . '/ge-organization/class-ge-organization.php';
    require_once __DIR__ . '/ge-organization/class-ge-organization-ui.php';
    GE_Organization::init();
    GE_Organization_UI::init();
}, 40);
