<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit, per-supplier dispatch with scoped download links. */
final class GE_WTP_Workflow_Dispatch {
    const GRANTS_META = '_ge_workflow_supplier_grants';
    const HISTORY_META = '_ge_workflow_supplier_history';

    public static function init() {
        add_action( 'admin_post_ge_workflow_send_supplier', array( __CLASS__, 'send' ) );
        add_action( 'admin_post_ge_workflow_supplier_file', array( __CLASS__, 'download' ) );
        add_action( 'admin_post_nopriv_ge_workflow_supplier_file', array( __CLASS__, 'download' ) );
    }

    private static function groups( $order ) {
        $groups = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( 'production' !== GE_WTP_Production::item_status( $item, $order ) ) { continue; }
            $key = sanitize_key( $item->get_meta( '_ge_production_supplier', true ) );
            if ( ! $key ) { $key = 'pending'; }
            if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array(); }
            $groups[ $key ][] = $item;
        }
        return $groups;
    }

    private static function documents_for_items( $order, $items ) {
        $documents = GE_WTP_Documents::get_documents( $order->get_id() ); $by_id = array();
        foreach ( $documents as $document ) { if ( ! empty( $document['id'] ) ) { $by_id[ (string) $document['id'] ] = $document; } }
        $result = array();
        foreach ( $items as $item ) {
            if ( ! GE_WTP_Artwork_Library::item_ready_for_production( $item, $order ) ) { return false; }
            foreach ( (array) $item->get_meta( '_ge_item_artwork_sources', true ) as $source ) {
                if ( 0 !== strpos( $source, 'document:' ) ) { return false; }
                $id = substr( $source, 9 );
                if ( ! isset( $by_id[ $id ] ) || 'arte' !== ( $by_id[ $id ]['category'] ?? '' ) || ! in_array( absint( $by_id[ $id ]['order_item_id'] ?? 0 ), array( 0, $item->get_id() ), true ) ) { return false; }
                $result[ $id ] = $by_id[ $id ];
            }
        }
        return $result ?: false;
    }

    private static function reference( $order ) { return GE_WTP_Manual_Orders::reference( $order ); }

    private static function item_summary( $item ) {
        $finishes = array();
        $catalog = get_option( 'ge_wtp_finishing_catalog', array() );
        if ( ! is_array( $catalog ) || ! $catalog ) { $catalog = array( 'corte' => 'Corte', 'hendido' => 'Hendido', 'pegado' => 'Pegado', 'abrochado' => 'Abrochado', 'agujereado' => 'Agujereado', 'confeccion' => 'Confección', 'emblocado' => 'Emblocado', 'laminado' => 'Laminado', 'troquelado' => 'Troquelado' ); }
        foreach ( (array) $item->get_meta( GE_WTP_Workflow::FINISHES_META, true ) as $key ) { if ( isset( $catalog[ $key ] ) ) { $finishes[] = sanitize_text_field( $catalog[ $key ] ); } }
        $custom = sanitize_text_field( $item->get_meta( '_ge_item_custom_finish', true ) );
        if ( $custom ) { $finishes[] = $custom; }
        return $item->get_name() . ' · ' . $item->get_quantity() . ' unidades' . ( $finishes ? ' · Terminaciones: ' . implode( ', ', $finishes ) : '' );
    }

    private static function group_fingerprint( $items ) {
        $parts = array();
        foreach ( $items as $item ) {
            $parts[] = array( $item->get_id(), $item->get_quantity(), (string) $item->get_meta( '_ge_item_artwork_release_hash', true ), (array) $item->get_meta( GE_WTP_Workflow::FINISHES_META, true ), (string) $item->get_meta( '_ge_item_custom_finish', true ) );
        }
        return hash( 'sha256', wp_json_encode( $parts ) );
    }

    private static function sent_for( $order, $supplier ) {
        $history = (array) $order->get_meta( self::HISTORY_META, true );
        $groups = self::groups( $order ); $fingerprint = self::group_fingerprint( $groups[ $supplier ] ?? array() );
        foreach ( array_reverse( $history ) as $entry ) { if ( $supplier === ( $entry['supplier'] ?? '' ) && ! empty( $entry['sent'] ) && hash_equals( $fingerprint, (string) ( $entry['fingerprint'] ?? '' ) ) ) { return $entry; } }
        return false;
    }

    public static function all_sent( $order ) {
        $groups = self::groups( $order );
        if ( ! $groups ) { return false; }
        foreach ( $groups as $supplier => $items ) {
            foreach ( $items as $item ) { if ( ! GE_WTP_Artwork_Library::item_ready_for_production( $item, $order ) ) { return false; } }
            if ( 'internal' === $supplier ) { continue; }
            $profile = GE_WTP_Supplier_Dispatch::profile( $supplier );
            $sent = self::sent_for( $order, $supplier );
            if ( ! self::documents_for_items( $order, $items ) || ! $sent || ! is_email( $profile['email'] ?? '' ) || ! hash_equals( (string) $profile['email'], (string) ( $sent['email'] ?? '' ) ) ) { return false; }
        }
        return true;
    }

    public static function render( $order ) {
        $groups = self::groups( $order );
        if ( ! $groups ) { echo '<section class="ge-production-card"><p>No hay productos liberados.</p></section>'; return; }
        foreach ( $groups as $supplier => $items ) {
            if ( 'internal' === $supplier ) { echo '<section class="ge-production-card"><h2>Producción interna</h2><p>Este trabajo se realiza en Graph Express y no requiere envío a proveedor.</p></section>'; continue; }
            $profile = GE_WTP_Supplier_Dispatch::profile( $supplier ); $documents = self::documents_for_items( $order, $items );
            $sent = self::sent_for( $order, $supplier );
            echo '<section class="ge-production-card ge-dispatch-card"><div class="ge-production-section-head"><div><span>Orden al proveedor</span><h2>' . esc_html( $profile['name'] ?? $supplier ) . '</h2></div>' . ( $sent ? '<b>Enviado ' . esc_html( wp_date( 'd/m/Y H:i', absint( $sent['time'] ) ) ) . '</b>' : '' ) . '</div>';
            echo '<p><strong>Destinatario:</strong> ' . esc_html( $profile['email'] ?? 'Sin email configurado' ) . '</p><p><strong>Fecha solicitada:</strong> ' . esc_html( GE_WTP_Production::date_label_public( $order->get_meta( '_ge_production_promised_date' ) ) ) . '</p><ul>';
            foreach ( $items as $item ) { echo '<li>' . esc_html( self::item_summary( $item ) ) . '</li>'; }
            echo '</ul><p><strong>Indicaciones:</strong> ' . esc_html( $order->get_meta( '_ge_production_technical_notes' ) ?: 'Sin observaciones generales.' ) . '</p>';
            if ( $documents ) { echo '<p><strong>Archivos incluidos mediante enlaces privados:</strong></p><ul>'; foreach ( $documents as $document ) { echo '<li>' . esc_html( $document['name'] ) . '</li>'; } echo '</ul><p>El correo pedirá confirmar recepción y fecha de entrega. Los enlaces vencerán a los 7 días.</p>'; }
            else { echo '<div class="ge-production-notice is-error">Todos los archivos finales deben estar asignados a su producto y guardados como documentos privados antes de enviarlos.</div>'; }
            if ( $profile && is_email( $profile['email'] ?? '' ) && $documents ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_workflow_send_supplier"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="supplier" value="' . esc_attr( $supplier ) . '">'; wp_nonce_field( 'ge_workflow_supplier_' . $order->get_id() . '_' . $supplier ); echo '<button class="ge-staff-button" type="submit">' . ( $sent ? 'Reenviar orden' : 'Enviar al proveedor' ) . '</button></form>'; }
            echo '</section>';
        }
    }

    public static function send() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); $supplier = sanitize_key( wp_unslash( $_POST['supplier'] ?? '' ) );
        if ( ! GE_WTP_Workflow::enabled( $order ) || 'production' !== $order->get_meta( GE_WTP_Workflow::STAGE_META, true ) ) { wp_die( 'Orden no liberada.', 403 ); }
        check_admin_referer( 'ge_workflow_supplier_' . $order->get_id() . '_' . $supplier );
        $groups = self::groups( $order ); $items = $groups[ $supplier ] ?? array(); $profile = GE_WTP_Supplier_Dispatch::profile( $supplier );
        $documents = $items && 'internal' !== $supplier ? self::documents_for_items( $order, $items ) : false;
        if ( ! $documents || ! is_email( $profile['email'] ?? '' ) ) { wp_die( 'Revisá el proveedor y los archivos finales.', 400 ); }
        $token = bin2hex( random_bytes( 32 ) ); $grant = array( 'id' => wp_generate_uuid4(), 'hash' => hash( 'sha256', $token ), 'supplier' => $supplier, 'fingerprint' => self::group_fingerprint( $items ), 'document_ids' => array_keys( $documents ), 'expires' => time() + 7 * DAY_IN_SECONDS, 'created_at' => time(), 'created_by' => get_current_user_id(), 'accesses' => array() );
        $grants = (array) $order->get_meta( self::GRANTS_META, true ); $grants[] = $grant; $order->update_meta_data( self::GRANTS_META, array_slice( $grants, -30 ) ); $order->save();
        $reference = self::reference( $order ); $rows = '';
        foreach ( $items as $item ) {
            $specifications = wc_display_item_meta( $item, array( 'echo' => false, 'separator' => ' · ' ) );
            $rows .= '<li><strong>' . esc_html( self::item_summary( $item ) ) . '</strong> ' . wp_kses_post( $specifications ) . '</li>';
        }
        $links = '';
        foreach ( $documents as $document ) {
            $url = add_query_arg( array( 'action' => 'ge_workflow_supplier_file', 'order_id' => $order->get_id(), 'document_id' => $document['id'], 'token' => $token ), admin_url( 'admin-post.php' ) );
            $links .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $document['name'] ) . '</a></li>';
        }
        $html = '<p>Solicitamos producir la orden <strong>' . esc_html( $reference ) . '</strong> para el ' . esc_html( GE_WTP_Production::date_label_public( $order->get_meta( '_ge_production_promised_date' ) ) ) . '.</p><ul>' . $rows . '</ul><p>Indicaciones: ' . nl2br( esc_html( $order->get_meta( '_ge_production_technical_notes' ) ?: 'Sin observaciones adicionales.' ) ) . '</p><p>Archivos finales (enlaces privados válidos por 7 días):</p><ul>' . $links . '</ul><p>Confirmá recepción y fecha de entrega respondiendo este correo.</p>';
        $sent = GE_WTP_Notifications::send( $profile['email'], 'Orden de producción ' . $reference . ' · Graph Express', $html, 'workflow_supplier_order', $order->get_id() );
        if ( ! $sent ) { $grants = array_values( array_filter( $grants, function( $entry ) use ( $grant ) { return ( $entry['id'] ?? '' ) !== $grant['id']; } ) ); $order->update_meta_data( self::GRANTS_META, $grants ); }
        $history = (array) $order->get_meta( self::HISTORY_META, true ); $history[] = array( 'time' => time(), 'user_id' => get_current_user_id(), 'supplier' => $supplier, 'email' => $profile['email'], 'sent' => (bool) $sent, 'grant_id' => $sent ? $grant['id'] : '', 'fingerprint' => self::group_fingerprint( $items ) ); $order->update_meta_data( self::HISTORY_META, array_slice( $history, -50 ) ); $order->save();
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id(), 'step' => 'supplier', 'dispatch_status' => $sent ? 'supplier-sent' : 'supplier-failed' ) ) ); exit;
    }

    public static function download() {
        $order = wc_get_order( absint( $_GET['order_id'] ?? 0 ) ); $document_id = sanitize_text_field( wp_unslash( $_GET['document_id'] ?? '' ) ); $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        if ( ! GE_WTP_Workflow::enabled( $order ) || ! $token || ! $document_id ) { wp_die( 'Enlace no válido.', 403 ); }
        $hash = hash( 'sha256', $token ); $grants = (array) $order->get_meta( self::GRANTS_META, true ); $found = false;
        foreach ( $grants as &$grant ) {
            if ( ! hash_equals( (string) ( $grant['hash'] ?? '' ), $hash ) || absint( $grant['expires'] ?? 0 ) < time() || ! in_array( $document_id, (array) ( $grant['document_ids'] ?? array() ), true ) ) { continue; }
            $groups = self::groups( $order ); $items = $groups[ $grant['supplier'] ?? '' ] ?? array(); $current = $items ? self::documents_for_items( $order, $items ) : false;
            if ( ! $current || ! isset( $current[ $document_id ] ) || ! hash_equals( self::group_fingerprint( $items ), (string) ( $grant['fingerprint'] ?? '' ) ) ) { break; }
            $grant['accesses'][] = array( 'document_id' => $document_id, 'time' => time() ); $grant['accesses'] = array_slice( $grant['accesses'], -100 ); $found = $current[ $document_id ]; break;
        }
        unset( $grant );
        if ( ! $found ) { wp_die( 'El enlace venció o el archivo fue reemplazado.', 403 ); }
        $order->update_meta_data( self::GRANTS_META, $grants ); $order->save();
        GE_WTP_Documents::stream_record( $found );
    }
}
