<?php
/**
 * Plugin Name: Graph Express · Español de tienda
 * Description: Traducciones puntuales de la tienda sin modificar WooCommerce ni Mercado Pago.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'gettext', static function ( $translated, $original ) {
    if ( 'Related Products' === $original || 'Related products' === $original ) { return 'Productos relacionados'; }
    return $translated;
}, 20, 2 );

add_action( 'wp_enqueue_scripts', static function () {
    if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) ) { return; }
    wp_enqueue_script( 'ge-store-es-ar', content_url( '/mu-plugins/ge-store-es-ar.js' ), array( 'wp-i18n', 'wp-hooks' ), '20260926', false );
}, 99 );
