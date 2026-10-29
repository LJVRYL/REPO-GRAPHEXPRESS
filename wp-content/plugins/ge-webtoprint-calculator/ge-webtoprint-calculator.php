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
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-commercial-quote-files.php';
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
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-supplier-portal.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-supplier-dispatch.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-supplier-workspace.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-manual-orders.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-newsletter.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-email-branding.php';
GE_WTP_Email_Branding::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-email-core.php';
GE_WTP_Email_Core::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-email-content.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-email-woocommerce.php';
GE_WTP_Email_WooCommerce::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-email-preview.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-email-templates.php';
GE_WTP_Email_Templates::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-customers.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-customer-workspace.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-customer-quotes.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-jobs.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-portal.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-backoffice.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-staff-portal.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-admin.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-plugin.php';
foreach(array('operations','operations-stock','operations-finance','operations-equipment','operations-ui') as $module) require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-'.$module.'.php';
GE_WTP_Operations::init();
foreach(array('cost-math','cost-engine','cost-products','cost-ui','cost-assist','cost-quotes','cost-legacy') as $module) require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-'.$module.'.php';
GE_WTP_Cost_Engine::init();GE_WTP_Cost_Quotes::init();
GE_WTP_Cost_UI::init();

register_activation_hook( __FILE__, array( 'GE_WTP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GE_WTP_Plugin', 'deactivate' ) );

GE_WTP_Plugin::instance();
GE_WTP_Storefront::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-prints.php';
GE_WTP_Deco_Prints::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-gallery.php';
GE_WTP_Deco_Gallery::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-uploads.php';
GE_WTP_Deco_Uploads::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-proofs.php';
GE_WTP_Deco_Proofs::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-release.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-payments.php';
GE_WTP_Deco_Payments::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-observations.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-drive.php';
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-creators.php';
GE_WTP_Deco_Drive::init();
GE_WTP_Deco_Creators::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-marketplace.php';
GE_WTP_Deco_Marketplace::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-marketplace-checkout.php';
GE_WTP_Deco_Marketplace_Checkout::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-marketplace-webhooks.php';
GE_WTP_Deco_Marketplace_Webhooks::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-deco-artist-commerce.php';
GE_WTP_Deco_Artist_Commerce::init();
GE_WTP_Payments::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-business-export.php';
GE_WTP_Business_Export::init();

// Scoped additive card configuration and native CCAC draft access.
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-unlaminated-cards.php';
GE_WTP_Unlaminated_Cards::init();
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-ccac-native-api.php';
GE_WTP_CCAC_Native_API::init();

// Historical standalone UI withdrawn; retain stored declaration history without loading it.
add_action('template_redirect', static function () {
    if (is_page('gestion') && isset($_GET['ge_work_reconciliation'])) {
        nocache_headers();
        wp_die('Acceso no disponible.', '', array('response' => 403));
    }
}, 0);
foreach (array('ge_organization_reconciliation_save', 'ge_organization_reconciliation_download') as $ge_retired_action) {
    foreach (array('admin_post_', 'admin_post_nopriv_') as $ge_retired_prefix) {
        add_action($ge_retired_prefix . $ge_retired_action, static function () {
            nocache_headers();
            wp_die('Acceso no disponible.', '', array('response' => 403));
        });
    }
}
unset($ge_retired_action, $ge_retired_prefix);

// Registered-user design references; isolated from commercial calculation and order flows.
require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-registered-templates.php';
GE_WTP_Registered_Templates::init();

require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-catalog-reviewed-media.php';

require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-flags-product-experience.php';
