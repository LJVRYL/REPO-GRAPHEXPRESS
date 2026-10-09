<?php
/** Plugin Name: Graph Express Search Readiness
 * Index commercial pages, keep operational screens out of search discovery.
 * No changes to WordPress domain options, customer data or payments.
 */
defined( 'ABSPATH' ) || exit;

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

add_filter( 'wp_robots', function ( $robots ) {
    if ( ge_search_private_request() ) {
        $robots['noindex'] = true;
        unset( $robots['index'] );
    }
    return $robots;
} );

add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $type ) {
    if ( 'page' !== $type ) { return $args; }
    $excluded = isset( $args['post__not_in'] ) ? $args['post__not_in'] : array();
    foreach ( ge_search_excluded_pages() as $slug ) {
        $page = get_page_by_path( $slug );
        if ( $page ) { $excluded[] = (int) $page->ID; }
    }
    $args['post__not_in'] = array_values( array_unique( $excluded ) );
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
    if ( ! ge_is_alternate_host() || ge_search_private_request() ) { return; }
    if ( function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_product' ) ) {
        $product = wc_get_product( get_queried_object_id() );
        if ( $product ) {
            $description = trim( wp_strip_all_tags( $product->get_short_description() ) );
            if ( $description ) {
                echo '<meta name="description" content="' . esc_attr( wp_html_excerpt( $description, 155, '…' ) ) . '" />' . "\n";
            }
        }
    }
    if ( is_front_page() ) {
        $data = array( '@context' => 'https://schema.org', '@graph' => array(
            array( '@type' => 'Organization', '@id' => 'https://graphex.ar/#organization', 'name' => 'Graph Express', 'url' => 'https://graphex.ar/' ),
            array( '@type' => 'WebSite', '@id' => 'https://graphex.ar/#website', 'name' => 'Graph Express', 'url' => 'https://graphex.ar/', 'publisher' => array( '@id' => 'https://graphex.ar/#organization' ) ),
        ) );
        echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>' . "\n";
    }
}, 20 );
