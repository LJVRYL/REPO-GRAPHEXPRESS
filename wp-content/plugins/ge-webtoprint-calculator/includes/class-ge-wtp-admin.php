<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_post_ge_wtp_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_ge_wtp_sync_products', array( __CLASS__, 'sync_products' ) );
        add_action( 'admin_post_ge_wtp_refresh_bna_rate', array( __CLASS__, 'refresh_bna_rate' ) );
    }

    public static function register_menu() {
        add_menu_page(
            'GE Web-to-Print',
            'Web-to-Print',
            'manage_woocommerce',
            'ge-webtoprint',
            array( __CLASS__, 'render_page' ),
            'dashicons-printer',
            56
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Graph Express · Portal Markcom</h1>
            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Configuración actualizada.</p></div>
            <?php endif; ?>
            <div style="max-width:760px;background:#fff;border:1px solid #dcdcde;padding:24px;margin-top:18px;">
                <h2>Cotización utilizada en el portal</h2>
                <p>El valor queda registrado dentro de cada pedido para que una actualización futura no modifique órdenes ya emitidas.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="ge_wtp_save_settings">
                    <?php wp_nonce_field( 'ge_wtp_save_settings' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="ge-rate">ARS por USD</label></th>
                            <td><input id="ge-rate" name="rate" type="number" min="0.01" step="0.01" value="<?php echo esc_attr( GE_WTP_Catalog::exchange_rate() ); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="ge-rate-label">Referencia</label></th>
                            <td><input id="ge-rate-label" name="label" type="text" value="<?php echo esc_attr( GE_WTP_Catalog::exchange_label() ); ?>" class="regular-text"></td>
                        </tr>
                    </table>
                    <?php submit_button( 'Guardar cotización' ); ?>
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="ge_wtp_refresh_bna_rate">
                    <?php wp_nonce_field( 'ge_wtp_refresh_bna_rate' ); ?>
                    <?php submit_button( 'Actualizar ahora desde Banco Nación', 'secondary' ); ?>
                </form>
                <p><small>Actualización automática cada cuatro horas. Si Banco Nación no responde o devuelve un valor inválido, se conserva la última cotización válida.</small></p>
            </div>
            <div style="max-width:760px;background:#fff;border:1px solid #dcdcde;padding:24px;margin-top:18px;">
                <h2>Catálogo Markcom</h2>
                <p><?php echo esc_html( count( GE_WTP_Catalog::products() ) ); ?> presentaciones comerciales configuradas.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="ge_wtp_sync_products">
                    <?php wp_nonce_field( 'ge_wtp_sync_products' ); ?>
                    <?php submit_button( 'Sincronizar productos WooCommerce', 'secondary' ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public static function save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Acceso denegado.', 403 );
        }
        check_admin_referer( 'ge_wtp_save_settings' );

        $rate = isset( $_POST['rate'] ) ? (float) wp_unslash( $_POST['rate'] ) : 0;
        $label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
        if ( $rate <= 0 ) {
            wp_die( 'La cotización debe ser mayor que cero.' );
        }

        update_option( 'ge_wtp_bna_sell_rate', $rate, false );
        update_option( 'ge_wtp_bna_rate_label', $label ? $label : 'Dólar vendedor Banco Nación', false );
        update_option( 'ge_wtp_bna_rate_updated_at', current_time( 'mysql' ), false );

        wp_safe_redirect( admin_url( 'admin.php?page=ge-webtoprint&updated=1' ) );
        exit;
    }

    public static function sync_products() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Acceso denegado.', 403 );
        }
        check_admin_referer( 'ge_wtp_sync_products' );
        GE_WTP_Catalog::sync_products();
        wp_safe_redirect( admin_url( 'admin.php?page=ge-webtoprint&updated=1' ) );
        exit;
    }

    public static function refresh_bna_rate() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Acceso denegado.', 403 );
        }
        check_admin_referer( 'ge_wtp_refresh_bna_rate' );

        $result = GE_WTP_Catalog::refresh_exchange_rate( true );
        $status = is_wp_error( $result ) ? 'bna_error' : 'bna_updated';
        wp_safe_redirect( add_query_arg( $status, '1', admin_url( 'admin.php?page=ge-webtoprint' ) ) );
        exit;
    }
}
