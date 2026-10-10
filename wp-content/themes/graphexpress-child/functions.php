<?php
/**
 * Funciones del child theme Graph Express.
 */

defined('ABSPATH') || exit;
require_once get_stylesheet_directory() . '/inc/graphex-brand.php';

/** Short quote entry and category URLs resolved from Woo taxonomy, never guessed paths. */
function graphexpress_quote_url($company = false) {
    $extra=array('seccion'=>'personalizado','rapida'=>'1');
    if(!is_user_logged_in())$extra['modo']='registro';
    if($company)$extra['cuenta']='empresa';
    return class_exists('GE_WTP_Portal')?GE_WTP_Portal::portal_url('', $extra):add_query_arg($extra,home_url('/cliente-markcom/'));
}
function graphexpress_conversion_category($slug) {
    $term=get_term_by('slug',$slug,'product_cat');
    if($term && $term->count>0) { $url=get_term_link($term); if(!is_wp_error($url))return $url; }
    return graphexpress_quote_url();
}
function graphexpress_funnel_assets() {
    if(is_admin() || is_page('gestion'))return;
    wp_enqueue_script('graphex-funnel-events',get_stylesheet_directory_uri().'/assets/js/funnel-events.js',array(),filemtime(get_stylesheet_directory().'/assets/js/funnel-events.js'),false);
}
add_action('wp_enqueue_scripts','graphexpress_funnel_assets',19);


function graphexpress_child_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo');
    add_theme_support('woocommerce');
    add_theme_support('html5', array('search-form', 'gallery', 'caption', 'style', 'script'));
}
add_action('after_setup_theme', 'graphexpress_child_setup');

/**
 * Reutiliza la G vigente de GRAPHEX como favicon público.
 */
function graphexpress_render_brand_favicon() {
    $icon_url = get_stylesheet_directory_uri() . '/assets/images/graphex-simbolo.svg';
    echo '<link rel="icon" href="' . esc_url($icon_url) . '" type="image/svg+xml" sizes="any">' . "\n";
    echo '<link rel="shortcut icon" href="' . esc_url($icon_url) . '" type="image/svg+xml">' . "\n";
}

function graphexpress_use_brand_favicon() {
    remove_action('wp_head', 'wp_site_icon', 99);
    add_action('wp_head', 'graphexpress_render_brand_favicon', 2);
}
add_action('init', 'graphexpress_use_brand_favicon');

function graphexpress_child_enqueue_styles() {
    wp_enqueue_style(
        'graphexpress-parent-style',
        get_template_directory_uri() . '/style.css',
        array(),
        wp_get_theme(get_template())->get('Version')
    );

    wp_enqueue_style(
        'graphexpress-child-style',
        get_stylesheet_uri(),
        array('graphexpress-parent-style'),
        (string) filemtime(get_stylesheet_directory() . '/style.css')
    );

    if (is_front_page()) {
        wp_enqueue_style(
            'graphex-landing',
            get_stylesheet_directory_uri() . '/assets/css/graphex-landing.css',
            array('graphexpress-child-style'),
            (string) filemtime(get_stylesheet_directory() . '/assets/css/graphex-landing.css')
        );
        wp_enqueue_script(
            'graphex-landing-video',
            get_stylesheet_directory_uri() . '/assets/js/graphex-landing.js',
            array(),
            (string) filemtime(get_stylesheet_directory() . '/assets/js/graphex-landing.js'),
            true
        );
    }

    // El encabezado público se reutiliza también en carrito, checkout, guías
    // y páginas provistas por el plugin. El controlador del menú debe estar
    // disponible en todas ellas.
    wp_enqueue_script(
        'graphexpress-home',
        get_stylesheet_directory_uri() . '/assets/js/home.js',
        array(),
        (string) filemtime(get_stylesheet_directory() . '/assets/js/home.js'),
        true
    );
}
add_action('wp_enqueue_scripts', 'graphexpress_child_enqueue_styles', 20);

/**
 * Encabezado único para todas las páginas públicas de Graph Express.
 *
 * @param array $args Opciones: active (shop|guides|careers), action_label, id y brand.
 */
