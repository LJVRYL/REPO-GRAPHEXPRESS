<?php
/** Plugin Name: Graph Express Search Readiness
 * Index commercial pages, keep operational screens out of search discovery.
 * No changes to WordPress domain options, customer data or payments.
 */
defined( 'ABSPATH' ) || exit;

// Keep the previously submitted alias usable while WordPress owns the index.
add_action( 'template_redirect', function () {
    $path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
    if ( ge_is_alternate_host() && '/sitemap.xml' === $path && in_array( $method, array( 'GET', 'HEAD' ), true ) && empty( $_GET ) ) {
        wp_redirect( 'https://graphex.ar/wp-sitemap.xml', 301, 'Graphex Sitemap' );
        exit;
    }
}, 0 );

function ge_search_excluded_pages() {
    return array( 'sample-page', 'cart', 'checkout', 'my-account', 'cliente-markcom', 'gestion', 'mi-perfil', 'preferencias-email' );
}

function ge_search_private_request() {
    $path = trim( (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH ), '/' );
    $first = explode( '/', $path );
    return in_array( $first[0], array_merge( ge_search_excluded_pages(), array( 'portal', 'mi-cuenta', 'tarjetas' ) ), true )
        || ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) )
        || is_search() || is_author();
}

function ge_search_placeholder_request() {
    return is_singular( 'post' ) && 'hello-world' === get_post_field( 'post_name', get_queried_object_id() )
        || is_tax( 'product_cat', 'uncategorized' )
        || is_category( 'uncategorized' );
}

add_filter( 'wp_robots', function ( $robots ) {
    if ( ge_search_private_request() || ge_search_placeholder_request() ) {
        $robots['noindex'] = true;
        unset( $robots['index'] );
    }
    return $robots;
} );

add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $type ) {
    if ( 'post' === $type ) {
        $placeholder = get_page_by_path( 'hello-world', OBJECT, 'post' );
        if ( $placeholder ) {
            $args['post__not_in'] = array_values( array_unique( array_merge( isset( $args['post__not_in'] ) ? $args['post__not_in'] : array(), array( (int) $placeholder->ID ) ) ) );
        }
    }
    if ( 'page' !== $type ) { return $args; }
    $excluded = isset( $args['post__not_in'] ) ? $args['post__not_in'] : array();
    foreach ( ge_search_excluded_pages() as $slug ) {
        $page = get_page_by_path( $slug );
        if ( $page ) { $excluded[] = (int) $page->ID; }
    }
    $args['post__not_in'] = array_values( array_unique( $excluded ) );
    return $args;
}, 10, 2 );

add_filter( 'wp_sitemaps_taxonomies_query_args', function ( $args, $taxonomy ) {
    if ( in_array( $taxonomy, array( 'category', 'product_cat' ), true ) ) {
        $term = get_term_by( 'slug', 'uncategorized', $taxonomy );
        if ( $term && ! is_wp_error( $term ) ) {
            $args['exclude'] = array_values( array_unique( array_merge( isset( $args['exclude'] ) ? (array) $args['exclude'] : array(), array( (int) $term->term_id ) ) ) );
        }
    }
    return $args;
}, 10, 2 );

add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
    return 'users' === $name ? false : $provider;
}, 10, 2 );

// This is a public ownership-verification marker, not an API credential.
add_action( 'wp_head', function () {
    if ( is_front_page() && ge_is_alternate_host() ) {
        echo '<meta name="google-site-verification" content="3thYVmfuHXhzv57zQ2iCEPRjt9_3tNeLdbue1dhoWQk" />' . "\n";
    }
}, 2 );

add_action( 'wp_head', function () {
    if ( ! ge_is_alternate_host() || ge_search_private_request() || ge_search_placeholder_request() ) { return; }
    if ( function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_product' ) ) {
        $product = wc_get_product( get_queried_object_id() );
        if ( $product ) {
            $description = trim( wp_strip_all_tags( $product->get_short_description() ) );
            if ( ! $description ) { $description = trim( wp_strip_all_tags( $product->get_description() ) ); }
            if ( ! $description ) { $description = $product->get_name() . ' en Graph Express. Consultá las opciones de personalización y solicitá un presupuesto para tu trabajo.'; }
            if ( $description ) {
                echo '<meta name="description" content="' . esc_attr( wp_html_excerpt( $description, 155, '…' ) ) . '" />' . "\n";
            }
            // Quote-only pages cannot claim Product rich results without real offers.
            $data = array( '@context' => 'https://schema.org', '@type' => 'WebPage',
                '@id' => get_permalink( $product->get_id() ) . '#webpage',
                'url' => get_permalink( $product->get_id() ), 'name' => $product->get_name(),
                'description' => $description );
            $image = wp_get_attachment_url( $product->get_image_id() );
            if ( $image ) { $data['image'] = $image; }
            echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>' . "\n";
        }
    }
    if ( function_exists( 'is_product_category' ) && is_product_category() ) {
        $term = get_queried_object();
        $description = trim( wp_strip_all_tags( $term->description ) );
        if ( ! $description ) { $description = $term->name . ' en Graph Express. Consultá los productos, materiales y opciones de impresión disponibles.'; }
        echo '<meta name="description" content="' . esc_attr( wp_html_excerpt( $description, 155, '…' ) ) . '" />' . "\n";
    } elseif ( function_exists( 'is_shop' ) && is_shop() ) {
        echo '<meta name="description" content="Productos de impresión de Graph Express: explorá categorías, materiales y opciones para tu próximo trabajo gráfico." />' . "\n";
    } elseif ( is_tax( 'ge_guide_topic' ) ) {
        $term = get_queried_object();
        $description = trim( wp_strip_all_tags( $term->description ) );
        if ( ! $description ) { $description = 'Guías de ' . $term->name . ' de Graph Express. Información para preparar archivos y planificar tus trabajos de producción gráfica.'; }
        echo '<meta name="description" content="' . esc_attr( wp_html_excerpt( $description, 155, '…' ) ) . '" />' . "\n";
    } elseif ( is_page( array( 'privacy-policy', 'privacidad-conexion-canva', 'condiciones-conexion-canva' ) ) ) {
        $description = trim( wp_strip_all_tags( get_post_field( 'post_content', get_queried_object_id() ) ) );
        if ( $description ) { echo '<meta name="description" content="' . esc_attr( wp_html_excerpt( $description, 155, '…' ) ) . '" />' . "\n"; }
    }
    if ( is_front_page() ) {
        $data = array( '@context' => 'https://schema.org', '@graph' => array(
            array( '@type' => 'Organization', '@id' => 'https://graphex.ar/#organization', 'name' => 'Graph Express', 'url' => 'https://graphex.ar/' ),
            array( '@type' => 'WebSite', '@id' => 'https://graphex.ar/#website', 'name' => 'Graph Express', 'url' => 'https://graphex.ar/', 'publisher' => array( '@id' => 'https://graphex.ar/#organization' ) ),
        ) );
        echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>' . "\n";
    }
}, 20 );
