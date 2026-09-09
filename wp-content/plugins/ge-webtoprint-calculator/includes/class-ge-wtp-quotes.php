<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Presupuestos PDF descargables para carritos y pedidos, sin dependencias externas. */
final class GE_WTP_Quotes {
    const CART_ACTION  = 'ge_quote_cart_pdf';
    const STORE_ACTION = 'ge_quote_store_cart_pdf';
    const ORDER_ACTION = 'ge_quote_order_pdf';

    public static function init() {
        add_action( 'admin_post_' . self::CART_ACTION, array( __CLASS__, 'download_cart' ) );
        add_action( 'admin_post_' . self::STORE_ACTION, array( __CLASS__, 'download_store_cart' ) );
        add_action( 'admin_post_' . self::ORDER_ACTION, array( __CLASS__, 'download_order' ) );
        add_action( 'woocommerce_proceed_to_checkout', array( __CLASS__, 'render_store_cart_button' ), 15 );
    }

    public static function cart_url() {
        return wp_nonce_url( add_query_arg( 'action', self::CART_ACTION, admin_url( 'admin-post.php' ) ), self::CART_ACTION );
    }

    public static function order_url( $order_id ) {
        $order_id = absint( $order_id );
        return wp_nonce_url( add_query_arg( array( 'action' => self::ORDER_ACTION, 'order_id' => $order_id ), admin_url( 'admin-post.php' ) ), self::ORDER_ACTION . '_' . $order_id );
    }

    public static function store_cart_url() {
        return wp_nonce_url( add_query_arg( 'action', self::STORE_ACTION, admin_url( 'admin-post.php' ) ), self::STORE_ACTION );
    }

    public static function render_store_cart_button() {
        if ( is_user_logged_in() && function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
            echo '<a class="button wc-forward ge-download-quote" href="' . esc_url( self::store_cart_url() ) . '">Descargar presupuesto PDF</a>';
        }
    }

    public static function download_cart() {
        check_admin_referer( self::CART_ACTION );
        if ( ! is_user_logged_in() || ! current_user_can( 'ge_access_markcom_portal' ) ) { wp_die( 'No tenés permiso para descargar este presupuesto.', 403 ); }
        $cart = GE_WTP_Orders::cart();
        $rate = (float) GE_WTP_Catalog::exchange_rate();
        if ( ! $cart || $rate <= 0 ) { wp_die( 'El carrito está vacío o todavía no hay un tipo de cambio válido.', 400 ); }
        self::send_pdf( self::cart_quote( $cart, $rate ), 'presupuesto-graph-express-borrador.pdf' );
    }