function graphexpress_render_site_header($args = array()) {
    $args = wp_parse_args($args, array(
        'active'       => '',
        'action_label' => 'Consultar',
        'id'           => '',
        'brand'        => 'graphex',
    ));

    $shop_url    = graphexpress_shop_url();
    $guides_url  = class_exists('GE_WTP_Knowledge_Base') ? GE_WTP_Knowledge_Base::archive_url() : home_url('/guias/');
    $careers_url = class_exists('GE_WTP_Jobs') ? GE_WTP_Jobs::page_url() : home_url('/trabaja-con-nosotros/');
    $portal_url  = class_exists('GE_WTP_Portal') ? GE_WTP_Portal::portal_url() : home_url('/cliente-markcom/');
    $cart_url    = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
    $cart_count  = function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    $quote_url=graphexpress_quote_url();
    $portal_label=is_user_logged_in()?'Portal':'Ingresar';
    $store_label = graphexpress_store_is_public() ? 'Tienda' : 'Tienda · Próximamente';
    ?>
    <header class="gx-header"<?php echo $args['id'] ? ' id="' . esc_attr($args['id']) . '"' : ''; ?>>
        <div class="gx-wrap gx-header-inner">
            <?php if ('graphex' === $args['brand']) : ?>
                <a class="gx-logo gx-graphex-logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="GRAPHEX, inicio"><img class="gx-graphex-symbol" src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/images/graphex-simbolo.svg'); ?>" alt="" width="52" height="52"><span><strong>GRAPHEX</strong><small>Impresión que comunica</small></span></a>
            <?php else : ?>
                <a class="gx-logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Graph Express, inicio"><span class="gx-logo-mark">GE</span><span><strong>GRAPH EXPRESS</strong><small>Impresión que comunica</small></span></a>
            <?php endif; ?>
            <button class="gx-menu-toggle" type="button" aria-expanded="false" aria-controls="gx-navigation"><span></span><span></span><span></span><span class="screen-reader-text">Abrir menú</span></button>
            <nav class="gx-nav" id="gx-navigation" aria-label="Navegación principal">
                <a<?php echo 'shop' === $args['active'] ? ' class="is-active" aria-current="page"' : ''; ?> href="<?php echo esc_url($shop_url); ?>" data-funnel-event="landing_shop_click"><?php echo esc_html($store_label); ?></a>
                <a href="<?php echo esc_url($quote_url); ?>" data-funnel-event="landing_quote_click">Presupuesto</a>
                <a<?php echo 'guides' === $args['active'] ? ' class="is-active" aria-current="page"' : ''; ?> href="<?php echo esc_url($guides_url); ?>">Guías</a>

                <a<?php echo 'careers' === $args['active'] ? ' class="is-active" aria-current="page"' : ''; ?> href="<?php echo esc_url($careers_url); ?>">Trabajá con nosotros</a>
                <?php if(is_user_logged_in()&&class_exists('GE_WTP_Portal')&&GE_WTP_Portal::is_customer_user()): ?><a href="<?php echo esc_url(GE_WTP_Portal::portal_url('solicitudes')); ?>">Mis solicitudes</a><?php endif; ?>
                <a class="gx-nav-mobile-action is-portal" href="<?php echo esc_url($portal_url); ?>"><?php echo esc_html($portal_label); ?></a>
                <a class="gx-nav-mobile-action is-cart" href="<?php echo esc_url($cart_url); ?>">Carrito<?php if ($cart_count) : ?> <b><?php echo esc_html($cart_count); ?></b><?php endif; ?></a>
                <a class="gx-nav-mobile-action is-consult" href="<?php echo esc_url($quote_url); ?>" data-funnel-event="landing_quote_click">Pedir una cotización</a>
            </nav>
            <div class="gx-header-actions">
                <a class="gx-portal-link" href="<?php echo esc_url($portal_url); ?>"><?php echo esc_html($portal_label); ?></a>
                <a class="gx-portal-link gx-cart-link" href="<?php echo esc_url($cart_url); ?>">Carrito<?php if ($cart_count) : ?> <b><?php echo esc_html($cart_count); ?></b><?php endif; ?></a>
                <a class="gx-button gx-button-small gx-button-dark" href="<?php echo esc_url($quote_url); ?>" data-funnel-event="landing_quote_click"><?php echo 'Pedir una cotización'; ?></a>
            </div>
        </div>
    </header>
    <?php
}

function graphexpress_child_body_class($classes) {
    if (is_front_page()) {
        $classes[] = 'gx-landing-active';
    }
    return $classes;
}
add_filter('body_class', 'graphexpress_child_body_class');

/**
 * Devuelve una URL de tienda segura incluso antes de ejecutar el asistente de WooCommerce.
 */
function graphexpress_shop_url() {
    if (! graphexpress_store_is_public()) {
        return home_url('/#tienda-proximamente');
    }

    if (function_exists('wc_get_page_permalink')) {
        return wc_get_page_permalink('shop');
    }

    return home_url('/index.php/shop/');
}

/**
 * La tienda puede mantenerse privada mientras se completa el catálogo.
 */
function graphexpress_is_local_site() {
    $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    return in_array($host, array('localhost', '127.0.0.1', '::1'), true) || 'local' === wp_get_environment_type();
}

