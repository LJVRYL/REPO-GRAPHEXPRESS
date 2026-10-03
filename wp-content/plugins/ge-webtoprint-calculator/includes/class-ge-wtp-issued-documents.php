<?php

defined( 'ABSPATH' ) || exit;

/** Staff-issued commercial documents attached to a WooCommerce order. */
require_once __DIR__ . '/class-ge-wtp-billing-issuers.php';

final class GE_WTP_Issued_Documents {
    public static function init() {
        add_action( 'admin_post_ge_issued_document_attach', array( __CLASS__, 'handle_attach' ) );
    }

    public static function handle_attach() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'ge_manage_operations' ) ) { wp_die( 'Acceso denegado.', 403 ); }
        $order_id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_issued_document_' . $order_id );
        $type = sanitize_key( wp_unslash( $_POST['document_type'] ?? '' ) );
        $number = sanitize_text_field( wp_unslash( $_POST['document_number'] ?? '' ) );
        $date = sanitize_text_field( wp_unslash( $_POST['issue_date'] ?? '' ) );
        $replaces = sanitize_text_field( wp_unslash( $_POST['replaces_id'] ?? '' ) );
        $result = GE_WTP_Documents::attach_issued( $order_id, $type, $number, $date, $replaces, sanitize_text_field( wp_unslash( $_POST['issuer_confirmation_hash'] ?? '' ) ) );
        $url = GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order_id, 'issued_status' => is_wp_error( $result ) ? $result->get_error_code() : 'saved' ) );
        wp_safe_redirect( $url );
        exit;
    }

    public static function render_staff( $order ) {
        $documents = GE_WTP_Documents::issued_documents( $order->get_id(), true );
        $current = array_values( array_filter( $documents, function ( $document ) { return empty( $document['superseded_at'] ); } ) );
        echo '<section class="ge-admin-panel ge-issued-documents"><div class="ge-admin-panel-head"><div><span>Facturación</span><h2>Facturas y documentos emitidos</h2></div></div>';
        if ( isset( $_GET['issued_status'] ) ) { echo '<p class="ge-order-notice">' . esc_html( 'saved' === $_GET['issued_status'] ? 'Documento guardado y disponible en el portal.' : 'No se pudo guardar el documento. Revisá el PDF y los datos.' ) . '</p>'; }
        if ( ! $documents ) { echo '<p>Todavía no hay documentos emitidos para este pedido.</p>'; }
        foreach ( $documents as $document ) {
            $label = GE_WTP_Documents::categories()[ $document['category'] ] ?? 'Documento emitido';
            echo '<p><a href="' . esc_url( GE_WTP_Documents::download_url( $order->get_id(), $document['id'] ) ) . '">' . esc_html( $label . ( ! empty( $document['document_number'] ) ? ' ' . $document['document_number'] : '' ) ) . '</a> · ' . esc_html( $document['issue_date'] ?: $document['uploaded_at'] ) . ( ! empty( $document['superseded_at'] ) ? ' · Versión reemplazada' : '' ) . '</p>';
        }
        echo '<form class="ge-admin-form ge-admin-upload" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_issued_document_attach"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
        wp_nonce_field( 'ge_issued_document_' . $order->get_id() );
        $issuer = GE_WTP_Billing_Issuers::order_snapshot( $order );
        echo '<p>Emisor registrado: ' . esc_html( GE_WTP_Billing_Issuers::label( $issuer ) ) . '</p><label><input type="checkbox" name="issuer_confirmation_hash" value="' . esc_attr( hash( 'sha256', wp_json_encode( $issuer ) ) ) . '" required> Revisé que el PDF corresponde al emisor registrado. Si es histórico sin emisor, se conserva como no registrado y requiere revisión.</label>';
        echo '<label>Tipo<select name="document_type"><option value="factura">Factura</option><option value="nota_credito">Nota de crédito</option><option value="nota_debito">Nota de débito</option><option value="presupuesto_emitido">Presupuesto PDF</option><option value="otro">Otro documento comercial</option></select></label>';
        echo '<label>Número (opcional)<input name="document_number" maxlength="80"></label><label>Fecha de emisión (opcional)<input type="date" name="issue_date"></label>';
        echo '<label>Reemplaza versión (opcional)<select name="replaces_id"><option value="">Documento nuevo</option>';
        foreach ( $current as $document ) { echo '<option value="' . esc_attr( $document['id'] ) . '">' . esc_html( ( GE_WTP_Documents::categories()[ $document['category'] ] ?? 'Documento' ) . ' · ' . ( $document['document_number'] ?: $document['name'] ) ) . '</option>'; }
        echo '</select></label><label>PDF emitido<input type="file" name="ge_issued_document" accept="application/pdf,.pdf" required></label><button class="ge-staff-button" type="submit">Subir factura o documento</button></form></section>';
    }

    public static function render_portal( $order ) {
        $documents = GE_WTP_Documents::issued_documents( $order->get_id() );
        echo '<section class="ge-panel ge-issued-documents"><span class="ge-eyebrow">Facturación</span><h2>Facturas y documentos emitidos</h2>';
        if ( ! $documents ) { echo '<p>La factura estará disponible acá cuando se cargue.</p>'; }
        foreach ( $documents as $document ) {
            $label = GE_WTP_Documents::categories()[ $document['category'] ] ?? 'Documento emitido';
            echo '<p><strong>' . esc_html( $label . ( ! empty( $document['document_number'] ) ? ' ' . $document['document_number'] : '' ) ) . '</strong> · ' . esc_html( $document['issue_date'] ?: $document['uploaded_at'] ) . ' <a href="' . esc_url( GE_WTP_Documents::download_url( $order->get_id(), $document['id'] ) ) . '">Descargar PDF</a></p>';
        }
        echo '</section>';
    }
}
