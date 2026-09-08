<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Backoffice {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 9 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_ge_backoffice_order_status', array( __CLASS__, 'handle_order_status' ) );
        add_action( 'admin_post_ge_backoffice_order_document', array( __CLASS__, 'handle_order_document' ) );
        add_action( 'admin_post_ge_backoffice_candidate_status', array( __CLASS__, 'handle_candidate_status' ) );
    }

    public static function register_menu() {
        add_menu_page( 'Graph Express', 'Graph Express', 'manage_woocommerce', 'ge-backoffice', array( __CLASS__, 'render_dashboard' ), 'dashicons-printer', 56 );
        add_submenu_page( 'ge-backoffice', 'Tablero', 'Tablero', 'manage_woocommerce', 'ge-backoffice', array( __CLASS__, 'render_dashboard' ) );
        add_submenu_page( 'ge-backoffice', 'Pedidos', 'Pedidos', 'manage_woocommerce', 'ge-backoffice-orders', array( __CLASS__, 'render_orders' ) );
        add_submenu_page( 'ge-backoffice', 'Comunicaciones', 'Comunicaciones', 'manage_woocommerce', 'ge-backoffice-newsletter', array( 'GE_WTP_Newsletter', 'render_admin' ) );
        add_submenu_page( 'ge-backoffice', 'Candidatos', 'Candidatos', 'manage_woocommerce', 'ge-backoffice-candidates', array( __CLASS__, 'render_candidates' ) );
    }

    public static function enqueue_assets( $hook ) {
        if ( false !== strpos( (string) $hook, 'ge-backoffice' ) ) {
            wp_enqueue_style( 'ge-backoffice', GE_WTP_PLUGIN_URL . 'assets/css/admin.css', array(), GE_WTP_VERSION );
        }
    }

    private static function guard() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'ge_manage_operations' ) ) {
            wp_die( 'Acceso denegado.', 403 );
        }
    }

    private static function page_header( $eyebrow, $title, $description ) {
        ?>
        <div class="ge-admin-heading">
            <div><span><?php echo esc_html( $eyebrow ); ?></span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $description ); ?></p></div>
            <div class="ge-admin-heading-actions"><a class="button" target="_blank" href="<?php echo esc_url( home_url( '/' ) ); ?>">Ver sitio ↗</a><a class="button button-primary" target="_blank" href="<?php echo esc_url( GE_WTP_Portal::portal_url() ); ?>">Portal clientes ↗</a></div>
        </div>
        <?php
    }

    public static function render_communications_header() {
        self::page_header( 'Comunicaciones', 'Newsletter y correos', 'Contactos, campañas y trazabilidad de los mensajes enviados.' );
    }

    private static function notice() {
        if ( ! empty( $_GET['ge_saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Cambios guardados correctamente.</p></div>';
        }
    }

    public static function render_dashboard() {
        self::guard();
        $orders = GE_WTP_Orders::get_all_orders( 100 );
        $markcom_orders = array_filter( $orders, function( $order ) { return 'yes' === $order->get_meta( '_ge_markcom_order' ); } );
        $active = 0;
        $documents = 0;
        foreach ( $orders as $order ) {
            if ( ! in_array( $order->get_status(), array( 'ge-entregado', 'ge-cobrado', 'cancelled', 'refunded' ), true ) ) {
                $active++;
            }
            $documents += count( GE_WTP_Documents::get_documents( $order->get_id() ) );
        }
        $candidates = GE_WTP_Jobs::get_candidates();
        ?>
        <div class="wrap ge-admin-wrap">
            <?php self::page_header( 'Operación', 'Buen día, ' . wp_get_current_user()->display_name, 'Pedidos, producción y personas en un solo lugar.' ); ?>
            <div class="ge-admin-metrics">
                <article><span>Pedidos activos</span><strong><?php echo esc_html( $active ); ?></strong><small>requieren seguimiento</small></article>
                <article><span>Pedidos totales</span><strong><?php echo esc_html( count( $orders ) ); ?></strong><small>tienda y portal</small></article>
                <article><span>Pedidos Markcom</span><strong><?php echo esc_html( count( $markcom_orders ) ); ?></strong><small>cuenta corporativa</small></article>
                <article><span>Documentos</span><strong><?php echo esc_html( $documents ); ?></strong><small>archivos privados</small></article>
            </div>
            <div class="ge-admin-layout">
                <section class="ge-admin-panel ge-admin-panel-wide"><div class="ge-admin-panel-head"><div><span>Actividad</span><h2>Últimos pedidos</h2></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=ge-backoffice-orders' ) ); ?>">Ver todos →</a></div><?php self::orders_table( array_slice( $orders, 0, 6 ), false ); ?></section>
                <aside class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Accesos rápidos</span><h2>Gestión diaria</h2></div></div><div class="ge-admin-shortcuts"><a href="<?php echo esc_url( admin_url( 'admin.php?page=ge-backoffice-orders' ) ); ?>"><b>01</b><span><strong>Revisar pedidos</strong><small>Producción y documentos</small></span></a><a href="<?php echo esc_url( admin_url( 'admin.php?page=ge-backoffice-newsletter' ) ); ?>"><b>02</b><span><strong>Comunicaciones</strong><small>Newsletters y correos enviados</small></span></a><a href="<?php echo esc_url( admin_url( 'admin.php?page=ge-backoffice-candidates' ) ); ?>"><b>03</b><span><strong>Ver candidatos</strong><small>Perfiles y contactos</small></span></a><a href="<?php echo esc_url( admin_url( 'admin.php?page=ge-webtoprint' ) ); ?>"><b>04</b><span><strong>Configurar portal</strong><small>Cotización y catálogo</small></span></a></div></aside>
            </div>
        </div>
        <?php
    }

    public static function render_orders() {
        self::guard();
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
        ?>
        <div class="wrap ge-admin-wrap">
            <?php self::page_header( 'Producción', 'Pedidos', 'Órdenes de la tienda y del portal corporativo.' ); self::notice(); ?>
            <?php if ( $order ) : self::order_detail( $order ); else : ?>
                <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Vista general</span><h2>Todos los pedidos</h2></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders' ) ); ?>">WooCommerce</a></div><?php self::orders_table( GE_WTP_Orders::get_all_orders( 200 ), true ); ?></section>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function orders_table( $orders, $show_customer ) {
        if ( ! $orders ) {
            echo '<div class="ge-admin-empty"><strong>Todavía no hay pedidos.</strong><span>Las órdenes nuevas aparecerán automáticamente acá.</span></div>';
            return;
        }
        echo '<div class="ge-admin-table-scroll"><table class="ge-admin-table"><thead><tr><th>Orden</th>';
        if ( $show_customer ) { echo '<th>Cliente</th>'; }
        echo '<th>Origen</th><th>Fecha</th><th>PO / pago</th><th>Estado</th><th>Total</th><th></th></tr></thead><tbody>';
        foreach ( $orders as $order ) {
            $url = admin_url( 'admin.php?page=ge-backoffice-orders&order_id=' . $order->get_id() );
            $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id();
            echo '<tr><td><a class="ge-admin-order" href="' . esc_url( $url ) . '">' . esc_html( $reference ) . '</a><small>' . esc_html( $order->get_item_count() ) . ' ítems</small></td>';
            if ( $show_customer ) { echo '<td>' . esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ) . '</td>'; }
            $is_markcom = 'yes' === $order->get_meta( '_ge_markcom_order' );
            $is_manual = 'yes' === $order->get_meta( '_ge_manual_order' );
            $payment = $is_markcom ? ( $order->get_meta( '_ge_markcom_po_reference' ) ?: 'PO pendiente' ) : ( $order->get_payment_method_title() ?: 'Sin definir' );
            echo '<td><span class="ge-admin-origin ' . ( $is_markcom ? 'is-markcom' : 'is-store' ) . '">' . esc_html( $is_markcom ? 'Markcom' : ( $is_manual ? 'Mostrador' : 'Tienda' ) ) . '</span></td><td>' . esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ) . '</td><td>' . esc_html( $payment ) . '</td><td><span class="ge-admin-status ge-status-' . esc_attr( sanitize_html_class( $order->get_status() ) ) . '">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span></td><td><strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></td><td><a href="' . esc_url( $url ) . '">Ver →</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function order_detail( $order ) {
        $documents = GE_WTP_Documents::get_documents( $order->get_id() );
        $is_markcom = 'yes' === $order->get_meta( '_ge_markcom_order' );
        $available_statuses = $is_markcom ? GE_WTP_Plugin::order_status_labels() : array_combine( array_map( function( $key ) { return str_replace( 'wc-', '', $key ); }, array_keys( wc_get_order_statuses() ) ), array_values( wc_get_order_statuses() ) );
        ?>
        <a class="ge-admin-back" href="<?php echo esc_url( admin_url( 'admin.php?page=ge-backoffice-orders' ) ); ?>">← Volver a pedidos</a>
        <div class="ge-admin-order-hero"><div><span>Orden</span><h2><?php echo esc_html( class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id() ); ?></h2><p><?php echo esc_html( $order->get_billing_email() ?: $order->get_billing_phone() ); ?> · <?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ); ?></p></div><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></div>
        <div class="ge-admin-order-grid">
            <section class="ge-admin-panel ge-admin-panel-wide"><div class="ge-admin-panel-head"><div><span>Contenido</span><h2>Productos solicitados</h2></div><span class="ge-admin-origin <?php echo $is_markcom ? 'is-markcom' : 'is-store'; ?>"><?php echo esc_html( $is_markcom ? 'Portal Markcom' : 'Tienda online' ); ?></span></div></div><div class="ge-admin-items"><?php foreach ( $order->get_items() as $item ) : ?><div><span><strong><?php echo esc_html( $item->get_name() ); ?></strong><small><?php echo esc_html( number_format_i18n( $item->get_quantity() ) ); ?> unidades</small></span><b><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></b></div><?php endforeach; ?></div><div class="ge-admin-meta"><?php if ( $is_markcom ) : ?><div><small>PO</small><strong><?php echo esc_html( $order->get_meta( '_ge_markcom_po_reference' ) ?: 'Pendiente' ); ?></strong></div><div><small>Cotización</small><strong><?php echo esc_html( number_format_i18n( (float) $order->get_meta( '_ge_markcom_exchange_rate' ), 2 ) ); ?> ARS/USD</strong></div><?php else : ?><div><small>Cliente</small><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ); ?></strong></div><div><small>Entrega</small><strong><?php echo esc_html( $order->get_shipping_method() ?: 'A coordinar' ); ?></strong></div><?php endif; ?><div><small>Pago</small><strong><?php echo esc_html( $order->get_payment_method_title() ?: ( $is_markcom ? 'Cuenta corriente a 30 días' : 'Sin definir' ) ); ?></strong></div></div><?php if ( $order->get_meta( '_ge_markcom_notes' ) || $order->get_customer_note() ) : ?><div class="ge-admin-note"><small>Observaciones</small><p><?php echo nl2br( esc_html( $order->get_meta( '_ge_markcom_notes' ) ?: $order->get_customer_note() ) ); ?></p></div><?php endif; ?></section>
            <aside class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Seguimiento</span><h2>Estado del pedido</h2></div></div><form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_order_status"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_backoffice_order_status_' . $order->get_id() ); ?><label>Etapa<select name="status"><?php foreach ( $available_statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order->get_status(), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>Fecha estimada<input type="date" name="estimated_date" value="<?php echo esc_attr( $order->get_meta( '_ge_estimated_date' ) ); ?>"></label><label>Nota interna<textarea name="status_note" rows="3" placeholder="Detalle opcional"></textarea></label><button class="button button-primary" type="submit">Actualizar pedido</button></form></aside>
        </div>
        <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Archivo compartido</span><h2>Documentos</h2></div><strong><?php echo esc_html( count( $documents ) ); ?> archivos</strong></div><div class="ge-admin-document-grid"><div><?php if ( ! $documents ) : ?><div class="ge-admin-empty"><span>No hay documentos cargados.</span></div><?php else : foreach ( $documents as $document ) : ?><a class="ge-admin-document" href="<?php echo esc_url( GE_WTP_Documents::download_url( $order->get_id(), $document['id'] ) ); ?>"><b>↓</b><span><strong><?php echo esc_html( $document['name'] ); ?></strong><small><?php echo esc_html( GE_WTP_Documents::categories()[ $document['category'] ] ?? 'Documento' ); ?> · <?php echo esc_html( size_format( $document['size'] ) ); ?></small></span></a><?php endforeach; endif; ?></div><form class="ge-admin-form ge-admin-upload" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_order_document"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_backoffice_order_document_' . $order->get_id() ); ?><label>Tipo<select name="category"><?php foreach ( GE_WTP_Documents::categories() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>Archivo<input type="file" name="ge_documents[]" accept=".pdf,.jpg,.jpeg,.png,.zip" multiple required></label><button class="button button-primary" type="submit">Cargar documento</button></form></div></section>
        <?php
    }

    public static function render_candidates() {
        self::guard(); $candidates = GE_WTP_Jobs::get_candidates();
        ?>
        <div class="wrap ge-admin-wrap"><?php self::page_header( 'Personas', 'Base de talentos', 'Perfiles recibidos desde “Trabajá con nosotros”.' ); self::notice(); ?><section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span><?php echo esc_html( count( $candidates ) ); ?> perfiles</span><h2>Candidatos</h2></div><a class="button" target="_blank" href="<?php echo esc_url( GE_WTP_Jobs::page_url() ); ?>">Ver formulario ↗</a></div>
        <?php if ( ! $candidates ) : ?><div class="ge-admin-empty"><strong>Todavía no hay postulaciones.</strong><span>Los perfiles enviados desde la web aparecerán acá.</span></div><?php else : ?><div class="ge-admin-candidates"><?php foreach ( $candidates as $candidate ) : $status = get_post_meta( $candidate->ID, '_ge_candidate_status', true ) ?: 'nuevo'; ?><article><div class="ge-candidate-main"><span class="ge-admin-status ge-candidate-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span><h3><?php echo esc_html( $candidate->post_title ); ?></h3><p><?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_area', true ) ); ?> · <?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_city', true ) ); ?></p><div class="ge-candidate-links"><a href="mailto:<?php echo esc_attr( get_post_meta( $candidate->ID, '_ge_candidate_email', true ) ); ?>"><?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_email', true ) ); ?></a><a href="tel:<?php echo esc_attr( get_post_meta( $candidate->ID, '_ge_candidate_phone', true ) ); ?>"><?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_phone', true ) ); ?></a><a target="_blank" rel="noopener" href="<?php echo esc_url( get_post_meta( $candidate->ID, '_ge_candidate_linkedin', true ) ); ?>">LinkedIn ↗</a></div><?php if ( $candidate->post_content ) : ?><blockquote><?php echo nl2br( esc_html( $candidate->post_content ) ); ?></blockquote><?php endif; ?></div><form class="ge-admin-form ge-candidate-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_candidate_status"><input type="hidden" name="candidate_id" value="<?php echo esc_attr( $candidate->ID ); ?>"><?php wp_nonce_field( 'ge_backoffice_candidate_status_' . $candidate->ID ); ?><label>Seguimiento<select name="candidate_status"><option value="nuevo" <?php selected( $status, 'nuevo' ); ?>>Nuevo</option><option value="contactado" <?php selected( $status, 'contactado' ); ?>>Contactado</option><option value="entrevista" <?php selected( $status, 'entrevista' ); ?>>Entrevista</option><option value="archivado" <?php selected( $status, 'archivado' ); ?>>Archivado</option></select></label><small>Recibido <?php echo esc_html( get_the_date( 'd/m/Y H:i', $candidate ) ); ?></small><button class="button" type="submit">Guardar</button></form></article><?php endforeach; ?></div><?php endif; ?></section></div>
        <?php
    }

    public static function handle_order_status() {
        self::guard(); $id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; check_admin_referer( 'ge_backoffice_order_status_' . $id ); $order = wc_get_order( $id ); $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
        $valid_statuses = array_map( function( $key ) { return str_replace( 'wc-', '', $key ); }, array_keys( wc_get_order_statuses() ) );
        if ( ! $order || ! in_array( $status, $valid_statuses, true ) ) { wp_die( 'Pedido o estado inválido.' ); }
        $estimated = isset( $_POST['estimated_date'] ) ? sanitize_text_field( wp_unslash( $_POST['estimated_date'] ) ) : ''; if ( $estimated && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $estimated ) ) { wp_die( 'La fecha estimada no es válida.' ); } $order->update_meta_data( '_ge_estimated_date', $estimated ); $note = isset( $_POST['status_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['status_note'] ) ) : ''; $order->set_status( $status, $note ?: 'Estado actualizado desde Graph Express.' ); $order->save(); self::redirect_order( $id );
    }

    public static function handle_order_document() {
        self::guard(); $id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; check_admin_referer( 'ge_backoffice_order_document_' . $id ); if ( ! wc_get_order( $id ) ) { wp_die( 'Pedido inválido.' ); } $category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : 'otro'; GE_WTP_Documents::handle_uploaded_files( $id, 'ge_documents', $category ); self::redirect_order( $id );
    }

    private static function redirect_order( $id ) { $staff = isset( $_POST['return_to'] ) && 'staff' === sanitize_key( wp_unslash( $_POST['return_to'] ) ); $url = $staff && class_exists( 'GE_WTP_Staff_Portal' ) ? GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $id, 'ge_saved' => 1 ) ) : admin_url( 'admin.php?page=ge-backoffice-orders&order_id=' . $id . '&ge_saved=1' ); wp_safe_redirect( $url ); exit; }

    public static function handle_candidate_status() {
        self::guard(); $id = isset( $_POST['candidate_id'] ) ? absint( $_POST['candidate_id'] ) : 0; check_admin_referer( 'ge_backoffice_candidate_status_' . $id ); $status = isset( $_POST['candidate_status'] ) ? sanitize_key( wp_unslash( $_POST['candidate_status'] ) ) : 'nuevo'; if ( GE_WTP_Jobs::POST_TYPE === get_post_type( $id ) && in_array( $status, array( 'nuevo', 'contactado', 'entrevista', 'archivado' ), true ) ) { update_post_meta( $id, '_ge_candidate_status', $status ); } $staff = isset( $_POST['return_to'] ) && 'staff' === sanitize_key( wp_unslash( $_POST['return_to'] ) ); wp_safe_redirect( $staff && class_exists( 'GE_WTP_Staff_Portal' ) ? GE_WTP_Staff_Portal::portal_url( 'candidates', array( 'ge_saved' => 1 ) ) : admin_url( 'admin.php?page=ge-backoffice-candidates&ge_saved=1' ) ); exit;
    }
}
