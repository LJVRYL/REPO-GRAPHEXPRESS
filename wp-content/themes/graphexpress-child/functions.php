<?php
/**
 * Funciones del child theme Graph Express.
 */

defined('ABSPATH') || exit;

function graphexpress_child_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo');
    add_theme_support('woocommerce');
    add_theme_support('html5', array('search-form', 'gallery', 'caption', 'style', 'script'));
}
add_action('after_setup_theme', 'graphexpress_child_setup');

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
        wp_get_theme()->get('Version')
    );

    if (is_front_page() || (function_exists('is_woocommerce') && is_woocommerce()) || is_search()) {
        wp_enqueue_script(
            'graphexpress-home',
            get_stylesheet_directory_uri() . '/assets/js/home.js',
            array(),
            wp_get_theme()->get('Version'),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'graphexpress_child_enqueue_styles', 20);

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
    if (graphexpress_store_is_public() || is_admin() || wp_doing_ajax()) {
        return;
    }

    if (function_exists('is_woocommerce') && (is_shop() || is_product() || is_product_category() || is_product_tag() || is_cart() || is_checkout())) {
        wp_safe_redirect(home_url('/#tienda-proximamente'), 302);
        exit;
    }
}
add_action('template_redirect', 'graphexpress_hide_storefront_until_launch', 5);

/**
 * Datos visuales de las cinco familias principales del catálogo.
 */
function graphexpress_store_families() {
    return array(
        'gran-formato' => array(
            'number'      => '01',
            'name'        => 'Gran formato',
            'description' => 'Gráfica de alto impacto para espacios, eventos y puntos de venta.',
            'examples'    => array('Banners', 'Windflags', 'Vinilos', 'Cartelería', 'Displays'),
            'class'       => 'violet',
            'symbol'      => '↗',
        ),
        'imprenta-digital' => array(
            'number'      => '02',
            'name'        => 'Imprenta digital',
            'description' => 'Producción rápida y flexible para tiradas cortas y personalizadas.',
            'examples'    => array('Tarjetas', 'Carpetas', 'Folletos', 'Talonarios', 'Papelería'),
            'class'       => 'yellow',
            'symbol'      => '▤',
        ),
        'imprenta-offset' => array(
            'number'      => '03',
            'name'        => 'Imprenta offset',
            'description' => 'Calidad y eficiencia para grandes cantidades y proyectos especiales.',
            'examples'    => array('Anotadores', 'Afiches', 'Folletos', 'Carpetas', 'Packaging'),
            'class'       => 'ink',
            'symbol'      => '◎',
        ),
        'merchandising' => array(
            'number'      => '04',
            'name'        => 'Merchandising',
            'description' => 'Objetos personalizados para campañas, equipos y regalos corporativos.',
            'examples'    => array('Bolsas', 'Lapiceras', 'Botellas', 'Textil', 'Regalos'),
            'class'       => 'green',
            'symbol'      => '✦',
        ),
        'editorial' => array(
            'number'      => '05',
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
    echo '<div class="gx-price-guide-intro"><span>Valores de referencia</span><h2>Elegí formato y cantidad</h2><p>Los importes publicados son valores Graph Express antes de IVA. Confirmamos disponibilidad y valor final al solicitar la cotización.</p></div>';

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
