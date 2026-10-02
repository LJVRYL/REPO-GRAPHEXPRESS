<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Staff workflow for orders created after the staged-production rollout. */
final class GE_WTP_Workflow {
    const VERSION_META = '_ge_workflow_version';
    const STAGE_META = '_ge_workflow_stage';
    const ITEM_STATE_META = '_ge_item_prepress_state';
    const REQUESTS_META = '_ge_item_ai_requests';
    const FINISHES_META = '_ge_item_finishes';
    private static $artwork_rendered = false;

    public static function init() {
        add_action( 'admin_post_ge_workflow_review', array( __CLASS__, 'save_review' ) );
        add_action( 'admin_post_ge_workflow_ai_request', array( __CLASS__, 'request_ai' ) );
        add_action( 'admin_post_ge_workflow_request_approval', array( __CLASS__, 'request_approval' ) );
        add_action( 'admin_post_ge_workflow_customer_changes', array( __CLASS__, 'customer_changes' ) );
        add_action( 'admin_post_ge_workflow_release', array( __CLASS__, 'release' ) );
        add_action( 'admin_post_ge_workflow_finishes', array( __CLASS__, 'save_finishes' ) );
        add_action( 'admin_post_ge_workflow_notify_customer', array( __CLASS__, 'notify_customer' ) );
        add_action( 'admin_post_ge_workflow_adopt', array( __CLASS__, 'adopt_existing' ) );
    }

    public static function enabled( $order ) {
        return $order instanceof WC_Order && '1' === (string) $order->get_meta( self::VERSION_META, true );
    }

    public static function tracking_stage_locked( $order ) {
        if ( ! self::enabled( $order ) ) { return false; }
        foreach ( (array) $order->get_meta( '_ge_workflow_customer_notices', true ) as $notice ) {
            if ( ! empty( $notice['sent'] ) ) { return false; }
        }
        return true;
    }

