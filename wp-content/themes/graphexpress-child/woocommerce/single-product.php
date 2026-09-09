<?php
/**
 * Ficha individual de producto con la identidad visual de la tienda Graph Express.
 */

defined('ABSPATH') || exit;

$shop_url = graphexpress_shop_url();
$portal_url = home_url('/index.php/cliente-markcom/');
$guides_url = class_exists('GE_WTP_Knowledge_Base') ? GE_WTP_Knowledge_Base::archive_url() : home_url('/guias/');
$whatsapp = 'https://wa.me/5491151393899?text=' . rawurlencode('Hola Graph Express, quiero consultar por un producto de la tienda.');
$cart_count = function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('gx-home gx-store-page gx-single-product-page'); ?>>
<?php wp_body_open(); ?>

<a class="gx-skip-link" href="#producto">Ir al producto</a>

<div class="gx-announcement"><div class="gx-wrap"><span><b>Producción gráfica integral</b> para empresas y particulares.</span><a href="tel:+5491151393899">+54 9 11 5139-3899</a></div></div>

<header class="gx-header">
    <div class="gx-wrap gx-header-inner">
        <a class="gx-logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Graph Express, inicio"><span class="gx-logo-mark">GE</span><span><strong>GRAPH EXPRESS</strong><small>Impresión que comunica</small></span></a>
        <button class="gx-menu-toggle" type="button" aria-expanded="false" aria-controls="gx-navigation"><span></span><span></span><span></span><span class="screen-reader-text">Abrir menú</span></button>
        <nav class="gx-nav" id="gx-navigation" aria-label="Navegación principal">
            <a class="is-active" href="<?php echo esc_url($shop_url); ?>">Tienda</a>
            <a href="<?php echo esc_url($guides_url); ?>">Guías</a>
            <a href="<?php echo esc_url(home_url('/#servicios')); ?>">Servicios</a>
            <a href="<?php echo esc_url(home_url('/#trabajos')); ?>">Trabajos</a>
            <a href="<?php echo esc_url(home_url('/#contacto')); ?>">Contacto</a>
        </nav>
        <div class="gx-header-actions"><a class="gx-portal-link" href="<?php echo esc_url($portal_url); ?>">Portal clientes</a><a class="gx-portal-link gx-cart-link" href="<?php echo esc_url(wc_get_cart_url()); ?>">Carrito<?php if ($cart_count) : ?> <b><?php echo esc_html($cart_count); ?></b><?php endif; ?></a><a class="gx-button gx-button-small gx-button-dark" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Consultar</a></div>
    </div>
</header>

<main id="producto" class="gx-store-main gx-single-product-main">
    <div class="gx-wrap">
        <a class="gx-store-back" href="<?php echo esc_url(wp_get_referer() ?: $shop_url); ?>">← Volver al catálogo</a>
        <?php wc_print_notices(); ?>
        <div class="gx-single-product-content">
            <?php
            do_action('woocommerce_before_main_content');

            while (have_posts()) {
                the_post();
                wc_get_template_part('content', 'single-product');
            }

            do_action('woocommerce_after_main_content');
            ?>
        </div>
    </div>
</main>

<section class="gx-store-help"><div class="gx-wrap"><div><span class="gx-kicker gx-kicker-light"><i></i> ¿Necesitás otra medida o terminación?</span><h2>También hacemos productos a medida.</h2></div><a class="gx-button gx-button-primary" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Contanos tu idea ↗</a></div></section>

<footer class="gx-footer gx-store-footer"><div class="gx-wrap gx-footer-bottom"><span>© <?php echo esc_html(wp_date('Y')); ?> Graph Express</span><a href="<?php echo esc_url(home_url('/')); ?>">Volver a la web</a><a href="<?php echo esc_url($portal_url); ?>">Portal de clientes</a></div></footer>
<a class="gx-whatsapp-float" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener" aria-label="Contactar por WhatsApp">WA</a>
<?php wp_footer(); ?>
</body>
</html>
