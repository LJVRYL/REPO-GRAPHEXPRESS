<?php
defined( 'ABSPATH' ) || exit;

/** Native branded presentation of an already authorized immutable fiscal payload. */
final class GE_WTP_ARCA_Invoice_Compact_PDF {
    const REVISION = 'graphex-compact-c-v1';

    private static function date( $s ) { return strlen( $s ) === 8 ? substr( $s, 6, 2 ) . '/' . substr( $s, 4, 2 ) . '/' . substr( $s, 0, 4 ) : $s; }
    private static function money( $s ) { return number_format( (float) $s, 2, ',', '.' ); }
    private static function quantity( $value ) {
        $s = (string) $value;
        if ( ! preg_match( '/^([0-9]+)(?:\.([0-9]+))?$/D', $s, $m ) ) { return str_replace( '.', ',', $s ); }
        return preg_replace( '/\B(?=(\d{3})+(?!\d))/', '.', $m[1] ) . ',' . str_pad( rtrim( $m[2] ?? '', '0' ), 2, '0' );
    }

    /** Width-based wrapping, including unbroken strings, without discarding any text. */
    private static function wrap( $pdf, $text, $width, $size, $bold = false ) {
        $out = array();
        foreach ( preg_split( '/\R/u', trim( (string) $text ) ) as $paragraph ) {
            $line = '';
            foreach ( preg_split( '/\s+/u', trim( $paragraph ) ) as $word ) {
                if ( $word === '' ) { continue; }
                if ( $line !== '' && $pdf->measure( $line . ' ' . $word, $size, $bold ) > $width ) { $out[] = $line; $line = ''; }
                if ( $pdf->measure( $word, $size, $bold ) > $width ) {
                    if ( $line !== '' ) { $out[] = $line; $line = ''; }
                    foreach ( preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
                        if ( $line !== '' && $pdf->measure( $line . $char, $size, $bold ) > $width ) { $out[] = $line; $line = ''; }
                        $line .= $char;
                    }
                } else { $line = $line === '' ? $word : $line . ' ' . $word; }
            }
            if ( $line !== '' ) { $out[] = $line; }
        }
        return $out ?: array( '' );
    }

    private static function lines( $pdf, $lines, $x, $top, $size, $leading, $bold = false, $muted = false ) {
        foreach ( $lines as $line ) { $pdf->text( $x, $top, $size, $line, $bold, $muted ? 96 : 17, $muted ? 96 : 17, $muted ? 107 : 22 ); $top += $leading; }
        return $top;
    }

