<?php
/**
 * Graph Express public host. SEO follows the active graphex.ar storefront.
 * Do not change database home/siteurl, orders or payment settings.
 */

function ge_is_alternate_host() {
    return isset($_SERVER['HTTP_HOST']) && strtolower(rtrim($_SERVER['HTTP_HOST'], '.')) === 'graphex.ar';
}

if (ge_is_alternate_host()) {
    // WordPress core prints a canonical only on singular pages. Emit one
    // canonical for public pages, always pointing at the active host.
    remove_action('wp_head', 'rel_canonical');
    add_action('wp_head', function () {
        if (is_admin() || is_404() || is_search() || is_feed()) {
            return;
        }
        if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) {
            return;
        }
        $path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            $path = '/';
        }
        // Pagination has distinct content; tracking/filter query strings do not.
        $paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
        $query = $paged > 1 && strpos( $path, '/page/' ) === false ? '?paged=' . $paged : '';
        echo '<link rel="canonical" href="' . esc_url('https://graphex.ar' . $path . $query) . '" />' . "\n";
    }, 10);
}
