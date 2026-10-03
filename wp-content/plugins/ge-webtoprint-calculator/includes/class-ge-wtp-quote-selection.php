<?php
defined( 'ABSPATH' ) || exit;

/** Customer choices reference frozen staff prices. Preview never writes commercial data. */
final class GE_WTP_Quote_Selection {
    const META = '_ge_commercial_selection';

    public static function has_choices( $snapshot ) {
        foreach ( $snapshot['items'] ?? array() as $item ) {
            if ( in_array( $item['selection_type'] ?? '', array( 'alternative', 'optional' ), true ) ) { return true; }
        }
        return false;
    }

    public static function apply( $quote, $input, $version ) {
        if ( (int) $version !== (int) $quote['version'] ) { return new WP_Error( 'ge_selection_version', 'El presupuesto cambió. Recargá para elegir las opciones actuales.' ); }
        $s = $quote['snapshot'];
        if ( ! self::has_choices( $s ) ) { return $quote; }
        if ( ! is_array( $input ) || count( $input ) > 30 ) { return new WP_Error( 'ge_selection_invalid', 'Revisá tu selección.' ); }
        $ids = array_values( $input );
        foreach ( $ids as $id ) { if ( ! is_string( $id ) || ! GE_WTP_Quote_Artwork_V2::uuid( $id ) ) { return new WP_Error( 'ge_selection_invalid', 'Opción inválida.' ); } }
        if ( count( $ids ) !== count( array_unique( $ids ) ) ) { return new WP_Error( 'ge_selection_invalid', 'Una opción está repetida.' ); }
        $known = array_column( $s['items'], 'line_uuid' );
        if ( array_diff( $ids, $known ) ) { return new WP_Error( 'ge_selection_invalid', 'Una opción no pertenece a este presupuesto.' ); }
        $groups = array(); $selected = array();
        foreach ( $s['items'] as $index => $item ) {
            $type = $item['selection_type'] ?? 'required';
            $chosen = in_array( $item['line_uuid'], $ids, true );
            if ( 'required' === $type ) { $chosen = true; }
            if ( 'alternative' === $type ) {
                $group = $item['selection_group'] ?? '';
                if ( ! $group ) { return new WP_Error( 'ge_selection_group', 'Solicitá una actualización de las opciones del presupuesto.' ); }
                if ( ! isset( $groups[$group] ) ) { $groups[$group] = 0; }
                if ( $chosen ) { ++$groups[$group]; }
            }
            if ( ! $chosen ) { continue; }
            if ( ! empty( $item['product_id'] ) ) {
                $product = wc_get_product( $item['product_id'] );
                if ( ! $product || 'publish' !== $product->get_status() ) { return new WP_Error( 'ge_selection_unavailable', 'Una opción ya no está disponible. Solicitá una actualización.' ); }
            }
            $selected[] = $item;
        }
        foreach ( $groups as $count ) { if ( 1 !== $count ) { return new WP_Error( 'ge_selection_exclusive', 'Elegí una sola opción de cada grupo de alternativas.' ); } }
        if ( ! $selected ) { return new WP_Error( 'ge_selection_empty', 'Elegí al menos un ítem.' ); }
        // Reapply documented discounts to the chosen scope, with integer-cent rounding.
        $final = 'final' === ( $s['quote_vat_mode'] ?? '' );
        $subtotal = 0; $item_discount = 0; $discounts = array();
        foreach ( $selected as $i => &$item ) {
            $base = (int) ( $final ? ( $item['entered_line_cents'] ?? $item['net_cents'] ) : $item['net_cents'] );
            $discount = $item['discount'] ?? null;
            $amount = (int) ( $discount['amount_cents'] ?? 0 );
            $item['taxable_base_cents'] = $base - $amount;
            $item['discount_cents'] = $amount;
            $subtotal += $base; $item_discount += $amount;
            if ( $discount ) { $discount['item_index'] = $i; $discounts[] = $discount; }
        }
        unset( $item );
        $quote_discount = 0;
        foreach ( $s['discounts'] ?? array() as $d ) {
            if ( 'quote' !== ( $d['discount_scope'] ?? '' ) ) { continue; }
            $d = GE_WTP_Commercial_Quotes::discount( $d, $subtotal - $item_discount, 'quote', $d['applied_by'] ?? 0 );
            if ( is_wp_error( $d ) ) { return $d; }
            if ( $d ) { $quote_discount += $d['amount_cents']; $discounts[] = $d; }
        }
        $base = $subtotal - $item_discount; $remaining_discount = $quote_discount;
        foreach ( $selected as &$item ) {
            $value = $item['taxable_base_cents'];
            $share = $base > 0 ? intdiv( $remaining_discount * $value, $base ) : 0;
            $item['taxable_base_cents'] -= $share; $item['discount_cents'] += $share;
            $base -= $value; $remaining_discount -= $share;
        }
        unset( $item );
        $price = $subtotal - $item_discount - $quote_discount;
        if ( ! isset( $s['tax_cents'], $s['total_cents'] ) ) { return new WP_Error( 'ge_selection_prices', 'Solicitá una actualización de los precios del presupuesto.' ); }
        $rate = (int) ( $s['tax_rates'][0] ?? $s['billing']['resolution']['tax_rate_basis_points'] ?? 0 );
        $net = $final ? intdiv( $price * 10000 + intdiv( 10000 + $rate, 2 ), 10000 + $rate ) : $price;
        $tax = $final ? $price - $net : intdiv( $net * $rate + 5000, 10000 );
        $remaining_price = $price; $remaining_net = $net; $remaining_tax = $tax;
        foreach ( $selected as &$item ) {
            $value = (int) $item['taxable_base_cents'];
            $line_net = $final ? ( $remaining_price > 0 ? intdiv( $remaining_net * $value, $remaining_price ) : 0 ) : $value;
            $line_tax = $final ? $value - $line_net : ( $remaining_net > 0 ? intdiv( $remaining_tax * $line_net, $remaining_net ) : 0 );
            $item['taxable_base_cents'] = $line_net; $item['tax_cents'] = $line_tax; $item['total_cents'] = $line_net + $line_tax;
            if ( $final ) { $item['final_line_cents'] = $value; $item['net_cents'] = $line_net; $item['discount_cents'] = 0; }
            $remaining_price -= $value; $remaining_net -= $line_net; $remaining_tax -= $line_tax;
        }
        unset( $item );
        $s['items'] = $selected; $s['subtotal_cents'] = $final ? $net : $subtotal;
        $s['discount_cents'] = $final ? 0 : $item_discount + $quote_discount;
        $s['net_cents'] = $s['taxable_base_cents'] = $net;
        $s['tax_cents'] = $tax; $s['total_cents'] = $net + $tax; $s['discounts'] = $discounts;
        if ( $final ) { $s['entered_subtotal_cents'] = $subtotal; $s['entered_discount_cents'] = $item_discount + $quote_discount; }
        if ( ! empty( $s['billing']['resolution'] ) ) {
            $s['billing']['resolution']['subtotal_cents'] = $net;
            $s['billing']['resolution']['tax_cents'] = $tax;
            $s['billing']['resolution']['total_cents'] = $net + $tax;
        }
        $s['customer_selection'] = array( 'version' => (int) $version, 'line_ids' => array_column( $selected, 'line_uuid' ) );
        unset( $s['snapshot_hash'] ); $s['snapshot_hash'] = GE_WTP_Quote_Billing_Control::hash( $s );
        $quote['snapshot'] = $s;
        return $quote;
    }

