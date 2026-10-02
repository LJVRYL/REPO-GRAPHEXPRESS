<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The customer's five production stages, independent of WooCommerce payment status. */
final class GE_WTP_Order_Lifecycle {
    const STAGE_META = '_ge_lifecycle_stage';
    const DELIVERED_META = '_ge_lifecycle_delivered_at';

    public static function stages() {
        return array(
            'recibido' => 'Recibido',
            'aprobado' => 'Aprobado',
            'produccion' => 'En producción',
            'listo' => 'Listo para entrega',
            'entregado' => 'Entregado',
        );
    }

    public static function init() {
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'sync_legacy_status' ), 10, 4 );
        add_filter( 'woocommerce_my_account_my_orders_columns', array( __CLASS__, 'account_columns' ) );
        add_action( 'woocommerce_my_account_my_orders_column_ge-stage', array( __CLASS__, 'account_stage' ) );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_stage' ) );
    }

    public static function stage( $order ) {
        if ( ! $order instanceof WC_Order ) { return 'recibido'; }
        $saved = (string) $order->get_meta( self::STAGE_META, true );
        if ( isset( self::stages()[ $saved ] ) ) { return $saved; }
        $status = $order->get_status();
        $legacy = array(
            'ge-enviado' => 'recibido', 'ge-espera-po' => 'recibido',
            'ge-confirmado' => 'aprobado', 'ge-produccion' => 'produccion',
            'ge-listo' => 'listo', 'ge-entregado' => 'entregado', 'completed' => 'entregado',
        );
        if ( isset( $legacy[ $status ] ) ) { return $legacy[ $status ]; }
        if ( $order->get_meta( '_ge_delivery_confirmed_at', true ) ) { return 'entregado'; }
        $production = (string) $order->get_meta( '_ge_production_status', true );
        $from_production = array( 'approved' => 'aprobado', 'production' => 'produccion', 'ready' => 'listo' );
        return $from_production[ $production ] ?? 'recibido';
    }

    public static function label( $order ) {
        if ( $order instanceof WC_Order ) {
            $exceptions = array( 'cancelled' => 'Cancelado', 'refunded' => 'Reembolsado', 'failed' => 'Pago no completado' );
            if ( isset( $exceptions[ $order->get_status() ] ) ) { return $exceptions[ $order->get_status() ]; }
        }
        return self::stages()[ self::stage( $order ) ];
    }

    public static function delivered_at_utc( $order ) {
        if ( ! $order instanceof WC_Order || 'entregado' !== self::stage( $order ) ) { return null; }
        $saved = (string) $order->get_meta( self::DELIVERED_META, true );
        if ( $saved ) { return $saved; }
        $completed = $order->get_date_completed();
        return $completed ? gmdate( 'Y-m-d H:i:s', $completed->getTimestamp() ) : null;
    }

    public static function retention_due_utc( $order ) {
        $delivered = self::delivered_at_utc( $order );
        if ( ! $delivered ) { return null; }
        $time = strtotime( $delivered . ' UTC' );
        return $time ? gmdate( 'Y-m-d H:i:s', $time + 180 * DAY_IN_SECONDS ) : null;
    }

    /** Explicit staff decision; it never changes WooCommerce's payment status. */
    public static function set_stage( $order, $stage, $note = '' ) {
        if ( ! $order instanceof WC_Order || ! isset( self::stages()[ $stage ] ) || in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) { return false; }
        if ( class_exists( 'GE_WTP_Customer_Quotes' ) && GE_WTP_Customer_Quotes::is_quote_order( $order ) && 'recibido' !== $stage ) { return false; }
        $previous = self::stage( $order );
        if ( $previous === $stage && (string) $order->get_meta( self::STAGE_META, true ) === $stage ) { return false; }
        $order->update_meta_data( self::STAGE_META, $stage );
        if ( 'entregado' === $stage ) {
            if ( ! $order->get_meta( self::DELIVERED_META, true ) ) {
                $order->update_meta_data( self::DELIVERED_META, current_time( 'mysql', true ) );
            }
        } elseif ( 'entregado' === $previous ) {
            // A reopened job cannot expire under the previous delivery date.
            $order->delete_meta_data( self::DELIVERED_META );
        }
        if ( $previous !== $stage ) {
            $order->add_order_note( sprintf( 'Etapa del trabajo: %s → %s.%s', self::stages()[ $previous ], self::stages()[ $stage ], $note ? ' ' . $note : '' ) );
        }
        $order->save();
        return $previous !== $stage;
    }

    /** Existing Markcom and QR delivery actions continue to set their legacy statuses. */
    public static function sync_legacy_status( $order_id, $old_status, $new_status, $order ) {
        if ( ! $order instanceof WC_Order ) { return; }
        $mapping = array(
            'ge-enviado' => 'recibido', 'ge-espera-po' => 'recibido',
            'ge-confirmado' => 'aprobado', 'ge-produccion' => 'produccion',
            'ge-listo' => 'listo', 'ge-entregado' => 'entregado', 'completed' => 'entregado',
        );
        if ( isset( $mapping[ $new_status ] ) ) { self::set_stage( $order, $mapping[ $new_status ] ); }
    }

    public static function account_columns( $columns ) {
        $result = array();
        foreach ( $columns as $key => $label ) {
            $result[ 'order-status' === $key ? 'ge-stage' : $key ] = 'order-status' === $key ? 'Estado del trabajo' : $label;
        }
        return $result;
    }

    public static function account_stage( $order ) {
        echo esc_html( self::label( $order ) );
    }

    public static function admin_stage( $order ) {
        if ( ! $order instanceof WC_Order ) { return; }
        echo '<p><strong>Etapa del trabajo:</strong> ' . esc_html( self::label( $order ) ) . '</p>';
        echo '<p><small>Estado interno de WooCommerce: ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</small></p>';
        $retention = self::retention_due_utc( $order );
        if ( $retention ) { echo '<p><small>Revisión de retención (180 días): ' . esc_html( $retention ) . ' UTC. Borrado automático desactivado.</small></p>'; }
    }
}
