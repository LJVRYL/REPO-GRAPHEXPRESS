<?php
/** Plugin Name: Graphex · Restricted Meta review */
defined('ABSPATH') || exit;
add_action('plugins_loaded',function() {
    if (!class_exists('GE_CRM_Outbound') || !class_exists('GE_Organization')) return;
    require_once __DIR__.'/ge-crm/class-ge-meta-review-policy.php';
    require_once __DIR__.'/ge-crm/class-ge-meta-review.php';
    GE_Meta_Review::init();
},100);
