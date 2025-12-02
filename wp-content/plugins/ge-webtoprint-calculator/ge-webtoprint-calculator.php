<?php
/**
 * Plugin Name:       GE Web-to-Print Core
 * Plugin URI:        https://graphexpress.com.ar/
 * Description:       Plugin base para la plataforma Web-to-Print de Graph Express (calculadora de costos, estados de pedido, etc.).
 * Version:           1.0.0
 * Author:            Graph Express
 * Author URI:        https://graphexpress.com.ar/
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       ge-webtoprint
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Constantes básicas del plugin.
define( 'GE_WTP_VERSION', '1.0.0' );
define( 'GE_WTP_PLUGIN_FILE', __FILE__ );
define( 'GE_WTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GE_WTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Carga el textdomain del plugin.
 */
function ge_wtp_load_textdomain() {
    load_plugin_textdomain(
        'ge-webtoprint',
        false,
        dirname( plugin_basename( GE_WTP_PLUGIN_FILE ) ) . '/languages'
    );
}
add_action( 'plugins_loaded', 'ge_wtp_load_textdomain' );

/**
 * Hook de inicialización principal del plugin.
 */
function ge_wtp_init() {
    // Aseguramos que WooCommerce esté activo.
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    // Aquí en el futuro iremos cargando archivos con lógica extra.
    // require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-order-status.php';
    // require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-pricing-engine.php';
}
add_action( 'init', 'ge_wtp_init' );

/**
 * Registra el menú de administración Web-to-Print.
 */
function ge_wtp_register_admin_menu() {
    add_menu_page(
        __( 'GE Web-to-Print', 'ge-webtoprint' ),
        __( 'Web-to-Print', 'ge-webtoprint' ),
        'manage_options',
        'ge-webtoprint',
        'ge_wtp_render_admin_page',
        'dashicons-printer'
    );
}
add_action( 'admin_menu', 'ge_wtp_register_admin_menu' );

/**
 * Renderiza la página principal del panel Web-to-Print.
 */
function ge_wtp_render_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'GE Web-to-Print · Panel', 'ge-webtoprint' ); ?></h1>
        <p><?php echo esc_html__( 'Base del plugin GE Web-to-Print activa. Acá vamos a ir sumando la calculadora de costos y funciones de producción.', 'ge-webtoprint' ); ?></p>
    </div>
    <?php
}

