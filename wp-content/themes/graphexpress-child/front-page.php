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
$careers_url = class_exists('GE_WTP_Jobs') ? GE_WTP_Jobs::page_url() : home_url('/trabaja-con-nosotros/');
// Hero media is independent of the private store catalogue.
$digital_media = array(
    'src' => 'https://v1.pinimg.com/videos/iht/expMp4/45/f5/09/45f509444e96069350b9a64eaf4085e8_720w.mp4',
    'poster' => 'https://i.pinimg.com/videos/thumbnails/originals/45/f5/09/45f509444e96069350b9a64eaf4085e8.0000000.jpg',
    'source_url' => 'https://www.pinterest.com/pin/88735055153840396/',
    'credit' => 'Video: Jess Wharehinga / Pinterest',
);
$format_media = array(
    'src' => 'https://v1.pinimg.com/videos/mc/720p/fe/fe/bb/fefebb54d6a2d9330ea07d72d334bbf1.mp4',
    'poster' => 'https://i.pinimg.com/736x/eb/7c/b0/eb7cb0f048f4355a4c79184cea0dd35d.jpg',
    'source_url' => 'https://www.pinterest.com/pin/603693525061224286/',
    'credit' => 'Video: Halsall Glass / Pinterest',
);
$digital_has_video = ! empty($digital_media['src']) && ! empty($digital_media['poster']);
$format_has_video = ! empty($format_media['src']) && ! empty($format_media['poster']);
$hero_video_ids = array();
$hero_video_credits = array();
foreach (array('gx-video-format' => $format_media, 'gx-video-digital' => $digital_media) as $video_id => $media) {
    if (! empty($media['src']) && ! empty($media['poster'])) {
        $hero_video_ids[] = $video_id;
        if (! empty($media['source_url']) && ! empty($media['credit'])) {
            $media['video_id'] = $video_id;
            $hero_video_credits[] = $media;
        }
    }
}
$product_category_urls = array();
foreach (array('merchandising', 'windbanners', 'bolsas') as $category_slug) {
    $category_term = get_term_by('slug', $category_slug, 'product_cat');
    $category_link = $category_term ? get_term_link($category_term) : false;
    $product_category_urls[$category_slug] = $store_is_public && $category_link && ! is_wp_error($category_link) ? $category_link : $whatsapp;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Soluciones impresas, gráficas y editoriales para empresas, instituciones y comercios. Producción de calidad y respuesta ágil en Buenos Aires.">
    <?php wp_head(); ?>
    <noscript><style>.gx-landing-active .gx-reveal { opacity: 1; transform: none; }</style></noscript>
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

<?php graphexpress_render_site_header(array('id' => 'inicio', 'action_label' => 'Cotizar ahora', 'brand' => 'graphex')); ?>

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

            <div class="gx-hero-visual gx-reveal" aria-label="Gran formato e imprenta digital">
                <div class="gx-orbit gx-orbit-one"></div>
                <div class="gx-orbit gx-orbit-two"></div>
                <figure class="gx-hero-image gx-hero-image-main">
                    <img class="gx-hero-fallback" src="<?php echo esc_url($asset_uri . 'graphex-gran-formato.jpg'); ?>" alt="Aplicación gráfica de gran formato" fetchpriority="high" decoding="async">
                    <?php if ($format_has_video) : ?>
                    <video id="gx-video-format" data-gx-hero-video muted loop playsinline preload="none" data-poster="<?php echo esc_url($format_media['poster']); ?>" aria-label="Impresión de gran formato en acción">
                        <source data-src="<?php echo esc_url($format_media['src']); ?>" type="video/mp4">
                        <a href="<?php echo esc_url($format_media['src']); ?>">Ver video de gran formato</a>
                    </video>
                    <?php endif; ?>
                    <figcaption>Gran formato</figcaption>
                </figure>
                <figure class="gx-hero-image gx-hero-image-float">
                    <img class="gx-hero-fallback" src="<?php echo esc_url($asset_uri . 'graphex-offset-digital.jpg'); ?>" alt="Piezas de impresión offset y digital" decoding="async">
                    <?php if ($digital_has_video) : ?>
                    <video id="gx-video-digital" data-gx-hero-video muted loop playsinline preload="none" data-poster="<?php echo esc_url($digital_media['poster']); ?>" aria-label="Impresión digital en acción">
                        <source data-src="<?php echo esc_url($digital_media['src']); ?>" type="video/mp4">
                        <a href="<?php echo esc_url($digital_media['src']); ?>">Ver video de impresión digital</a>
                    </video>
                    <?php endif; ?>
                    <figcaption>Imprenta digital</figcaption>
                </figure>
                <div class="gx-floating-note">
                    <span class="gx-note-icon">✓</span>
                    <span><b>De punta a punta</b><small>Diseño, impresión y entrega</small></span>
                </div>
                <?php if ($hero_video_ids) : ?>
                    <div class="gx-media-controls" hidden>
                        <button class="gx-video-toggle" type="button" aria-controls="<?php echo esc_attr(implode(' ', $hero_video_ids)); ?>" hidden>Reproducir videos</button>
                        <?php if ($hero_video_credits) : ?>
                            <details class="gx-video-credits"><summary>Créditos de video</summary><div><?php foreach ($hero_video_credits as $media) : ?><a data-gx-video-credit="<?php echo esc_attr($media['video_id']); ?>" href="<?php echo esc_url($media['source_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($media['credit']); ?></a><?php endforeach; ?></div></details>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="gx-trust gx-trust-clients" aria-labelledby="gx-client-heading">
        <div class="gx-wrap gx-trust-grid">
            <h2 id="gx-client-heading">Algunas organizaciones para las que realizamos trabajos</h2>
            <ul class="gx-client-names" aria-label="Organizaciones">
                <li class="gx-client-item" data-client="ameport"><img class="gx-client-logo" src="<?php echo esc_url($asset_uri . 'clients/ameport-color.png'); ?>" alt="AMEPORT" width="1626" height="963" loading="lazy" decoding="async"></li>
                <li class="gx-client-item" data-client="fao"><img class="gx-client-logo gx-client-logo--fao" src="<?php echo esc_url($asset_uri . 'clients/fao-logo-blue-3lines-es.svg'); ?>" alt="FAO — Organización de las Naciones Unidas para la Alimentación y la Agricultura" width="196" height="43" loading="lazy" decoding="async"></li>
                <li class="gx-client-item" data-client="unodc"><img class="gx-client-logo gx-client-logo--unodc" src="<?php echo esc_url($asset_uri . 'clients/unodc-logo-es.svg'); ?>" alt="UNODC — Oficina de las Naciones Unidas contra la Droga y el Delito" width="394" height="102" loading="lazy" decoding="async"></li>
                <li class="gx-client-item" data-client="ypf"><img class="gx-client-logo gx-client-logo--ypf" src="<?php echo esc_url($asset_uri . 'clients/ypf-logo-azul.svg'); ?>" alt="YPF" width="74" height="20" loading="lazy" decoding="async"></li>
                <li class="gx-client-item" data-client="multiplex"><img class="gx-client-logo gx-client-logo--multiplex" src="<?php echo esc_url($asset_uri . 'clients/multiplex-logo-oficial.png'); ?>" alt="Cines Multiplex" width="470" height="267" loading="lazy" decoding="async"></li>
                <li class="gx-client-item" data-client="claro"><img class="gx-client-logo gx-client-logo--claro" src="<?php echo esc_url($asset_uri . 'clients/claro-logo-rojo.svg'); ?>" alt="Claro" width="90" height="32" loading="lazy" decoding="async"></li>
                <li class="gx-client-item" data-client="zte"><img class="gx-client-logo gx-client-logo--zte" src="<?php echo esc_url($asset_uri . 'clients/zte-official-blue.png'); ?>" alt="ZTE" width="1535" height="907" loading="lazy" decoding="async"></li>
            </ul>
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
                <article class="gx-service-card gx-service-photo gx-service-offset gx-reveal">
                    <img src="<?php echo esc_url($asset_uri . 'graphex-offset-digital.jpg'); ?>" alt="Pliegos impresos a color a la salida de una prensa" width="899" height="1348" loading="lazy" decoding="async">
                    <div class="gx-service-content">
                        <span class="gx-service-number">01</span>
                        <h3>Offset & digital</h3>
                        <p>Papelería comercial, folletos, carpetas, catálogos y tiradas cortas o de alto volumen con excelente definición.</p>
                        <a class="gx-inline-link" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Cotizar impresión <span>↗</span></a>
                    </div>
                </article>

                <article class="gx-service-card gx-service-photo gx-service-digital gx-reveal">
                    <img src="<?php echo esc_url($asset_uri . 'graphex-gran-formato.jpg'); ?>" alt="Instalación de gráfica de gran formato en una fachada comercial" width="952" height="672" loading="lazy" decoding="async">
                    <div class="gx-service-content">
                        <span class="gx-service-number">02</span>
                        <h3>Gran formato</h3>
                        <p>Banners, lonas, vinilos, cartelería, stands y gráfica para puntos de venta.</p>
                        <a class="gx-inline-link" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Cotizar gran formato <span>↗</span></a>
                    </div>
                </article>

                <article class="gx-service-card gx-service-photo gx-service-editorial gx-reveal">
                    <img src="<?php echo esc_url($asset_uri . 'graphex-editorial.jpg'); ?>" alt="Revista abierta con diseño editorial sobre fondo celeste" width="1200" height="1680" loading="lazy" decoding="async">
                    <div class="gx-service-content">
                        <span class="gx-service-number">03</span>
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
                <p>Merchandising, windbanners y bolsas personalizadas para que tu marca se vea en cada detalle.</p>
            </div>

            <div class="gx-work-grid">
                <a class="gx-work-card gx-work-wide gx-work-merch gx-reveal" href="<?php echo esc_url($product_category_urls['merchandising']); ?>">
                    <img src="<?php echo esc_url($asset_uri . 'graphex-merchandising.webp'); ?>" alt="Muestra de merchandising GRAPHEX: botella, taza, libreta, lapicera y bolsa de tela con la G a color" width="1254" height="1254" loading="lazy" decoding="async">
                    <div><span>Regalos con identidad</span><h3>Merchandising</h3><span class="gx-work-action">Ver productos ↗</span></div>
                </a>
                <a class="gx-work-card gx-work-windbanners gx-reveal" href="<?php echo esc_url($product_category_urls['windbanners']); ?>">
                    <img src="<?php echo esc_url($asset_uri . 'graphex-windbanners.webp'); ?>" alt="Muestra de windbanners negro y blanco con el logo a color de GRAPHEX" width="1774" height="887" loading="lazy" decoding="async">
                    <div><span>Tu marca en movimiento</span><h3>Windbanners</h3><span class="gx-work-action">Ver modelos ↗</span></div>
                </a>
                <a class="gx-work-card gx-work-bags gx-reveal" href="<?php echo esc_url($product_category_urls['bolsas']); ?>">
                    <img src="<?php echo esc_url($asset_uri . 'graphex-bolsas.webp'); ?>" alt="Muestra de bolsas de papel negra y blanca con la nueva G de GRAPHEX impresa a color" width="1774" height="887" loading="lazy" decoding="async">
                    <div><span>Tu próximo proyecto</span><h3>Bolsas personalizadas</h3><span class="gx-work-action">Ver bolsas ↗</span></div>
                </a>
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
            <span class="gx-contact-word" aria-hidden="true">GRAPHEX</span>
        </div>
    </section>

    <?php if ( class_exists( 'GE_WTP_Newsletter' ) ) { echo GE_WTP_Newsletter::signup_block(); } ?>
</main>

<footer class="gx-footer">
    <div class="gx-wrap gx-footer-main">
        <div>
            <a class="gx-logo gx-logo-light gx-graphex-logo" href="#inicio" aria-label="GRAPHEX, inicio"><img class="gx-graphex-symbol" src="<?php echo esc_url($asset_uri . 'graphex-simbolo.svg'); ?>" alt="" width="52" height="52"><span><strong>GRAPHEX</strong><small>Impresión que comunica</small></span></a>
            <p>Soluciones gráficas integrales para empresas, instituciones y comercios.</p>
        </div>
        <div><h3>Servicios</h3><a href="#servicios">Offset & digital</a><a href="#servicios">Gran formato</a><a href="#servicios">Gráfica editorial</a></div>
        <div><h3>Contacto</h3><a href="tel:+5491151393899">+54 9 11 5139-3899</a><a href="mailto:imprentagraphexpress@gmail.com">Enviar un email</a><a href="<?php echo esc_url($careers_url); ?>">Trabajá con nosotros</a><span>Microcentro, CABA</span></div>
        <div><h3>Tienda & clientes</h3><a href="<?php echo esc_url($shop_url); ?>"><?php echo $store_is_public ? 'Ver productos' : 'Tienda próximamente'; ?></a><a href="<?php echo esc_url($portal_url); ?>">Ingresar al portal</a><a href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener">Solicitar cotización</a></div>
    </div>
    <div class="gx-wrap gx-footer-bottom"><span>© <?php echo esc_html(wp_date('Y')); ?> GRAPHEX · Graph Express</span><span>Hecho para imprimir grandes ideas.</span></div>
</footer>

<a class="gx-whatsapp-float" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener" aria-label="Contactar por WhatsApp">WA</a>

<?php wp_footer(); ?>
</body>
</html>
