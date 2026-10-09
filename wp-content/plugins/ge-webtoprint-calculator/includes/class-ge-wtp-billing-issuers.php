<?php
defined( 'ABSPATH' ) || exit;

/** Issuers are independent from customer billing profiles. No secrets belong here. */
final class GE_WTP_Billing_Issuers {
    const OPTION = 'ge_billing_issuer_profiles_v1';
    const AUDIT = 'ge_issuer_audit';
    const ORDER_META = '_ge_billing_issuer_snapshot';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_type' ) );
        add_action( 'admin_post_ge_save_issuer_profile', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_ge_change_order_issuer', array( __CLASS__, 'handle_order_change' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }
    public static function enqueue() { wp_enqueue_style( 'ge-billing-issuers', GE_WTP_PLUGIN_URL . 'assets/css/billing-issuers.css', array(), (string) filemtime( GE_WTP_PLUGIN_DIR . 'assets/css/billing-issuers.css' ) ); }
    public static function register_type() {
        register_post_type( self::AUDIT, array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'supports' => array( 'title' ), 'rewrite' => false ) );
    }
    public static function can_manage( $actor ) {
        return user_can( $actor, 'manage_options' ) || user_can( $actor, 'ge_manage_billing_issuers' );
    }
    public static function all() { return (array) get_option( self::OPTION, array() ); }
    public static function get( $id ) { $all = self::all(); return $all[ $id ] ?? null; }
    public static function valid_cuit( $cuit ) {
        if ( ! preg_match( '/^[0-9]{11}$/D', $cuit ) ) { return false; }
        $sum = 0; $weights = array( 5,4,3,2,7,6,5,4,3,2 );
        for ( $i = 0; $i < 10; $i++ ) { $sum += (int) $cuit[$i] * $weights[$i]; }
        return (int) $cuit[10] === ( 11 - $sum % 11 ) % 11;
    }
    public static function normalize( $raw, $id ) {
        $p = array( 'id' => sanitize_key( $id ), 'schema_version' => 1 );
        foreach ( array( 'display_name','legal_name','iibb','fiscal_address','locality','province','postal_code','country','contact_email','contact_phone','commercial_brand','point_of_sale' ) as $key ) {
            $p[$key] = sanitize_text_field( $raw[$key] ?? '' );
        }
        $p['cuit'] = preg_replace( '/[^0-9]/', '', (string) ( $raw['cuit'] ?? '' ) );
        if ( ! $p['id'] || ! $p['legal_name'] || ! self::valid_cuit( $p['cuit'] ) ) { return new WP_Error( 'ge_issuer_identity', 'Razón social y CUIT válido del emisor son obligatorios.' ); }
        if ( $p['contact_email'] && ! is_email( $p['contact_email'] ) ) { return new WP_Error( 'ge_issuer_email', 'Email del emisor inválido.' ); }
        $p['vat_status'] = sanitize_key( $raw['vat_status'] ?? '' );
        if ( ! in_array( $p['vat_status'], array( '', 'registered','monotributo','exempt' ), true ) ) { return new WP_Error( 'ge_issuer_vat', 'Condición fiscal inválida.' ); }
        $p['invoice_types_allowed'] = array_values( array_intersect( array( 'A','B','C' ), (array) ( $raw['invoice_types_allowed'] ?? array() ) ) );
        $p['default_for_scenarios'] = array_values( array_intersect( array( 'invoice_a','common' ), (array) ( $raw['default_for_scenarios'] ?? array() ) ) );
        $p['active'] = ! empty( $raw['active'] );
        $p['relationship_confirmed'] = ! empty( $raw['relationship_confirmed'] );
        $p['verification_status'] = in_array( $raw['verification_status'] ?? '', array( 'pending','verified' ), true ) ? $raw['verification_status'] : 'pending';
        $p['tax_rate_basis_points'] = (int) ( $raw['tax_rate_basis_points'] ?? 0 );
        if ( $p['tax_rate_basis_points'] < 0 || $p['tax_rate_basis_points'] > 10000 ) { return new WP_Error( 'ge_issuer_rate', 'Tasa fuera de rango.' ); }
        foreach ( array( 'common_price_policy','invoice_a_price_policy' ) as $key ) { $p[$key] = in_array( $raw[$key] ?? '', array( 'tax_exclusive','tax_inclusive' ), true ) ? $raw[$key] : ''; }
        foreach ( array( 'credentials_ref','cert_ref' ) as $key ) {
            $ref = (string) ( $raw[$key] ?? '' );
            if ( $ref && ! preg_match( '/^[a-z][a-z0-9-]{2,79}$/D', $ref ) ) { return new WP_Error( 'ge_issuer_ref', 'Usá sólo una referencia simbólica al almacén seguro.' ); }
            $p[$key] = $ref;
        }
        // The real ARCA adapter is intentionally absent in v1.
        $p['arca_integration_status'] = $p['credentials_ref'] && $p['cert_ref'] ? 'references_configured' : 'not_configured';
        if ( 'verified' === $p['verification_status'] ) {
            $allowed = 'registered' === $p['vat_status'] ? array( 'A','B' ) : ( in_array( $p['vat_status'], array( 'monotributo','exempt' ), true ) ? array( 'C' ) : array() );
            if ( ! $p['relationship_confirmed'] || ! $p['point_of_sale'] || ! $p['fiscal_address'] || ! $p['invoice_types_allowed'] || array_diff( $p['invoice_types_allowed'], $allowed ) || ! $p['common_price_policy'] || ! $p['invoice_a_price_policy'] || ( 'registered' === $p['vat_status'] ? $p['tax_rate_basis_points'] <= 0 : $p['tax_rate_basis_points'] !== 0 ) ) {
                return new WP_Error( 'ge_issuer_verification', 'Verificar requiere relación real confirmada, condición fiscal, domicilio, punto de venta, comprobantes y políticas compatibles.' );
            }
        }
        return $p;
    }
    public static function save( $id, $raw, $actor, $reason, $expected_revision = null ) {
        if ( ! self::can_manage( $actor ) ) { return new WP_Error( 'ge_issuer_forbidden', 'No tenés permiso para editar emisores.' ); }
        $reason = sanitize_textarea_field( $reason );
        if ( ! $reason ) { return new WP_Error( 'ge_issuer_reason', 'Indicá el motivo del cambio.' ); }
        if ( ! add_option( 'ge_issuer_catalog_lock', gmdate( 'c' ), '', false ) ) { return new WP_Error( 'ge_issuer_busy', 'Otro cambio está en curso. Reintentá.' ); }
        try {
            $all = self::all(); $old = $all[$id] ?? array();
            if ( null !== $expected_revision && (int) $expected_revision !== (int) ( $old['revision'] ?? 0 ) ) { return new WP_Error( 'ge_issuer_conflict', 'El perfil cambió; volvé a abrirlo.' ); }
            $p = self::normalize( $raw, $id ); if ( is_wp_error( $p ) ) { return $p; }
            if ( $old && $p['cuit'] !== $old['cuit'] && ( $p['credentials_ref'] || $p['cert_ref'] ) ) { return new WP_Error( 'ge_issuer_credentials_owner', 'Al cambiar CUIT, retirás las referencias previas y configurás credenciales propias del nuevo emisor.' ); }
            foreach ( $all as $other_id => $other ) {
                if ( $other_id !== $id ) { foreach ( array( 'credentials_ref', 'cert_ref' ) as $ref_key ) { if ( $p[$ref_key] && $p[$ref_key] === ( $other[$ref_key] ?? '' ) ) { return new WP_Error( 'ge_issuer_credentials_shared', 'Cada emisor debe tener referencias de credenciales y certificados propias.' ); } } }
                if ( $other_id !== $id && $p['active'] && ! empty( $other['active'] ) && array_intersect( $p['default_for_scenarios'], (array) ( $other['default_for_scenarios'] ?? array() ) ) ) { return new WP_Error( 'ge_issuer_default', 'Ya existe un emisor sugerido para ese escenario.' ); }
            }
            $p['revision'] = (int) ( $old['revision'] ?? 0 ) + 1;
            $p['created_at'] = $old['created_at'] ?? gmdate( 'c' ); $p['updated_at'] = gmdate( 'c' ); $p['updated_by'] = (int) $actor;
            $p['verified_at'] = 'verified' === $p['verification_status'] ? gmdate( 'c' ) : '';
            $p['source'] = sanitize_text_field( $raw['source'] ?? 'staff' );
            $audit = self::audit( 'profile_saved', $actor, $reason, array( 'before' => $old, 'after' => $p ) );
            if ( is_wp_error( $audit ) ) { return $audit; }
            $all[$id] = $p; update_option( self::OPTION, $all, false ); return $p;
        } finally { delete_option( 'ge_issuer_catalog_lock' ); }
    }
    public static function audit( $event, $actor, $reason, $data ) {
        $id = wp_insert_post( array( 'post_type' => self::AUDIT, 'post_status' => 'private', 'post_title' => $event, 'post_author' => (int) $actor ), true );
        if ( ! is_wp_error( $id ) ) { update_post_meta( $id, '_ge_issuer_event', array( 'at' => gmdate( 'c' ), 'event' => $event, 'actor' => (int) $actor, 'reason' => $reason, 'data' => $data ) ); }
        return $id;
    }
    /** Explicit deployment migration; never run lazily on public requests. */
    public static function seed( $actor ) {
        if(defined('GE_ORGANIZATION_INSTANCE_ID') && GE_ORGANIZATION_INSTANCE_ID!=='graph-express')return new WP_Error('organization_seed','Los emisores legacy pertenecen exclusivamente a Graph Express.');
        $base = array( 'active' => true, 'verification_status' => 'pending', 'relationship_confirmed' => false, 'country' => 'AR', 'commercial_brand' => 'Graphex', 'source' => 'Leo supplied 2026-10-02; official verification pending' );
        $seeds = array(
            'leonardo-c' => array( 'display_name' => 'Leonardo Ayala', 'legal_name' => 'AYALA LEONARDO JAVIER', 'cuit' => '23336924529', 'iibb' => '23336924529', 'fiscal_address' => 'SAN MARTIN AV. 6177 Piso: PB', 'locality' => 'CIUDAD AUTONOMA DE BUENOS AIRES', 'province' => 'CIUDAD AUTONOMA DE BUENOS AIRES', 'postal_code' => '1419', 'vat_status' => '', 'invoice_types_allowed' => array( 'C' ), 'default_for_scenarios' => array( 'common' ), 'relationship_confirmed' => true ),
            'mardones-a' => array( 'display_name' => 'Mardones Espinoza Eduardo Andrés', 'legal_name' => 'MARDONES ESPINOZA EDUARDO ANDRES', 'cuit' => '20948548934', 'fiscal_address' => 'SUCRE 5096 - Piso: P.B.', 'locality' => 'EZPELETA', 'province' => '', 'postal_code' => '1882', 'vat_status' => 'registered', 'invoice_types_allowed' => array( 'A' ), 'default_for_scenarios' => array( 'invoice_a' ) ),
        );
        foreach ( $seeds as $id => $seed ) { if ( ! self::get( $id ) ) { $result = self::save( $id, array_merge( $base, $seed ), $actor, 'Alta v1: datos aportados por Leo, sin habilitación fiscal inferida', 0 ); if ( is_wp_error( $result ) ) { return $result; } } }
        return true;
    }
    public static function suggestion( $customer_profile, $net = 0 ) {
        if ( GE_WTP_Customer_Tax_UI::stage() >= 2 ) {
            $tax = GE_WTP_Customer_Tax::suggestion( $customer_profile );
            $tax['resolver'] = GE_WTP_Billing::resolve_net_quote( self::entity( self::get( $tax['issuer_profile_id'] ) ?: array() ), (array) $customer_profile, (int) $net );
            return $tax;
        }
        $scenario = 'invoice_a' === ( $customer_profile['billing_mode'] ?? '' ) || in_array( $customer_profile['vat_status'] ?? '', array( 'registered','monotributo' ), true ) ? 'invoice_a' : 'common';
        foreach ( self::all() as $p ) {
            if ( ! empty( $p['active'] ) && in_array( $scenario, $p['default_for_scenarios'], true ) ) {
                $resolution = GE_WTP_Billing::resolve_net_quote( self::entity( $p ), (array) $customer_profile, (int) $net );
                return array( 'issuer_profile_id' => $p['id'], 'scenario' => $scenario, 'rule_version' => 'commercial-suggestion-v1', 'resolver' => $resolution, 'requires_review' => true, 'customer_verified' => ! empty( $customer_profile['verified_at'] ) );
            }
        }
        return array( 'issuer_profile_id' => 'unknown', 'scenario' => $scenario, 'rule_version' => 'commercial-suggestion-v1', 'requires_review' => true );
    }
    public static function capture( $p ) {
        $s = $p; unset( $s['credentials_ref'], $s['cert_ref'] );
        $s['captured_at'] = gmdate( 'c' ); $s['status'] = 'selected';
        $s['snapshot_hash'] = hash( 'sha256', wp_json_encode( $s ) ); return $s;
    }
    public static function unknown() { return array( 'id' => 'unknown', 'status' => 'legacy_unknown', 'legal_name' => '', 'schema_version' => 1 ); }
    public static function from_snapshot( $s ) { return ! empty( $s['issuer_snapshot'] ) ? $s['issuer_snapshot'] : self::unknown(); }
    public static function choose( $profile, $args, $actor, $previous = null, $require_review = true ) {
        if ( GE_WTP_Customer_Tax_UI::stage() >= 3 && $require_review && ! user_can( $actor, 'manage_woocommerce' ) && ! user_can( $actor, 'ge_manage_operations' ) ) { return new WP_Error( 'ge_tax_review_forbidden', 'La revisión fiscal requiere personal autorizado.' ); }
        $suggestion = self::suggestion( $profile );
        $old = is_array( $previous ) ? self::from_snapshot( $previous ) : null;
        $requested = sanitize_key( $args['issuer_profile_id'] ?? '' );
        // Ordinary revisions retain the exact issuer data; choosing a new ID or refresh requires permission and a reason.
        if ( $old && ( ! $requested || $requested === $old['id'] ) && empty( $args['issuer_refresh'] ) ) {
            $prior_profile = $previous['customer_billing_profile'] ?? $previous['billing']['profile'] ?? null;
            $changed_profile = null !== $prior_profile && $prior_profile !== $profile;
            if ( GE_WTP_Customer_Tax_UI::stage() >= 3 && $require_review && $changed_profile && ! $requested && empty( $args['customer_tax_confirm'] ) ) { return new WP_Error( 'ge_tax_review', 'Seleccioná el emisor para el receptor elegido.' ); }
            $review = $previous['issuer_suggestion'] ?? $suggestion;
            if ( $changed_profile || ! empty( $args['customer_tax_confirm'] ) ) { $review['reviewed_by'] = (int) $actor; $review['reviewed_at'] = gmdate( 'c' ); }
            return array( 'issuer' => $old, 'suggestion' => $review );
        }
        $id = $requested ?: $suggestion['issuer_profile_id'];
        if ( $old || $id !== $suggestion['issuer_profile_id'] ) {
            if ( ! self::can_manage( $actor ) ) { return new WP_Error( 'ge_issuer_forbidden', 'Sólo un rol autorizado puede cambiar o reemplazar el emisor.' ); }
            if ( $old && empty( trim( $args['issuer_change_reason'] ?? '' ) ) ) { return new WP_Error( 'ge_issuer_reason', 'Indicá el motivo del cambio de emisor.' ); }
            if ( ! $old && empty( trim( $args['issuer_change_reason'] ?? '' ) ) ) { $args['issuer_change_reason'] = 'Selección explícita del emisor al crear el presupuesto'; }
        }
        $p = self::get( $id );
        if ( ! $p || empty( $p['active'] ) ) { return new WP_Error( 'ge_issuer_missing', 'Seleccioná un emisor activo.' ); }
        if ( GE_WTP_Customer_Tax_UI::stage() >= 3 && $require_review && ! $requested && empty( $args['customer_tax_confirm'] ) ) { return new WP_Error( 'ge_tax_review', 'Seleccioná el emisor en Emisor / facturación.' ); }
        $suggestion['reviewed_by'] = (int) $actor; $suggestion['reviewed_at'] = gmdate( 'c' );
        $suggestion['override_reason'] = sanitize_textarea_field( $args['issuer_change_reason'] ?? '' );
        $suggestion['selected_issuer_id'] = $p['id'];
        return array( 'issuer' => self::capture( $p ), 'suggestion' => $suggestion );
    }
    public static function entity( $p ) {
        return array( 'id' => $p['id'] ?? '', 'active' => $p['active'] ?? false, 'verification_status' => $p['verification_status'] ?? 'pending', 'relationship_confirmed' => $p['relationship_confirmed'] ?? false, 'legal_name' => $p['legal_name'] ?? '', 'cuit' => $p['cuit'] ?? '', 'vat_status' => $p['vat_status'] ?? '', 'point_of_sale' => $p['point_of_sale'] ?? '', 'document_capabilities' => $p['invoice_types_allowed'] ?? array(), 'common_price_policy' => $p['common_price_policy'] ?? '', 'invoice_a_price_policy' => $p['invoice_a_price_policy'] ?? '', 'tax_rate_basis_points' => (int) ( $p['tax_rate_basis_points'] ?? 0 ) );
    }
    public static function ready( $s ) {
        return 'selected' === ( $s['status'] ?? '' ) && 'verified' === ( $s['verification_status'] ?? '' ) && ! empty( $s['relationship_confirmed'] );
    }
    public static function can_publish( $s ) {
        if ( 'unknown' === ( $s['id'] ?? 'unknown' ) ) { return true; }
        $p = self::get( $s['id'] );
        if ( empty( $s['relationship_confirmed'] ) || ! $p || empty( $p['active'] ) || empty( $p['relationship_confirmed'] ) ) { return new WP_Error( 'ge_issuer_relationship', 'Confirmá la relación comercial/contable real del emisor antes de enviar o crear esta operación.' ); }
        return true;
    }
    public static function validate_current( $s ) {
        if ( ! self::ready( $s ) ) { return new WP_Error( 'ge_issuer_pending', 'Emisor pendiente de verificación y relación comercial real.' ); }
        $current = self::get( $s['id'] );
        if ( ! $current || empty( $current['active'] ) || (int) $current['revision'] !== (int) $s['revision'] || 'verified' !== $current['verification_status'] || empty( $current['relationship_confirmed'] ) ) { return new WP_Error( 'ge_issuer_changed', 'El emisor cambió. Revisá una nueva versión explícita antes de cobrar o facturar.' ); }
        return true;
    }
    public static function vat_label( $s ) { $labels = array( 'registered' => 'IVA Responsable Inscripto', 'monotributo' => 'Monotributista', 'exempt' => 'IVA Exento' ); return $labels[$s['vat_status'] ?? ''] ?? 'Condición fiscal pendiente de verificación'; }
    public static function label( $s ) { return 'unknown' === ( $s['id'] ?? 'unknown' ) ? 'Emisor histórico no registrado · requiere revisión' : ( $s['legal_name'] . ' · CUIT ' . $s['cuit'] . ' · ' . self::vat_label( $s ) ); }
    public static function render_summary( $s ) { if ( ! GE_WTP_Staff_Portal::can_access() || GE_WTP_Portal::is_staff_preview() ) { return; } echo '<p class="ge-issuer-summary"><strong>Emisor / Facturación:</strong> ' . esc_html( self::label( self::from_snapshot( $s ) ) ) . '</p>'; }
    public static function render_picker( $s = array() ) {
        $old = self::from_snapshot( $s ); $can = self::can_manage( get_current_user_id() );
        echo '<section class="ge-production-card ge-billing-issuer-picker"><h2>Emisor / Facturación</h2><label>Emisor seleccionado<select name="issuer_profile_id"' . ( ! $can ? ' disabled' : '' ) . '><option value="">' . esc_html( $s ? 'Conservar emisor registrado' : 'Sugerir según perfil fiscal del cliente' ) . '</option>';
        foreach ( self::all() as $p ) { if ( $p['active'] ) { echo '<option value="' . esc_attr( $p['id'] ) . '"' . selected( $old['id'], $p['id'], false ) . '>' . esc_html( ( $p['display_name'] ?? $p['legal_name'] ) . ' · ' . self::vat_label( $p ) . ' · previsto ' . implode( '/', $p['invoice_types_allowed'] ) ) . '</option>'; } }
        echo '</select></label><p>La sugerencia es comercial y requiere revisión. El emisor debe corresponder a quien realmente facture.</p>';
        if ( $can ) { echo '<label>Motivo del cambio / selección manual<input name="issuer_change_reason" maxlength="500"></label>'; if ( $s ) { echo '<label><input type="checkbox" name="issuer_refresh" value="1"> Actualizar expresamente los datos del emisor en una nueva versión</label>'; } }
        if ( GE_WTP_Customer_Tax_UI::stage() >= 3 ) { echo '<label><input type="checkbox" name="customer_tax_confirm" value="1"> Revisé el emisor real y el comprobante sugerido</label>'; }
        if ( $s ) { self::render_summary( $s ); } echo '</section>';
    }
    public static function render_settings() {
        echo '<section class="ge-admin-panel ge-billing-issuers-settings"><h2>Perfiles emisores</h2><p>Datos aportados y configuración verificada se distinguen. Cada operación conserva su emisor; editar estos perfiles no modifica los históricos.</p>';
        if ( ! self::can_manage( get_current_user_id() ) ) { echo '<p>Sólo roles autorizados pueden editar emisores.</p></section>'; return; }
        foreach ( self::all() as $p ) {
            echo '<details><summary>' . esc_html( ( $p['display_name'] ?? $p['legal_name'] ) . ' · ' . $p['verification_status'] ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_save_issuer_profile"><input type="hidden" name="issuer_id" value="' . esc_attr( $p['id'] ) . '"><input type="hidden" name="expected_revision" value="' . esc_attr( $p['revision'] ) . '">'; wp_nonce_field( 'ge_save_issuer_profile' );
            foreach ( array( 'display_name' => 'Nombre visible','legal_name' => 'Razón social','cuit' => 'CUIT','iibb' => 'IIBB','fiscal_address' => 'Domicilio fiscal','locality' => 'Localidad','province' => 'Provincia','postal_code' => 'Código postal','country' => 'País','contact_email' => 'Email','contact_phone' => 'Teléfono','commercial_brand' => 'Marca comercial','point_of_sale' => 'Punto de venta','tax_rate_basis_points' => 'Tasa IVA en puntos básicos','credentials_ref' => 'Referencia segura ARCA','cert_ref' => 'Referencia segura certificado' ) as $key => $label ) { echo '<p><label>' . esc_html( $label ) . '<input name="profile[' . esc_attr( $key ) . ']" maxlength="220" value="' . esc_attr( $p[$key] ?? '' ) . '"></label></p>'; }
            foreach ( array( 'vat_status' => array( '' => 'Pendiente', 'registered' => 'IVA Responsable Inscripto','monotributo' => 'Monotributista','exempt' => 'Exento' ), 'verification_status' => array( 'pending' => 'Pendiente','verified' => 'Verificado oficialmente' ), 'common_price_policy' => array( '' => 'Pendiente','tax_exclusive' => 'Neto + impuesto','tax_inclusive' => 'Impuesto incluido' ), 'invoice_a_price_policy' => array( '' => 'Pendiente','tax_exclusive' => 'Neto + impuesto','tax_inclusive' => 'Impuesto incluido' ) ) as $key => $choices ) {
                echo '<p><label>' . esc_html( array( 'vat_status' => 'Condición fiscal','verification_status' => 'Verificación','common_price_policy' => 'Política cliente común','invoice_a_price_policy' => 'Política cliente A' )[$key] ) . '<select name="profile[' . esc_attr( $key ) . ']">'; foreach ( $choices as $v => $label ) { echo '<option value="' . esc_attr( $v ) . '"' . selected( $p[$key], $v, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label></p>';
            }
            foreach ( array( 'invoice_types_allowed' => array( 'A' => 'Comprobante A','B' => 'Comprobante B','C' => 'Comprobante C' ), 'default_for_scenarios' => array( 'common' => 'Sugerir en operación común / C','invoice_a' => 'Sugerir con perfil A' ) ) as $key => $choices ) { foreach ( $choices as $v => $label ) { echo '<p><label><input type="checkbox" name="profile[' . esc_attr( $key ) . '][]" value="' . esc_attr( $v ) . '"' . checked( in_array( $v, $p[$key], true ), true, false ) . '> ' . esc_html( $label ) . '</label></p>'; } }
            foreach ( array( 'active' => 'Activo para selección comercial','relationship_confirmed' => 'Relación comercial/contable real confirmada' ) as $key => $label ) { echo '<p><label><input type="checkbox" name="profile[' . $key . ']" value="1"' . checked( $p[$key], true, false ) . '> ' . esc_html( $label ) . '</label></p>'; }
            echo '<p><label>Motivo y referencia de verificación<input name="reason" required maxlength="500"></label></p><button type="submit">Guardar perfil emisor</button><p>ARCA: ' . esc_html( $p['arca_integration_status'] ) . '. La emisión automática no está habilitada.</p></form></details>';
        }
        echo '</section>';
    }
    public static function handle_save() {
        if ( ! self::can_manage( get_current_user_id() ) ) { wp_die( 'Acceso denegado.', 403 ); } check_admin_referer( 'ge_save_issuer_profile' );
        $result = self::save( sanitize_key( $_POST['issuer_id'] ?? '' ), wp_unslash( (array) ( $_POST['profile'] ?? array() ) ), get_current_user_id(), wp_unslash( $_POST['reason'] ?? '' ), absint( $_POST['expected_revision'] ?? 0 ) );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 422 ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'settings', array( 'category' => 'billing', 'saved' => '1' ) ) ); exit;
    }
    public static function order_snapshot( $order ) { $s = $order->get_meta( self::ORDER_META, true ); return is_array( $s ) && $s ? $s : self::unknown(); }
    public static function inherit( $order, $snapshot ) { if(!empty($snapshot['organization_snapshot']))$order->update_meta_data('_ge_organization_snapshot',$snapshot['organization_snapshot']); if ( isset( $snapshot['receiver_snapshot'] ) ) { $order->update_meta_data( '_ge_billing_profile_snapshot', $snapshot['receiver_snapshot'] ); } if ( isset( $snapshot['billing_resolution'] ) ) { $order->update_meta_data( '_ge_quote_billing_resolution', $snapshot['billing_resolution'] ); } if ( ! empty( $snapshot['customer_tax_decision'] ) ) { $order->update_meta_data( '_ge_customer_tax_decision', $snapshot['customer_tax_decision'] ); } $s = self::from_snapshot( $snapshot ); $order->update_meta_data( self::ORDER_META, $s ); $order->update_meta_data( '_ge_billing_issuer_profile_id', $s['id'] ); }
    public static function render_order( $order, $staff ) {
        self::render_summary( array( 'issuer_snapshot' => self::order_snapshot( $order ) ) );
        if ( ! $staff || ! self::can_manage( get_current_user_id() ) ) { return; }
        echo '<details><summary>Cambiar emisor del pedido</summary><p>Un pedido cobrado o con documentos fiscales requiere un flujo de rectificación. El cambio permitido genera una versión explícita y recalcula el IVA.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_change_order_issuer"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="issuer_expected_hash" value="' . esc_attr( hash( 'sha256', wp_json_encode( self::order_snapshot( $order ) ) ) ) . '">'; wp_nonce_field( 'ge_change_order_issuer' ); self::render_picker( array( 'issuer_snapshot' => self::order_snapshot( $order ) ) ); echo '<button type="submit">Confirmar cambio y recalcular</button></form></details>';
    }
    public static function change_order( $order, $args, $actor ) {
        if ( ! self::can_manage( $actor ) ) { return new WP_Error( 'ge_issuer_forbidden', 'No tenés permiso.' ); }
        if ( class_exists( 'GE_WTP_ARCA_Emission' ) && GE_WTP_ARCA_Emission::locks_order( $order->get_id() ) ) { return new WP_Error( 'ge_issuer_order_locked', 'Pedido con solicitud fiscal enviada: consultá su registro antes de cualquier rectificación.' ); }
        if ( empty( trim( $args['issuer_change_reason'] ?? '' ) ) ) { return new WP_Error( 'ge_issuer_reason', 'Indicá el motivo del cambio de emisor.' ); }
        if ( $order->is_paid() || $order->get_date_paid() || (int) $order->get_meta( '_ge_amount_paid_cents', true ) > 0 || $order->get_meta( '_ge_commercial_initial_payment_order', true ) || $order->get_meta( '_ge_commercial_payment_order', true ) || $order->get_meta( '_ge_payment_confirmed_at', true ) || $order->get_meta( '_ge_source_quote_id', true ) || ( class_exists( 'GE_WTP_Documents' ) && GE_WTP_Documents::issued_documents( $order->get_id(), true ) ) ) { return new WP_Error( 'ge_issuer_order_locked', 'Pedido vinculado a presupuesto, cobro o documento fiscal: requiere rectificación; no se cambia silenciosamente.' ); }
        $old = self::order_snapshot( $order );
        if ( ! hash_equals( hash( 'sha256', wp_json_encode( $old ) ), (string) ( $args['issuer_expected_hash'] ?? '' ) ) ) { return new WP_Error( 'ge_issuer_conflict', 'El pedido cambió. Volvé a abrirlo.' ); }
        $profile = (array) $order->get_meta( '_ge_billing_profile_snapshot', true );
        $chosen = self::choose( $profile, $args, $actor, array( 'issuer_snapshot' => $old ) ); if ( is_wp_error( $chosen ) ) { return $chosen; }
        $s = $chosen['issuer']; $valid = self::validate_current( $s ); if ( is_wp_error( $valid ) ) { return $valid; }
        if ( $s === $old ) { return new WP_Error( 'ge_issuer_unchanged', 'El emisor no cambió. Para actualizar sus datos, marcá la actualización explícita.' ); }
        if ( $order->get_items( 'fee' ) || $order->get_items( 'shipping' ) ) { return new WP_Error( 'ge_issuer_order_complex', 'Revisá cargos y envío antes de cambiar emisor; el cálculo requiere conciliación explícita.' ); }
        $net = 0; foreach ( $order->get_items() as $item ) { $net += GE_WTP_Quote_Balance::cents( (string) $item->get_total() ); }
        $resolution = GE_WTP_Billing::resolve_net_quote( self::entity( $s ), $profile, $net ); if ( $resolution['blockers'] ) { return new WP_Error( 'ge_issuer_order_tax', 'El resolver fiscal bloqueó el cambio.', $resolution['blockers'] ); }
        if ( $resolution['tax_cents'] > 0 && ! absint( get_option( 'ge_commercial_tax_rate_id', 0 ) ) ) { return new WP_Error( 'ge_issuer_tax_mapping', 'Configurá la tasa de IVA en WooCommerce antes del cambio.' ); }
        $audit = self::audit( 'order_issuer_changed', $actor, sanitize_textarea_field( $args['issuer_change_reason'] ?? '' ), array( 'order_id' => $order->get_id(), 'before' => $old, 'after' => $s, 'resolution' => $resolution ) ); if ( is_wp_error( $audit ) ) { return $audit; }
        $remaining = $net; $tax_remaining = $resolution['tax_cents'];
        foreach ( $order->get_items() as $item ) { $base = GE_WTP_Quote_Balance::cents( (string) $item->get_total() ); $tax = $remaining ? intdiv( $tax_remaining * $base, $remaining ) : 0; $item->set_taxes( array( 'total' => array( absint( get_option( 'ge_commercial_tax_rate_id', 0 ) ) => GE_WTP_Quote_Balance::decimal( $tax ) ), 'subtotal' => array( absint( get_option( 'ge_commercial_tax_rate_id', 0 ) ) => GE_WTP_Quote_Balance::decimal( $tax ) ) ) ); $item->save(); $remaining -= $base; $tax_remaining -= $tax; }
        $history = $order->get_meta( '_ge_issuer_history', true ); $history = is_array( $history ) ? $history : array(); $history[] = array( 'before' => $old, 'after' => $s, 'audit_id' => $audit ); $order->update_meta_data( '_ge_issuer_history', $history );
        self::inherit( $order, array( 'issuer_snapshot' => $s ) ); $order->update_meta_data( '_ge_commercial_billing_snapshot', GE_WTP_Billing::snapshot( self::entity( $s ), $profile, $resolution ) );
        $order->update_meta_data( '_ge_final_total_cents', $resolution['total_cents'] ); $order->update_meta_data( '_ge_amount_due_cents', $resolution['total_cents'] );
        $order->update_taxes(); $order->calculate_totals( false ); $order->set_cart_tax( GE_WTP_Quote_Balance::decimal( $resolution['tax_cents'] ) ); $order->set_total( GE_WTP_Quote_Balance::decimal( $resolution['total_cents'] ) ); $order->add_order_note( 'Emisor cambiado explícitamente. Auditoría #' . $audit . '. Motivo: ' . sanitize_textarea_field( $args['issuer_change_reason'] ) ); $order->save(); return true;
    }
    public static function handle_order_change() {
        if ( ! self::can_manage( get_current_user_id() ) ) { wp_die( 'Acceso denegado.', 403 ); } check_admin_referer( 'ge_change_order_issuer' );
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) ); if ( ! $order ) { wp_die( 'Pedido inválido.', 404 ); }
        $lock = 'ge_issuer_order_lock_' . $order->get_id(); if ( ! add_option( $lock, gmdate( 'c' ), '', false ) ) { wp_die( 'Otro cambio está en curso.', 409 ); }
        try { $result = self::change_order( $order, wp_unslash( $_POST ), get_current_user_id() ); } finally { delete_option( $lock ); }
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 422 ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) ); exit;
    }
    /** Contract only. Real credentials, certificate loading and CAE are future adapter responsibilities. */
    public static function arca_request( $order ) {
        $s = self::order_snapshot( $order ); $valid = self::validate_current( $s ); if ( is_wp_error( $valid ) ) { return $valid; }
        $p = self::get( $s['id'] );
        if ( ! $p['credentials_ref'] || ! $p['cert_ref'] ) { return new WP_Error( 'ge_arca_unconfigured', 'Referencias seguras del emisor pendientes.' ); }
        return array( 'issuer_profile_id' => $p['id'], 'issuer_snapshot' => $s, 'credentials_ref' => $p['credentials_ref'], 'cert_ref' => $p['cert_ref'], 'order_id' => $order->get_id(), 'billing_snapshot' => $order->get_meta( '_ge_commercial_billing_snapshot', true ), 'execution_enabled' => false );
    }
}
