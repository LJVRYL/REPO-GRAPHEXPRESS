<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Etapa 1 del circuito de facturas de proveedores.
 *
 * El original queda en una bóveda privada y nunca se publica. La copia
 * derivada requiere dos aprobaciones humanas independientes antes de poder
 * adjuntarse al pedido del cliente. Las notificaciones sólo se preparan como
 * borrador: esta clase no llama a wp_mail().
 */
final class GE_WTP_Supplier_Invoices {
    const POST_TYPE = 'ge_supplier_invoice';
    const META = '_ge_si_record';
    const EVENTS_META = '_ge_si_events';
    const AUTO_SEND = false;
    const MAX_FILE_SIZE = 50;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ), 6 );
        add_action( 'admin_post_ge_si_receive', array( __CLASS__, 'handle_receive' ) );
        add_action( 'admin_post_ge_si_update', array( __CLASS__, 'handle_update' ) );
        add_action( 'admin_post_ge_si_approve_association', array( __CLASS__, 'handle_approve_association' ) );
        add_action( 'admin_post_ge_si_generate_copy', array( __CLASS__, 'handle_generate_copy' ) );
        add_action( 'admin_post_ge_si_upload_copy', array( __CLASS__, 'handle_upload_copy' ) );
        add_action( 'admin_post_ge_si_approve_copy', array( __CLASS__, 'handle_approve_copy' ) );
        add_action( 'admin_post_ge_si_publish', array( __CLASS__, 'handle_publish' ) );
        add_action( 'admin_post_ge_si_revoke', array( __CLASS__, 'handle_revoke' ) );
        add_action( 'admin_post_ge_si_revoke', array( __CLASS__, 'handle_revoke' ) );
        add_action( 'admin_post_ge_si_private_file', array( __CLASS__, 'handle_private_file' ) );
    }

    public static function register_post_type() {
        register_post_type(
            self::POST_TYPE,
            array(
                'label' => 'Facturas de proveedores',
                'public' => false,
                'show_ui' => false,
                'supports' => array( 'title' ),
                'capability_type' => 'post',
            )
        );
        self::ensure_private_directories();
    }

    public static function render() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) {
            wp_die( 'Acceso denegado.', 403 );
        }
        wp_enqueue_style( 'ge-supplier-invoices', GE_WTP_PLUGIN_URL . 'assets/css/supplier-invoices.css', array( 'ge-staff-portal' ), GE_WTP_VERSION );
        $invoice_id = isset( $_GET['invoice_id'] ) ? absint( $_GET['invoice_id'] ) : 0;
        if ( $invoice_id ) {
            self::render_detail( $invoice_id );
            return;
        }
        if ( isset( $_GET['view'] ) && 'new' === sanitize_key( wp_unslash( $_GET['view'] ) ) ) {
            self::render_receive_form();
            return;
        }
        self::render_inbox();
    }

    private static function render_inbox() {
        $records = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );
        ?>
        <div class="ge-staff-heading"><div><span>PROVEEDORES</span><h1>Facturas recibidas</h1><p>Originales privados, conciliación y copias controladas para el portal.</p></div><a class="ge-staff-button" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'supplier-invoices', array( 'view' => 'new' ) ) ); ?>">＋ Cargar factura</a></div>
        <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>BANDEJA</span><h2>Documentos en proceso</h2></div><strong><?php echo esc_html( count( $records ) ); ?></strong></div>
        <?php if ( ! $records ) : ?><div class="ge-admin-empty">Todavía no hay facturas de proveedores registradas.</div><?php else : ?><div class="ge-si-list">
            <?php foreach ( $records as $post ) : $record = self::record( $post->ID ); ?>
                <a href="<?php echo esc_url( self::url( $post->ID ) ); ?>"><span class="ge-si-state is-<?php echo esc_attr( $record['status'] ); ?>"><?php echo esc_html( self::status_label( $record['status'] ) ); ?></span><strong><?php echo esc_html( $record['supplier_name'] ?: 'Proveedor sin identificar' ); ?></strong><small><?php echo esc_html( $record['original_name'] ); ?></small><em><?php echo esc_html( $record['invoice_number'] ?: 'Sin número extraído' ); ?> · <?php echo esc_html( $record['received_at'] ); ?></em></a>
            <?php endforeach; ?>
        </div><?php endif; ?></section>
        <?php
    }

    private static function render_receive_form() {
        ?>
        <div class="ge-staff-heading"><div><span>NUEVO INGRESO</span><h1>Registrar factura</h1><p>La carga nunca publica ni envía el documento.</p></div><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'supplier-invoices' ) ); ?>">← Volver</a></div>
        <section class="ge-admin-panel ge-si-form-panel"><form class="ge-admin-form ge-si-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ge_si_receive"><?php wp_nonce_field( 'ge_si_receive' ); ?>
            <label>Proveedor<select name="supplier" required><option value="mardones">Mardones</option><option value="druck">Druck</option><option value="bandurria">Bandurria</option><option value="otro">Otro / identificar</option></select></label>
            <label>Canal de recepción<select name="channel" required><option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="portal">Portal</option><option value="otro">Otro</option></select></label>
            <label>Fecha y hora recibida<input type="datetime-local" name="received_at" value="<?php echo esc_attr( current_time( 'Y-m-d\TH:i' ) ); ?>" required></label>
            <label>Pedido probable<input type="number" name="probable_order_id" min="1" placeholder="ID interno, por ejemplo 37"></label>
            <label class="is-wide">Archivo original privado<input type="file" name="supplier_invoice" accept=".pdf,.jpg,.jpeg,.png" required><small>PDF, JPG o PNG · máximo <?php echo esc_html( self::MAX_FILE_SIZE ); ?> MB.</small></label>
            <label class="is-wide">Notas de recepción<textarea name="notes" rows="4" placeholder="Quién la envió, a qué trabajo parece corresponder y cualquier aclaración."></textarea></label>
            <div class="ge-si-warning is-wide"><strong>El original no se comparte.</strong><span>Primero se valida, se concilia y se genera una copia derivada.</span></div>
            <button class="ge-staff-button is-wide" type="submit">Guardar en bandeja privada</button>
        </form></section>
        <?php
    }

    private static function render_detail( $invoice_id ) {
        $post = get_post( $invoice_id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) { wp_die( 'Factura inexistente.', 404 ); }
        $record = self::record( $invoice_id );
        $issues = self::issues( $record );
        $events = get_post_meta( $invoice_id, self::EVENTS_META, true );
        $events = is_array( $events ) ? array_reverse( $events ) : array();
        $order = $record['order_id'] && function_exists( 'wc_get_order' ) ? wc_get_order( $record['order_id'] ) : false;
        ?>
        <div class="ge-staff-heading"><div><span>EXPEDIENTE <?php echo esc_html( '#' . $invoice_id ); ?></span><h1><?php echo esc_html( $record['supplier_name'] ?: 'Factura de proveedor' ); ?></h1><p><?php echo esc_html( $record['original_name'] ); ?></p></div><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'supplier-invoices' ) ); ?>">← Volver</a></div>
        <?php self::notice(); ?>
        <div class="ge-si-summary"><div><small>Estado</small><strong><?php echo esc_html( self::status_label( $record['status'] ) ); ?></strong></div><div><small>Original</small><strong><?php echo esc_html( substr( $record['original_hash'], 0, 12 ) ); ?>…</strong></div><div><small>Copia</small><strong><?php echo $record['derived_hash'] ? esc_html( substr( $record['derived_hash'], 0, 12 ) . '…' ) : 'Pendiente'; ?></strong></div><div><small>Envío automático</small><strong>Deshabilitado</strong></div></div>
        <?php if ( $issues ) : ?><section class="ge-si-issues"><strong>Revisión obligatoria</strong><ul><?php foreach ( $issues as $issue ) : ?><li><?php echo esc_html( $issue ); ?></li><?php endforeach; ?></ul></section><?php endif; ?>
        <div class="ge-si-grid">
            <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>ORIGINAL PRIVADO</span><h2>Recepción</h2></div></div><dl class="ge-si-data"><dt>Proveedor</dt><dd><?php echo esc_html( $record['supplier_name'] ); ?></dd><dt>Canal</dt><dd><?php echo esc_html( ucfirst( $record['channel'] ) ); ?></dd><dt>Recibida</dt><dd><?php echo esc_html( $record['received_at'] ); ?></dd><dt>Operador</dt><dd><?php echo esc_html( get_the_author_meta( 'display_name', $record['operator_id'] ) ); ?></dd></dl><a class="ge-staff-button ge-si-private" href="<?php echo esc_url( self::private_file_url( $invoice_id, 'original' ) ); ?>" target="_blank" rel="noopener">Ver original privado</a></section>
            <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>ASOCIACIÓN</span><h2>Pedido propuesto</h2></div></div><?php if ( $order ) : ?><p><strong><?php echo esc_html( $order->get_order_number() ); ?></strong><br><?php echo esc_html( $order->get_billing_email() ); ?><br><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) ); ?>">Abrir pedido →</a><?php else : ?><p>No hay un pedido confirmado.</p><?php endif; ?><p class="ge-si-approval"><?php echo $record['association_approved_at'] ? '✓ Asociación aprobada ' . esc_html( $record['association_approved_at'] ) : 'Pendiente de aprobación'; ?></p></section>
        </div>
        <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>LECTURA ASISTIDA</span><h2>Datos fiscales y conciliación</h2></div><strong><?php echo esc_html( strtoupper( $record['confidence'] ) ); ?></strong></div>
            <form class="ge-admin-form ge-si-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_si_update"><input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice_id ); ?>"><?php wp_nonce_field( 'ge_si_update_' . $invoice_id ); ?>
                <?php foreach ( self::field_labels() as $key => $label ) : ?><label><?php echo esc_html( $label ); ?><input type="text" name="fields[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $record[ $key ] ); ?>"></label><?php endforeach; ?>
                <label>Pedido asociado<input type="number" name="order_id" min="1" value="<?php echo esc_attr( $record['order_id'] ); ?>"></label>
                <label>Neto esperado del proveedor<input type="text" inputmode="decimal" name="expected_supplier_net" value="<?php echo esc_attr( $record['expected_supplier_net'] ?? '' ); ?>" placeholder="Orden al proveedor sin IVA"></label>
                <label>Total esperado del proveedor<input type="text" inputmode="decimal" name="expected_supplier_total" value="<?php echo esc_attr( $record['expected_supplier_total'] ?? '' ); ?>" placeholder="Total de la orden al proveedor"></label>
                <label class="is-wide">Notas internas<textarea name="notes" rows="3"><?php echo esc_textarea( $record['notes'] ); ?></textarea></label>
                <button class="ge-staff-button is-wide" type="submit">Guardar lectura y recalcular</button>
            </form>
            <?php if ( $order ) : $comparison = self::reconcile( $record, array( 'net' => $record['expected_supplier_net'], 'total' => $record['expected_supplier_total'] ) ); $sale = self::order_snapshot( $order ); ?><div class="ge-si-compare"><div><small>Neto factura</small><strong><?php echo esc_html( self::money( $record['net_amount'] ) ); ?></strong><small>Neto esperado proveedor</small><strong><?php echo esc_html( self::money( $comparison['order_net'] ) ); ?></strong></div><div><small>Total factura</small><strong><?php echo esc_html( self::money( $record['total_amount'] ) ); ?></strong><small>Total esperado proveedor</small><strong><?php echo esc_html( self::money( $comparison['order_total'] ) ); ?></strong></div><div class="<?php echo $comparison['matches'] ? 'is-ok' : 'is-blocked'; ?>"><strong><?php echo $comparison['matches'] ? 'Costo conciliado' : 'Diferencia bloqueante'; ?></strong><small><?php echo esc_html( implode( ' · ', $comparison['messages'] ) ); ?></small></div><div><small>Venta neta al cliente</small><strong><?php echo esc_html( self::money( $sale['net'] ) ); ?></strong><small>Venta total al cliente</small><strong><?php echo esc_html( self::money( $sale['total'] ) ); ?></strong></div></div><?php endif; ?>
        </section>
        <div class="ge-si-grid">
            <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>PASO 1</span><h2>Aprobar asociación</h2></div></div><p>Confirma que esta factura corresponde al pedido indicado. No publica archivos.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_si_approve_association"><input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice_id ); ?>"><?php wp_nonce_field( 'ge_si_approve_association_' . $invoice_id ); ?><button class="ge-staff-button" type="submit" <?php disabled( ! empty( $issues ) || ! $order ); ?>>Aprobar asociación</button></form></section>
            <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>PASO 2</span><h2>Copia segura</h2></div></div><?php if ( $record['derived_name'] ) : ?><iframe class="ge-si-preview" title="Vista previa de copia" src="<?php echo esc_url( self::private_file_url( $invoice_id, 'derived', true ) ); ?>"></iframe><a href="<?php echo esc_url( self::private_file_url( $invoice_id, 'derived' ) ); ?>">Abrir copia →</a><?php else : ?><p>Todavía no existe una copia derivada.</p><?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_si_generate_copy"><input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice_id ); ?>"><?php wp_nonce_field( 'ge_si_generate_copy_' . $invoice_id ); ?><button class="ge-staff-button" type="submit">Generar con perfil del proveedor</button></form>
                <form class="ge-admin-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_si_upload_copy"><input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice_id ); ?>"><?php wp_nonce_field( 'ge_si_upload_copy_' . $invoice_id ); ?><label>Copia preparada externamente<input type="file" name="derived_invoice" accept=".pdf,.jpg,.jpeg,.png" required></label><button class="ge-staff-button" type="submit">Cargar para revisar</button></form>
                <p class="ge-si-approval"><?php echo $record['copy_approved_at'] ? '✓ Copia aprobada ' . esc_html( $record['copy_approved_at'] ) : 'La vista previa debe aprobarse manualmente.'; ?></p>
                <?php if ( $record['derived_name'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_si_approve_copy"><input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice_id ); ?>"><?php wp_nonce_field( 'ge_si_approve_copy_' . $invoice_id ); ?><label class="ge-si-check"><input type="checkbox" name="visual_checked" value="1" required> Revisé todas las páginas y ningún dato fiscal quedó cubierto.</label><button class="ge-staff-button" type="submit">Aprobar copia</button></form><?php endif; ?>
            </section>
        </div>
        <section class="ge-admin-panel ge-si-publish"><div><span>PUBLICACIÓN CONTROLADA</span><h2>Portal del cliente</h2><p>Sólo se adjunta la copia aprobada. El original no tiene una ruta pública.</p></div><?php if ( $record['published_at'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_si_revoke"><input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice_id ); ?>"><?php wp_nonce_field( 'ge_si_revoke_' . $invoice_id ); ?><button class="ge-staff-button" type="submit">Retirar copia del portal</button></form><?php else : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_si_publish"><input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice_id ); ?>"><?php wp_nonce_field( 'ge_si_publish_' . $invoice_id ); ?><button class="ge-staff-button" type="submit" <?php disabled( ! self::ready_to_publish( $record ) ); ?>>Publicar copia en el pedido</button></form><?php endif; ?><div class="ge-si-draft"><strong>Borrador de aviso</strong><p><?php echo nl2br( esc_html( $record['notification_draft'] ) ); ?></p><small>No se envía automáticamente.</small></div></section>
        <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>AUDITORÍA</span><h2>Eventos inmutables</h2></div><strong><?php echo esc_html( count( $events ) ); ?></strong></div><ol class="ge-si-events"><?php foreach ( $events as $event ) : ?><li><strong><?php echo esc_html( $event['type'] ); ?></strong><span><?php echo esc_html( $event['at'] ); ?> · usuario <?php echo esc_html( $event['user_id'] ); ?></span><small><?php echo esc_html( $event['summary'] ); ?></small></li><?php endforeach; ?></ol></section>
        <?php
    }

    private static function notice() {
        $code = isset( $_GET['ge_si_notice'] ) ? sanitize_key( wp_unslash( $_GET['ge_si_notice'] ) ) : '';
        $labels = array( 'saved' => 'Datos guardados.', 'received' => 'Original guardado en la bóveda privada.', 'associated' => 'Asociación aprobada.', 'copy-ready' => 'Copia generada; falta revisión visual.', 'copy-approved' => 'Copia aprobada.', 'published' => 'Copia publicada en el pedido.', 'revoked' => 'La copia fue retirada del portal y la evidencia quedó preservada.', 'duplicate' => 'El archivo ya estaba registrado.', 'blocked' => 'La acción quedó bloqueada por controles pendientes.', 'copy-error' => 'No se pudo generar automáticamente. Podés cargar una copia preparada para revisión.' );
        if ( $code && isset( $labels[ $code ] ) ) { echo '<div class="ge-si-notice">' . esc_html( $labels[ $code ] ) . '</div>'; }
    }

    public static function handle_receive() {
        self::require_access(); check_admin_referer( 'ge_si_receive' );
        if ( empty( $_FILES['supplier_invoice'] ) ) { self::fail( 'Falta el archivo.' ); }
        $file = $_FILES['supplier_invoice'];
        if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > self::MAX_FILE_SIZE * MB_IN_BYTES ) { self::fail( 'Archivo inválido o demasiado grande.' ); }
        $name = sanitize_file_name( wp_basename( $file['name'] ) );
        $allowed = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' );
        $check = wp_check_filetype_and_ext( $file['tmp_name'], $name, $allowed );
        $ext = strtolower( (string) ( $check['ext'] ?? '' ) );
        if ( ! isset( $allowed[ $ext ] ) ) { self::fail( 'Formato no permitido.' ); }
        $hash = hash_file( 'sha256', $file['tmp_name'] );
        $duplicate = self::find_duplicate_hash( $hash );
        if ( $duplicate ) { self::redirect( $duplicate, 'duplicate' ); }
        if ( ! self::ensure_private_directories() ) { self::fail( 'No se pudo preparar la bóveda privada.' ); }
        $stored = wp_generate_uuid4() . '.' . $ext;
        $destination = trailingslashit( self::originals_directory() ) . $stored;
        if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) { self::fail( 'No se pudo guardar el original.' ); }
        @chmod( $destination, 0600 );
        $supplier = sanitize_key( wp_unslash( $_POST['supplier'] ?? 'otro' ) );
        $suppliers = self::suppliers();
        $record = self::defaults();
        $record['supplier'] = isset( $suppliers[ $supplier ] ) ? $supplier : 'otro';
        $record['supplier_name'] = $suppliers[ $record['supplier'] ]['name'];
        $record['channel'] = sanitize_key( wp_unslash( $_POST['channel'] ?? 'otro' ) );
        $record['received_at'] = sanitize_text_field( wp_unslash( $_POST['received_at'] ?? current_time( 'mysql' ) ) );
        $record['operator_id'] = get_current_user_id();
        $record['probable_order_id'] = absint( $_POST['probable_order_id'] ?? 0 );
        $record['order_id'] = $record['probable_order_id'];
        $record['notes'] = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $record['original_name'] = $name; $record['original_stored_name'] = $stored; $record['original_mime'] = $allowed[ $ext ]; $record['original_size'] = (int) $file['size']; $record['original_hash'] = $hash;
        $extracted = self::extract_fields( $destination, $record['original_mime'] );
        $record = array_merge( $record, $extracted );
        $post_id = wp_insert_post( array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'post_title' => $record['supplier_name'] . ' · ' . $name, 'post_author' => get_current_user_id() ), true );
        if ( is_wp_error( $post_id ) ) { @unlink( $destination ); self::fail( 'No se pudo crear el expediente.' ); }
        update_post_meta( $post_id, self::META, $record );
        self::event( $post_id, 'received', 'Original privado registrado por ' . $record['channel'] . '; hash ' . substr( $hash, 0, 12 ) . '…' );
        self::redirect( $post_id, 'received' );
    }

    public static function handle_update() {
        self::require_access(); $id = absint( $_POST['invoice_id'] ?? 0 ); check_admin_referer( 'ge_si_update_' . $id );
        $record = self::record( $id ); if ( ! $record['original_hash'] ) { self::fail( 'Expediente inválido.' ); }
        $old_identity = self::invoice_identity( $record );
        foreach ( self::field_labels() as $key => $label ) { $record[ $key ] = sanitize_text_field( wp_unslash( $_POST['fields'][ $key ] ?? '' ) ); }
        $record['order_id'] = absint( $_POST['order_id'] ?? 0 );
        $record['expected_supplier_net'] = sanitize_text_field( wp_unslash( $_POST['expected_supplier_net'] ?? '' ) );
        $record['expected_supplier_total'] = sanitize_text_field( wp_unslash( $_POST['expected_supplier_total'] ?? '' ) );
        $record['notes'] = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $record['confidence'] = self::required_fields_complete( $record ) ? 'manual-review' : 'low';
        $new_identity = self::invoice_identity( $record );
        if ( self::should_invalidate_approval( $old_identity, $new_identity ) ) { self::invalidate_approvals( $record ); }
        update_post_meta( $id, self::META, $record ); self::event( $id, 'fields_updated', 'Lectura corregida; las aprobaciones previas se invalidan si cambió la identidad fiscal.' ); self::redirect( $id, 'saved' );
    }

    public static function handle_approve_association() {
        self::require_access(); $id = absint( $_POST['invoice_id'] ?? 0 ); check_admin_referer( 'ge_si_approve_association_' . $id ); $record = self::record( $id );
        if ( self::issues( $record ) || ! $record['order_id'] || ! wc_get_order( $record['order_id'] ) ) { self::redirect( $id, 'blocked' ); }
        $record['association_approved_at'] = current_time( 'mysql' ); $record['association_approved_by'] = get_current_user_id(); $record['status'] = 'associated'; update_post_meta( $id, self::META, $record ); self::event( $id, 'association_approved', 'Pedido ' . absint( $record['order_id'] ) . ' confirmado.' ); self::redirect( $id, 'associated' );
    }

    public static function handle_generate_copy() {
        self::require_access(); $id = absint( $_POST['invoice_id'] ?? 0 ); check_admin_referer( 'ge_si_generate_copy_' . $id ); $record = self::record( $id );
        $profile = self::redaction_profile( $record['supplier'] );
        if ( ! $profile || ! self::redactions_are_safe( $profile['redactions'], $profile['protected'] ) ) { self::redirect( $id, 'blocked' ); }
        $source = self::original_path( $record );
        if ( ! is_file( $source ) || ! class_exists( 'Imagick' ) ) { self::event( $id, 'copy_generation_blocked', 'Motor de renderizado no disponible; se requiere copia preparada y revisión visual.' ); self::redirect( $id, 'copy-error' ); }
        try {
            $destination = trailingslashit( self::derived_directory() ) . wp_generate_uuid4() . '.pdf';
            $document = new Imagick(); $document->setResolution( 300, 300 ); $document->readImage( $source ); $output = new Imagick();
            foreach ( $document as $page ) { $page->setImageBackgroundColor( 'white' ); $page = $page->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN ); $draw = new ImagickDraw(); $draw->setFillColor( 'white' ); $width = $page->getImageWidth(); $height = $page->getImageHeight(); foreach ( $profile['redactions'] as $rect ) { $draw->rectangle( $rect[0] * $width, $rect[1] * $height, ( $rect[0] + $rect[2] ) * $width, ( $rect[1] + $rect[3] ) * $height ); } $page->drawImage( $draw ); $page->setImageFormat( 'pdf' ); $output->addImage( $page ); }
            $output->writeImages( $destination, true ); $document->clear(); $output->clear();
            self::set_derived( $id, $record, $destination, 'Factura-' . ( $record['invoice_number'] ?: $id ) . '-copia-segura.pdf' ); self::redirect( $id, 'copy-ready' );
        } catch ( Exception $error ) { self::event( $id, 'copy_generation_failed', 'La generación automática falló; no se publicó ningún archivo.' ); self::redirect( $id, 'copy-error' ); }
    }

    public static function handle_upload_copy() {
        self::require_access(); $id = absint( $_POST['invoice_id'] ?? 0 ); check_admin_referer( 'ge_si_upload_copy_' . $id ); $record = self::record( $id );
        if ( empty( $_FILES['derived_invoice'] ) ) { self::redirect( $id, 'blocked' ); }
        $file = $_FILES['derived_invoice']; $name = sanitize_file_name( wp_basename( $file['name'] ) ); $allowed = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' ); $check = wp_check_filetype_and_ext( $file['tmp_name'], $name, $allowed ); $ext = strtolower( (string) ( $check['ext'] ?? '' ) );
        if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! isset( $allowed[ $ext ] ) || (int) $file['size'] > self::MAX_FILE_SIZE * MB_IN_BYTES ) { self::redirect( $id, 'blocked' ); }
        $destination = trailingslashit( self::derived_directory() ) . wp_generate_uuid4() . '.' . $ext; if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) { self::redirect( $id, 'blocked' ); } @chmod( $destination, 0600 ); self::set_derived( $id, $record, $destination, $name ); self::redirect( $id, 'copy-ready' );
    }

    public static function handle_approve_copy() {
        self::require_access(); $id = absint( $_POST['invoice_id'] ?? 0 ); check_admin_referer( 'ge_si_approve_copy_' . $id ); $record = self::record( $id );
        if ( empty( $_POST['visual_checked'] ) || ! $record['derived_hash'] || ! is_file( self::derived_path( $record ) ) ) { self::redirect( $id, 'blocked' ); }
        $record['copy_approved_at'] = current_time( 'mysql' ); $record['copy_approved_by'] = get_current_user_id(); $record['copy_approved_hash'] = $record['derived_hash']; $record['status'] = 'copy-approved'; update_post_meta( $id, self::META, $record ); self::event( $id, 'copy_approved', 'Vista completa aprobada para el hash derivado ' . substr( $record['derived_hash'], 0, 12 ) . '…' ); self::redirect( $id, 'copy-approved' );
    }

    public static function handle_publish() {
        self::require_access(); $id = absint( $_POST['invoice_id'] ?? 0 ); check_admin_referer( 'ge_si_publish_' . $id ); $record = self::record( $id );
        if ( ! self::ready_to_publish( $record ) ) { self::redirect( $id, 'blocked' ); }
        $order = wc_get_order( $record['order_id'] ); if ( ! $order || ! GE_WTP_Documents::ensure_private_directory() ) { self::redirect( $id, 'blocked' ); }
        $source = self::derived_path( $record ); $extension = pathinfo( $record['derived_name'], PATHINFO_EXTENSION ) ?: 'pdf'; $stored = wp_generate_uuid4() . '.' . sanitize_key( $extension ); $destination = trailingslashit( GE_WTP_Documents::private_directory() ) . $stored;
        if ( ! copy( $source, $destination ) ) { self::redirect( $id, 'blocked' ); }
        $document_id = wp_generate_uuid4(); $documents = GE_WTP_Documents::get_documents( $order->get_id() ); $documents[] = array( 'id' => $document_id, 'stored_name' => $stored, 'name' => $record['derived_name'], 'mime' => $record['derived_mime'], 'size' => filesize( $destination ), 'category' => 'factura', 'uploaded_by' => get_current_user_id(), 'uploaded_at' => current_time( 'mysql' ), 'analysis' => GE_WTP_Documents::analyze_file( $destination, $record['derived_mime'] ) ); $order->update_meta_data( GE_WTP_Documents::META_KEY, $documents ); $order->save();
        $record['published_at'] = current_time( 'mysql' ); $record['published_by'] = get_current_user_id(); $record['published_order_id'] = $order->get_id(); $record['published_document_id'] = $document_id; $record['published_stored_name'] = $stored; $record['status'] = 'published'; update_post_meta( $id, self::META, $record ); self::event( $id, 'published', 'Copia derivada publicada en el pedido ' . $order->get_id() . '; el original permaneció privado.' ); self::redirect( $id, 'published' );
    }

    public static function handle_revoke() {
        self::require_access(); $id = absint( $_POST['invoice_id'] ?? 0 ); check_admin_referer( 'ge_si_revoke_' . $id ); $record = self::record( $id );
        $order = $record['published_order_id'] ? wc_get_order( $record['published_order_id'] ) : false;
        if ( ! $order || ! $record['published_document_id'] ) { self::redirect( $id, 'blocked' ); }
        $documents = GE_WTP_Documents::get_documents( $order->get_id() );
        $documents = array_values( array_filter( $documents, function( $document ) use ( $record ) { return ( $document['id'] ?? '' ) !== $record['published_document_id']; } ) );
        $order->update_meta_data( GE_WTP_Documents::META_KEY, $documents ); $order->save();
        $record['published_at'] = ''; $record['published_by'] = 0; $record['published_order_id'] = 0; $record['published_document_id'] = ''; $record['published_stored_name'] = ''; $record['status'] = 'copy-approved'; update_post_meta( $id, self::META, $record );
        self::event( $id, 'publication_revoked', 'La copia fue retirada del portal; el original, la copia derivada y la auditoría se conservaron.' ); self::redirect( $id, 'revoked' );
    }

    public static function handle_private_file() {
        self::require_access(); $id = absint( $_GET['invoice_id'] ?? 0 ); $kind = sanitize_key( wp_unslash( $_GET['kind'] ?? '' ) ); check_admin_referer( 'ge_si_private_file_' . $id . '_' . $kind ); $record = self::record( $id );
        $path = 'derived' === $kind ? self::derived_path( $record ) : self::original_path( $record ); $name = 'derived' === $kind ? $record['derived_name'] : $record['original_name']; $mime = 'derived' === $kind ? $record['derived_mime'] : $record['original_mime']; if ( ! $path || ! is_file( $path ) ) { wp_die( 'Archivo inexistente.', 404 ); }
        nocache_headers(); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: ' . $mime ); header( 'Content-Length: ' . filesize( $path ) ); header( 'Content-Disposition: ' . ( empty( $_GET['inline'] ) ? 'attachment' : 'inline' ) . '; filename="' . rawurlencode( $name ) . '"' ); readfile( $path ); exit;
    }

    private static function set_derived( $id, $record, $path, $name ) {
        if ( $record['derived_stored_name'] && is_file( self::derived_path( $record ) ) ) { /* Se preserva el archivo anterior; la auditoría mantiene su hash. */ }
        $record['derived_stored_name'] = wp_basename( $path ); $record['derived_name'] = sanitize_file_name( $name ); $record['derived_mime'] = self::mime_from_name( $name ); $record['derived_hash'] = hash_file( 'sha256', $path ); $record['derived_size'] = filesize( $path ); $record['copy_approved_at'] = ''; $record['copy_approved_by'] = 0; $record['copy_approved_hash'] = ''; $record['published_at'] = ''; $record['published_by'] = 0; $record['published_order_id'] = 0; $record['status'] = 'copy-review'; update_post_meta( $id, self::META, $record ); self::event( $id, 'derived_created', 'Nueva copia derivada; hash ' . substr( $record['derived_hash'], 0, 12 ) . '…; aprobaciones anteriores invalidadas.' );
    }

    public static function record( $id ) { $record = get_post_meta( $id, self::META, true ); $record = array_merge( array( 'expected_supplier_net' => '', 'expected_supplier_total' => '', 'published_document_id' => '', 'published_stored_name' => '' ), self::defaults(), is_array( $record ) ? $record : array() ); $record['record_id'] = absint( $id ); return $record; }
    public static function defaults() { return array_merge( array( 'record_id' => 0, 'supplier' => '', 'supplier_name' => '', 'channel' => '', 'received_at' => '', 'operator_id' => 0, 'probable_order_id' => 0, 'order_id' => 0, 'notes' => '', 'status' => 'received', 'confidence' => 'low', 'original_name' => '', 'original_stored_name' => '', 'original_mime' => '', 'original_size' => 0, 'original_hash' => '', 'derived_name' => '', 'derived_stored_name' => '', 'derived_mime' => '', 'derived_size' => 0, 'derived_hash' => '', 'association_approved_at' => '', 'association_approved_by' => 0, 'copy_approved_at' => '', 'copy_approved_by' => 0, 'copy_approved_hash' => '', 'published_at' => '', 'published_by' => 0, 'published_order_id' => 0, 'notification_draft' => "La factura correspondiente a tu pedido ya está disponible en el portal.\n\nEste mensaje es un borrador y no fue enviado." ), array_fill_keys( array_keys( self::field_labels() ), '' ) ); }
    public static function field_labels() { return array( 'invoice_type' => 'Tipo', 'point_of_sale' => 'Punto de venta', 'invoice_number' => 'Número', 'issue_date' => 'Fecha de emisión', 'supplier_tax_id' => 'CUIT emisor', 'supplier_legal_name' => 'Razón social emisora', 'customer_tax_id' => 'CUIT receptor', 'customer_name' => 'Receptor', 'concept' => 'Concepto', 'quantity' => 'Cantidad', 'net_amount' => 'Neto', 'vat_amount' => 'IVA', 'other_taxes' => 'Otros tributos', 'total_amount' => 'Total', 'currency' => 'Moneda', 'payment_terms' => 'Condición de pago', 'cae' => 'CAE', 'cae_expiry' => 'Vencimiento CAE', 'qr_value' => 'QR / referencia' ); }
    public static function suppliers() { return array( 'mardones' => array( 'name' => 'Mardones', 'tax_id' => '20948548934' ), 'druck' => array( 'name' => 'Druck', 'tax_id' => '' ), 'bandurria' => array( 'name' => 'Bandurria', 'tax_id' => '' ), 'otro' => array( 'name' => 'Otro / identificar', 'tax_id' => '' ) ); }

    public static function extract_fields( $path, $mime ) {
        $result = array_fill_keys( array_keys( self::field_labels() ), '' ); $result['currency'] = 'ARS'; $result['confidence'] = 'low';
        if ( 'application/pdf' === $mime ) { $raw = file_get_contents( $path, false, null, 0, 8 * MB_IN_BYTES ); if ( is_string( $raw ) ) { $text = preg_replace( '/[^\x20-\x7E\x{00A0}-\x{00FF}]+/u', ' ', $raw ); self::regex_field( $text, '/CUIT\D{0,12}([0-9]{2}[- ]?[0-9]{8}[- ]?[0-9])/i', $result, 'supplier_tax_id' ); self::regex_field( $text, '/CAE\D{0,15}([0-9]{10,16})/i', $result, 'cae' ); self::regex_field( $text, '/Factura\s+([ABC])/i', $result, 'invoice_type' ); } }
        if ( $result['supplier_tax_id'] || $result['cae'] ) { $result['confidence'] = 'basic'; }
        return $result;
    }

    private static function regex_field( $text, $pattern, &$result, $key ) { if ( preg_match( $pattern, $text, $matches ) ) { $result[ $key ] = sanitize_text_field( $matches[1] ); } }
    public static function invoice_identity( $record ) { $tax = preg_replace( '/\D+/', '', (string) ( $record['supplier_tax_id'] ?? '' ) ); $type = strtoupper( trim( (string) ( $record['invoice_type'] ?? '' ) ) ); $pos = ltrim( preg_replace( '/\D+/', '', (string) ( $record['point_of_sale'] ?? '' ) ), '0' ); $number = ltrim( preg_replace( '/\D+/', '', (string) ( $record['invoice_number'] ?? '' ) ), '0' ); return $tax && $type && $pos && $number ? implode( '|', array( $tax, $type, $pos, $number ) ) : ''; }
    public static function should_invalidate_approval( $old, $new ) { return (string) $old !== (string) $new; }
    public static function approval_valid_for_hash( $current_hash, $approved_hash ) { return $current_hash && $approved_hash && hash_equals( (string) $current_hash, (string) $approved_hash ); }
    public static function hash_is_duplicate( $hash, $existing_hashes ) { foreach ( (array) $existing_hashes as $existing ) { if ( $hash && $existing && hash_equals( (string) $hash, (string) $existing ) ) { return true; } } return false; }
    private static function invalidate_approvals( &$record ) { $record['association_approved_at'] = ''; $record['association_approved_by'] = 0; $record['copy_approved_at'] = ''; $record['copy_approved_by'] = 0; $record['copy_approved_hash'] = ''; $record['published_at'] = ''; $record['published_by'] = 0; $record['published_order_id'] = 0; $record['status'] = 'received'; }
    public static function choose_candidate( $scores, $minimum = 60 ) { arsort( $scores ); $ids = array_keys( $scores ); if ( ! $ids || reset( $scores ) < $minimum ) { return array( 'status' => 'none', 'order_id' => 0 ); } $top = reset( $scores ); $second = count( $scores ) > 1 ? array_values( $scores )[1] : -1; return $top === $second ? array( 'status' => 'ambiguous', 'order_id' => 0 ) : array( 'status' => 'proposed', 'order_id' => absint( $ids[0] ) ); }
    public static function reconcile( $invoice, $order ) { $invoice_net = self::decimal( $invoice['net_amount'] ?? 0 ); $invoice_total = self::decimal( $invoice['total_amount'] ?? 0 ); $order_net = self::decimal( $order['net'] ?? 0 ); $order_total = self::decimal( $order['total'] ?? 0 ); $messages = array(); if ( abs( $invoice_net - $order_net ) > 0.01 ) { $messages[] = 'Neto diferente'; } if ( abs( $invoice_total - $order_total ) > 0.01 ) { $messages[] = 'Total diferente'; } return array( 'matches' => ! $messages && $invoice_net > 0 && $invoice_total > 0, 'messages' => $messages ?: array( 'Neto y total coinciden' ), 'order_net' => $order_net, 'order_total' => $order_total ); }
    public static function redactions_are_safe( $redactions, $protected ) { foreach ( $redactions as $redaction ) { if ( count( $redaction ) !== 4 || $redaction[0] < 0 || $redaction[1] < 0 || $redaction[2] <= 0 || $redaction[3] <= 0 || $redaction[0] + $redaction[2] > 1 || $redaction[1] + $redaction[3] > 1 ) { return false; } foreach ( $protected as $field ) { if ( self::rectangles_overlap( $redaction, $field ) ) { return false; } } } return true; }
    private static function rectangles_overlap( $a, $b ) { return $a[0] < $b[0] + $b[2] && $a[0] + $a[2] > $b[0] && $a[1] < $b[1] + $b[3] && $a[1] + $a[3] > $b[1]; }
    public static function redaction_profile( $supplier ) { if ( 'mardones' !== $supplier ) { return array(); } return array( 'redactions' => array( array( .043, .033, .182, .086 ), array( .048, .132, .288, .012 ), array( .048, .152, .190, .012 ) ), 'protected' => array( array( .040, .120, .310, .012 ), array( .040, .145, .360, .009 ), array( .240, .151, .180, .014 ), array( .040, .166, .180, .012 ), array( .610, .100, .300, .090 ), array( .035, .190, .930, .790 ) ) ); }
    public static function original_is_publishable() { return false; }
    public static function auto_send_enabled() { return self::AUTO_SEND; }

    private static function issues( $record ) {
        $issues = array(); if ( ! self::required_fields_complete( $record ) ) { $issues[] = 'Faltan campos fiscales obligatorios o la lectura tiene baja confianza.'; }
        $identity = self::invoice_identity( $record ); if ( $identity && self::find_duplicate_identity( $identity, absint( $record['record_id'] ?? 0 ) ) ) { $issues[] = 'Existe otra factura con la misma identidad fiscal.'; }
        if ( ! $record['order_id'] || ! function_exists( 'wc_get_order' ) || ! wc_get_order( $record['order_id'] ) ) { $issues[] = 'No hay un pedido válido asociado.'; }
        elseif ( empty( $record['expected_supplier_net'] ) || empty( $record['expected_supplier_total'] ) ) { $issues[] = 'Falta cargar el costo esperado de la orden al proveedor.'; }
        else {
            $comparison = self::reconcile( $record, array( 'net' => $record['expected_supplier_net'], 'total' => $record['expected_supplier_total'] ) );
            if ( ! $comparison['matches'] ) { $issues[] = 'La factura no concilia exactamente con la orden al proveedor.'; }
            $sale = self::order_snapshot( wc_get_order( $record['order_id'] ) );
            if ( self::decimal( $record['net_amount'] ) > self::decimal( $sale['net'] ) ) { $issues[] = 'El costo neto supera la venta neta al cliente; requiere revisión gerencial.'; }
        }
        if ( $record['derived_hash'] && $record['copy_approved_hash'] && ! hash_equals( $record['derived_hash'], $record['copy_approved_hash'] ) ) { $issues[] = 'La copia cambió después de ser aprobada.'; }
        return $issues;
    }
    private static function required_fields_complete( $record ) { foreach ( array( 'invoice_type', 'point_of_sale', 'invoice_number', 'issue_date', 'supplier_tax_id', 'supplier_legal_name', 'customer_tax_id', 'customer_name', 'net_amount', 'vat_amount', 'total_amount', 'cae', 'cae_expiry' ) as $key ) { if ( '' === trim( (string) ( $record[ $key ] ?? '' ) ) ) { return false; } } return true; }
    private static function order_snapshot( $order ) { return array( 'net' => (float) $order->get_subtotal(), 'total' => (float) $order->get_total() ); }
    private static function ready_to_publish( $record ) { return $record['association_approved_at'] && $record['copy_approved_at'] && self::approval_valid_for_hash( $record['derived_hash'], $record['copy_approved_hash'] ) && $record['order_id'] && ! $record['published_at'] && ! self::issues( $record ); }
    private static function decimal( $value ) {
        if ( is_int( $value ) || is_float( $value ) ) { return round( (float) $value, 2 ); }
        $value = preg_replace( '/[^0-9,.-]/', '', (string) $value );
        $last_comma = strrpos( $value, ',' );
        $last_dot = strrpos( $value, '.' );
        if ( false !== $last_comma && false !== $last_dot ) {
            $decimal_separator = $last_comma > $last_dot ? ',' : '.';
            $thousands_separator = ',' === $decimal_separator ? '.' : ',';
            $value = str_replace( $thousands_separator, '', $value );
            $value = str_replace( $decimal_separator, '.', $value );
        } elseif ( false !== $last_comma ) {
            $value = str_replace( '.', '', $value );
            $value = str_replace( ',', '.', $value );
        } elseif ( false !== $last_dot && 1 !== substr_count( $value, '.' ) ) {
            $parts = explode( '.', $value );
            $decimal = array_pop( $parts );
            $value = implode( '', $parts ) . ( strlen( $decimal ) <= 2 ? '.' . $decimal : $decimal );
        }
        return round( (float) $value, 2 );
    }
    private static function money( $value ) { return '$ ' . number_format_i18n( self::decimal( $value ), 2 ); }
    private static function mime_from_name( $name ) { $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ); return 'pdf' === $ext ? 'application/pdf' : ( 'png' === $ext ? 'image/png' : 'image/jpeg' ); }

    private static function ensure_private_directories() { foreach ( array( self::base_directory(), self::originals_directory(), self::derived_directory() ) as $directory ) { if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) { return false; } $index = trailingslashit( $directory ) . 'index.php'; if ( ! file_exists( $index ) ) { file_put_contents( $index, "<?php\nhttp_response_code(404);\nexit;\n" ); } $htaccess = trailingslashit( $directory ) . '.htaccess'; if ( ! file_exists( $htaccess ) ) { file_put_contents( $htaccess, "Require all denied\nDeny from all\n" ); } } return is_writable( self::originals_directory() ) && is_writable( self::derived_directory() ); }
    private static function base_directory() { return WP_CONTENT_DIR . '/ge-private/supplier-invoices'; }
    private static function originals_directory() { return self::base_directory() . '/originals'; }
    private static function derived_directory() { return self::base_directory() . '/derived'; }
    private static function original_path( $record ) { return $record['original_stored_name'] ? trailingslashit( self::originals_directory() ) . wp_basename( $record['original_stored_name'] ) : ''; }
    private static function derived_path( $record ) { return $record['derived_stored_name'] ? trailingslashit( self::derived_directory() ) . wp_basename( $record['derived_stored_name'] ) : ''; }
    private static function private_file_url( $id, $kind, $inline = false ) { return wp_nonce_url( admin_url( 'admin-post.php?action=ge_si_private_file&invoice_id=' . absint( $id ) . '&kind=' . sanitize_key( $kind ) . ( $inline ? '&inline=1' : '' ) ), 'ge_si_private_file_' . absint( $id ) . '_' . sanitize_key( $kind ) ); }
    private static function url( $id, $notice = '' ) { $args = array( 'invoice_id' => absint( $id ) ); if ( $notice ) { $args['ge_si_notice'] = sanitize_key( $notice ); } return GE_WTP_Staff_Portal::portal_url( 'supplier-invoices', $args ); }
    private static function redirect( $id, $notice ) { wp_safe_redirect( self::url( $id, $notice ) ); exit; }
    private static function require_access() { if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); } }
    private static function fail( $message ) { wp_die( esc_html( $message ), 400 ); }
    private static function event( $id, $type, $summary ) { $events = get_post_meta( $id, self::EVENTS_META, true ); $events = is_array( $events ) ? $events : array(); $events[] = array( 'id' => wp_generate_uuid4(), 'type' => sanitize_key( $type ), 'at' => current_time( 'mysql' ), 'user_id' => get_current_user_id(), 'summary' => sanitize_text_field( $summary ) ); update_post_meta( $id, self::EVENTS_META, $events ); }
    private static function find_duplicate_hash( $hash ) {
        if ( ! $hash ) { return 0; }
        $posts = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 500, 'fields' => 'ids' ) );
        foreach ( $posts as $id ) {
            $record = self::record( $id );
            if ( self::hash_is_duplicate( $hash, array( $record['original_hash'] ) ) ) { return absint( $id ); }
        }
        return 0;
    }
    private static function find_duplicate_identity( $identity, $exclude_id ) { if ( ! $identity ) { return 0; } $posts = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 100, 'fields' => 'ids', 'post__not_in' => $exclude_id ? array( $exclude_id ) : array() ) ); foreach ( $posts as $id ) { if ( hash_equals( $identity, self::invoice_identity( self::record( $id ) ) ) ) { return absint( $id ); } } return 0; }
    private static function status_label( $status ) { $labels = array( 'received' => 'Recibida · sin validar', 'associated' => 'Asociación aprobada', 'copy-review' => 'Copia en revisión', 'copy-approved' => 'Copia aprobada', 'published' => 'Publicada' ); return $labels[ $status ] ?? ucfirst( $status ); }
}
