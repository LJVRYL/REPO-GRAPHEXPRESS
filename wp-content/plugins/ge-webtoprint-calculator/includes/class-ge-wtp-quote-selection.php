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

    public static function apply( $quote, $input, $version, $configurations = array() ) {
        if ( (int) $version !== (int) $quote['version'] ) { return new WP_Error( 'ge_selection_version', 'El presupuesto cambió. Recargá para elegir las opciones actuales.' ); }
        $s = $quote['snapshot'];
        if ( ! is_array( $configurations ) || count( $configurations ) > 30 ) { return new WP_Error( 'ge_selection_config', 'Configuración inválida.' ); }
        $known_config = array_column( $s['items'], 'line_uuid' );
        if ( array_diff( array_keys( $configurations ), $known_config ) ) { return new WP_Error( 'ge_selection_config', 'Una configuración no pertenece al presupuesto.' ); }
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
            $facet = $item['choice_facets'] ?? array();
            if ( ! empty( $facet['papers'] ) ) {
                $paper = $configurations[$item['line_uuid']]['paper'] ?? '';
                if ( ! is_string( $paper ) || ! in_array( $paper, $facet['papers'], true ) ) { return new WP_Error( 'ge_selection_paper', 'Elegí un papel disponible para el modelo.' ); }
                $item['configuration']['paper'] = $paper;
                $item['configuration']['model'] = $facet['model_label'];
                $item['configuration']['finish'] = $facet['finish_label'];
                $item['configuration_label'] = $facet['model_label'] . ' · ' . $paper . ' · ' . $facet['finish_label'];
                $item['details'] = trim( ( $item['details'] ?? '' ) . ' · ' . $item['configuration_label'], ' ·' );
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
        $s['customer_selection'] = array( 'version' => (int) $version, 'line_ids' => array_column( $selected, 'line_uuid' ), 'configurations' => array_intersect_key( $configurations, array_flip( array_column( $selected, 'line_uuid' ) ) ) );
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
        $ids = wp_unslash( $_GET['quote_selection'] ?? array() );
        $configs = wp_unslash( $_GET['quote_configuration'] ?? array() );
        $facets = wp_unslash( $_GET['choice'] ?? array() );
        if ( ! is_array( $ids ) || ! is_array( $configs ) || ! is_array( $facets ) ) { return new WP_Error( 'ge_selection_config', 'Revisá la configuración.' ); }
        foreach ( $facets as $group => $choice ) {
            if ( ! is_array( $choice ) ) { return new WP_Error( 'ge_selection_config', 'Revisá la configuración.' ); }
            $matches = array();
            foreach ( $quote['snapshot']['items'] as $item ) {
                $f = $item['choice_facets'] ?? array();
                if ( $f && ( $item['selection_group'] ?? '' ) === $group && ( $choice['model'] ?? '' ) === $f['model_key'] && ( $choice['finish'] ?? '' ) === $f['finish_key'] ) { $matches[] = $item; }
            }
            if ( 1 !== count( $matches ) ) { return new WP_Error( 'ge_selection_config', 'La combinación elegida no está disponible.' ); }
            $id = $matches[0]['line_uuid']; $ids[] = $id; $configs[$id] = array( 'paper' => $choice['paper'] ?? '' );
        }
        return self::apply( $quote, $ids, absint( $_GET['selection_version'] ?? 0 ), $configs );
    }

    private static function option_price( $item, $snapshot ) {
        $base = (int) ( $item['entered_line_cents'] ?? $item['net_cents'] ) - (int) ( $item['discount']['amount_cents'] ?? 0 );
        if ( 'final' === ( $snapshot['quote_vat_mode'] ?? '' ) ) { return $base; }
        $rate = (int) ( $snapshot['tax_rates'][0] ?? 0 );
        return $base + intdiv( $base * $rate + 5000, 10000 );
    }

    /** Facets reference complete, frozen-priced lines; the browser cannot supply prices. */
    public static function facets( $input ) {
        if ( ! $input ) { return array(); }
        if ( ! is_array( $input ) ) { return new WP_Error( 'ge_choice_facets', 'Configuración de variantes inválida.' ); }
        $out = array();
        foreach ( array( 'model_key', 'finish_key' ) as $key ) {
            if ( ! is_string( $input[$key] ?? null ) || ! preg_match( '/^[a-z0-9_-]{1,60}$/D', $input[$key] ) ) { return new WP_Error( 'ge_choice_facets', 'Indicá identificadores de modelo y terminación.' ); }
            $out[$key] = $input[$key];
        }
        foreach ( array( 'model_label', 'finish_label' ) as $key ) {
            if ( ! is_string( $input[$key] ?? null ) || ! trim( $input[$key] ) || strlen( $input[$key] ) > 160 ) { return new WP_Error( 'ge_choice_facets', 'Indicá nombres de modelo y terminación.' ); }
            $out[$key] = sanitize_text_field( $input[$key] );
        }
        $papers = $input['papers'] ?? array();
        if ( ! is_array( $papers ) || ! $papers || count( $papers ) > 10 ) { return new WP_Error( 'ge_choice_facets', 'Indicá los papeles disponibles.' ); }
        foreach ( $papers as $paper ) { if ( ! is_string( $paper ) || ! trim( $paper ) || strlen( $paper ) > 120 ) { return new WP_Error( 'ge_choice_facets', 'Papel inválido.' ); } }
        $out['papers'] = array_values( array_unique( array_map( 'sanitize_text_field', $papers ) ) );
        $preview = $input['preview_file_id'] ?? '';
        if ( $preview && ! GE_WTP_Quote_Artwork_V2::uuid( $preview ) ) { return new WP_Error( 'ge_choice_facets', 'Miniatura inválida.' ); }
        $out['preview_file_id'] = $preview;
        return $out;
    }

    public static function selection_args( $quote ) {
        return array( 'selection_submitted' => 1, 'quote_selection' => $quote['snapshot']['customer_selection']['line_ids'] ?? array(), 'quote_configuration' => $quote['snapshot']['customer_selection']['configurations'] ?? array(), 'selection_version' => $quote['version'], 'selection_nonce' => wp_create_nonce( 'ge_quote_selection_' . $quote['id'] . '_' . $quote['version'] ) );
    }

    public static function render_choices( $quote, $chosen = array(), $configurations = array(), $staff = false ) {
        $s = $quote['snapshot']; $groups = array(); $ordinary = array();
        foreach ( $s['items'] as $item ) {
            if ( ! empty( $item['choice_facets'] ) ) { $groups[$item['selection_group']][] = $item; }
            else { $ordinary[] = $item; }
        }
        echo '<form method="get" class="ge-quote-selection ge-production-card" id="ge-quote-configurator" data-ge-choice-form><h3>Configurá tu presupuesto</h3><p>Elegí el modelo, el papel y la terminación. El PDF incluirá únicamente tu selección.</p>';
        $keys = $staff ? array( 'section', 'quote_id' ) : array( 'presupuesto', 'seccion', 'ge_preview_customer', 'ge_preview_token' );
        foreach ( $keys as $key ) {
            $value = 'quote_id' === $key || 'presupuesto' === $key ? $quote['id'] : ( $_GET[$key] ?? '' );
            if ( $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
        }
        if ( ! $staff ) { echo '<input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '">'; }
        echo '<input type="hidden" name="selection_submitted" value="1"><input type="hidden" name="selection_version" value="' . esc_attr( $quote['version'] ) . '"><input type="hidden" name="selection_nonce" value="' . esc_attr( wp_create_nonce( 'ge_quote_selection_' . $quote['id'] . '_' . $quote['version'] ) ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'ge_commercial_quote_pdf_' . $quote['id'] ) ) . '">';
        foreach ( $groups as $group => $items ) {
            $models = array(); $finishes = array(); $papers = array(); $selected = null; $prices = array();
            foreach ( $items as $item ) {
                $f = $item['choice_facets']; $models[$f['model_key']] = $item; $finishes[$f['finish_key']] = $f['finish_label'];
                $papers = array_values( array_unique( array_merge( $papers, $f['papers'] ) ) );
                if ( in_array( $item['line_uuid'], $chosen, true ) ) { $selected = $item; }
                $prices[] = array( 'model' => $f['model_key'], 'finish' => $f['finish_key'], 'papers' => $f['papers'], 'price' => self::option_price( $item, $s ), 'id' => $item['line_uuid'] );
            }
            if ( ! $selected ) { $selected = $items[0]; }
            $f = $selected['choice_facets']; $paper_selected = $configurations[$selected['line_uuid']]['paper'] ?? $f['papers'][0];
            echo '<fieldset data-ge-choice-group="' . esc_attr( $group ) . '"><legend>' . esc_html( $group ) . ' · ' . esc_html( $selected['quantity'] . ' ' . $selected['unit'] ) . '</legend><div class="ge-choice-models">';
            foreach ( $models as $key => $item ) {
                $m = $item['choice_facets'];
                echo '<label class="ge-choice-model"><input type="radio" required name="choice[' . esc_attr( $group ) . '][model]" value="' . esc_attr( $key ) . '"' . checked( $f['model_key'], $key, false ) . '>';
                if ( ! empty( $m['preview_file_id'] ) ) {
                    $file = null; foreach ( GE_WTP_Commercial_Quote_Files::all( $quote['id'] ) as $candidate ) { if ( $candidate['id'] === $m['preview_file_id'] && in_array( $candidate['mime'] ?? '', array( 'image/jpeg', 'image/png' ), true ) ) { $file = $candidate; break; } }
                    if ( $file ) { echo '<img loading="lazy" width="360" height="260" src="' . esc_url( GE_WTP_Commercial_Quote_Files::download_url( $quote['id'], $file['id'], true ) ) . '" alt="' . esc_attr( $m['model_label'] ) . '">'; }
                }
                echo '<strong>' . esc_html( $m['model_label'] ) . '</strong>';
                if ( ! empty( $item['selection_recommended'] ) ) { echo '<span class="ge-choice-recommended">Recomendado</span>'; }
                if ( ! empty( $item['notes'] ) ) { echo '<span>' . esc_html( $item['notes'] ) . '</span>'; }
                echo '</label>';
            }
            echo '</div><div class="ge-choice-options"><label>Terminación<select required name="choice[' . esc_attr( $group ) . '][finish]">';
            foreach ( $finishes as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $f['finish_key'], $key, false ) . '>' . esc_html( $label ) . '</option>'; }
            echo '</select></label><label>Papel<select required name="choice[' . esc_attr( $group ) . '][paper]">';
            foreach ( $papers as $paper ) { echo '<option' . selected( $paper_selected, $paper, false ) . '>' . esc_html( $paper ) . '</option>'; }
            echo '</select></label></div><script type="application/json" data-ge-choice-prices>' . wp_json_encode( $prices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script><output class="ge-choice-total" aria-live="polite" data-ge-choice-total></output></fieldset>';
        }
        foreach ( $ordinary as $item ) {
            $type = $item['selection_type'] ?? 'required';
            echo '<div class="ge-quote-item"><label>';
            if ( 'required' !== $type ) {
                $name = 'alternative' === $type ? 'quote_selection[' . $item['selection_group'] . ']' : 'quote_selection[' . $item['line_uuid'] . ']';
                echo '<input type="' . ( 'alternative' === $type ? 'radio' : 'checkbox' ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $item['line_uuid'] ) . '"' . checked( in_array( $item['line_uuid'], $chosen, true ), true, false ) . ( 'alternative' === $type ? ' required' : '' ) . '> ';
            } else { echo '<input type="hidden" name="quote_selection[' . esc_attr( $item['line_uuid'] ) . ']" value="' . esc_attr( $item['line_uuid'] ) . '">Incluido: '; }
            echo '<strong>' . esc_html( $item['name'] ) . '</strong> · ' . esc_html( $item['quantity'] . ' ' . ( $item['unit'] ?? 'u' ) ) . ' · ' . esc_html( GE_WTP_Commercial_Quote_UI::money( self::option_price( $item, $s ) ) ) . '</label>';
            foreach ( array( 'details', 'notes' ) as $key ) { if ( ! empty( $item[$key] ) ) { echo '<p>' . esc_html( $item[$key] ) . '</p>'; } }
            echo '</div>';
        }
        echo '<div class="ge-choice-actions"><button type="submit" class="ge-button ge-staff-button is-secondary">Ver selección y total</button><button type="submit" name="action" value="ge_commercial_quote_pdf" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ge-button ge-staff-button">Exportar PDF de mi selección</button></div><p>Podés exportar el PDF sin aceptar ni pagar el presupuesto. Los importes por opción se muestran antes del descuento general, si lo hubiera.</p></form>';
    }
}
