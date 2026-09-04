<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Orders {
    const CART_META = '_ge_markcom_cart';

    public static function cart() {
        $cart = get_user_meta( get_current_user_id(), self::CART_META, true );
        return is_array( $cart ) ? $cart : array();
    }

    public static function set_cart( $cart ) {
        update_user_meta( get_current_user_id(), self::CART_META, array_values( $cart ) );
    }

    public static function add_to_cart( $product_key, $tier, $notes = '' ) {
        $product = GE_WTP_Catalog::get( $product_key );
        if ( ! $product || ! isset( $product['prices'][ $tier ] ) ) {
            return new WP_Error( 'invalid_product', 'El producto o la cantidad seleccionada no son válidos.' );
        }

        $cart = self::cart();
        $line_key = sanitize_key( $product_key . '-' . $tier );
        $cart[ $line_key ] = array(
            'line_key'    => $line_key,
            'product_key' => $product_key,
            'tier'        => (int) $tier,
            'notes'       => sanitize_textarea_field( $notes ),
        );
        self::set_cart( $cart );

        return true;
    }

    public static function remove_from_cart( $line_key ) {
        $cart = self::cart();
        foreach ( $cart as $index => $line ) {
            if ( isset( $line['line_key'] ) && hash_equals( (string) $line['line_key'], (string) $line_key ) ) {
                unset( $cart[ $index ] );
            }
        }
        self::set_cart( $cart );
    }

    public static function totals( $cart = null, $rate = null ) {
        $cart = null === $cart ? self::cart() : $cart;
        $rate = null === $rate ? GE_WTP_Catalog::exchange_rate() : (float) $rate;
        $subtotal_ars = 0;

        foreach ( $cart as $line ) {
            $product = GE_WTP_Catalog::get( $line['product_key'] );
            $tier = (int) $line['tier'];
            if ( $product && isset( $product['prices'][ $tier ] ) ) {
                $subtotal_ars += (float) $product['prices'][ $tier ] * $tier;
            }
        }

        $subtotal_usd = GE_WTP_Catalog::ars_to_usd( $subtotal_ars, $rate );
        $tax_usd = round( $subtotal_usd * 0.21, 2 );

        return array(
            'subtotal_ars' => $subtotal_ars,
            'subtotal_usd' => $subtotal_usd,
            'tax_usd'      => $tax_usd,
            'total_usd'    => $subtotal_usd + $tax_usd,
        );
    }

    public static function create_order( $po_reference, $notes ) {
        if ( ! function_exists( 'wc_create_order' ) ) {
            return new WP_Error( 'woocommerce_required', 'WooCommerce no está disponible.' );
        }

        $cart = self::cart();
        $rate = GE_WTP_Catalog::exchange_rate();
        if ( ! $cart ) {
            return new WP_Error( 'empty_cart', 'El carrito está vacío.' );
        }
        if ( $rate <= 0 ) {
            return new WP_Error( 'missing_rate', 'Graph Express debe configurar la cotización antes de emitir el pedido.' );
        }

        $user = wp_get_current_user();
        $order = wc_create_order( array( 'customer_id' => $user->ID ) );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $order->set_currency( 'USD' );
        $order->set_billing_first_name( $user->first_name ? $user->first_name : $user->display_name );
        $order->set_billing_last_name( $user->last_name );
        $order->set_billing_email( $user->user_email );
        $order->set_payment_method( 'ge_purchase_order' );
        $order->set_payment_method_title( 'Purchase Order · pago a 30 días' );
        $order->update_meta_data( '_ge_markcom_order', 'yes' );
        $order->update_meta_data( '_ge_markcom_po_reference', sanitize_text_field( $po_reference ) );
        $order->update_meta_data( '_ge_markcom_exchange_rate', $rate );
        $order->update_meta_data( '_ge_markcom_exchange_label', GE_WTP_Catalog::exchange_label() );
        $order->update_meta_data( '_ge_markcom_exchange_updated_at', GE_WTP_Catalog::exchange_updated_at() );
        $order->update_meta_data( '_ge_markcom_notes', sanitize_textarea_field( $notes ) );

        $subtotal_usd = 0;
        foreach ( $cart as $line ) {
            $product_data = GE_WTP_Catalog::get( $line['product_key'] );
            $tier = (int) $line['tier'];
            if ( ! $product_data || ! isset( $product_data['prices'][ $tier ] ) ) {
                continue;
            }

            $unit_ars = (float) $product_data['prices'][ $tier ];
            $unit_usd = GE_WTP_Catalog::ars_to_usd( $unit_ars, $rate );
            $line_total = round( $unit_usd * $tier, 2 );
            $subtotal_usd += $line_total;

            $item = new WC_Order_Item_Product();
            $product_id = GE_WTP_Catalog::product_id( $line['product_key'] );
            if ( $product_id ) {
                $item->set_product_id( $product_id );
            }
            $item->set_name( $product_data['name'] );
            $item->set_quantity( $tier );
            $item->set_subtotal( $line_total );
            $item->set_total( $line_total );
            $item->add_meta_data( 'Precio unitario ARS', 'ARS ' . number_format_i18n( $unit_ars, 2 ) );
            $item->add_meta_data( 'Tipo de cambio', $rate . ' ARS/USD' );
            if ( ! empty( $line['notes'] ) ) {
                $item->add_meta_data( 'Observaciones', $line['notes'] );
            }
            $order->add_item( $item );
        }

        $tax = round( $subtotal_usd * 0.21, 2 );
        $fee = new WC_Order_Item_Fee();
        $fee->set_name( 'IVA 21%' );
        $fee->set_amount( $tax );
        $fee->set_total( $tax );
        $order->add_item( $fee );

        $order->calculate_totals( false );
        $order->set_status( 'ge-enviado', 'Pedido enviado desde el portal Markcom.' );
        $order->save();

        $reference = sprintf( 'GE-MKC-%s-%05d', current_time( 'Y' ), $order->get_id() );
        $order->update_meta_data( '_ge_markcom_reference', $reference );
        $order->save();

        delete_user_meta( $user->ID, self::CART_META );

        return $order;
    }

    public static function get_orders( $limit = 50 ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        $args = array(
            'limit'      => $limit,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_key'   => '_ge_markcom_order',
            'meta_value' => 'yes',
        );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            $args['customer_id'] = get_current_user_id();
        }

        return wc_get_orders( $args );
    }
}