function graphexpress_store_is_public() {
    if (graphexpress_is_local_site()) {
        return true;
    }
    return 'public' === (string) get_option('graphexpress_store_visibility', 'private');
}

function graphexpress_hide_storefront_until_launch() {
    // Allow administrators to verify the checkout while the public store stays private.
    if (graphexpress_store_is_public() || is_admin() || wp_doing_ajax() || current_user_can('manage_options')) {
        return;
    }

    if (function_exists('is_woocommerce') && (is_shop() || is_product() || is_product_category() || is_product_tag() || is_cart() || is_checkout())) {
        wp_safe_redirect(home_url('/#tienda-proximamente'), 302);
        exit;
    }
}
add_action('template_redirect', 'graphexpress_hide_storefront_until_launch', 5);

/**
 * Datos visuales de las familias principales del catálogo.
 */
function graphexpress_store_families() {
    return array(
        'imprenta-digital' => array(
            'number'      => '01',
            'name'        => 'Imprenta digital',
            'description' => 'Producción rápida y flexible para tiradas cortas y personalizadas.',
            'examples'    => array('Tarjetas', 'Carpetas', 'Folletos', 'Talonarios', 'Papelería'),
            'class'       => 'yellow',
            'symbol'      => '▤',
            'media'       => array(
                'type'       => 'video',
                'src'        => 'https://v1.pinimg.com/videos/iht/expMp4/45/f5/09/45f509444e96069350b9a64eaf4085e8_720w.mp4',
                'poster'     => 'https://i.pinimg.com/videos/thumbnails/originals/45/f5/09/45f509444e96069350b9a64eaf4085e8.0000000.jpg',
                'source_url' => 'https://www.pinterest.com/pin/88735055153840396/',
                'label'      => 'Impresión digital en acción',
                'credit'     => 'Video: Jess Wharehinga / Pinterest',
            ),
        ),
        'imprenta-offset' => array(
            'number'      => '02',
            'name'        => 'Imprenta offset',
            'description' => 'Calidad y eficiencia para grandes cantidades y proyectos especiales.',
            'examples'    => array('Anotadores', 'Afiches', 'Folletos', 'Carpetas', 'Packaging'),
            'class'       => 'ink',
            'symbol'      => '◎',
            'media'       => array(
                'type'       => 'video',
                'src'        => 'https://v1.pinimg.com/videos/iht/720p/f3/20/27/f32027b63c6565ad571551d80e834823.mp4',
                'poster'     => 'https://i.pinimg.com/videos/thumbnails/originals/f3/20/27/f32027b63c6565ad571551d80e834823.0000000.jpg',
                'source_url' => 'https://www.pinterest.com/pin/33003009765848303/',
                'label'      => 'Producción offset en acción',
                'credit'     => 'Video: aman sharma / Pinterest',
            ),
        ),
        'gran-formato' => array(
            'number'      => '03',
            'name'        => 'Gran formato',
            'description' => 'Gráfica de alto impacto para espacios, eventos y puntos de venta.',
            'examples'    => array('Banners', 'Windflags', 'Vinilos', 'Cartelería', 'Displays'),
            'class'       => 'violet',
            'symbol'      => '↗',
            'media'       => array(
                'type'       => 'video',
                'src'        => 'https://v1.pinimg.com/videos/mc/720p/fe/fe/bb/fefebb54d6a2d9330ea07d72d334bbf1.mp4',
                'poster'     => 'https://i.pinimg.com/736x/eb/7c/b0/eb7cb0f048f4355a4c79184cea0dd35d.jpg',
                'source_url' => 'https://www.pinterest.com/pin/603693525061224286/',
                'label'      => 'Impresión UV en acción',
                'credit'     => 'Video: Halsall Glass / Pinterest',
            ),
        ),
        'windbanners' => array(
            'number'      => '04',
            'name'        => 'Windbanners',
            'description' => 'Sistemas textiles y exhibidores para eventos, puntos de venta y comunicación exterior.',
            'examples'    => array('Fly banners', 'Banderas', 'Bases', 'Carpas', 'Displays'),
            'class'       => 'blue',
            'symbol'      => '⚑',
            'media'       => array(
                'type'  => 'image',
                'src'   => get_stylesheet_directory_uri() . '/assets/images/windbanners-graph-express.png',
                'label' => 'Windbanners personalizados',
            ),
        ),
        'merchandising' => array(
            'number'      => '05',
            'name'        => 'Merchandising',
            'description' => 'Objetos personalizados para campañas, equipos y regalos corporativos.',
            'examples'    => array('Lanyards', 'Lapiceras', 'Botellas', 'Libretas', 'Regalos'),
            'class'       => 'green',
            'symbol'      => '✦',
        ),
        'bolsas' => array(
            'number'      => '06',
            'name'        => 'Bolsas',
            'description' => 'Bolsas reutilizables y packaging para comercios, marcas y envíos.',
            'examples'    => array('Friselina', 'Lienzo', 'E-commerce', 'Personalizadas'),
            'class'       => 'coral',
            'symbol'      => '▱',
        ),
        'linea-ecologica' => array(
            'number'      => '07',
            'name'        => 'Línea ecológica',
            'description' => 'Alternativas reutilizables y materiales seleccionados para comunicar con menor impacto.',
            'examples'    => array('Bambú', 'Corcho', 'Algodón', 'Reutilizables', 'Reciclados'),
            'class'       => 'eco',
            'symbol'      => '♻',
        ),
        'editorial' => array(
            'number'      => '08',
            'name'        => 'Editorial',
            'description' => 'Publicaciones cuidadas en cada detalle, desde el archivo a la encuadernación.',
            'examples'    => array('Libros', 'Catálogos', 'Revistas', 'Balances', 'Memorias'),
            'class'       => 'paper',
            'symbol'      => '▥',
        ),
    );
}

