<?php
/** Plugin Name: Graphex CRM · Own Meta asset verification */
defined('ABSPATH') || exit;
add_action('plugins_loaded',function(){
    if (!class_exists('GE_CRM_Outbound') || !class_exists('GE_CRM_UI')) return;
    require_once __DIR__.'/ge-crm/class-ge-crm-meta-assets.php';
    GE_CRM_Meta_Assets::init();
},95);
