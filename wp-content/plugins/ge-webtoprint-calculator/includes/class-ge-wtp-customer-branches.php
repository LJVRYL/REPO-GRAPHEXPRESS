<?php

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-ge-wtp-billing-issuers.php';

/** Additional fiscal identities under one commercial customer account. */
final class GE_WTP_Customer_Branches {
    const META = '_ge_billing_profiles';

    public static function init() {
        add_action( 'admin_post_ge_customer_billing_profile', array( __CLASS__, 'handle_save' ) );
        add_action( 'wp_ajax_ge_customer_branch_options', array( __CLASS__, 'ajax_options' ) );
    }

    public static function profiles( $customer_id, $include_inactive = false ) {
        $legacy = GE_WTP_Billing::profile( $customer_id );
        $legacy=array_merge($legacy,(array)get_user_meta($customer_id,'_ge_primary_profile_contact',true));
        $legacy['id'] = 'default';
        $legacy['label'] = get_user_meta( $customer_id, '_ge_primary_profile_label', true ) ?: 'Perfil principal';
        $legacy['active'] = 'yes' !== get_user_meta( $customer_id, '_ge_primary_profile_inactive', true );
        $legacy['legacy'] = true;
        $stored = get_user_meta( $customer_id, self::META, true );
        $stored = is_array( $stored ) ? $stored : array();
        $legacy['is_default'] = ! array_filter( $stored, function ( $profile ) { return ! empty( $profile['active'] ) && ! empty( $profile['is_default'] ); } );
        return array_values( array_filter( array_merge( array( $legacy ), $stored ), function ( $profile ) use ( $include_inactive ) { return $include_inactive || ! empty( $profile['active'] ); } ) );
    }

    public static function default_profile_id( $customer_id ) {
        foreach ( self::profiles( $customer_id ) as $profile ) { if ( ! empty( $profile['is_default'] ) ) { return $profile['id']; } }
        return 'default';
    }

    public static function find( $customer_id, $id, $include_inactive = false ) {
        foreach ( self::profiles( $customer_id, $include_inactive ) as $profile ) {
            if ( (string) ( $profile['id'] ?? '' ) === (string) $id ) { return $profile; }
        }
        return null;
    }

    public static function delivery( $customer_id, $id ) {
        foreach ( GE_WTP_Customers::addresses( $customer_id ) as $index => $address ) {
            if ( (string) ( $address['id'] ?? $index ) === (string) $id ) { return array_merge( $address, array( 'id' => (string) ( $address['id'] ?? $index ) ) ); }
        }
        return null;
    }

    public static function save( $customer_id, $input, $actor_id ) {
        if(class_exists('GE_Organization_Runtime') && GE_Organization_Runtime::role($actor_id) && !GE_Organization_Runtime::allowed('customers',true,$actor_id))return new WP_Error('ge_org_role','Rol sin permiso sobre clientes.');
        if ( ! GE_WTP_Customer_Tax_UI::can_edit( $customer_id, $actor_id ) ) { return new WP_Error( 'ge_profile_forbidden', 'Acceso denegado.' ); }
        if ( ! get_userdata( $customer_id ) ) { return new WP_Error( 'ge_profile_customer', 'Cliente inexistente.' ); }
        $id = sanitize_text_field( $input['id'] ?? '' );
        if ( 'default' === $id ) { return new WP_Error( 'ge_profile_default', 'Editá el perfil principal en la ficha.' ); }
        $stored = get_user_meta( $customer_id, self::META, true );
        $stored = is_array( $stored ) ? $stored : array();
        $index = null;
        foreach ( $stored as $key => $profile ) { if ( ( $profile['id'] ?? '' ) === $id ) { $index = $key; break; } }
        if ( $id && null === $index ) { return new WP_Error( 'ge_profile_missing', 'Perfil no encontrado.' ); }
        $old = null === $index ? array() : $stored[ $index ];
        if ( ! empty( $input['archive'] ) ) {
            $profile = $old;
            $profile['active'] = false;
        } else {
            try { $profile = GE_WTP_Billing::normalize_profile( $input ); }
            catch ( InvalidArgumentException $error ) { return new WP_Error( 'ge_profile_invalid', $error->getMessage() ); }
            $profile = array_merge( $profile, GE_WTP_Customer_Tax_UI::metadata( $customer_id, $id, array_merge( $profile, array( 'tax_preview_token' => $input['tax_preview_token'] ?? '' ) ), $old, $actor_id ) );
            $profile['id'] = $id ?: wp_generate_uuid4();
            $profile['label'] = sanitize_text_field( $input['label'] ?? '' );
            if ( ! $profile['label'] ) { return new WP_Error( 'ge_profile_label', 'Indicá la sucursal o el nombre del perfil.' ); }
            $profile['branch'] = sanitize_text_field( $input['branch'] ?? '' );
            $profile['contact_name'] = sanitize_text_field( $input['contact_name'] ?? '' );
            $profile['contact_phone'] = sanitize_text_field( $input['contact_phone'] ?? '' );
            $profile['active'] = true;
            $profile['is_default'] = ! empty( $input['is_default'] );
            $profile['created_at'] = $old['created_at'] ?? gmdate( 'c' );
        }
        $profile['updated_at'] = gmdate( 'c' );
        $profile['updated_by'] = (int) $actor_id;
        if ( ! empty( $profile['is_default'] ) ) { foreach ( $stored as &$other ) { $other['is_default'] = false; } unset( $other ); }
        if ( null === $index ) { $stored[] = $profile; } else { $stored[ $index ] = $profile; }
        update_user_meta( $customer_id, self::META, $stored );
        add_user_meta( $customer_id, '_ge_billing_audit', array( 'at' => gmdate( 'c' ), 'actor_id' => $actor_id, 'profile_id' => $profile['id'], 'action' => ! empty( $input['archive'] ) ? 'archive' : ( $old ? 'update' : 'create' ) ) );
        return $profile;
    }

