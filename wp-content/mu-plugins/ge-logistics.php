<?php
/** Plugin Name: Graphex Logistics · coordinated deliveries */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/ge-logistics/core.php';
require_once __DIR__ . '/ge-logistics/ui.php';
add_action( 'plugins_loaded', array( 'GE_Logistics_UI', 'init' ), 30 );
