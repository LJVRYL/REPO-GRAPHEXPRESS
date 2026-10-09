<?php
defined( 'ABSPATH' ) || exit;

/** Commercial terms are separate from the ledger of money actually received. */
final class GE_WTP_Payment_Policy {
    const META = '_ge_payment_policy';
    const CUSTOMER_META = '_ge_customer_payment_policy';

    public static function init() {
        add_action( 'admin_post_ge_order_payment_policy', array( __CLASS__, 'handle_order' ) );
    }

    public static function defaults() {
        return array( 'kind' => 'deposit', 'percent' => 50, 'days' => 0, 'reason' => '', 'approved_by' => 0, 'approved_at' => '' );
    }

    public static function normalize( $input, $actor = 0 ) {
        $kind = (string) ( $input['kind'] ?? 'deposit' );
        if ( ! in_array( $kind, array( 'deposit', 'full', 'custom', 'credit' ), true ) ) { return new WP_Error( 'ge_policy_invalid', 'Elegí una condición de pago válida.' ); }
        $policy = self::defaults();
        $policy['kind'] = $kind;
        if ( 'full' === $kind ) { $policy['percent'] = 100; }
        if ( 'custom' === $kind ) {
            $percent = filter_var( $input['percent'] ?? '', FILTER_VALIDATE_INT );
            if ( false === $percent || $percent < 1 || $percent > 99 ) { return new WP_Error( 'ge_policy_percent', 'La seña especial debe ser un porcentaje entero entre 1 y 99.' ); }
            $policy['percent'] = $percent;
        }
        if ( 'credit' === $kind ) {
            $days = filter_var( $input['days'] ?? '', FILTER_VALIDATE_INT );
            if ( false === $days || $days < 1 || $days > 120 ) { return new WP_Error( 'ge_policy_days', 'Indicá un plazo de cuenta corriente entre 1 y 120 días desde la entrega.' ); }
            if ( ! $actor || ( class_exists( 'GE_Organization_Runtime' ) ? ! GE_Organization_Runtime::allowed( 'finance', true, $actor ) : ! user_can( $actor, 'manage_woocommerce' ) ) ) { return new WP_Error( 'ge_policy_forbidden', 'La cuenta corriente requiere aprobación de Administración.' ); }
            if ( empty( $input['approve_credit'] ) ) { return new WP_Error( 'ge_policy_approval', 'Confirmá expresamente la aprobación de la cuenta corriente.' ); }
            $policy['percent'] = 0;
            $policy['days'] = $days;
        }
        $policy['reason'] = sanitize_textarea_field( $input['reason'] ?? '' );
        if ( in_array( $kind, array( 'custom', 'credit' ), true ) && '' === trim( $policy['reason'] ) ) { return new WP_Error( 'ge_policy_reason', 'Registrá el motivo de esta excepción.' ); }
        $policy['approved_by'] = (int) $actor;
        $policy['approved_at'] = gmdate( 'c' );
        return $policy;
    }

    private static function valid( $policy ) {
        if ( ! is_array( $policy ) ) { return false; }
        $kind = $policy['kind'] ?? '';
        $percent = $policy['percent'] ?? null;
        if ( 'deposit' === $kind ) { return 50 === $percent; }
        if ( 'full' === $kind ) { return 100 === $percent; }
        if ( empty( $policy['approved_by'] ) || empty( $policy['approved_at'] ) || empty( $policy['reason'] ) ) { return false; }
        if ( 'custom' === $kind ) { return is_int( $percent ) && $percent > 0 && $percent < 100; }
        return 'credit' === $kind && 0 === $percent && is_int( $policy['days'] ?? null ) && $policy['days'] > 0 && $policy['days'] <= 120;
    }

    public static function customer( $id ) {
        $policy = get_user_meta( $id, self::CUSTOMER_META, true );
        return self::valid( $policy ) ? $policy : self::defaults();
    }

