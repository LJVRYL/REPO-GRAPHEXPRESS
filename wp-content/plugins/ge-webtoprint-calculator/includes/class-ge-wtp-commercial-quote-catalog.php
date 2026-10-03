<?php

defined( 'ABSPATH' ) || exit;

/** Read-only catalog choices and authoritative prices for staff commercial quotes. */
final class GE_WTP_Commercial_Quote_Catalog {
    public static function describe( $product ) {
        if ( ! $product ) { return array( 'mode' => 'manual' ); }
        $digital = $product->get_meta( '_ge_digital_config', true );
        if ( is_array( $digital ) && ! empty( $digital['fields'] ) ) {
            $fields = array();
            foreach ( $digital['fields'] as $field ) {
                $fields[] = array(
                    'key' => sanitize_key( $field['key'] ?? '' ),
                    'label' => sanitize_text_field( $field['label'] ?? '' ),
                    'type' => sanitize_key( $field['type'] ?? 'select' ),
                    'options' => array_map( function ( $option ) { return array( 'value' => (string) ( $option['value'] ?? '' ), 'label' => (string) ( $option['label'] ?? '' ) ); }, (array) ( $field['options'] ?? array() ) ),
                    'min' => $field['min'] ?? 1,
                    'max' => $field['max'] ?? 10000,
                    'step' => $field['step'] ?? 1,
                    'default' => $field['default'] ?? '',
                );
            }
            return array( 'mode' => 'digital', 'fields' => $fields );
        }
        $config = GE_WTP_Storefront::config( $product->get_id() );
        if ( ! empty( $config['options'] ) ) {
            $options = array();
            foreach ( $config['options'] as $key => $option ) {
                $options[ $key ] = array( 'label' => sanitize_text_field( $option['label'] ?? $key ), 'price' => (float) ( $option['price'] ?? 0 ), 'fixed_qty' => absint( $option['fixed_qty'] ?? 0 ), 'min_qty' => max( 1, absint( $option['min_qty'] ?? $config['min_qty'] ?? 1 ) ), 'step' => max( 1, absint( $option['step'] ?? $config['step'] ?? 1 ) ) );
            }
            return array( 'mode' => 'option', 'label' => sanitize_text_field( $config['label'] ?? 'Configuración' ), 'options' => $options, 'measure' => in_array( $config['mode'] ?? '', array( 'm2', 'ml' ), true ) ? $config['mode'] : '', 'roll_widths_cm' => $config['roll_widths_cm'] ?? array() );
        }
        if ( $product->is_type( 'variable' ) ) {
            $options = array();
            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation || ! $variation->exists() || 'publish' !== $variation->get_status() ) { continue; }
                $labels = array();
                foreach ( $variation->get_attributes() as $key => $value ) { $labels[] = wc_attribute_label( $key ) . ': ' . $value; }
                $options[ (string) $variation_id ] = array( 'label' => implode( ' · ', $labels ) ?: '#' . $variation_id, 'price' => (float) wc_get_price_excluding_tax( $variation ), 'fixed_qty' => 0, 'min_qty' => 1, 'step' => 1 );
            }
            return $options ? array( 'mode' => 'variation', 'label' => 'Variante', 'options' => $options, 'measure' => '' ) : array( 'mode' => 'manual' );
        }
        $price = (float) wc_get_price_excluding_tax( $product );
        return $price > 0 ? array( 'mode' => 'fixed', 'price' => $price ) : array( 'mode' => 'manual' );
    }

    /** Resolve against the current catalog; never trust a submitted calculated price. */
    public static function price( $product_id, $configuration, $quantity ) {
        $product = wc_get_product( absint( $product_id ) );
        if ( ! $product || 'publish' !== $product->get_status() ) { return new WP_Error( 'ge_quote_product', 'Producto no disponible.' ); }
        $config = self::describe( $product );
        $mode = $config['mode'];
        $configuration = is_array( $configuration ) ? $configuration : array();
        if ( 'manual' === $mode ) { return array( 'manual' => true, 'configuration' => array(), 'description' => '' ); }
        if ( 'digital' === $mode ) {
            $result = GE_WTP_Digital_Catalog::commercial_quote_price( $product_id, $configuration );
            if ( is_wp_error( $result ) ) { return $result; }
            return array( 'manual' => $result['price'] <= 0, 'price' => $result['price'], 'quantity' => 1, 'configuration' => $result['values'], 'description' => implode( ' · ', $result['labels'] ) );
        }
        if ( 'fixed' === $mode ) { return array( 'manual' => false, 'price' => $config['price'], 'quantity' => $quantity, 'configuration' => array(), 'description' => '' ); }
        $key = sanitize_text_field( $configuration['option_key'] ?? '' );
        if ( ! isset( $config['options'][ $key ] ) ) { return new WP_Error( 'ge_quote_configuration', 'Elegí una configuración disponible.' ); }
        $option = $config['options'][ $key ];
        $qty = $option['fixed_qty'] ?: $quantity;
        if ( $qty < $option['min_qty'] || ( $qty - $option['min_qty'] ) % $option['step'] ) { return new WP_Error( 'ge_quote_quantity', 'Cantidad fuera de la escala del producto.' ); }
        $price = $option['price'];
        $description = $option['label'];
        $selected = array( 'option_key' => $key );
        if ( 'm2' === $config['measure'] || 'ml' === $config['measure'] ) {
            foreach ( 'm2' === $config['measure'] ? array( 'width', 'height' ) : array( 'length' ) as $dimension ) {
                $value = isset( $configuration[ $dimension ] ) ? (float) str_replace( ',', '.', (string) $configuration[ $dimension ] ) : 0;
                if ( $value <= 0 || $value > 100000 ) { return new WP_Error( 'ge_quote_measure', 'Revisá las medidas del producto.' ); }
                $selected[ $dimension ] = $value;
            }
            $billable_width = $selected['width'] ?? 0;
            if ( 'm2' === $config['measure'] && ! empty( $config['roll_widths_cm'] ) ) {
                $billable_width = GE_WTP_Roll_Pricing::billable_width( $selected['width'], $config['roll_widths_cm'] );
                if ( ! $billable_width ) { return new WP_Error( 'ge_quote_roll_width', 'El ancho excede los rollos disponibles. Cotizá este trabajo en paños.' ); }
                $selected['roll_width_cm'] = $billable_width;
            }
            $factor = 'm2' === $config['measure'] ? $billable_width * $selected['height'] / 10000 : $selected['length'] / 100;
            $price = round( $price * $factor );
            $description = 'm2' === $config['measure'] ? $selected['width'] . ' × ' . $selected['height'] . ' cm · ' . $description : $selected['length'] . ' cm de largo · ' . $description;
            if ( isset( $selected['roll_width_cm'] ) ) { $description .= ' · cálculo: rollo ' . $billable_width . ' cm × ' . $selected['height'] . ' cm'; }
        }
        return array( 'manual' => $price <= 0, 'price' => $price, 'quantity' => $qty, 'configuration' => $selected, 'description' => $description );
    }
}
