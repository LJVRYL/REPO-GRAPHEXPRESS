<?php

defined( 'ABSPATH' ) || exit;

final class GE_WTP_Private_Catalog {
    const META = '_ge_private_catalog_quote';

    public static function init() {
        add_action( 'show_user_profile', array( __CLASS__, 'profile' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'profile' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
        add_action( 'admin_post_ge_private_catalog_draft', array( __CLASS__, 'save_draft' ) );
    }

    private static function can_configure( $id ) {
        return current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_user', $id );
    }

    public static function profile( $user ) {
        if ( ! self::can_configure( $user->ID ) || ! GE_WTP_Portal::is_customer_user( $user ) || GE_WTP_Portal::is_markcom_user( $user ) ) { return; }
        wp_nonce_field( 'ge_private_catalog_profile_' . $user->ID, 'ge_private_catalog_nonce' );
        printf( '<h2>Catálogo privado</h2><p><label>ID interno del presupuesto fuente <input name="ge_private_catalog_quote" type="number" min="0" value="%d"></label> Dejá 0 para quitar el catálogo. El presupuesto debe pertenecer al cliente.</p>', absint( get_user_meta( $user->ID, self::META, true ) ) );
    }

    public static function save_profile( $id ) {
        if ( ! self::can_configure( $id ) || ! isset( $_POST['ge_private_catalog_quote'], $_POST['ge_private_catalog_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ge_private_catalog_nonce'] ) ), 'ge_private_catalog_profile_' . $id ) ) { return; }
        $value = absint( $_POST['ge_private_catalog_quote'] );
        if ( ! $value ) {
            delete_user_meta( $id, self::META );
            return;
        }
        if ( ! self::groups( $id, $value ) ) {
            wp_die( 'Elegí un presupuesto de ese cliente con productos y precios válidos.', '', array( 'response' => 400 ) );
        }
        update_user_meta( $id, self::META, $value );
    }

    private static function groups( $id, $source = 0 ) {
        $source = $source ?: absint( get_user_meta( $id, self::META, true ) );
        if ( ! $id || ! $source ) { return array(); }
        $quote = GE_WTP_Commercial_Quotes::get( $source );
        if ( is_wp_error( $quote ) || (int) $quote['customer_id'] !== (int) $id ) { return array(); }
        $groups = array();
        foreach ( $quote['snapshot']['items'] ?? array() as $item ) {
            $uuid = $item['line_uuid'] ?? '';
            if ( ! GE_WTP_Quote_Artwork_V2::uuid( $uuid ) || empty( $item['name'] ) || empty( $item['taxable_base_cents'] ) ) { continue; }
            $key = 'alternative' === ( $item['selection_type'] ?? '' ) ? $item['selection_group'] : $uuid;
            $groups[$key][$uuid] = $item;
        }
        return $groups;
    }

    public static function available( $id ) {
        return (bool) self::groups( $id );
    }

    public static function render( $id, $preview ) {
        $groups = self::groups( $id );
        if ( ! $groups ) { return; }
        echo '<section class="ge-panel"><h1>Mis productos</h1><p>Elegí una variante por producto y guardá tu selección. Los importes de referencia están en ARS sin IVA.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_private_catalog_draft">';
        wp_nonce_field( 'ge_private_catalog_draft' );
        echo '<input type="hidden" name="catalog_session" value="' . esc_attr( wp_generate_uuid4() ) . '">';
        echo '<fieldset' . ( $preview ? ' disabled' : '' ) . '>';
        foreach ( array_values( $groups ) as $index => $variants ) {
            $first = reset( $variants );
            $label = preg_replace( '/\s*·\s*(?:[\d.]+)\b.*$/u', '', $first['name'] );
            echo '<article class="ge-panel"><h2>' . esc_html( $label ) . '</h2><fieldset><legend>Variantes</legend>';
            printf( '<label><input type="radio" name="variants[%d]" value="" checked> Omitir</label>', $index );
            foreach ( $variants as $uuid => $variant ) {
                printf( '<p><label><input type="radio" name="variants[%d]" value="%s"> %s · %s</label><br>%s</p>', $index, esc_attr( $uuid ), esc_html( $variant['name'] ), wp_kses_post( wc_price( $variant['taxable_base_cents'] / 100, array( 'currency' => 'ARS' ) ) ), esc_html( $variant['details'] ?? '' ) );
            }
            echo '</fieldset></article>';
        }
        echo '<button class="ge-button ge-button-primary" type="submit">Guardar borrador de solicitud</button></fieldset></form></section>';
    }

    public static function save_draft() {
        check_admin_referer( 'ge_private_catalog_draft' );
        if ( ! GE_WTP_Portal::can_access() || GE_WTP_Portal::is_staff_preview() || GE_WTP_Portal::is_markcom_user() ) {
            wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) );
        }
        $id = get_current_user_id();
        $groups = self::groups( $id );
        $raw = isset( $_POST['variants'] ) && is_array( $_POST['variants'] ) ? wp_unslash( $_POST['variants'] ) : array();
        $selected = array_filter( array_map( 'sanitize_text_field', $raw ) );
        $items = array();
        foreach ( $groups as $variants ) {
            $choices = array_intersect( $selected, array_keys( $variants ) );
            if ( count( $choices ) > 1 ) { wp_die( 'Elegí una sola variante por producto.', '', array( 'response' => 400 ) ); }
            if ( ! $choices ) { continue; }
            $variant = $variants[reset( $choices )];
            $items[] = array(
                'line_uuid' => wp_generate_uuid4(),
                'title' => $variant['name'],
                'quantity' => $variant['quantity'],
                'description' => $variant['details'] ?? '',
                'notes' => 'Importe de referencia por la selección, sin IVA: ARS ' . wc_format_decimal( $variant['taxable_base_cents'] / 100, 2 ),
            );
        }
        if ( ! $items || count( $items ) !== count( $selected ) ) {
            wp_die( 'Seleccioná variantes disponibles del catálogo.', '', array( 'response' => 400 ) );
        }
        $result = GE_WTP_Quote_Requests::save( array(
            'items' => $items,
            'artwork_session' => sanitize_text_field( wp_unslash( $_POST['catalog_session'] ?? '' ) ),
        ), $id, false );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) ); }
        wp_safe_redirect( GE_WTP_Portal::portal_url( 'solicitudes' ) );
        exit;
    }
}
