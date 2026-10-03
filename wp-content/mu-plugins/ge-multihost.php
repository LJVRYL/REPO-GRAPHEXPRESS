<?php
/**
 * Graph Express alternate host. Production SEO remains on graphexpress.com.ar.
 * Do not change database home/siteurl, orders or payment settings.
 */

function ge_is_alternate_host() {
    return isset($_SERVER['HTTP_HOST']) && strtolower(rtrim($_SERVER['HTTP_HOST'], '.')) === 'graphex.ar';
}

if (ge_is_alternate_host()) {
    // WordPress core prints a canonical only on singular pages. Emit one
    // canonical for public pages, always pointing at the established host.
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
        echo '<link rel="canonical" href="' . esc_url('https://graphexpress.com.ar' . $path) . '" />' . "\n";
    }, 10);
}
