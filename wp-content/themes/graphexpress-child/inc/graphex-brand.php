<?php
/** Presentation layer for the public GRAPHEX site and customer portal. */
defined('ABSPATH') || exit;

/** Use the approved artwork when WordPress has no configured site icon. */
function graphex_brand_site_icon_url($url, $size, $blog_id) {
    if ($blog_id && (int) $blog_id !== get_current_blog_id()) {
        return $url;
    }
    if (get_option('site_icon') || ($url !== '' && $url !== includes_url('images/w-logo-gray-white-bg.png'))) {
        return $url;
    }
    $icon_size = 512;
    foreach (array(32, 180, 192, 270, 512) as $available_size) {
        if ((int) $size <= $available_size) {
            $icon_size = $available_size;
            break;
        }
    }
    $relative = '/assets/images/favicon/graphex-' . $icon_size . '.png';
    if (! is_file(get_stylesheet_directory() . $relative)) {
        return $url;
    }
    return add_query_arg('v', '20260916', get_stylesheet_directory_uri() . $relative);
}
add_filter('get_site_icon_url', 'graphex_brand_site_icon_url', 10, 3);

function graphex_brand_enabled() {
    return ! is_admin() && ! is_page('gestion');
}

function graphex_brand_body_classes($classes) {
    if (graphex_brand_enabled()) {
        $classes[] = 'gx-brand-active';
    }
    return $classes;
}
add_filter('body_class', 'graphex_brand_body_classes');

function graphex_brand_assets() {
    if (! graphex_brand_enabled()) {
        return;
    }
    // Existing plugin styles, including the guide contrast layer, load first.
    $dependencies = array_values(wp_styles()->queue);
    $base = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    foreach (array('brand', 'commerce', 'content') as $layer) {
        $relative = '/assets/css/graphex-' . $layer . '.css';
        wp_enqueue_style('graphex-' . $layer, $uri . $relative, $dependencies, (string) filemtime($base . $relative));
        $dependencies = array('graphex-' . $layer);
    }
}
add_action('wp_enqueue_scripts', 'graphex_brand_assets', 100);

function graphex_brand_login_assets() {
    wp_enqueue_style('graphex-login', get_stylesheet_directory_uri() . '/assets/css/graphex-login.css', array(), (string) filemtime(get_stylesheet_directory() . '/assets/css/graphex-login.css'));
}
add_action('login_enqueue_scripts', 'graphex_brand_login_assets');
add_filter('login_headerurl', function () { return home_url('/'); });
add_filter('login_headertext', function () { return 'GRAPHEX'; });

function graphex_render_brand_logo($class = '', $url = '') {
    $url = $url ?: home_url('/');
    ?><a class="gx-logo gx-graphex-logo <?php echo esc_attr($class); ?>" href="<?php echo esc_url($url); ?>" aria-label="GRAPHEX, inicio"><img class="gx-graphex-symbol" src="<?php echo esc_url(get_stylesheet_directory_uri() . '/assets/images/graphex-simbolo.svg'); ?>" width="52" height="52" alt=""><span><strong>GRAPHEX</strong><small>Impresión que comunica</small></span></a><?php
}

function graphex_render_site_footer() {
    $shop_url = graphexpress_shop_url();
    $portal_url = class_exists('GE_WTP_Portal') ? GE_WTP_Portal::portal_url() : home_url('/cliente-markcom/');
    $careers_url = class_exists('GE_WTP_Jobs') ? GE_WTP_Jobs::page_url() : home_url('/trabaja-con-nosotros/');
    ?>
    <footer class="gx-footer">
        <div class="gx-wrap gx-footer-main">
            <div><?php graphex_render_brand_logo('gx-logo-light'); ?><p>Soluciones gráficas integrales para empresas, instituciones y comercios.</p></div>
            <div><h3>Servicios</h3><a href="<?php echo esc_url(home_url('/#servicios')); ?>">Offset & digital</a><a href="<?php echo esc_url(home_url('/#servicios')); ?>">Gran formato</a><a href="<?php echo esc_url(home_url('/#servicios')); ?>">Gráfica editorial</a></div>
            <div><h3>Contacto</h3><a href="tel:+5491151393899">+54 9 11 5139-3899</a><a href="mailto:imprentagraphexpress@gmail.com">Enviar un email</a><a href="<?php echo esc_url($careers_url); ?>">Trabajá con nosotros</a><span>Microcentro, CABA</span></div>
            <div><h3>Tienda & clientes</h3><a href="<?php echo esc_url($shop_url); ?>">Ver productos</a><a href="<?php echo esc_url($portal_url); ?>">Ingresar al portal</a><a href="<?php echo esc_url(graphexpress_quote_url()); ?>" data-funnel-event="landing_quote_click">Solicitar cotización</a></div>
        </div>
        <div class="gx-wrap gx-footer-bottom"><span>© <?php echo esc_html(wp_date('Y')); ?> GRAPHEX · Graph Express</span><span>Hecho para imprimir grandes ideas.</span></div>
    </footer>
    <?php
}

function graphex_brand_shell($template) {
    if (! defined('GE_WTP_PLUGIN_DIR')) {
        return $template;
    }
    // Only presentation shells are overridden; shortcode/auth handlers stay in the plugin.
    foreach (array('careers-shell.php', 'portal-shell.php', 'knowledge-archive.php', 'knowledge-single.php') as $shell) {
        if (wp_normalize_path($template) === wp_normalize_path(GE_WTP_PLUGIN_DIR . 'templates/' . $shell)) {
            return get_stylesheet_directory() . '/templates/graphex/' . $shell;
        }
    }
    return $template;
}
add_filter('template_include', 'graphex_brand_shell', 120);

function graphex_brand_portal_markup($html) {
    $symbol = '<img class="ge-brand-mark gx-portal-symbol" src="' . esc_url(get_stylesheet_directory_uri() . '/assets/images/graphex-simbolo.svg') . '" width="52" height="52" alt="">';
    $html = str_replace('<span class="ge-brand-mark">GX</span>', $symbol, $html);
    $html = str_replace('<span class="ge-brand-name">GRAPH EXPRESS</span>', '<span class="ge-brand-name">GRAPHEX</span>', $html);
    return preg_replace('~(<a class="ge-portal-logo"[^>]*>.*?)<strong>GRAPH EXPRESS</strong>~s', '$1<strong>GRAPHEX</strong>', $html, 1);
}
