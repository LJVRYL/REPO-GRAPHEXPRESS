<?php
/**
 * Plugin Name: Graphex · Experiencia de productos
 * Description: Composición compartida y contenedor visual de Canva, sin modificar reglas comerciales.
 */
defined('ABSPATH') || exit;
final class GE_Product_Experience {
    public static function init() {
        add_action('wp', array(__CLASS__, 'layout'), 30);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 120);
    }
    public static function layout() {
        if (!function_exists('is_product') || !is_product()) return;
        // Volantes owns its introduction and production calendar; reuse that exact output.
        if (get_post_field('post_name', get_queried_object_id()) === 'volantes-full-color') return;
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
        remove_action('woocommerce_single_product_summary', array('GE_WTP_Knowledge_Base', 'quick_help'), 22);
        add_action('woocommerce_before_single_product', array(__CLASS__, 'intro'), 20);
    }
    public static function intro() {
        echo '<header class="gx-product-intro">';
        woocommerce_template_single_title();
        woocommerce_template_single_excerpt();
        if (class_exists('GE_WTP_Knowledge_Base')) GE_WTP_Knowledge_Base::quick_help();
        echo '</header>';
    }
    public static function assets() {
        if (!function_exists('is_product') || !is_product()) return;
        $base=__DIR__.'/ge-product-experience/';
        $url=content_url('/mu-plugins/ge-product-experience/');
        wp_enqueue_style('ge-product-experience', $url.'products.css', array('graphex-commerce'), (string)filemtime($base.'products.css'));
        // Run after the existing integration has bound its controls. No OAuth/export API changes.
        $deps=array();
        foreach(array('ge-cards-canva-entry','ge-cards-canva') as $handle) {
            if(wp_script_is($handle,'enqueued')) $deps[]=$handle;
        }
        wp_enqueue_script('ge-product-experience', $url.'products.js', $deps, (string)filemtime($base.'products.js'), true);
        wp_localize_script('ge-product-experience','geProductExperience',array('canvaLogo'=>$url.'canva-icon.svg'));
    }
}
GE_Product_Experience::init();
