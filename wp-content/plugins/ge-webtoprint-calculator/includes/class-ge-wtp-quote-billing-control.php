<?php
defined( 'ABSPATH' ) || exit;

/** Quote-specific identity decisions; commercial amounts are never recalculated here. */
final class GE_WTP_Quote_Billing_Control {
    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'wp_ajax_ge_quote_billing', array( __CLASS__, 'ajax' ) );
    }
    public static function can_edit( $actor ) {
        return user_can( $actor, 'manage_woocommerce' ) || user_can( $actor, 'ge_manage_operations' );
    }
    public static function receiver( $s ) {
        return (array) ( $s['receiver_snapshot'] ?? $s['billing']['profile'] ?? $s['customer_billing_profile'] ?? array() );
    }
    public static function source( $p ) {
        $source = $p['verified_source'] ?? $p['source'] ?? 'manual';
        return in_array( $source, array( 'arca', 'arca_wsci' ), true ) ? 'arca' : ( in_array( $source, array( 'customer', 'customer_portal' ), true ) ? 'customer' : 'manual' );
    }
    public static function state( $s ) {
        $p = self::receiver( $s );
        $verified = 'verified' === ( $p['verification_status'] ?? '' ) && ! empty( $p['verified_at'] ) && 'unknown' !== GE_WTP_Customer_Tax::vat_status( $p );
        if ( 'arca' === self::source( $p ) ) { $e = GE_WTP_Customer_Tax::resolve( GE_WTP_Billing_Issuers::from_snapshot( $s ), $p ); $verified = $verified && ! empty( $e['customer_evidence_fresh'] ); }
        return array( 'status' => $verified ? 'verified' : 'pending', 'verified_source' => self::source( $p ), 'verified_at' => $p['verified_at'] ?? '', 'locked' => $verified );
    }
    public static function hash( $s ) { return hash( 'sha256', wp_json_encode( $s ) ); }
    public static function verified_args( $p, $args, $actor ) {
        if ( ! self::state( array( 'receiver_snapshot' => $p ) )['locked'] ) { return $args; }
        $sg = GE_WTP_Customer_Tax::suggestion( $p );
        if ( ! empty( $args['billing_override'] ) ) {
            if ( ! GE_WTP_Billing_Issuers::can_manage( $actor ) || empty( trim( $args['billing_reason'] ?? $args['issuer_change_reason'] ?? '' ) ) ) { return new WP_Error( 'ge_billing_override', 'Override requiere rol autorizado y motivo.' ); }
        } elseif ( ! empty( $args['issuer_profile_id'] ) && $args['issuer_profile_id'] !== $sg['issuer_profile_id'] ) { return new WP_Error( 'ge_billing_suggested_lock', 'El receptor está verificado: usá el emisor sugerido o Cambiar igualmente con motivo.' ); }
        else { $args['issuer_profile_id'] = $sg['issuer_profile_id']; }
        return $args;
    }
    public static function guard( $previous, $args, $actor ) {
        $p = self::receiver( $previous );
        $changed = (string) ( $args['billing_profile_id'] ?? $previous['billing_profile_id'] ?? '' ) !== (string) ( $previous['billing_profile_id'] ?? '' ) || ( ! empty( $args['issuer_profile_id'] ) && $args['issuer_profile_id'] !== ( $previous['issuer_snapshot']['id'] ?? 'unknown' ) ) || ! empty( $args['billing_refresh'] ) || ! empty( $args['issuer_refresh'] );
        if ( $changed && self::state( $previous )['locked'] && ( empty( $args['billing_override'] ) || ! GE_WTP_Billing_Issuers::can_manage( $actor ) || empty( trim( $args['billing_reason'] ?? $args['issuer_change_reason'] ?? '' ) ) ) ) { return new WP_Error( 'ge_billing_locked', 'Datos fiscales verificados: usá Cambiar igualmente con un rol autorizado y motivo.' ); }
        return true;
    }
    public static function capture( $s, $actor, $reason ) {
        $s['receiver_snapshot'] = self::receiver( $s );
        $s['billing_resolution'] = array_merge( self::state( $s ), array( 'chosen_by' => (int) $actor, 'chosen_at' => gmdate( 'c' ), 'reason' => sanitize_textarea_field( $reason ) ) );
        unset( $s['snapshot_hash'] ); $s['snapshot_hash'] = self::hash( $s );
        return $s;
    }
    public static function select( $id, $args, $actor ) {
        $lock = 'ge_quote_publish_notice_' . absint($id);
        if (!add_option($lock,time(),'',false)) { return new WP_Error('ge_quote_busy','El presupuesto se está publicando o enviando. Esperá antes de editar la facturación.'); }
        try { return self::select_unlocked($id,$args,$actor); }
        finally { delete_option($lock); }
    }

    private static function select_unlocked( $id, $args, $actor ) {
        if ( ! self::can_edit( $actor ) ) { return new WP_Error( 'ge_billing_forbidden', 'Acceso denegado.' ); }
        $q = GE_WTP_Commercial_Quotes::get( $id, $actor ); if ( is_wp_error( $q ) ) { return $q; }
        if ( 'draft' !== $q['status'] || $q['converted_order_id'] || get_post_meta( $id, '_ge_commercial_initial_payment_order', true ) ) { return new WP_Error( 'ge_billing_historical', 'El presupuesto enviado o vinculado conserva sus datos. Creá una revisión explícita.' ); }
        $s = $q['snapshot'];
        if ( ! hash_equals( self::hash( $s ), (string) ( $args['expected_hash'] ?? '' ) ) ) { return new WP_Error( 'ge_billing_conflict', 'El presupuesto cambió. Volvé a abrirlo.' ); }
        $guard = self::guard( $s, $args, $actor ); if ( is_wp_error( $guard ) ) { return $guard; }
        $reason = sanitize_textarea_field( $args['billing_reason'] ?? '' );
        if ( ! trim( $reason ) ) { return new WP_Error( 'ge_billing_reason', 'Indicá el motivo de la selección.' ); }
        $pid = sanitize_text_field( $args['billing_profile_id'] ?? '' );
        $same = $pid === ( $s['billing_profile_id'] ?? '' );
        $p = $same && empty( $args['billing_refresh'] ) ? self::receiver( $s ) : GE_WTP_Customer_Branches::find( $q['customer_id'], $pid );
        if ( ! $p ) { return new WP_Error( 'ge_billing_profile', 'Seleccioná un perfil activo de este cliente.' ); }
        $suggestion = GE_WTP_Customer_Tax::suggestion( $p );
        $issuer_id = sanitize_key( $args['issuer_profile_id'] ?? '' );
        if ( ! empty( self::state( array( 'receiver_snapshot' => $p ) )['locked'] ) && empty( $args['billing_override'] ) ) { $issuer_id = $suggestion['issuer_profile_id']; }
        $issuer = GE_WTP_Billing_Issuers::get( $issuer_id );
        if ( ! $issuer || empty( $issuer['active'] ) ) { return new WP_Error( 'ge_billing_issuer', 'Seleccioná un emisor activo; la resolución está pendiente.' ); }
        // Overrides of the suggested issuer require the existing issuer management permission.
        if ( ! empty( $args['billing_override'] ) && ! GE_WTP_Billing_Issuers::can_manage( $actor ) ) { return new WP_Error( 'ge_billing_override', 'No tenés permiso para override.' ); }
        $old = $s;
        $s['billing_profile_id'] = $pid; $s['customer_billing_profile'] = $p; $s['receiver_snapshot'] = $p;
        $old_issuer = GE_WTP_Billing_Issuers::from_snapshot( $old );
        $s['issuer_snapshot'] = $issuer_id === $old_issuer['id'] && empty( $args['issuer_refresh'] ) ? $old_issuer : GE_WTP_Billing_Issuers::capture( $issuer );
        $s['issuer_profile_id'] = $issuer_id; $s['issuer_fiscal_snapshot'] = GE_WTP_Billing_Issuers::entity( $s['issuer_snapshot'] );
        $s['issuer_suggestion'] = $suggestion;
        $s['customer_tax_decision'] = GE_WTP_Customer_Tax::resolve( $s['issuer_snapshot'], $p );
        $s['customer_tax_decision']['reviewed_by'] = $actor; $s['customer_tax_decision']['reviewed_at'] = gmdate( 'c' ); $s['customer_tax_decision']['override_reason'] = $reason;
        // The old computed fiscal resolution cannot be presented as applying to new parties.
        $s['billing'] = null; $s['fiscal_status'] = 'pending'; $s['fiscal_blockers'] = array( 'billing_identity_changed_requires_reconciliation' );
        $resolution = GE_WTP_Billing::resolve_net_quote( $s['issuer_fiscal_snapshot'], $p, (int) ( $s['net_cents'] ?? 0 ) );
        if ( empty( $resolution['blockers'] ) && (int) $resolution['total_cents'] === (int) ( $s['total_cents'] ?? -1 ) && (int) $resolution['tax_cents'] === (int) ( $s['tax_cents'] ?? -1 ) ) { $s['billing'] = GE_WTP_Billing::snapshot( $s['issuer_fiscal_snapshot'], $p, $resolution ); $s['fiscal_status'] = 'resolved'; unset( $s['fiscal_blockers'] ); }
        if ( ! empty( $s['items'] ) && empty( $s['draft_lines'] ) ) {
            // Legacy final prices are entered gross amounts. Restore those inputs
            // before resolving a new explicit party selection, never extract VAT twice.
            if ( 'final' === ( $s['quote_vat_mode'] ?? '' ) && isset( $s['entered_subtotal_cents'] ) ) {
                $s['subtotal_cents'] = $s['entered_subtotal_cents'];
                $s['discount_cents'] = $s['entered_discount_cents'] ?? 0;
                $s['net_cents'] = $s['subtotal_cents'] - $s['discount_cents'];
                foreach ( $s['items'] as &$item ) {
                    $item['unit_net_cents'] = $item['entered_unit_cents'] ?? $item['unit_net_cents'];
                    $item['net_cents'] = $item['entered_line_cents'] ?? $item['net_cents'];
                    $item['discount_cents'] = $item['entered_discount_cents'] ?? 0;
                    $item['taxable_base_cents'] = $item['final_line_cents'] ?? $item['net_cents'];
                }
                unset( $item );
            }
            unset( $s['draft_incomplete'] );
            $s = GE_WTP_Commercial_Quotes::preview_billing( $q['customer_id'], $s );
        }
        $s = self::capture( $s, $actor, $reason );
        $s['billing_resolution']['override'] = ! empty( $args['billing_override'] ); unset( $s['snapshot_hash'] ); $s['snapshot_hash'] = self::hash( $s );
        $versions = get_post_meta( $id, GE_WTP_Commercial_Quotes::VERSIONS_META, true );
        if ( ! is_array( $versions ) ) { return new WP_Error( 'ge_billing_history', 'Historial inválido.' ); }
        // A new version preserves any previously sent/approved copy even when its current status is draft.
        $version = $q['version'] + 1; $versions[$version] = $s;
        update_post_meta( $id, GE_WTP_Commercial_Quotes::VERSIONS_META, $versions ); update_post_meta( $id, GE_WTP_Commercial_Quotes::CURRENT_META, $version );
        GE_WTP_Commercial_Quotes::record_event( $id, 'billing_selection', $actor, array( 'before_hash' => self::hash( $old ), 'after_hash' => self::hash( $s ), 'before_receiver' => self::receiver( $old ), 'after_receiver' => $p, 'before_issuer' => $old_issuer, 'after_issuer' => $s['issuer_snapshot'], 'reason' => $reason, 'override' => ! empty( $args['billing_override'] ) ) );
        return GE_WTP_Commercial_Quotes::get( $id, $actor );
    }
    public static function save_customer( $q, $input, $actor ) {
        $id = $q['customer_id']; $kind = sanitize_key( $input['kind'] ?? 'profile' );
        if ( ! self::can_edit( $actor ) ) { return new WP_Error( 'ge_customer_forbidden', 'Acceso denegado.' ); }
        if ( 'contact' === $kind ) {
            $name = sanitize_text_field( $input['display_name'] ?? '' ); $email = sanitize_email( $input['email'] ?? '' );
            if ( ! $name || ! is_email( $email ) ) { return new WP_Error( 'ge_customer_contact', 'Revisá nombre y email.' ); }
            $result = wp_update_user( array( 'ID' => $id, 'display_name' => $name, 'user_email' => $email ) ); if ( is_wp_error( $result ) ) { return $result; }
            $phone = sanitize_text_field( $input['whatsapp'] ?? '' ); update_user_meta( $id, '_ge_whatsapp', $phone ); update_user_meta( $id, 'billing_phone', $phone );
            add_user_meta( $id, '_ge_billing_audit', array( 'event' => 'quick_contact', 'actor_id' => $actor, 'at' => gmdate( 'c' ) ) ); return true;
        }
        $pid = sanitize_text_field( $input['id'] ?? '' );
        $old = $pid ? GE_WTP_Customer_Branches::find( $id, $pid ) : array();
        if ( $pid && ! $old ) { return new WP_Error( 'ge_profile_missing', 'Perfil no encontrado.' ); }
        if ( ! hash_equals( self::hash( (array) $old ), (string) ( $input['profile_hash'] ?? '' ) ) ) { return new WP_Error( 'ge_profile_conflict', 'El perfil cambió. Volvé a abrir la ficha.' ); }
        $verify = sanitize_key( $input['verification_action'] ?? 'keep' );
        $reason = sanitize_textarea_field( $input['verification_reason'] ?? '' );
        if ( in_array( $verify, array( 'verify','unverify' ), true ) && ( ! GE_WTP_Billing_Issuers::can_manage( $actor ) || ! trim( $reason ) ) ) { return new WP_Error( 'ge_profile_verify_forbidden', 'Verificar/desverificar requiere un rol autorizado y motivo.' ); }
        if ( 'verify' === $verify && ( empty( $input['legal_name'] ) || empty( $input['cuit'] ) || ! GE_WTP_Billing_Issuers::valid_cuit( preg_replace( '/[^0-9]/', '', (string) $input['cuit'] ) ) || 'unknown' === GE_WTP_Customer_Tax::vat_status( $input ) ) ) { return new WP_Error( 'ge_verify_incomplete', 'Para verificar completá razón social, CUIT válido y condición fiscal.' ); }
        try { $result = 'default' === $pid ? GE_WTP_Billing::save_profile( $id, array_merge( $old, $input ), $actor ) : GE_WTP_Customer_Branches::save( $id, array_merge( $old, $input ), $actor ); }
        catch ( InvalidArgumentException $e ) { return new WP_Error( 'ge_profile_invalid', $e->getMessage() ); }
        if ( is_wp_error( $result ) ) { return $result; }
        if ( in_array( $verify, array( 'verify','unverify' ), true ) ) {
            $result['verification_status'] = 'verify' === $verify ? 'verified' : 'pending'; $result['verified_at'] = 'verify' === $verify ? gmdate( 'c' ) : ''; $result['checked_at'] = $result['verified_at']; $result['source'] = 'manual'; $result['verified_source'] = 'manual'; $result['verified_by'] = $actor;
            if ( 'default' === $pid ) { update_user_meta( $id, GE_WTP_Billing::PROFILE_META, $result ); }
            else { $stored = get_user_meta( $id, GE_WTP_Customer_Branches::META, true ); foreach ( $stored as &$row ) { if ( $row['id'] === $result['id'] ) { $row = $result; } } unset( $row ); update_user_meta( $id, GE_WTP_Customer_Branches::META, $stored ); }
            add_user_meta( $id, '_ge_billing_audit', array( 'event' => $verify, 'actor_id' => $actor, 'at' => gmdate( 'c' ), 'profile_id' => $result['id'] ?? $pid, 'reason' => $reason, 'source' => 'manual' ) );
        }
        return $result;
    }
    public static function ajax() {
        check_ajax_referer( 'ge_quote_billing', 'nonce' ); $actor = get_current_user_id();
        if ( ! self::can_edit( $actor ) ) { wp_send_json_error( array( 'message' => 'Acceso denegado.' ), 403 ); }
        $input = wp_unslash( $_POST ); $id = absint( $input['quote_id'] ?? 0 ); $q = GE_WTP_Commercial_Quotes::get( $id, $actor );
        if ( is_wp_error( $q ) ) { wp_send_json_error( array( 'message' => $q->get_error_message() ), 404 ); }
        $lock = 'ge_qbc_lock_' . $id;
        if ( ! add_option( $lock, gmdate( 'c' ), '', false ) ) { wp_send_json_error( array( 'message' => 'Otro cambio está en curso.' ), 409 ); }
        try { $result = 'customer' === ( $input['operation'] ?? '' ) ? self::save_customer( $q, $input, $actor ) : self::select( $id, $input, $actor ); } finally { delete_option( $lock ); }
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 422 ); }
        wp_send_json_success( array( 'message' => 'Guardado. El presupuesto conserva sus datos hasta una actualización explícita.' ) );
    }
    public static function enqueue() {
        if ( ! self::can_edit( get_current_user_id() ) ) { return; }
        wp_enqueue_style( 'ge-quote-billing', GE_WTP_PLUGIN_URL . 'assets/css/quote-billing-control.css', array(), filemtime( GE_WTP_PLUGIN_DIR . 'assets/css/quote-billing-control.css' ) );
        wp_enqueue_script( 'ge-quote-billing', GE_WTP_PLUGIN_URL . 'assets/js/quote-billing-control.js', array(), filemtime( GE_WTP_PLUGIN_DIR . 'assets/js/quote-billing-control.js' ), true );
        wp_localize_script( 'ge-quote-billing', 'geQuoteBilling', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ge_quote_billing' ) ) );
    }
    public static function summary( $s ) {
        $p = self::receiver( $s ); $state = self::state( $s ); $d = $s['customer_tax_decision'] ?? array();
        echo '<div class="ge-qbc-summary"><p><strong>Receptor:</strong> ' . esc_html( ( $p['label'] ?? '' ) . ' · ' . ( $p['legal_name'] ?? 'Receptor histórico pendiente' ) . ( ! empty( $p['cuit'] ) ? ' · CUIT ' . $p['cuit'] : '' ) ) . '</p><p><strong>Condición:</strong> ' . esc_html( self::vat_label( $p ) ) . ' · ' . esc_html( $state['locked'] ? 'Verificado' : 'Pendiente' ) . '</p>';
        GE_WTP_Billing_Issuers::render_summary( $s );
        if ( in_array( 'billing_identity_changed_requires_reconciliation', (array) ( $s['fiscal_blockers'] ?? array() ), true ) ) { echo '<p><strong>Importes conservados. Podés publicar esta propuesta comercial; la conciliación fiscal se revisa antes de cobrar o facturar. Editar y guardar permite revisar el cálculo expresamente.</strong></p>'; }
        echo '<p><strong>Comprobante sugerido:</strong> ' . esc_html( 'unknown' === ( $d['suggested_document_class'] ?? 'unknown' ) ? 'Pendiente' : $d['suggested_document_class'] ) . '</p><p>' . esc_html( $state['locked'] ? 'Resuelto por datos fiscales verificados' : 'Situación fiscal no verificada' ) . ' · Fuente: ' . esc_html( $state['verified_source'] ) . ( $state['verified_at'] ? ' · ' . esc_html( $state['verified_at'] ) : '' ) . '</p></div>';
    }
    public static function vat_label( $p ) { return array( 'registered' => 'Responsable inscripto','monotributo' => 'Monotributista','exempt' => 'Exento','final_consumer' => 'Consumidor final' )[ $p['vat_status'] ?? '' ] ?? 'Sin confirmar'; }
    public static function form_start( $q, $operation ) {
        echo '<form data-ge-qbc-form><input type="hidden" name="quote_id" value="' . esc_attr( $q['id'] ) . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
    }
    public static function render( $q ) {
        $s = $q['snapshot']; $state = self::state( $s );
        echo '<section class="ge-production-card ge-qbc"><h3>Facturación</h3>'; self::summary( $s );
        echo '<div class="ge-qbc-actions"><button type="button" class="ge-staff-button is-secondary" data-ge-qbc-open="ge-qbc-customer">Ver ficha cliente</button>';
        if ( 'draft' === $q['status'] && ! $q['converted_order_id'] && ! get_post_meta( $q['id'], '_ge_commercial_initial_payment_order', true ) ) {
            if ( ! $state['locked'] || GE_WTP_Billing_Issuers::can_manage( get_current_user_id() ) ) { echo '<button type="button" class="ge-staff-button is-secondary" data-ge-qbc-open="ge-qbc-selection">' . esc_html( $state['locked'] ? 'Cambiar igualmente' : 'Elegir receptor y emisor' ) . '</button>'; }
        } else { echo '<p>Snapshot histórico: los datos nuevos de la ficha no modifican esta versión.</p>'; }
        echo '</div></section>';
        echo '<dialog id="ge-qbc-selection" class="ge-qbc-dialog" aria-labelledby="ge-qbc-selection-title"><button type="button" data-ge-qbc-close aria-label="Cerrar">×</button><h2 id="ge-qbc-selection-title">Receptor y emisor</h2>';
        self::form_start( $q, 'select' );
        echo '<input type="hidden" data-ge-qbc-locked value="' . ( $state['locked'] ? '1' : '0' ) . '">';
        echo '<input type="hidden" name="expected_hash" value="' . esc_attr( self::hash( $s ) ) . '"><label>Facturar a / Receptor<select name="billing_profile_id" required data-ge-qbc-receiver><option value="">Elegí un perfil</option>';
        foreach ( GE_WTP_Customer_Branches::profiles( $q['customer_id'] ) as $p ) {
            $live = $p; $sg_live = GE_WTP_Customer_Tax::suggestion( $live ); $st_live = self::state( array( 'receiver_snapshot' => $live ) );
            if ( ( $s['billing_profile_id'] ?? '' ) === $p['id'] && self::receiver( $s ) ) { $p = self::receiver( $s ); $p['id'] = $live['id']; $p['label'] = $p['label'] ?? $live['label']; }
            $sg = GE_WTP_Customer_Tax::suggestion( $p ); $st = self::state( array( 'receiver_snapshot' => $p ) );
            echo '<option data-live-verified="' . ( $st_live['locked'] ? '1' : '0' ) . '" data-live-issuer="' . esc_attr( $sg_live['issuer_profile_id'] ) . '" data-live-suggestion="' . esc_attr( wp_json_encode( $sg_live ) ) . '" data-verified="' . ( $st['locked'] ? '1' : '0' ) . '" data-issuer="' . esc_attr( $sg['issuer_profile_id'] ) . '" data-suggestion="' . esc_attr( wp_json_encode( $sg ) ) . '" value="' . esc_attr( $p['id'] ) . '"' . selected( $s['billing_profile_id'] ?? '', $p['id'], false ) . '>' . esc_html( $p['label'] . ' · ' . $p['legal_name'] . ' · CUIT ' . $p['cuit'] . ' · ' . self::vat_label( $p ) . ' · ' . ( $p['billing_mode'] ?? 'common' ) . ' · ' . ( $st['locked'] ? 'Verificado' : 'Pendiente' ) ) . '</option>';
        }
        echo '</select></label><label>Emisor<select name="issuer_profile_id" required data-ge-qbc-issuer><option value="">Elegí un emisor</option>';
        foreach ( GE_WTP_Billing_Issuers::all() as $issuer ) { if ( empty( $issuer['active'] ) ) { continue; } echo '<option value="' . esc_attr( $issuer['id'] ) . '"' . selected( $s['issuer_snapshot']['id'] ?? '', $issuer['id'], false ) . '>' . esc_html( $issuer['display_name'] . ' · ' . $issuer['legal_name'] . ' · ' . $issuer['verification_status'] ) . '</option>'; }
        echo '</select></label><p data-ge-qbc-suggestion role="status"></p>';
        if ( GE_WTP_Billing_Issuers::can_manage( get_current_user_id() ) ) { echo '<label><input type="checkbox" name="billing_override" value="1" data-ge-qbc-override> Cambiar igualmente: override autorizado</label>'; }
        echo '<label>Motivo de la selección<textarea name="billing_reason" required maxlength="500"></textarea></label><label><input type="checkbox" name="billing_refresh" value="1" data-ge-qbc-refresh> Actualizar este presupuesto con los datos nuevos del receptor</label><label><input type="checkbox" name="issuer_refresh" value="1"> Actualizar datos del emisor</label><p>Se crea una nueva versión. Precios, ítems y archivos se conservan. El cálculo fiscal queda pendiente de conciliación.</p><button class="ge-staff-button" type="submit">Guardar selección</button><p data-ge-qbc-status role="status"></p></form></dialog>';
        self::drawer( $q );
    }
    public static function drawer( $q ) {
        $id = $q['customer_id']; $u = get_userdata( $id ); if ( ! $u ) { return; }
        echo '<dialog id="ge-qbc-customer" class="ge-qbc-dialog ge-qbc-drawer" aria-labelledby="ge-qbc-customer-title"><button type="button" data-ge-qbc-close aria-label="Cerrar ficha cliente">×</button><h2 id="ge-qbc-customer-title">Ficha rápida · ' . esc_html( $u->display_name ) . '</h2><a href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'customers', array( 'customer_id' => $id ) ) ) . '">Abrir ficha completa</a>';
        self::form_start( $q, 'customer' ); echo '<input type="hidden" name="kind" value="contact">';
        foreach ( array( 'display_name' => array( 'Nombre', $u->display_name ), 'email' => array( 'Email', $u->user_email ), 'whatsapp' => array( 'WhatsApp', get_user_meta( $id, '_ge_whatsapp', true ) ) ) as $key => $field ) { echo '<label>' . esc_html( $field[0] ) . '<input name="' . $key . '" value="' . esc_attr( $field[1] ) . '" maxlength="190"' . ( 'whatsapp' !== $key ? ' required' : '' ) . ( 'email' === $key ? ' type="email"' : '' ) . '></label>'; }
        echo '<button type="submit" class="ge-staff-button">Guardar contacto</button><p data-ge-qbc-status role="status"></p></form><h3>Perfiles de facturación</h3>';
        // Use the same normalized model and fiscal lookup controls as Customer Workspace.
        $profiles = GE_WTP_Customer_Branches::profiles( $id ); $profiles[] = array( 'id' => '', 'label' => 'Agregar perfil de facturación' );
        foreach ( $profiles as $p ) {
            echo '<details><summary>' . esc_html( $p['label'] . ' · ' . ( $p['legal_name'] ?? '' ) ) . '</summary>'; self::form_start( $q, 'customer' );
            echo '<input type="hidden" name="id" value="' . esc_attr( $p['id'] ) . '"><input type="hidden" name="profile_hash" value="' . esc_attr( self::hash( $p['id'] ? $p : array() ) ) . '">';
            foreach ( array( 'label' => 'Nombre / sucursal', 'legal_name' => 'Razón social', 'cuit' => 'CUIT', 'fiscal_address' => 'Domicilio fiscal', 'billing_email' => 'Email de facturación' ) as $key => $label ) { echo '<label>' . esc_html( $label ) . '<input name="' . esc_attr( $key ) . '" value="' . esc_attr( $p[$key] ?? '' ) . '" maxlength="220"></label>'; }
            echo '<label>Condición fiscal<select name="vat_status"><option value="">Pendiente</option>'; foreach ( array( 'registered' => 'Responsable inscripto','monotributo' => 'Monotributista','exempt' => 'Exento','final_consumer' => 'Consumidor final' ) as $v => $label ) { echo '<option value="' . $v . '"' . selected( $p['vat_status'] ?? '', $v, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label><label>Modalidad<select name="billing_mode"><option value="common">Común</option><option value="invoice_a"' . selected( $p['billing_mode'] ?? '', 'invoice_a', false ) . '>Requiere A si corresponde</option></select></label>';
            GE_WTP_Customer_Tax_UI::controls( $id, $p['id'], $p );
            if ( GE_WTP_Billing_Issuers::can_manage( get_current_user_id() ) ) { echo '<label>Verificación<select name="verification_action"><option value="keep">Conservar si los datos no cambiaron</option><option value="verify">Confirmar manualmente</option><option value="unverify">Desverificar</option></select></label><label>Motivo de verificación<input name="verification_reason" maxlength="500"></label>'; }
            echo '<button type="submit" class="ge-staff-button">Guardar perfil</button><p data-ge-qbc-status role="status"></p></form></details>';
        }
        echo '<h3>Direcciones de entrega</h3>'; foreach ( GE_WTP_Customers::addresses( $id ) as $a ) { echo '<p>' . esc_html( ( $a['label'] ?? '' ) . ' · ' . ( $a['street'] ?? '' ) . ' · ' . ( $a['city'] ?? '' ) ) . '</p>'; }
        echo '<p>Los cambios de la ficha no modifican el presupuesto guardado.</p>';
        if ( 'draft' === $q['status'] ) { echo '<button type="button" class="ge-staff-button is-secondary" data-ge-qbc-open="ge-qbc-selection">Actualizar este presupuesto con los datos nuevos</button>'; }
        echo '</dialog>';
    }
}
