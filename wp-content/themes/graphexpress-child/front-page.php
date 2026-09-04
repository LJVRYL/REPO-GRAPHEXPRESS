<?php
/**
 * Graph Express — portada comercial.
 *
 * Si la página de inicio fue construida con Elementor, respetamos su contenido.
 * Mientras tanto, esta plantilla entrega una landing completa y autocontenida.
 */

defined('ABSPATH') || exit;

$front_page_id = (int) get_option('page_on_front');
$is_elementor_page = false;

if ($front_page_id && did_action('elementor/loaded')) {
    $is_elementor_page = \Elementor\Plugin::$instance->db->is_built_with_elementor($front_page_id);
}

if ($is_elementor_page) {
    get_header();
    while (have_posts()) {
        the_post();
        the_content();
    }
    get_footer();
    return;
}

$asset_uri = trailingslashit(get_stylesheet_directory_uri()) . 'assets/images/';
$whatsapp = 'https://wa.me/5491151393899?text=' . rawurlencode('Hola Graph Express, quiero solicitar una cotización.');
$portal_page = get_page_by_path('cliente-markcom');
$portal_url = $portal_page ? get_permalink($portal_page) : home_url('/cliente-markcom/');
$shop_url = graphexpress_shop_url();
$store_is_public = graphexpress_store_is_public();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Soluciones impresas, gráficas y editoriales para empresas, instituciones y comercios. Producción de calidad y respuesta ágil en Buenos Aires.">
    <?php wp_head(); ?>
</head>
<body <?php body_class('gx-home'); ?>>
<?php wp_body_open(); ?>

<a class="gx-skip-link" href="#contenido">Ir al contenido</a>

<div class="gx-announcement">
    <div class="gx-wrap">
        <span><b>10 años</b> convirtiendo ideas en piezas impresas.</span>
        <a href="tel:+5491151393899">+54 9 11 5139-3899</a>
    </div>
</div>

<header class="gx-header" id="inicio">
    <div class="gx-wrap gx-header-inner">
        <a class="gx-logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Graph Express, inicio">
            <span class="gx-logo-mark">GE</span>
            <span><strong>GRAPH EXPRESS</strong><small>Impresión que comunica</small></span>
        </a>

        <button class="gx-menu-toggle" type="button" aria-expanded="false" aria-controls="gx-navigation">
            <span></span><span></span><span></span><span class="screen-reader-text">Abrir menú</span>
        </button>

        <nav class="gx-nav" id="gx-navigation" aria-label="Navegación principal">
            <a href="<?php echo esc_url($shop_url); ?>"><?php echo $store_is_public ? 'Tienda' : 'Tienda · Próximamente'; ?></a>
            <a href="#servicios">Servicios</a>
            <a href="#trabajos">Trabajos</a>
            <a href="#proceso">Cómo trabajamos</a>
            <a href="#contacto">Contacto</a>
        </nav>

        <div class="gx-header-actions">
            <a class="gx-portal-link" href="<?php echo esc_url($portal_url); ?>">Portal clientes</a>
            <a class="gx-button gx-button-small gx-button-dark" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Cotizar ahora</a>
        </div>
    </div>
</header>

<?php if (! $store_is_public) : ?>
    <section class="gx-store-coming" id="tienda-proximamente" aria-label="Próxima apertura de la tienda">
        <div class="gx-wrap">
            <span><b>Tienda online</b> Estamos preparando el catálogo público.</span>
            <a href="<?php echo esc_url($portal_url); ?>">Clientes con acceso: ingresar al portal →</a>
        </div>
    </section>
<?php endif; ?>

