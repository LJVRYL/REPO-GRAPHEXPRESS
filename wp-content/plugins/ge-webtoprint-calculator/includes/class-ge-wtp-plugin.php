<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Plugin {
    private static $instance;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'load_textdomain' ), 1 );
        add_action( 'init', array( $this, 'register_order_statuses' ), 5 );
        add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
        add_filter( 'wc_order_statuses', array( $this, 'add_order_statuses' ) );

        GE_WTP_Portal::init();
        GE_WTP_Admin::init();
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'ge-webtoprint',
            false,
            dirname( plugin_basename( GE_WTP_PLUGIN_FILE ) ) . '/languages'
        );
    }

    public static function activate() {
        self::install_role_and_page();
        GE_WTP_Documents::ensure_private_directory();
        update_option( 'ge_wtp_needs_product_sync', 'yes', false );
        flush_rewrite_rules();
    }

    public function maybe_upgrade() {
        $installed = (string) get_option( 'ge_wtp_version', '' );

        if ( GE_WTP_VERSION !== $installed ) {
            self::install_role_and_page();
            GE_WTP_Documents::ensure_private_directory();
            update_option( 'ge_wtp_needs_product_sync', 'yes', false );
            update_option( 'ge_wtp_version', GE_WTP_VERSION, false );
        }

        if ( 'yes' === get_option( 'ge_wtp_needs_product_sync' ) && class_exists( 'WooCommerce' ) ) {
            GE_WTP_Catalog::sync_products();
            delete_option( 'ge_wtp_needs_product_sync' );
        }
    }

    private static function install_role_and_page() {
        add_role(
            'ge_markcom_client',
            'Cliente Markcom',
            array(
                'read'                    => true,
                'ge_access_markcom_portal' => true,
            )
        );

        $page = get_page_by_path( 'cliente-markcom' );
        if ( ! $page ) {
            wp_insert_post(
                array(
                    'post_title'   => 'Portal Markcom',
                    'post_name'    => 'cliente-markcom',
                    'post_content' => '[ge_markcom_portal]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                )
            );
        } elseif ( false === strpos( (string) $page->post_content, '[ge_markcom_portal]' ) ) {
            wp_update_post(
                array(
                    'ID'           => $page->ID,
                    'post_content' => '[ge_markcom_portal]',
                )
            );
        }
    }

    public function register_order_statuses() {
        foreach ( self::order_status_labels() as $status => $label ) {
            register_post_status(
                'wc-' . $status,
                array(
                    'label'                     => $label,
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'ge-webtoprint' ),
                )
            );
        }
    }

    public function add_order_statuses( $statuses ) {
        $result = array();

        foreach ( $statuses as $key => $label ) {
            $result[ $key ] = $label;
            if ( 'wc-on-hold' === $key ) {
                foreach ( self::order_status_labels() as $status => $custom_label ) {
                    $result[ 'wc-' . $status ] = $custom_label;
                }
            }
        }

        return $result;
    }

    public static function order_status_labels() {
        return array(
            'ge-enviado'    => 'Markcom: enviado',
            'ge-espera-po'  => 'Markcom: pendiente de PO',
            'ge-confirmado' => 'Markcom: confirmado',
            'ge-produccion' => 'Markcom: en producción',
            'ge-listo'      => 'Markcom: listo',
            'ge-entregado'  => 'Markcom: entregado',
            'ge-facturado'  => 'Markcom: facturado',
            'ge-cobrado'    => 'Markcom: cobrado',
        );
    }
}
