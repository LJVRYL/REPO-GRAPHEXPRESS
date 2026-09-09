<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Saved orders, favourites and repeat-order flows for both Markcom and WooCommerce customers.
 */
final class GE_WTP_Reorders {
    const ENDPOINT = 'pedidos-guardados';
    const SAVED_META = '_ge_saved_orders';
    const FAVORITES_META = '_ge_favorite_products';
    const REORDER_SOURCE_META = '_ge_reorder_source_order_id';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 20 );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'account_content' ) );
        add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'shop_favorite_button' ), 35 );
        add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'shop_favorite_button' ), 20 );
        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'woo_order_actions' ), 20 );
        add_action( 'woocommerce_before_cart', array( __CLASS__, 'woo_cart_notice' ), 5 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        add_action( 'admin_post_ge_save_current_order', array( __CLASS__, 'handle_save_current' ) );
        add_action( 'admin_post_ge_save_order', array( __CLASS__, 'handle_save_order' ) );
        add_action( 'admin_post_ge_load_saved_order', array( __CLASS__, 'handle_load_saved' ) );
        add_action( 'admin_post_ge_reorder_order', array( __CLASS__, 'handle_reorder_order' ) );
        add_action( 'admin_post_ge_delete_saved_order', array( __CLASS__, 'handle_delete_saved' ) );
        add_action( 'admin_post_ge_toggle_favorite', array( __CLASS__, 'handle_toggle_favorite' ) );
        add_action( 'admin_post_nopriv_ge_toggle_favorite', array( __CLASS__, 'handle_guest_favorite' ) );
    }

    public static function install() {
        self::register_endpoint();
        flush_rewrite_rules( false );
    }

    public static function register_endpoint() {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
    }

    public static function query_vars( $vars ) {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    public static function account_menu_items( $items ) {
        $logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
        unset( $items['customer-logout'] );
        $items[ self::ENDPOINT ] = 'Guardados y frecuentes';
        if ( null !== $logout ) {
            $items['customer-logout'] = $logout;
        }
        return $items;
    }

    public static function saved( $user_id = 0 ) {
        $items = get_user_meta( $user_id ? $user_id : get_current_user_id(), self::SAVED_META, true );
        return is_array( $items ) ? array_values( $items ) : array();
    }

    public static function favorites( $user_id = 0 ) {
        $items = get_user_meta( $user_id ? $user_id : get_current_user_id(), self::FAVORITES_META, true );
        return is_array( $items ) ? array_values( array_unique( array_map( 'sanitize_text_field', $items ) ) ) : array();
    }

    public static function source_order_id() {
        return absint( get_user_meta( get_current_user_id(), self::REORDER_SOURCE_META, true ) );
    }

    public static function clear_source_order() {
        delete_user_meta( get_current_user_id(), self::REORDER_SOURCE_META );
    }

    public static function is_favorite( $type, $key ) {
        return in_array( sanitize_key( $type ) . ':' . sanitize_text_field( $key ), self::favorites(), true );
    }

    public static function markcom_favorite_form( $product_key ) {
        $favorite = self::is_favorite( 'markcom', $product_key );
        ob_start();
        ?>
        <form class="ge-favorite-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ge_toggle_favorite">
            <input type="hidden" name="favorite_type" value="markcom">
            <input type="hidden" name="favorite_key" value="<?php echo esc_attr( $product_key ); ?>">
            <input type="hidden" name="return_context" value="markcom-catalog">
            <?php wp_nonce_field( 'ge_toggle_favorite' ); ?>
            <button type="submit" class="<?php echo $favorite ? 'is-favorite' : ''; ?>" aria-label="<?php echo esc_attr( $favorite ? 'Quitar de favoritos' : 'Guardar como favorito' ); ?>" title="<?php echo esc_attr( $favorite ? 'Quitar de favoritos' : 'Guardar como favorito' ); ?>"><?php echo $favorite ? '♥' : '♡'; ?></button>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function order_actions( $order, $context = 'markcom' ) {
        if ( ! $order || ! self::can_access_order( $order ) ) {
            return;
        }
        ?>
        <div class="ge-repeat-actions">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_reorder_order"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><input type="hidden" name="return_context" value="<?php echo esc_attr( $context ); ?>"><?php wp_nonce_field( 'ge_reorder_order_' . $order->get_id() ); ?>
                <button class="ge-button ge-button-primary" type="submit">Volver a pedir</button>
            </form>
            <form class="ge-save-order-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_save_order"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><input type="hidden" name="return_context" value="<?php echo esc_attr( $context ); ?>"><?php wp_nonce_field( 'ge_save_order_' . $order->get_id() ); ?>
                <input type="text" name="saved_name" maxlength="100" placeholder="Ej.: Cartelería mensual"><button class="ge-button ge-button-secondary" type="submit">Guardar para repetir</button>
            </form>
        </div>
        <?php
    }

    public static function render_markcom_saved() {
        self::render_saved_screen( 'markcom' );
    }

    public static function render_customer_saved() {
        self::render_saved_screen( 'customer-portal' );
    }

    public static function account_content() {
        self::render_saved_screen( 'account' );
    }

    public static function enqueue_assets() {
        if ( is_page( 'cliente-markcom' ) || ( function_exists( 'is_account_page' ) && is_account_page() ) || ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product() || is_cart() ) ) ) {
            wp_enqueue_style( 'ge-reorders', GE_WTP_PLUGIN_URL . 'assets/css/reorders.css', array(), GE_WTP_VERSION );
        }
    }

    public static function woo_order_actions( $order ) {
        if ( 'yes' !== $order->get_meta( '_ge_markcom_order' ) ) {
            self::order_actions( $order, 'account' );
        }
    }

    public static function woo_cart_notice() {
        if ( 'loaded' !== ( isset( $_GET['ge_saved_notice'] ) ? sanitize_key( wp_unslash( $_GET['ge_saved_notice'] ) ) : '' ) ) { return; }
        $changes = isset( $_GET['price_changes'] ) ? absint( $_GET['price_changes'] ) : 0;
        wc_print_notice( $changes ? sprintf( 'El pedido se agregó al carrito con los precios actuales. Cambió el valor de %d producto%s.', $changes, 1 === $changes ? '' : 's' ) : 'El pedido guardado se agregó al carrito. Podés revisar cantidades y datos antes de finalizar.', $changes ? 'notice' : 'success' );
    }

    private static function render_saved_screen( $context ) {
        $markcom = 'markcom' === $context;
        $saved = self::saved();
        $source = $markcom ? 'markcom' : 'woo';
        $saved_count = count( array_filter( $saved, function ( $item ) use ( $source ) { return isset( $item['source'] ) && $source === $item['source']; } ) );
        $frequent = self::frequent_products( $markcom ? 'markcom' : 'woo' );
        $favorites = self::favorite_details( $markcom ? 'markcom' : 'woo' );
        ?>
        <section class="ge-saved-screen <?php echo $markcom ? 'is-markcom' : 'is-account'; ?>">
            <div class="ge-saved-heading"><div><span class="ge-eyebrow">Accesos rápidos</span><h1>Guardados y frecuentes</h1><p>Repetí un trabajo anterior sin volver a cargar productos y configuraciones.</p></div><?php if ( $markcom ) : ?><a class="ge-button ge-button-primary" href="<?php echo esc_url( GE_WTP_Portal::portal_url( 'catalogo' ) ); ?>">Crear pedido nuevo</a><?php endif; ?></div>
            <?php self::render_context_notice(); ?>
            <div class="ge-saved-layout">
                <section class="ge-panel ge-saved-main"><div class="ge-panel-heading"><div><span class="ge-eyebrow">Plantillas</span><h2>Pedidos guardados</h2></div><span><?php echo esc_html( $saved_count ); ?></span></div>
                    <?php
                    $shown = 0;
                    foreach ( $saved as $template ) {
                        if ( ( $markcom ? 'markcom' : 'woo' ) !== $template['source'] ) { continue; }
                        ++$shown;
                        self::render_saved_card( $template, $context );
                    }
                    if ( ! $shown ) { echo '<div class="ge-empty-state ge-empty-state-inline"><p>Todavía no guardaste pedidos. Podés hacerlo desde el carrito o desde el detalle de una orden.</p></div>'; }
                    ?>
                </section>
                <aside class="ge-saved-side">
                    <section class="ge-panel"><span class="ge-eyebrow">Favoritos</span><h2>Productos guardados</h2><?php self::render_product_shortcuts( $favorites, $context, 'Todavía no marcaste productos favoritos.' ); ?></section>
                    <section class="ge-panel"><span class="ge-eyebrow">Según tu historial</span><h2>Los que más pedís</h2><?php self::render_product_shortcuts( $frequent, $context, 'Se mostrarán cuando tengas pedidos anteriores.' ); ?></section>
                </aside>
            </div>
        </section>
        <?php
    }

    private static function render_saved_card( $template, $context ) {
        $changes = self::template_price_changes( $template );
        ?>
        <article class="ge-saved-card">
            <div><small><?php echo esc_html( wp_date( 'd/m/Y', isset( $template['created_at'] ) ? (int) $template['created_at'] : time() ) ); ?></small><h3><?php echo esc_html( $template['name'] ); ?></h3><p><?php echo esc_html( count( $template['lines'] ) . ' productos' ); ?><?php if ( ! empty( $template['source_order_id'] ) ) : ?> · Origen #<?php echo esc_html( $template['source_order_id'] ); ?><?php endif; ?></p><?php if ( $changes ) : ?><span class="ge-price-change">El precio actual cambió en <?php echo esc_html( $changes ); ?> producto<?php echo 1 === $changes ? '' : 's'; ?>.</span><?php endif; ?></div>
            <div class="ge-saved-card-actions">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_load_saved_order"><input type="hidden" name="saved_id" value="<?php echo esc_attr( $template['id'] ); ?>"><input type="hidden" name="return_context" value="<?php echo esc_attr( $context ); ?>"><?php wp_nonce_field( 'ge_load_saved_order_' . $template['id'] ); ?><button class="ge-button ge-button-primary" type="submit">Cargar al carrito</button></form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Quitar este pedido guardado?');"><input type="hidden" name="action" value="ge_delete_saved_order"><input type="hidden" name="saved_id" value="<?php echo esc_attr( $template['id'] ); ?>"><input type="hidden" name="return_context" value="<?php echo esc_attr( $context ); ?>"><?php wp_nonce_field( 'ge_delete_saved_order_' . $template['id'] ); ?><button class="ge-link-danger" type="submit">Quitar</button></form>
            </div>
        </article>
        <?php
    }

    private static function render_product_shortcuts( $items, $context, $empty ) {
        if ( ! $items ) { echo '<p class="ge-muted">' . esc_html( $empty ) . '</p>'; return; }
        echo '<div class="ge-product-shortcuts">';
        foreach ( array_slice( $items, 0, 5 ) as $item ) {
            $url = 'markcom' === $item['type'] ? GE_WTP_Portal::portal_url( 'catalogo' ) : get_permalink( $item['key'] );
            echo '<a href="' . esc_url( $url ) . '"><span><strong>' . esc_html( $item['name'] ) . '</strong><small>' . esc_html( ! empty( $item['count'] ) ? $item['count'] . ' pedidos' : 'Favorito' ) . '</small></span><b>→</b></a>';
        }
        echo '</div>';
    }

    private static function render_context_notice() {
        $notice = isset( $_GET['ge_saved_notice'] ) ? sanitize_key( wp_unslash( $_GET['ge_saved_notice'] ) ) : '';
        $messages = array( 'saved' => 'Pedido guardado para repetir.', 'deleted' => 'Pedido quitado de guardados.', 'favorite-added' => 'Producto agregado a favoritos.', 'favorite-removed' => 'Producto quitado de favoritos.', 'error' => 'No pudimos completar la operación.' );
        if ( isset( $messages[ $notice ] ) ) { echo '<div class="ge-notice ' . ( 'error' === $notice ? 'ge-notice-error' : 'ge-notice-success' ) . '">' . esc_html( $messages[ $notice ] ) . '</div>'; }
    }

    public static function current_cart_save_form() {
        if ( ! GE_WTP_Orders::cart() ) { return; }
        ?>
        <form class="ge-save-current-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_save_current_order"><?php wp_nonce_field( 'ge_save_current_order' ); ?><label>Guardar este armado<input type="text" name="saved_name" maxlength="100" placeholder="Ej.: Reposición mensual"></label><button class="ge-button ge-button-secondary ge-button-block" type="submit">Guardar para después</button></form>
        <?php
    }

    public static function handle_save_current() {
        self::require_login(); check_admin_referer( 'ge_save_current_order' );
        $lines = self::markcom_cart_lines();
        $name = isset( $_POST['saved_name'] ) ? sanitize_text_field( wp_unslash( $_POST['saved_name'] ) ) : '';
        $result = self::save_template( 'markcom', $name, $lines, 0 );
        self::redirect_context( 'markcom-catalog', is_wp_error( $result ) ? 'error' : 'saved' );
    }

    public static function handle_save_order() {
        self::require_login();
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        check_admin_referer( 'ge_save_order_' . $order_id );
        $order = wc_get_order( $order_id );
        if ( ! self::can_access_order( $order ) ) { wp_die( 'Acceso denegado.', 403 ); }
        $source = 'yes' === $order->get_meta( '_ge_markcom_order' ) ? 'markcom' : 'woo';
        $name = isset( $_POST['saved_name'] ) ? sanitize_text_field( wp_unslash( $_POST['saved_name'] ) ) : '';
        $result = self::save_template( $source, $name, self::order_lines( $order, $source ), $order_id );
        self::redirect_context( isset( $_POST['return_context'] ) ? sanitize_key( wp_unslash( $_POST['return_context'] ) ) : $source, is_wp_error( $result ) ? 'error' : 'saved', $order_id );
    }

    public static function handle_reorder_order() {
        self::require_login();
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        check_admin_referer( 'ge_reorder_order_' . $order_id );
        $order = wc_get_order( $order_id );
        if ( ! self::can_access_order( $order ) ) { wp_die( 'Acceso denegado.', 403 ); }
        $source = 'yes' === $order->get_meta( '_ge_markcom_order' ) ? 'markcom' : 'woo';
        $changes = self::load_lines( self::order_lines( $order, $source ), $source );
        if ( 'markcom' === $source ) { update_user_meta( get_current_user_id(), self::REORDER_SOURCE_META, $order_id ); }
        self::redirect_to_cart( $source, $changes, $order_id );
    }

    public static function handle_load_saved() {
        self::require_login();
        $id = isset( $_POST['saved_id'] ) ? sanitize_text_field( wp_unslash( $_POST['saved_id'] ) ) : '';
        check_admin_referer( 'ge_load_saved_order_' . $id );
        $template = self::find_saved( $id );
        if ( ! $template ) { self::redirect_context( 'markcom', 'error' ); }
        $changes = self::load_lines( $template['lines'], $template['source'] );
        if ( 'markcom' === $template['source'] && ! empty( $template['source_order_id'] ) ) { update_user_meta( get_current_user_id(), self::REORDER_SOURCE_META, absint( $template['source_order_id'] ) ); }
        self::redirect_to_cart( $template['source'], $changes, ! empty( $template['source_order_id'] ) ? absint( $template['source_order_id'] ) : 0 );
    }

    public static function handle_delete_saved() {
        self::require_login();
        $id = isset( $_POST['saved_id'] ) ? sanitize_text_field( wp_unslash( $_POST['saved_id'] ) ) : '';
        check_admin_referer( 'ge_delete_saved_order_' . $id );
        $saved = array_values( array_filter( self::saved(), function ( $item ) use ( $id ) { return ! isset( $item['id'] ) || $id !== $item['id']; } ) );
        update_user_meta( get_current_user_id(), self::SAVED_META, $saved );
        self::redirect_context( isset( $_POST['return_context'] ) ? sanitize_key( wp_unslash( $_POST['return_context'] ) ) : 'markcom', 'deleted' );
    }

    public static function handle_toggle_favorite() {
        self::require_login(); check_admin_referer( 'ge_toggle_favorite' );
        $type = isset( $_POST['favorite_type'] ) ? sanitize_key( wp_unslash( $_POST['favorite_type'] ) ) : '';
        $key = isset( $_POST['favorite_key'] ) ? sanitize_text_field( wp_unslash( $_POST['favorite_key'] ) ) : '';
        if ( ( 'markcom' === $type && ! GE_WTP_Catalog::get( $key ) ) || ( 'woo' === $type && ! wc_get_product( absint( $key ) ) ) ) { self::redirect_context( 'markcom', 'error' ); }
        $token = $type . ':' . $key; $favorites = self::favorites(); $exists = in_array( $token, $favorites, true );
        if ( $exists ) { $favorites = array_values( array_diff( $favorites, array( $token ) ) ); } else { $favorites[] = $token; }
        update_user_meta( get_current_user_id(), self::FAVORITES_META, array_slice( $favorites, -100 ) );
        self::redirect_context( isset( $_POST['return_context'] ) ? sanitize_key( wp_unslash( $_POST['return_context'] ) ) : 'account', $exists ? 'favorite-removed' : 'favorite-added' );
    }

    public static function handle_guest_favorite() {
        wp_safe_redirect( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url() ); exit;
    }

    public static function shop_favorite_button() {
        global $product;
        if ( ! $product || 'yes' === $product->get_meta( '_ge_markcom_only' ) ) { return; }
        $favorite = is_user_logged_in() && self::is_favorite( 'woo', $product->get_id() );
        ?><form class="ge-woo-favorite" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_toggle_favorite"><input type="hidden" name="favorite_type" value="woo"><input type="hidden" name="favorite_key" value="<?php echo esc_attr( $product->get_id() ); ?>"><input type="hidden" name="return_context" value="referer"><?php wp_nonce_field( 'ge_toggle_favorite' ); ?><button type="submit"><?php echo $favorite ? '♥ Guardado' : '♡ Guardar'; ?></button></form><?php
    }

    private static function save_template( $source, $name, $lines, $source_order_id ) {
        if ( ! $lines ) { return new WP_Error( 'empty', 'No hay productos para guardar.' ); }
        $saved = self::saved();
        $saved[] = array( 'id' => wp_generate_uuid4(), 'name' => $name ? $name : ( $source_order_id ? 'Pedido #' . $source_order_id : 'Pedido guardado ' . wp_date( 'd/m/Y' ) ), 'source' => $source, 'created_at' => time(), 'source_order_id' => absint( $source_order_id ), 'lines' => $lines );
        update_user_meta( get_current_user_id(), self::SAVED_META, array_slice( $saved, -30 ) );
        return true;
    }

    private static function markcom_cart_lines() {
        $lines = array();
        foreach ( GE_WTP_Orders::cart() as $line ) {
            $product = GE_WTP_Catalog::get( $line['product_key'] ); $tier = absint( $line['tier'] );
            if ( ! $product || ! isset( $product['prices'][ $tier ] ) ) { continue; }
            $lines[] = array( 'type' => 'markcom', 'product_key' => $line['product_key'], 'name' => $product['name'], 'quantity' => $tier, 'tier' => $tier, 'notes' => isset( $line['notes'] ) ? $line['notes'] : '', 'snapshot_price' => (float) $product['prices'][ $tier ], 'snapshot_currency' => 'ARS' );
        }
        return $lines;
    }

    private static function order_lines( $order, $source ) {
        $lines = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( 'markcom' === $source ) {
                $key = sanitize_key( $item->get_meta( '_ge_product_key', true ) );
                if ( ! $key && $item->get_product_id() ) { $key = sanitize_key( get_post_meta( $item->get_product_id(), '_ge_markcom_key', true ) ); }
                if ( ! $key ) { $key = self::markcom_key_from_name( $item->get_name() ); }
                $tier = absint( $item->get_meta( '_ge_tier', true ) ); if ( ! $tier ) { $tier = absint( $item->get_quantity() ); }
                $product = GE_WTP_Catalog::get( $key ); if ( ! $product || ! isset( $product['prices'][ $tier ] ) ) { continue; }
                $snapshot = (float) $item->get_meta( '_ge_unit_ars_snapshot', true );
                if ( ! $snapshot ) { $rate = (float) $order->get_meta( '_ge_markcom_exchange_rate' ); $snapshot = $rate > 0 && $tier > 0 ? ( (float) $item->get_subtotal() * $rate ) / $tier : (float) $product['prices'][ $tier ]; }
                $lines[] = array( 'type' => 'markcom', 'product_key' => $key, 'name' => $item->get_name(), 'quantity' => $tier, 'tier' => $tier, 'notes' => (string) ( $item->get_meta( '_ge_line_notes', true ) ?: $item->get_meta( 'Observaciones', true ) ), 'snapshot_price' => $snapshot, 'snapshot_currency' => 'ARS' );
            } else {
                $product = $item->get_product(); if ( ! $product ) { continue; }
                $qty = max( 1, absint( $item->get_quantity() ) );
                $lines[] = array( 'type' => 'woo', 'product_id' => absint( $item->get_product_id() ), 'variation_id' => absint( $item->get_variation_id() ), 'variation' => $item->get_variation_id() ? wc_get_product_variation_attributes( $item->get_variation_id() ) : array(), 'name' => $item->get_name(), 'quantity' => $qty, 'snapshot_price' => (float) $item->get_subtotal() / $qty, 'snapshot_currency' => $order->get_currency() );
            }
        }
        return $lines;
    }

    private static function load_lines( $lines, $source ) {
        $changes = self::lines_price_changes( $lines );
        if ( 'markcom' === $source ) {
            $cart = GE_WTP_Orders::cart();
            foreach ( $lines as $line ) { $key = isset( $line['product_key'] ) ? sanitize_key( $line['product_key'] ) : ''; $tier = isset( $line['tier'] ) ? absint( $line['tier'] ) : 0; $product = GE_WTP_Catalog::get( $key ); if ( ! $product || ! isset( $product['prices'][ $tier ] ) ) { continue; } $line_key = sanitize_key( $key . '-' . $tier ); $cart[ $line_key ] = array( 'line_key' => $line_key, 'product_key' => $key, 'tier' => $tier, 'notes' => isset( $line['notes'] ) ? sanitize_textarea_field( $line['notes'] ) : '' ); }
            GE_WTP_Orders::set_cart( $cart );
        } elseif ( function_exists( 'WC' ) && WC()->cart ) {
            foreach ( $lines as $line ) { WC()->cart->add_to_cart( absint( $line['product_id'] ), max( 1, absint( $line['quantity'] ) ), isset( $line['variation_id'] ) ? absint( $line['variation_id'] ) : 0, isset( $line['variation'] ) && is_array( $line['variation'] ) ? $line['variation'] : array() ); }
        }
        return $changes;
    }

    private static function template_price_changes( $template ) { return self::lines_price_changes( isset( $template['lines'] ) ? $template['lines'] : array() ); }

    private static function lines_price_changes( $lines ) {
        $changes = 0;
        foreach ( $lines as $line ) { $current = 0; if ( 'markcom' === $line['type'] ) { $product = GE_WTP_Catalog::get( $line['product_key'] ); $tier = absint( $line['tier'] ); $current = $product && isset( $product['prices'][ $tier ] ) ? (float) $product['prices'][ $tier ] : 0; } else { $product = wc_get_product( ! empty( $line['variation_id'] ) ? absint( $line['variation_id'] ) : absint( $line['product_id'] ) ); $current = $product ? (float) wc_get_price_to_display( $product ) : 0; } if ( $current > 0 && isset( $line['snapshot_price'] ) && abs( $current - (float) $line['snapshot_price'] ) > 0.01 ) { ++$changes; } }
        return $changes;
    }

    private static function frequent_products( $source ) {
        if ( ! function_exists( 'wc_get_orders' ) ) { return array(); }
        $counts = array(); $names = array();
        $orders = wc_get_orders( array( 'customer_id' => get_current_user_id(), 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );
        foreach ( $orders as $order ) { $is_markcom = 'yes' === $order->get_meta( '_ge_markcom_order' ); if ( ( 'markcom' === $source ) !== $is_markcom ) { continue; } foreach ( self::order_lines( $order, $source ) as $line ) { $key = 'markcom' === $source ? $line['product_key'] : $line['product_id']; $counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1; $names[ $key ] = $line['name']; } }
        arsort( $counts ); $result = array(); foreach ( $counts as $key => $count ) { $result[] = array( 'type' => $source, 'key' => $key, 'name' => $names[ $key ], 'count' => $count ); } return $result;
    }

    private static function favorite_details( $source ) {
        $result = array(); foreach ( self::favorites() as $token ) { $parts = explode( ':', $token, 2 ); if ( 2 !== count( $parts ) || $source !== $parts[0] ) { continue; } if ( 'markcom' === $source ) { $product = GE_WTP_Catalog::get( $parts[1] ); if ( $product ) { $result[] = array( 'type' => 'markcom', 'key' => $parts[1], 'name' => $product['name'] ); } } else { $product = wc_get_product( absint( $parts[1] ) ); if ( $product ) { $result[] = array( 'type' => 'woo', 'key' => $product->get_id(), 'name' => $product->get_name() ); } } } return $result;
    }

    private static function markcom_key_from_name( $name ) { foreach ( GE_WTP_Catalog::products() as $key => $product ) { if ( 0 === strcasecmp( trim( $name ), trim( $product['name'] ) ) ) { return $key; } } return ''; }
    private static function find_saved( $id ) { foreach ( self::saved() as $item ) { if ( isset( $item['id'] ) && hash_equals( (string) $item['id'], (string) $id ) ) { return $item; } } return null; }
    private static function can_access_order( $order ) {
        if ( ! $order || ! is_user_logged_in() ) { return false; }
        if ( (int) $order->get_customer_id() === get_current_user_id() || current_user_can( 'manage_woocommerce' ) || current_user_can( 'ge_manage_operations' ) ) { return true; }
        $user = wp_get_current_user();
        return 0 === (int) $order->get_customer_id() && $order->get_billing_email() && 0 === strcasecmp( $order->get_billing_email(), $user->user_email );
    }
    private static function require_login() { if ( ! is_user_logged_in() ) { wp_die( 'Tenés que iniciar sesión.', 403 ); } }

    private static function redirect_to_cart( $source, $changes, $order_id = 0 ) {
        if ( 'markcom' === $source ) { $url = GE_WTP_Portal::portal_url( 'catalogo', array( 'ge_notice' => 'reorder-loaded', 'price_changes' => absint( $changes ), 'source_order' => absint( $order_id ) ) ); }
        else { $url = add_query_arg( array( 'ge_saved_notice' => 'loaded', 'price_changes' => absint( $changes ) ), wc_get_cart_url() ); }
        wp_safe_redirect( $url ); exit;
    }

    private static function redirect_context( $context, $notice, $order_id = 0 ) {
        if ( 'referer' === $context ) { $url = wp_get_referer(); }
        elseif ( 'markcom-catalog' === $context ) { $url = GE_WTP_Portal::portal_url( 'catalogo' ); }
        elseif ( 'markcom' === $context ) { $url = GE_WTP_Portal::portal_url( 'guardados' ); }
        elseif ( 'account' === $context || 'woo' === $context ) { $url = wc_get_account_endpoint_url( self::ENDPOINT ); }
        elseif ( in_array( $context, array( 'markcom-order', 'customer-order' ), true ) && $order_id ) { $url = GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order_id ) ); }
        elseif ( 'customer-portal' === $context ) { $url = GE_WTP_Portal::portal_url( 'guardados' ); }
        else { $url = wp_get_referer(); }
        $url = $url ? $url : home_url( '/' );
        wp_safe_redirect( add_query_arg( 'ge_saved_notice', $notice, $url ) ); exit;
    }
}
