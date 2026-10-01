<?php
/**
 * Plugin Name:       GE Web-to-Print Core
 * Plugin URI:        https://graphexpress.com.ar/
 * Description:       Portal privado, catálogo corporativo y gestión de pedidos de Graph Express.
 * Version:           2.16.3
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

define( 'GE_WTP_VERSION', '2.16.3' );
define( 'GE_WTP_PLUGIN_FILE', __FILE__ );
define( 'GE_WTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GE_WTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-public-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-roll-pricing.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-mardones-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-digital-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-quote-api.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-windbanners-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-msbags-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-marketing-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-promotional-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-documents.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-issued-documents.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-order-lifecycle.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-orders.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-quotes.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-quote-balance.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-commercial-quote-catalog.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-commercial-quotes.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-commercial-quote-ui.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-commercial-checkout.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-billing.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-customer-branches.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-reorders.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-artwork-library.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-delivery-labels.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-notifications.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-notification-center.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-knowledge-base.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-google-auth.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-turnstile.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-vps-storage.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-canva.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-product-images.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-storefront.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-payments.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-review-requests.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-production.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-workflow.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-workflow-dispatch.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-supplier-dispatch.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-manual-orders.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-newsletter.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-customers.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-customer-workspace.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-customer-quotes.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-jobs.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-portal.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-backoffice.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-staff-portal.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-admin.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-plugin.php';

register_activation_hook( __FILE__, array( 'GE_WTP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GE_WTP_Plugin', 'deactivate' ) );

GE_WTP_Plugin::instance();
GE_WTP_Storefront::init();
GE_WTP_Payments::init();
