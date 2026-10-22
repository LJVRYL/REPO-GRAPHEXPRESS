<?php
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/vendor/qrcode-generator/qrcode.php';
require_once __DIR__ . '/class-ge-wtp-arca-invoice-compact-pdf.php';

/** PDF from the authorized immutable fiscal journal, never from current order prices. */
final class GE_WTP_ARCA_Invoice_PDF {
    private static function display_date( $date ) { return substr( $date, 6, 2 ) . '/' . substr( $date, 4, 2 ) . '/' . substr( $date, 0, 4 ); }
    public static function qr_url( $row ) {
        $p = $row['payload']; $d = $p['detail'];
        $data = array( 'ver' => 1, 'fecha' => substr( $d['CbteFch'], 0, 4 ) . '-' . substr( $d['CbteFch'], 4, 2 ) . '-' . substr( $d['CbteFch'], 6, 2 ), 'cuit' => (int) $p['issuer']['cuit'],
            'ptoVta' => (int) $p['header']['PtoVta'], 'tipoCmp' => (int) $p['header']['CbteTipo'], 'nroCmp' => (int) $row['number'], 'importe' => (float) $d['ImpTotal'], 'moneda' => $d['MonId'], 'ctz' => (float) $d['MonCotiz'],
            'tipoDocRec' => (int) $d['DocTipo'], 'nroDocRec' => (int) $d['DocNro'], 'tipoCodAut' => 'E', 'codAut' => (int) $row['authorization']['cae'] );
        return 'https://www.arca.gob.ar/fe/qr/?p=' . base64_encode( wp_json_encode( $data ) );
    }
    private static function lines( $pdf, $text, $x, &$y, $width = 500, $size = 10 ) {
        $words = preg_split( '/\s+/u', trim( (string) $text ) ); $line = '';
        foreach ( $words as $word ) {
            $candidate = $line ? $line . ' ' . $word : $word;
            if ( $line && GE_WTP_Simple_PDF::width( $candidate, $size ) > $width ) { $pdf->text( $x, $y, $size, $line ); $y += 14; $line = $word; }
            else { $line = $candidate; }
        }
        $pdf->text( $x, $y, $size, $line ); $y += 14;
    }
    public static function build( $row ) {
        if ( 'C' === ( $row['payload']['class'] ?? '' ) ) { return GE_WTP_ARCA_Invoice_Compact_PDF::build( $row ); }
        if ( 'authorized' !== $row['state'] || ! preg_match( '/^[0-9]{14}$/D', (string) ( $row['authorization']['cae'] ?? '' ) ) ) { return new WP_Error( 'ge_arca_pdf', 'Falta autorización fiscal válida.' ); }
        $p = $row['payload']; $d = $p['detail']; $i = $p['issuer']; $r = $p['receiver'];
        $pdf = new GE_WTP_Simple_PDF(); $pages = array();
        $url = self::qr_url( $row );
        $qr = new \Graphex\QR\QRCode(); $qr->setErrorCorrectLevel( \Graphex\QR\QR_ERROR_CORRECT_LEVEL_M ); $qr->addData( $url, \Graphex\QR\QR_MODE_8BIT_BYTE );
        $made = false;
        for ( $v = 1; $v <= 40; $v++ ) {
            $capacity = 0;
            foreach ( \Graphex\QR\QRRSBlock::getRSBlocks( $v, \Graphex\QR\QR_ERROR_CORRECT_LEVEL_M ) as $block ) { $capacity += $block->getDataCount() * 8; }
            if ( 4 + ( $v < 10 ? 8 : 16 ) + strlen( $url ) * 8 + 4 <= $capacity ) { $qr->setTypeNumber( $v ); $qr->make(); $made = true; break; }
        }
        if ( ! $made ) { return new WP_Error( 'ge_arca_pdf', 'No se pudo generar el QR fiscal.' ); }
        $number = sprintf( '%05d-%08d', $p['header']['PtoVta'], $row['number'] );
        $start = function() use ( $pdf, $p, $d, $i, $r, $number ) {
            $pdf->begin_page();
            $pdf->brand_symbol( 40, 25, 48 );
            $pdf->text( 98, 49, 22, 'GRAPHEX', true, 27, 27, 32 );
            $pdf->text( 99, 67, 10, 'Impresión que comunica', false, 70, 70, 75 );
            $pdf->line( 40, 87, 555, 87, 220, 220, 225 );
            $pdf->text( 40, 105, 11, 'ORIGINAL', true );
            if ( 'homologation' === $p['environment'] ) { $pdf->text( 290, 105, 11, 'HOMOLOGACIÓN · SIN VALIDEZ FISCAL', true, 180, 0, 0 ); }
            $pdf->text( 40, 134, 20, 'Factura ' . $p['class'], true ); $pdf->text( 180, 134, 10, 'Código ' . sprintf( '%03d', $p['header']['CbteTipo'] ) ); $pdf->text_right( 555, 134, 14, $number, true );
            $y = 158; self::lines( $pdf, $i['legal_name'], 40, $y, 515, 12 ); self::lines( $pdf, 'CUIT ' . $i['cuit'] . ' · ' . GE_WTP_Billing_Issuers::vat_label( $i ), 40, $y );
            self::lines( $pdf, $i['fiscal_address'], 40, $y );
            self::lines( $pdf, 'IIBB: ' . ( $i['iibb'] ?? '' ) . ' · Inicio de actividades: ' . ( $p['activity_start'] ?? 'NO VERIFICADO' ), 40, $y );
            self::lines( $pdf, 'Fecha: ' . substr( $d['CbteFch'], 6, 2 ) . '/' . substr( $d['CbteFch'], 4, 2 ) . '/' . substr( $d['CbteFch'], 0, 4 ), 40, $y );
            $recipient_document = (int) $d['DocTipo'] === 80 ? ' · CUIT ' . $d['DocNro'] : ( (int) $d['DocTipo'] === 96 ? ' · DNI ' . $d['DocNro'] : '' );
            $y += 8; self::lines( $pdf, 'Receptor: ' . $r['legal_name'] . $recipient_document, 40, $y ); self::lines( $pdf, $r['fiscal_address'], 40, $y );
            self::lines( $pdf, 'Condición IVA: ' . ( array( 1 => 'Responsable Inscripto', 6 => 'Monotributista', 4 => 'Exento', 5 => 'Consumidor final' )[$d['CondicionIVAReceptorId']] ?? 'No informada' ), 40, $y );
            self::lines( $pdf, 'Concepto: ' . array( 1 => 'Productos', 2 => 'Servicios', 3 => 'Productos y servicios' )[$d['Concepto']], 40, $y );
            if ( isset( $d['FchServDesde'] ) ) { self::lines( $pdf, 'Período: ' . self::display_date( $d['FchServDesde'] ) . ' a ' . self::display_date( $d['FchServHasta'] ) . ' · Vto. pago: ' . self::display_date( $d['FchVtoPago'] ), 40, $y ); }
            self::lines( $pdf, 'Condición de venta: ' . ( $p['sale_terms'] ?? 'NO VERIFICADO' ), 40, $y );
            $pdf->line( 40, $y + 4, 555, $y + 4, 130, 130, 130 ); return $y + 25;
        };
        $footer = function( $page ) use ( $pdf, $qr, $p, $d, $row ) {
            $pdf->line( 40, 654, 555, 654, 130, 130, 130 );
            $pdf->text( 40, 678, 10, 'Neto: $' . $d['ImpNeto'] . ' · IVA: $' . $d['ImpIVA'] . ' · Otros tributos: $' . $d['ImpTrib'] );
            $pdf->text_right( 555, 703, 17, 'TOTAL ARS $' . $d['ImpTotal'], true );
            $count = $qr->getModuleCount(); $module = 93 / ( $count + 8 ); $pdf->fill_rect( 40, 719, 93, 93, 255, 255, 255 );
            for ( $a = 0; $a < $count; $a++ ) { for ( $b = 0; $b < $count; $b++ ) { if ( $qr->isDark( $a, $b ) ) { $pdf->fill_rect( 40 + ( $b + 4 ) * $module, 719 + ( $a + 4 ) * $module, $module, $module, 0, 0, 0 ); } } }
            $pdf->text( 150, 746, 12, 'CAE: ' . $row['authorization']['cae'], true );
            $expiry = $row['authorization']['expires']; $pdf->text( 150, 766, 10, 'Vencimiento CAE: ' . substr( $expiry, 6, 2 ) . '/' . substr( $expiry, 4, 2 ) . '/' . substr( $expiry, 0, 4 ) );
            $pdf->text( 150, 786, 9, 'Comprobante autorizado por ARCA · Pedido #' . $p['order_id'] ); $pdf->text_right( 555, 820, 8, 'Página ' . $page );
        };
        $y = $start(); $page = 1;
        $lines = $p['items'];
        if ( (float) $p['shipping_net'] !== 0.0 ) { $lines[] = array( 'name' => 'Envío', 'quantity' => 1, 'net' => $p['shipping_net'] ); }
        foreach ( $p['fees'] ?? array() as $fee ) { $lines[] = array( 'name' => $fee['name'], 'quantity' => 1, 'net' => $fee['net'] ); }
        foreach ( $lines as $item ) {
            $quantity = (float) $item['quantity'];
            $unit = $quantity > 0 ? number_format( (float) $item['net'] / $quantity, 4, ',', '.' ) : '';
            $subtotal = number_format( (float) $item['net'], 2, ',', '.' );
            $text = $item['quantity'] . ' × ' . ( $item['description'] ?? $item['name'] ) . "\nPrecio unitario neto: $" . $unit . ' · Subtotal neto: $' . $subtotal;
            // Reserve enough space for wrapped descriptions, without clipping long product names.
            foreach ( $pdf->wrap( $text, 85 ) as $line ) {
                if ( $y > 620 ) { $footer( $page++ ); $pages[] = $pdf->end_page(); $y = $start(); }
                self::lines( $pdf, $line, 40, $y );
            }
            $y += 8;
        }
        $footer( $page ); $pages[] = $pdf->end_page(); return $pdf->output( $pages );
    }
    public static function publish( $row, $refresh_presentation = false ) {
        if ( 'authorized' !== ( $row['state'] ?? '' ) ) { return new WP_Error( 'ge_arca_pdf', 'El registro no tiene autorización fiscal.' ); }
        global $wpdb;
        $lock = 'ge_arca_pdf_' . (int) $row['payload']['order_id'];
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return new WP_Error( 'ge_arca_pdf_busy', 'El PDF se está actualizando. Consultá nuevamente.' ); }
        try { return self::publish_locked( $row, $refresh_presentation ); }
        finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }
    private static function publish_locked( $row, $refresh_presentation ) {
        $p = $row['payload']; $order = wc_get_order( $p['order_id'] );
        if ( ! $order ) { return new WP_Error( 'ge_arca_pdf_order', 'Factura autorizada. Falta recuperar el pedido para publicar el PDF.' ); }
        $documents = GE_WTP_Documents::get_documents( $order->get_id() );
        $previous = null; $previous_index = null;
        foreach ( $documents as $index => $document ) {
            if ( (int) ( $document['arca_invoice_id'] ?? 0 ) !== (int) $row['id'] ) { continue; }
            if ( ! $refresh_presentation || 'C' !== $p['class'] || ( $document['pdf_render_revision'] ?? '' ) === GE_WTP_ARCA_Invoice_Compact_PDF::REVISION ) { return $document; }
            $number = sprintf( '%05d-%08d', $p['header']['PtoVta'], $row['number'] );
            if ( ( $document['document_number'] ?? '' ) !== $number || (string) ( $document['cae'] ?? '' ) !== (string) $row['authorization']['cae'] || (string) ( $document['issuer_snapshot']['cuit'] ?? '' ) !== (string) $p['issuer']['cuit'] || (string) ( $document['billing_profile_snapshot']['cuit'] ?? '' ) !== (string) $p['receiver']['cuit'] ) { return new WP_Error( 'ge_arca_pdf_conflict', 'La presentación existente no coincide con el registro fiscal. No se reemplazó.' ); }
            $old_path = GE_WTP_Customer_Invoices::file_path( array( 'file' => $document ) );
            if ( $old_path && ( empty( $document['sha256'] ) || ! hash_equals( $document['sha256'], hash_file( 'sha256', $old_path ) ) ) ) { return new WP_Error( 'ge_arca_pdf_integrity', 'El PDF anterior requiere revisión. No se reemplazó.' ); }
            $previous = $document; $previous['presentation_file_available'] = (bool) $old_path; $previous_index = $index; break;
        }
        if ( 'production' !== $p['environment'] ) { return array( 'arca_invoice_id' => $row['id'], 'cae' => $row['authorization']['cae'], 'environment' => 'homologation', 'pdf_bytes' => self::build( $row ) ); }
        if ( ! GE_WTP_Documents::ensure_private_directory() ) { return new WP_Error( 'ge_arca_pdf_storage', 'Factura autorizada. Falta almacenamiento para publicar el PDF; no vuelvas a emitir.' ); }
        $bytes = self::build( $row ); if ( is_wp_error( $bytes ) ) { return $bytes; }
        $stored = 'arca-' . $row['id'] . '-' . hash( 'sha256', $bytes ) . '.pdf'; $path = trailingslashit( GE_WTP_Documents::private_directory() ) . $stored;
        if ( is_link( $path ) || ( file_exists( $path ) && hash_file( 'sha256', $path ) !== hash( 'sha256', $bytes ) ) ) { return new WP_Error( 'ge_arca_pdf_integrity', 'Revisá la integridad del PDF autorizado.' ); }
        if ( ! file_exists( $path ) ) { $file = fopen( $path, 'xb' ); if ( ! $file ) { return new WP_Error( 'ge_arca_pdf_storage', 'Factura autorizada. No se pudo guardar el PDF.' ); } try { if ( fwrite( $file, $bytes ) !== strlen( $bytes ) || ! fflush( $file ) ) { return new WP_Error( 'ge_arca_pdf_storage', 'Factura autorizada. Revisá el archivo antes de publicarlo.' ); } } finally { fclose( $file ); } chmod( $path, 0640 ); }
        $number = sprintf( '%05d-%08d', $p['header']['PtoVta'], $row['number'] );
        $record = array( 'id' => 'arca-' . $row['id'], 'version_id' => 'arca-' . $row['id'], 'stored_name' => $stored, 'name' => 'factura-' . $p['class'] . '-' . $number . '.pdf', 'mime' => 'application/pdf', 'size' => strlen( $bytes ),
            'category' => 'factura', 'issued_by_graphex' => true, 'document_number' => $number, 'issue_date' => substr( $p['detail']['CbteFch'], 0, 4 ) . '-' . substr( $p['detail']['CbteFch'], 4, 2 ) . '-' . substr( $p['detail']['CbteFch'], 6, 2 ), 'uploaded_at' => gmdate( 'Y-m-d H:i:s' ), 'uploaded_by' => $p['approved_by'],
            'issuer_snapshot' => $p['issuer'], 'billing_profile_snapshot' => $p['receiver'], 'arca_invoice_id' => (int) $row['id'], 'cae' => $row['authorization']['cae'], 'cae_expires' => $row['authorization']['expires'], 'qr_url' => self::qr_url( $row ), 'sha256' => hash( 'sha256', $bytes ) );
        if ( 'C' === $p['class'] ) { $record['pdf_render_revision'] = GE_WTP_ARCA_Invoice_Compact_PDF::REVISION; }
        if ( $previous !== null ) {
            // Reload native metadata before replacing only this presentation; retain unrelated uploads.
            $order->read_meta_data( true ); $latest = $order->get_meta( GE_WTP_Documents::META_KEY, true ); $latest = is_array( $latest ) ? $latest : array(); $found = false;
            foreach ( $latest as $index => $document ) {
                if ( ( $document['id'] ?? '' ) !== $previous['id'] ) { continue; }
                if ( ( $document['sha256'] ?? '' ) !== $previous['sha256'] || ( $document['cae'] ?? '' ) !== $previous['cae'] ) { return new WP_Error( 'ge_arca_pdf_changed', 'El documento cambió durante la actualización. Revisá la versión vigente.' ); }
                $available = $previous['presentation_file_available']; $previous = $document; $previous['presentation_file_available'] = $available; $previous_index = $index; $found = true; break;
            }
            if ( ! $found ) { return new WP_Error( 'ge_arca_pdf_changed', 'El documento cambió durante la actualización. No se reemplazó.' ); }
            $documents = $latest;
            $history = $previous['presentation_history'] ?? array(); unset( $previous['presentation_history'] ); $history[] = $previous;
            $record['presentation_history'] = $history; $record['presentation_updated_at'] = gmdate( 'c' );
            // Existing invoice/download references stay stable. Only the active PDF projection changes.
            $record['id'] = $previous['id']; $record['version_id'] = $previous['version_id'];
            $record['uploaded_at'] = $previous['uploaded_at']; $record['uploaded_by'] = $previous['uploaded_by'];
            $documents[$previous_index] = $record;
        } else {
            $order->read_meta_data( true ); $latest = $order->get_meta( GE_WTP_Documents::META_KEY, true ); $documents = is_array( $latest ) ? $latest : array();
            foreach ( $documents as $document ) { if ( (int) ( $document['arca_invoice_id'] ?? 0 ) === (int) $row['id'] ) { return new WP_Error( 'ge_arca_pdf_changed', 'La factura ya tiene una presentación. Consultá su versión vigente.' ); } }
            $documents[] = $record;
        }
        $order->update_meta_data( GE_WTP_Documents::META_KEY, $documents ); $order->save();
        $order->add_order_note( ( $previous !== null ? 'Formato PDF actualizado, sin nueva emisión: ' : 'Factura ARCA ' ) . $p['class'] . ' ' . $number . ' · CAE ' . $record['cae'] . ' · Registro #' . $row['id'] );
        return $record;
    }
}
