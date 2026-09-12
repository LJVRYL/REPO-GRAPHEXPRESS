<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Lightweight artwork index. Production originals live in an external provider;
 * WordPress stores metadata and an optimized, private preview only.
 */
final class GE_WTP_Artwork_Library {
    const POST_TYPE = 'ge_artwork';
    const ENDPOINT = 'mis-archivos';
    const ORDER_META = '_ge_artwork_ids';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ) );
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 25 );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'account_content' ) );
        add_action( 'woocommerce_checkout_after_customer_details', array( __CLASS__, 'woo_checkout_picker' ) );
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'woo_checkout_save' ), 20, 2 );
        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'woo_order_links' ), 15 );
        add_action( 'admin_post_ge_artwork_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_ge_artwork_preview', array( __CLASS__, 'handle_preview' ) );
        add_action( 'admin_post_ge_artwork_original', array( __CLASS__, 'handle_original_download' ) );
        add_action( 'admin_post_ge_customer_drive_artwork', array( __CLASS__, 'handle_customer_drive_artwork' ) );
        add_action( 'admin_post_ge_artwork_control_save', array( __CLASS__, 'handle_artwork_control_save' ) );
        add_action( 'admin_post_ge_customer_artwork_approval', array( __CLASS__, 'handle_customer_artwork_approval' ) );
        add_action( 'admin_post_ge_artwork_release_sheet', array( __CLASS__, 'handle_release_sheet' ) );
        add_action( 'admin_post_ge_production_save', array( __CLASS__, 'guard_production_transition' ), 1 );
        add_action( 'admin_post_ge_supplier_email', array( __CLASS__, 'guard_supplier_dispatch' ), 1 );
        add_action( 'admin_post_ge_supplier_whatsapp', array( __CLASS__, 'guard_supplier_dispatch' ), 1 );
        add_action( 'admin_post_ge_production_sheet', array( __CLASS__, 'guard_supplier_dispatch' ), 1 );
        add_action( 'wp_footer', array( __CLASS__, 'render_staff_artwork_control' ), 40 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function install() {
        self::register();
        self::ensure_preview_directory();
        self::ensure_original_directory();
        flush_rewrite_rules( false );
    }

    public static function register() {
        register_post_type( self::POST_TYPE, array( 'labels' => array( 'name' => 'Biblioteca de archivos', 'singular_name' => 'Ficha de archivo' ), 'public' => false, 'show_ui' => false, 'supports' => array( 'title', 'author' ) ) );
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
    }

    public static function query_vars( $vars ) { $vars[] = self::ENDPOINT; return $vars; }

    public static function account_menu_items( $items ) {
        $logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
        unset( $items['customer-logout'] );
        $items[ self::ENDPOINT ] = 'Mis archivos';
        if ( null !== $logout ) { $items['customer-logout'] = $logout; }
        return $items;
    }

    public static function enqueue_assets() {
        if ( is_page( 'cliente-markcom' ) || is_page( 'gestion' ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
            $style_file = GE_WTP_PLUGIN_DIR . 'assets/css/artwork-library.css';
            $style_version = file_exists( $style_file ) ? (string) filemtime( $style_file ) : GE_WTP_VERSION;
            wp_enqueue_style( 'ge-artwork-library', GE_WTP_PLUGIN_URL . 'assets/css/artwork-library.css', array(), $style_version );
            if ( is_page( 'gestion' ) ) {
                $control_script = GE_WTP_PLUGIN_DIR . 'assets/js/artwork-control.js';
                wp_enqueue_script( 'ge-artwork-control', GE_WTP_PLUGIN_URL . 'assets/js/artwork-control.js', array(), file_exists( $control_script ) ? (string) filemtime( $control_script ) : GE_WTP_VERSION, true );
            }
        }
    }

    public static function get_items( $user_id = 0, $include_archived = false ) {
        $args = array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'modified', 'order' => 'DESC' );
        if ( $user_id ) { $args['meta_query'] = array( array( 'key' => '_ge_artwork_customer_id', 'value' => absint( $user_id ), 'compare' => '=' ) ); }
        $posts = get_posts( $args );
        if ( $include_archived ) { return $posts; }
        return array_values( array_filter( $posts, function ( $post ) { return 'archived' !== get_post_meta( $post->ID, '_ge_artwork_status', true ); } ) );
    }

    public static function get_order_ids( $order ) {
        if ( ! $order ) { return array(); }
        $ids = $order->get_meta( self::ORDER_META, true );
        return is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
    }

    public static function attach_to_order( $order, $ids ) {
        if ( ! $order ) { return; }
        $allowed = array();
        foreach ( (array) $ids as $id ) { $id = absint( $id ); if ( $id && self::can_access( $id, (int) $order->get_customer_id() ) ) { $allowed[] = $id; } }
        $order->update_meta_data( self::ORDER_META, array_values( array_unique( $allowed ) ) );
        $order->save();
    }

    public static function copy_order_links( $source_order_id, $target_order ) {
        $source = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $source_order_id ) ) : false;
        if ( $source && $target_order ) { self::attach_to_order( $target_order, self::get_order_ids( $source ) ); }
    }

    public static function render_order_picker( $user_id = 0 ) {
        $items = self::get_items( $user_id ? $user_id : get_current_user_id() );
        if ( ! $items ) { return; }
        $selected = array(); $source_id = class_exists( 'GE_WTP_Reorders' ) ? GE_WTP_Reorders::source_order_id() : 0;
        if ( $source_id && function_exists( 'wc_get_order' ) ) { $selected = self::get_order_ids( wc_get_order( $source_id ) ); }
        ?>
        <fieldset class="ge-artwork-picker"><legend>Archivos guardados</legend><small>Vinculá la ficha del arte. El original pesado no se copia al VPS.</small><div><?php foreach ( $items as $item ) : ?><label><input type="checkbox" name="artwork_ids[]" value="<?php echo esc_attr( $item->ID ); ?>" <?php checked( in_array( $item->ID, $selected, true ) ); ?>><span><strong><?php echo esc_html( self::code( $item->ID ) ); ?></strong><?php echo esc_html( $item->post_title ); ?></span></label><?php endforeach; ?></div></fieldset>
        <?php
    }

    public static function render_order_links( $order, $context = 'customer' ) {
        if ( 'customer' === $context ) { self::render_customer_approval_panel( $order ); }
        $ids = self::get_order_ids( $order );
        if ( ! $ids ) { return; }
        echo '<section class="ge-artwork-order-links"><span class="ge-eyebrow">Artes vinculados</span><div class="ge-artwork-mini-grid">';
        foreach ( $ids as $id ) { $item = get_post( $id ); if ( ! $item || ! self::can_access( $id ) ) { continue; } self::render_card( $item, true ); }
        echo '</div></section>';
    }

    public static function render_customer_library( $user_id = 0, $markcom = false ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        $items = self::get_items( $user_id );
        $drive_notice = isset( $_GET['drive_notice'] ) ? sanitize_key( wp_unslash( $_GET['drive_notice'] ) ) : '';
        ?>
        <section class="ge-artwork-library <?php echo $markcom ? 'is-markcom' : ''; ?>"><div class="ge-artwork-heading"><div><span class="ge-eyebrow">Biblioteca de producción</span><h1>Mis archivos</h1><p>Fichas, versiones y previsualizaciones. Los originales se conservan fuera de este servidor.</p></div><span class="ge-artwork-count"><?php echo esc_html( count( $items ) ); ?></span></div>
        <?php if ( 'saved' === $drive_notice ) : ?><div class="ge-drive-customer-notice">El archivo quedó vinculado a tu biblioteca y, si elegiste un pedido, también a ese trabajo.</div><?php elseif ( 'error' === $drive_notice ) : ?><div class="ge-drive-customer-notice is-error">No pudimos registrar el archivo. Volvé a seleccionarlo desde Drive.</div><?php endif; ?>
        <?php if ( class_exists( 'GE_WTP_Google_Auth' ) && GE_WTP_Google_Auth::drive_enabled() ) { self::render_customer_drive_form( $user_id ); } ?>
        <?php if ( ! $items ) : ?><div class="ge-panel ge-artwork-empty"><strong>Todavía no hay archivos registrados.</strong><p>Graph Express creará una ficha cuando un arte quede aprobado para reutilizar.</p></div><?php else : ?><div class="ge-artwork-grid"><?php foreach ( $items as $item ) { self::render_card( $item ); } ?></div><?php endif; ?></section>
        <?php
    }

    private static function render_customer_drive_form( $user_id ) {
        $orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( array( 'customer_id' => absint( $user_id ), 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC' ) ) : array();
        $return_url = class_exists( 'GE_WTP_Portal' ) && is_page( 'cliente-markcom' ) ? GE_WTP_Portal::portal_url( 'documentos' ) : wc_get_account_endpoint_url( self::ENDPOINT );
        ?>
        <section class="ge-drive-customer-panel">
            <div class="ge-drive-customer-head"><b>DR</b><div><span>Google Drive</span><h2>Compartir un original</h2><p>Elegí un archivo que ya está en Drive o subilo directamente desde tu computadora. El archivo no atraviesa el servidor de Graph Express.</p></div></div>
            <form class="ge-customer-drive-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_customer_drive_artwork"><input type="hidden" name="return_url" value="<?php echo esc_url( $return_url ); ?>"><?php wp_nonce_field( 'ge_customer_drive_artwork' ); ?>
                <div class="ge-drive-customer-fields"><label>Nombre del trabajo<input type="text" name="artwork_name" required maxlength="180" placeholder="Ej.: Tarjetas corporativas Violeta"></label><label>Vincular a un pedido<select name="order_id"><option value="0">Guardar solamente en Mis archivos</option><?php foreach ( $orders as $order ) : ?><option value="<?php echo esc_attr( $order->get_id() ); ?>"><?php echo esc_html( ( class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id() ) . ' · ' . wc_format_datetime( $order->get_date_created(), 'd/m/Y' ) ); ?></option><?php endforeach; ?></select></label></div>
                <div class="ge-drive-customer-actions"><button type="button" data-ge-drive-picker>Elegir desde mi Drive</button><label><span>Subir desde mi computadora a Drive</span><input type="file" data-ge-drive-upload accept=".pdf,.ai,.eps,.psd,.tif,.tiff,.svg,.cdr,.zip,.jpg,.jpeg,.png"></label><button type="button" data-ge-drive-upload-button>Subir a mi Drive</button></div>
                <p data-ge-drive-status>Google pedirá permiso solamente para el archivo que elijas o subas.</p><progress data-ge-drive-progress max="100" value="0" hidden></progress>
                <input type="hidden" name="drive_file_id"><input type="hidden" name="drive_file_name"><input type="hidden" name="drive_mime_type"><input type="hidden" name="drive_file_url"><input type="hidden" name="drive_file_size"><input type="hidden" name="drive_file_source">
                <button class="ge-drive-customer-save" type="submit" disabled data-ge-drive-save>Guardar archivo en mi portal</button>
            </form>
        </section>
        <?php
    }

    public static function handle_customer_drive_artwork() {
        if ( ! is_user_logged_in() ) { auth_redirect(); }
        check_admin_referer( 'ge_customer_drive_artwork' );
        if ( ! class_exists( 'GE_WTP_Google_Auth' ) || ! GE_WTP_Google_Auth::drive_enabled() ) { wp_die( esc_html__( 'La integración con Google Drive no está disponible.', 'ge-wtp' ), '', array( 'response' => 503 ) ); }
        $user_id = get_current_user_id();
        $name = sanitize_text_field( wp_unslash( $_POST['artwork_name'] ?? '' ) );
        $file_id = sanitize_text_field( wp_unslash( $_POST['drive_file_id'] ?? '' ) );
        $file_name = sanitize_file_name( wp_unslash( $_POST['drive_file_name'] ?? '' ) );
        $return_url = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_url'] ?? '' ) ), class_exists( 'GE_WTP_Portal' ) ? GE_WTP_Portal::portal_url( 'documentos' ) : home_url( '/' ) );
        if ( ! $name || ! preg_match( '/^[a-zA-Z0-9_-]{10,200}$/', $file_id ) ) { wp_safe_redirect( add_query_arg( 'drive_notice', 'error', $return_url ) ); exit; }
        $existing = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'author' => $user_id, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_ge_artwork_drive_file_id', 'meta_value' => $file_id ) );
        $id = $existing ? absint( $existing[0] ) : wp_insert_post( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'post_title' => $name, 'post_author' => $user_id ), true );
        if ( is_wp_error( $id ) ) { wp_safe_redirect( add_query_arg( 'drive_notice', 'error', $return_url ) ); exit; }
        if ( $existing ) { wp_update_post( array( 'ID' => $id, 'post_title' => $name ) ); }
        $url = 'https://drive.google.com/open?id=' . rawurlencode( $file_id );
        update_post_meta( $id, '_ge_artwork_customer_id', $user_id );
        update_post_meta( $id, '_ge_artwork_status', 'review' );
        update_post_meta( $id, '_ge_artwork_version', '1' );
        update_post_meta( $id, '_ge_artwork_code', sprintf( 'GE-ART-%s-%05d', current_time( 'Y' ), $id ) );
        update_post_meta( $id, '_ge_artwork_original_name', $file_name ?: $name );
        update_post_meta( $id, '_ge_artwork_storage_provider', 'drive' );
        update_post_meta( $id, '_ge_artwork_drive_file_id', $file_id );
        update_post_meta( $id, '_ge_artwork_external_reference', $url );
        update_post_meta( $id, '_ge_artwork_original', array( 'provider' => 'drive', 'file_id' => $file_id, 'name' => $file_name ?: $name, 'mime' => sanitize_text_field( wp_unslash( $_POST['drive_mime_type'] ?? '' ) ), 'size' => absint( $_POST['drive_file_size'] ?? 0 ), 'url' => $url, 'source' => sanitize_key( wp_unslash( $_POST['drive_file_source'] ?? 'picker' ) ), 'linked_at' => current_time( 'mysql' ) ) );
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( $order && (int) $order->get_customer_id() === $user_id ) { self::attach_to_order( $order, array_merge( self::get_order_ids( $order ), array( $id ) ) ); }
        wp_safe_redirect( add_query_arg( 'drive_notice', 'saved', $return_url ) ); exit;
    }

    public static function account_content() { self::render_customer_library(); }

    public static function woo_checkout_picker() {
        if ( is_user_logged_in() && self::get_items( get_current_user_id() ) ) {
            echo '<section class="ge-woo-artwork-picker"><h3>Archivos guardados</h3><p>Si este pedido usa un arte que ya tenemos registrado, vinculalo acá.</p>';
            self::render_order_picker( get_current_user_id() );
            echo '</section>';
        }
    }

    public static function woo_checkout_save( $order, $data ) {
        if ( ! is_user_logged_in() || empty( $_POST['artwork_ids'] ) ) { return; }
        $allowed = array();
        foreach ( (array) wp_unslash( $_POST['artwork_ids'] ) as $id ) { $id = absint( $id ); if ( self::can_access( $id ) ) { $allowed[] = $id; } }
        $order->update_meta_data( self::ORDER_META, array_values( array_unique( $allowed ) ) );
    }

    public static function woo_order_links( $order ) {
        if ( 'yes' !== $order->get_meta( '_ge_markcom_order' ) ) { self::render_order_links( $order ); }
    }

    /**
     * Returns every production file that can be assigned to an individual order item.
     * Tokens are deliberately namespaced because library records and uploaded documents
     * use different identifiers.
     */
    public static function order_sources( $order ) {
        if ( ! $order instanceof WC_Order ) { return array(); }
        $sources = array();
        foreach ( self::get_order_ids( $order ) as $id ) {
            $artwork = get_post( $id );
            if ( ! $artwork || self::POST_TYPE !== $artwork->post_type ) { continue; }
            $original = get_post_meta( $id, '_ge_artwork_original', true );
            $original = is_array( $original ) ? $original : array();
            $analysis = isset( $original['analysis'] ) && is_array( $original['analysis'] ) ? $original['analysis'] : array();
            $sources[ 'artwork:' . $id ] = array(
                'token' => 'artwork:' . $id,
                'name' => $original['name'] ?? ( get_post_meta( $id, '_ge_artwork_original_name', true ) ?: $artwork->post_title ),
                'code' => self::code( $id ),
                'url' => get_post_meta( $id, '_ge_artwork_external_reference', true ) ?: self::original_url( $id ),
                'analysis' => $analysis,
                'hash' => $analysis['sha256'] ?? ( $original['file_id'] ?? (string) $id ),
                'kind' => 'library',
            );
        }
        if ( class_exists( 'GE_WTP_Documents' ) ) {
            foreach ( GE_WTP_Documents::get_documents_with_analysis( $order->get_id() ) as $document ) {
                if ( ! empty( $document['category'] ) && 'arte' !== $document['category'] ) { continue; }
                $id = sanitize_text_field( $document['id'] ?? '' );
                if ( ! $id ) { continue; }
                $analysis = isset( $document['analysis'] ) && is_array( $document['analysis'] ) ? $document['analysis'] : array();
                $sources[ 'document:' . $id ] = array(
                    'token' => 'document:' . $id,
                    'name' => $document['name'] ?? 'Archivo del pedido',
                    'code' => 'DOC-' . strtoupper( substr( preg_replace( '/[^a-zA-Z0-9]/', '', $id ), -8 ) ),
                    'url' => GE_WTP_Documents::download_url( $order->get_id(), $id ),
                    'analysis' => $analysis,
                    'hash' => $analysis['sha256'] ?? $id,
                    'kind' => 'document',
                );
            }
        }
        return $sources;
    }

    private static function item_release_data( $item ) {
        $sources = $item->get_meta( '_ge_item_artwork_sources', true );
        $expected = $item->get_meta( '_ge_item_artwork_expected', true );
        return array(
            'sources' => is_array( $sources ) ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $sources ) ) ) ) : array(),
            'version' => (string) $item->get_meta( '_ge_item_artwork_version', true ),
            'expected' => is_array( $expected ) ? $expected : array(),
            'customer' => (array) $item->get_meta( '_ge_item_artwork_customer_approval', true ),
            'staff' => (array) $item->get_meta( '_ge_item_artwork_staff_approval', true ),
            'release_hash' => (string) $item->get_meta( '_ge_item_artwork_release_hash', true ),
            'released_at' => absint( $item->get_meta( '_ge_item_artwork_released_at', true ) ),
        );
    }

    private static function release_fingerprint( $item, $tokens, $version, $expected, $available ) {
        $files = array();
        foreach ( (array) $tokens as $token ) {
            if ( isset( $available[ $token ] ) ) { $files[] = $token . ':' . ( $available[ $token ]['hash'] ?? '' ); }
        }
        sort( $files ); ksort( $expected );
        return hash( 'sha256', wp_json_encode( array( 'item' => $item->get_id(), 'files' => $files, 'version' => $version, 'expected' => $expected ) ) );
    }

    public static function attach_customer_uploads_to_item( $order, $item, $documents, $side = 'general' ) {
        if ( ! ( $order instanceof WC_Order ) || ! ( $item instanceof WC_Order_Item_Product ) || (int) $item->get_order_id() !== (int) $order->get_id() ) { return false; }
        $tokens = $item->get_meta( '_ge_item_artwork_sources', true );
        $tokens = is_array( $tokens ) ? $tokens : array();
        $new_ids = array_values( array_filter( array_map( static function ( $document ) { return sanitize_text_field( $document['id'] ?? '' ); }, (array) $documents ) ) );
        $superseded = array();
        foreach ( GE_WTP_Documents::get_documents( $order->get_id() ) as $document ) {
            $document_id = sanitize_text_field( $document['id'] ?? '' );
            if ( $document_id && ! in_array( $document_id, $new_ids, true ) && (int) ( $document['order_item_id'] ?? 0 ) === (int) $item->get_id() && sanitize_key( $document['artwork_side'] ?? 'general' ) === sanitize_key( $side ) ) {
                $superseded[] = 'document:' . $document_id;
            }
        }
        $tokens = array_values( array_diff( $tokens, $superseded ) );
        foreach ( (array) $documents as $document ) {
            $document_id = sanitize_text_field( $document['id'] ?? '' );
            if ( $document_id ) { $tokens[] = 'document:' . $document_id; }
        }
        $tokens = array_values( array_unique( array_filter( $tokens ) ) );
        $item->update_meta_data( '_ge_item_artwork_sources', $tokens );
        $item->update_meta_data( '_ge_item_artwork_version', 'Cliente · ' . current_time( 'd/m/Y H:i' ) );
        $item->delete_meta_data( '_ge_item_artwork_customer_approval' );
        $item->delete_meta_data( '_ge_item_artwork_staff_approval' );
        $item->delete_meta_data( '_ge_item_artwork_release_hash' );
        $item->delete_meta_data( '_ge_item_artwork_released_at' );
        $item->delete_meta_data( '_ge_item_artwork_released_by' );
        $item->update_meta_data( '_ge_item_artwork_last_side', sanitize_key( $side ) );
        $item->save();
        return true;
    }

    public static function item_ready_for_production( $item, $order = false ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $item->get_order_id() );
        if ( ! $order ) { return false; }
        $data = self::item_release_data( $item ); $available = self::order_sources( $order );
        foreach ( $data['sources'] as $token ) { if ( ! isset( $available[ $token ] ) ) { return false; } }
        if ( ! $data['sources'] || empty( $data['customer']['approved'] ) || empty( $data['staff']['approved'] ) ) { return false; }
        return $data['release_hash'] && hash_equals( $data['release_hash'], self::release_fingerprint( $item, $data['sources'], $data['version'], $data['expected'], $available ) );
    }

    public static function blocked_item_names( $order ) {
        $blocked = array();
        $items = self::production_items( $order );
        foreach ( $items as $item ) { if ( ! self::item_ready_for_production( $item, $order ) ) { $blocked[] = $item->get_name(); } }
        return $blocked;
    }

    public static function order_ready_for_dispatch( $order ) {
        if ( ! $order instanceof WC_Order ) { return false; }
        $items = self::production_items( $order );
        return ! empty( $items ) && ! self::blocked_item_names( $order );
    }

    private static function production_items( $order ) {
        if ( class_exists( 'GE_WTP_Production' ) && method_exists( 'GE_WTP_Production', 'actionable_items' ) ) { return GE_WTP_Production::actionable_items( $order, false ); }
        $items = array();
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $status = class_exists( 'GE_WTP_Production' ) && method_exists( 'GE_WTP_Production', 'item_status' ) ? GE_WTP_Production::item_status( $item, $order ) : 'approved';
            if ( in_array( $status, array( 'approved', 'production', 'ready' ), true ) ) { $items[ $item_id ] = $item; }
        }
        return $items;
    }

    public static function guard_production_transition() {
        if ( ! class_exists( 'GE_WTP_Staff_Portal' ) || ! GE_WTP_Staff_Portal::can_access() ) { return; }
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! $order ) { return; }
        foreach ( (array) ( $_POST['item_statuses'] ?? array() ) as $item_id => $posted ) {
            $item = $order->get_item( absint( $item_id ) ); $next = sanitize_key( wp_unslash( $posted ) );
            if ( ! $item || ! in_array( $next, array( 'production', 'ready' ), true ) ) { continue; }
            $current = class_exists( 'GE_WTP_Production' ) ? GE_WTP_Production::item_status( $item, $order ) : 'pending';
            if ( in_array( $current, array( 'production', 'ready' ), true ) ) { continue; }
            if ( ! self::item_ready_for_production( $item, $order ) ) { self::blocked_page( $order, array( $item->get_name() ), 'No se puede pasar este trabajo a producción' ); }
        }
    }

    public static function guard_supplier_dispatch() {
        if ( ! class_exists( 'GE_WTP_Staff_Portal' ) || ! GE_WTP_Staff_Portal::can_access() ) { return; }
        $order = wc_get_order( absint( $_REQUEST['order_id'] ?? 0 ) );
        if ( $order && ! self::order_ready_for_dispatch( $order ) ) { self::blocked_page( $order, self::blocked_item_names( $order ), 'Orden bloqueada por control de archivos' ); }
    }

    private static function blocked_page( $order, $items, $title ) {
        $back = GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id() ) );
        $message = '<p>Falta vincular y aprobar el archivo exacto de: <strong>' . esc_html( implode( ', ', $items ) ) . '</strong>.</p><p>El precio aprobado y el arte aprobado son controles independientes. Completá la liberación de archivos antes de continuar.</p><p><a href="' . esc_url( $back ) . '">Volver al pedido</a></p>';
        wp_die( wp_kses_post( $message ), esc_html( $title ), array( 'response' => 409 ) );
    }

    public static function handle_artwork_control_save() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! $order ) { wp_die( 'Pedido inválido.', 404 ); }
        check_admin_referer( 'ge_artwork_control_' . $order->get_id() );
        $available = self::order_sources( $order ); $rows = (array) ( $_POST['artwork_items'] ?? array() );
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $row = isset( $rows[ $item_id ] ) ? (array) $rows[ $item_id ] : array(); $tokens = array();
            foreach ( (array) ( $row['sources'] ?? array() ) as $token ) { $token = sanitize_text_field( wp_unslash( $token ) ); if ( isset( $available[ $token ] ) ) { $tokens[] = $token; } }
            $tokens = array_values( array_unique( $tokens ) );
            $version = sanitize_text_field( wp_unslash( $row['version'] ?? '' ) );
            $expected = array(
                'dimensions' => sanitize_text_field( wp_unslash( $row['dimensions'] ?? '' ) ),
                'pages' => sanitize_text_field( wp_unslash( $row['pages'] ?? '' ) ),
                'orientation' => sanitize_text_field( wp_unslash( $row['orientation'] ?? '' ) ),
                'notes' => sanitize_textarea_field( wp_unslash( $row['notes'] ?? '' ) ),
            );
            $old = self::item_release_data( $item );
            $new_hash = self::release_fingerprint( $item, $tokens, $version, $expected, $available );
            $old_hash = self::release_fingerprint( $item, $old['sources'], $old['version'], $old['expected'], $available );
            $changed = ! hash_equals( $old_hash, $new_hash );
            $customer = $changed ? array() : $old['customer']; $staff = $changed ? array() : $old['staff'];
            if ( ! empty( $row['customer_approved'] ) && $tokens ) { $customer = array( 'approved' => true, 'time' => time(), 'user_id' => get_current_user_id(), 'method' => sanitize_key( $row['customer_method'] ?? 'staff-recorded' ) ); }
            elseif ( empty( $row['customer_approved'] ) ) { $customer = array(); }
            if ( ! empty( $row['staff_approved'] ) && $tokens ) { $staff = array( 'approved' => true, 'time' => time(), 'user_id' => get_current_user_id(), 'method' => 'staff-control' ); }
            elseif ( empty( $row['staff_approved'] ) ) { $staff = array(); }
            $item->update_meta_data( '_ge_item_artwork_sources', $tokens );
            $item->update_meta_data( '_ge_item_artwork_version', $version );
            $item->update_meta_data( '_ge_item_artwork_expected', $expected );
            $item->update_meta_data( '_ge_item_artwork_customer_approval', $customer );
            $item->update_meta_data( '_ge_item_artwork_staff_approval', $staff );
            if ( $tokens && ! empty( $customer['approved'] ) && ! empty( $staff['approved'] ) ) {
                $item->update_meta_data( '_ge_item_artwork_release_hash', $new_hash ); $item->update_meta_data( '_ge_item_artwork_released_at', time() ); $item->update_meta_data( '_ge_item_artwork_released_by', get_current_user_id() );
            } else { $item->delete_meta_data( '_ge_item_artwork_release_hash' ); $item->delete_meta_data( '_ge_item_artwork_released_at' ); $item->delete_meta_data( '_ge_item_artwork_released_by' ); }
            $item->save();
        }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id(), 'artwork_saved' => 1 ) ) ); exit;
    }

    public static function handle_customer_artwork_approval() {
        if ( ! is_user_logged_in() ) { auth_redirect(); }
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! $order || ! GE_WTP_Documents::can_access_order( $order ) ) { wp_die( 'Acceso denegado.', 403 ); }
        check_admin_referer( 'ge_customer_artwork_approval_' . $order->get_id() );
        $selected = array_map( 'absint', (array) ( $_POST['approve_items'] ?? array() ) ); $available = self::order_sources( $order );
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! in_array( (int) $item_id, $selected, true ) ) { continue; }
            $data = self::item_release_data( $item ); if ( ! $data['sources'] ) { continue; }
            $customer = array( 'approved' => true, 'time' => time(), 'user_id' => get_current_user_id(), 'method' => 'customer-portal' );
            $item->update_meta_data( '_ge_item_artwork_customer_approval', $customer );
            if ( ! empty( $data['staff']['approved'] ) ) { $item->update_meta_data( '_ge_item_artwork_release_hash', self::release_fingerprint( $item, $data['sources'], $data['version'], $data['expected'], $available ) ); $item->update_meta_data( '_ge_item_artwork_released_at', time() ); }
            $item->save();
        }
        wp_safe_redirect( GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id(), 'ge_notice' => 'artwork-approved' ) ) ); exit;
    }

    private static function render_customer_approval_panel( $order ) {
        if ( ! $order instanceof WC_Order || ! GE_WTP_Documents::can_access_order( $order ) ) { return; }
        $available = self::order_sources( $order ); $has_mapped = false;
        echo '<section class="ge-artwork-approval ge-panel"><span class="ge-eyebrow">Archivos para producir</span><h2>Confirmá cada archivo</h2><p>La aprobación del presupuesto no aprueba automáticamente el diseño. Revisá nombre, versión y medidas antes de confirmar.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_customer_artwork_approval"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">'; wp_nonce_field( 'ge_customer_artwork_approval_' . $order->get_id() );
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $data = self::item_release_data( $item ); $ready = self::item_ready_for_production( $item, $order );
            echo '<article class="ge-artwork-approval-item ' . ( $ready ? 'is-ready' : '' ) . '"><div><strong>' . esc_html( $item->get_name() ) . '</strong><small>' . esc_html( number_format_i18n( $item->get_quantity() ) ) . ' unidades · versión ' . esc_html( $data['version'] ?: 'sin definir' ) . '</small></div>';
            if ( ! $data['sources'] ) { echo '<p class="ge-artwork-waiting">Graph Express todavía debe asignar el archivo exacto a este producto.</p>'; }
            else { $has_mapped = true; echo '<ul>'; foreach ( $data['sources'] as $token ) { if ( isset( $available[ $token ] ) ) { echo '<li><a target="_blank" rel="noopener" href="' . esc_url( $available[ $token ]['url'] ) . '">' . esc_html( $available[ $token ]['name'] ) . '</a><small>' . esc_html( $available[ $token ]['code'] . self::analysis_summary( $available[ $token ]['analysis'] ) ) . '</small></li>'; } } echo '</ul>'; self::render_expected( $data['expected'] ); if ( ! empty( $data['customer']['approved'] ) ) { echo '<b class="ge-release-state">✓ Confirmado por vos</b>'; } else { echo '<label class="ge-approval-check"><input type="checkbox" name="approve_items[]" value="' . esc_attr( $item_id ) . '"><span>Confirmo que estos son los archivos correctos para imprimir</span></label>'; } }
            echo '</article>';
        }
        if ( $has_mapped ) { echo '<button class="ge-artwork-approve-button" type="submit">Confirmar archivos seleccionados</button>'; }
        echo '</form></section>';
    }

    public static function render_staff_artwork_control() {
        if ( ! is_page( 'gestion' ) || ! GE_WTP_Staff_Portal::can_access() || 'production' !== sanitize_key( $_GET['section'] ?? '' ) ) { return; }
        $order = wc_get_order( absint( $_GET['order_id'] ?? 0 ) ); if ( ! $order ) { return; }
        $available = self::order_sources( $order );
        ?>
        <section class="ge-production-card ge-artwork-control" data-ge-artwork-control><div class="ge-production-section-head"><div><span>Control obligatorio</span><h2>Archivos por producto</h2></div><b><?php echo self::order_ready_for_dispatch( $order ) ? 'Listo para producir' : 'Producción bloqueada'; ?></b></div>
        <?php if ( ! empty( $_GET['artwork_saved'] ) ) : ?><div class="ge-production-notice">El control de archivos quedó guardado.</div><?php endif; ?>
        <p>Asigná el archivo exacto a cada ítem. Si cambia un archivo, una versión o una medida, las aprobaciones anteriores se invalidan.</p>
        <?php self::render_duplicate_warning( $available ); ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_artwork_control_save"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_artwork_control_' . $order->get_id() ); ?>
        <div class="ge-artwork-control-list"><?php foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) : $data = self::item_release_data( $item ); ?>
        <article><header><div><strong><?php echo esc_html( $item->get_name() ); ?></strong><small><?php echo esc_html( number_format_i18n( $item->get_quantity() ) ); ?> unidades</small></div><b class="<?php echo self::item_ready_for_production( $item, $order ) ? 'is-ready' : 'is-blocked'; ?>"><?php echo self::item_ready_for_production( $item, $order ) ? 'LIBERADO' : 'PENDIENTE'; ?></b></header>
        <fieldset><legend>Archivo(s) exacto(s) para este producto</legend><?php if ( ! $available ) : ?><p>No hay archivos vinculados al pedido. Cargalos en “Documentos del pedido” como Arte o vinculalos desde la Biblioteca.</p><?php else : foreach ( $available as $token => $source ) : ?><label class="ge-artwork-source"><input type="checkbox" name="artwork_items[<?php echo esc_attr( $item_id ); ?>][sources][]" value="<?php echo esc_attr( $token ); ?>" <?php checked( in_array( $token, $data['sources'], true ) ); ?>><span><strong><?php echo esc_html( $source['name'] ); ?></strong><small><?php echo esc_html( $source['code'] . self::analysis_summary( $source['analysis'] ) ); ?></small></span><a target="_blank" rel="noopener" href="<?php echo esc_url( $source['url'] ); ?>">Ver</a></label><?php endforeach; endif; ?></fieldset>
        <div class="ge-artwork-spec-grid"><label>Versión<input type="text" name="artwork_items[<?php echo esc_attr( $item_id ); ?>][version]" value="<?php echo esc_attr( $data['version'] ); ?>" placeholder="v1 final"></label><label>Medida esperada<input type="text" name="artwork_items[<?php echo esc_attr( $item_id ); ?>][dimensions]" value="<?php echo esc_attr( $data['expected']['dimensions'] ?? '' ); ?>" placeholder="21 × 14,5 cm"></label><label>Páginas/diseños esperados<input type="text" name="artwork_items[<?php echo esc_attr( $item_id ); ?>][pages]" value="<?php echo esc_attr( $data['expected']['pages'] ?? '' ); ?>" placeholder="5 archivos / 200 números"></label><label>Orientación<input type="text" name="artwork_items[<?php echo esc_attr( $item_id ); ?>][orientation]" value="<?php echo esc_attr( $data['expected']['orientation'] ?? '' ); ?>" placeholder="Horizontal"></label><label class="is-wide">Observaciones<input type="text" name="artwork_items[<?php echo esc_attr( $item_id ); ?>][notes]" value="<?php echo esc_attr( $data['expected']['notes'] ?? '' ); ?>" placeholder="Sponsors alternados, numeración, frente..."></label></div>
        <div class="ge-artwork-confirm-grid"><label><input type="checkbox" name="artwork_items[<?php echo esc_attr( $item_id ); ?>][customer_approved]" value="1" <?php checked( ! empty( $data['customer']['approved'] ) ); ?>><span><strong>Cliente confirmó este archivo</strong><small>Marcá sólo con evidencia en portal, email, WhatsApp o presencial.</small></span></label><label>Método<select name="artwork_items[<?php echo esc_attr( $item_id ); ?>][customer_method]"><option value="staff-recorded">Registrado manualmente</option><option value="whatsapp" <?php selected( $data['customer']['method'] ?? '', 'whatsapp' ); ?>>WhatsApp</option><option value="email" <?php selected( $data['customer']['method'] ?? '', 'email' ); ?>>Email</option><option value="in-person" <?php selected( $data['customer']['method'] ?? '', 'in-person' ); ?>>Presencial</option><option value="customer-portal" <?php selected( $data['customer']['method'] ?? '', 'customer-portal' ); ?>>Portal del cliente</option></select></label><label><input type="checkbox" name="artwork_items[<?php echo esc_attr( $item_id ); ?>][staff_approved]" value="1" <?php checked( ! empty( $data['staff']['approved'] ) ); ?>><span><strong>Control técnico Graph Express</strong><small>Nombre, medida, páginas, orientación y versión revisados.</small></span></label></div>
        </article><?php endforeach; ?></div><div class="ge-artwork-control-actions"><button class="ge-staff-button" type="submit">Guardar control de archivos</button><?php if ( self::order_ready_for_dispatch( $order ) ) : ?><a target="_blank" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ge_artwork_release_sheet&order_id=' . $order->get_id() ), 'ge_artwork_release_sheet_' . $order->get_id() ) ); ?>">Abrir ficha “Archivos a producir” ↗</a><?php endif; ?></div></form></section>
        <?php
    }

    private static function render_duplicate_warning( $sources ) {
        $seen = array(); $duplicates = array();
        foreach ( $sources as $source ) { $key = ! empty( $source['hash'] ) ? $source['hash'] : strtolower( $source['name'] ); if ( isset( $seen[ $key ] ) ) { $duplicates[] = $source['name']; } $seen[ $key ] = true; }
        if ( $duplicates ) { echo '<div class="ge-artwork-duplicate"><strong>Atención: posibles archivos duplicados</strong><span>' . esc_html( implode( ', ', array_unique( $duplicates ) ) ) . '. Verificá cuál corresponde antes de liberar.</span></div>'; }
    }

    private static function analysis_summary( $analysis ) {
        if ( ! is_array( $analysis ) || ! $analysis ) { return ' · análisis técnico pendiente'; }
        $parts = array(); if ( ! empty( $analysis['pages'] ) ) { $parts[] = $analysis['pages'] . ' pág.'; }
        if ( ! empty( $analysis['width'] ) && ! empty( $analysis['height'] ) ) { $parts[] = $analysis['width'] . ' × ' . $analysis['height'] . ' ' . ( $analysis['unit'] ?? '' ); }
        if ( ! empty( $analysis['orientation'] ) ) { $parts[] = $analysis['orientation']; }
        return $parts ? ' · ' . implode( ' · ', $parts ) : ' · análisis técnico pendiente';
    }

    private static function render_expected( $expected ) {
        $parts = array_filter( array( $expected['dimensions'] ?? '', $expected['pages'] ?? '', $expected['orientation'] ?? '', $expected['notes'] ?? '' ) );
        if ( $parts ) { echo '<p class="ge-artwork-expected"><strong>Debe coincidir con:</strong> ' . esc_html( implode( ' · ', $parts ) ) . '</p>'; }
    }

    public static function supplier_artwork_html( $order ) {
        $available = self::order_sources( $order ); $html = '<h3>Archivos liberados para producir</h3><ul>';
        foreach ( $order->get_items( 'line_item' ) as $item ) { if ( class_exists( 'GE_WTP_Production' ) && ! in_array( GE_WTP_Production::item_status( $item, $order ), array( 'approved', 'production', 'ready' ), true ) ) { continue; } $data = self::item_release_data( $item ); foreach ( $data['sources'] as $token ) { if ( isset( $available[ $token ] ) ) { $html .= '<li><strong>' . esc_html( $item->get_name() ) . ':</strong> <a href="' . esc_url( $available[ $token ]['url'] ) . '">' . esc_html( $available[ $token ]['name'] ) . '</a> · ' . esc_html( $available[ $token ]['code'] . ' · versión ' . ( $data['version'] ?: '-' ) . self::analysis_summary( $available[ $token ]['analysis'] ) ) . '</li>'; } } }
        return $html . '</ul><p><strong>Producir únicamente los archivos enumerados arriba.</strong></p>';
    }

    public static function supplier_artwork_text( $order ) {
        $available = self::order_sources( $order ); $lines = array( 'Archivos liberados para producir:' );
        foreach ( $order->get_items( 'line_item' ) as $item ) { if ( class_exists( 'GE_WTP_Production' ) && ! in_array( GE_WTP_Production::item_status( $item, $order ), array( 'approved', 'production', 'ready' ), true ) ) { continue; } $data = self::item_release_data( $item ); foreach ( $data['sources'] as $token ) { if ( isset( $available[ $token ] ) ) { $lines[] = '- ' . $item->get_name() . ': ' . $available[ $token ]['name'] . ' (' . $available[ $token ]['code'] . ', versión ' . ( $data['version'] ?: '-' ) . ') ' . $available[ $token ]['url']; } } }
        $lines[] = 'Producir únicamente estos archivos.'; return implode( "\n", $lines );
    }

    public static function handle_release_sheet() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order = wc_get_order( absint( $_GET['order_id'] ?? 0 ) ); if ( ! $order ) { wp_die( 'Pedido inválido.', 404 ); }
        check_admin_referer( 'ge_artwork_release_sheet_' . $order->get_id() );
        if ( ! self::order_ready_for_dispatch( $order ) ) { self::blocked_page( $order, self::blocked_item_names( $order ), 'Ficha no disponible' ); }
        $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id(); $available = self::order_sources( $order );
        ?><!doctype html><html><head><meta charset="utf-8"><title>Archivos a producir <?php echo esc_html( $reference ); ?></title><style>body{font:14px Arial;color:#17152a;margin:28px}.head{border-bottom:4px solid #ed1f7a;padding-bottom:14px;margin-bottom:20px}.head b{color:#ed1f7a}article{border:1px solid #bbb;border-radius:10px;padding:16px;margin:14px 0}h1,h2{margin:4px 0}ul{padding-left:20px}.ok{background:#e9f8ef;padding:10px;border-radius:6px;font-weight:bold}@media print{button{display:none}}</style></head><body><div class="head"><b>GRAPH EXPRESS · ARCHIVOS A PRODUCIR</b><h1><?php echo esc_html( $reference ); ?></h1><p>Emitida <?php echo esc_html( current_time( 'd/m/Y H:i' ) ); ?> · Cliente: <?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ); ?></p></div><?php foreach ( $order->get_items( 'line_item' ) as $item ) : if ( class_exists( 'GE_WTP_Production' ) && ! in_array( GE_WTP_Production::item_status( $item, $order ), array( 'approved', 'production', 'ready' ), true ) ) { continue; } $data = self::item_release_data( $item ); ?><article><h2><?php echo esc_html( $item->get_name() ); ?></h2><p>Cantidad: <strong><?php echo esc_html( $item->get_quantity() ); ?></strong> · Versión: <strong><?php echo esc_html( $data['version'] ?: '-' ); ?></strong></p><?php self::render_expected( $data['expected'] ); ?><ul><?php foreach ( $data['sources'] as $token ) : $source = $available[ $token ]; ?><li><strong><?php echo esc_html( $source['name'] ); ?></strong><br><?php echo esc_html( $source['code'] . self::analysis_summary( $source['analysis'] ) ); ?><br><?php echo esc_html( $source['url'] ); ?></li><?php endforeach; ?></ul><p class="ok">✓ Cliente confirmado · ✓ Control Graph Express · Liberado <?php echo esc_html( wp_date( 'd/m/Y H:i', $data['released_at'] ) ); ?></p></article><?php endforeach; ?><button onclick="window.print()">Imprimir ficha</button></body></html><?php exit;
    }

    public static function render_staff() {
        $edit_id = isset( $_GET['artwork_id'] ) ? absint( $_GET['artwork_id'] ) : 0;
        $edit = $edit_id ? get_post( $edit_id ) : false;
        $notice = isset( $_GET['library_notice'] ) ? sanitize_key( wp_unslash( $_GET['library_notice'] ) ) : '';
        $items = self::get_items( 0, true );
        ?>
        <div class="ge-staff-heading"><div><span>Producción</span><h1>Biblioteca de archivos</h1><p>Índice liviano de artes, versiones y ubicación de originales.</p></div><a class="ge-staff-button" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'library', array( 'artwork_id' => 'new' ) ) ); ?>">Nueva ficha</a></div>
        <?php if ( 'saved' === $notice ) : ?><div class="ge-notice ge-notice-success">La ficha se guardó correctamente.</div><?php elseif ( 'error' === $notice ) : ?><div class="ge-notice ge-notice-error">No pudimos guardar la ficha. Revisá los datos y la previsualización.</div><?php endif; ?>
        <?php if ( $edit || ( isset( $_GET['artwork_id'] ) && 'new' === sanitize_key( wp_unslash( $_GET['artwork_id'] ) ) ) ) { self::render_form( $edit ); } ?>
        <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Archivo maestro</span><h2>Fichas registradas</h2></div><strong><?php echo esc_html( count( $items ) ); ?></strong></div><?php if ( ! $items ) : ?><div class="ge-admin-empty">Todavía no hay fichas.</div><?php else : ?><div class="ge-library-table"><?php foreach ( $items as $item ) : $customer = get_userdata( absint( get_post_meta( $item->ID, '_ge_artwork_customer_id', true ) ) ); ?><article><?php self::preview_markup( $item->ID ); ?><div class="ge-library-row-main"><small><?php echo esc_html( self::code( $item->ID ) ); ?></small><h3><?php echo esc_html( $item->post_title ); ?></h3><p><?php echo esc_html( $customer ? ( $customer->display_name . ' · ' . $customer->user_email ) : 'Cliente sin asignar' ); ?></p></div><span class="ge-library-status is-<?php echo esc_attr( self::status( $item->ID ) ); ?>"><?php echo esc_html( self::status_label( self::status( $item->ID ) ) ); ?></span><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'library', array( 'artwork_id' => $item->ID ) ) ); ?>">Editar →</a></article><?php endforeach; ?></div><?php endif; ?></section>
        <?php
    }

    private static function render_form( $item ) {
        $id = $item ? $item->ID : 0;
        $customers = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 500 ) );
        ?>
        <section class="ge-admin-panel ge-library-editor"><div class="ge-admin-panel-head"><div><span><?php echo $id ? esc_html( self::code( $id ) ) : 'Nueva ficha'; ?></span><h2><?php echo $id ? 'Editar archivo' : 'Registrar archivo'; ?></h2></div><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'library' ) ); ?>">Cerrar</a></div><form class="ge-library-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_artwork_save"><input type="hidden" name="artwork_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'ge_artwork_save_' . $id ); ?><div class="ge-library-form-grid"><label class="is-wide">Nombre del trabajo<input type="text" name="artwork_name" required maxlength="180" value="<?php echo esc_attr( $item ? $item->post_title : '' ); ?>" placeholder="Ej.: Adhesivo lubricantes frente"></label><label>Cliente<select name="customer_id" required><option value="">Seleccionar</option><?php foreach ( $customers as $customer ) : ?><option value="<?php echo esc_attr( $customer->ID ); ?>" <?php selected( $id ? get_post_meta( $id, '_ge_artwork_customer_id', true ) : 0, $customer->ID ); ?>><?php echo esc_html( $customer->display_name . ' · ' . $customer->user_email ); ?></option><?php endforeach; ?></select></label><label>Estado<select name="artwork_status"><option value="active" <?php selected( $id ? self::status( $id ) : 'active', 'active' ); ?>>Activo / aprobado</option><option value="review" <?php selected( $id ? self::status( $id ) : '', 'review' ); ?>>En revisión</option><option value="archived" <?php selected( $id ? self::status( $id ) : '', 'archived' ); ?>>Archivado</option></select></label><label>Versión<input type="text" name="version" maxlength="60" value="<?php echo esc_attr( $id ? get_post_meta( $id, '_ge_artwork_version', true ) : '1' ); ?>" placeholder="v1 / Septiembre 2026"></label><label>Medidas<input type="text" name="dimensions" maxlength="100" value="<?php echo esc_attr( $id ? get_post_meta( $id, '_ge_artwork_dimensions', true ) : '' ); ?>" placeholder="60 × 90 cm"></label><label>Material / soporte<input type="text" name="material" maxlength="160" value="<?php echo esc_attr( $id ? get_post_meta( $id, '_ge_artwork_material', true ) : '' ); ?>" placeholder="Vinilo, lona, ilustración..."></label><label>Impresión / color<input type="text" name="print_specs" maxlength="160" value="<?php echo esc_attr( $id ? get_post_meta( $id, '_ge_artwork_print_specs', true ) : '' ); ?>" placeholder="UV full color, 4/4..."></label><label>Nombre del original<input type="text" name="original_name" maxlength="190" value="<?php echo esc_attr( $id ? get_post_meta( $id, '_ge_artwork_original_name', true ) : '' ); ?>" placeholder="archivo-final.pdf"></label><label>Ubicación futura<select name="storage_provider"><option value="pending" <?php selected( $id ? get_post_meta( $id, '_ge_artwork_storage_provider', true ) : 'pending', 'pending' ); ?>>A configurar</option><option value="graph-pc" <?php selected( $id ? get_post_meta( $id, '_ge_artwork_storage_provider', true ) : '', 'graph-pc' ); ?>>PC Graph Express</option><option value="drive" <?php selected( $id ? get_post_meta( $id, '_ge_artwork_storage_provider', true ) : '', 'drive' ); ?>>Google Drive</option><option value="canva" <?php selected( $id ? get_post_meta( $id, '_ge_artwork_storage_provider', true ) : '', 'canva' ); ?>>Canva</option><option value="dropbox" <?php selected( $id ? get_post_meta( $id, '_ge_artwork_storage_provider', true ) : '', 'dropbox' ); ?>>Dropbox</option><option value="s3" <?php selected( $id ? get_post_meta( $id, '_ge_artwork_storage_provider', true ) : '', 's3' ); ?>>Almacenamiento S3</option><option value="other" <?php selected( $id ? get_post_meta( $id, '_ge_artwork_storage_provider', true ) : '', 'other' ); ?>>Otro</option></select></label><label class="is-wide">Código, ruta o referencia externa<input type="text" name="external_reference" maxlength="500" value="<?php echo esc_attr( $id ? get_post_meta( $id, '_ge_artwork_external_reference', true ) : '' ); ?>" placeholder="Se completará cuando conectemos el almacenamiento externo"></label><label class="is-wide">Notas técnicas<textarea name="notes" rows="4" maxlength="2000" placeholder="Sangrado, terminaciones, observaciones de producción..."><?php echo esc_textarea( $id ? get_post_meta( $id, '_ge_artwork_notes', true ) : '' ); ?></textarea></label><label class="is-wide ge-original-upload">Original de producción<input type="file" name="artwork_original" accept=".pdf,.ai,.eps,.psd,.tif,.tiff,.svg,.cdr,.zip,.jpg,.jpeg,.png"><small>Hasta 1 GB. En localhost se guarda de forma privada; en producción se enviará al almacenamiento externo configurado.</small></label><label class="is-wide ge-preview-upload">Previsualización liviana<input type="file" name="artwork_preview" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG o WebP. Se reduce automáticamente a 1400 px y calidad web.</small></label><?php GE_WTP_Canva::render_library_panel( $id ); ?></div><button class="ge-staff-button" type="submit">Guardar ficha</button></form></section>
        <?php
    }

    private static function render_card( $item, $compact = false ) {
        $id = $item->ID;
        $original = get_post_meta( $id, '_ge_artwork_original', true );
        ?><article class="ge-artwork-card <?php echo $compact ? 'is-compact' : ''; ?>"><div class="ge-artwork-preview"><?php self::preview_markup( $id ); ?><span><?php echo esc_html( self::status_label( self::status( $id ) ) ); ?></span></div><div class="ge-artwork-body"><small><?php echo esc_html( self::code( $id ) ); ?></small><h3><?php echo esc_html( $item->post_title ); ?></h3><dl><?php self::detail( 'Versión', get_post_meta( $id, '_ge_artwork_version', true ) ); self::detail( 'Medidas', get_post_meta( $id, '_ge_artwork_dimensions', true ) ); self::detail( 'Material', get_post_meta( $id, '_ge_artwork_material', true ) ); self::detail( 'Impresión', get_post_meta( $id, '_ge_artwork_print_specs', true ) ); ?></dl><?php if ( ! $compact ) : ?><div class="ge-artwork-original"><strong>Original</strong><span><?php echo esc_html( get_post_meta( $id, '_ge_artwork_original_name', true ) ?: 'Pendiente de registrar' ); ?></span><small><?php echo esc_html( self::provider_label( get_post_meta( $id, '_ge_artwork_storage_provider', true ) ) ); ?></small><?php if ( is_array( $original ) && 'local' === ( isset( $original['provider'] ) ? $original['provider'] : '' ) ) : ?><a href="<?php echo esc_url( self::original_url( $id ) ); ?>">Descargar original · <?php echo esc_html( size_format( isset( $original['size'] ) ? $original['size'] : 0 ) ); ?></a><?php elseif ( is_array( $original ) && 'drive' === ( isset( $original['provider'] ) ? $original['provider'] : '' ) && ! empty( $original['url'] ) ) : ?><a href="<?php echo esc_url( $original['url'] ); ?>" target="_blank" rel="noopener">Abrir original en Google Drive ↗</a><?php endif; ?></div><?php endif; ?></div></article><?php
    }

    private static function detail( $label, $value ) { if ( $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>'; } }
    private static function preview_markup( $id ) { $data = self::preview_data( $id ); if ( $data ) { echo '<img src="' . esc_url( self::preview_url( $id ) ) . '" alt="Previsualización de ' . esc_attr( get_the_title( $id ) ) . '">'; } else { echo '<div class="ge-artwork-placeholder"><b>GE</b><span>Sin preview</span></div>'; } }

    public static function handle_save() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $id = isset( $_POST['artwork_id'] ) ? absint( $_POST['artwork_id'] ) : 0;
        check_admin_referer( 'ge_artwork_save_' . $id );
        $name = isset( $_POST['artwork_name'] ) ? sanitize_text_field( wp_unslash( $_POST['artwork_name'] ) ) : '';
        $customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
        if ( ! $name || ! get_userdata( $customer_id ) || ( $id && self::POST_TYPE !== get_post_type( $id ) ) ) { self::staff_redirect( 'error', $id ); }
        $post = array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'post_title' => $name, 'post_author' => get_current_user_id() );
        if ( $id ) { $post['ID'] = $id; $result = wp_update_post( $post, true ); } else { $result = wp_insert_post( $post, true ); }
        if ( is_wp_error( $result ) ) { self::staff_redirect( 'error', $id ); }
        $id = absint( $result );
        $fields = array( '_ge_artwork_customer_id' => $customer_id, '_ge_artwork_status' => self::posted_choice( 'artwork_status', array( 'active', 'review', 'archived' ), 'active' ), '_ge_artwork_version' => self::posted_text( 'version' ), '_ge_artwork_dimensions' => self::posted_text( 'dimensions' ), '_ge_artwork_material' => self::posted_text( 'material' ), '_ge_artwork_print_specs' => self::posted_text( 'print_specs' ), '_ge_artwork_original_name' => self::posted_text( 'original_name' ), '_ge_artwork_storage_provider' => self::posted_choice( 'storage_provider', array( 'pending', 'graph-pc', 'drive', 'canva', 'dropbox', 's3', 'other' ), 'pending' ), '_ge_artwork_external_reference' => self::posted_text( 'external_reference' ), '_ge_artwork_notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '' );
        foreach ( $fields as $key => $value ) { update_post_meta( $id, $key, $value ); }
        $canva_id = self::posted_text( 'canva_design_id' );
        if ( $canva_id ) { update_post_meta( $id, '_ge_canva_design', array( 'id' => $canva_id, 'title' => self::posted_text( 'canva_title' ), 'edit_url' => esc_url_raw( self::posted_text( 'canva_edit_url' ) ), 'view_url' => esc_url_raw( self::posted_text( 'canva_view_url' ) ), 'physical_width' => (float) self::posted_text( 'canva_physical_width' ), 'physical_height' => (float) self::posted_text( 'canva_physical_height' ), 'unit' => self::posted_choice( 'canva_unit', array( 'cm', 'mm', 'px' ), 'cm' ), 'pixel_width' => absint( self::posted_text( 'canva_pixel_width' ) ), 'pixel_height' => absint( self::posted_text( 'canva_pixel_height' ) ), 'linked_at' => current_time( 'mysql' ) ) ); }
        $drive_id = self::posted_text( 'drive_file_id' );
        if ( $drive_id && preg_match( '/^[a-zA-Z0-9_-]{10,200}$/', $drive_id ) ) {
            $drive_name = sanitize_file_name( self::posted_text( 'drive_file_name' ) );
            $drive_url = 'https://drive.google.com/open?id=' . rawurlencode( $drive_id );
            $drive_data = array( 'provider' => 'drive', 'file_id' => $drive_id, 'name' => $drive_name, 'mime' => self::posted_text( 'drive_mime_type' ), 'size' => absint( self::posted_text( 'drive_file_size' ) ), 'url' => $drive_url, 'linked_at' => current_time( 'mysql' ) );
            update_post_meta( $id, '_ge_artwork_original', $drive_data );
            update_post_meta( $id, '_ge_artwork_original_name', $drive_name );
            update_post_meta( $id, '_ge_artwork_storage_provider', 'drive' );
            update_post_meta( $id, '_ge_artwork_external_reference', $drive_url );
        }
        if ( ! get_post_meta( $id, '_ge_artwork_code', true ) ) { update_post_meta( $id, '_ge_artwork_code', sprintf( 'GE-ART-%s-%05d', current_time( 'Y' ), $id ) ); }
        $preview = self::save_preview( $id );
        if ( is_wp_error( $preview ) ) { self::staff_redirect( 'error', $id ); }
        $original = self::save_original( $id );
        if ( is_wp_error( $original ) ) { self::staff_redirect( 'error', $id ); }
        self::staff_redirect( 'saved', $id );
    }

    private static function save_preview( $id ) {
        if ( empty( $_FILES['artwork_preview']['name'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['artwork_preview']['error'] ) { return true; }
        $file = $_FILES['artwork_preview'];
        if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 2 * MB_IN_BYTES ) { return new WP_Error( 'preview_invalid', 'Preview inválida.' ); }
        $allowed = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
        $check = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ), $allowed );
        if ( empty( $check['ext'] ) ) { return new WP_Error( 'preview_type', 'Formato inválido.' ); }
        if ( ! self::ensure_preview_directory() ) { return new WP_Error( 'preview_storage', 'Almacenamiento no disponible.' ); }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $editor = wp_get_image_editor( $file['tmp_name'] );
        if ( is_wp_error( $editor ) ) { return $editor; }
        $editor->resize( 1400, 1400, false ); $editor->set_quality( 78 );
        $filename = wp_generate_uuid4() . '.jpg'; $path = trailingslashit( self::preview_directory() ) . $filename;
        $saved = $editor->save( $path, 'image/jpeg' ); if ( is_wp_error( $saved ) ) { return $saved; }
        $old = self::preview_data( $id );
        update_post_meta( $id, '_ge_artwork_preview', array( 'stored_name' => $filename, 'mime' => 'image/jpeg', 'size' => filesize( $path ), 'width' => isset( $saved['width'] ) ? absint( $saved['width'] ) : 0, 'height' => isset( $saved['height'] ) ? absint( $saved['height'] ) : 0 ) );
        if ( $old && ! empty( $old['stored_name'] ) ) { $old_path = trailingslashit( self::preview_directory() ) . wp_basename( $old['stored_name'] ); if ( is_file( $old_path ) ) { wp_delete_file( $old_path ); } }
        return true;
    }

    public static function handle_preview() {
        $id = isset( $_GET['artwork_id'] ) ? absint( $_GET['artwork_id'] ) : 0;
        check_admin_referer( 'ge_artwork_preview_' . $id );
        if ( ! self::can_access( $id ) ) { wp_die( 'Acceso denegado.', 403 ); }
        $data = self::preview_data( $id ); $path = $data ? trailingslashit( self::preview_directory() ) . wp_basename( $data['stored_name'] ) : '';
        if ( ! $path || ! is_file( $path ) ) { wp_die( 'Previsualización no disponible.', 404 ); }
        nocache_headers(); header( 'Content-Type: ' . $data['mime'] ); header( 'Content-Length: ' . filesize( $path ) ); header( 'Content-Disposition: inline; filename="preview-' . rawurlencode( self::code( $id ) ) . '.jpg"' ); readfile( $path ); exit; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
    }

    public static function preview_url( $id ) { return wp_nonce_url( admin_url( 'admin-post.php?action=ge_artwork_preview&artwork_id=' . absint( $id ) ), 'ge_artwork_preview_' . absint( $id ) ); }
    public static function original_url( $id ) { return wp_nonce_url( admin_url( 'admin-post.php?action=ge_artwork_original&artwork_id=' . absint( $id ) ), 'ge_artwork_original_' . absint( $id ) ); }
    private static function save_original( $id ) {
        if ( empty( $_FILES['artwork_original']['name'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['artwork_original']['error'] ) { return true; }
        $file = $_FILES['artwork_original']; $max = 1024 * MB_IN_BYTES;
        if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > $max ) { return new WP_Error( 'original_invalid', 'El original supera el límite o no pudo recibirse.' ); }
        $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $allowed = array( 'pdf', 'ai', 'eps', 'psd', 'tif', 'tiff', 'svg', 'cdr', 'zip', 'jpg', 'jpeg', 'png' );
        if ( ! in_array( $extension, $allowed, true ) ) { return new WP_Error( 'original_type', 'Formato de original no permitido.' ); }
        $external = apply_filters( 'ge_wtp_store_artwork_original', null, $file, $id );
        if ( is_wp_error( $external ) ) { return $external; }
        if ( is_array( $external ) ) { update_post_meta( $id, '_ge_artwork_original', $external ); update_post_meta( $id, '_ge_artwork_original_name', sanitize_file_name( $file['name'] ) ); return true; }
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
        if ( 'local' !== wp_get_environment_type() && false === strpos( $host, 'localhost' ) && false === strpos( $host, '127.0.0.1' ) ) { return new WP_Error( 'external_required', 'Configurá el almacenamiento externo antes de recibir originales en producción.' ); }
        if ( ! self::ensure_original_directory() ) { return new WP_Error( 'original_storage', 'No se pudo preparar el almacenamiento privado local.' ); }
        $stored = wp_generate_uuid4() . '.' . $extension; $path = trailingslashit( self::original_directory() ) . $stored;
        if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) { return new WP_Error( 'original_move', 'No se pudo guardar el original.' ); }
        $old = get_post_meta( $id, '_ge_artwork_original', true );
        $mime = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : 'application/octet-stream';
        $analysis = class_exists( 'GE_WTP_Documents' ) ? GE_WTP_Documents::analyze_file( $path, $mime ) : array();
        update_post_meta( $id, '_ge_artwork_original', array( 'provider' => 'local', 'stored_name' => $stored, 'name' => sanitize_file_name( $file['name'] ), 'size' => (int) $file['size'], 'mime' => $mime, 'analysis' => $analysis, 'uploaded_at' => current_time( 'mysql' ) ) );
        update_post_meta( $id, '_ge_artwork_original_name', sanitize_file_name( $file['name'] ) ); update_post_meta( $id, '_ge_artwork_storage_provider', 'local' );
        if ( is_array( $old ) && 'local' === ( isset( $old['provider'] ) ? $old['provider'] : '' ) && ! empty( $old['stored_name'] ) ) { $old_path = trailingslashit( self::original_directory() ) . wp_basename( $old['stored_name'] ); if ( is_file( $old_path ) ) { wp_delete_file( $old_path ); } }
        return true;
    }
    public static function handle_original_download() {
        $id = isset( $_GET['artwork_id'] ) ? absint( $_GET['artwork_id'] ) : 0; check_admin_referer( 'ge_artwork_original_' . $id );
        if ( ! self::can_access( $id ) ) { wp_die( 'Acceso denegado.', 403 ); }
        $data = get_post_meta( $id, '_ge_artwork_original', true );
        if ( ! is_array( $data ) || 'local' !== ( isset( $data['provider'] ) ? $data['provider'] : '' ) ) { wp_die( 'El original está en almacenamiento externo o todavía no está disponible.', 404 ); }
        $path = trailingslashit( self::original_directory() ) . wp_basename( $data['stored_name'] ); if ( ! is_file( $path ) ) { wp_die( 'Original no disponible.', 404 ); }
        nocache_headers(); header( 'Content-Type: ' . ( ! empty( $data['mime'] ) ? $data['mime'] : 'application/octet-stream' ) ); header( 'Content-Length: ' . filesize( $path ) ); header( 'Content-Disposition: attachment; filename="' . rawurlencode( $data['name'] ) . '"' ); readfile( $path ); exit;
    }
    private static function preview_data( $id ) { $data = get_post_meta( $id, '_ge_artwork_preview', true ); return is_array( $data ) ? $data : array(); }
    private static function preview_directory() { return WP_CONTENT_DIR . '/ge-private/artwork-previews'; }
    private static function original_directory() { return WP_CONTENT_DIR . '/ge-private/artwork-originals'; }
    private static function ensure_original_directory() { $dir = self::original_directory(); if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); } if ( is_dir( $dir ) && ! file_exists( $dir . '/.htaccess' ) ) { file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); } if ( is_dir( $dir ) && ! file_exists( $dir . '/index.php' ) ) { file_put_contents( $dir . '/index.php', "<?php\nhttp_response_code(404); exit;\n" ); } return is_dir( $dir ) && is_writable( $dir ); }
    private static function ensure_preview_directory() { $dir = self::preview_directory(); if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); } if ( is_dir( $dir ) && ! file_exists( $dir . '/.htaccess' ) ) { file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); } if ( is_dir( $dir ) && ! file_exists( $dir . '/index.php' ) ) { file_put_contents( $dir . '/index.php', "<?php\nhttp_response_code(404); exit;\n" ); } return is_dir( $dir ) && is_writable( $dir ); }
    private static function can_access( $id, $user_id = 0 ) { if ( self::POST_TYPE !== get_post_type( $id ) || ! is_user_logged_in() ) { return false; } if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'ge_manage_operations' ) ) { return true; } return absint( get_post_meta( $id, '_ge_artwork_customer_id', true ) ) === ( $user_id ? absint( $user_id ) : get_current_user_id() ); }
    private static function code( $id ) { return get_post_meta( $id, '_ge_artwork_code', true ) ?: 'GE-ART-' . absint( $id ); }
    private static function status( $id ) { return get_post_meta( $id, '_ge_artwork_status', true ) ?: 'active'; }
    private static function status_label( $status ) { $labels = array( 'active' => 'Activo', 'review' => 'En revisión', 'archived' => 'Archivado' ); return isset( $labels[ $status ] ) ? $labels[ $status ] : 'Activo'; }
    public static function store_canva_export( $id, $url ) {
        if ( self::POST_TYPE !== get_post_type( $id ) || ! GE_WTP_Staff_Portal::can_access() ) { return new WP_Error( 'canva_access', 'No se puede guardar este PDF.' ); }
        $external = apply_filters( 'ge_wtp_store_canva_export', null, $url, $id ); if ( is_wp_error( $external ) ) { return $external; }
        if ( is_array( $external ) ) { update_post_meta( $id, '_ge_artwork_original', $external ); update_post_meta( $id, '_ge_artwork_original_name', 'canva-' . $id . '.pdf' ); update_post_meta( $id, '_ge_artwork_storage_provider', $external['provider'] ?? 'other' ); update_post_meta( $id, '_ge_artwork_status', 'review' ); return true; }
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : ''; if ( 'local' !== wp_get_environment_type() && false === strpos( $host, 'localhost' ) && false === strpos( $host, '127.0.0.1' ) ) { return new WP_Error( 'canva_external_required', 'Configurá almacenamiento externo antes de importar PDFs de Canva en producción.' ); }
        if ( ! self::ensure_original_directory() ) { return new WP_Error( 'canva_storage', 'No se pudo preparar el almacenamiento local.' ); }
        require_once ABSPATH . 'wp-admin/includes/file.php'; $temp = download_url( esc_url_raw( $url ), 60 ); if ( is_wp_error( $temp ) ) { return $temp; }
        if ( filesize( $temp ) > 1024 * MB_IN_BYTES ) { wp_delete_file( $temp ); return new WP_Error( 'canva_size', 'El PDF supera el límite de 1 GB.' ); }
        $stored = wp_generate_uuid4() . '.pdf'; $path = trailingslashit( self::original_directory() ) . $stored; if ( ! rename( $temp, $path ) ) { wp_delete_file( $temp ); return new WP_Error( 'canva_move', 'No se pudo conservar el PDF.' ); }
        update_post_meta( $id, '_ge_artwork_original', array( 'provider' => 'local', 'source' => 'canva', 'stored_name' => $stored, 'name' => 'canva-' . $id . '.pdf', 'size' => filesize( $path ), 'mime' => 'application/pdf', 'uploaded_at' => current_time( 'mysql' ) ) ); update_post_meta( $id, '_ge_artwork_original_name', 'canva-' . $id . '.pdf' ); update_post_meta( $id, '_ge_artwork_storage_provider', 'local' ); update_post_meta( $id, '_ge_artwork_status', 'review' ); return true;
    }
    private static function provider_label( $provider ) { $labels = array( 'pending' => 'Ubicación a configurar', 'local' => 'Almacenamiento privado local', 'graph-pc' => 'PC Graph Express', 'drive' => 'Google Drive', 'canva' => 'Canva', 'dropbox' => 'Dropbox', 's3' => 'Almacenamiento externo', 'other' => 'Ubicación externa' ); return isset( $labels[ $provider ] ) ? $labels[ $provider ] : 'Ubicación a configurar'; }
    private static function posted_text( $key ) { return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; }
    private static function posted_choice( $key, $allowed, $default ) { $value = self::posted_text( $key ); return in_array( $value, $allowed, true ) ? $value : $default; }
    private static function staff_redirect( $notice, $id = 0 ) { $args = array( 'library_notice' => $notice ); if ( $id ) { $args['artwork_id'] = $id; } wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'library', $args ) ); exit; }
}
