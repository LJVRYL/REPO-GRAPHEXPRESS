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
<?php graphexpress_render_site_header(array('active' => 'shop')); ?>
<main class="gx-store-main gx-commerce-main"><div class="gx-wrap"><a class="gx-store-back" href="<?php echo esc_url(wc_get_cart_url()); ?>">← Volver al carrito</a><div class="gx-commerce-heading"><span>Último paso</span><h1>Finalizar pedido</h1><p>Completá tus datos de facturación, entrega y forma de pago.</p></div><?php while (have_posts()) : the_post(); the_content(); endwhile; ?></div></main>
<footer class="gx-footer gx-store-footer"><div class="gx-wrap gx-footer-bottom"><span>© <?php echo esc_html(wp_date('Y')); ?> Graph Express</span><a href="<?php echo esc_url($shop_url); ?>">Tienda</a><a href="<?php echo esc_url($portal_url); ?>">Portal de clientes</a></div></footer>
<?php wp_footer(); ?></body></html>
