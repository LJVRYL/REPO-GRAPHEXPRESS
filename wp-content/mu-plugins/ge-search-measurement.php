<?php
/** Plugin Name: Graph Express Consent Measurement
 * Browser adapter reused from the reviewed growth candidate; no CRM/schema migration.
 * Requires a reviewed published policy and explicit operational enablement.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
    $path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
    if ( ! ge_is_alternate_host() || is_admin() || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' )
        || preg_match( '#^/(gestion|portal|mi-perfil|mi-cuenta|my-account|cliente-markcom|tarjetas|wp-admin)(/|$)#', $path ) ) { return; }
    foreach ( array( 'token', 'key', 'password', 'resetpass', 'reset_password', 'email', 'access_token', 'auth', 'order_key' ) as $key ) {
        if ( isset( $_GET[ $key ] ) ) { return; }
    }
    // Ownership is already verified in GA4. Collection remains gated separately.
    $policy_id = (int) get_option( 'wp_page_for_privacy_policy' );
    $policy_url = get_privacy_policy_url();
    $ready = 'yes' === get_option( 'ge_search_measurement_approved', 'no' )
        && $policy_id > 0 && 'publish' === get_post_status( $policy_id )
        && 'graphex.ar' === parse_url( $policy_url, PHP_URL_HOST );
    if ( ! $ready ) { return; }
    $event = '';
    $id = 0;
    if ( function_exists( 'is_product' ) && is_product() ) { $event = 'product_view'; $id = get_queried_object_id(); }
    elseif ( function_exists( 'is_product_category' ) && is_product_category() ) { $event = 'category_view'; }
    elseif ( function_exists( 'is_checkout' ) && is_checkout() ) { $event = 'begin_checkout'; }
    wp_enqueue_script( 'ge-search-measurement', plugins_url( 'ge-search/measurement.js', __FILE__ ), array( 'jquery' ), '20261009sales1', true );
    wp_add_inline_script( 'ge-search-measurement', 'window.geGrowthConfig=' . wp_json_encode( array(
        'measurementId' => 'G-09DF84KP8S', 'measurementReady' => true,
        'privacyUrl' => $policy_url, 'pageEvent' => $event, 'pageId' => $id,
        'analyticsConsent' => isset( $_COOKIE['ge_growth_consent'] ) && 'granted' === $_COOKIE['ge_growth_consent'],
    ) ) . ';', 'before' );
}, 100 );