    public static function save_customer( $id, $input, $actor ) {
        $user = get_userdata( $id );
        $scope = get_user_meta( $id, '_ge_organization_id', true );
        if ( ! $user || user_can( $id, 'ge_manage_operations' ) || user_can( $id, 'manage_options' ) || ( class_exists( 'GE_Organization' ) && $scope && GE_Organization::PRIMARY !== $scope ) ) { return new WP_Error( 'ge_policy_customer', 'Cliente inválido.' ); }
        if ( class_exists( 'GE_Organization_Runtime' ) ? ! GE_Organization_Runtime::allowed( 'customers', true, $actor ) && ! GE_Organization_Runtime::allowed( 'finance', true, $actor ) : ! user_can( $actor, 'manage_woocommerce' ) ) { return new WP_Error( 'ge_policy_forbidden', 'Acceso denegado.' ); }
        $policy = self::normalize( $input, $actor );
        if ( is_wp_error( $policy ) ) { return $policy; }
        $history = get_user_meta( $id, '_ge_payment_policy_history', true );
        $history = is_array( $history ) ? $history : array();
        $history[] = array( 'previous' => self::customer( $id ), 'policy' => $policy );
        update_user_meta( $id, '_ge_payment_policy_history', $history );
        update_user_meta( $id, self::CUSTOMER_META, $policy );
        return $policy;
    }

    public static function order( $order ) {
        $stored = $order->get_meta( self::META, true );
        if ( $stored ) {
            return self::valid( $stored ) ? $stored : new WP_Error( 'ge_policy_invalid', 'Revisá las condiciones de pago registradas en el pedido.' );
        }
        // Existing quotes retain their accepted terms, independent of later customer changes.
        $snapshot = $order->get_meta( '_ge_commercial_quote_snapshot', true );
        if ( is_array( $snapshot ) && $snapshot ) {
            if ( isset( $snapshot['payment_policy'] ) && self::valid( $snapshot['payment_policy'] ) ) { return $snapshot['payment_policy']; }
            $percent = array_key_exists( 'deposit_enabled', $snapshot ) && ! $snapshot['deposit_enabled'] ? 100 : (int) ( $snapshot['deposit_percent'] ?? 50 );
            if ( $percent < 1 || $percent > 100 ) { return new WP_Error( 'ge_policy_invalid', 'Revisá la seña acordada en el presupuesto.' ); }
            return array_merge( self::defaults(), array( 'kind' => 100 === $percent ? 'full' : 'deposit', 'percent' => $percent, 'source' => 'accepted_quote' ) );
        }
        return self::defaults();
    }

    public static function freeze( $order ) {
        if ( $order->get_meta( self::META, true ) ) { return; }
        $policy = $order->get_meta( '_ge_commercial_quote_id', true ) ? self::order( $order ) : self::customer( $order->get_customer_id() );
        if ( ! is_wp_error( $policy ) ) {
            // Legacy accepted percentages are stored as terms rather than new approvals.
            if ( 'accepted_quote' === ( $policy['source'] ?? '' ) && ! in_array( $policy['percent'], array( 50, 100 ), true ) ) { return; }
            $order->update_meta_data( self::META, $policy );
        }
    }

