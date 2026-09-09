<?php
defined('ABSPATH') || exit;
$shop_url = graphexpress_shop_url();
$portal_url = home_url('/index.php/cliente-markcom/');
$guides_url = class_exists('GE_WTP_Knowledge_Base') ? GE_WTP_Knowledge_Base::archive_url() : home_url('/guias/');
$cart_count = function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
?><!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class('gx-home gx-store-page gx-commerce-page'); ?>><?php wp_body_open(); ?>
<div class="gx-announcement"><div class="gx-wrap"><span><b>Producción gráfica integral</b> para empresas y particulares.</span><a href="tel:+5491151393899">+54 9 11 5139-3899</a></div></div>
<header class="gx-header"><div class="gx-wrap gx-header-inner">
<a class="gx-logo" href="<?php echo esc_url(home_url('/')); ?>"><span class="gx-logo-mark">GE</span><span><strong>GRAPH EXPRESS</strong><small>Impresión que comunica</small></span></a>
<nav class="gx-nav"><a class="is-active" href="<?php echo esc_url($shop_url); ?>">Tienda</a><a href="<?php echo esc_url($guides_url); ?>">Guías</a><a href="<?php echo esc_url(home_url('/#servicios')); ?>">Servicios</a><a href="<?php echo esc_url(home_url('/#contacto')); ?>">Contacto</a></nav>
<div class="gx-header-actions"><a class="gx-portal-link" href="<?php echo esc_url($portal_url); ?>">Portal clientes</a><a class="gx-portal-link gx-cart-link" href="<?php echo esc_url(wc_get_cart_url()); ?>">Carrito <b><?php echo esc_html($cart_count); ?></b></a></div>
</div></header>
<main class="gx-store-main gx-commerce-main"><div class="gx-wrap"><a class="gx-store-back" href="<?php echo esc_url($shop_url); ?>">← Seguir comprando</a><div class="gx-commerce-heading"><span>Tu pedido</span><h1>Carrito</h1><p>Revisá productos, configuraciones y cantidades antes de continuar.</p></div><?php while (have_posts()) : the_post(); the_content(); endwhile; ?></div></main>
<footer class="gx-footer gx-store-footer"><div class="gx-wrap gx-footer-bottom"><span>© <?php echo esc_html(wp_date('Y')); ?> Graph Express</span><a href="<?php echo esc_url($shop_url); ?>">Tienda</a><a href="<?php echo esc_url($portal_url); ?>">Portal de clientes</a></div></footer>
<?php wp_footer(); ?></body></html>
