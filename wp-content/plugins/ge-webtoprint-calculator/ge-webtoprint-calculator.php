<?php
/*
 Plugin Name:  GE Web-to-Print Core
 Plugin URI:   https://graphexpress.com.ar/
 Description:  Plugin base para la plataforma Web-to-Print de Graph Express (calculadora de costos, estados de pedido, etc.).
 Author:       Graph Express
 Version:      1.0.0
 Text Domain:  ge-webtoprint
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GE_WTP_VERSION', '1.0.0' );
define( 'GE_WTP_PLUGIN_FILE', __FILE__ );
define( 'GE_WTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GE_WTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function ge_wtp_load_textdomain() {
    load_plugin_textdomain( 'ge-webtoprint', false, dirname( plugin_basename( GE_WTP_PLUGIN_FILE ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'ge_wtp_load_textdomain' );

function ge_wtp_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
}
add_action( 'init', 'ge_wtp_init' );

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

function ge_wtp_render_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'GE Web-to-Print · Panel', 'ge-webtoprint' ); ?></h1>
        <p><?php echo esc_html__( 'Base del plugin GE Web-to-Print activa. Acá vamos a ir sumando la calculadora de costos y funciones de producción.', 'ge-webtoprint' ); ?></p>
    </div>
    <?php
}
