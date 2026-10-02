<?php
defined( 'ABSPATH' ) || exit;

/** Commercial PDFs use the existing immutable version, never a fresh price calculation. */
final class GE_WTP_Commercial_Quote_PDF {
    const ACTION = 'ge_commercial_quote_pdf';

    public static function init() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
    }

    public static function url( $id ) {
        return wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'quote_id' => absint( $id ) ), admin_url( 'admin-post.php' ) ), self::ACTION . '_' . absint( $id ) );
    }

    public static function download() {
        $id = absint( $_GET['quote_id'] ?? 0 );
        check_admin_referer( self::ACTION . '_' . $id );
        $actor = get_current_user_id();
        $quote = $actor ? GE_WTP_Commercial_Quotes::get( $id, $actor ) : false;
        $staff = $actor && ( user_can( $actor, 'ge_manage_operations' ) || user_can( $actor, 'manage_woocommerce' ) );
        if ( ! $quote || is_wp_error( $quote ) || ( ! $staff && 'draft' === $quote['status'] ) ) {
            wp_die( 'No tenés acceso a este presupuesto.', '', array( 'response' => 403 ) );
        }
        $pdf = self::build( $quote );
        if ( is_wp_error( $pdf ) ) { wp_die( esc_html( $pdf->get_error_message() ), '', array( 'response' => 409 ) ); }
        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'presupuesto-' . $quote['number'] . '-v' . $quote['version'] . '.pdf' ) . '"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /** Ephemeral attachment outside the public document root; caller always removes it. */
    public static function attachment( $id ) {
        $quote = GE_WTP_Commercial_Quotes::get( $id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        $bytes = self::build( $quote );
        if ( is_wp_error( $bytes ) ) { return $bytes; }
        $directory = realpath( sys_get_temp_dir() );
        $root = realpath( ABSPATH );
        if ( ! $directory || ! $root || 0 === strpos( $directory . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR ) ) {
            return new WP_Error( 'ge_pdf_temp', 'No hay almacenamiento temporal privado disponible.' );
        }
        $path = tempnam( $directory, 'ge-quote-' );
        if ( ! $path ) { return new WP_Error( 'ge_pdf_temp', 'No pudimos preparar el PDF adjunto.' ); }
        chmod( $path, 0600 );
        if ( strlen( $bytes ) !== file_put_contents( $path, $bytes ) ) { unlink( $path ); return new WP_Error( 'ge_pdf_write', 'No pudimos guardar el PDF adjunto.' ); }
        return array( 'path' => $path, 'name' => sanitize_file_name( 'presupuesto-' . $quote['number'] . '-v' . $quote['version'] . '.pdf' ), 'version' => $quote['version'], 'sha256' => hash( 'sha256', $bytes ) );
    }

    public static function build( $quote ) {
        $s = $quote['snapshot'] ?? array();
        if ( ! isset( $s['total_cents'], $s['net_cents'] ) || empty( $s['items'] ) ) {
            return new WP_Error( 'ge_pdf_snapshot', 'El presupuesto necesita un snapshot comercial completo antes de emitir el PDF.' );
        }
        $pdf = new GE_WTP_Simple_PDF();
        $currency = $s['currency'] ?? 'ARS';
        $profile = $s['billing']['profile'] ?? array();
        $customer = get_userdata( $quote['customer_id'] );
        // Fiscal identity is frozen in the version; contact fallback follows the existing portal.
        $client = array_filter( array( ( $profile['legal_name'] ?? '' ) ?: ( $customer ? $customer->display_name : '' ), ! empty( $profile['cuit'] ) ? 'CUIT ' . $profile['cuit'] : '', $profile['contact_name'] ?? '', ( $profile['billing_email'] ?? '' ) ?: ( $customer ? $customer->user_email : '' ), $profile['contact_phone'] ?? '', $profile['fiscal_address'] ?? '' ) );
        $address = array_filter( array( $profile['street'] ?? $profile['address_1'] ?? '', $profile['city'] ?? '', $profile['postcode'] ?? '' ) );
        if ( $address ) { $client[] = implode( ', ', $address ); }
        $rows = array();
        foreach ( $client as $text ) { foreach ( self::wrap( $text, 510, 10 ) as $line ) { $rows[] = array( 'text' => $line, 'kind' => 'client', 'height' => 16 ); } }
        $rows[] = array( 'kind' => 'gap', 'height' => 22 );
        $rows[] = array( 'kind' => 'table', 'height' => 28 );
        foreach ( $s['items'] as $item ) {
            $detail = array( $item['name'] ?? '' );
            if ( ! empty( $item['unit'] ) && 'u' !== $item['unit'] ) { $detail[] = 'Unidad de medida: ' . $item['unit']; }
            $configuration = GE_WTP_Commercial_Quote_UI::customer_configuration_label( $item );
            if ( $configuration ) { $detail[] = $configuration; }
            if ( ! empty( $item['finishes'] ) && class_exists( 'GE_WTP_Workflow' ) ) {
                $finishes = array_intersect_key( GE_WTP_Workflow::finishing_catalog(), array_flip( $item['finishes'] ) );
                if ( $finishes ) { $detail[] = 'Terminaciones: ' . implode( ', ', $finishes ); }
            }
            foreach ( array( 'details', 'notes' ) as $key ) { if ( ! empty( $item[ $key ] ) ) { $detail[] = $item[ $key ]; } }
            $lines = self::wrap( implode( "\n", $detail ), 290, 9.5 );
            foreach ( $lines as $index => $line ) {
                $rows[] = array( 'kind' => 'item', 'text' => $line, 'first' => 0 === $index, 'item' => $item, 'height' => 15 );
            }
            $rows[] = array( 'kind' => 'rule', 'height' => 17 );
        }
        $rows[] = array( 'kind' => 'gap', 'height' => 18 );
        // Only display fields actually recorded; no tax or discount is recomputed here.
        $discount = (int) ( $s['discount_cents'] ?? 0 );
        $subtotal = (int) ( $s['subtotal_cents'] ?? $s['net_cents'] );
        $rows[] = array( 'kind' => 'amount', 'label' => ! empty( $s['tax_cents'] ) ? 'Subtotal sin IVA' : 'Subtotal', 'amount' => $subtotal, 'height' => 23 );
        if ( $discount ) { $rows[] = array( 'kind' => 'amount', 'label' => 'Descuento', 'amount' => -$discount, 'height' => 23 ); }
        if ( $discount && isset( $s['tax_cents'] ) ) { $rows[] = array( 'kind' => 'amount', 'label' => 'Neto imponible', 'amount' => $s['taxable_base_cents'] ?? $s['net_cents'], 'height' => 23 ); }
        $rates = (array) ( $s['tax_rates'] ?? array() );
        $tax_label = count( $rates ) === 1 ? 'IVA ' . number_format( (int) reset( $rates ) / 100, 2, ',', '.' ) . '%' : 'IVA / impuestos';
        if ( isset( $s['tax_cents'] ) ) { $rows[] = array( 'kind' => 'amount', 'label' => $tax_label, 'amount' => $s['tax_cents'], 'height' => 23 ); }
        $rows[] = array( 'kind' => 'total', 'label' => 'Total', 'amount' => $s['total_cents'], 'height' => 48 );
        if ( isset( $s['deposit_percent'] ) ) {
            $deposit = (int) round( (int) $s['total_cents'] * (int) $s['deposit_percent'] / 100 );
            $pending_fiscal = 'pending' === ( $s['fiscal_status'] ?? '' );
            $rows[] = array( 'kind' => 'copy', 'text' => ( $pending_fiscal ? 'Seña prevista: ' : 'Seña disponible: ' ) . $s['deposit_percent'] . '% (' . $currency . ' ' . self::amount( $deposit ) . ').', 'height' => 20 );
            $rows[] = array( 'kind' => 'copy', 'text' => 'Saldo si elegís seña: ' . $currency . ' ' . self::amount( (int) $s['total_cents'] - $deposit ) . '.', 'height' => 20 );
            $rows[] = array( 'kind' => 'copy', 'text' => $pending_fiscal ? 'Pago sujeto a confirmación de los datos de facturación.' : 'El pago se confirma desde el portal.', 'height' => 20 );
        }
        $rows[] = array( 'kind' => 'gap', 'height' => 16 );
        $rows[] = array( 'kind' => 'heading', 'text' => 'Condiciones de la propuesta', 'height' => 24 );
        $conditions = array();
        if ( 'pending' === ( $s['fiscal_status'] ?? '' ) ) { $conditions[] = 'Propuesta comercial. Datos de facturación pendientes de confirmación.'; }
        if ( 'C' === ( $s['billing']['resolution']['document_type'] ?? '' ) ) { $conditions[] = 'IVA no discriminado según configuración fiscal del emisor.'; }
        if ( ! empty( $s['valid_until'] ) ) { $conditions[] = 'Vigencia hasta el ' . implode( '/', array_reverse( explode( '-', $s['valid_until'] ) ) ) . '.'; }
        foreach ( array( 'payment_terms', 'delivery_terms', 'notes_customer' ) as $key ) { if ( ! empty( $s[ $key ] ) ) { $conditions[] = $s[ $key ]; } }
        foreach ( (array) ( $s['discounts'] ?? array() ) as $discount_detail ) {
            if ( ! empty( $discount_detail['reason'] ) ) { $conditions[] = 'Descuento: ' . $discount_detail['reason']; }
        }
        foreach ( $conditions as $text ) { foreach ( self::wrap( $text, 510, 9.5 ) as $line ) { $rows[] = array( 'kind' => 'copy', 'text' => $line, 'height' => 15 ); } }
        $rows[] = array( 'kind' => 'gap', 'height' => 20 );
        $rows[] = array( 'kind' => 'heading', 'text' => 'Revisá tu presupuesto online', 'height' => 24 );
        $rows[] = array( 'kind' => 'copy', 'text' => 'Ingresá con tu cuenta para revisar la propuesta, aceptarla y seguir el trabajo.', 'height' => 17 );
        $url = GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $quote['id'] ) );
        // portal_url can carry preview nonces. Build an account-login URL without credentials.
        $url = remove_query_arg( array( 'ge_preview_customer', 'ge_preview_token' ), $url );
        foreach ( self::wrap( $url, 510, 9 ) as $line ) { $rows[] = array( 'kind' => 'copy', 'text' => $line, 'height' => 15 ); }
        $chunks = array(); $current = array(); $height = 0;
        foreach ( $rows as $index => $row ) {
            $reserve = $row['height'];
            if ( in_array( $row['kind'], array( 'heading', 'table', 'amount' ), true ) && isset( $rows[ $index + 1 ] ) ) { $reserve += $rows[ $index + 1 ]['height']; }
            if ( $height + $reserve > 590 && $current ) {
                $chunks[] = $current; $current = array(); $height = 0;
                if ( 'item' === $row['kind'] ) { $current[] = array( 'kind' => 'table', 'height' => 28 ); $height = 28; }
            }
            $current[] = $row; $height += $row['height'];
        }
        if ( $current ) { $chunks[] = $current; }
        $pages = array();
        foreach ( $chunks as $page => $chunk ) {
            $pdf->begin_page();
            $pdf->brand_symbol( 38, 32, 38 );
            $pdf->text( 86, 57, 21, 'GRAPHEX', true, 17, 24, 39 );
            $pdf->text( 87, 74, 8, 'SOLUCIONES GRÁFICAS', false, 105, 115, 134 );
            $pdf->text_right( 557, 48, 11, 'Presupuesto ' . $quote['number'], true, 17, 24, 39 );
            $date = ! empty( $s['created_at'] ) ? wp_date( 'd/m/Y', strtotime( $s['created_at'] ) ) : '';
            $pdf->text_right( 557, 67, 9, $date . '  ·  Versión ' . $quote['version'], false, 105, 115, 134 );
            $pdf->line( 38, 94, 557, 94, 109, 69, 239, 2 );
            $pdf->text( 38, 121, 9, $page ? 'PROPUESTA COMERCIAL · CONTINUACIÓN' : 'PROPUESTA COMERCIAL · CLIENTE', true, 109, 69, 239 );
            $top = 147;
            foreach ( $chunk as $row ) {
                $kind = $row['kind'];
                if ( 'table' === $kind ) {
                    $pdf->fill_rect( 38, $top - 12, 519, 25, 244, 245, 248 );
                    $pdf->text( 46, $top + 4, 8, 'DESCRIPCIÓN / DETALLE', true, 105, 115, 134 );
                    $pdf->text_right( 382, $top + 4, 8, 'CANT.', true, 105, 115, 134 );
                    $pdf->text_right( 467, $top + 4, 8, ! empty( $s['tax_cents'] ) ? 'UNIT. NETO' : 'UNITARIO', true, 105, 115, 134 );
                    $pdf->text_right( 551, $top + 4, 8, 'SUBTOTAL', true, 105, 115, 134 );
                } elseif ( 'item' === $kind ) {
                    $pdf->text( 46, $top, 9.5, $row['text'], $row['first'], 17, 24, 39 );
                    if ( $row['first'] ) {
                        $item = $row['item'];
                        $pdf->text_right( 382, $top, 8, (string) $item['quantity'], false, 17, 24, 39 );
                        $pdf->text_right( 467, $top, 8, self::amount( $item['unit_net_cents'] ), false, 17, 24, 39 );
                        $pdf->text_right( 551, $top, 8, self::amount( $item['net_cents'] ), true, 17, 24, 39 );
                    }
                } elseif ( 'rule' === $kind ) { $pdf->line( 38, $top, 557, $top, 231, 234, 240 );
                } elseif ( 'amount' === $kind || 'total' === $kind ) {
                    if ( 'total' === $kind ) { $pdf->fill_rect( 280, $top - 10, 277, 36, 109, 69, 239 ); }
                    $white = 'total' === $kind ? 255 : 17;
                    $pdf->text( 292, $top + 10, 'total' === $kind ? 13 : 10, $row['label'], 'total' === $kind, $white, $white, $white );
                    $pdf->text_right( 545, $top + 10, 'total' === $kind ? 13 : 10, $currency . ' ' . self::amount( $row['amount'] ), true, $white, $white, $white );
                } elseif ( 'gap' !== $kind ) {
                    $pdf->text( 38, $top, 'heading' === $kind ? 12 : ( 'client' === $kind ? 10 : 9.5 ), $row['text'], 'heading' === $kind, 17, 24, 39 );
                }
                $top += $row['height'];
            }
            $pdf->line( 38, 781, 557, 781, 231, 234, 240 );
            $pdf->text( 38, 801, 8, 'GRAPHEX · graphex.ar', true, 105, 115, 134 );
            $contact = class_exists( 'GE_WTP_Notification_Center' ) ? ( GE_WTP_Notification_Center::settings()['sender_email'] ?? '' ) : '';
            if ( $contact ) { $pdf->text( 38, 817, 8, $contact, false, 105, 115, 134 ); }
            $pdf->text_right( 557, 801, 8, $quote['number'] . ' · Página ' . ( $page + 1 ) . ' de ' . count( $chunks ), false, 105, 115, 134 );
            $pages[] = $pdf->end_page();
        }
        return $pdf->output( $pages );
    }

    private static function amount( $cents ) { return number_format( (int) $cents / 100, 2, ',', '.' ); }

    /** Wrap at actual Helvetica widths, including unbroken URLs and identifiers. */
    private static function wrap( $text, $width, $size ) {
        $lines = array();
        foreach ( preg_split( '/\R/u', html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) ) as $paragraph ) {
            $line = '';
            foreach ( preg_split( '/\s+/u', trim( $paragraph ) ) as $word ) {
                while ( GE_WTP_Simple_PDF::width( $word, $size, true ) > $width ) {
                    if ( $line !== '' ) { $lines[] = $line; $line = ''; }
                    $limit = 1;
                    while ( $limit < mb_strlen( $word ) && GE_WTP_Simple_PDF::width( mb_substr( $word, 0, $limit + 1 ), $size, true ) <= $width ) { $limit++; }
                    $lines[] = mb_substr( $word, 0, $limit ); $word = mb_substr( $word, $limit );
                }
                if ( GE_WTP_Simple_PDF::width( $line . ' ' . $word, $size, true ) > $width && $line !== '' ) { $lines[] = $line; $line = ''; }
                $line = $line === '' ? $word : $line . ' ' . $word;
            }
            $lines[] = $line;
        }
        return $lines;
    }
}