<main id="contenido">
    <section class="gx-hero">
        <div class="gx-wrap gx-hero-grid">
            <div class="gx-hero-copy gx-reveal">
                <span class="gx-kicker"><i></i> Producción gráfica integral · Buenos Aires</span>
                <h1>Ideas que se vuelven <em>impresión.</em></h1>
                <p>Diseñamos y producimos gráfica de calidad para empresas e instituciones: desde una pieza editorial hasta una campaña completa en punto de venta.</p>
                <div class="gx-hero-actions">
                    <a class="gx-button gx-button-primary" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Pedir una cotización <span>↗</span></a>
                    <a class="gx-text-link" href="#trabajos">Ver qué hacemos <span>↓</span></a>
                </div>
                <div class="gx-hero-proof">
                    <div><strong>10+</strong><span>años de experiencia</span></div>
                    <div><strong>3</strong><span>líneas de producción</span></div>
                    <div><strong>24 h</strong><span>respuesta comercial</span></div>
                </div>
            </div>

            <div class="gx-hero-visual gx-reveal" aria-label="Muestra de trabajos gráficos">
                <div class="gx-orbit gx-orbit-one"></div>
                <div class="gx-orbit gx-orbit-two"></div>
                <figure class="gx-hero-image gx-hero-image-main">
                    <img src="<?php echo esc_url($asset_uri . 'hero-stationery.jpg'); ?>" alt="Papelería institucional impresa">
                </figure>
                <figure class="gx-hero-image gx-hero-image-float">
                    <img src="<?php echo esc_url($asset_uri . 'service-display.jpg'); ?>" alt="Banners de gran formato">
                </figure>
                <div class="gx-floating-note">
                    <span class="gx-note-icon">✓</span>
                    <span><b>De punta a punta</b><small>Diseño, impresión y entrega</small></span>
                </div>
            </div>
        </div>
    </section>

    <section class="gx-trust" aria-label="Clientes">
        <div class="gx-wrap gx-trust-grid">
            <p>Empresas e instituciones que confiaron en nosotros</p>
            <div class="gx-client-names">
                <span>BBVA</span><span>Banco Nación</span><span>McDonald's</span><span>Ministerio Público Fiscal</span><span>ZTE</span>
            </div>
        </div>
    </section>

    <section class="gx-section gx-services" id="servicios">
        <div class="gx-wrap">
            <div class="gx-section-heading gx-reveal">
                <div>
                    <span class="gx-kicker"><i></i> Todo en un mismo lugar</span>
                    <h2>Una solución para cada formato.</h2>
                </div>
                <p>Te ayudamos a elegir el sistema, soporte y terminación adecuados para que cada pieza se vea bien y cumpla su objetivo.</p>
            </div>

            <div class="gx-service-grid">
                <article class="gx-service-card gx-service-primary gx-reveal">
                    <div class="gx-service-number">01</div>
                    <div class="gx-service-icon" aria-hidden="true">
                        <svg viewBox="0 0 48 48"><path d="M10 7h23l5 5v29H10z"/><path d="M33 7v7h7M16 23h16M16 29h12M16 35h9"/></svg>
                    </div>
                    <div>
                        <h3>Offset & digital</h3>
                        <p>Papelería comercial, folletos, carpetas, catálogos y tiradas cortas o de alto volumen con excelente definición.</p>
                        <ul><li>Respuesta ágil</li><li>Múltiples terminaciones</li><li>Control de archivos</li></ul>
                    </div>
                </article>

                <article class="gx-service-card gx-service-image gx-reveal">
                    <img src="<?php echo esc_url($asset_uri . 'service-display.jpg'); ?>" alt="Producción de banners y cartelería">
                    <div class="gx-service-overlay">
                        <span>02</span>
                        <h3>Gran formato</h3>
                        <p>Banners, lonas, vinilos, cartelería, stands y gráfica para puntos de venta.</p>
                        <a href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener" aria-label="Cotizar gran formato">↗</a>
                    </div>
                </article>

                <article class="gx-service-card gx-service-light gx-reveal">
                    <div class="gx-service-number">03</div>
                    <div class="gx-service-icon gx-service-icon-yellow" aria-hidden="true">
                        <svg viewBox="0 0 48 48"><path d="M8 10h14c4 0 6 2 6 6v24c0-4-2-6-6-6H8z"/><path d="M40 10H26M40 10v24H26M14 17h8M14 23h8"/></svg>
                    </div>
                    <div>
                        <h3>Gráfica editorial</h3>
                        <p>Libros, revistas, informes, balances y publicaciones institucionales cuidadas de principio a fin.</p>
                        <a class="gx-inline-link" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Consultar proyecto <span>→</span></a>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="gx-section gx-value-section">
        <div class="gx-wrap gx-value-grid">
            <div class="gx-value-copy gx-reveal">
                <span class="gx-kicker gx-kicker-light"><i></i> Producción sin vueltas</span>
                <h2>Tu proyecto, bien resuelto.</h2>
                <p>Nos ocupamos de los detalles técnicos para que vos puedas concentrarte en comunicar. Revisamos archivos, recomendamos materiales y coordinamos la entrega.</p>
                <a class="gx-button gx-button-primary" href="#proceso">Conocé el proceso <span>↓</span></a>
            </div>
            <div class="gx-value-list">
                <article class="gx-reveal"><b>01</b><div><h3>Asesoramiento real</h3><p>No vendemos un formato porque sí. Buscamos la opción que mejor funciona para tu necesidad y presupuesto.</p></div></article>
                <article class="gx-reveal"><b>02</b><div><h3>Calidad controlada</h3><p>Revisión previa y seguimiento durante la producción para evitar sorpresas en el resultado final.</p></div></article>
                <article class="gx-reveal"><b>03</b><div><h3>Entrega coordinada</h3><p>Distribución puerta a puerta en CABA y coordinación logística para el resto del país.</p></div></article>
            </div>
        </div>
    </section>

    <section class="gx-section gx-work" id="trabajos">
        <div class="gx-wrap">
            <div class="gx-section-heading gx-reveal">
                <div>
                    <span class="gx-kicker"><i></i> Productos y posibilidades</span>
                    <h2>Gráfica que trabaja para tu marca.</h2>
                </div>
                <p>Una selección de formatos que podemos diseñar, producir y entregar. Próximamente cada categoría tendrá su catálogo completo.</p>
            </div>

            <div class="gx-work-grid">
                <article class="gx-work-card gx-work-wide gx-reveal">
                    <img src="<?php echo esc_url($asset_uri . 'work-editorial.jpg'); ?>" alt="Papelería corporativa y editorial">
                    <div><span>Empresas</span><h3>Papelería corporativa</h3></div>
                </article>
                <article class="gx-work-card gx-reveal">
                    <img src="<?php echo esc_url($asset_uri . 'work-display.jpg'); ?>" alt="Banners y displays para eventos">
                    <div><span>Gran formato</span><h3>Eventos & puntos de venta</h3></div>
                </article>
                <article class="gx-work-card gx-work-color gx-reveal">
                    <div class="gx-work-placeholder">
                        <span>Tu próximo proyecto</span>
                        <strong>Packaging, merchandising y piezas especiales.</strong>
                        <a href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Hablemos ↗</a>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="gx-section gx-process" id="proceso">
        <div class="gx-wrap">
            <div class="gx-process-intro gx-reveal">
                <span class="gx-kicker"><i></i> Cómo trabajamos</span>
                <h2>Simple, claro y acompañado.</h2>
            </div>
            <div class="gx-process-steps">
                <article class="gx-reveal"><span>01</span><div class="gx-step-symbol">⌁</div><h3>Nos contás</h3><p>Compartís la idea, cantidades, medidas y fecha que necesitás.</p></article>
                <article class="gx-reveal"><span>02</span><div class="gx-step-symbol">⌕</div><h3>Revisamos</h3><p>Chequeamos archivos y recomendamos materiales y terminaciones.</p></article>
                <article class="gx-reveal"><span>03</span><div class="gx-step-symbol">✓</div><h3>Confirmamos</h3><p>Recibís una cotización clara y el cronograma de producción.</p></article>
                <article class="gx-reveal"><span>04</span><div class="gx-step-symbol">→</div><h3>Producimos</h3><p>Imprimimos, controlamos y coordinamos la entrega final.</p></article>
            </div>
        </div>
    </section>

    <section class="gx-contact" id="contacto">
        <div class="gx-wrap gx-contact-card gx-reveal">
            <div>
                <span class="gx-kicker gx-kicker-light"><i></i> Empecemos</span>
                <h2>¿Qué necesitás imprimir?</h2>
                <p>Mandanos las medidas, cantidades y una referencia. Te orientamos y preparamos una cotización.</p>
            </div>
            <div class="gx-contact-actions">
                <a class="gx-button gx-button-primary" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Hablar por WhatsApp <span>↗</span></a>
                <a href="mailto:imprentagraphexpress@gmail.com">imprentagraphexpress@gmail.com</a>
            </div>
            <span class="gx-contact-word">EXPRESS</span>
        </div>
    </section>