    public static function save_order( $order, $input, $actor ) {
        if ( ! $order instanceof WC_Order || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) || $order->get_meta( '_ge_delivery_confirmed_at', true ) ) { return new WP_Error( 'ge_policy_order', 'No se pueden modificar las condiciones de este pedido.' ); }
        if ( class_exists( 'GE_Organization_Runtime' ) ? ( ! GE_Organization_Runtime::allowed( 'orders', true, $actor ) && ! GE_Organization_Runtime::allowed( 'finance', true, $actor ) ) : ! user_can( $actor, 'manage_woocommerce' ) ) { return new WP_Error( 'ge_policy_forbidden', 'Acceso denegado.' ); }
        $scope = $order->get_meta( '_ge_organization_id', true );
        $customer_scope = get_user_meta( $order->get_customer_id(), '_ge_organization_id', true );
        if ( class_exists( 'GE_Organization' ) && ( ( $scope && GE_Organization::PRIMARY !== $scope ) || ( $customer_scope && GE_Organization::PRIMARY !== $customer_scope ) ) ) { return new WP_Error( 'ge_policy_forbidden', 'Acceso denegado.' ); }
        $policy = self::normalize( $input, $actor );
        if ( is_wp_error( $policy ) ) { return $policy; }
        $history = $order->get_meta( '_ge_payment_policy_history', true );
        $history = is_array( $history ) ? $history : array();
        $history[] = array( 'previous' => self::order( $order ), 'policy' => $policy );
        $order->update_meta_data( '_ge_payment_policy_history', $history );
        $order->update_meta_data( self::META, $policy );
        $order->add_order_note( 'Condiciones de pago: ' . self::label( $policy ) . '. Registradas por #' . (int) $actor . ( $policy['reason'] ? '. Motivo: ' . $policy['reason'] : '' ), false );
        $order->save();
        return $policy;
    }

    public static function label( $policy ) {
        if ( is_wp_error( $policy ) ) { return $policy->get_error_message(); }
        if ( 'credit' === $policy['kind'] ) { return 'Cuenta corriente aprobada: ' . $policy['days'] . ' días desde la entrega'; }
        if ( 100 === $policy['percent'] ) { return 'Pago completo antes de producir'; }
        return 'Seña ' . $policy['percent'] . '%; saldo antes de entregar';
    }

    public static function fields( $policy ) {
        if ( is_wp_error( $policy ) ) { $policy = self::defaults(); }
        echo '<label>Condición de pago<select name="policy_kind">';
        foreach ( array( 'deposit' => 'Seña 50% (habitual)', 'full' => 'Pago completo antes de producir', 'custom' => 'Otra seña', 'credit' => 'Cuenta corriente aprobada' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $policy['kind'], $key, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label><details><summary>Condiciones especiales</summary><label>Seña especial (%)<input type="number" min="1" max="99" name="policy_percent" value="' . esc_attr( $policy['percent'] ?: 50 ) . '"></label><label>Cuenta corriente: días desde la entrega<input type="number" min="1" max="120" name="policy_days" value="' . esc_attr( $policy['days'] ?: 30 ) . '"></label><label>Motivo de la excepción<textarea name="policy_reason">' . esc_textarea( $policy['reason'] ) . '</textarea></label><label><input type="checkbox" name="policy_approve_credit" value="1"> Apruebo expresamente la cuenta corriente (Administración)</label></details>';
    }

    public static function input() {
        $input = array();
        foreach ( array( 'kind', 'percent', 'days', 'reason', 'approve_credit' ) as $key ) { $input[ $key ] = wp_unslash( $_POST[ 'policy_' . $key ] ?? '' ); }
        return $input;
    }

    public static function render_order( $order ) {
        if ( GE_WTP_Customer_Quotes::is_quote_order( $order ) ) { return; }
        $policy = self::order( $order );
        echo '<section class="ge-admin-panel"><h2>Condiciones de pago</h2><p>' . esc_html( self::label( $policy ) ) . '</p><p>La cuenta corriente mantiene el saldo pendiente hasta que se registre su cobro.</p>';
        if ( ! $order->get_meta( '_ge_delivery_confirmed_at', true ) ) {
            echo '<details><summary>Cambiar para este pedido</summary><form class="ge-profile-fields" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_order_payment_policy"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
            wp_nonce_field( 'ge_order_payment_policy_' . $order->get_id() );
            self::fields( $policy );
            echo '<button type="submit">Guardar condiciones</button></form></details>';
        }
        echo '</section>';
    }

    public static function handle_order() {
        $id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_order_payment_policy_' . $id );
        $result = self::save_order( wc_get_order( $id ), self::input(), get_current_user_id() );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $id ) ) );
        exit;
    }
}
