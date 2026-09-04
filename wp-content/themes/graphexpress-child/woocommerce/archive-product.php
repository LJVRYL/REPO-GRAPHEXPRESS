<?php
/**
 * Catálogo público Graph Express: familias primero, productos después.
 */

defined('ABSPATH') || exit;

$families = graphexpress_store_families();
$shop_url = graphexpress_shop_url();
$portal_url = home_url('/index.php/cliente-markcom/');
$whatsapp = 'https://wa.me/5491151393899?text=' . rawurlencode('Hola Graph Express, quiero consultar por un producto de la tienda.');
$current_term = is_product_category() ? get_queried_object() : null;
$is_store_home = is_shop() && ! is_search();
$child_terms = $current_term ? get_terms(array(
    'taxonomy'   => 'product_cat',
    'parent'     => (int) $current_term->term_id,
    'hide_empty' => false,
    'orderby'    => 'term_id',
)) : array();
$page_title = $is_store_home ? '¿Qué necesitás producir?' : woocommerce_page_title(false);
$page_description = $is_store_home
    ? 'Buscá un producto o empezá por una de nuestras cinco familias. Dentro de cada categoría vas a encontrar formatos, opciones y cantidades.'
    : ($current_term ? term_description($current_term) : 'Encontrá productos, formatos y soluciones de producción gráfica.');
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('gx-home gx-store-page'); ?>>
<?php wp_body_open(); ?>

<a class="gx-skip-link" href="#catalogo">Ir al catálogo</a>

<div class="gx-announcement"><div class="gx-wrap"><span><b>Producción gráfica integral</b> para empresas y particulares.</span><a href="tel:+5491151393899">+54 9 11 5139-3899</a></div></div>

<header class="gx-header">
    <div class="gx-wrap gx-header-inner">
        <a class="gx-logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Graph Express, inicio"><span class="gx-logo-mark">GE</span><span><strong>GRAPH EXPRESS</strong><small>Impresión que comunica</small></span></a>
        <button class="gx-menu-toggle" type="button" aria-expanded="false" aria-controls="gx-navigation"><span></span><span></span><span></span><span class="screen-reader-text">Abrir menú</span></button>
        <nav class="gx-nav" id="gx-navigation" aria-label="Navegación principal">
            <a href="<?php echo esc_url($shop_url); ?>">Tienda</a>
            <a href="<?php echo esc_url(home_url('/#servicios')); ?>">Servicios</a>
            <a href="<?php echo esc_url(home_url('/#trabajos')); ?>">Trabajos</a>
            <a href="<?php echo esc_url(home_url('/#contacto')); ?>">Contacto</a>
        </nav>
        <div class="gx-header-actions"><a class="gx-portal-link" href="<?php echo esc_url($portal_url); ?>">Portal clientes</a><a class="gx-button gx-button-small gx-button-dark" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Consultar</a></div>
    </div>
</header>