    public static function effective( $quote ) {
        if ( ! in_array( $quote['status'], array( 'accepted', 'converted' ), true ) ) { return $quote; }
        $saved = get_post_meta( $quote['id'], self::META, true );
        if ( ! is_array( $saved ) || (int) ( $saved['version'] ?? 0 ) !== $quote['version'] || empty( $saved['snapshot'] ) ) { return $quote; }
        if ( ( $saved['proposal_hash'] ?? '' ) !== GE_WTP_Quote_Billing_Control::hash( $quote['snapshot'] ) ) { return new WP_Error( 'ge_selection_changed', 'El presupuesto cambió. Solicitá una actualización.' ); }
        $quote['snapshot'] = $saved['snapshot'];
        return $quote;
    }

    public static function preview_request( $quote ) {
        if ( ! isset( $_GET['selection_submitted'] ) ) { return $quote; }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['selection_nonce'] ?? '' ) ), 'ge_quote_selection_' . $quote['id'] . '_' . $quote['version'] ) ) { return new WP_Error( 'ge_selection_nonce', 'Recargá el presupuesto para elegir las opciones actuales.' ); }
        return self::apply( $quote, wp_unslash( $_GET['quote_selection'] ?? array() ), absint( $_GET['selection_version'] ?? 0 ) );
    }

    private static function option_price( $item, $snapshot ) {
        $base = (int) ( $item['entered_line_cents'] ?? $item['net_cents'] ) - (int) ( $item['discount']['amount_cents'] ?? 0 );
        if ( 'final' === ( $snapshot['quote_vat_mode'] ?? '' ) ) { return $base; }
        $rate = (int) ( $snapshot['tax_rates'][0] ?? 0 );
        return $base + intdiv( $base * $rate + 5000, 10000 );
    }

    public static function render_choices( $quote, $chosen = array() ) {
        echo '<form method="get" class="ge-quote-selection ge-production-card"><h3>Elegí los ítems de tu presupuesto</h3><p>Elegí una opción por grupo. Los adicionales se pueden seleccionar por separado. Los precios mostrados son finales, antes del descuento general si lo hubiera.</p>';
        foreach ( array( 'presupuesto', 'seccion', 'ge_preview_customer', 'ge_preview_token' ) as $key ) {
            $value = 'presupuesto' === $key ? $quote['id'] : ( $_GET[$key] ?? '' );
            if ( $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
        }
        echo '<input type="hidden" name="selection_submitted" value="1"><input type="hidden" name="selection_version" value="' . esc_attr( $quote['version'] ) . '"><input type="hidden" name="selection_nonce" value="' . esc_attr( wp_create_nonce( 'ge_quote_selection_' . $quote['id'] . '_' . $quote['version'] ) ) . '">';
        foreach ( $quote['snapshot']['items'] as $item ) {
            $type = $item['selection_type'] ?? 'required';
            echo '<div class="ge-quote-item"><label>';
            if ( 'required' !== $type ) {
                $name = 'alternative' === $type ? 'quote_selection[' . $item['selection_group'] . ']' : 'quote_selection[' . $item['line_uuid'] . ']';
                echo '<input type="' . ( 'alternative' === $type ? 'radio' : 'checkbox' ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $item['line_uuid'] ) . '"' . checked( in_array( $item['line_uuid'], $chosen, true ), true, false ) . ( 'alternative' === $type ? ' required' : '' ) . '> ';
            } else { echo '<input type="hidden" name="quote_selection[' . esc_attr( $item['line_uuid'] ) . ']" value="' . esc_attr( $item['line_uuid'] ) . '">Incluido: '; }
            echo '<strong>' . esc_html( $item['name'] ) . '</strong> · ' . esc_html( $item['quantity'] . ' ' . ( $item['unit'] ?? 'u' ) ) . ' · ' . esc_html( GE_WTP_Commercial_Quote_UI::money( self::option_price( $item, $quote['snapshot'] ) ) );
            if ( 'alternative' === $type ) { echo '<span> · Alternativa: ' . esc_html( $item['selection_group'] ) . '</span>'; }
            if ( ! empty( $item['selection_recommended'] ) ) { echo '<span> · Recomendada</span>'; }
            echo '</label>';
            foreach ( array( 'details', 'notes' ) as $key ) { if ( ! empty( $item[$key] ) ) { echo '<p>' . esc_html( $item[$key] ) . '</p>'; } }
            echo '</div>';
        }
        echo '<button type="submit" class="ge-button ge-button-primary">Ver total de mi elección</button><p>Esta vista no acepta el presupuesto ni inicia producción.</p></form>';
    }
}
