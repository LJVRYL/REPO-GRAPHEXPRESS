<?php
/** Shared brand shell for pages rendered by the parent theme. */
defined('ABSPATH') || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class('gx-generic-page'); ?>>
<?php wp_body_open(); ?>
<?php do_action('haru_before_page_main'); ?>
<div id="haru-main">
    <a class="gx-skip-link" href="#haru-content-main">Ir al contenido</a>
    <div class="gx-announcement"><div class="gx-wrap"><span><b>Producción gráfica integral</b> · Buenos Aires</span><a href="tel:+5491151393899">+54 9 11 5139-3899</a></div></div>
    <?php graphexpress_render_site_header(); ?>
    <div id="haru-content-main" class="clearfix">
        <?php do_action('haru_main_content_start'); ?>