    public static function handle_save() {
        $customer_id = absint( $_POST['customer_id'] ?? 0 );
        check_admin_referer( 'ge_customer_billing_profile_' . $customer_id );
        try { $result = self::save( $customer_id, wp_unslash( $_POST ), get_current_user_id() ); } catch ( InvalidArgumentException $error ) { $result = new WP_Error( 'ge_profile_invalid', $error->getMessage() ); }
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 422 ) ); }
        $url = (int) get_current_user_id() === (int) $customer_id ? GE_WTP_Portal::portal_url( 'perfil' ) : GE_WTP_Staff_Portal::portal_url( 'customers', array( 'customer_id' => $customer_id, 'billing_status' => is_wp_error( $result ) ? $result->get_error_code() : 'saved' ) );
        wp_safe_redirect( $url );
        exit;
    }

    public static function ajax_options() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'ge_manage_operations' ) ) { wp_send_json_error( 'Acceso denegado.', 403 ); }
        check_ajax_referer( 'ge_customer_branch_options' );
        $email = sanitize_email( wp_unslash( $_GET['email'] ?? '' ) );
        $customer_id = absint( email_exists( $email ) );
        wp_send_json_success( array( 'customer_id' => $customer_id, 'profiles' => $customer_id ? array_map( function ( $profile ) { return array( 'id' => $profile['id'], 'label' => $profile['label'], 'cuit' => $profile['cuit'], 'legal_name' => $profile['legal_name'] ?? '', 'vat_status' => $profile['vat_status'] ?? '', 'fiscal_address' => $profile['fiscal_address'] ?? '', 'is_default' => ! empty( $profile['is_default'] ) ); }, self::profiles( $customer_id ) ) : array(), 'addresses' => $customer_id ? array_map( function ( $index, $address ) { return array( 'id' => (string) ( $address['id'] ?? $index ), 'label' => $address['label'] ?: $address['street'], 'street' => $address['street'] ); }, array_keys( GE_WTP_Customers::addresses( $customer_id ) ), GE_WTP_Customers::addresses( $customer_id ) ) : array() ) );
    }

    public static function render_staff( $customer_id ) {
        echo '<div class="ge-workspace-branches">';
        if ( isset( $_GET['billing_status'] ) ) { echo '<p>' . esc_html( 'saved' === $_GET['billing_status'] ? 'Perfil guardado.' : 'No se pudo guardar el perfil. Revisá los datos fiscales.' ) . '</p>'; }
        foreach ( self::profiles( $customer_id, true ) as $profile ) {
            if ( 'default' === $profile['id'] ) { continue; }
            echo '<details class="ge-workspace-item"><summary><strong>' . esc_html( $profile['label'] ) . '</strong><span>' . esc_html( $profile['legal_name'] ?: 'Sin razón social' ) . ' · ' . esc_html( $profile['cuit'] ?: 'Sin CUIT' ) . ( empty( $profile['active'] ) ? ' · Inactivo' : '' ) . '</span></summary>';
            if ( ! empty( $profile['active'] ) ) { self::form( $customer_id, $profile ); }
            echo '</details>';
        }
        echo '<details class="ge-workspace-item"><summary><strong>Agregar perfil</strong><span>Otra sucursal o razón social</span></summary>';
        self::form( $customer_id, array() );
        echo '</details></div>';
    }

    public static function render_order_summary( $order, $staff = false ) {
        GE_WTP_Billing_Issuers::render_order( $order, $staff );
        $profile = $order->get_meta( '_ge_billing_profile_snapshot', true );
        if ( ! is_array( $profile ) || ! $profile ) {
            $billing = $order->get_meta( '_ge_commercial_billing_snapshot', true );
            $profile = is_array( $billing ) ? ( $billing['profile'] ?? array() ) : array();
        }
        $tax = $order->get_meta( '_ge_customer_tax_decision', true );
        if ( $staff && is_array( $tax ) && $tax ) { echo '<p>' . esc_html( GE_WTP_Customer_Tax_UI::decision_label( array( 'customer_tax_decision' => $tax, 'customer_billing_profile' => $profile ) ) ) . '</p>'; }
        $delivery = $order->get_meta( '_ge_delivery_snapshot', true );
        $delivery = is_array( $delivery ) ? $delivery : array();
        $customer = get_userdata( $order->get_customer_id() );
        echo '<section class="' . ( $staff ? 'ge-admin-panel' : 'ge-panel' ) . ' ge-order-branch-summary"><h2>Cliente, facturación y entrega</h2>';
        echo '<p><strong>Cliente comercial:</strong> ' . esc_html( $customer ? $customer->display_name : ( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ) ) . '</p>';
        echo '<p><strong>Facturar a:</strong> ' . esc_html( $profile ? ( ( $profile['label'] ?? 'Perfil principal' ) . ' · ' . ( $profile['legal_name'] ?: 'Sin razón social' ) . ( $staff && ! empty( $profile['cuit'] ) ? ' · CUIT ' . $profile['cuit'] : '' ) ) : ( $order->get_billing_company() ?: 'Datos históricos del pedido' ) ) . '</p>';
        echo '<p><strong>Entregar en:</strong> ' . esc_html( $delivery ? ( ( $delivery['label'] ?? 'Destino' ) . ' · ' . ( $delivery['street'] ?? '' ) ) : ( $order->get_shipping_address_1() ?: 'A coordinar' ) ) . '</p>';
        echo '</section>';
    }

    private static function form( $customer_id, $profile ) {
        $existing = ! empty( $profile['id'] );
        echo '<form class="ge-profile-fields ge-workspace-form" data-ge-workspace-form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_customer_billing_profile"><input type="hidden" name="customer_id" value="' . esc_attr( $customer_id ) . '"><input type="hidden" name="id" value="' . esc_attr( $profile['id'] ?? '' ) . '">';
        wp_nonce_field( 'ge_customer_billing_profile_' . $customer_id );
        foreach ( array( 'label' => 'Sucursal / perfil', 'branch' => 'Sede', 'legal_name' => 'Razón social', 'cuit' => 'CUIT', 'fiscal_address' => 'Domicilio fiscal', 'billing_email' => 'Email de facturación', 'contact_name' => 'Contacto', 'contact_phone' => 'Teléfono' ) as $key => $label ) { echo '<label>' . esc_html( $label ) . '<input name="' . esc_attr( $key ) . '" value="' . esc_attr( $profile[ $key ] ?? '' ) . '" maxlength="220"></label>'; }
        GE_WTP_Customer_Tax_UI::controls( $customer_id, $profile['id'] ?? '', $profile );
        echo '<label><input type="checkbox" name="is_default" value="1"' . checked( ! empty( $profile['is_default'] ), true, false ) . '> Usar por defecto</label>';
        echo '<label>Condición fiscal<select name="vat_status">';
        foreach ( array( '' => 'Seleccionar', 'registered' => 'Responsable inscripto', 'monotributo' => 'Monotributista', 'exempt' => 'Exento', 'final_consumer' => 'Consumidor final' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $profile['vat_status'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label>';
        if ( GE_WTP_Customer_Tax_UI::stage() < 2 ) {
            echo '<label>Modalidad<select name="billing_mode"><option value="common">Común</option><option value="invoice_a"' . selected( $profile['billing_mode'] ?? '', 'invoice_a', false ) . '>Requiere A si el emisor puede emitirla</option></select></label>';
        } else { echo '<input type="hidden" name="billing_mode" value="' . esc_attr( $profile['billing_mode'] ?? 'common' ) . '"><p>El comprobante se sugiere según el emisor y la condición fiscal; requiere revisión del personal.</p>'; }
        echo '<div class="ge-workspace-save"><span data-ge-save-state aria-live="polite">Sin cambios</span><button type="submit">' . ( $existing ? 'Guardar' : 'Agregar perfil' ) . '</button></div>';
        if ( $existing ) { echo '<button type="submit" name="archive" value="1" onclick="return confirm(\'¿Archivar este perfil?\')">Desactivar perfil</button>'; }
        echo '</form>';
    }
}
