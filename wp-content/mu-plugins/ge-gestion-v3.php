<?php
/** Plugin Name: Graphex Gestión v3 · shell y referencia comercial */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'plugins_loaded', static function() {
    if ( defined( 'GE_WTP_PLUGIN_DIR' ) ) {
        require_once GE_WTP_PLUGIN_DIR . 'includes/class-ge-wtp-gestion-v3.php';
        GE_WTP_Gestion_V3::init();
    }
}, 30 );
