<?php

defined( 'ABSPATH' ) || exit;

/** Billing profile and fiscal decision for commercial quotes. Amounts are centavos. */
final class GE_WTP_Billing {
    const ENTITY_OPTION = 'ge_wtp_billing_entity';
    const PROFILE_META = '_ge_billing_profile';

    public static function init() {
        add_action( 'admin_post_ge_save_billing_entity', array( __CLASS__, 'save_entity' ) );
    }

    public static function profile( $user_id ) {
        $stored = get_user_meta( $user_id, self::PROFILE_META, true );
        $stored = is_array( $stored ) ? $stored : array();
        return array_merge( array(
            'billing_mode' => 'common',
            'cuit' => (string) get_user_meta( $user_id, '_ge_cuit', true ),
            'legal_name' => (string) get_user_meta( $user_id, 'billing_company', true ),
            'vat_status' => '',
            'billing_email' => '',
            'fiscal_address' => '',
            'verified_at' => '',
        ), $stored );
    }

    public static function normalize_profile( $input ) {
        $mode = isset( $input['billing_mode'] ) ? (string) $input['billing_mode'] : 'common';
        if ( ! in_array( $mode, array( 'common', 'invoice_a' ), true ) ) {
            throw new InvalidArgumentException( 'Modalidad de facturación inválida.' );
        }
        $cuit = preg_replace( '/[^0-9]/', '', (string) ( $input['cuit'] ?? '' ) );
        if ( '' !== $cuit && ! self::valid_cuit( $cuit ) ) {
            throw new InvalidArgumentException( 'CUIT inválido.' );
        }
        $vat = (string) ( $input['vat_status'] ?? '' );
        if ( ! in_array( $vat, array( '', 'registered', 'monotributo', 'exempt', 'final_consumer' ), true ) ) {
            throw new InvalidArgumentException( 'Condición fiscal inválida.' );
        }
        $email = trim( (string) ( $input['billing_email'] ?? '' ) );
        if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
            throw new InvalidArgumentException( 'Email de facturación inválido.' );
        }
        return array(
            'billing_mode' => $mode,
            'cuit' => $cuit,
            'legal_name' => trim( (string) ( $input['legal_name'] ?? '' ) ),
            'vat_status' => $vat,
            'billing_email' => $email,
            'fiscal_address' => trim( (string) ( $input['fiscal_address'] ?? '' ) ),
            'verified_at' => '',
        );
    }

    public static function missing_fields( $profile ) {
        if ( 'invoice_a' !== ( $profile['billing_mode'] ?? 'common' ) ) { return array(); }
        $missing = array();
        foreach ( array( 'cuit', 'legal_name', 'vat_status', 'billing_email', 'fiscal_address' ) as $key ) {
            if ( empty( $profile[ $key ] ) ) { $missing[] = $key; }
        }
        if ( ! empty( $profile['vat_status'] ) && ! in_array( $profile['vat_status'], array( 'registered', 'monotributo' ), true ) ) {
            $missing[] = 'compatible_vat_status';
        }
        if ( ! empty( $profile['cuit'] ) && ! self::valid_cuit( preg_replace( '/[^0-9]/', '', (string) $profile['cuit'] ) ) ) { $missing[] = 'valid_cuit'; }
        return $missing;
    }

    /** Used by customer, staff and future Action Registry quote writes. */
    public static function assert_can_accept_or_pay( $snapshot, $current_entity = null ) {
        $resolution = $snapshot['resolution'] ?? array();
        $profile = $snapshot['profile'] ?? array();
        $blockers = array_merge( (array) ( $resolution['blockers'] ?? array() ), self::missing_fields( $profile ) );
        if ( ! isset( $resolution['total_cents'] ) || ! in_array( $resolution['document_type'] ?? null, array( 'A', 'B', 'C' ), true ) || empty( $snapshot['entity'] ) ) { $blockers[] = 'billing_snapshot_invalid'; }
        if ( is_array( $current_entity ) ) {
            foreach ( array( 'cuit', 'vat_status', 'point_of_sale', 'document_capabilities', 'common_price_policy', 'invoice_a_price_policy', 'tax_rate_basis_points' ) as $field ) {
                if ( ( $snapshot['entity'][ $field ] ?? null ) !== ( $current_entity[ $field ] ?? null ) ) { $blockers[] = 'billing_entity_changed'; break; }
            }
        }
        if ( $blockers ) { throw new DomainException( 'Datos de facturación pendientes: ' . implode( ', ', array_unique( $blockers ) ) ); }
        return true;
    }

    public static function save_profile( $user_id, $input, $actor_id, $verify = false ) {
        $profile = self::normalize_profile( $input );
        $old = self::profile( $user_id );
        $old_fields = $old;
        unset( $old_fields['verified_at'] );
        $new_fields = $profile;
        unset( $new_fields['verified_at'] );
        $changed = $old_fields !== $new_fields;
        $profile['verified_at'] = $verify ? gmdate( 'c' ) : ( $changed ? '' : ( $old['verified_at'] ?? '' ) );
        if ( ! $changed && $old['verified_at'] === $profile['verified_at'] ) { return $old; }
        update_user_meta( $user_id, self::PROFILE_META, $profile );
        update_user_meta( $user_id, '_ge_cuit', $profile['cuit'] );
        update_user_meta( $user_id, 'billing_company', $profile['legal_name'] );
        update_user_meta( $user_id, 'billing_email', $profile['billing_email'] );
        add_user_meta( $user_id, '_ge_billing_audit', array(
            'at' => gmdate( 'c' ), 'actor_id' => (int) $actor_id,
            'changed_fields' => array_values( array_keys( array_diff_assoc( $profile, $old ) ) ),
        ) );
        return $profile;
    }

    public static function entity() {
        $stored = get_option( self::ENTITY_OPTION, array() );
        return is_array( $stored ) ? $stored : array();
    }

    public static function save_entity() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Acceso denegado.', 403 ); }
        check_admin_referer( 'ge_save_billing_entity' );
        $raw = isset( $_POST['entity'] ) && is_array( $_POST['entity'] ) ? wp_unslash( $_POST['entity'] ) : array();
        $cuit = preg_replace( '/[^0-9]/', '', (string) ( $raw['cuit'] ?? '' ) );
        if ( '' !== $cuit && ! self::valid_cuit( $cuit ) ) { wp_die( 'CUIT del emisor inválido.', 400 ); }
        $status = sanitize_key( $raw['vat_status'] ?? '' );
        if ( ! in_array( $status, array( '', 'registered', 'monotributo', 'exempt' ), true ) ) { wp_die( 'Condición fiscal del emisor inválida.', 400 ); }
        $capabilities = isset( $raw['document_capabilities'] ) && is_array( $raw['document_capabilities'] ) ? array_values( array_intersect( array( 'A', 'B', 'C' ), array_map( 'strtoupper', array_map( 'sanitize_key', $raw['document_capabilities'] ) ) ) ) : array();
        $allowed = 'registered' === $status ? array( 'A', 'B' ) : ( in_array( $status, array( 'monotributo', 'exempt' ), true ) ? array( 'C' ) : array() );
        if ( array_diff( $capabilities, $allowed ) ) { wp_die( 'Comprobantes incompatibles con el emisor.', 400 ); }
        $entity = array(
            'legal_name' => sanitize_text_field( $raw['legal_name'] ?? '' ),
            'cuit' => $cuit,
            'vat_status' => $status,
            'point_of_sale' => sanitize_text_field( $raw['point_of_sale'] ?? '' ),
            'document_capabilities' => array_values( $capabilities ),
            'common_price_policy' => in_array( $raw['common_price_policy'] ?? '', array( 'tax_inclusive', 'tax_exclusive' ), true ) ? $raw['common_price_policy'] : '',
            'invoice_a_price_policy' => in_array( $raw['invoice_a_price_policy'] ?? '', array( 'tax_inclusive', 'tax_exclusive' ), true ) ? $raw['invoice_a_price_policy'] : '',
            'tax_rate_basis_points' => isset( $raw['tax_rate_basis_points'] ) && ctype_digit( (string) $raw['tax_rate_basis_points'] ) ? min( 10000, (int) $raw['tax_rate_basis_points'] ) : 0,
        );
        update_option( self::ENTITY_OPTION, $entity, false );
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'settings', array( 'category' => 'billing', 'saved' => '1' ) ) ); exit;
    }

    public static function render_settings() {
        $entity = self::entity();
        echo '<section class="ge-admin-panel"><h2>Emisor y política de facturación</h2><p>Configurá los datos verificados del emisor. El cobro de Factura A se bloquea si faltan datos o si el emisor no está habilitado.</p>';
        if ( ! current_user_can( 'manage_options' ) ) { echo '<p>Solo un administrador puede modificar esta configuración.</p></section>'; return; }
        if ( isset( $_GET['saved'] ) ) { echo '<p>Configuración guardada. Verificá los datos antes de habilitar cobros.</p>'; }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_save_billing_entity">';
        wp_nonce_field( 'ge_save_billing_entity' );
        foreach ( array( 'legal_name' => 'Razón social', 'cuit' => 'CUIT', 'point_of_sale' => 'Punto de venta', 'tax_rate_basis_points' => 'Tasa IVA en puntos básicos (21% = 2100)' ) as $key => $label ) {
            echo '<p><label>' . esc_html( $label ) . ' <input name="entity[' . esc_attr( $key ) . ']" value="' . esc_attr( $entity[ $key ] ?? '' ) . '"></label></p>';
        }
        echo '<p><label>Condición fiscal <select name="entity[vat_status]">';
        foreach ( array( '' => 'Seleccionar', 'registered' => 'Responsable inscripto', 'monotributo' => 'Monotributista', 'exempt' => 'Exento' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $entity['vat_status'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label></p><p>Comprobantes habilitados: ';
        foreach ( array( 'A', 'B', 'C' ) as $type ) { echo '<label><input type="checkbox" name="entity[document_capabilities][]" value="' . esc_attr( $type ) . '"' . checked( in_array( $type, (array) ( $entity['document_capabilities'] ?? array() ), true ), true, false ) . '> ' . esc_html( $type ) . '</label> '; }
        echo '</p>';
        foreach ( array( 'common_price_policy' => 'Cliente común', 'invoice_a_price_policy' => 'Necesita Factura A' ) as $key => $label ) {
            echo '<p><label>Política de precio · ' . esc_html( $label ) . ' <select name="entity[' . esc_attr( $key ) . ']"><option value="">Seleccionar</option>';
            foreach ( array( 'tax_inclusive' => 'Precio final, IVA incluido si corresponde', 'tax_exclusive' => 'Precio base, impuesto aparte' ) as $value => $name ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $entity[ $key ] ?? '', $value, false ) . '>' . esc_html( $name ) . '</option>'; }
            echo '</select></label></p>';
        }
        echo '<button type="submit">Guardar emisor</button></form></section>';
    }

    /** Pricing policy is explicit; tax rates and A capability are configured, never inferred from a checkbox. */
    public static function resolve( $entity, $profile, $subtotal_cents, $tax_rate_basis_points = null ) {
        if ( null === $tax_rate_basis_points ) { $tax_rate_basis_points = $entity['tax_rate_basis_points'] ?? 0; }
        if ( ! is_int( $subtotal_cents ) || $subtotal_cents < 0 || ! is_int( $tax_rate_basis_points ) || $tax_rate_basis_points < 0 || $tax_rate_basis_points > 10000 ) {
            throw new InvalidArgumentException( 'Base o tasa fiscal inválida.' );
        }
        $mode = $profile['billing_mode'] ?? 'common';
        $blockers = array();
        if ( ! in_array( $mode, array( 'common', 'invoice_a' ), true ) ) { $blockers[] = 'billing_mode_invalid'; }
        if ( 'invoice_a' === $mode ) {
            $blockers = self::missing_fields( $profile );
            if ( 'registered' !== ( $entity['vat_status'] ?? '' ) || ! in_array( 'A', (array) ( $entity['document_capabilities'] ?? array() ), true ) ) {
                $blockers[] = 'issuer_cannot_invoice_a';
            }
        }
        if ( empty( $entity['cuit'] ) || empty( $entity['legal_name'] ) || empty( $entity['point_of_sale'] ) ) {
            $blockers[] = 'billing_entity_unconfigured';
        }
        $document = null;
        if ( ! $blockers ) {
            if ( 'invoice_a' === $mode ) { $document = 'A'; }
            elseif ( in_array( $entity['vat_status'] ?? '', array( 'monotributo', 'exempt' ), true ) ) { $document = 'C'; }
            elseif ( 'registered' === ( $entity['vat_status'] ?? '' ) ) { $document = in_array( $profile['vat_status'] ?? '', array( 'registered', 'monotributo' ), true ) ? 'A' : 'B'; }
            if ( 'A' === $document && ( empty( $profile['cuit'] ) || empty( $profile['legal_name'] ) ) ) { $blockers[] = 'recipient_fiscal_data_missing'; }
            if ( ! $document || ! in_array( $document, (array) ( $entity['document_capabilities'] ?? array() ), true ) ) { $blockers[] = 'document_not_enabled'; }
        }
        $policy = $mode === 'invoice_a' ? ( $entity['invoice_a_price_policy'] ?? '' ) : ( $entity['common_price_policy'] ?? '' );
        if ( ! in_array( $policy, array( 'tax_inclusive', 'tax_exclusive' ), true ) ) { $blockers[] = 'price_policy_unconfigured'; }
        if ( $tax_rate_basis_points === 0 && 'registered' === ( $entity['vat_status'] ?? '' ) && empty( $entity['allow_zero_tax'] ) ) { $blockers[] = 'tax_rate_unconfigured'; }
        if ( $tax_rate_basis_points > 0 && in_array( $entity['vat_status'] ?? '', array( 'monotributo', 'exempt' ), true ) ) { $blockers[] = 'tax_rate_incompatible_with_issuer'; }
        $tax = 0;
        $base = $subtotal_cents;
        if ( 'tax_exclusive' === $policy ) {
            $tax = intdiv( $base * $tax_rate_basis_points + 5000, 10000 );
        } elseif ( 'tax_inclusive' === $policy ) {
            $base = intdiv( $subtotal_cents * 10000 + intdiv( 10000 + $tax_rate_basis_points, 2 ), 10000 + $tax_rate_basis_points );
            $tax = $subtotal_cents - $base;
        }
        return array(
            'billing_flow' => $mode,
            'tax_treatment' => $policy,
            'subtotal_cents' => $base,
            'tax_cents' => $tax,
            'total_cents' => $base + $tax,
            'document_type' => $blockers ? null : $document,
            'blockers' => array_values( array_unique( $blockers ) ),
        );
    }

    /** Staff enters a net price in Nuevo presupuesto; never reinterpret it as tax-inclusive. */
    public static function resolve_net_quote( $entity, $profile, $net_subtotal_cents, $tax_rate_basis_points = null ) {
        $resolution = self::resolve( $entity, $profile, $net_subtotal_cents, $tax_rate_basis_points );
        if ( 'tax_exclusive' !== $resolution['tax_treatment'] ) {
            $resolution['blockers'][] = 'quote_requires_net_price_policy';
            $resolution['document_type'] = null;
        }
        return $resolution;
    }

    public static function snapshot( $entity, $profile, $resolution ) {
        return array( 'entity' => $entity, 'profile' => $profile, 'resolution' => $resolution, 'captured_at' => gmdate( 'c' ) );
    }

    private static function valid_cuit( $cuit ) {
        if ( ! preg_match( '/^[0-9]{11}$/D', $cuit ) ) { return false; }
        $weights = array( 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 ); $sum = 0;
        for ( $i = 0; $i < 10; $i++ ) { $sum += (int) $cuit[ $i ] * $weights[ $i ]; }
        return (int) $cuit[10] === ( 11 - $sum % 11 ) % 11;
    }
}
