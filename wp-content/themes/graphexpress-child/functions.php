<?php
/**
 * Funciones del child theme GraphExpress.
 */

/**
 * Encola estilos del tema padre y del child.
 */
function graphexpress_child_enqueue_styles() {
    // Encolar primero el style.css del tema padre
    wp_enqueue_style(
        'graphexpress-parent-style',
        get_template_directory_uri() . '/style.css',
        array(),
        null
    );

    // Encolar el style.css del child
    wp_enqueue_style(
        'graphexpress-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array('graphexpress-parent-style'),
        '1.0.0'
    );
}
add_action('wp_enqueue_scripts', 'graphexpress_child_enqueue_styles');
