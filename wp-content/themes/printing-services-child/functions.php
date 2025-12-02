<?php
/**
 * Funciones del child theme Printing Services Child.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Seguridad básica: no permitir acceso directo.
}

/**
 * Encolar estilos del tema padre y del child theme.
 */
function ge_ps_child_enqueue_styles() {
    $parent_style_handle = 'printing-services-parent-style';

    wp_enqueue_style(
        $parent_style_handle,
        get_template_directory_uri() . '/style.css',
        array(),
        wp_get_theme( 'printing-services' )->get( 'Version' )
    );

    wp_enqueue_style(
        'printing-services-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( $parent_style_handle ),
        wp_get_theme()->get( 'Version' )
    );
}
add_action( 'wp_enqueue_scripts', 'ge_ps_child_enqueue_styles' );
