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
<main id="ge-markcom-app">
    <?php echo do_shortcode( '[ge_markcom_portal]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
