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