    public static function can_adopt( $order ) {
        if ( ! $order instanceof WC_Order || self::enabled( $order ) || ! $order->get_meta( '_ge_manual_reference', true ) ) { return false; }
        if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'completed' ), true ) || in_array( $order->get_meta( '_ge_production_status', true ), array( 'production', 'ready', 'delivered' ), true ) ) { return false; }
        if ( $order->get_meta( '_ge_supplier_auto_dispatch_at', true ) ) { return false; }
        foreach ( (array) $order->get_meta( '_ge_supplier_dispatch_history', true ) as $entry ) { if ( ! empty( $entry['success'] ) ) { return false; } }
        foreach ( $order->get_items( 'line_item' ) as $item ) { if ( in_array( GE_WTP_Production::item_status( $item, $order ), array( 'production', 'ready', 'delivered' ), true ) ) { return false; } }
        return true;
    }

    public static function adopt_existing() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! self::can_adopt( $order ) ) { wp_die( 'Este pedido ya avanzó o no admite el cambio de circuito.', 409 ); }
        check_admin_referer( 'ge_workflow_adopt_' . $order->get_id() );
        $snapshot = array( 'time' => time(), 'production_status' => $order->get_meta( '_ge_production_status', true ), 'lifecycle_stage' => $order->get_meta( '_ge_lifecycle_stage', true ), 'items' => array() );
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) { $snapshot['items'][ $item_id ] = $item->get_meta( '_ge_item_status', true ); }
        $order->update_meta_data( '_ge_workflow_adoption_snapshot', $snapshot ); $order->save();
        self::enable( $order );
        $order->update_meta_data( '_ge_production_status', 'pending' ); $order->update_meta_data( '_ge_production_processes', array() ); $order->save();
        self::redirect( $order, 'review', 'saved' );
    }

    public static function enable( $order ) {
        if ( ! $order instanceof WC_Order || self::enabled( $order ) ) { return; }
        $order->update_meta_data( self::VERSION_META, '1' );
        $order->update_meta_data( self::STAGE_META, 'review' );
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $item->update_meta_data( '_ge_item_status', 'pending' );
            $item->update_meta_data( self::ITEM_STATE_META, 'received' );
            $item->save();
        }
        $order->save();
        if ( class_exists( 'GE_WTP_Order_Lifecycle' ) ) { GE_WTP_Order_Lifecycle::set_stage( $order, 'recibido' ); }
    }

    public static function step( $order ) {
        $requested = sanitize_key( wp_unslash( $_GET['step'] ?? '' ) );
        if ( in_array( $requested, array( 'review', 'prepress', 'production', 'supplier', 'customer' ), true ) ) { return $requested; }
        $stage = sanitize_key( $order->get_meta( self::STAGE_META, true ) );
        return in_array( $stage, array( 'review', 'prepress', 'production' ), true ) ? $stage : 'review';
    }

    private static function url( $order, $step, $args = array() ) {
        return GE_WTP_Staff_Portal::portal_url( 'production', array_merge( array( 'order_id' => $order->get_id(), 'step' => $step ), $args ) );
    }

    private static function reference( $order ) {
        return class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id();
    }

    private static function ready( $item, $order ) {
        return class_exists( 'GE_WTP_Artwork_Library' ) && GE_WTP_Artwork_Library::item_ready_for_production( $item, $order );
    }

    private static function released( $order ) {
        return 'production' === $order->get_meta( self::STAGE_META, true );
    }

    private static function all_items_released( $order ) {
        $items = $order->get_items( 'line_item' );
        if ( ! $items ) { return false; }
        foreach ( $items as $item ) {
            if ( 'production' !== GE_WTP_Production::item_status( $item, $order ) || ! self::ready( $item, $order ) ) { return false; }
        }
        return true;
    }

    public static function artwork_already_rendered() { return self::$artwork_rendered; }

    public static function render( $order ) {
        $step = self::step( $order );
        $reference = self::reference( $order );
        $labels = array( 'review' => 'Revisión y planificación', 'prepress' => 'Preproducción', 'production' => 'Producción', 'supplier' => 'Salida al proveedor', 'customer' => 'Aviso al cliente' );
        echo '<a class="ge-admin-back" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'production' ) ) . '">← Volver a trabajos</a>';
        echo '<div class="ge-production-hero"><div><span>Pedido ' . esc_html( $labels[ $step ] ) . '</span><h1>' . esc_html( $reference ) . '</h1><p>' . esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_company() ?: $order->get_billing_email() ) . '</p></div><b>' . esc_html( self::released( $order ) ? 'En producción' : 'Pendiente de liberación' ) . '</b></div>';
        $notice = sanitize_key( wp_unslash( $_GET['workflow_notice'] ?? '' ) );
        $messages = array( 'saved' => 'Los datos quedaron guardados.', 'ai-queued' => 'Solicitud interna registrada. Queda pendiente la integración con AI-GRUPO.', 'approval-sent' => 'La solicitud de aprobación final se envió al cliente.', 'approval-failed' => 'No se pudo enviar la solicitud de aprobación. Revisá el email del cliente.', 'released' => 'Los trabajos aprobados pasaron a producción.', 'blocked' => 'Revisá el archivo, el control técnico y la aprobación final de cada trabajo.', 'email-sent' => 'El aviso al cliente se envió y quedó registrado.', 'email-failed' => 'No se pudo enviar el aviso al cliente.' );
        if ( isset( $messages[ $notice ] ) ) { echo '<div class="ge-production-notice' . ( in_array( $notice, array( 'blocked', 'email-failed', 'approval-failed' ), true ) ? ' is-error' : '' ) . '" role="status">' . esc_html( $messages[ $notice ] ) . '</div>'; }
        echo '<nav class="ge-workflow-steps" aria-label="Pasos del pedido">';
        foreach ( $labels as $key => $label ) {
            $available = in_array( $key, array( 'review', 'prepress', 'supplier' ), true ) || self::released( $order );
            echo $available ? '<a class="' . ( $key === $step ? 'is-active' : '' ) . '" href="' . esc_url( self::url( $order, $key ) ) . '">' . esc_html( $label ) . '</a>' : '<span aria-disabled="true">' . esc_html( $label ) . '</span>';
        }
        echo '</nav>';
        if ( 'review' === $step ) { self::render_review( $order ); }
        elseif ( 'prepress' === $step ) { self::render_prepress( $order ); }
        elseif ( 'production' === $step ) { self::render_production( $order ); }
        elseif ( 'supplier' === $step ) { self::render_supplier( $order ); }
        else { self::render_customer( $order ); }
    }

    private static function render_review( $order ) {
        if ( class_exists( 'GE_WTP_Manual_Orders' ) ) { GE_WTP_Manual_Orders::render_order_contact( $order ); }
        $suppliers = GE_WTP_Production::suppliers();
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>01 · Revisar</span><h2>Planificación</h2></div><p>La asignación automática es una sugerencia.</p></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_workflow_review"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
        wp_nonce_field( 'ge_workflow_review_' . $order->get_id() );
        if ( $order->get_customer_note() ) { echo '<p><strong>Notas ingresadas al crear el pedido:</strong> ' . esc_html( $order->get_customer_note() ) . '</p>'; }
        echo '<div class="ge-production-fields"><label>Fecha prometida<input type="date" name="promised_date" value="' . esc_attr( $order->get_meta( '_ge_production_promised_date' ) ) . '"></label><label>Prioridad<select name="priority">';
        foreach ( GE_WTP_Production::priorities() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $order->get_meta( '_ge_production_priority' ), $key, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label><label class="is-wide">Indicaciones internas adicionales<textarea name="technical_notes" rows="3" maxlength="3000" placeholder="Sólo instrucciones nuevas que afecten a todo el pedido">' . esc_textarea( $order->get_meta( '_ge_production_technical_notes' ) ) . '</textarea></label></div>';
        echo '<div class="ge-workflow-items">';
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $assigned = $item->get_meta( '_ge_production_supplier', true );
            $state = $item->get_meta( self::ITEM_STATE_META, true ) ?: 'received';
            echo '<article><h3>' . esc_html( $item->get_name() ) . '</h3><p>' . esc_html( $item->get_quantity() ) . ' unidades · ' . wp_kses_post( wc_display_item_meta( $item, array( 'echo' => false, 'separator' => ' · ' ) ) ) . '</p>';
            if ( 'production' === GE_WTP_Production::item_status( $item, $order ) ) { echo '<p><strong>Este producto ya fue liberado a producción.</strong></p></article>'; continue; }
            echo '<div class="ge-production-fields"><label>Proveedor sugerido<select name="items[' . esc_attr( $item_id ) . '][supplier]">';
            foreach ( $suppliers as $key => $supplier ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $assigned, $key, false ) . '>' . esc_html( $supplier['name'] ) . '</option>'; }
            echo '</select></label><label>Estado del archivo<select name="items[' . esc_attr( $item_id ) . '][state]">';
            foreach ( self::item_states() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $state, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
            echo '</select></label></div></article>';
        }
        echo '</div><div class="ge-production-submit"><button class="ge-staff-button" type="submit">Guardar revisión</button></div></form></section>';
        GE_WTP_Production::render_documents( $order );
        if ( class_exists( 'GE_WTP_Artwork_Library' ) ) { GE_WTP_Artwork_Library::render_staff_artwork_control(); self::$artwork_rendered = true; }
        self::render_approval_requests( $order );
        echo '<form class="ge-workflow-release" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_workflow_release"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
        wp_nonce_field( 'ge_workflow_release_' . $order->get_id() );
        $eligible = 0;
        foreach ( $order->get_items( 'line_item' ) as $item ) { if ( self::ready( $item, $order ) ) { $eligible++; } }
        $has_date = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $order->get_meta( '_ge_production_promised_date', true ) );
        echo '<p>Para liberar un trabajo necesitás el archivo final asignado al producto, aprobación interna o aprobación del cliente cuando se requiera, y fecha prometida.</p>';
        if ( ! $eligible || ! $has_date ) { echo '<p class="ge-production-notice is-error">' . esc_html( ! $has_date ? 'Guardá primero la fecha prometida en Planificación.' : 'Todavía no hay trabajos con archivo y aprobación completos.' ) . '</p>'; }
        echo '<button class="ge-staff-button" type="submit" ' . disabled( ! $eligible || ! $has_date, true, false ) . '>Enviar trabajos aprobados a producción</button></form>';
    }

    public static function item_states() {
        return array( 'received' => 'Pendiente de revisión', 'missing' => 'Falta archivo', 'customer_fix' => 'Requiere corrección del cliente', 'graph_fix' => 'Requiere preproducción Graph Express', 'ready' => 'Listo para control final' );
    }

    private static function render_approval_requests( $order ) {
        $email = sanitize_email( $order->get_billing_email() );
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>Aprobación final</span><h2>Presentar archivos al cliente</h2></div></div><p>Revisá internamente cada versión antes de enviarla. El cliente confirma el archivo exacto en su portal.</p>';
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( 'production' === GE_WTP_Production::item_status( $item, $order ) ) { continue; }
            $state = $item->get_meta( self::ITEM_STATE_META, true );
            $sources = (array) $item->get_meta( '_ge_item_artwork_sources', true );
            $staff = (array) $item->get_meta( '_ge_item_artwork_staff_approval', true );
            if ( 'ready' !== $state || ! $sources || empty( $staff['approved'] ) ) { continue; }
            $customer = (array) $item->get_meta( '_ge_item_artwork_customer_approval', true );
            echo '<article class="ge-workflow-approval-item"><strong>' . esc_html( $item->get_name() ) . '</strong><span>Versión: ' . esc_html( $item->get_meta( '_ge_item_artwork_version', true ) ?: 'sin definir' ) . '</span>';
            if ( ! empty( $customer['approved'] ) ) { echo '<p>Aprobado por cliente.</p>'; }
            elseif ( 'yes' !== $item->get_meta( '_ge_item_artwork_client_required', true ) ) { echo '<p>Aprobado por staff. No requiere confirmación del cliente.</p>'; }
            elseif ( 'yes' === $item->get_meta( '_ge_item_artwork_client_required', true ) && is_email( $email ) ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_workflow_request_approval"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="item_id" value="' . esc_attr( $item_id ) . '">'; wp_nonce_field( 'ge_workflow_approval_' . $order->get_id() . '_' . $item_id ); echo '<button type="submit">Enviar versión al cliente para aprobar</button></form>'; }
            else { echo '<p>No hay email. Solicitá la aprobación por un canal verificable y registrá la evidencia en el control de archivos.</p>'; }
            echo '</article>';
        }
        echo '</section>';
    }

    private static function render_sources( $order ) {
        GE_WTP_AI_Artwork::assets();
        $sources = GE_WTP_Artwork_Library::order_sources( $order );
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>Archivos recibidos</span><h2>Originales del pedido</h2></div><strong>' . esc_html( count( $sources ) ) . '</strong></div>';
        if ( ! $sources ) { echo '<p>Aún no hay archivos vinculados a este pedido.</p>'; }
        else { echo '<ul class="ge-workflow-sources">'; foreach ( $sources as $source ) { echo '<li><a target="_blank" rel="noopener" href="' . esc_url( $source['url'] ) . '">' . esc_html( $source['name'] ) . '</a><span>' . esc_html( $source['code'] ) . '</span>'; if ( 0 === strpos( $source['token'] ?? '', 'document:' ) ) { $document = GE_WTP_Documents::find_version( $order->get_id(), substr( $source['token'], 9 ) ); if ( $document ) { GE_WTP_AI_Artwork::render_button( $order, $document ); } } echo '</li>'; } echo '</ul><p>Un archivo general puede asignarse al producto en el control siguiente sin volver a subirlo.</p>'; }
        echo '</section>';
    }

    private static function render_prepress( $order ) {
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>02 · Opcional</span><h2>Preproducción</h2></div></div><p>El empleado revisa cada resultado antes de presentarlo al cliente. Sólo el cliente aprueba la versión final.</p>';
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $state = $item->get_meta( self::ITEM_STATE_META, true );
            if ( ! in_array( $state, array( 'graph_fix', 'customer_fix', 'missing' ), true ) ) { continue; }
            if ( 'production' === GE_WTP_Production::item_status( $item, $order ) ) { continue; }
            echo '<article class="ge-workflow-prepress-item"><h3>' . esc_html( $item->get_name() ) . '</h3><p>' . esc_html( self::item_states()[ $state ] ) . '</p>';
            $requests = (array) $item->get_meta( self::REQUESTS_META, true );
            foreach ( array_reverse( $requests ) as $request ) { echo '<p><strong>Solicitud ' . esc_html( $request['id'] ?? '' ) . '</strong> · ' . esc_html( $request['status'] ?? 'pendiente' ) . ' · ' . esc_html( $request['brief'] ?? '' ) . '</p>'; if ( ! empty( $request['output_document_id'] ) ) { echo '<p><a href="' . esc_url( GE_WTP_Documents::download_url( $order->get_id(), $request['output_document_id'] ) ) . '">Revisar versión candidata →</a></p>'; } }
            foreach ( array_reverse( (array) $item->get_meta( '_ge_item_change_requests', true ) ) as $change ) { echo '<p><strong>Cambios solicitados por el cliente:</strong> ' . esc_html( $change['message'] ?? '' ) . '</p>'; }
            if ( 'graph_fix' === $state ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_workflow_ai_request"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="item_id" value="' . esc_attr( $item_id ) . '">'; wp_nonce_field( 'ge_workflow_ai_' . $order->get_id() . '_' . $item_id ); echo '<label>Trabajo concreto para AI-GRUPO<textarea name="brief" required maxlength="2000" rows="3" placeholder="Qué corregir, cómo debe quedar y qué revisar"></textarea></label><label>Límite máximo de uso en USD<input type="number" name="budget_usd" required min="0.01" max="25" step="0.01" value="1"></label><button class="ge-staff-button" type="submit">Pedir ayuda a AI-GRUPO</button></form>'; }
            echo '</article>';
        }
        echo '<p>Para cargar una versión corregida, usá los archivos del pedido y después asigná la versión exacta en Revisión.</p><a href="' . esc_url( self::url( $order, 'review' ) ) . '">Volver al control de archivos →</a></section>';
        GE_WTP_Production::render_documents( $order );
    }

    public static function finishing_catalog() {
        $saved = get_option( 'ge_wtp_finishing_catalog', array() );
        $defaults = array( 'corte' => 'Corte', 'hendido' => 'Hendido', 'pegado' => 'Pegado', 'abrochado' => 'Abrochado', 'agujereado' => 'Agujereado', 'confeccion' => 'Confección', 'emblocado' => 'Emblocado', 'laminado' => 'Laminado', 'troquelado' => 'Troquelado' );
        return is_array( $saved ) && $saved ? array_map( 'sanitize_text_field', $saved ) : $defaults;
    }

    private static function render_production( $order ) {
        if ( ! self::released( $order ) ) { echo '<div class="ge-production-notice is-error">Este pedido aún no fue liberado a producción.</div>'; return; }
        GE_WTP_AI_Artwork::assets();
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>03 · Producción</span><h2>Archivos finales y terminaciones</h2></div></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_workflow_finishes"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
        wp_nonce_field( 'ge_workflow_finishes_' . $order->get_id() );
        $sources = GE_WTP_Artwork_Library::order_sources( $order ); $catalog = self::finishing_catalog();
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( 'production' !== GE_WTP_Production::item_status( $item, $order ) ) { continue; }
            $selected = (array) $item->get_meta( self::FINISHES_META, true ); $tokens = (array) $item->get_meta( '_ge_item_artwork_sources', true );
            echo '<article class="ge-workflow-production-item"><h3>' . esc_html( $item->get_name() ) . '</h3><p>Archivo aprobado: '; foreach ( $tokens as $token ) { if ( isset( $sources[ $token ] ) ) { echo '<a target="_blank" rel="noopener" href="' . esc_url( $sources[ $token ]['url'] ) . '">' . esc_html( $sources[ $token ]['name'] ) . '</a> '; if ( 0 === strpos( $token, 'document:' ) ) { $document = GE_WTP_Documents::find_version( $order->get_id(), substr( $token, 9 ) ); if ( $document ) { GE_WTP_AI_Artwork::render_button( $order, $document ); } } } } echo '</p><fieldset><legend>Terminaciones (opcionales)</legend>';
            foreach ( $catalog as $key => $label ) { echo '<label><input type="checkbox" name="finishes[' . esc_attr( $item_id ) . '][]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $selected, true ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
            echo '</fieldset><label>Terminación especial<input type="text" name="custom[' . esc_attr( $item_id ) . ']" maxlength="180" value="' . esc_attr( $item->get_meta( '_ge_item_custom_finish', true ) ) . '" placeholder="Sólo si no figura en el catálogo"></label></article>';
        }
        echo '<div class="ge-production-submit"><button class="ge-staff-button" type="submit">Guardar terminaciones</button></div></form></section>';
    }

    private static function render_supplier( $order ) {
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>04 · Salida</span><h2>Destino y orden de producción</h2></div></div><p>Elegí producción interna o un proveedor. Revisá la ficha técnica y el mensaje antes de enviar desde el correo configurado de Graphex. El portal privado permite descargar archivos, confirmar recepción e informar una fecha estimada.</p></section>';
        if ( ! self::released( $order ) ) {
            $suppliers = GE_WTP_Production::suppliers();
            echo '<section class="ge-production-card ge-dispatch-card"><div class="ge-production-section-head"><div><span>Preparación</span><h2>Falta liberar el pedido</h2></div></div><p>El botón de envío aparecerá cuando se guarden la fecha, los archivos finales y sus aprobaciones, y se liberen los trabajos.</p><ul>';
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $key = sanitize_key( $item->get_meta( '_ge_production_supplier', true ) );
                echo '<li>' . esc_html( $item->get_name() . ' · ' . ( $suppliers[ $key ]['name'] ?? 'Proveedor pendiente' ) . ' · ' . ( self::ready( $item, $order ) ? 'Archivo aprobado' : 'Archivo o aprobación pendiente' ) ) . '</li>';
            }
            echo '</ul><a class="ge-staff-button" href="' . esc_url( self::url( $order, 'review' ) ) . '">Completar revisión y liberar →</a></section>';
            return;
        }
        $status = sanitize_key( wp_unslash( $_GET['dispatch_status'] ?? '' ) );
        if ( 'supplier-sent' === $status ) { echo '<div class="ge-production-notice" role="status">La orden fue enviada desde Graph Express.</div>'; }
        elseif ( 'supplier-failed' === $status ) { echo '<div class="ge-production-notice is-error" role="status">Falló el envío. Revisá el correo configurado y el historial.</div>'; }
        GE_WTP_Supplier_Portal::render( $order );
    }

    private static function render_customer( $order ) {
        if ( ! self::released( $order ) ) { echo '<div class="ge-production-notice is-error">Todavía no hay trabajos liberados para informar al cliente.</div>'; return; }
        $email = sanitize_email( $order->get_billing_email() );
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>05 · Comunicación</span><h2>Avisar al cliente</h2></div></div><p>El cliente verá que su pedido fue aprobado y enviado a producción. No se incluyen datos del proveedor.</p><div class="ge-workflow-message"><strong>Para: ' . esc_html( $email ?: 'Sin email' ) . '</strong><p>Tu pedido ' . esc_html( self::reference( $order ) ) . ' fue aprobado y pasó a producción. Podés consultar su avance en el portal de clientes.</p></div>';
        if ( ! self::all_items_released( $order ) || ! GE_WTP_Workflow_Dispatch::all_sent( $order ) ) { echo '<div class="ge-production-notice is-error">Primero liberá todos los productos y enviá los trabajos a sus proveedores externos.</div>'; }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_workflow_notify_customer"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">'; wp_nonce_field( 'ge_workflow_customer_' . $order->get_id() ); echo '<button class="ge-staff-button" type="submit" ' . disabled( ! is_email( $email ) || ! self::all_items_released( $order ) || ! GE_WTP_Workflow_Dispatch::all_sent( $order ), true, false ) . '>Enviar aviso al cliente</button></form>';
        $history = (array) $order->get_meta( '_ge_workflow_customer_notices', true );
        if ( $history ) { echo '<p>Último aviso: ' . esc_html( wp_date( 'd/m/Y H:i', absint( end( $history )['time'] ?? 0 ) ) ) . '</p>'; }
        echo '</section>';
    }

    private static function posted_order( $action ) {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! self::enabled( $order ) ) { wp_die( 'Pedido no disponible en este flujo.', 404 ); }
        check_admin_referer( $action . '_' . $order->get_id() );
        return $order;
    }

    private static function redirect( $order, $step, $notice ) {
        wp_safe_redirect( self::url( $order, $step, array( 'workflow_notice' => $notice ) ) ); exit;
    }

    public static function save_review() {
        $order = self::posted_order( 'ge_workflow_review' );
        $date = sanitize_text_field( wp_unslash( $_POST['promised_date'] ?? '' ) );
        if ( $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { $date = ''; }
        $priority = sanitize_key( wp_unslash( $_POST['priority'] ?? 'normal' ) );
        $order->update_meta_data( '_ge_production_promised_date', $date );
        $order->update_meta_data( '_ge_production_priority', isset( GE_WTP_Production::priorities()[ $priority ] ) ? $priority : 'normal' );
        $order->update_meta_data( '_ge_production_technical_notes', sanitize_textarea_field( wp_unslash( $_POST['technical_notes'] ?? '' ) ) );
        $suppliers = GE_WTP_Production::suppliers(); $states = self::item_states();
        foreach ( (array) ( $_POST['items'] ?? array() ) as $item_id => $values ) {
            $item = $order->get_item( absint( $item_id ) ); if ( ! $item instanceof WC_Order_Item_Product || 'production' === GE_WTP_Production::item_status( $item, $order ) ) { continue; }
            $supplier = sanitize_key( wp_unslash( $values['supplier'] ?? '' ) ); $state = sanitize_key( wp_unslash( $values['state'] ?? '' ) );
            if ( isset( $suppliers[ $supplier ] ) ) { $item->update_meta_data( '_ge_production_supplier', $supplier ); }
            if ( isset( $states[ $state ] ) ) { $item->update_meta_data( self::ITEM_STATE_META, $state ); }
            $item->save();
        }
        $keys = array(); foreach ( $order->get_items( 'line_item' ) as $item ) { $keys[] = $item->get_meta( '_ge_production_supplier', true ); }
        $keys = array_values( array_unique( $keys ) ); $order->update_meta_data( '_ge_production_supplier', 1 === count( $keys ) ? $keys[0] : 'multiple' );
        $order->save(); self::redirect( $order, 'review', 'saved' );
    }

    public static function request_ai() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); $item_id = absint( $_POST['item_id'] ?? 0 );
        if ( ! self::enabled( $order ) ) { wp_die( 'Pedido no disponible.', 403 ); }
        check_admin_referer( 'ge_workflow_ai_' . $order->get_id() . '_' . $item_id );
        $item = $order->get_item( $item_id ); $brief = sanitize_textarea_field( wp_unslash( $_POST['brief'] ?? '' ) );
        $budget = min( 25, max( 0, (float) ( $_POST['budget_usd'] ?? 0 ) ) );
        if ( ! $item instanceof WC_Order_Item_Product || 'production' === GE_WTP_Production::item_status( $item, $order ) || 'graph_fix' !== $item->get_meta( self::ITEM_STATE_META, true ) || ! $brief || $budget <= 0 ) { wp_die( 'Solicitud incompleta.', 400 ); }
        $requests = (array) $item->get_meta( self::REQUESTS_META, true );
        $request = array( 'id' => wp_generate_uuid4(), 'status' => 'queued', 'brief' => $brief, 'budget_usd' => $budget, 'created_at' => time(), 'created_by' => get_current_user_id(), 'input_tokens' => (array) $item->get_meta( '_ge_item_artwork_sources', true ), 'version' => (string) $item->get_meta( '_ge_item_artwork_version', true ) );
        $requests[] = $request; $item->update_meta_data( self::REQUESTS_META, array_slice( $requests, -30 ) ); $item->save();
        do_action( 'ge_wtp_ai_prepress_request_created', $request, $order->get_id(), $item_id );
        self::redirect( $order, 'prepress', 'ai-queued' );
    }

    public static function request_approval() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); $item_id = absint( $_POST['item_id'] ?? 0 );
        if ( ! self::enabled( $order ) ) { wp_die( 'Pedido no disponible.', 403 ); }
        check_admin_referer( 'ge_workflow_approval_' . $order->get_id() . '_' . $item_id );
        $item = $order->get_item( $item_id ); $email = sanitize_email( $order->get_billing_email() );
        $sources = $item instanceof WC_Order_Item_Product ? (array) $item->get_meta( '_ge_item_artwork_sources', true ) : array();
        $staff = $item instanceof WC_Order_Item_Product ? (array) $item->get_meta( '_ge_item_artwork_staff_approval', true ) : array();
        if ( ! $item instanceof WC_Order_Item_Product || 'production' === GE_WTP_Production::item_status( $item, $order ) || 'ready' !== $item->get_meta( self::ITEM_STATE_META, true ) || ! $sources || empty( $staff['approved'] ) || ! is_email( $email ) ) { self::redirect( $order, 'review', 'approval-failed' ); }
        $portal = GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) );
        $registration = ! $order->get_customer_id() ? '<p>Si todavía no tenés cuenta, <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">registrate con este mismo email</a> para ver el pedido.</p>' : '';
        $html = '<p>Preparámos el archivo de <strong>' . esc_html( $item->get_name() ) . '</strong>, versión <strong>' . esc_html( $item->get_meta( '_ge_item_artwork_version', true ) ) . '</strong>.</p><p><a href="' . esc_url( $portal ) . '">Revisar y aprobar la versión final en tu portal</a></p>' . $registration . '<p>Si necesitás cambios, respondé este mensaje antes de aprobar. No enviaremos este trabajo a producción sin tu confirmación.</p>';
        $sent = GE_WTP_Notifications::send( $email, 'Aprobación final del archivo · ' . self::reference( $order ), $html, 'workflow_artwork_approval', $order->get_id() );
        $item->update_meta_data( '_ge_item_artwork_client_required', 'yes' );
        $history = (array) $item->get_meta( '_ge_item_approval_requests', true ); $history[] = array( 'time' => time(), 'user_id' => get_current_user_id(), 'email' => $email, 'version' => (string) $item->get_meta( '_ge_item_artwork_version', true ), 'checksum' => GE_WTP_Artwork_Library::release_fingerprint( $item, $sources, (string) $item->get_meta( '_ge_item_artwork_version', true ), (array) $item->get_meta( '_ge_item_artwork_expected', true ), GE_WTP_Artwork_Library::order_sources( $order ) ), 'requested_at' => gmdate( 'c' ), 'sent' => (bool) $sent ); $item->update_meta_data( '_ge_item_approval_requests', array_slice( $history, -30 ) ); $item->save();
        self::redirect( $order, 'review', $sent ? 'approval-sent' : 'approval-failed' );
    }

    /** Called by the future AI-GRUPO bridge after it imports a candidate document. */
    public static function record_ai_result( $order_id, $item_id, $request_id, $document_id, $summary ) {
        $order = wc_get_order( absint( $order_id ) ); $item = $order ? $order->get_item( absint( $item_id ) ) : false;
        if ( ! self::enabled( $order ) || ! $item instanceof WC_Order_Item_Product || 'production' === GE_WTP_Production::item_status( $item, $order ) ) { return false; }
        $document = false;
        foreach ( GE_WTP_Documents::get_documents( $order->get_id() ) as $candidate ) { if ( hash_equals( (string) ( $candidate['id'] ?? '' ), (string) $document_id ) && absint( $candidate['order_item_id'] ?? 0 ) === (int) $item_id && 'arte' === ( $candidate['category'] ?? '' ) ) { $document = $candidate; break; } }
        if ( ! $document ) { return false; }
        $requests = (array) $item->get_meta( self::REQUESTS_META, true ); $found = false;
        foreach ( $requests as &$request ) { if ( hash_equals( (string) ( $request['id'] ?? '' ), (string) $request_id ) && in_array( $request['status'] ?? '', array( 'queued', 'working' ), true ) ) { $request['status'] = 'candidate'; $request['output_document_id'] = (string) $document_id; $request['summary'] = sanitize_textarea_field( $summary ); $request['completed_at'] = time(); $found = true; break; } } unset( $request );
        if ( ! $found ) { return false; }
        $item->update_meta_data( self::REQUESTS_META, $requests ); $item->save();
        return true;
    }

    /** The AI-GRUPO bridge may report progress without exposing its worker internals. */
    public static function record_ai_progress( $order_id, $item_id, $request_id, $status, $message = '' ) {
        if ( ! in_array( $status, array( 'working', 'blocked', 'failed' ), true ) ) { return false; }
        $order = wc_get_order( absint( $order_id ) ); $item = $order ? $order->get_item( absint( $item_id ) ) : false;
        if ( ! self::enabled( $order ) || ! $item instanceof WC_Order_Item_Product ) { return false; }
        $requests = (array) $item->get_meta( self::REQUESTS_META, true );
        foreach ( $requests as &$request ) {
            if ( ! hash_equals( (string) ( $request['id'] ?? '' ), (string) $request_id ) || ! in_array( $request['status'] ?? '', array( 'queued', 'working' ), true ) ) { continue; }
            $request['status'] = $status; $request['status_message'] = sanitize_textarea_field( $message ); $request['updated_at'] = time();
            $item->update_meta_data( self::REQUESTS_META, $requests ); $item->save(); return true;
        }
        return false;
    }

    public static function customer_changes() {
        if ( ! is_user_logged_in() ) { auth_redirect(); }
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); $item_id = absint( $_POST['item_id'] ?? 0 );
        if ( ! self::enabled( $order ) || ! GE_WTP_Documents::can_access_order( $order ) ) { wp_die( 'Acceso denegado.', 403 ); }
        check_admin_referer( 'ge_workflow_customer_changes_' . $order->get_id() );
        $item = $order->get_item( $item_id ); $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        if ( ! $item instanceof WC_Order_Item_Product || 'production' === GE_WTP_Production::item_status( $item, $order ) || 'ready' !== $item->get_meta( self::ITEM_STATE_META, true ) || ! $message ) { wp_die( 'Revisá el trabajo y el detalle de los cambios.', 400 ); }
        $changes = (array) $item->get_meta( '_ge_item_change_requests', true ); $changes[] = array( 'time' => time(), 'user_id' => get_current_user_id(), 'message' => $message, 'version' => (string) $item->get_meta( '_ge_item_artwork_version', true ) );
        $item->update_meta_data( '_ge_item_change_requests', array_slice( $changes, -30 ) ); $item->update_meta_data( self::ITEM_STATE_META, 'graph_fix' ); $item->delete_meta_data( '_ge_item_artwork_customer_approval' ); $item->delete_meta_data( '_ge_item_artwork_release_hash' ); $item->delete_meta_data( '_ge_item_artwork_released_at' ); $item->save();
        $order->add_order_note( 'El cliente solicitó cambios en el arte del ítem #' . $item_id . '.' ); $order->save();
        wp_safe_redirect( GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id(), 'ge_notice' => 'artwork-changes' ) ) ); exit;
    }

    public static function release() {
        $order = self::posted_order( 'ge_workflow_release' );
        $approved = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) { if ( 'production' !== GE_WTP_Production::item_status( $item, $order ) && self::ready( $item, $order ) ) { $approved[] = $item; } }
        $date = (string) $order->get_meta( '_ge_production_promised_date', true );
        if ( ! $approved || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { self::redirect( $order, 'review', 'blocked' ); }
        foreach ( $approved as $item ) { $item->update_meta_data( '_ge_item_status', 'production' ); $item->update_meta_data( '_ge_item_workflow_released_at', time() ); $item->update_meta_data( '_ge_item_workflow_released_by', get_current_user_id() ); $item->save(); }
        $order->update_meta_data( self::STAGE_META, 'production' ); $order->update_meta_data( '_ge_production_status', 'production' ); $order->update_meta_data( '_ge_production_started_at', time() ); $order->save();
        if ( class_exists( 'GE_WTP_Order_Lifecycle' ) ) { GE_WTP_Order_Lifecycle::set_stage( $order, 'aprobado', 'Archivo final liberado por el equipo.' ); }
        self::redirect( $order, 'production', 'released' );
    }

    public static function save_finishes() {
        $order = self::posted_order( 'ge_workflow_finishes' );
        if ( ! self::released( $order ) ) { self::redirect( $order, 'review', 'blocked' ); }
        $catalog = self::finishing_catalog(); $posted = (array) ( $_POST['finishes'] ?? array() );
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( 'production' !== GE_WTP_Production::item_status( $item, $order ) ) { continue; }
            $values = array(); foreach ( (array) ( $posted[ $item_id ] ?? array() ) as $key ) { $key = sanitize_key( wp_unslash( $key ) ); if ( isset( $catalog[ $key ] ) ) { $values[] = $key; } }
            $item->update_meta_data( self::FINISHES_META, array_values( array_unique( $values ) ) );
            $item->update_meta_data( '_ge_item_custom_finish', sanitize_text_field( wp_unslash( $_POST['custom'][ $item_id ] ?? '' ) ) ); $item->save();
        }
        self::redirect( $order, 'production', 'saved' );
    }

    public static function notify_customer() {
        $order = self::posted_order( 'ge_workflow_customer' );
        if ( ! self::released( $order ) || ! self::all_items_released( $order ) ) { self::redirect( $order, 'review', 'blocked' ); }
        if ( ! GE_WTP_Workflow_Dispatch::all_sent( $order ) ) { self::redirect( $order, 'supplier', 'blocked' ); }
        $email = sanitize_email( $order->get_billing_email() );
        if ( ! is_email( $email ) ) { self::redirect( $order, 'customer', 'email-failed' ); }
        $reference = self::reference( $order ); $portal = GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) );
        $html = '<p>Tu pedido <strong>' . esc_html( $reference ) . '</strong> fue aprobado y pasó a producción.</p><p>Podés consultar su avance desde <a href="' . esc_url( $portal ) . '">tu portal de cliente</a>.</p>';
        $sent = GE_WTP_Notifications::send( $email, 'Pedido en producción · ' . $reference, $html, 'workflow_customer_production', $order->get_id() );
        $history = (array) $order->get_meta( '_ge_workflow_customer_notices', true ); $history[] = array( 'time' => time(), 'user_id' => get_current_user_id(), 'sent' => (bool) $sent, 'email' => $email ); $order->update_meta_data( '_ge_workflow_customer_notices', array_slice( $history, -30 ) ); $order->save();
        if ( $sent && class_exists( 'GE_WTP_Order_Lifecycle' ) ) { GE_WTP_Order_Lifecycle::set_stage( $order, 'produccion', 'Aviso de producción enviado al cliente.' ); }
        self::redirect( $order, 'customer', $sent ? 'email-sent' : 'email-failed' );
    }
}
