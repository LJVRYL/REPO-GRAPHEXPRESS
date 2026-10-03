<?php

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-ge-wtp-billing-issuers.php';
require_once __DIR__ . '/class-ge-wtp-quote-billing-control.php';
require_once __DIR__ . '/class-ge-wtp-quote-selection.php';

/** Commercial proposals. A quote never is a WooCommerce order. */
final class GE_WTP_Commercial_Quotes {
    const POST_TYPE = 'ge_commercial_quote';
    const STATUS_META = '_ge_commercial_status';
    const CUSTOMER_META = '_ge_commercial_customer_id';
    const VERSIONS_META = '_ge_commercial_versions';
    const CURRENT_META = '_ge_commercial_current_version';
    const ORDER_META = '_ge_commercial_order_id';

    public static function init() {
        GE_WTP_Quote_Billing_Control::init();
        add_action( 'init', array( __CLASS__, 'register_type' ) );
    }

    public static function register_type() {
        register_post_type( self::POST_TYPE, array(
            'label' => 'Presupuestos comerciales',
            'public' => false,
            'show_ui' => false,
            'supports' => array( 'title', 'author' ),
            'exclude_from_search' => true,
            'rewrite' => false,
        ) );
    }

    public static function get( $quote_id, $actor_id = 0 ) {
        $post = get_post( absint( $quote_id ) );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            return new WP_Error( 'ge_quote_missing', 'Presupuesto no encontrado.' );
        }
        if ( $actor_id && ! self::can_access( $post->ID, $actor_id ) ) {
            return new WP_Error( 'ge_quote_forbidden', 'No tenés acceso a este presupuesto.' );
        }
        $versions = get_post_meta( $post->ID, self::VERSIONS_META, true );
        $version = absint( get_post_meta( $post->ID, self::CURRENT_META, true ) );
        $quote = array(
            'id' => (int) $post->ID,
            'number' => class_exists('GE_Organization_Runtime') ? GE_Organization_Runtime::quote_number($post->ID,$versions[$version]??array()) : (class_exists('GE_WTP_Gestion_V3')?GE_WTP_Gestion_V3::quote_number($post->ID):'GE-PRE-'.$post->ID),
            'work_number' => class_exists( 'GE_WTP_Gestion_V3' ) ? GE_WTP_Gestion_V3::lookup( 'quote_id', $post->ID ) : 0,
            'customer_id' => absint( get_post_meta( $post->ID, self::CUSTOMER_META, true ) ),
            'status' => (string) get_post_meta( $post->ID, self::STATUS_META, true ),
            'version' => $version,
            'snapshot' => is_array( $versions ) && isset( $versions[ $version ] ) ? $versions[ $version ] : array(),
            'converted_order_id' => absint( get_post_meta( $post->ID, self::ORDER_META, true ) ),
        );
        return GE_WTP_Quote_Selection::effective( $quote );
    }

    public static function can_access( $quote_id, $actor_id ) {
        if(class_exists('GE_Organization_Runtime')) {
            if(!GE_Organization_Runtime::enabled('quotes'))return false;
            if(GE_Organization_Runtime::role($actor_id) && !GE_Organization_Runtime::allowed('quotes',false,$actor_id))return false;
        }
        if ( user_can( $actor_id, 'ge_manage_operations' ) || user_can( $actor_id, 'manage_woocommerce' ) ) {
            return true;
        }
        return (int) $actor_id === (int) get_post_meta( $quote_id, self::CUSTOMER_META, true );
    }

    /**
     * Code-native operation for staff and a future Action Registry adapter.
     * It makes a commercial draft only: no artwork, cart, order or production.
     */
    public static function create_draft( $customer_id, $lines, $args = array(), $actor_id = 0 ) {
        $actor_id = $actor_id ?: get_current_user_id();
        if (class_exists('GE_Organization_Runtime') && !GE_Organization_Runtime::allowed('quotes', true, $actor_id)) return new WP_Error('ge_org_permission','Rol o módulo sin permiso para modificar presupuestos.');
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) {
            return new WP_Error( 'ge_quote_forbidden', 'No tenés permiso para crear presupuestos.' );
        }
        $customer = get_userdata( absint( $customer_id ) );
        if ( ! $customer || ! is_email( $customer->user_email ) ) {
            return new WP_Error( 'ge_quote_customer', 'El cliente necesita una ficha con email válido.' );
        }
        $args['applied_by'] = $actor_id;
        if ( empty( $args['billing_profile_id'] ) ) { $profiles = GE_WTP_Customer_Branches::profiles( $customer_id ); if ( count( $profiles ) !== 1 ) { return new WP_Error( 'ge_quote_profile_required', 'Elegí el receptor de este presupuesto.' ); } $args['billing_profile_id'] = $profiles[0]['id']; }
        if ( ! isset( $args['quote_vat_mode'] ) ) { $args['quote_vat_mode'] = $quote['snapshot']['quote_vat_mode'] ?? ''; }
        $snapshot = self::build_snapshot( $lines, $args );
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        if ( ! GE_WTP_Customer_Branches::find( $customer->ID, $snapshot['billing_profile_id'] ) ) { return new WP_Error( 'ge_quote_profile', 'Seleccioná un perfil activo de este cliente.' ); }
        $args = GE_WTP_Quote_Billing_Control::verified_args( GE_WTP_Customer_Branches::find( $customer->ID, $snapshot['billing_profile_id'] ), $args, $actor_id ); if ( is_wp_error( $args ) ) { return $args; }
        $chosen = GE_WTP_Billing_Issuers::choose( GE_WTP_Customer_Branches::find( $customer->ID, $snapshot['billing_profile_id'] ), $args, $actor_id );
        if ( is_wp_error( $chosen ) ) { return $chosen; }
        $snapshot['issuer_profile_id'] = $chosen['issuer']['id']; $snapshot['issuer_snapshot'] = $chosen['issuer']; $snapshot['issuer_suggestion'] = $chosen['suggestion'];
        $snapshot = self::preview_billing( $customer->ID, $snapshot );
        $snapshot = GE_WTP_Quote_Billing_Control::capture( $snapshot, $actor_id, $args['billing_reason'] ?? 'Selección explícita al crear presupuesto' );
        $post_id = wp_insert_post( array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => 'Presupuesto para ' . $customer->display_name,
            'post_author' => $actor_id,
        ), true );
        if ( is_wp_error( $post_id ) ) { return $post_id; }
        update_post_meta( $post_id, self::CUSTOMER_META, $customer->ID );
        update_post_meta( $post_id, self::STATUS_META, 'draft' );
        update_post_meta( $post_id, self::CURRENT_META, 1 );
        update_post_meta( $post_id, self::VERSIONS_META, array( 1 => $snapshot ) );
        update_post_meta( $post_id, '_ge_commercial_source', sanitize_key( $args['source'] ?? 'manual' ) );
        self::event( $post_id, 1, 'created', $actor_id );
        self::record_event( $post_id, 'issuer_selected', $actor_id, array( 'issuer_profile_id' => $snapshot['issuer_profile_id'], 'issuer_hash' => $snapshot['issuer_snapshot']['snapshot_hash'], 'suggestion' => $snapshot['issuer_suggestion'], 'reason' => sanitize_textarea_field( $args['issuer_change_reason'] ?? 'Sugerencia comercial revisable' ) ) );
        return self::get( $post_id, $actor_id );
    }

    /**
     * A sent revision is never edited in place. New terms become a new draft
     * version; a previously accepted version remains available for audit.
     */
    public static function revise( $quote_id, $lines, $args = array(), $actor_id = 0 ) {
        $actor_id = $actor_id ?: get_current_user_id();
        if (class_exists('GE_Organization_Runtime') && !GE_Organization_Runtime::allowed('quotes', true, $actor_id)) return new WP_Error('ge_org_permission','Rol o módulo sin permiso para modificar presupuestos.');
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) {
            return new WP_Error( 'ge_quote_forbidden', 'No tenés permiso para editar presupuestos.' );
        }
        $quote = self::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( isset( $args['expected_version'] ) && (int) $args['expected_version'] !== $quote['version'] ) {
            return new WP_Error( 'ge_quote_version_changed', 'El presupuesto cambió mientras lo editabas. Volvé a abrirlo.' );
        }
        if ( in_array( $quote['status'], array( 'accepted', 'converted', 'cancelled' ), true ) ) {
            return new WP_Error( 'ge_quote_locked', 'Este presupuesto ya no admite cambios.' );
        }
        if ( get_post_meta( $quote_id, '_ge_commercial_initial_payment_order', true ) ) {
            return new WP_Error( 'ge_quote_payment_locked', 'Hay un cobro iniciado. Revisá su estado antes de editar la propuesta.' );
        }
        $args['applied_by'] = $actor_id;
        if ( empty( $args['billing_profile_id'] ) ) { $args['billing_profile_id'] = $quote['snapshot']['billing_profile_id'] ?? ''; }
        $guard = GE_WTP_Quote_Billing_Control::guard( $quote['snapshot'], $args, $actor_id ); if ( is_wp_error( $guard ) ) { return $guard; }
        $snapshot = self::build_snapshot( $lines, $args );
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        if ( ! GE_WTP_Customer_Branches::find( $quote['customer_id'], $snapshot['billing_profile_id'] ) ) { return new WP_Error( 'ge_quote_profile', 'Seleccioná un perfil activo de este cliente.' ); }
        $same_profile = $snapshot['billing_profile_id'] === ( $quote['snapshot']['billing_profile_id'] ?? '' ) && empty( $args['billing_refresh'] );
        $profile = $same_profile ? GE_WTP_Quote_Billing_Control::receiver( $quote['snapshot'] ) : GE_WTP_Customer_Branches::find( $quote['customer_id'], $snapshot['billing_profile_id'] );
        if ( ! $profile ) { $profile = GE_WTP_Customer_Branches::find( $quote['customer_id'], $snapshot['billing_profile_id'] ); }
        $snapshot['receiver_snapshot'] = $profile;
        if ( ! $same_profile ) { $args = GE_WTP_Quote_Billing_Control::verified_args( $profile, $args, $actor_id ); if ( is_wp_error( $args ) ) { return $args; } }
        $chosen = GE_WTP_Billing_Issuers::choose( $profile, $args, $actor_id, $quote['snapshot'] );
        if ( is_wp_error( $chosen ) ) { return $chosen; }
        $snapshot['issuer_profile_id'] = $chosen['issuer']['id']; $snapshot['issuer_snapshot'] = $chosen['issuer']; $snapshot['issuer_suggestion'] = $chosen['suggestion'];
        $issuer_changed = GE_WTP_Billing_Issuers::from_snapshot( $quote['snapshot'] ) !== $snapshot['issuer_snapshot'];
        $snapshot = self::preview_billing( $quote['customer_id'], $snapshot );
        if ( $same_profile && ! $issuer_changed ) { foreach ( array( 'customer_tax_decision','issuer_suggestion','billing_resolution' ) as $key ) { if ( isset( $quote['snapshot'][$key] ) ) { $snapshot[$key] = $quote['snapshot'][$key]; } } unset( $snapshot['snapshot_hash'] ); $snapshot['snapshot_hash'] = GE_WTP_Quote_Billing_Control::hash( $snapshot ); }
        else { $snapshot = GE_WTP_Quote_Billing_Control::capture( $snapshot, $actor_id, $args['billing_reason'] ?? $args['issuer_change_reason'] ?? 'Revisión explícita' ); }
        $versions = get_post_meta( $quote_id, self::VERSIONS_META, true );
        if ( ! is_array( $versions ) ) { return new WP_Error( 'ge_quote_corrupt', 'Historial del presupuesto inválido.' ); }
        $was_sent = false; foreach ( (array) get_post_meta( $quote_id, '_ge_commercial_events', true ) as $event ) { if ( 'sent' === ( $event['event'] ?? $event['name'] ?? '' ) ) { $was_sent = true; } }
        $version = $quote['status'] === 'draft' && ! $issuer_changed && ! $was_sent ? $quote['version'] : $quote['version'] + 1;
        $versions[ $version ] = $snapshot;
        update_post_meta( $quote_id, self::VERSIONS_META, $versions );
        update_post_meta( $quote_id, self::CURRENT_META, $version );
        update_post_meta( $quote_id, self::STATUS_META, 'draft' );
        self::event( $quote_id, $version, 'revised', $actor_id );
        if ( ! $same_profile || $issuer_changed ) { self::record_event( $quote_id, 'billing_selection', $actor_id, array( 'before_receiver' => GE_WTP_Quote_Billing_Control::receiver( $quote['snapshot'] ), 'after_receiver' => GE_WTP_Quote_Billing_Control::receiver( $snapshot ), 'reason' => $args['billing_reason'] ?? $args['issuer_change_reason'] ?? '', 'override' => ! empty( $args['billing_override'] ) ) ); }
        if ( $issuer_changed ) { self::record_event( $quote_id, 'issuer_changed', $actor_id, array( 'before' => GE_WTP_Billing_Issuers::from_snapshot( $quote['snapshot'] ), 'after' => $snapshot['issuer_snapshot'], 'reason' => sanitize_textarea_field( $args['issuer_change_reason'] ?? '' ) ) ); }
        return self::get( $quote_id, $actor_id );
    }

    public static function send( $quote_id, $actor_id = 0 ) {
        $actor_id = $actor_id ?: get_current_user_id();
        if (class_exists('GE_Organization_Runtime') && !GE_Organization_Runtime::allowed('quotes', true, $actor_id)) return new WP_Error('ge_org_permission','Rol o módulo sin permiso para modificar presupuestos.');
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) {
            return new WP_Error( 'ge_quote_forbidden', 'No tenés permiso para enviar presupuestos.' );
        }
        $quote = self::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( 'draft' !== $quote['status'] ) { return new WP_Error( 'ge_quote_state', 'Sólo puede enviarse un borrador.' ); }
        if ( self::needs_roll_reprice( $quote['snapshot'] ) ) { return new WP_Error( 'ge_quote_roll_reprice', 'Editá y guardá este borrador para recalcular el vinilo según el ancho del rollo antes de enviarlo.' ); }
        if ( in_array( 'billing_identity_changed_requires_reconciliation', (array) ( $quote['snapshot']['fiscal_blockers'] ?? array() ), true ) ) { return new WP_Error( 'ge_billing_reconcile', 'Revisá expresamente los importes fiscales antes de enviar.' ); }
        $snapshot = $quote['snapshot'];
        $issuer = GE_WTP_Billing_Issuers::from_snapshot( $snapshot );
        $publish = GE_WTP_Billing_Issuers::can_publish( $issuer ); if ( is_wp_error( $publish ) ) { return $publish; }
        if ( isset( $snapshot['total_cents'] ) && 'pending' === ( $snapshot['fiscal_status'] ?? '' ) && ! empty( $snapshot['commercial_tax_policy'] ) ) { /* Commercial proposal; fiscal operations remain gated. */ }
        elseif ( isset( $snapshot['total_cents'] ) ) { $valid = self::check_billing_snapshot( $quote ); if ( is_wp_error( $valid ) ) { return $valid; } }
        else { $snapshot = self::resolve_billing( $quote['customer_id'], $snapshot ); }
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        $versions = get_post_meta( $quote_id, self::VERSIONS_META, true );
        $versions[ $quote['version'] ] = $snapshot;
        update_post_meta( $quote_id, self::VERSIONS_META, $versions );
        $customer = get_userdata( $quote['customer_id'] );
        $url = GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $quote_id ) );
        $body = '<p>Hola ' . esc_html( $customer->first_name ?: $customer->display_name ) . ',</p><p>Tenés un nuevo presupuesto de Graph Express para revisar.</p><p><a href="' . esc_url( $url ) . '">Ver presupuesto ' . esc_html( $quote['number'] ) . '</a></p>';
        $body .= self::email_summary( $snapshot );
        if ( 'yes' === get_user_meta( $customer->ID, '_ge_commercial_needs_invite', true ) ) {
            $body .= '<p>Si es tu primer acceso, usá “¿Olvidaste tu contraseña?” en el portal para definirla con este email.</p>';
        }
        if ( ! GE_WTP_Notifications::send( $customer->user_email, 'Tu presupuesto · ' . $quote['number'], $body, 'commercial_quote_sent', $quote_id ) ) {
            return new WP_Error( 'ge_quote_email', 'No se pudo enviar el presupuesto. Revisá Notificaciones antes de reintentar.' );
        }
        update_post_meta( $quote_id, self::STATUS_META, 'sent' );
        self::event( $quote_id, $quote['version'], 'sent', $actor_id );
        return self::get( $quote_id, $actor_id );
    }

    public static function resend( $quote_id, $actor_id = 0 ) {
        $actor_id = $actor_id ?: get_current_user_id();
        if (class_exists('GE_Organization_Runtime') && !GE_Organization_Runtime::allowed('quotes', true, $actor_id)) return new WP_Error('ge_org_permission','Rol o módulo sin permiso para modificar presupuestos.');
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) { return new WP_Error( 'ge_quote_forbidden', 'Acceso denegado.' ); }
        $quote = self::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( ! in_array( $quote['status'], array( 'sent', 'viewed' ), true ) ) { return new WP_Error( 'ge_quote_state', 'Este presupuesto no se puede reenviar.' ); }
        $publish = GE_WTP_Billing_Issuers::can_publish( GE_WTP_Billing_Issuers::from_snapshot( $quote['snapshot'] ) ); if ( is_wp_error( $publish ) ) { return $publish; }
        $customer = get_userdata( $quote['customer_id'] );
        if ( ! $customer ) { return new WP_Error( 'ge_quote_customer', 'Cliente no disponible.' ); }
        $url = GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $quote_id ) );
        $body = '<p>Hola ' . esc_html( $customer->first_name ?: $customer->display_name ) . ',</p><p>Podés volver a revisar tu presupuesto de Graph Express.</p><p><a href="' . esc_url( $url ) . '">Ver presupuesto ' . esc_html( $quote['number'] ) . '</a></p>';
        $body .= self::email_summary( $quote['snapshot'] );
        if ( ! GE_WTP_Notifications::send( $customer->user_email, 'Tu presupuesto · ' . $quote['number'], $body, 'commercial_quote_sent', $quote_id ) ) { return new WP_Error( 'ge_quote_email', 'No se pudo reenviar. Revisá Notificaciones.' ); }
        self::event( $quote_id, $quote['version'], 'sent', $actor_id );
        return $quote;
    }

    public static function email_summary( $snapshot ) {
        if ( GE_WTP_Quote_Selection::has_choices( $snapshot ) && empty( $snapshot['customer_selection'] ) ) { return '<p>Revisá las variantes y elegí los ítems desde tu portal para ver el total de tu presupuesto.</p>'; }
        $tax_body = '';
        if ( ! isset( $snapshot['total_cents'] ) ) { return $tax_body . '<p>Total pendiente de confirmación.</p>'; }
        $money = function( $cents ) { return number_format_i18n( $cents / 100, 2 ) . ' ' . ( $snapshot['currency'] ?? 'ARS' ); };
        $receiver = GE_WTP_Quote_Billing_Control::receiver( $snapshot );
        $body = '<p>Receptor: ' . esc_html( ( $receiver['legal_name'] ?? 'Pendiente' ) . ' · CUIT ' . ( $receiver['cuit'] ?? '' ) . ' · ' . ( $receiver['fiscal_address'] ?? '' ) ) . '</p>';
        $body .= '<p>Emisor / Facturación: ' . esc_html( GE_WTP_Billing_Issuers::label( GE_WTP_Billing_Issuers::from_snapshot( $snapshot ) ) ) . '</p>';
        $body .= '<p>Subtotal / Neto: ' . esc_html( $money( $snapshot['subtotal_cents'] ?? $snapshot['net_cents'] ) ) . '<br>';
        if ( ! empty( $snapshot['discount_cents'] ) ) { $body .= 'Descuento comercial: −' . esc_html( $money( $snapshot['discount_cents'] ) ) . '<br>Neto imponible: ' . esc_html( $money( $snapshot['net_cents'] ) ) . '<br>'; }
        $body .= 'IVA: ' . esc_html( $money( $snapshot['tax_cents'] ?? 0 ) ) . '<br><strong>Total final: ' . esc_html( $money( $snapshot['total_cents'] ) ) . '</strong></p>';
        // Internal fiscal blockers are confined to the staff summary.
        return (class_exists('GE_Organization_Runtime')?GE_Organization_Runtime::email_summary($snapshot):'') . $tax_body . $body;
    }

    /** Older drafts must be reviewed before their previous area price is sent. */
    public static function needs_roll_reprice( $snapshot ) {
        foreach ( (array) ( $snapshot['items'] ?? array() ) as $item ) {
            $product_id = absint( $item['product_id'] ?? 0 );
            $configuration = $item['configuration'] ?? array();
            if ( ! $product_id || ! is_array( $configuration ) || ! isset( $configuration['width'], $configuration['height'] ) ) { continue; }
            $key = (string) get_post_meta( $product_id, '_ge_public_catalog_key', true );
            if ( ! GE_WTP_Roll_Pricing::widths_for_catalog_key( $key ) ) { continue; }
            if ( ! isset( $configuration['roll_width_cm'] ) ) { return true; }
            $priced = GE_WTP_Commercial_Quote_Catalog::price( $product_id, $configuration, absint( $item['quantity'] ?? 1 ) );
            if ( is_wp_error( $priced ) || (int) ( $item['unit_net_cents'] ?? 0 ) !== (int) round( (float) $priced['price'] * 100 ) ) { return true; }
        }
        return false;
    }

    public static function accept( $quote_id, $version, $actor_id = 0 ) {
        $actor_id = $actor_id ?: get_current_user_id();
        if (class_exists('GE_Organization_Runtime') && !GE_Organization_Runtime::enabled('quotes')) return new WP_Error('ge_org_module','Presupuestos deshabilitados.');
        $quote = self::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( $actor_id !== $quote['customer_id'] || ! in_array( $quote['status'], array( 'sent', 'viewed' ), true ) || (int) $version !== $quote['version'] ) {
            return new WP_Error( 'ge_quote_accept', 'El presupuesto cambió o no está disponible para aceptar.' );
        }
        if ( ! empty( $quote['snapshot']['valid_until'] ) && $quote['snapshot']['valid_until'] < wp_date( 'Y-m-d' ) ) {
            return new WP_Error( 'ge_quote_expired', 'El presupuesto venció. Solicitá una actualización.' );
        }
        if ( 'pending' !== ( $quote['snapshot']['fiscal_status'] ?? '' ) || empty( $quote['snapshot']['commercial_tax_policy'] ) ) {
            $billing = self::check_billing_snapshot( $quote );
            if ( is_wp_error( $billing ) ) { return $billing; }
        }
        $lock = 'ge_commercial_quote_accept_' . $quote_id;
        if ( ! add_option( $lock, time(), '', 'no' ) ) { return new WP_Error( 'ge_quote_busy', 'Estamos procesando el presupuesto. Volvé a intentar.' ); }
        try {
            $quote = self::get( $quote_id, $actor_id );
            if ( is_wp_error( $quote ) || ! in_array( $quote['status'], array( 'sent', 'viewed' ), true ) || $quote['version'] !== (int) $version ) {
                return new WP_Error( 'ge_quote_accept', 'El presupuesto ya no está disponible.' );
            }
            if ( GE_WTP_Quote_Selection::has_choices( $quote['snapshot'] ) ) {
                $selection = GE_WTP_Quote_Selection::apply( $quote, wp_unslash( $_POST['quote_selection'] ?? array() ), $version, wp_unslash( $_POST['quote_configuration'] ?? array() ) );
                if ( is_wp_error( $selection ) ) { return $selection; }
                update_post_meta( $quote_id, GE_WTP_Quote_Selection::META, array( 'version' => $version, 'proposal_hash' => GE_WTP_Quote_Billing_Control::hash( $quote['snapshot'] ), 'snapshot' => $selection['snapshot'], 'selected_by' => $actor_id, 'selected_at' => gmdate( 'c' ) ) );
            }
            update_post_meta( $quote_id, self::STATUS_META, 'accepted' );
            update_post_meta( $quote_id, '_ge_commercial_accepted_at', gmdate( 'c' ) );
            update_post_meta( $quote_id, '_ge_commercial_accepted_by', $actor_id );
            update_post_meta( $quote_id, '_ge_commercial_accept_source', 'portal' );
            self::event( $quote_id, $version, 'accepted', $actor_id );
            GE_WTP_Internal_Alerts::create('quote_approved','Presupuesto aprobado · ' . $quote['number'],$quote_id,$quote['customer_id']);
            return self::get( $quote_id, $actor_id );
        } finally {
            delete_option( $lock );
        }
    }

    /** Records a staff-confirmed commercial acceptance, never artwork approval. */
    public static function accept_staff( $quote_id, $version, $method, $reason, $actor_id ) {
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) { return new WP_Error( 'ge_quote_forbidden', 'Acceso denegado.' ); }
        $quote = self::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( (int) $version !== $quote['version'] ) { return new WP_Error( 'ge_quote_version_changed', 'La versión cambió.' ); }
        if ( in_array( $quote['status'], array( 'accepted', 'converted' ), true ) ) { return $quote; }
        if ( ! in_array( $quote['status'], array( 'sent', 'viewed' ), true ) ) { return new WP_Error( 'ge_quote_state', 'Este presupuesto no admite aceptación comercial.' ); }
        if ( GE_WTP_Quote_Selection::has_choices( $quote['snapshot'] ) ) { return new WP_Error( 'ge_quote_selection_required', 'Pedí al cliente que elija y acepte desde su portal.' ); }
        $method = sanitize_key( $method );
        if ( ! in_array( $method, array( 'whatsapp', 'email', 'phone', 'in_person', 'other' ), true ) || ! trim( $reason ) ) { return new WP_Error( 'ge_quote_evidence', 'Indicá el canal y la referencia de la aceptación.' ); }
        update_post_meta( $quote_id, self::STATUS_META, 'accepted' );
        update_post_meta( $quote_id, '_ge_commercial_accepted_at', gmdate( 'c' ) );
        update_post_meta( $quote_id, '_ge_commercial_accepted_by', $actor_id );
        update_post_meta( $quote_id, '_ge_commercial_accept_source', 'staff:' . $method );
        self::record_event( $quote_id, 'accepted_staff', $actor_id, array( 'method' => $method, 'reason' => sanitize_textarea_field( $reason ) ) );
        return self::get( $quote_id, $actor_id );
    }

    public static function build_snapshot( $lines, $args = array() ) {
        $organization = class_exists('GE_Organization_Runtime') ? GE_Organization_Runtime::snapshot() : array();
        if ($organization) { $args += array('valid_until'=>wp_date('Y-m-d',strtotime('+'.(int)$organization['documents']['quote_valid_days'].' days',current_time('timestamp'))),'payment_terms'=>$organization['documents']['payment_terms'],'discount_value'=>$organization['commercial']['default_discount_percent']); }
        if ( ! is_array( $lines ) || ! $lines || count( $lines ) > 30 ) {
            return new WP_Error( 'ge_quote_lines', 'Agregá entre 1 y 30 ítems.' );
        }
        $items = array(); $net = 0; $discounts = array(); $item_discount = 0; $seen_line_ids = array();
        foreach ( $lines as $line ) {
            if ( ! is_array( $line ) ) { return new WP_Error( 'ge_quote_line', 'Ítem inválido.' ); }
            $product_id = absint( $line['product_id'] ?? 0 );
            $source_type = sanitize_key( $line['source_type'] ?? ( $product_id ? 'catalog_product' : 'custom' ) );
            if ( ! in_array( $source_type, array( 'catalog_product', 'custom' ), true ) || ( 'catalog_product' === $source_type && ! $product_id ) || ( 'custom' === $source_type && $product_id ) ) {
                return new WP_Error( 'ge_quote_source', 'Elegí un producto del catálogo o un ítem personalizado válido.' );
            }
            $product = $product_id ? wc_get_product( $product_id ) : false;
            if ( $product_id && ( ! $product || 'publish' !== $product->get_status() ) ) {
                return new WP_Error( 'ge_quote_product', 'Un producto de catálogo ya no está disponible.' );
            }
            $name = sanitize_text_field( $line['name'] ?? ( $product ? $product->get_name() : '' ) );
            $unit = sanitize_text_field( $line['unit'] ?? 'u' );
            if ( ! in_array( $unit, array( 'u', 'm²', 'ml', 'lote', 'servicio' ), true ) ) { return new WP_Error( 'ge_quote_unit', 'Unidad inválida.' ); }
            $quantity_input = (string) ( $line['quantity'] ?? '' );
            $quantity = preg_match( '/^[1-9][0-9]{0,5}$/D', $quantity_input ) ? (int) $quantity_input : 0;
            if ( ! $name || $quantity < 1 || $quantity > 100000 ) {
                return new WP_Error( 'ge_quote_line', 'Nombre o cantidad inválidos.' );
            }
            $configuration = array(); $configuration_label = '';
            $unit_net = $line['unit_net'] ?? '';
            if ( $product ) {
                $priced = GE_WTP_Commercial_Quote_Catalog::price( $product_id, $line['configuration'] ?? array(), $quantity );
                if ( is_wp_error( $priced ) ) { return $priced; }
                $configuration = $priced['configuration'];
                $configuration_label = $priced['description'];
                $quantity = absint( $priced['quantity'] ?? $quantity );
                if ( ! $priced['manual'] ) { $unit_net = wc_format_decimal( $priced['price'], 2 ); }
            }
            $finishes = array_values( array_unique( array_map( 'sanitize_key', (array) ( $line['finishes'] ?? array() ) ) ) );
            if ( array_diff( $finishes, array_keys( GE_WTP_Workflow::finishing_catalog() ) ) ) { return new WP_Error( 'ge_quote_finishes', 'Revisá las terminaciones seleccionadas.' ); }
            try { $unit_cents = GE_WTP_Quote_Balance::cents( $unit_net ); }
            catch ( InvalidArgumentException $error ) { return new WP_Error( 'ge_quote_price', 'Precio unitario inválido.' ); }
            $line_cents = $unit_cents * $quantity;
            if ( $line_cents < 0 || $line_cents > 999999999999 ) { return new WP_Error( 'ge_quote_price', 'Importe de ítem fuera de rango.' ); }
            $discount = self::discount( $line, $line_cents, 'item', $args['applied_by'] ?? 0 );
            if ( is_wp_error( $discount ) ) { return $discount; }
            if ( $discount ) { $discount['item_index'] = count( $items ); $discounts[] = $discount; $item_discount += $discount['amount_cents']; }
            $line_uuid = $line['line_uuid'] ?? wp_generate_uuid4();
            if (!GE_WTP_Quote_Artwork_V2::uuid($line_uuid) || isset($seen_line_ids[$line_uuid])) { return new WP_Error('ge_quote_line_uuid', 'Identificador de ítem inválido o duplicado.'); }
            $selection_type = $line['selection_type'] ?? 'required';
            $selection_group = sanitize_text_field( $line['selection_group'] ?? '' );
            if ( ! in_array( $selection_type, array( 'required', 'optional', 'alternative' ), true ) || ( 'alternative' === $selection_type && ! preg_match( '/^[a-zA-Z0-9_-]{1,60}$/D', $selection_group ) ) ) { return new WP_Error( 'ge_quote_selection', 'Definí el tipo y el grupo de alternativas de cada ítem.' ); }
            $facets = GE_WTP_Quote_Selection::facets( $line['choice_facets'] ?? array() );
            if ( is_wp_error( $facets ) ) { return $facets; }
            if ( $facets && ( 'alternative' !== $selection_type || $product_id ) ) { return new WP_Error( 'ge_choice_facets', 'Las variantes configurables deben ser alternativas personalizadas.' ); }
            foreach ( $items as $previous ) {
                if ( 'alternative' === $selection_type && ( $previous['selection_group'] ?? '' ) === $selection_group && (bool) $facets !== (bool) ( $previous['choice_facets'] ?? array() ) ) { return new WP_Error( 'ge_choice_facets', 'No mezcles alternativas simples y configurables en el mismo grupo.' ); }
                if ( $facets && ( $previous['selection_group'] ?? '' ) === $selection_group ) {
                    $pf = $previous['choice_facets'] ?? array();
                    if ( ! $pf || ( $pf['model_key'] === $facets['model_key'] && $pf['finish_key'] === $facets['finish_key'] ) ) { return new WP_Error( 'ge_choice_facets', 'No repitas la combinación de modelo y terminación dentro del grupo.' ); }
                    if ( $pf['model_key'] === $facets['model_key'] && $pf['model_label'] !== $facets['model_label'] ) { return new WP_Error( 'ge_choice_facets', 'El nombre del modelo debe ser consistente.' ); }
                }
            }
            $seen_line_ids[$line_uuid] = true;
            $items[] = array(
                'line_uuid' => $line_uuid,
                'choice_facets' => $facets,
                'selection_type' => $selection_type,
                'selection_group' => $selection_group,
                'selection_recommended' => ! empty( $line['selection_recommended'] ),
                'source_type' => $source_type,
                'product_id' => $product_id ?: null,
                'sku' => $product ? $product->get_sku() : '',
                'name' => $name,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_net_cents' => $unit_cents,
                'net_cents' => $line_cents,
                'discount_cents' => $discount ? $discount['amount_cents'] : 0,
                'discount' => $discount,
                'taxable_base_cents' => $line_cents - ( $discount ? $discount['amount_cents'] : 0 ),
                'details' => sanitize_textarea_field( $line['details'] ?? '' ),
                'notes' => sanitize_textarea_field( $line['notes'] ?? '' ),
                'configuration' => $configuration,
                'configuration_label' => $configuration_label,
                'finishes' => $finishes,
                'lead_days' => absint( $line['lead_days'] ?? 0 ),
            );
            $net += $line_cents;
            if ( $net > 999999999999 ) { return new WP_Error( 'ge_quote_price', 'Total fuera de rango.' ); }
        }
        $discount = self::discount( $args, $net - $item_discount, 'quote', $args['applied_by'] ?? 0 );
        if ( is_wp_error( $discount ) ) { return $discount; }
        $quote_discount = $discount ? $discount['amount_cents'] : 0;
        if ( $discount ) { $discounts[] = $discount; }
        $remaining_base = $net - $item_discount; $remaining_discount = $quote_discount;
        foreach ( $items as &$item ) {
            $base = $item['taxable_base_cents'];
            $share = $remaining_base > 0 ? intdiv( $remaining_discount * $base, $remaining_base ) : 0;
            $item['discount_cents'] += $share; $item['taxable_base_cents'] -= $share;
            $remaining_base -= $base; $remaining_discount -= $share;
        }
        unset( $item );
        $valid_until = sanitize_text_field( $args['valid_until'] ?? wp_date( 'Y-m-d', strtotime( '+30 days', current_time( 'timestamp' ) ) ) );
        if ( $valid_until && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $valid_until ) || $valid_until < wp_date( 'Y-m-d' ) ) ) {
            return new WP_Error( 'ge_quote_validity', 'Fecha de validez inválida.' );
        }
        $deposit_percent = absint( $args['deposit_percent'] ?? get_option( 'ge_commercial_deposit_percent', 50 ) );
        if ( $deposit_percent < 1 || $deposit_percent > 100 ) { return new WP_Error( 'ge_quote_deposit', 'Porcentaje de seña inválido.' ); }
        return array(
            'items' => $items,
            'currency' => $organization['general']['currency'] ?? 'ARS',
            'organization_snapshot' => $organization,
            'payment_terms' => sanitize_textarea_field($args['payment_terms'] ?? ''),
            'commercial_terms' => $organization['documents']['terms'] ?? '',
            'subtotal_cents' => $net,
            'discount_cents' => $item_discount + $quote_discount,
            'discounts' => $discounts,
            'taxable_base_cents' => $net - $item_discount - $quote_discount,
            'net_cents' => $net - $item_discount - $quote_discount,
            'schema_version' => 2,
            'commercial_tax_policy' => get_option( 'ge_commercial_tax_policy', array() ),
            'quote_vat_mode' => in_array( $args['quote_vat_mode'] ?? '', array( 'added', 'final' ), true ) ? $args['quote_vat_mode'] : '',
            'valid_until' => $valid_until,
            'deposit_percent' => $deposit_percent,
            'notes_customer' => sanitize_textarea_field( $args['notes_customer'] ?? '' ),
            'notes_internal' => sanitize_textarea_field( $args['notes_internal'] ?? '' ),
            'billing' => null,
            'billing_profile_id' => sanitize_text_field( $args['billing_profile_id'] ?? 'default' ),
            'delivery_address_id' => sanitize_text_field( $args['delivery_address_id'] ?? '' ),
            'created_at' => gmdate( 'c' ),
        );
    }

    /** Commercial reductions are independent of document type and fiscal policy. */
    public static function discount( $input, $base, $scope, $actor_id ) {
        $type = sanitize_key( $input['discount_type'] ?? 'percent' );
        $raw = (string) ( $input['discount_value'] ?? '0' );
        if ( ! in_array( $type, array( 'percent', 'fixed' ), true ) || ! preg_match( '/^[0-9]{1,10}(\.[0-9]{1,2})?$/D', $raw ) ) { return new WP_Error( 'ge_quote_discount', 'Descuento inválido.' ); }
        try { $value = GE_WTP_Quote_Balance::cents( $raw ); }
        catch ( InvalidArgumentException $error ) { return new WP_Error( 'ge_quote_discount', 'Descuento inválido.' ); }
        if ( 'percent' === $type && $value > 10000 ) { return new WP_Error( 'ge_quote_discount', 'El porcentaje no puede superar 100%.' ); }
        if ( $value > 999999999999 ) { return new WP_Error( 'ge_quote_discount', 'Descuento fuera de rango.' ); }
        $amount = 'percent' === $type ? intdiv( $base * $value + 5000, 10000 ) : $value;
        if ( $amount > $base ) { return new WP_Error( 'ge_quote_discount', 'El descuento supera el importe disponible.' ); }
        if ( ! $amount ) { return null; }
        $reason = sanitize_textarea_field( $input['discount_reason'] ?? '' );
        if ( ! $reason ) { return new WP_Error( 'ge_quote_discount_reason', 'Indicá el motivo del descuento comercial.' ); }
        return array( 'discount_type' => $type, 'discount_value' => GE_WTP_Quote_Balance::decimal( $value ), 'discount_reason' => $reason, 'discount_scope' => $scope, 'amount_cents' => $amount, 'applied_by' => (int) $actor_id, 'applied_at' => gmdate( 'c' ) );
    }

    /** A draft may be saved with pending fiscal data; no guessed tax or total. */
    public static function preview_billing( $customer_id, $snapshot ) {
        if ( GE_WTP_Customer_Tax_UI::stage() >= 3 && empty( $snapshot['billing_profile_id'] ) ) { $snapshot['billing_profile_id'] = GE_WTP_Customer_Branches::default_profile_id( $customer_id ); }
        $snapshot['customer_billing_profile'] = $snapshot['receiver_snapshot'] ?? GE_WTP_Customer_Branches::find( $customer_id, $snapshot['billing_profile_id'] ?? 'default' );
        $snapshot['receiver_snapshot'] = $snapshot['customer_billing_profile'];
        if ( empty( $snapshot['issuer_snapshot'] ) ) {
            $chosen = GE_WTP_Billing_Issuers::choose( (array) $snapshot['customer_billing_profile'], array(), get_current_user_id(), null, false );
            $snapshot['issuer_snapshot'] = is_wp_error( $chosen ) ? GE_WTP_Billing_Issuers::unknown() : $chosen['issuer'];
            $snapshot['issuer_profile_id'] = $snapshot['issuer_snapshot']['id'];
            $snapshot['issuer_suggestion'] = is_wp_error( $chosen ) ? array() : $chosen['suggestion'];
        }
        if ( GE_WTP_Customer_Tax_UI::stage() >= 3 ) {
            $snapshot['customer_tax_decision'] = GE_WTP_Customer_Tax::resolve( $snapshot['issuer_snapshot'], (array) $snapshot['customer_billing_profile'] );
            foreach ( array( 'reviewed_by', 'reviewed_at', 'override_reason' ) as $key ) { $snapshot['customer_tax_decision'][$key] = $snapshot['issuer_suggestion'][$key] ?? ''; }
        }
        $snapshot['issuer_fiscal_snapshot'] = GE_WTP_Billing_Issuers::entity( $snapshot['issuer_snapshot'] );
        if ( empty( $snapshot['quote_vat_mode'] ) && in_array( $snapshot['issuer_fiscal_snapshot']['vat_status'] ?? '', array( 'monotributo', 'exempt' ), true ) ) { $snapshot['quote_vat_mode'] = 'final'; }
        $resolved = self::resolve_billing( $customer_id, $snapshot );
        $policy = $snapshot['commercial_tax_policy'] ?? array();
        // An explicit price choice belongs to this version, never to the issuer identity.
        if ( ! empty( $snapshot['quote_vat_mode'] ) && ! is_wp_error( $resolved ) ) { return $resolved; }
        if ( ! empty( $snapshot['quote_vat_mode'] ) && is_wp_error( $resolved ) ) {
            $entity = $snapshot['issuer_fiscal_snapshot'];
            $rate = in_array( $entity['vat_status'] ?? '', array( 'monotributo', 'exempt' ), true ) ? 0 : ( $entity['tax_rate_basis_points'] ?? $policy['tax_rate_basis_points'] ?? null );
            if ( null !== $rate && $rate >= 0 && $rate <= 10000 ) {
                $entity['common_price_policy'] = $entity['invoice_a_price_policy'] = 'final' === $snapshot['quote_vat_mode'] ? 'tax_inclusive' : 'tax_exclusive';
                $commercial = GE_WTP_Billing::resolve( $entity, (array) $snapshot['customer_billing_profile'], (int) $snapshot['net_cents'], (int) $rate );
                if ( 'final' === $snapshot['quote_vat_mode'] ) { self::extract_final_net( $snapshot, $commercial, (int) $rate ); }
                $snapshot['fiscal_status'] = 'pending';
                $snapshot['fiscal_blockers'] = array_merge( array( $resolved->get_error_code() ), (array) $resolved->get_error_data() );
                $snapshot['billing'] = null; $snapshot['tax_rates'] = array( (int) $rate );
                $snapshot['tax_cents'] = $commercial['tax_cents']; $snapshot['total_cents'] = $commercial['total_cents'];
                $snapshot['commercial_tax_policy'] = array( 'mode' => $snapshot['quote_vat_mode'], 'tax_rate_basis_points' => (int) $rate );
                self::allocate_tax( $snapshot ); unset( $snapshot['snapshot_hash'] );
                $snapshot['snapshot_hash'] = hash( 'sha256', wp_json_encode( $snapshot ) );
                return $snapshot;
            }
            $snapshot['fiscal_status'] = 'pending'; $snapshot['fiscal_blockers'] = array( $resolved->get_error_code() );
            return $snapshot;
        }
        if ( 'net_plus_tax' === ( $policy['mode'] ?? '' ) && isset( $policy['tax_rate_basis_points'] ) ) {
            $rate = (int) $policy['tax_rate_basis_points'];
            if ( $rate < 0 || $rate > 10000 ) { $snapshot['fiscal_status'] = 'pending'; return $snapshot; }
            if ( is_wp_error( $resolved ) || (int) ( $resolved['billing']['resolution']['tax_rate_basis_points'] ?? 0 ) !== $rate ) {
                $snapshot['fiscal_status'] = 'pending';
                $snapshot['fiscal_blockers'] = array( 'commercial_issuer_reconciliation_pending' );
                $snapshot['billing'] = null;
                $snapshot['tax_rule_version'] = 'commercial-net-plus-tax-v2';
                $snapshot['tax_rates'] = array( $rate );
                $snapshot['tax_cents'] = intdiv( $snapshot['net_cents'] * $rate + 5000, 10000 );
                $snapshot['total_cents'] = $snapshot['net_cents'] + $snapshot['tax_cents'];
                self::allocate_tax( $snapshot );
                unset( $snapshot['snapshot_hash'] );
                $snapshot['snapshot_hash'] = hash( 'sha256', wp_json_encode( $snapshot ) );
                return $snapshot;
            }
        }
        if ( ! is_wp_error( $resolved ) ) { return $resolved; }
        $snapshot['fiscal_status'] = 'pending';
        $snapshot['fiscal_blockers'] = array( $resolved->get_error_code() );
        unset( $snapshot['snapshot_hash'] );
        $snapshot['snapshot_hash'] = hash( 'sha256', wp_json_encode( $snapshot ) );
        return $snapshot;
    }

    private static function extract_final_net( &$snapshot, $resolution, $rate ) {
            // Preserve entered final amounts and distribute the extracted net exactly.
            $snapshot['entered_subtotal_cents'] = $snapshot['subtotal_cents'];
            $snapshot['entered_discount_cents'] = $snapshot['discount_cents'];
            $remaining_gross = (int) $snapshot['net_cents']; $remaining_net = (int) $resolution['subtotal_cents'];
            foreach ( $snapshot['items'] as &$item ) {
                $gross = (int) $item['taxable_base_cents'];
                $net = $remaining_gross > 0 ? intdiv( $remaining_net * $gross, $remaining_gross ) : 0;
                $item['entered_unit_cents'] = $item['unit_net_cents']; $item['entered_line_cents'] = $item['net_cents'];
                $item['entered_discount_cents'] = $item['discount_cents']; $item['final_line_cents'] = $gross;
                $item['unit_net_cents'] = intdiv( $item['unit_net_cents'] * 10000 + intdiv( 10000 + $rate, 2 ), 10000 + $rate );
                $item['net_cents'] = $net; $item['taxable_base_cents'] = $net; $item['discount_cents'] = 0;
                $remaining_gross -= $gross; $remaining_net -= $net;
            }
            unset( $item );
            $snapshot['subtotal_cents'] = $snapshot['net_cents'] = $snapshot['taxable_base_cents'] = (int) $resolution['subtotal_cents'];
            $snapshot['discount_cents'] = 0;
    }

    private static function allocate_tax( &$snapshot ) {
        $allocated_tax = 0; $remaining_net = (int) $snapshot['net_cents'];
        foreach ( $snapshot['items'] as &$item ) {
            $base = (int) ( $item['taxable_base_cents'] ?? $item['net_cents'] );
            $tax = isset( $item['final_line_cents'] ) && 'final' === ( $snapshot['quote_vat_mode'] ?? '' ) ? $item['final_line_cents'] - $base : ( $remaining_net > 0 ? intdiv( ( $snapshot['tax_cents'] - $allocated_tax ) * $base, $remaining_net ) : 0 );
            $item['tax_cents'] = $tax; $item['total_cents'] = $base + $tax;
            $allocated_tax += $tax; $remaining_net -= $base;
        }
        unset( $item );
    }

    private static function resolve_billing( $customer_id, $snapshot ) {
        if ( ! class_exists( 'GE_WTP_Billing' ) ) {
            return new WP_Error( 'ge_quote_billing_unavailable', 'Falta configurar el perfil fiscal antes de enviar presupuestos.' );
        }
        $issuer = GE_WTP_Billing_Issuers::from_snapshot( $snapshot );
        if ( ! GE_WTP_Billing_Issuers::ready( $issuer ) ) { return new WP_Error( 'ge_issuer_pending', 'Verificá el emisor real antes de habilitar operaciones fiscales.' ); }
        $valid = GE_WTP_Billing_Issuers::validate_current( $issuer ); if ( is_wp_error( $valid ) ) { return $valid; }
        $entity = GE_WTP_Billing_Issuers::entity( $issuer );
        $profile = $snapshot['receiver_snapshot'] ?? $snapshot['customer_billing_profile'] ?? GE_WTP_Customer_Branches::find( $customer_id, $snapshot['billing_profile_id'] ?? 'default' );
        if ( ! $profile ) { return new WP_Error( 'ge_quote_profile', 'Seleccioná un perfil de facturación activo del cliente.' ); }
        $delivery_id = $snapshot['delivery_address_id'] ?? '';
        $delivery = '' !== (string) $delivery_id ? GE_WTP_Customer_Branches::delivery( $customer_id, $delivery_id ) : null;
        if ( '' !== (string) $delivery_id && ! $delivery ) { return new WP_Error( 'ge_quote_delivery', 'Seleccioná una dirección de entrega del cliente.' ); }
        $mode = $snapshot['quote_vat_mode'] ?? '';
        $effective_entity = $entity;
        if ( $mode ) {
            $effective_entity['common_price_policy'] = $effective_entity['invoice_a_price_policy'] = 'final' === $mode ? 'tax_inclusive' : 'tax_exclusive';
        }
        $resolution = $mode ? GE_WTP_Billing::resolve( $effective_entity, $profile, (int) $snapshot['net_cents'] ) : GE_WTP_Billing::resolve_net_quote( $entity, $profile, (int) $snapshot['net_cents'] );
        if ( ! empty( $resolution['blockers'] ) ) {
            return new WP_Error( 'ge_quote_billing_blocked', 'Faltan datos fiscales o una configuración de facturación válida.', $resolution['blockers'] );
        }
        if ( ! $mode && 'tax_exclusive' !== $resolution['tax_treatment'] ) {
            return new WP_Error( 'ge_quote_billing_policy', 'El presupuesto requiere precios de entrada antes de IVA.' );
        }
        $resolution['resolver_version'] = 'ge-billing-v1/quote-v2';
        $resolution['tax_rate_basis_points'] = (int) ( $entity['tax_rate_basis_points'] ?? 0 );
        if ( 'final' === $mode ) { self::extract_final_net( $snapshot, $resolution, (int) $entity['tax_rate_basis_points'] ); }
        $snapshot['fiscal_status'] = 'resolved';
        $snapshot['tax_rule_version'] = $resolution['resolver_version'];
        $snapshot['tax_rates'] = array( $resolution['tax_rate_basis_points'] );
        $snapshot['billing'] = GE_WTP_Billing::snapshot( $entity, $profile, $resolution );
        $snapshot['delivery'] = $delivery;
        $snapshot['tax_cents'] = (int) $resolution['tax_cents'];
        $snapshot['total_cents'] = (int) $resolution['total_cents'];
        self::allocate_tax( $snapshot );
        unset( $snapshot['snapshot_hash'], $snapshot['fiscal_blockers'] );
        $snapshot['snapshot_hash'] = hash( 'sha256', wp_json_encode( $snapshot ) );
        return $snapshot;
    }

    public static function check_billing_snapshot( $quote ) {
        if ( GE_WTP_Quote_Selection::has_choices( $quote['snapshot'] ) && empty( $quote['snapshot']['customer_selection'] ) && in_array( $quote['status'], array( 'accepted', 'converted' ), true ) ) { return new WP_Error( 'ge_quote_selection_required', 'El cliente debe elegir las variantes antes de cobrar o convertir.' ); }
        if ( 'pending' === ( $quote['snapshot']['fiscal_status'] ?? '' ) ) { return new WP_Error( 'ge_quote_fiscal_pending', 'El presupuesto comercial requiere verificar el emisor fiscal antes de cobrar o convertir.' ); }
        $billing = $quote['snapshot']['billing'] ?? array();
        $issuer = GE_WTP_Billing_Issuers::from_snapshot( $quote['snapshot'] );
        $valid = GE_WTP_Billing_Issuers::validate_current( $issuer ); if ( is_wp_error( $valid ) ) { return $valid; }
        try { GE_WTP_Billing::assert_can_accept_or_pay( $billing, GE_WTP_Billing_Issuers::entity( $issuer ) ); }
        catch ( DomainException $error ) { return new WP_Error( 'ge_quote_billing_changed', 'Los datos fiscales requieren una nueva versión del presupuesto.' ); }
        $current = GE_WTP_Customer_Branches::find( $quote['customer_id'], $quote['snapshot']['billing_profile_id'] ?? 'default' );
        if ( ! $current ) { return new WP_Error( 'ge_quote_billing_changed', 'El perfil fiscal seleccionado ya no está activo; hace falta una nueva versión.' ); }
        foreach ( array( 'billing_mode', 'cuit', 'legal_name', 'vat_status', 'billing_email', 'fiscal_address' ) as $field ) {
            if ( ( $billing['profile'][ $field ] ?? null ) !== ( $current[ $field ] ?? null ) ) {
                return new WP_Error( 'ge_quote_billing_changed', 'El perfil fiscal del cliente cambió; hace falta una nueva versión.' );
            }
        }
        return true;
    }

    /** Resolve a draft for staff conversion without sending an email. */
    public static function prepare_for_conversion( $quote_id, $actor_id ) {
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) { return new WP_Error( 'ge_quote_forbidden', 'Acceso denegado.' ); }
        $quote = self::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( GE_WTP_Quote_Selection::has_choices( $quote['snapshot'] ) && empty( $quote['snapshot']['customer_selection'] ) ) { return new WP_Error( 'ge_quote_selection_required', 'El cliente debe elegir las variantes antes de convertir.' ); }
        if ( isset( $quote['snapshot']['total_cents'] ) ) {
            $valid = self::check_billing_snapshot( $quote );
            return is_wp_error( $valid ) ? $valid : $quote;
        }
        if ( 'draft' !== $quote['status'] ) { return new WP_Error( 'ge_quote_total', 'El presupuesto no tiene un total fiscal válido.' ); }
        if ( self::needs_roll_reprice( $quote['snapshot'] ) ) { return new WP_Error( 'ge_quote_roll_reprice', 'Revisá y guardá el precio del vinilo antes de convertir.' ); }
        $resolved = self::resolve_billing( $quote['customer_id'], $quote['snapshot'] );
        if ( is_wp_error( $resolved ) ) { return $resolved; }
        $versions = get_post_meta( $quote_id, self::VERSIONS_META, true );
        if ( ! is_array( $versions ) ) { return new WP_Error( 'ge_quote_corrupt', 'Historial inválido.' ); }
        $versions[ $quote['version'] ] = $resolved;
        update_post_meta( $quote_id, self::VERSIONS_META, $versions );
        self::event( $quote_id, $quote['version'], 'billing_resolved_for_conversion', $actor_id );
        return self::get( $quote_id, $actor_id );
    }

    public static function mark_viewed( $quote_id, $actor_id ) {
        $quote = self::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) || (int) $quote['customer_id'] !== (int) $actor_id ) { return; }
        if ( 'sent' === $quote['status'] ) {
            update_post_meta( $quote_id, self::STATUS_META, 'viewed' );
            self::event( $quote_id, $quote['version'], 'viewed', $actor_id );
        }
    }

    public static function record_event( $quote_id, $name, $actor_id, $data = array() ) {
        $quote = self::get( $quote_id );
        if ( is_wp_error( $quote ) ) { return; }
        $events = get_post_meta( $quote_id, '_ge_commercial_events', true );
        if ( ! is_array( $events ) ) { $events = array(); }
        $events[] = array( 'event' => sanitize_key( $name ), 'version' => (int) $quote['version'], 'actor_id' => (int) $actor_id, 'at' => gmdate( 'c' ), 'data' => $data );
        update_post_meta( $quote_id, '_ge_commercial_events', $events );
    }

    /** Four independent axes; a commercial acceptance never approves artwork. */
    public static function state_axes( $quote ) {
        $order = ! empty( $quote['converted_order_id'] ) ? wc_get_order( $quote['converted_order_id'] ) : false;
        $payment = $order ? (string) $order->get_meta( '_ge_payment_state', true ) : 'pending';
        if ( ! $order ) {
            $attempt_id = absint( get_post_meta( $quote['id'], '_ge_commercial_initial_payment_order', true ) );
            $attempt = $attempt_id ? wc_get_order( $attempt_id ) : false;
            if ( $attempt && in_array( $attempt->get_status(), array( 'failed', 'cancelled' ), true ) ) { $payment = 'failed'; }
        }
        if ( 'unpaid' === $payment || ! $payment ) { $payment = 'pending'; }
        $files = GE_WTP_Commercial_Quote_Files::all( $quote['id'] );
        $artwork = $files ? 'received' : 'none';
        if ( $files ) {
            $last = end( $files );
            if ( in_array( $last['analysis']['confidence'] ?? '', array( 'medium', 'high' ), true ) ) { $artwork = 'analyzed'; }
            if ( 'final' === ( $last['source_type'] ?? '' ) ) { $artwork = 'final'; }
        }
        if ( $order ) {
            $items = $order->get_items( 'line_item' );
            $approved = (bool) $items;
            foreach ( $items as $item ) {
                if ( $item->get_meta( '_ge_item_artwork_sources', true ) && 'final' === $artwork ) { $artwork = 'approval_pending'; }
                if ( ! $item->get_meta( '_ge_item_artwork_release_hash', true ) ) { $approved = false; }
            }
            if ( $approved ) { $artwork = 'approved'; }
        }
        $production = 'not_created';
        if ( $order ) {
            $status = (string) $order->get_meta( '_ge_production_status', true );
            $production = array( 'pending' => 'created', 'approved' => 'ready', 'production' => 'in_production', 'ready' => 'ready_for_delivery', 'delivered' => 'delivered' )[ $status ] ?? 'created';
        }
        return array( 'commercial' => $quote['status'], 'payment' => $payment, 'artwork' => $artwork, 'production' => $production );
    }

    private static function event( $quote_id, $version, $name, $actor_id ) {
        $events = get_post_meta( $quote_id, '_ge_commercial_events', true );
        if ( ! is_array( $events ) ) { $events = array(); }
        $events[] = array( 'event' => $name, 'version' => (int) $version, 'actor_id' => (int) $actor_id, 'at' => gmdate( 'c' ) );
        update_post_meta( $quote_id, '_ge_commercial_events', $events );
    }
}
