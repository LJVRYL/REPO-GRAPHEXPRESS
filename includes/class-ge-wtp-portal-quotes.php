<?php
defined( 'ABSPATH' ) || exit;

/** Read-only portal projections; draft and internal events never appear to customers. */
final class GE_WTP_Portal_Quotes {
    public static function customer_quotes() {
        $customer = GE_WTP_Portal::portal_customer_id();
        $posts = get_posts( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'numberposts' => -1, 'meta_key' => GE_WTP_Commercial_Quotes::CUSTOMER_META, 'meta_value' => $customer ) );
        $quotes = array();
        foreach ( $posts as $post ) {
            $quote = GE_WTP_Commercial_Quotes::get( $post->ID, $customer );
            if ( ! is_wp_error( $quote ) && 'draft' !== $quote['status'] ) { $quotes[] = $quote; }
        }
        return $quotes;
    }

    public static function pending( $quote ) {
        return in_array( $quote['status'], array( 'sent', 'viewed' ), true ) && ( empty( $quote['snapshot']['valid_until'] ) || $quote['snapshot']['valid_until'] >= wp_date( 'Y-m-d' ) );
    }

    public static function card( $quotes ) {
        $pending = count( array_filter( $quotes, array( __CLASS__, 'pending' ) ) );
        echo '<article class="ge-quote-stat"><span>Presupuestos</span><strong>' . esc_html( count( $quotes ) ) . '</strong><small>' . esc_html( $pending ? $pending . ' pendiente' . ( $pending > 1 ? 's' : '' ) . ' de aprobación' : 'En tu historial' ) . '</small><a href="' . esc_url( GE_WTP_Portal::portal_url( 'presupuestos' ) ) . '">Ver presupuestos <span aria-hidden="true">→</span></a></article>';
    }

    public static function activity( $quotes, $orders ) {
        $entries = array();
        $labels = array( 'created' => 'creado', 'sent' => 'enviado', 'accepted' => 'aprobado', 'accepted_staff' => 'aprobado', 'rejected' => 'rechazado', 'observed' => 'observado', 'converted' => 'convertido a pedido' );
        foreach ( $quotes as $quote ) {
            $events = (array) get_post_meta( $quote['id'], '_ge_commercial_events', true );
            $visible = array_values( array_filter( $events, function( $event ) use ( $labels, $quote ) { return isset( $labels[ $event['event'] ?? '' ] ) && (int) ( $event['version'] ?? 0 ) === $quote['version']; } ) );
            $last = $visible ? end( $visible ) : array();
            $pending = self::pending( $quote );
            $status = $pending ? 'pendiente de aprobación' : ( $labels[ $last['event'] ?? '' ] ?? array( 'accepted' => 'aprobado', 'converted' => 'convertido a pedido', 'expired' => 'vencido', 'rejected' => 'rechazado' )[ $quote['status'] ] ?? 'disponible' );
            $entries[] = array( 'title' => 'Presupuesto ' . $quote['number'] . ' ' . $status, 'at' => strtotime( $last['at'] ?? $quote['snapshot']['created_at'] ?? '' ) ?: 0, 'pending' => $pending, 'url' => GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $quote['id'] ) ), 'cta' => $pending ? 'Revisar presupuesto' : 'Ver presupuesto' );
        }
        foreach ( array_slice( $orders, 0, 6 ) as $order ) {
            $date = $order->get_date_modified() ?: $order->get_date_created();
            $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id();
            $entries[] = array( 'title' => 'Pedido ' . $reference . ' · ' . wc_get_order_status_name( $order->get_status() ), 'at' => $date ? $date->getTimestamp() : 0, 'pending' => false, 'url' => GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) ), 'cta' => 'Ver pedido' );
        }
        usort( $entries, function( $a, $b ) { return ( $b['pending'] <=> $a['pending'] ) ?: ( $b['at'] <=> $a['at'] ); } );
        if ( ! $entries ) { echo '<p>Todavía no hay actividad comercial. Tus presupuestos y pedidos aparecerán acá.</p>'; return; }
        echo '<ol class="ge-commercial-activity">';
        foreach ( array_slice( $entries, 0, 6 ) as $entry ) {
            echo '<li' . ( $entry['pending'] ? ' class="is-pending"' : '' ) . '><div><strong>' . esc_html( $entry['title'] ) . '</strong>' . ( $entry['at'] ? '<time datetime="' . esc_attr( gmdate( 'c', $entry['at'] ) ) . '">' . esc_html( wp_date( 'd/m/Y H:i', $entry['at'] ) ) . '</time>' : '' ) . '</div><a href="' . esc_url( $entry['url'] ) . '">' . esc_html( $entry['cta'] ) . '</a></li>';
        }
        echo '</ol>';
    }
}