<main id="catalogo" class="gx-store-main">
    <section class="gx-store-hero">
        <div class="gx-wrap">
            <a class="gx-store-back" href="<?php echo esc_url($is_store_home ? home_url('/') : $shop_url); ?>">← <?php echo $is_store_home ? 'Volver al inicio' : 'Todas las categorías'; ?></a>
            <div class="gx-store-heading">
                <div>
                    <span class="gx-kicker"><i></i> Catálogo Graph Express</span>
                    <h1><?php echo esc_html($page_title); ?></h1>
                    <div class="gx-store-description"><?php echo wp_kses_post(wpautop($page_description)); ?></div>
                </div>
                <form class="gx-product-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                    <label class="screen-reader-text" for="gx-product-search">Buscar productos</label>
                    <span aria-hidden="true">⌕</span>
                    <input id="gx-product-search" type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="Buscar tarjetas, banners, libros…">
                    <input type="hidden" name="post_type" value="product">
                    <button type="submit">Buscar</button>
                </form>
            </div>
        </div>
    </section>

    <?php if ($is_store_home) : ?>
        <section class="gx-store-categories">
            <div class="gx-wrap">
                <div class="gx-store-section-title"><h2>Elegí una categoría</h2><span>05 familias de productos</span></div>
                <div class="gx-category-grid">
                    <?php foreach ($families as $slug => $family) :
                        $term = get_term_by('slug', $slug, 'product_cat');
                        $term_url = $term && ! is_wp_error($term) ? get_term_link($term) : add_query_arg('product_cat', $slug, $shop_url);
                    ?>
                        <a class="gx-category-card gx-category-<?php echo esc_attr($family['class']); ?>" href="<?php echo esc_url($term_url); ?>">
                            <span class="gx-category-number"><?php echo esc_html($family['number']); ?></span>
                            <span class="gx-category-symbol" aria-hidden="true"><?php echo esc_html($family['symbol']); ?></span>
                            <div>
                                <h2><?php echo esc_html($family['name']); ?></h2>
                                <p><?php echo esc_html($family['description']); ?></p>
                                <ul><?php foreach ($family['examples'] as $example) : ?><li><?php echo esc_html($example); ?></li><?php endforeach; ?></ul>
                            </div>
                            <span class="gx-category-action">Ver productos <b>→</b></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($current_term && ! empty($child_terms) && ! is_wp_error($child_terms)) : ?>
        <section class="gx-subcategory-section">
            <div class="gx-wrap">
                <div class="gx-store-section-title"><h2>Elegí un grupo</h2><span><?php echo esc_html(count($child_terms)); ?> grupos de productos</span></div>
                <div class="gx-subcategory-grid">
                    <?php foreach ($child_terms as $index => $child_term) : ?>
                        <a href="<?php echo esc_url(get_term_link($child_term)); ?>">
                            <span><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
                            <h2><?php echo esc_html($child_term->name); ?></h2>
                            <p><?php echo esc_html($child_term->description); ?></p>
                            <b><?php echo esc_html($child_term->count); ?> <?php echo 1 === (int) $child_term->count ? 'producto' : 'productos'; ?> →</b>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if (! $is_store_home || have_posts()) : ?>
        <section class="gx-products-section">
            <div class="gx-wrap">
                <div class="gx-store-section-title">
                    <h2><?php echo $is_store_home ? 'Productos destacados' : esc_html($page_title); ?></h2>
                    <?php if (woocommerce_product_loop()) : ?><span><?php woocommerce_result_count(); ?></span><?php endif; ?>
                </div>

                <?php if (woocommerce_product_loop()) : ?>
                    <?php woocommerce_catalog_ordering(); ?>
                    <?php woocommerce_product_loop_start(); ?>
                    <?php while (have_posts()) : the_post(); ?>
                        <?php wc_get_template_part('content', 'product'); ?>
                    <?php endwhile; ?>
                    <?php woocommerce_product_loop_end(); ?>
                    <?php woocommerce_pagination(); ?>
                <?php else : ?>
                    <div class="gx-empty-category">
                        <span>+</span>
                        <div><h2>Estamos preparando los productos de esta categoría.</h2><p>Mientras completamos el catálogo, podés pedirnos cualquier formato y cantidad por WhatsApp.</p></div>
                        <a class="gx-button gx-button-primary" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Consultar ahora ↗</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="gx-store-help"><div class="gx-wrap"><div><span class="gx-kicker gx-kicker-light"><i></i> ¿No encontrás lo que buscás?</span><h2>También hacemos productos a medida.</h2></div><a class="gx-button gx-button-primary" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Contanos tu idea ↗</a></div></section>
</main>

<footer class="gx-footer gx-store-footer"><div class="gx-wrap gx-footer-bottom"><span>© <?php echo esc_html(wp_date('Y')); ?> Graph Express</span><a href="<?php echo esc_url(home_url('/')); ?>">Volver a la web</a><a href="<?php echo esc_url($portal_url); ?>">Portal de clientes</a></div></footer>
<a class="gx-whatsapp-float" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener" aria-label="Contactar por WhatsApp">WA</a>
<?php wp_footer(); ?>
</body>
</html>
