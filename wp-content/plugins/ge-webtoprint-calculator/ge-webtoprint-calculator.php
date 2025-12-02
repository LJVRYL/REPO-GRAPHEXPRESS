<?php
/**
 * Plugin Name:       GE Web-to-Print Calculator
 * Plugin URI:        https://graphexpress.com.ar/
 * Description:       Funciones base para la plataforma Web-to-Print de Graph Express (estados de pedido, calculadora de costos, campos extra).
 * Version:           0.1.0
 * Author:            Graph Express
 * Author URI:        https://graphexpress.com.ar/
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Text Domain:       ge-webtoprint
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Seguridad básica: no permitir acceso directo.
}

// Constantes básicas del plugin.
define( 'GE_WTP_VERSION', '0.1.0' );
define( 'GE_WTP_PLUGIN_FILE', __FILE__ );
define( 'GE_WTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GE_WTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Cargar el textdomain del plugin (por si en el futuro usamos traducciones).
 */
function ge_wtp_load_textdomain() {
    load_plugin_textdomain(
        'ge-webtoprint',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}
add_action( 'plugins_loaded', 'ge_wtp_load_textdomain' );

/**
 * Hook de inicialización principal del plugin.
 *
 * Más adelante acá vamos a enganchar:
 * - Registro de estados personalizados de pedido WooCommerce.
 * - Campos extra en productos / pedidos.
 * - Lógica de calculadora de costos.
 */
function ge_wtp_init() {

    // Por ahora, nos aseguramos de que WooCommerce esté activo.
    if ( ! class_exists( 'WooCommerce' ) ) {
        // En el futuro podríamos mostrar un aviso en el admin si hace falta.
        return;
    }

    // TODO: cuando avancemos, vamos a ir incluyendo archivos separados, por ejemplo:
    // require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-order-status.php';
    // require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-pricing-engine.php';
}
add_action( 'init', 'ge_wtp_init', 20 );

/**
 * Registrar menú de administración Web-to-Print.
 */
function ge_wtp_register_admin_menu() {
    add_menu_page(
        __( 'GE Web-to-Print', 'ge-webtoprint' ), // Título de la página
        __( 'Web-to-Print', 'ge-webtoprint' ),    // Título del menú
        'manage_options',                         // Capability
        'ge-webtoprint',                          // Slug del menú
        'ge_wtp_render_admin_page',              // Callback
        'dashicons-printer'                       // Icono
    );
}
add_action( 'admin_menu', 'ge_wtp_register_admin_menu' );

/**
 * Renderizar la página principal del panel Web-to-Print.
 */
function ge_wtp_render_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'GE Web-to-Print · Panel', 'ge-webtoprint' ); ?></h1>
        <p>
            <?php esc_html_e(
                'Plugin base funcionando. Aquí más adelante vamos a configurar estados de pedido y calculadora de costos.',
                'ge-webtoprint'
            ); ?>
        </p>
    </div>
    <?php
}

