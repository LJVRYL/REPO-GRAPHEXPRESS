<?php
/**
 * Funciones del child theme Printing Services Child
 *
 * Acá vamos a ir agregando hooks y filtros a medida que avancemos.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Seguridad básica: no permitir acceso directo.
}

/**
 * Cargar estilos del tema padre y del child.
 *
 * Nota: el handle del tema padre ('printing-services-style') es una suposición.
 * Cuando tengamos el tema instalado, podemos ajustar el handle si hace falta.
 */
function ge_printing_services_child_enqueue_styles() {
    $parent_style = 'printing-services-style'; // TODO: ajustar si el tema padre usa otro handle.

    // Estilo del tema padre
    wp_enqueue_style(
        $parent_style,
        get_template_directory_uri() . '/style.css'
    );

    // Estilo del child theme
    wp_enqueue_style(
        'printing-services-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( $parent_style ),
        wp_get_theme()->get( 'Version' )
    );
}
add_action( 'wp_enqueue_scripts', 'ge_printing_services_child_enqueue_styles' );
