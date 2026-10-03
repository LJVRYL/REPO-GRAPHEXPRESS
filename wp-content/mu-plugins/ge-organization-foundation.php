<?php
/** Plugin Name: Organization Foundation v1 */
defined('ABSPATH') || exit;
// An instance is bound by deployment, never by a request parameter.
if (!defined('GE_ORGANIZATION_INSTANCE_ID')) define('GE_ORGANIZATION_INSTANCE_ID', 'graph-express');
add_action('plugins_loaded', function () {
    if (!class_exists('GE_WTP_Staff_Portal')) { return; }
    require_once __DIR__ . '/ge-organization/class-ge-organization.php';
    require_once __DIR__ . '/ge-organization/class-ge-organization-ui.php';
    require_once __DIR__ . '/ge-organization/class-ge-organization-runtime.php';
    require_once __DIR__ . '/ge-organization/class-ge-organization-export.php';
    GE_Organization::init();
    GE_Organization_UI::init();
    GE_Organization_Runtime::init();
}, 40);