    public static function download_order() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        check_admin_referer( self::ORDER_ACTION . '_' . $order_id );
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
        if ( ! $order || ! GE_WTP_Documents::can_access_order( $order ) ) { wp_die( 'No tenés permiso para descargar este presupuesto.', 403 ); }
        $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order_id;
        self::send_pdf( self::order_quote( $order ), 'presupuesto-' . sanitize_file_name( $reference ) . '.pdf' );
    }

    public static function download_store_cart() {
        check_admin_referer( self::STORE_ACTION );
        if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) { wp_die( 'Iniciá sesión y agregá productos antes de descargar el presupuesto.', 400 ); }
        self::send_pdf( self::store_cart_quote( WC()->cart ), 'presupuesto-graph-express-carrito.pdf' );
    }

    /** Público para las pruebas automáticas de renderizado. */
    public static function build_pdf( $quote ) {
        $document = new GE_WTP_Simple_PDF();
        return $document->output( self::layout_pages( $quote, $document ) );
    }

    private static function send_pdf( $quote, $filename ) {
        $pdf = self::build_pdf( $quote );
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function cart_quote( $cart, $rate ) {
        $user = wp_get_current_user(); $totals = GE_WTP_Orders::totals( $cart, $rate ); $items = array();
        foreach ( $cart as $line ) {
            $product = GE_WTP_Catalog::get( $line['product_key'] ); $tier = absint( $line['tier'] );
            if ( ! $product || ! isset( $product['prices'][ $tier ] ) ) { continue; }
            $unit = GE_WTP_Catalog::ars_to_usd( (float) $product['prices'][ $tier ], $rate );
            $items[] = array( 'description' => $product['name'] . ( ! empty( $line['notes'] ) ? "\n" . $line['notes'] : '' ), 'quantity' => $tier, 'unit' => $unit, 'subtotal' => round( $unit * $tier, 2 ) );
        }
        return array(
            'number' => 'BORRADOR-' . $user->ID . '-' . wp_date( 'Ymd' ), 'date' => wp_date( 'd/m/Y' ), 'customer' => self::user_customer( $user ), 'items' => $items,
            'summary' => array( array( 'label' => 'Subtotal', 'amount' => $totals['subtotal_usd'] ), array( 'label' => 'IVA 21%', 'amount' => $totals['tax_usd'] ) ),
            'total' => $totals['total_usd'], 'currency' => 'USD',
            'notes' => 'Presupuesto generado desde el carrito. No constituye una orden de compra hasta confirmar el pedido.',
            'conditions' => array( 'Valores expresados en dólares estadounidenses. IVA incluido según detalle.', 'Pago mediante Purchase Order a 30 días.', 'Conversión de referencia: dólar vendedor BNA, ARS ' . number_format_i18n( $rate, 2 ) . ' por USD.', 'Validez del presupuesto: 10 días corridos. Plazo de producción: según producto y disponibilidad.' ),
        );
    }

    private static function order_quote( $order ) {
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $quantity = max( 1, (float) $item->get_quantity() ); $details = array();
            foreach ( $item->get_formatted_meta_data( '' ) as $meta ) {
                $label = wp_strip_all_tags( $meta->display_key );
                if ( in_array( $label, array( 'Precio unitario ARS', 'Tipo de cambio' ), true ) ) { continue; }
                $details[] = $label . ': ' . wp_strip_all_tags( $meta->display_value );
            }
            $items[] = array( 'description' => $item->get_name() . ( $details ? "\n" . implode( ' · ', $details ) : '' ), 'quantity' => $quantity, 'unit' => (float) $item->get_total() / $quantity, 'subtotal' => (float) $item->get_total() );
        }
        $summary = array( array( 'label' => 'Subtotal', 'amount' => (float) $order->get_subtotal() ) );
        if ( (float) $order->get_discount_total() ) { $summary[] = array( 'label' => 'Descuento', 'amount' => -1 * (float) $order->get_discount_total() ); }
        foreach ( $order->get_items( 'fee' ) as $fee ) { $summary[] = array( 'label' => $fee->get_name(), 'amount' => (float) $fee->get_total() ); }
        if ( (float) $order->get_shipping_total() ) { $summary[] = array( 'label' => 'Envío', 'amount' => (float) $order->get_shipping_total() ); }
        if ( (float) $order->get_total_tax() ) { $summary[] = array( 'label' => 'Impuestos', 'amount' => (float) $order->get_total_tax() ); }
        $is_markcom = 'yes' === $order->get_meta( '_ge_markcom_order' );
        $conditions = array( 'Valores expresados en ' . ( 'USD' === $order->get_currency() ? 'dólares estadounidenses' : 'pesos argentinos' ) . '. Impuestos incluidos según detalle.', 'Forma de pago: ' . ( $order->get_payment_method_title() ?: ( $is_markcom ? 'Purchase Order a 30 días' : 'a coordinar' ) ) . '.', 'Validez del presupuesto: 10 días corridos. Plazo de producción y entrega: a coordinar según el trabajo.' );
        $rate = (float) $order->get_meta( '_ge_markcom_exchange_rate' );
        if ( $is_markcom && $rate > 0 ) { $conditions[] = 'Tipo de cambio de referencia al emitir el pedido: ARS ' . number_format_i18n( $rate, 2 ) . ' por USD.'; }
        return array( 'number' => class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id(), 'date' => wp_date( 'd/m/Y' ), 'customer' => self::order_customer( $order ), 'items' => $items, 'summary' => $summary, 'total' => (float) $order->get_total(), 'currency' => $order->get_currency(), 'notes' => $order->get_customer_note() ?: $order->get_meta( '_ge_markcom_notes' ), 'conditions' => $conditions );
    }

    private static function store_cart_quote( $cart ) {
        $items = array();
        foreach ( $cart->get_cart() as $line ) {
            $product = isset( $line['data'] ) ? $line['data'] : false; $quantity = max( 1, (float) ( $line['quantity'] ?? 1 ) );
            if ( ! $product || ! is_a( $product, 'WC_Product' ) ) { continue; }
            $details = array();
            if ( ! empty( $line['variation'] ) && is_array( $line['variation'] ) ) { foreach ( $line['variation'] as $label => $value ) { $details[] = wc_attribute_label( str_replace( 'attribute_', '', $label ), $product ) . ': ' . $value; } }
            $items[] = array( 'description' => $product->get_name() . ( $details ? "\n" . implode( ' · ', $details ) : '' ), 'quantity' => $quantity, 'unit' => (float) $line['line_total'] / $quantity, 'subtotal' => (float) $line['line_total'] );
        }
        $totals = $cart->get_totals(); $summary = array( array( 'label' => 'Subtotal', 'amount' => (float) ( $totals['subtotal'] ?? 0 ) ) );
        if ( ! empty( $totals['discount_total'] ) ) { $summary[] = array( 'label' => 'Descuento', 'amount' => -1 * (float) $totals['discount_total'] ); }
        if ( ! empty( $totals['shipping_total'] ) ) { $summary[] = array( 'label' => 'Envío', 'amount' => (float) $totals['shipping_total'] ); }
        $tax = (float) ( $totals['total_tax'] ?? ( ( $totals['cart_contents_tax'] ?? 0 ) + ( $totals['shipping_tax'] ?? 0 ) + ( $totals['fee_tax'] ?? 0 ) ) );
        if ( $tax ) { $summary[] = array( 'label' => 'Impuestos', 'amount' => $tax ); }
        $user = wp_get_current_user(); $currency = get_woocommerce_currency();
        return array( 'number' => 'BORRADOR-GE-' . $user->ID . '-' . wp_date( 'Ymd' ), 'date' => wp_date( 'd/m/Y' ), 'customer' => self::user_customer( $user ), 'items' => $items, 'summary' => $summary, 'total' => (float) ( $totals['total'] ?? 0 ), 'currency' => $currency, 'notes' => 'Presupuesto generado desde el carrito. Los archivos, el envío y la forma de pago se confirman al finalizar el pedido.', 'conditions' => array( 'Valores expresados en ' . ( 'ARS' === $currency ? 'pesos argentinos' : $currency ) . '. Impuestos incluidos según detalle.', 'Validez del presupuesto: 10 días corridos.', 'Plazo de producción: según producto, terminaciones y aprobación de archivos.', 'Este documento no reserva producción ni materiales hasta confirmar el pedido.' ) );
    }

    private static function user_customer( $user ) {
        $addresses = class_exists( 'GE_WTP_Customers' ) ? GE_WTP_Customers::addresses( $user->ID ) : array(); $primary = ! empty( $addresses[0] ) ? $addresses[0] : array();
        return array( 'name' => get_user_meta( $user->ID, 'billing_company', true ) ?: $user->display_name, 'contact' => trim( $user->first_name . ' ' . $user->last_name ), 'email' => $user->user_email, 'phone' => get_user_meta( $user->ID, '_ge_whatsapp', true ) ?: get_user_meta( $user->ID, 'billing_phone', true ), 'cuit' => get_user_meta( $user->ID, '_ge_cuit', true ), 'address' => self::address_line( $primary ) );
    }

    private static function order_customer( $order ) {
        $address = array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), $order->get_billing_state(), $order->get_billing_postcode() ) );
        if ( ! $address ) { $address = array_filter( array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city(), $order->get_shipping_state(), $order->get_shipping_postcode() ) ); }
        return array( 'name' => $order->get_billing_company() ?: ( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ), 'contact' => $order->get_billing_company() ? $order->get_formatted_billing_full_name() : '', 'email' => $order->get_billing_email(), 'phone' => $order->get_billing_phone(), 'cuit' => $order->get_meta( '_ge_customer_cuit' ), 'address' => implode( ', ', $address ) );
    }

    private static function address_line( $address ) {
        if ( ! is_array( $address ) ) { return ''; }
        return implode( ', ', array_filter( array( $address['street'] ?? '', $address['city'] ?? '', $address['province'] ?? '', $address['postal_code'] ?? '' ) ) );
    }

    private static function layout_pages( $quote, $pdf ) {
        $pages = array(); $chunks = array_chunk( array_values( $quote['items'] ), 6 ); if ( ! $chunks ) { $chunks = array( array() ); } $page_count = count( $chunks );
        foreach ( $chunks as $page_index => $page_items ) {
            $pdf->begin_page(); self::header( $pdf, $quote, $page_index + 1, $page_count ); $top = 220;
            $pdf->fill_rect( 35, $top, 525, 28, 249, 232, 242 );
            $pdf->text( 43, $top + 18, 9, 'DETALLE', true, 65, 52, 61 ); $pdf->text( 360, $top + 18, 9, 'CANT.', true, 65, 52, 61 ); $pdf->text( 405, $top + 18, 8.5, 'UNIT. (' . $quote['currency'] . ')', true, 65, 52, 61 ); $pdf->text( 493, $top + 18, 8.5, 'TOTAL (' . $quote['currency'] . ')', true, 65, 52, 61 ); $top += 30;
            foreach ( $page_items as $item_index => $item ) {
                $description = $pdf->wrap( $item['description'], 52 ); $height = max( 42, 17 + count( $description ) * 11 );
                if ( 1 === $item_index % 2 ) { $pdf->fill_rect( 35, $top, 525, $height, 251, 250, 252 ); }
                $pdf->stroke_rect( 35, $top, 525, $height, 224, 221, 226 );
                foreach ( $description as $line_number => $line ) { $pdf->text( 43, $top + 15 + $line_number * 11, 8.5, $line, 0 === $line_number, 45, 45, 50 ); }
                $pdf->text_right( 390, $top + 18, 9, self::quantity( $item['quantity'] ), false, 45, 45, 50 ); $pdf->text_right( 466, $top + 18, 8.5, self::amount( $item['unit'] ), false, 45, 45, 50 ); $pdf->text_right( 552, $top + 18, 8.5, self::amount( $item['subtotal'] ), true, 45, 45, 50 ); $top += $height;
            }
            if ( $page_index + 1 === $page_count ) {
                $top += 12;
                foreach ( $quote['summary'] as $line ) { $pdf->text_right( 470, $top + 12, 9, $line['label'], false, 80, 80, 85 ); $pdf->text_right( 552, $top + 12, 9, self::money( $line['amount'], $quote['currency'] ), true, 45, 45, 50 ); $top += 18; }
                $pdf->fill_rect( 318, $top + 2, 242, 40, 249, 232, 242 ); $pdf->fill_rect( 318, $top + 2, 5, 40, 216, 20, 113 ); $pdf->text( 338, $top + 27, 15, 'TOTAL', true, 35, 35, 40 ); $pdf->text_right( 548, $top + 27, 15, self::money( $quote['total'], $quote['currency'] ), true, 35, 35, 40 ); $top += 56;
                $word_lines = $pdf->wrap( 'SON: ' . self::amount_in_words( $quote['total'], $quote['currency'] ), 88 );
                foreach ( $word_lines as $i => $line ) { $pdf->text( 40, $top + 11 + $i * 10, 8.5, $line, true, 62, 62, 67 ); }
                $top += 20 + count( $word_lines ) * 10;
                if ( ! empty( $quote['notes'] ) ) {
                    $note_lines = $pdf->wrap( $quote['notes'], 96 );
                    $pdf->text( 40, $top + 10, 8.5, 'OBSERVACIONES', true, 216, 20, 113 );
                    foreach ( $note_lines as $i => $line ) { $pdf->text( 40, $top + 25 + $i * 10, 8, $line, false, 70, 70, 75 ); }
                    $top += 30 + count( $note_lines ) * 10;
                }
                $pdf->text( 40, $top + 10, 8.5, 'CONDICIONES COMERCIALES', true, 216, 20, 113 );
                foreach ( $quote['conditions'] as $condition ) { foreach ( $pdf->wrap( '• ' . $condition, 105 ) as $line ) { $top += 10; $pdf->text( 40, $top + 11, 7.8, $line, false, 70, 70, 75 ); } }
            }
            $pdf->text( 35, 816, 7.5, 'Graph Express · Av. Pte. Julio A. Roca 570, CABA · 11 5139-3899 · graphexpress.com.ar', false, 115, 115, 120 ); $pages[] = $pdf->end_page();
        }
        return $pages;
    }

    private static function header( $pdf, $quote, $page, $pages ) {
        $pdf->text( 35, 48, 21, 'GRAPH', true, 27, 27, 32 ); $pdf->text( 111, 48, 21, 'EXPRESS', true, 216, 20, 113 ); $pdf->text( 36, 65, 8, 'SOLUCIONES GRÁFICAS', true, 44, 174, 207 );
        $pdf->fill_rect( 326, 24, 234, 43, 249, 232, 242 ); $pdf->fill_rect( 326, 24, 5, 43, 216, 20, 113 ); $pdf->text( 348, 52, 22, 'PRESUPUESTO', true, 216, 20, 113 );
        $pdf->line( 35, 77, 560, 77, 216, 20, 113, 2 );
        $pdf->text( 36, 94, 9, 'Fecha: ' . $quote['date'], false, 95, 95, 100 ); $pdf->text( 240, 94, 9, 'N°: ' . $quote['number'], true, 65, 65, 70 ); if ( $pages > 1 ) { $pdf->text_right( 558, 94, 8, 'Página ' . $page . ' de ' . $pages, false, 95, 95, 100 ); }
        $customer = $quote['customer']; $pdf->fill_rect( 35, 108, 525, 96, 247, 247, 248 ); $pdf->fill_rect( 35, 108, 5, 96, 44, 174, 207 ); $pdf->text( 50, 125, 8, 'CLIENTE', true, 216, 20, 113 ); $pdf->text( 50, 145, 13, $customer['name'] ?: 'Cliente', true, 45, 45, 50 );
        $contact = array_filter( array( $customer['contact'] ? 'Contacto: ' . $customer['contact'] : '', $customer['cuit'] ? 'CUIT: ' . $customer['cuit'] : '' ) );
        $communication = array_filter( array( $customer['email'], $customer['phone'] ) );
        if ( $contact ) { $pdf->text( 50, 163, 8, implode( ' · ', $contact ), false, 80, 80, 85 ); }
        if ( $communication ) { $pdf->text( 50, 179, 8, implode( ' · ', $communication ), false, 80, 80, 85 ); }
        if ( $customer['address'] ) { $pdf->text( 50, 195, 8, 'Dirección: ' . $customer['address'], false, 80, 80, 85 ); }
    }

    private static function money( $amount, $currency ) { return $currency . ' ' . number_format( (float) $amount, 2, ',', '.' ); }
    private static function amount( $amount ) { return number_format( (float) $amount, 2, ',', '.' ); }
    private static function quantity( $quantity ) { return number_format( (float) $quantity, floor( $quantity ) === (float) $quantity ? 0 : 2, ',', '.' ); }
    private static function amount_in_words( $amount, $currency ) { $whole = (int) floor( abs( (float) $amount ) ); $cents = (int) round( ( abs( (float) $amount ) - $whole ) * 100 ); $unit = 'USD' === $currency ? ( 1 === $whole ? 'DÓLAR ESTADOUNIDENSE' : 'DÓLARES ESTADOUNIDENSES' ) : ( 1 === $whole ? 'PESO ARGENTINO' : 'PESOS ARGENTINOS' ); return strtoupper( self::number_words( $whole ) . ' ' . $unit . ' CON ' . str_pad( (string) $cents, 2, '0', STR_PAD_LEFT ) . '/100' ); }
    private static function number_words( $number ) {
        if ( 0 === $number ) { return 'cero'; }
        if ( $number >= 1000000000 ) { $part = intdiv( $number, 1000000000 ); return ( 1 === $part ? 'mil millones' : self::number_words( $part ) . ' mil millones' ) . ( $number % 1000000000 ? ' ' . self::number_words( $number % 1000000000 ) : '' ); }
        if ( $number >= 1000000 ) { $part = intdiv( $number, 1000000 ); return ( 1 === $part ? 'un millón' : self::number_words( $part ) . ' millones' ) . ( $number % 1000000 ? ' ' . self::number_words( $number % 1000000 ) : '' ); }
        if ( $number >= 1000 ) { $part = intdiv( $number, 1000 ); return ( 1 === $part ? 'mil' : self::number_words( $part ) . ' mil' ) . ( $number % 1000 ? ' ' . self::number_words( $number % 1000 ) : '' ); }
        $hundreds = array( 1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos', 6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos' ); if ( 100 === $number ) { return 'cien'; } if ( $number >= 100 ) { return $hundreds[ intdiv( $number, 100 ) ] . ( $number % 100 ? ' ' . self::number_words( $number % 100 ) : '' ); }
        $special = array( 0 => 'cero', 1 => 'uno', 2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco', 6 => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve', 10 => 'diez', 11 => 'once', 12 => 'doce', 13 => 'trece', 14 => 'catorce', 15 => 'quince', 16 => 'dieciséis', 17 => 'diecisiete', 18 => 'dieciocho', 19 => 'diecinueve', 20 => 'veinte', 21 => 'veintiuno', 22 => 'veintidós', 23 => 'veintitrés', 24 => 'veinticuatro', 25 => 'veinticinco', 26 => 'veintiséis', 27 => 'veintisiete', 28 => 'veintiocho', 29 => 'veintinueve' ); if ( isset( $special[ $number ] ) ) { return $special[ $number ]; } $tens = array( 3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa' ); return $tens[ intdiv( $number, 10 ) ] . ( $number % 10 ? ' y ' . $special[ $number % 10 ] : '' );
    }
}

