<?php

defined( 'ABSPATH' ) || exit;

/** Commercial proposals. A quote never is a WooCommerce order. */
final class GE_WTP_Commercial_Quotes {
    const POST_TYPE = 'ge_commercial_quote';
    const STATUS_META = '_ge_commercial_status';
    const CUSTOMER_META = '_ge_commercial_customer_id';
    const VERSIONS_META = '_ge_commercial_versions';
    const CURRENT_META = '_ge_commercial_current_version';
    const ORDER_META = '_ge_commercial_order_id';

    public static function init() {
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
        return array(
            'id' => (int) $post->ID,
            'number' => 'GE-PRE-' . $post->ID,
            'customer_id' => absint( get_post_meta( $post->ID, self::CUSTOMER_META, true ) ),
            'status' => (string) get_post_meta( $post->ID, self::STATUS_META, true ),
            'version' => $version,
            'snapshot' => is_array( $versions ) && isset( $versions[ $version ] ) ? $versions[ $version ] : array(),
            'converted_order_id' => absint( get_post_meta( $post->ID, self::ORDER_META, true ) ),
        );
    }

    public static function can_access( $quote_id, $actor_id ) {
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
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) {
            return new WP_Error( 'ge_quote_forbidden', 'No tenés permiso para crear presupuestos.' );
        }
        $customer = get_userdata( absint( $customer_id ) );
        if ( ! $customer || ! is_email( $customer->user_email ) ) {
            return new WP_Error( 'ge_quote_customer', 'El cliente necesita una ficha con email válido.' );
        }
        $snapshot = self::build_snapshot( $lines, $args );
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
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
        return self::get( $post_id, $actor_id );
    }

    /**
     * A sent revision is never edited in place. New terms become a new draft
     * version; a previously accepted version remains available for audit.
     */
    public static function revise( $quote_id, $lines, $args = array(), $actor_id = 0 ) {
        $actor_id = $actor_id ?: get_current_user_id();
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
        $snapshot = self::build_snapshot( $lines, $args );
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        $versions = get_post_meta( $quote_id, self::VERSIONS_META, true );
        if ( ! is_array( $versions ) ) { return new WP_Error( 'ge_quote_corrupt', 'Historial del presupuesto inválido.' ); }
        $version = $quote['status'] === 'draft' ? $quote['version'] : $quote['version'] + 1;
        $versions[ $version ] = $snapshot;
        update_post_meta( $quote_id, self::VERSIONS_META, $versions );
        update_post_meta( $quote_id, self::CURRENT_META, $version );
        update_post_meta( $quote_id, self::STATUS_META, 'draft' );
        self::event( $quote_id, $version, 'revised', $actor_id );
        return self::get( $quote_id, $actor_id );
    }

    public static function send( $quote_id, $actor_id = 0 ) {
        $actor_id = $actor_id ?: get_current_user_id();
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) {
            return new WP_Error( 'ge_quote_forbidden', 'No tenés permiso para enviar presupuestos.' );
        }
        $quote = self::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( 'draft' !== $quote['status'] ) { return new WP_Error( 'ge_quote_state', 'Sólo puede enviarse un borrador.' ); }
        if ( self::needs_roll_reprice( $quote['snapshot'] ) ) { return new WP_Error( 'ge_quote_roll_reprice', 'Editá y guardá este borrador para recalcular el vinilo según el ancho del rollo antes de enviarlo.' ); }
        $snapshot = self::resolve_billing( $quote['customer_id'], $quote['snapshot'] );
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        $versions = get_post_meta( $quote_id, self::VERSIONS_META, true );
        $versions[ $quote['version'] ] = $snapshot;
        update_post_meta( $quote_id, self::VERSIONS_META, $versions );
        $customer = get_userdata( $quote['customer_id'] );
        $url = GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $quote_id ) );
        $body = '<p>Hola ' . esc_html( $customer->first_name ?: $customer->display_name ) . ',</p><p>Tenés un nuevo presupuesto de Graph Express para revisar.</p><p><a href="' . esc_url( $url ) . '">Ver presupuesto ' . esc_html( $quote['number'] ) . '</a></p>';
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
        $quote = self::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( $actor_id !== $quote['customer_id'] || 'sent' !== $quote['status'] || (int) $version !== $quote['version'] ) {
            return new WP_Error( 'ge_quote_accept', 'El presupuesto cambió o no está disponible para aceptar.' );
        }
        if ( ! empty( $quote['snapshot']['valid_until'] ) && $quote['snapshot']['valid_until'] < wp_date( 'Y-m-d' ) ) {
            return new WP_Error( 'ge_quote_expired', 'El presupuesto venció. Solicitá una actualización.' );
        }
        $billing = self::check_billing_snapshot( $quote );
        if ( is_wp_error( $billing ) ) { return $billing; }
        $lock = 'ge_commercial_quote_accept_' . $quote_id;
        if ( ! add_option( $lock, time(), '', 'no' ) ) { return new WP_Error( 'ge_quote_busy', 'Estamos procesando el presupuesto. Volvé a intentar.' ); }
        try {
            $quote = self::get( $quote_id, $actor_id );
            if ( is_wp_error( $quote ) || 'sent' !== $quote['status'] || $quote['version'] !== (int) $version ) {
                return new WP_Error( 'ge_quote_accept', 'El presupuesto ya no está disponible.' );
            }
            update_post_meta( $quote_id, self::STATUS_META, 'accepted' );
            update_post_meta( $quote_id, '_ge_commercial_accepted_at', gmdate( 'c' ) );
            self::event( $quote_id, $version, 'accepted', $actor_id );
            return self::get( $quote_id, $actor_id );
        } finally {
            delete_option( $lock );
        }
    }

    public static function build_snapshot( $lines, $args = array() ) {
        if ( ! is_array( $lines ) || ! $lines || count( $lines ) > 30 ) {
            return new WP_Error( 'ge_quote_lines', 'Agregá entre 1 y 30 ítems.' );
        }
        $items = array(); $net = 0;
        foreach ( $lines as $line ) {
            if ( ! is_array( $line ) ) { return new WP_Error( 'ge_quote_line', 'Ítem inválido.' ); }
            $product_id = absint( $line['product_id'] ?? 0 );
            $product = $product_id ? wc_get_product( $product_id ) : false;
            if ( $product_id && ( ! $product || 'publish' !== $product->get_status() ) ) {
                return new WP_Error( 'ge_quote_product', 'Un producto de catálogo ya no está disponible.' );
            }
            $name = sanitize_text_field( $line['name'] ?? ( $product ? $product->get_name() : '' ) );
            $quantity = absint( $line['quantity'] ?? 0 );
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
            if ( $line_cents <= 0 || $line_cents > 999999999999 ) { return new WP_Error( 'ge_quote_price', 'Importe de ítem fuera de rango.' ); }
            $items[] = array(
                'product_id' => $product_id,
                'sku' => $product ? $product->get_sku() : '',
                'name' => $name,
                'quantity' => $quantity,
                'unit_net_cents' => $unit_cents,
                'net_cents' => $line_cents,
                'details' => sanitize_textarea_field( $line['details'] ?? '' ),
                'configuration' => $configuration,
                'configuration_label' => $configuration_label,
                'finishes' => $finishes,
                'lead_days' => absint( $line['lead_days'] ?? 0 ),
            );
            $net += $line_cents;
            if ( $net > 999999999999 ) { return new WP_Error( 'ge_quote_price', 'Total fuera de rango.' ); }
        }
        $valid_until = sanitize_text_field( $args['valid_until'] ?? wp_date( 'Y-m-d', strtotime( '+30 days', current_time( 'timestamp' ) ) ) );
        if ( $valid_until && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $valid_until ) || $valid_until < wp_date( 'Y-m-d' ) ) ) {
            return new WP_Error( 'ge_quote_validity', 'Fecha de validez inválida.' );
        }
        $deposit_percent = absint( $args['deposit_percent'] ?? get_option( 'ge_commercial_deposit_percent', 50 ) );
        if ( $deposit_percent < 1 || $deposit_percent > 100 ) { return new WP_Error( 'ge_quote_deposit', 'Porcentaje de seña inválido.' ); }
        return array(
            'items' => $items,
            'currency' => 'ARS',
            'net_cents' => $net,
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

    private static function resolve_billing( $customer_id, $snapshot ) {
        if ( ! class_exists( 'GE_WTP_Billing' ) ) {
            return new WP_Error( 'ge_quote_billing_unavailable', 'Falta configurar el perfil fiscal antes de enviar presupuestos.' );
        }
        $entity = GE_WTP_Billing::entity();
        $profile = GE_WTP_Customer_Branches::find( $customer_id, $snapshot['billing_profile_id'] ?? 'default' );
        if ( ! $profile ) { return new WP_Error( 'ge_quote_profile', 'Seleccioná un perfil de facturación activo del cliente.' ); }
        $delivery_id = $snapshot['delivery_address_id'] ?? '';
        $delivery = '' !== (string) $delivery_id ? GE_WTP_Customer_Branches::delivery( $customer_id, $delivery_id ) : null;
        if ( '' !== (string) $delivery_id && ! $delivery ) { return new WP_Error( 'ge_quote_delivery', 'Seleccioná una dirección de entrega del cliente.' ); }
        $resolution = GE_WTP_Billing::resolve_net_quote( $entity, $profile, (int) $snapshot['net_cents'] );
        if ( ! empty( $resolution['blockers'] ) ) {
            return new WP_Error( 'ge_quote_billing_blocked', 'Faltan datos fiscales o una configuración de facturación válida.', $resolution['blockers'] );
        }
        if ( 'tax_exclusive' !== $resolution['tax_treatment'] ) {
            return new WP_Error( 'ge_quote_billing_policy', 'El presupuesto requiere precios de entrada antes de IVA.' );
        }
        $snapshot['billing'] = GE_WTP_Billing::snapshot( $entity, $profile, $resolution );
        $snapshot['delivery'] = $delivery;
        $snapshot['tax_cents'] = (int) $resolution['tax_cents'];
        $snapshot['total_cents'] = (int) $resolution['total_cents'];
        $snapshot['snapshot_hash'] = hash( 'sha256', wp_json_encode( $snapshot ) );
        return $snapshot;
    }

    public static function check_billing_snapshot( $quote ) {
        $billing = $quote['snapshot']['billing'] ?? array();
        try { GE_WTP_Billing::assert_can_accept_or_pay( $billing, GE_WTP_Billing::entity() ); }
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

    private static function event( $quote_id, $version, $name, $actor_id ) {
        $events = get_post_meta( $quote_id, '_ge_commercial_events', true );
        if ( ! is_array( $events ) ) { $events = array(); }
        $events[] = array( 'event' => $name, 'version' => (int) $version, 'actor_id' => (int) $actor_id, 'at' => gmdate( 'c' ) );
        update_post_meta( $quote_id, '_ge_commercial_events', $events );
    }
}
