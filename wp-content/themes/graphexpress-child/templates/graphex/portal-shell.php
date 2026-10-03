<?php
/**
 * Standalone shell for the private Markcom portal.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'ge-markcom-standalone' ); ?>>
<?php wp_body_open(); ?>
<a class="gx-skip-link" href="#ge-markcom-app">Ir al portal</a>
<?php graphexpress_render_site_header(array('active' => 'portal')); ?>
<main id="ge-markcom-app">
    <?php echo graphex_brand_portal_markup(do_shortcode('[ge_markcom_portal]')); // Presentation only; plugin retains validation, nonces and authorization. ?>
</main>
<?php graphex_render_site_footer(); ?>
<?php wp_footer(); ?>
</body>
</html>
