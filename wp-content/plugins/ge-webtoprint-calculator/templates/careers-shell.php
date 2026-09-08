<?php
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Dejanos tu perfil para futuras oportunidades laborales en Graph Express.">
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'ge-careers-page' ); ?>>
<?php wp_body_open(); ?>
<?php
$shop_url = function_exists( 'graphexpress_shop_url' ) ? graphexpress_shop_url() : home_url( '/shop/' );
$portal_url = GE_WTP_Portal::portal_url();
$whatsapp = 'https://wa.me/5491151393899?text=' . rawurlencode( 'Hola Graph Express, quiero solicitar una cotización.' );
?>
<div class="gx-announcement"><div class="gx-wrap"><span><b>10 años</b> convirtiendo ideas en piezas impresas.</span><a href="tel:+5491151393899">+54 9 11 5139-3899</a></div></div>
<header class="gx-header" id="inicio">
    <div class="gx-wrap gx-header-inner">
        <a class="gx-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Graph Express, inicio"><span class="gx-logo-mark">GE</span><span><strong>GRAPH EXPRESS</strong><small>Impresión que comunica</small></span></a>
        <button class="gx-menu-toggle" type="button" aria-expanded="false" aria-controls="gx-navigation"><span></span><span></span><span></span><span class="screen-reader-text">Abrir menú</span></button>
        <nav class="gx-nav" id="gx-navigation" aria-label="Navegación principal"><a href="<?php echo esc_url( $shop_url ); ?>">Tienda</a><a href="<?php echo esc_url( class_exists( 'GE_WTP_Knowledge_Base' ) ? GE_WTP_Knowledge_Base::archive_url() : home_url( '/guias/' ) ); ?>">Guías</a><a href="<?php echo esc_url( home_url( '/#servicios' ) ); ?>">Servicios</a><a href="<?php echo esc_url( home_url( '/#trabajos' ) ); ?>">Trabajos</a><a href="<?php echo esc_url( home_url( '/#proceso' ) ); ?>">Cómo trabajamos</a><a class="is-active" href="#contenido">Trabajá con nosotros</a></nav>
        <div class="gx-header-actions"><a class="gx-portal-link" href="<?php echo esc_url( $portal_url ); ?>">Portal clientes</a><a class="gx-button gx-button-small gx-button-dark" href="<?php echo esc_url( $whatsapp ); ?>" target="_blank" rel="noopener">Cotizar ahora</a></div>
    </div>
</header>
<?php while ( have_posts() ) : the_post(); the_content(); endwhile; ?>
<footer class="gx-footer"><div class="gx-wrap gx-footer-main"><div><a class="gx-logo gx-logo-light" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="gx-logo-mark">GE</span><span><strong>GRAPH EXPRESS</strong><small>Impresión que comunica</small></span></a><p>Soluciones gráficas integrales para empresas, instituciones y comercios.</p></div><div><h3>Servicios</h3><a href="<?php echo esc_url( home_url( '/#servicios' ) ); ?>">Offset & digital</a><a href="<?php echo esc_url( home_url( '/#servicios' ) ); ?>">Gran formato</a><a href="<?php echo esc_url( home_url( '/#servicios' ) ); ?>">Gráfica editorial</a></div><div><h3>Contacto</h3><a href="tel:+5491151393899">+54 9 11 5139-3899</a><a href="mailto:imprentagraphexpress@gmail.com">Enviar un email</a><span>Microcentro, CABA</span></div><div><h3>Tienda & clientes</h3><a href="<?php echo esc_url( $shop_url ); ?>">Ver productos</a><a href="<?php echo esc_url( $portal_url ); ?>">Ingresar al portal</a><a href="#contenido">Trabajá con nosotros</a></div></div><div class="gx-wrap gx-footer-bottom"><span>© <?php echo esc_html( wp_date( 'Y' ) ); ?> Graph Express</span><span>Hecho para imprimir grandes ideas.</span></div></footer>
<a class="gx-whatsapp-float" href="<?php echo esc_url( $whatsapp ); ?>" target="_blank" rel="noopener" aria-label="Contactar por WhatsApp">WA</a>
<?php wp_footer(); ?>
</body>
</html>
