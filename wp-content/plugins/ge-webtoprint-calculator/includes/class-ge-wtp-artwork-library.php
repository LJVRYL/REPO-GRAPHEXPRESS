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
            wp_enqueue_style( 'ge-artwork-library', GE_WTP_PLUGIN_URL . 'assets/css/artwork-library.css', array(), GE_WTP_VERSION );
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
        $ids = self::get_order_ids( $order );
        if ( ! $ids ) { return; }
        echo '<section class="ge-artwork-order-links"><span class="ge-eyebrow">Artes vinculados</span><div class="ge-artwork-mini-grid">';
        foreach ( $ids as $id ) { $item = get_post( $id ); if ( ! $item || ! self::can_access( $id ) ) { continue; } self::render_card( $item, true ); }
        echo '</div></section>';
    }

    public static function render_customer_library( $user_id = 0, $markcom = false ) {
        $items = self::get_items( $user_id ? $user_id : get_current_user_id() );
        ?>
        <section class="ge-artwork-library <?php echo $markcom ? 'is-markcom' : ''; ?>"><div class="ge-artwork-heading"><div><span class="ge-eyebrow">Biblioteca de producción</span><h1>Mis archivos</h1><p>Fichas, versiones y previsualizaciones. Los originales se conservan fuera de este servidor.</p></div><span class="ge-artwork-count"><?php echo esc_html( count( $items ) ); ?></span></div>
        <?php if ( ! $items ) : ?><div class="ge-panel ge-artwork-empty"><strong>Todavía no hay archivos registrados.</strong><p>Graph Express creará una ficha cuando un arte quede aprobado para reutilizar.</p></div><?php else : ?><div class="ge-artwork-grid"><?php foreach ( $items as $item ) { self::render_card( $item ); } ?></div><?php endif; ?></section>
        <?php
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
        update_post_meta( $id, '_ge_artwork_original', array( 'provider' => 'local', 'stored_name' => $stored, 'name' => sanitize_file_name( $file['name'] ), 'size' => (int) $file['size'], 'mime' => function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : 'application/octet-stream', 'uploaded_at' => current_time( 'mysql' ) ) );
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
