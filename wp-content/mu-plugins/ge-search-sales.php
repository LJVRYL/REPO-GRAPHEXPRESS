<?php
/** Plugin Name: Graph Express Consented Sales Context
 * Records future order attribution in a separate private database. Never changes a payment.
 */
defined('ABSPATH')||exit;
if (!class_exists('GESalesStore',false)) require_once __DIR__.'/ge-search/sales-store.php';
add_action('init',function() {
    if (!isset($_GET['ge_sales_permission'])) return;
    ge_sales_permission_endpoint('graphex','https://graphex.ar');exit;
},0);
function ge_sales_capture_order($id) {
    if (current_user_can('manage_woocommerce')||current_user_can('manage_options')) return;
    if (strtolower($_SERVER['HTTP_HOST']??'')!=='graphex.ar') return;
    try {(new GESalesStore('/home/graphexpress/public_html/.ge-sales-context/queue.sqlite'))->capture((int)$id,$_COOKIE);}catch(Throwable $e) {/* Measurement must not interrupt ordering. */}
}
add_action('woocommerce_new_order','ge_sales_capture_order',99);
add_action('woocommerce_checkout_order_processed','ge_sales_capture_order',99);
add_action('woocommerce_store_api_checkout_order_processed',function($order){ge_sales_capture_order((int)$order->get_id());},99);