</main>

<footer class="gx-footer">
    <div class="gx-wrap gx-footer-main">
        <div>
            <a class="gx-logo gx-logo-light" href="#inicio"><span class="gx-logo-mark">GE</span><span><strong>GRAPH EXPRESS</strong><small>Impresión que comunica</small></span></a>
            <p>Soluciones gráficas integrales para empresas, instituciones y comercios.</p>
        </div>
        <div><h3>Servicios</h3><a href="#servicios">Offset & digital</a><a href="#servicios">Gran formato</a><a href="#servicios">Gráfica editorial</a></div>
        <div><h3>Contacto</h3><a href="tel:+5491151393899">+54 9 11 5139-3899</a><a href="mailto:imprentagraphexpress@gmail.com">Enviar un email</a><span>Microcentro, CABA</span></div>
        <div><h3>Tienda & clientes</h3><a href="<?php echo esc_url($shop_url); ?>"><?php echo $store_is_public ? 'Ver productos' : 'Tienda próximamente'; ?></a><a href="<?php echo esc_url($portal_url); ?>">Ingresar al portal</a><a href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Solicitar cotización</a></div>
    </div>
    <div class="gx-wrap gx-footer-bottom"><span>© <?php echo esc_html(wp_date('Y')); ?> Graph Express</span><span>Hecho para imprimir grandes ideas.</span></div>
</footer>

<a class="gx-whatsapp-float" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener" aria-label="Contactar por WhatsApp">WA</a>

<?php wp_footer(); ?>
</body>
</html>
