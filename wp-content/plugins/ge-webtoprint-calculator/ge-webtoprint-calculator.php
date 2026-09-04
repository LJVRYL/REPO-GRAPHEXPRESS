<?php
/**
 * Plugin Name:       GE Web-to-Print Core
 * Plugin URI:        https://graphexpress.com.ar/
 * Description:       Portal privado, catálogo corporativo y gestión de pedidos de Graph Express.
 * Version:           1.2.0
 * Author:            Graph Express
 * Author URI:        https://graphexpress.com.ar/
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Text Domain:       ge-webtoprint
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GE_WTP_VERSION', '1.2.0' );
define( 'GE_WTP_PLUGIN_FILE', __FILE__ );
define( 'GE_WTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GE_WTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-public-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-mardones-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-digital-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-documents.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-orders.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-portal.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-admin.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-plugin.php';

register_activation_hook( __FILE__, array( 'GE_WTP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GE_WTP_Plugin', 'deactivate' ) );

GE_WTP_Plugin::instance();