final class GE_WTP_Simple_PDF {
    private $commands = array();
    public function begin_page() { $this->commands = array(); }
    public function end_page() { return implode( "\n", $this->commands ); }
    public function text( $x, $top, $size, $text, $bold = false, $r = 0, $g = 0, $b = 0 ) { $encoded = $this->encode( $text ); $this->commands[] = sprintf( 'BT /%s %.2F Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET', $bold ? 'F2' : 'F1', $size, $r / 255, $g / 255, $b / 255, $x, 842 - $top, $encoded ); }
    public function text_right( $right, $top, $size, $text, $bold = false, $r = 0, $g = 0, $b = 0 ) { $width = strlen( $this->encode( $text ) ) * $size * ( $bold ? 0.57 : 0.50 ); $this->text( $right - $width, $top, $size, $text, $bold, $r, $g, $b ); }
    public function fill_rect( $x, $top, $width, $height, $r, $g, $b ) { $this->commands[] = sprintf( '%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f', $r / 255, $g / 255, $b / 255, $x, 842 - $top - $height, $width, $height ); }
    public function stroke_rect( $x, $top, $width, $height, $r, $g, $b ) { $this->commands[] = sprintf( '%.3F %.3F %.3F RG 0.6 w %.2F %.2F %.2F %.2F re S', $r / 255, $g / 255, $b / 255, $x, 842 - $top - $height, $width, $height ); }
    public function line( $x1, $top1, $x2, $top2, $r, $g, $b, $width = 1 ) { $this->commands[] = sprintf( '%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S', $r / 255, $g / 255, $b / 255, $width, $x1, 842 - $top1, $x2, 842 - $top2 ); }
    public function wrap( $text, $limit ) { $paragraphs = preg_split( '/\R/u', (string) $text ); $lines = array(); foreach ( $paragraphs as $paragraph ) { $words = preg_split( '/\s+/u', trim( $paragraph ) ); $line = ''; foreach ( $words as $word ) { if ( '' === $word ) { continue; } $candidate = '' === $line ? $word : $line . ' ' . $word; if ( mb_strlen( $candidate ) > $limit && '' !== $line ) { $lines[] = $line; $line = $word; } else { $line = $candidate; } } if ( '' !== $line ) { $lines[] = $line; } } return $lines ?: array( '' ); }
    public function output( $pages ) {
        $objects = array( 1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>' ); $kids = array(); $number = 5;
        foreach ( $pages as $commands ) { $content = $number++; $page = $number++; $objects[ $content ] = '<< /Length ' . strlen( $commands ) . ">>\nstream\n" . $commands . "\nendstream"; $objects[ $page ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content . ' 0 R >>'; $kids[] = $page . ' 0 R'; }
        $objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>'; ksort( $objects ); $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = array( 0 );
        foreach ( $objects as $index => $object ) { $offsets[ $index ] = strlen( $pdf ); $pdf .= $index . " 0 obj\n" . $object . "\nendobj\n"; }
        $xref = strlen( $pdf ); $count = count( $objects ) + 1; $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n"; for ( $i = 1; $i < $count; $i++ ) { $pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] ); } return $pdf . "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }
    private function encode( $text ) { $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ); $encoded = function_exists( 'iconv' ) ? iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text ) : $text; return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), false === $encoded ? $text : $encoded ); }
}