    public static function build( $row ) {
        if ( 'authorized' !== ( $row['state'] ?? '' ) || ! preg_match( '/^[0-9]{14}$/D', (string) ( $row['authorization']['cae'] ?? '' ) ) || 'C' !== ( $row['payload']['class'] ?? '' ) ) { return new WP_Error( 'ge_arca_pdf', 'Falta una factura C autorizada.' ); }
        $p = $row['payload']; $d = $p['detail']; $issuer = $p['issuer']; $receiver = $p['receiver'];
        $pdf = new GE_WTP_Simple_PDF();
        if ( ! method_exists( $pdf, 'use_brand_body_font' ) || ! $pdf->use_brand_body_font() ) { return new WP_Error( 'ge_arca_pdf_font', 'Falta la tipografía verificada del comprobante.' ); }
        $number = sprintf( '%05d-%08d', $p['header']['PtoVta'], $row['number'] );
        $url = GE_WTP_ARCA_Invoice_PDF::qr_url( $row );
        $qr = new \Graphex\QR\QRCode(); $qr->setErrorCorrectLevel( \Graphex\QR\QR_ERROR_CORRECT_LEVEL_M ); $qr->addData( $url, \Graphex\QR\QR_MODE_8BIT_BYTE );
        $made = false;
        for ( $v = 1; $v <= 40; $v++ ) {
            $capacity = 0;
            foreach ( \Graphex\QR\QRRSBlock::getRSBlocks( $v, \Graphex\QR\QR_ERROR_CORRECT_LEVEL_M ) as $block ) { $capacity += $block->getDataCount() * 8; }
            if ( 4 + ( $v < 10 ? 8 : 16 ) + strlen( $url ) * 8 + 4 <= $capacity ) { $qr->setTypeNumber( $v ); $qr->make(); $made = true; break; }
        }
        if ( ! $made ) { return new WP_Error( 'ge_arca_pdf_qr', 'No se pudo generar el QR fiscal.' ); }

        $start = function() use ( $pdf, $p, $d, $issuer, $receiver, $number ) {
            $pdf->begin_page(); $pdf->fill_rect( 0, 0, 595, 4, 91, 81, 245 );
            $pdf->brand_symbol( 36, 30, 35 ); $pdf->text( 80, 51, 21, 'GRAPHEX', true, 17, 17, 22 ); $pdf->text( 81, 64, 8, 'graphex.ar', false, 96, 96, 107 );
            $pdf->rounded_rect( 271, 23, 53, 51, 4, array( 221, 220, 223 ) ); $pdf->text( 287, 53, 28, 'C', true, 17, 17, 22 ); $pdf->text( 277, 69, 6.8, 'Código Nº 011' );
            $pdf->text_right( 559, 40, 10, 'FACTURA', true, 91, 81, 245 ); $pdf->text_right( 559, 57, 9, $number );
            $pdf->text_right( 559, 77, 7, 'production' === $p['environment'] ? 'ORIGINAL' : 'HOMOLOGACIÓN - SIN VALIDEZ FISCAL', false, 96, 96, 107 );
            $name = self::wrap( $pdf, $issuer['legal_name'], 233, 10, true ); $address = self::wrap( $pdf, $issuer['fiscal_address'], 233, 8 );
            $height = max( 100, 29 + count( $name ) * 12 + count( $address ) * 11 + 24 );
            $pdf->rounded_rect( 36, 90, 255, $height, 4, array( 221, 220, 223 ) ); $pdf->rounded_rect( 304, 90, 255, $height, 4, array( 221, 220, 223 ) );
            $pdf->text( 47, 108, 7, 'EMISOR', true, 91, 81, 245 );
            $y = self::lines( $pdf, $name, 47, 126, 10, 12, true ); $y = self::lines( $pdf, $address, 47, $y + 7, 8, 11 );
            $pdf->text( 47, 90 + $height - 12, 8.5, 'RESPONSABLE MONOTRIBUTO' );
            $pdf->text( 315, 111, 8.5, 'Fecha de emisión: ' . self::date( $d['CbteFch'] ) );
            $pdf->text( 315, 130, 9, 'CUIT: ' . $issuer['cuit'] ); $pdf->text( 315, 149, 9, 'Ingresos Brutos: ' . $issuer['iibb'] );
            $pdf->text( 315, 168, 9, 'Inicio de actividades: ' . self::date( str_replace( '-', '', $p['activity_start'] ) ) );
            $pdf->text_right( 548, 90 + $height - 5, 7, 'Punto de venta: ' . sprintf( '%05d', $p['header']['PtoVta'] ), false, 96, 96, 107 );
            $top = 90 + $height + 23; $pdf->text( 36, $top, 7, 'CLIENTE FISCAL', true, 91, 81, 245 ); $pdf->text( 342, $top, 7, 'CONDICIONES', true, 91, 81, 245 );
            $y = self::lines( $pdf, self::wrap( $pdf, $receiver['legal_name'], 290, 9, true ), 36, $top + 19, 9, 12, true );
            $vat = array( 1 => 'IVA Responsable inscripto', 6 => 'Monotributista', 4 => 'IVA Exento', 5 => 'Consumidor final' );
            $recipient_document = (int) $d['DocTipo'] === 80 ? 'CUIT: ' . $d['DocNro'] . ' · ' : ( (int) $d['DocTipo'] === 96 ? 'DNI: ' . $d['DocNro'] . ' · ' : '' );
            $y = self::lines( $pdf, self::wrap( $pdf, $recipient_document . ( $vat[$d['CondicionIVAReceptorId']] ?? 'Condición fiscal verificada' ), 290, 8 ), 36, $y + 5, 8, 11 );
            $y = self::lines( $pdf, self::wrap( $pdf, $receiver['fiscal_address'], 290, 8 ), 36, $y + 4, 8, 11 );
            $conditions = array( 'Venta: ' . $p['sale_terms'], 'Concepto: ' . array( 1 => 'Productos', 2 => 'Servicios', 3 => 'Productos y servicios' )[$d['Concepto']] );
            if ( isset( $d['FchServDesde'] ) ) { $conditions[] = 'Servicio: ' . self::date( $d['FchServDesde'] ) . ' a ' . self::date( $d['FchServHasta'] ); $conditions[] = 'Vto. pago: ' . self::date( $d['FchVtoPago'] ); }
            $cy = $top + 19;
            foreach ( $conditions as $condition ) { $cy = self::lines( $pdf, self::wrap( $pdf, $condition, 217, 8.5 ), 342, $cy, 8.5, 12 ); $cy += 4; }
            $table = max( 292, $y + 14, $cy + 12 );
            if ( $table > 420 ) { return new WP_Error( 'ge_arca_pdf_layout', 'Los datos fiscales requieren una revisión de maquetación antes de publicar.' ); }
            $pdf->rounded_rect( 36, $table, 523, 25, 4, array( 17, 17, 22 ), true ); $pdf->text( 47, $table + 16, 7, 'Producto', false, 255, 255, 255 );
            foreach ( array( 357 => 'Cantidad', 457 => 'Precio unitario', 547 => 'Importe' ) as $x => $label ) { $pdf->text_right( $x, $table + 16, 7, $label, false, 255, 255, 255 ); }
            return $table + 48;
        };
        $footer = function( $page ) use ( $pdf, $d, $row, $qr ) {
            $pdf->text( 347, 521, 9, 'Subtotal', false, 96, 96, 107 ); $pdf->text_right( 559, 521, 9, '$ ' . self::money( $d['ImpNeto'] ) );
            if ( (float) $d['ImpTrib'] !== 0.0 ) { $pdf->text( 347, 537, 8, 'Otros tributos' ); $pdf->text_right( 559, 537, 8, '$ ' . self::money( $d['ImpTrib'] ) ); }
            $total_text = '$ ' . self::money( $d['ImpTotal'] ); $total_size = 18;
            while ( $total_size > 9 && $pdf->measure( $total_text, $total_size ) > 145 ) { $total_size--; }
            $pdf->rounded_rect( 337, 544, 222, 38, 4, array( 91, 81, 245 ), true ); $pdf->text( 350, 568, 10, 'TOTAL', true, 255, 255, 255 ); $pdf->text_right( 547, 572, $total_size, $total_text, false, 255, 255, 255 );
            $pdf->text( 36, 561, 9, 'Factura C · sin IVA discriminado', false, 96, 96, 107 ); $pdf->line( 36, 699, 559, 699, 221, 220, 223, .6 );
            $count = $qr->getModuleCount(); $module = 93 / ( $count + 8 );
            for ( $a = 0; $a < $count; $a++ ) { for ( $b = 0; $b < $count; $b++ ) { if ( $qr->isDark( $a, $b ) ) { $pdf->fill_rect( 36 + ( $b + 4 ) * $module, 714 + ( $a + 4 ) * $module, $module, $module, 0, 0, 0 ); } } }
            $pdf->text( 147, 730, 7, 'COMPROBANTE AUTORIZADO POR ARCA', true, 91, 81, 245 ); $pdf->text( 147, 755, 12, 'CAE: ' . $row['authorization']['cae'], true );
            $pdf->text( 147, 777, 9, 'Vencimiento del CAE: ' . self::date( $row['authorization']['expires'] ) );
            $pdf->text_right( 559, 801, 8, 'Hoja ' . $page, false, 96, 96, 107 ); $pdf->line( 36, 814, 559, 814, 221, 220, 223, .6 ); $pdf->text( 36, 833, 7, 'GRAPHEX · Impresión que comunica', false, 96, 96, 107 );
        };
        $pages = array(); $page = 1; $y = $start(); if ( is_wp_error( $y ) ) { return $y; }
        $items = $p['items'];
        if ( (float) ( $p['shipping_net'] ?? 0 ) !== 0.0 ) { $items[] = array( 'name' => 'Envío', 'quantity' => 1, 'net' => $p['shipping_net'] ); }
        foreach ( $p['fees'] ?? array() as $fee ) { $items[] = array( 'name' => $fee['name'], 'quantity' => 1, 'net' => $fee['net'] ); }
        foreach ( $items as $item ) {
            $lines = self::wrap( $pdf, $item['description'] ?? $item['name'], 270, 9 ); $first = true;
            while ( $lines ) {
                if ( $y > 475 ) { $footer( $page++ ); $pages[] = $pdf->end_page(); $y = $start(); if ( is_wp_error( $y ) ) { return $y; } }
                if ( ! $first ) { $pdf->text( 47, $y, 7, '(continuación)', false, 96, 96, 107 ); $y += 11; }
                $room = max( 1, (int) floor( ( 490 - $y ) / 12 ) ); $chunk = array_splice( $lines, 0, $room );
                if ( $first ) {
                    $quantity = (float) $item['quantity']; $unit = $quantity > 0 ? (float) $item['net'] / $quantity : 0;
                    $unit_text = abs( round( $unit, 2 ) - $unit ) > .0000001 ? number_format( $unit, 4, ',', '.' ) : self::money( $unit );
                    $pdf->text_right( 357, $y, 9, self::quantity( $item['quantity'] ) ); $pdf->text_right( 457, $y, 9, $unit_text ); $pdf->text_right( 547, $y, 9, self::money( $item['net'] ) );
                }
                $y = self::lines( $pdf, $chunk, 47, $y, 9, 12 ); $first = false;
                if ( $lines ) { $y = 491; } else { $y += 14; $pdf->line( 36, $y, 559, $y, 221, 220, 223, .6 ); $y += 23; }
            }
        }
        $footer( $page ); $pages[] = $pdf->end_page(); return $pdf->output( $pages );
    }
}