/**
 * Los productos sin precio cerrado funcionan como solicitudes de cotización.
 */
function graphexpress_quote_only_product_cta() {
    global $product;

    if (! $product || 'yes' !== $product->get_meta('_ge_quote_only')) {
        return;
    }

    if ($product->get_meta('_ge_digital_config')) {
        return;
    }

    $message = rawurlencode('Hola Graph Express, quiero cotizar: ' . $product->get_name() . '.');
    echo '<div class="gx-single-quote">';
    echo '<p>Este producto se cotiza según medida, cantidad y terminaciones.</p>';
    echo '<a class="gx-button gx-button-primary" target="_blank" rel="noopener" href="' . esc_url('https://wa.me/5491151393899?text=' . $message) . '">Solicitar cotización ↗</a>';
    echo '</div>';
}
add_action('woocommerce_single_product_summary', 'graphexpress_quote_only_product_cta', 31);

/**
 * Agrega una tabla ordenada de formatos, cantidades y valores de referencia.
 */
function graphexpress_product_price_tabs($tabs) {
    global $product;

    if ($product && $product->get_meta('_ge_public_price_sections')) {
        $tabs['gx_formats_prices'] = array(
            'title'    => 'Formatos y precios',
            'priority' => 15,
            'callback' => 'graphexpress_render_product_price_tables',
        );
    }

    return $tabs;
}
add_filter('woocommerce_product_tabs', 'graphexpress_product_price_tabs');

function graphexpress_render_product_price_tables() {
    global $product;

    if (! $product) {
        return;
    }

    $sections = $product->get_meta('_ge_public_price_sections');
    $notes = $product->get_meta('_ge_public_price_notes');
    if (! is_array($sections)) {
        return;
    }

    echo '<div class="gx-price-guide">';
    $price_intro = 'Los importes publicados son valores Graph Express antes de IVA. Confirmamos disponibilidad y valor final al solicitar la cotización.';
    if (0 === strpos((string) $product->get_meta('_ge_public_catalog_key'), 'windbanners-') && class_exists('GE_WTP_Windbanners_Catalog')) {
        $tax_context = GE_WTP_Windbanners_Catalog::tax_context();
        $price_intro = 'Los importes de la tabla son valores netos Graph Express. ' . ($tax_context ? $tax_context['note'] : 'Consultanos para validar el comprobante y el precio final.');
    }
    echo '<div class="gx-price-guide-intro"><span>Valores de referencia</span><h2>Elegí formato y cantidad</h2><p>' . esc_html($price_intro) . '</p></div>';

    foreach ($sections as $section) {
        if (empty($section['columns']) || empty($section['rows'])) {
            continue;
        }

        echo '<section class="gx-price-section">';
        if (! empty($section['title'])) {
            echo '<h3>' . esc_html($section['title']) . '</h3>';
        }
        echo '<div class="gx-price-table-scroll"><table><thead><tr>';
        foreach ($section['columns'] as $column) {
            echo '<th scope="col">' . esc_html($column) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($section['rows'] as $row) {
            echo '<tr>';
            foreach ($row as $index => $cell) {
                $tag = 0 === $index ? 'th' : 'td';
                $scope = 0 === $index ? ' scope="row"' : '';
                echo '<' . $tag . $scope . '>' . esc_html($cell) . '</' . $tag . '>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
    }

    if (is_array($notes) && $notes) {
        echo '<aside class="gx-price-notes"><h3>Terminaciones y condiciones</h3><ul>';
        foreach ($notes as $note) {
            echo '<li>' . esc_html($note) . '</li>';
        }
        echo '</ul></aside>';
    }
    echo '</div>';
}
