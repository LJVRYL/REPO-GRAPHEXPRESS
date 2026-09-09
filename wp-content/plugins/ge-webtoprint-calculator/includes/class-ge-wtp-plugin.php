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
        add_filter( 'cron_schedules', array( 'GE_WTP_Catalog', 'cron_schedules' ) );
        add_action( GE_WTP_Catalog::BNA_CRON_HOOK, array( 'GE_WTP_Catalog', 'refresh_exchange_rate' ) );
        add_action( 'init', array( $this, 'load_textdomain' ), 1 );
        add_action( 'init', array( $this, 'register_order_statuses' ), 5 );
        add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
        add_action( 'ge_wtp_product_sync', array( $this, 'run_product_sync' ) );
        add_action( 'init', array( 'GE_WTP_Catalog', 'ensure_exchange_schedule' ), 30 );
        add_filter( 'wc_order_statuses', array( $this, 'add_order_statuses' ) );

        GE_WTP_Portal::init();
        GE_WTP_Quotes::init();
        GE_WTP_Notifications::init();
        GE_WTP_Notification_Center::init();
        GE_WTP_Knowledge_Base::init();
        GE_WTP_Google_Auth::init();
        GE_WTP_Turnstile::init();
        GE_WTP_Canva::init();
        GE_WTP_Product_Images::init();
        GE_WTP_Review_Requests::init();
        GE_WTP_Production::init();
        GE_WTP_Supplier_Dispatch::init();
        GE_WTP_Manual_Orders::init();
        GE_WTP_Newsletter::init();
        GE_WTP_Customers::init();
        GE_WTP_Reorders::init();
        GE_WTP_Artwork_Library::init();
        GE_WTP_Delivery_Labels::init();
        GE_WTP_Jobs::init();
        GE_WTP_Backoffice::init();
        GE_WTP_Staff_Portal::init();
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
        GE_WTP_Jobs::ensure_page();
        GE_WTP_Newsletter::install();
        GE_WTP_Staff_Portal::install();
        GE_WTP_Customers::install();
        GE_WTP_Reorders::install();
        GE_WTP_Artwork_Library::install();
        GE_WTP_Knowledge_Base::install();
        GE_WTP_Documents::ensure_private_directory();
        update_option( 'ge_wtp_needs_product_sync', 'yes', false );
        GE_WTP_Catalog::ensure_exchange_schedule();
        wp_schedule_single_event( time() + 10, GE_WTP_Catalog::BNA_CRON_HOOK );
        flush_rewrite_rules();
    }

    public static function deactivate() {
        GE_WTP_Catalog::clear_exchange_schedule();
    }

    public function maybe_upgrade() {
        $installed = (string) get_option( 'ge_wtp_version', '' );

        if ( GE_WTP_VERSION !== $installed ) {
            self::install_role_and_page();
            GE_WTP_Jobs::ensure_page();
            GE_WTP_Newsletter::install();
            GE_WTP_Staff_Portal::install();
            GE_WTP_Customers::install();
            GE_WTP_Reorders::install();
            GE_WTP_Artwork_Library::install();
            GE_WTP_Knowledge_Base::install();
            GE_WTP_Documents::ensure_private_directory();
            update_option( 'ge_wtp_needs_product_sync', 'yes', false );
            update_option( 'ge_wtp_version', GE_WTP_VERSION, false );
        }

        if ( 'yes' === get_option( 'ge_wtp_needs_product_sync' ) && class_exists( 'WooCommerce' ) && ! wp_next_scheduled( 'ge_wtp_product_sync' ) ) {
            wp_schedule_single_event( time() + 10, 'ge_wtp_product_sync' );
        }
    }

    public function run_product_sync() {
        if ( 'yes' !== get_option( 'ge_wtp_needs_product_sync' ) || ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        if ( get_transient( 'ge_wtp_product_sync_lock' ) ) {
            return;
        }

        set_transient( 'ge_wtp_product_sync_lock', 'yes', 30 * MINUTE_IN_SECONDS );

        try {
            GE_WTP_Catalog::sync_products();
            GE_WTP_Public_Catalog::sync();
            GE_WTP_Mardones_Catalog::sync();
            GE_WTP_Digital_Catalog::sync();
            GE_WTP_Windbanners_Catalog::sync();
            delete_option( 'ge_wtp_needs_product_sync' );
        } finally {
            delete_transient( 'ge_wtp_product_sync_lock' );
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
