<?php
defined( 'ABSPATH' ) || exit;

/** Explicit, authenticated lookup previews. Never accepts verification claims from a form. */
final class GE_WTP_Customer_Tax_UI {
    public static function stage() { return (int) get_option( 'ge_customer_tax_stage_v1', 0 ); }
    private static function checked_label( $profile ) {
        $time = ! empty( $profile['checked_at'] ) ? strtotime( $profile['checked_at'] ) : false;
        return $time ? wp_date( 'd/m/Y H:i', $time ) : '';
    }
    private static function profile_hash( $profile ) {
        $fields = array();
        foreach ( array( 'cuit', 'legal_name', 'vat_status', 'fiscal_address' ) as $key ) { $fields[$key] = (string) ( $profile[$key] ?? '' ); }
        return hash( 'sha256', wp_json_encode( $fields ) );
    }
    public static function init() {
        add_action( 'wp_ajax_ge_customer_tax_lookup', array( __CLASS__, 'lookup' ) );
        add_action( 'wp_ajax_ge_customer_tax_suggestion', array( __CLASS__, 'suggestion' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }
    public static function can_edit( $customer, $actor ) {
        return $actor && ( (int) $customer === (int) $actor || user_can( $actor, 'manage_woocommerce' ) || user_can( $actor, 'ge_manage_operations' ) );
    }
    public static function enqueue() {
        if ( ! is_user_logged_in() || self::stage() < 1 ) { return; }
        wp_enqueue_script( 'ge-customer-tax', GE_WTP_PLUGIN_URL . 'assets/js/customer-tax.js', array(), (string) filemtime( GE_WTP_PLUGIN_DIR . 'assets/js/customer-tax.js' ), true );
        wp_localize_script( 'ge-customer-tax', 'geCustomerTax', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ge_customer_tax' ), 'stage' => self::stage() ) );
    }
    public static function controls( $customer_id = 0, $profile_id = 'default', $profile = array(), $quick = false ) {
        if ( self::stage() < ( $quick ? 3 : 1 ) ) { return; }
        $evidence = GE_WTP_Customer_Tax::resolve( array(), $profile );
        $state = 'verified' === ( $profile['verification_status'] ?? '' ) ? ( ! empty( $evidence['customer_evidence_fresh'] ) ? 'Datos fiscales verificados' : 'Verificación anterior: revisá y actualizá la consulta' ) : 'Datos fiscales pendientes de verificación. Podés cargarlos manualmente.';
        if ( ! empty( $profile['checked_at'] ) ) { $state .= ' · Consulta ' . self::checked_label( $profile ) . ' · ' . ( 'arca_wsci' === ( $profile['source'] ?? '' ) ? 'ARCA' : 'Carga manual' ); }
        echo '<div class="ge-field-wide ge-customer-tax" data-ge-tax data-customer-id="' . esc_attr( $customer_id ) . '" data-profile-id="' . esc_attr( $profile_id ) . '"' . ( $quick ? ' data-quick="1"' : '' ) . '><button type="button" data-ge-tax-search>Buscar datos fiscales</button><input type="hidden" name="tax_preview_token" value=""><p data-ge-tax-state role="status" aria-live="polite">' . esc_html( $state ) . '</p><div data-ge-tax-preview hidden><h3>Revisá los datos encontrados</h3><dl data-ge-tax-data></dl><button type="button" data-ge-tax-confirm>Confirmar estos datos</button><button type="button" data-ge-tax-cancel>Cancelar</button></div></div>';
        if ( self::stage() >= 2 && ! $quick && GE_WTP_Staff_Portal::can_access() && ! GE_WTP_Portal::is_staff_preview() ) {
            $suggestion = GE_WTP_Customer_Tax::suggestion( $profile ); $issuer = GE_WTP_Billing_Issuers::get( $suggestion['issuer_profile_id'] );
            echo '<p>Emisor sugerido: ' . esc_html( $issuer['display_name'] ?? $issuer['legal_name'] ?? 'Pendiente de selección' ) . ' · Comprobante sugerido: ' . esc_html( 'unknown' === $suggestion['suggested_document_class'] ? 'Pendiente' : $suggestion['suggested_document_class'] ) . '. Requiere revisión del personal.</p>';
            self::render_warnings( $suggestion );
        }
    }
    public static function render_warnings( $decision ) {
        $warnings = array_values( array_unique( array_filter( (array) ( $decision['warnings'] ?? array() ) ) ) );
        if ( ! $warnings ) { return; }
        echo '<ul class="ge-tax-warnings" aria-label="Datos fiscales que requieren revisión">';
        foreach ( $warnings as $warning ) { echo '<li>' . esc_html( $warning ) . '</li>'; }
        echo '</ul>';
    }
    public static function quick_controls( $customer = 0 ) {
        if ( self::stage() < 3 ) { return; }
        echo '<section class="ge-production-card"><h2>Datos fiscales del cliente</h2><div class="ge-manual-contact-grid"><label>CUIT<input name="customer_cuit" inputmode="numeric" maxlength="20"></label><label>Razón social<input name="tax_legal_name" maxlength="220"></label><label>Condición fiscal<select name="tax_vat_status"><option value="">Pendiente</option><option value="registered">Responsable inscripto</option><option value="monotributo">Monotributista</option><option value="exempt">Exento</option><option value="final_consumer">Consumidor final</option></select></label><label>Domicilio fiscal<input name="tax_fiscal_address" maxlength="220"></label></div>';
        self::controls( $customer, 'default', array(), true );
        echo '<p>Al guardar se actualizará únicamente el perfil de facturación seleccionado. Dejá el CUIT vacío para conservarlo.</p></section>';
    }
    public static function decision_label( $snapshot ) {
        $decision = $snapshot['customer_tax_decision'] ?? array();
        if ( ! $decision ) { return ''; }
        $profile = $snapshot['billing']['profile'] ?? $snapshot['customer_billing_profile'] ?? array();
        $states = array( 'verified' => 'verificados', 'pending' => 'pendientes', 'manual' => 'declarados manualmente', 'error' => 'consulta con error' );
        $document = $decision['suggested_document_class'] ?? 'unknown';
        return 'Comprobante previsto: ' . ( 'unknown' === $document ? 'pendiente' : $document ) . ' · Datos fiscales: ' . ( $states[$profile['verification_status'] ?? 'pending'] ?? 'pendientes' ) . ' · Revisado por el personal';
    }
    public static function lookup() {
        check_ajax_referer( 'ge_customer_tax', 'nonce' );
        if ( self::stage() < 1 ) { wp_send_json_error( array( 'message' => 'La consulta fiscal todavía no está habilitada.' ), 403 ); }
        $actor = get_current_user_id(); $customer = absint( $_POST['customer_id'] ?? 0 );
        if ( ! $customer && ( user_can( $actor, 'manage_woocommerce' ) || user_can( $actor, 'ge_manage_operations' ) ) ) { $customer = absint( email_exists( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ) ); }
        if ( ! self::can_edit( $customer, $actor ) ) { wp_send_json_error( array( 'message' => 'No tenés acceso a este perfil.' ), 403 ); }
        $key = 'ge_tax_rate_' . $actor; $count = (int) get_transient( $key );
        if ( $count >= 15 ) { wp_send_json_error( array( 'message' => 'Alcanzaste el límite de consultas. Reintentá en unos minutos.' ), 429 ); }
        set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
        $id = sanitize_text_field( wp_unslash( $_POST['profile_id'] ?? 'default' ) );
        $old = $customer && $id ? GE_WTP_Customer_Branches::find( $customer, $id ) : array();
        if ( $customer && $id && ! $old ) { wp_send_json_error( array( 'message' => 'Perfil no encontrado.' ), 404 ); }
        $result = GE_WTP_ARCA_Lookup::lookup( sanitize_text_field( wp_unslash( $_POST['cuit'] ?? '' ) ) );
        add_user_meta( $customer ?: $actor, '_ge_billing_audit', array( 'event' => 'tax_lookup', 'at' => gmdate( 'c' ), 'actor_id' => $actor, 'customer_id' => $customer, 'profile_id' => $id, 'status' => $result['status'] ?? 'error', 'checked_at' => $result['checked_at'] ?? '', 'source' => $result['source'] ?? 'arca_wsci', 'cuit_hash' => hash_hmac( 'sha256', preg_replace( '/[^0-9]/', '', (string) ( $_POST['cuit'] ?? '' ) ), wp_salt( 'auth' ) ) ) );
        if ( ! empty( $result['verified'] ) || ( 'partial' === ( $result['status'] ?? '' ) && ! empty( $result['profile']['legal_name'] ) ) ) {
            $token = wp_generate_password( 40, false, false );
            set_transient( 'ge_tax_preview_' . hash( 'sha256', $token ), array( 'actor' => $actor, 'customer' => $customer, 'email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ), 'id' => $id, 'old_hash' => self::profile_hash( $old ), 'profile' => $result['profile'], 'checked_at' => $result['checked_at'], 'source' => $result['source'] ), 10 * MINUTE_IN_SECONDS );
            $result['preview_token'] = $token;
        }
        unset( $result['last_valid'] );
        wp_send_json_success( $result );
    }
    public static function metadata( $customer, $id, $input, $old, $actor ) {
        if ( ! self::can_edit( $customer, $actor ) ) { throw new InvalidArgumentException( 'No tenés acceso a este perfil.' ); }
        $fields = array( 'cuit', 'legal_name', 'vat_status', 'fiscal_address' ); $same = true;
        foreach ( $fields as $field ) { if ( (string) ( $input[$field] ?? '' ) !== (string) ( $old[$field] ?? '' ) ) { $same = false; } }
        $meta = array_merge( $same ? array_intersect_key( $old, array_flip( array( 'source_url', 'tax_evidence' ) ) ) : array(), array( 'verification_status' => $same ? ( $old['verification_status'] ?? 'pending' ) : 'manual', 'source' => $same ? ( $old['source'] ?? 'manual' ) : 'manual', 'checked_at' => $same ? ( $old['checked_at'] ?? '' ) : '', 'verified_at' => $same ? ( $old['verified_at'] ?? '' ) : '' ) );
        $token = sanitize_text_field( $input['tax_preview_token'] ?? '' );
        if ( ! $token ) { return $meta; }
        $key = 'ge_tax_preview_' . hash( 'sha256', $token ); $preview = get_transient( $key );
        $user = get_userdata( $customer );
        if ( ! is_array( $preview ) || (int) $preview['actor'] !== (int) $actor || ( $preview['customer'] && (int) $preview['customer'] !== (int) $customer ) || ( ! $preview['customer'] && ! empty( $preview['email'] ) && ( ! $user || 0 !== strcasecmp( $user->user_email, $preview['email'] ) ) ) || (string) $preview['id'] !== (string) $id || ! hash_equals( $preview['old_hash'], self::profile_hash( $old ) ) ) { throw new InvalidArgumentException( 'La consulta fiscal venció o el perfil cambió. Volvé a buscar los datos.' ); }
        foreach ( $fields as $field ) {
            $expected = (string) ( $preview['profile'][$field] ?? '' );
            if ( 'vat_status' === $field && 'unknown' === $expected ) { $expected = ''; }
            if ( (string) ( $input[$field] ?? '' ) !== $expected ) { throw new InvalidArgumentException( 'Los datos fiscales cambiaron después de la consulta. Volvé a confirmarlos.' ); }
        }
        delete_transient( $key );
        $status = 'verified' === ( $preview['profile']['verification_status'] ?? '' ) ? 'verified' : 'pending';
        return array_merge( array_intersect_key( $preview['profile'], array_flip( array( 'source_url', 'tax_evidence' ) ) ), array( 'verification_status' => $status, 'source' => $preview['source'], 'checked_at' => $preview['checked_at'], 'verified_at' => 'verified' === $status ? $preview['checked_at'] : '' ) );
    }
    public static function quick_profile( $customer, $input, $actor ) {
        if ( self::stage() < 3 || empty( $input['customer_cuit'] ) ) { return true; }
        $id = sanitize_text_field( $input['billing_profile_id'] ?? GE_WTP_Customer_Branches::default_profile_id( $customer ) );
        $old = GE_WTP_Customer_Branches::find( $customer, $id );
        if ( ! $old ) { return new WP_Error( 'ge_tax_profile', 'Seleccioná un perfil del cliente.' ); }
        $raw = array_merge( $old, array( 'cuit' => $input['customer_cuit'], 'legal_name' => $input['tax_legal_name'] ?? $input['customer_name'] ?? '', 'vat_status' => $input['tax_vat_status'] ?? '', 'fiscal_address' => $input['tax_fiscal_address'] ?? '', 'tax_preview_token' => $input['tax_preview_token'] ?? '' ) );
        try { return 'default' === $id ? GE_WTP_Billing::save_profile( $customer, $raw, $actor ) : GE_WTP_Customer_Branches::save( $customer, $raw, $actor ); }
        catch ( InvalidArgumentException $error ) { return new WP_Error( 'ge_tax_profile', $error->getMessage() ); }
    }
    public static function suggestion() {
        check_ajax_referer( 'ge_customer_tax', 'nonce' );
        if ( self::stage() < 2 ) { wp_send_json_error( array( 'message' => 'Las sugerencias fiscales todavía no están habilitadas.' ), 403 ); }
        $actor = get_current_user_id();
        if ( ! user_can( $actor, 'manage_woocommerce' ) && ! user_can( $actor, 'ge_manage_operations' ) ) { wp_send_json_error( array( 'message' => 'Acceso denegado.' ), 403 ); }
        $customer = absint( email_exists( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ) );
        $profile = $customer ? GE_WTP_Customer_Branches::find( $customer, sanitize_text_field( $_POST['profile_id'] ?? 'default' ) ) : array();
        $profile = self::suggestion_profile( (array) $profile, wp_unslash( $_POST ) );
        if ( is_wp_error( $profile ) ) { wp_send_json_error( array( 'message' => $profile->get_error_message() ), 422 ); }
        $issuer = GE_WTP_Billing_Issuers::get( sanitize_key( $_POST['issuer_id'] ?? '' ) );
        wp_send_json_success( $issuer ? GE_WTP_Customer_Tax::resolve( $issuer, (array) $profile ) : GE_WTP_Customer_Tax::suggestion( (array) $profile ) );
    }
    public static function suggestion_profile( $profile, $input ) {
        if ( self::stage() < 3 || empty( $input['customer_cuit'] ) ) { return $profile; }
        $cuit = preg_replace( '/[^0-9]/', '', (string) $input['customer_cuit'] );
        if ( ! GE_WTP_Billing_Issuers::valid_cuit( $cuit ) ) { return new WP_Error( 'ge_tax_candidate_cuit', 'El CUIT no es válido. Revisá sus 11 dígitos antes de sugerir un comprobante.' ); }
        $vat = sanitize_key( $input['vat_status'] ?? '' );
        return array_merge( $profile, array( 'cuit' => $cuit, 'vat_status' => in_array( $vat, array( 'registered', 'monotributo', 'exempt', 'final_consumer' ), true ) ? $vat : '', 'verification_status' => 'pending', 'source' => 'manual_preview' ) );
    }
}
