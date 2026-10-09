<?php
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-ge-wtp-arca-client.php';

/** One immutable invoice per order/environment; every uncertain submission is reconciled. */
final class GE_WTP_ARCA_Emission {
    const VERSION = '3';
    public static function init() {
        add_action( 'admin_post_ge_arca_invoice_review', array( __CLASS__, 'review' ) );
        add_action( 'admin_post_ge_arca_invoice_emit', array( __CLASS__, 'handle_emit' ) );
        add_action( 'admin_post_ge_arca_invoice_recover', array( __CLASS__, 'handle_recover' ) );
    }
    public static function table() { global $wpdb; return $wpdb->prefix . 'ge_arca_invoices'; }
    public static function install() {
        global $wpdb;
        if ( self::VERSION !== get_option( 'ge_arca_schema_version' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $table = self::table(); $charset = $wpdb->get_charset_collate();
            dbDelta( "CREATE TABLE $table (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                order_id bigint unsigned NOT NULL,
                environment varchar(20) NOT NULL,
                scope varchar(64) NOT NULL,
                number bigint unsigned NOT NULL,
                state varchar(24) NOT NULL,
                payload longtext NOT NULL,
                authorization longtext NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY order_environment (order_id,environment),
                KEY sequence (scope,number),
                KEY pending (scope,state)
            ) $charset;" );
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { return new WP_Error( 'ge_arca_database', 'No se pudo crear el registro fiscal.' ); }
            // A rejected request does not consume its ARCA number. Keep every attempt,
            // while GET_LOCK serializes numbering and uncertain attempts block reuse.
            $sequence = $wpdb->get_row( "SHOW INDEX FROM $table WHERE Key_name='sequence'", ARRAY_A );
            if ( $sequence && '0' === (string) $sequence['Non_unique'] ) {
                if ( false === $wpdb->query( "ALTER TABLE $table DROP INDEX sequence, ADD KEY sequence (scope,number)" ) ) { return new WP_Error( 'ge_arca_database', 'No se pudo actualizar el registro fiscal.' ); }
            }
            $order_index = $wpdb->get_row( "SHOW INDEX FROM $table WHERE Key_name='order_environment'", ARRAY_A );
            if ( $order_index && '0' === (string) $order_index['Non_unique'] && false === $wpdb->query( "ALTER TABLE $table DROP INDEX order_environment, ADD KEY order_environment (order_id,environment)" ) ) { return new WP_Error( 'ge_arca_database', 'No se pudo actualizar el historial fiscal.' ); }
            update_option( 'ge_arca_schema_version', self::VERSION, false );
        }
        return true;
    }
    public static function can_emit( $order, $actor ) {
        if ( ! $order || ( ! user_can( $actor, 'manage_woocommerce' ) && ! user_can( $actor, 'ge_manage_operations' ) ) ) { return false; }
        if ( class_exists( 'GE_Organization_Runtime' ) && ! GE_Organization_Runtime::allowed( 'finance', true, $actor ) ) { return false; }
        $org = $order->get_meta( '_ge_organization_id', true );
        if ( class_exists( 'GE_Organization' ) && $org && $org !== GE_Organization::PRIMARY ) { return false; }
        return true;
    }
    public static function cents( $value ) {
        $value = (string) $value;
        if ( ! preg_match( '/^(\d{1,11})(?:\.(\d{1,2}))?$/D', $value, $m ) ) { throw new InvalidArgumentException( 'Invalid fiscal amount' ); }
        return (int) $m[1] * 100 + (int) str_pad( $m[2] ?? '', 2, '0' );
    }
    private static function decimal( $cents ) { return sprintf( '%d.%02d', intdiv( $cents, 100 ), $cents % 100 ); }
    private static function date( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) { return ''; }
        $d = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
        return $d && $d->format( 'Y-m-d' ) === $value ? $d->format( 'Ymd' ) : '';
    }
    public static function prepare( $order, $input, $actor ) {
        if ( ! self::can_emit( $order, $actor ) ) { return new WP_Error( 'ge_arca_access', 'No tenés permiso para facturar este pedido.' ); }
        if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { return new WP_Error( 'ge_arca_order', 'El estado del pedido requiere revisión antes de facturar.' ); }
        if ( $order->get_currency() !== 'ARS' ) { return new WP_Error( 'ge_arca_currency', 'Esta integración requiere una operación local en pesos.' ); }
        if ( GE_WTP_Documents::issued_documents( $order->get_id(), true ) ) {
            foreach ( GE_WTP_Documents::issued_documents( $order->get_id(), true ) as $doc ) { if ( 'factura' === ( $doc['category'] ?? '' ) && empty( $doc['arca_invoice_id'] ) ) { return new WP_Error( 'ge_arca_existing_invoice', 'Este pedido ya tiene una factura cargada. Revisá su origen antes de emitir.' ); } }
        }
        $issuer = GE_WTP_Billing_Issuers::order_snapshot( $order );
        $original_issuer = $issuer;
        $original_receiver = (array) $order->get_meta( '_ge_billing_profile_snapshot', true );
        if ( '1' === (string) ( $input['refresh_fiscal'] ?? '' ) ) {
            $latest = GE_WTP_Billing_Issuers::get( $issuer['id'] ?? '' );
            if ( ! $latest || (string) $latest['cuit'] !== (string) ( $issuer['cuit'] ?? '' ) || (int) $latest['tax_rate_basis_points'] !== (int) ( $issuer['tax_rate_basis_points'] ?? 0 ) ) { return new WP_Error( 'ge_arca_issuer_review', 'El CUIT o IVA del emisor cambió. Requiere una rectificación comercial previa.' ); }
            $issuer = GE_WTP_Billing_Issuers::capture( $latest );
        }
        $valid = GE_WTP_Billing_Issuers::validate_current( $issuer );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $current = GE_WTP_Billing_Issuers::get( $issuer['id'] );
        $receiver = $order->get_meta( '_ge_billing_profile_snapshot', true );
        if ( ! is_array( $receiver ) ) { $receiver = array(); }
        if ( '1' === (string) ( $input['refresh_fiscal'] ?? '' ) ) {
            $lookup = GE_WTP_ARCA_Lookup::lookup( $receiver['cuit'] ?? '' );
            if ( empty( $lookup['verified'] ) ) { return new WP_Error( 'ge_arca_receiver_review', 'ARCA no confirmó los datos actuales del receptor. No se puede emitir.' ); }
            $receiver = array_merge( $receiver, $lookup['profile'] );
        }
        $tax = GE_WTP_Customer_Tax::resolve( $current, $receiver );
        if ( empty( $tax['ready'] ) || empty( $receiver['legal_name'] ) || empty( $receiver['fiscal_address'] ) || ! GE_WTP_ARCA_Lookup::valid_cuit( $receiver['cuit'] ?? '' ) ) { return new WP_Error( 'ge_arca_fiscal_review', 'Verificá emisor y receptor, su condición fiscal, CUIT y domicilio antes de facturar.' ); }
        $class = $tax['required_document_class'];
        $types = array( 'A' => 1, 'B' => 6, 'C' => 11 );
        $vat_ids = array( 'registered' => 1, 'exempt' => 4, 'final_consumer' => 5, 'monotributo' => 6 );
        if ( ! isset( $types[$class], $vat_ids[$receiver['vat_status'] ?? ''] ) ) { return new WP_Error( 'ge_arca_type', 'El comprobante requiere una integración fiscal específica.' ); }
        // FCE, exports, associated notes and special regimes require their own reviewed workflow.
        if ( ! empty( $input['special_regime'] ) || ! empty( $receiver['requires_fce'] ) || 'AR' !== ( $current['country'] ?? 'AR' ) || ! in_array( $receiver['country'] ?? 'AR', array( 'AR', '' ), true ) ) { return new WP_Error( 'ge_arca_regime', 'La operación requiere revisión de régimen fiscal.' ); }
        $config = GE_WTP_ARCA_Client::configuration( $current );
        if ( is_wp_error( $config ) ) { return $config; }
        $activity = self::date( $config['activity_start'] ?? '' );
        $terms = sanitize_text_field( $input['sale_terms'] ?? '' );
        if ( ! $activity || empty( $current['iibb'] ) || ! in_array( $terms, array( 'Contado', 'Cuenta corriente' ), true ) ) { return new WP_Error( 'ge_arca_invoice_data', 'Falta verificar IIBB, inicio de actividades o condición de venta para el comprobante.' ); }
        $pos = (string) $issuer['point_of_sale'];
        if ( ! preg_match( '/^[0-9]{1,5}$/D', $pos ) || (int) $pos < 1 ) { return new WP_Error( 'ge_arca_pos', 'Punto de venta inválido.' ); }
        try { $total = self::cents( $order->get_total() ); $iva = self::cents( $order->get_total_tax() ); }
        catch ( Throwable $e ) { return new WP_Error( 'ge_arca_amount', 'Revisá los importes del pedido.' ); }
        if ( $total <= 0 || $iva > $total ) { return new WP_Error( 'ge_arca_amount', 'Importes fiscales inválidos.' ); }
        $net = $total - $iva;
        $rate = (int) ( $issuer['tax_rate_basis_points'] ?? 0 );
        $rates = array( 250 => 9, 500 => 8, 1050 => 4, 2100 => 5, 2700 => 6 );
        if ( 'C' === $class ? ( $iva !== 0 || $rate !== 0 ) : ( ! isset( $rates[$rate] ) || abs( intdiv( $net * $rate + 5000, 10000 ) - $iva ) > 1 ) ) { return new WP_Error( 'ge_arca_tax', 'El IVA del pedido no coincide con la alícuota verificada. Revisá el pedido sin alterar su historial.' ); }
        $concept = (int) ( $input['concept'] ?? 0 );
        $date = self::date( $input['issue_date'] ?? '' );
        $today = new DateTimeImmutable( 'now', new DateTimeZone( 'America/Argentina/Buenos_Aires' ) );
        if ( ! in_array( $concept, array( 1, 2, 3 ), true ) || ! $date || $date !== $today->format( 'Ymd' ) ) { return new WP_Error( 'ge_arca_dates', 'Seleccioná el concepto y la fecha de hoy en Argentina.' ); }
        $detail = array( 'Concepto' => $concept, 'DocTipo' => 80, 'DocNro' => $receiver['cuit'], 'CbteFch' => $date,
            'ImpTotal' => self::decimal( $total ), 'ImpTotConc' => '0.00', 'ImpNeto' => self::decimal( $net ), 'ImpOpEx' => '0.00', 'ImpTrib' => '0.00', 'ImpIVA' => self::decimal( $iva ),
            'MonId' => 'PES', 'MonCotiz' => 1, 'CondicionIVAReceptorId' => $vat_ids[$receiver['vat_status']] );
        if ( 'C' !== $class ) { $detail['Iva'] = array( 'AlicIva' => array( array( 'Id' => $rates[$rate], 'BaseImp' => self::decimal( $net ), 'Importe' => self::decimal( $iva ) ) ) ); }
        if ( $concept !== 1 ) {
            $from = self::date( $input['service_from'] ?? '' ); $to = self::date( $input['service_to'] ?? '' ); $due = self::date( $input['payment_due'] ?? '' );
            if ( ! $from || ! $to || ! $due || $from > $to || $due < $date ) { return new WP_Error( 'ge_arca_dates', 'Revisá el período del servicio y el vencimiento del pago.' ); }
            $detail += array( 'FchServDesde' => $from, 'FchServHasta' => $to, 'FchVtoPago' => $due );
        }
        $lines = array();
        foreach ( $order->get_items() as $item ) {
            $specifications = array();
            foreach ( $item->get_formatted_meta_data( '_' ) as $meta ) {
                $label = wp_strip_all_tags( $meta->display_key );
                if ( in_array( $label, array( 'Precio unitario ARS', 'Tipo de cambio' ), true ) ) { continue; }
                $specifications[] = $label . ': ' . wp_strip_all_tags( $meta->display_value );
            }
            $description = $item->get_name() . ( $specifications ? "\n" . implode( ' · ', $specifications ) : '' );
            $lines[] = array( 'name' => $item->get_name(), 'description' => $description, 'quantity' => $item->get_quantity(), 'net' => $item->get_total(), 'tax' => $item->get_total_tax() );
        }
        if ( ! $lines ) { return new WP_Error( 'ge_arca_items', 'Faltan los conceptos del pedido.' ); }
        try {
            $line_net = self::cents( $order->get_shipping_total() ); $line_tax = self::cents( $order->get_shipping_tax() );
            foreach ( $lines as $line ) {
                if ( ! is_numeric( $line['quantity'] ) || (float) $line['quantity'] <= 0 ) { throw new InvalidArgumentException( 'Invalid quantity' ); }
                $line_net += self::cents( $line['net'] ); $line_tax += self::cents( $line['tax'] );
            }
            foreach ( $order->get_fees() as $fee ) { $line_net += self::cents( $fee->get_total() ); $line_tax += self::cents( $fee->get_total_tax() ); }
            if ( $line_net !== $net || $line_tax !== $iva ) { throw new InvalidArgumentException( 'Inconsistent fiscal totals' ); }
        } catch ( Throwable $e ) { return new WP_Error( 'ge_arca_totals', 'El detalle, envío y cargos no coinciden con los totales fiscales. Revisá el pedido antes de emitir.' ); }
        return array( 'order_id' => $order->get_id(), 'environment' => $config['environment'], 'issuer' => $issuer, 'receiver' => $receiver, 'class' => $class,
            'fiscal_refresh_reviewed' => '1' === (string) ( $input['refresh_fiscal'] ?? '' ), 'original_issuer_hash' => hash( 'sha256', wp_json_encode( $original_issuer ) ), 'original_receiver_hash' => hash( 'sha256', wp_json_encode( $original_receiver ) ),
            'header' => array( 'CantReg' => 1, 'PtoVta' => (int) $pos, 'CbteTipo' => $types[$class] ), 'detail' => $detail, 'items' => $lines,
            'activity_start' => $config['activity_start'], 'sale_terms' => $terms, 'shipping_net' => $order->get_shipping_total(), 'fees' => array_map( function( $item ) { return array( 'name' => $item->get_name(), 'net' => $item->get_total() ); }, array_values( $order->get_fees() ) ) );
    }
    public static function fingerprint( $payload ) { return hash( 'sha256', wp_json_encode( $payload ) ); }
    public static function entries( $node, $key ) { $v = $node[$key] ?? array(); return isset( $v['Code'] ) || isset( $v['Id'] ) || isset( $v['Nro'] ) ? array( $v ) : (array) $v; }
    public static function remote_error( $result ) {
        if ( is_wp_error( $result ) ) { return $result; }
        $errors = self::entries( $result['Errors'] ?? array(), 'Err' );
        return $errors ? new WP_Error( 'ge_arca_rejected', 'ARCA devolvió errores: ' . implode( ', ', array_map( function( $e ) { return (string) ( $e['Code'] ?? '' ); }, $errors ) ), $errors ) : null;
    }
    public static function check_capabilities( $client, $payload ) {
        $pos = $client->call( 'FEParamGetPtosVenta' ); $err = self::remote_error( $pos );
        $test_pos = method_exists( $client, 'homologation_pos_allowed' ) && $client->homologation_pos_allowed( $payload['header']['PtoVta'] );
        $codes = is_wp_error( $err ) ? array_column( (array) $err->get_error_data(), 'Code' ) : array();
        if ( $err && ! ( $test_pos && count( $codes ) === 1 && (int) $codes[0] === 602 ) ) { return $err; }
        $found = false;
        foreach ( self::entries( $pos['ResultGet'] ?? array(), 'PtoVenta' ) as $p ) {
            $closed = trim( (string) ( $p['FchBaja'] ?? '' ) );
            if ( (int) ( $p['Nro'] ?? 0 ) === $payload['header']['PtoVta'] && 'N' === ( $p['Bloqueado'] ?? '' ) && ( '' === $closed || 'NULL' === $closed ) && strpos( (string) ( $p['EmisionTipo'] ?? '' ), 'CAE' ) === 0 ) { $found = true; }
        }
        if ( ! $found && ! ( $test_pos && $err ) ) { return new WP_Error( 'ge_arca_pos', 'ARCA no informa el punto de venta activo para Web Services.' ); }
        $types = $client->call( 'FEParamGetTiposCbte' ); $err = self::remote_error( $types ); if ( $err ) { return $err; }
        $found = false;
        foreach ( self::entries( $types['ResultGet'] ?? array(), 'CbteTipo' ) as $t ) { if ( (int) ( $t['Id'] ?? 0 ) === $payload['header']['CbteTipo'] ) { $found = true; } }
        if ( ! $found ) { return new WP_Error( 'ge_arca_type', 'ARCA no informa el tipo de comprobante.' ); }
        $conditions = $client->call( 'FEParamGetCondicionIvaReceptor', array( 'ClaseCmp' => $payload['class'] ) ); $err = self::remote_error( $conditions ); if ( $err ) { return $err; }
        foreach ( self::entries( $conditions['ResultGet'] ?? array(), 'CondicionIvaReceptor' ) as $r ) { if ( (int) ( $r['Id'] ?? 0 ) === $payload['detail']['CondicionIVAReceptorId'] ) { return true; } }
        return new WP_Error( 'ge_arca_receiver_vat', 'ARCA no permite la condición IVA del receptor para esta clase.' );
    }
    public static function journal( $order_id, $environment ) {
        global $wpdb;
        $r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id=%d AND environment=%s ORDER BY id DESC LIMIT 1', $order_id, $environment ), ARRAY_A );
        if ( $r ) { $r['payload'] = json_decode( $r['payload'], true ); $r['authorization'] = json_decode( $r['authorization'], true ); }
        return $r;
    }
    public static function locks_order( $order_id ) {
        global $wpdb;
        if ( ! get_option( 'ge_arca_schema_version' ) ) { return false; }
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE order_id=%d AND environment='production' AND state<>'rejected' LIMIT 1", $order_id ) );
    }
    private static function store( $id, $state, $authorization = array() ) {
        global $wpdb;
        if ( false === $wpdb->update( self::table(), array( 'state' => $state, 'authorization' => wp_json_encode( $authorization ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) ) ) { throw new RuntimeException( 'Fiscal journal unavailable' ); }
    }
    public static function matches( $remote, $payload, $number ) {
        $d = $payload['detail'];
        foreach ( array( 'Concepto', 'DocTipo', 'DocNro', 'CbteFch', 'MonId', 'FchServDesde', 'FchServHasta', 'FchVtoPago' ) as $key ) { if ( isset( $d[$key] ) && (string) ( $remote[$key] ?? '' ) !== (string) $d[$key] ) { return false; } }
        foreach ( array( 'ImpTotal', 'ImpTotConc', 'ImpNeto', 'ImpOpEx', 'ImpTrib', 'ImpIVA' ) as $key ) { if ( ! isset( $remote[$key] ) || abs( (float) $remote[$key] - (float) $d[$key] ) > 0.001 ) { return false; } }
        if ( (int) ( $remote['CbteDesde'] ?? 0 ) !== (int) $number || (int) ( $remote['CbteHasta'] ?? 0 ) !== (int) $number || (int) ( $remote['PtoVta'] ?? 0 ) !== $payload['header']['PtoVta'] || (int) ( $remote['CbteTipo'] ?? 0 ) !== $payload['header']['CbteTipo'] || (float) ( $remote['MonCotiz'] ?? 0 ) !== 1.0 ) { return false; }
        return 'A' === ( $remote['Resultado'] ?? '' ) && 'CAE' === ( $remote['EmisionTipo'] ?? '' ) && preg_match( '/^[0-9]{14}$/D', (string) ( $remote['CodAutorizacion'] ?? '' ) ) && preg_match( '/^[0-9]{8}$/D', (string) ( $remote['FchVto'] ?? '' ) );
    }
    /** Never resend an uncertain request automatically. Recovery only consults its exact number. */
    public static function execute( $payload, $client, $actor, $recovery_only = false ) {
        global $wpdb;
        if ( ! self::can_emit( wc_get_order( $payload['order_id'] ), $actor ) ) { return new WP_Error( 'ge_arca_access', 'No tenés permiso para facturar este pedido.' ); }
        if ( ! method_exists( $client, 'environment' ) || $client->environment() !== $payload['environment'] ) { return new WP_Error( 'ge_arca_environment', 'El ambiente del comprobante no coincide con el transporte.' ); }
        $installed = self::install(); if ( is_wp_error( $installed ) ) { return $installed; }
        $scope = hash( 'sha256', $payload['environment'] . '|' . $payload['issuer']['cuit'] . '|' . $payload['header']['PtoVta'] . '|' . $payload['header']['CbteTipo'] );
        $lock = 'ge_arca_' . substr( $scope, 0, 48 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return new WP_Error( 'ge_arca_busy', 'Otra emisión está en curso. Esperá y consultá el estado.' ); }
        try {
            $row = self::journal( $payload['order_id'], $payload['environment'] );
            // A new reviewed request may follow a definitive rejection. Retain the
            // previous attempt; recovery itself can never submit a fresh request.
            if ( $row && 'rejected' === $row['state'] && ! $recovery_only ) { $row = null; }
            if ( $row ) {
                if ( 'authorized' === $row['state'] ) { return self::publish( $row ); }
                $payload = $row['payload'];
                if ( $row['scope'] !== $scope ) { return new WP_Error( 'ge_arca_changed', 'La emisión pendiente corresponde a otro emisor o punto de venta. Requiere revisión.' ); }
                if ( 'rejected' === $row['state'] ) { return new WP_Error( 'ge_arca_rejected', 'La solicitud fue rechazada. Revisá el registro fiscal; no se reenviará automáticamente.' ); }
                $number = (int) $row['number'];
                $query = $client->call( 'FECompConsultar', array( 'FeCompConsReq' => array( 'CbteTipo' => $payload['header']['CbteTipo'], 'CbteNro' => $number, 'PtoVta' => $payload['header']['PtoVta'] ) ) );
                if ( ! is_wp_error( $query ) && isset( $query['ResultGet'] ) ) {
                    if ( ! self::matches( $query['ResultGet'], $payload, $number ) ) { self::store( $row['id'], 'conflict' ); return new WP_Error( 'ge_arca_conflict', 'El número existe con otros datos. Revisión fiscal obligatoria.' ); }
                    $auth = array( 'cae' => (string) $query['ResultGet']['CodAutorizacion'], 'expires' => (string) $query['ResultGet']['FchVto'], 'recovered' => true, 'observations' => $query['ResultGet']['Observaciones'] ?? array() );
                    self::store( $row['id'], 'authorized', $auth ); $row['state'] = 'authorized'; $row['authorization'] = $auth;
                    return self::publish( $row );
                }
                return new WP_Error( 'ge_arca_uncertain', 'No hay confirmación recuperable todavía. La solicitud queda conservada y no se emitirá otra factura. Consultá nuevamente o revisá con ARCA.' );
            }
            if ( $recovery_only ) { return new WP_Error( 'ge_arca_missing', 'No existe una solicitud para consultar. No se envió ninguna emisión.' ); }
            if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE scope=%s AND state NOT IN ('authorized','rejected') LIMIT 1", $scope ) ) ) { return new WP_Error( 'ge_arca_pending', 'Hay una solicitud incierta en este punto de venta. Recuperala antes de emitir otra.' ); }
            $ready = self::check_capabilities( $client, $payload ); if ( is_wp_error( $ready ) ) { return $ready; }
            $last = $client->call( 'FECompUltimoAutorizado', array( 'PtoVta' => $payload['header']['PtoVta'], 'CbteTipo' => $payload['header']['CbteTipo'] ) );
            $error = self::remote_error( $last ); if ( $error ) { return $error; }
            if ( ! isset( $last['CbteNro'] ) || ! is_numeric( $last['CbteNro'] ) || (int) $last['CbteNro'] < 0 || (int) $last['CbteNro'] >= 99999999 || (int) ( $last['PtoVta'] ?? 0 ) !== $payload['header']['PtoVta'] || (int) ( $last['CbteTipo'] ?? 0 ) !== $payload['header']['CbteTipo'] ) { return new WP_Error( 'ge_arca_number', 'ARCA no confirmó la numeración.' ); }
            $number = (int) $last['CbteNro'] + 1;
            $payload['detail']['CbteDesde'] = $number; $payload['detail']['CbteHasta'] = $number;
            $payload['approved_by'] = (int) $actor; $payload['approved_at'] = gmdate( 'c' );
            if ( false === $wpdb->insert( self::table(), array( 'order_id' => $payload['order_id'], 'environment' => $payload['environment'], 'scope' => $scope, 'number' => $number, 'state' => 'submitted', 'payload' => wp_json_encode( $payload ), 'authorization' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) ) ) { return new WP_Error( 'ge_arca_database', 'No se pudo reservar el registro fiscal. No se envió la solicitud.' ); }
            $id = (int) $wpdb->insert_id;
            $response = $client->call( 'FECAESolicitar', array( 'FeCAEReq' => array( 'FeCabReq' => $payload['header'], 'FeDetReq' => array( 'FECAEDetRequest' => array( $payload['detail'] ) ) ) ) );
            if ( is_wp_error( $response ) ) { self::store( $id, 'uncertain' ); return $response; }
            $details = $response['FeDetResp']['FECAEDetResponse'] ?? array(); if ( isset( $details[0] ) ) { $details = $details[0]; }
            $response_header = $response['FeCabResp'] ?? array();
            if ( (int) ( $response_header['PtoVta'] ?? 0 ) === $payload['header']['PtoVta'] && (int) ( $response_header['CbteTipo'] ?? 0 ) === $payload['header']['CbteTipo'] && (string) ( $response_header['Cuit'] ?? '' ) === $payload['issuer']['cuit'] && 'A' === ( $details['Resultado'] ?? '' ) && (int) ( $details['CbteDesde'] ?? 0 ) === $number && (int) ( $details['CbteHasta'] ?? 0 ) === $number && (string) ( $details['CbteFch'] ?? '' ) === $payload['detail']['CbteFch'] && preg_match( '/^[0-9]{14}$/D', (string) ( $details['CAE'] ?? '' ) ) && preg_match( '/^[0-9]{8}$/D', (string) ( $details['CAEFchVto'] ?? '' ) ) ) {
                $auth = array( 'cae' => (string) $details['CAE'], 'expires' => (string) $details['CAEFchVto'], 'recovered' => false, 'observations' => $details['Observaciones'] ?? array() );
                self::store( $id, 'authorized', $auth );
                return self::publish( array( 'id' => $id, 'number' => $number, 'state' => 'authorized', 'payload' => $payload, 'authorization' => $auth ) );
            }
            // Even a sequence rejection may mean an external actor already used the number.
            $state = 'R' === ( $details['Resultado'] ?? '' ) ? 'rejected' : 'uncertain';
            self::store( $id, $state, array( 'errors' => $response['Errors'] ?? array(), 'observations' => $details['Observaciones'] ?? array() ) );
            return new WP_Error( 'ge_arca_' . $state, 'ARCA no autorizó esta solicitud. El registro queda conservado para revisión.' );
        } catch ( Throwable $e ) { return new WP_Error( 'ge_arca_uncertain', 'No se pudo confirmar el resultado. Consultá el registro fiscal antes de continuar.' ); }
        finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }
    private static function publish( $row ) {
        require_once __DIR__ . '/class-ge-wtp-arca-invoice-pdf.php';
        return GE_WTP_ARCA_Invoice_PDF::publish( $row );
    }
    public static function render_staff( $order ) {
        if ( ! self::can_emit( $order, get_current_user_id() ) ) { return; }
        echo '<section class="ge-admin-panel"><h3>Emisión electrónica ARCA</h3><p>La emisión se revisa por separado del presupuesto y del pedido. Una respuesta incierta se consulta antes de continuar.</p>';
        if ( get_option( 'ge_arca_schema_version' ) ) {
            foreach ( array( 'production', 'homologation' ) as $environment ) {
                $row = self::journal( $order->get_id(), $environment );
                if ( ! $row ) { continue; }
                echo '<p>Registro ARCA: ' . esc_html( $environment . ' · ' . $row['state'] . ' · Número ' . $row['number'] );
                if ( ! empty( $row['authorization']['cae'] ) ) { echo ' · CAE ' . esc_html( $row['authorization']['cae'] ) . ' · Vencimiento ' . esc_html( $row['authorization']['expires'] ); }
                echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_arca_invoice_recover"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="environment" value="' . esc_attr( $environment ) . '">';
                wp_nonce_field( 'ge_arca_recover_' . $order->get_id() );
                echo '<button type="submit">Consultar registro / recuperar PDF existente</button></form>';
            }
        }
        echo '<form class="ge-admin-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_arca_invoice_review"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
        wp_nonce_field( 'ge_arca_invoice_' . $order->get_id() );
        echo '<label>Concepto<select name="concept" required><option value="">Seleccionar</option><option value="1">Productos</option><option value="2">Servicios</option><option value="3">Productos y servicios</option></select></label>';
        echo '<label>Condición de venta<select name="sale_terms" required><option value="">Seleccionar</option><option>Contado</option><option>Cuenta corriente</option></select></label>';
        echo '<label><input type="checkbox" name="refresh_fiscal" value="1"> Revisar los datos fiscales actuales del mismo emisor y consultar el CUIT del receptor. Se conservará el historial comercial del pedido.</label>';
        $today = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Argentina/Buenos_Aires' ) ) )->format( 'Y-m-d' );
        foreach ( array( 'issue_date' => 'Fecha de emisión', 'service_from' => 'Servicio desde (si corresponde)', 'service_to' => 'Servicio hasta (si corresponde)', 'payment_due' => 'Vencimiento del pago (servicios)' ) as $key => $label ) { echo '<label>' . esc_html( $label ) . '<input type="date" name="' . esc_attr( $key ) . '" value="' . ( 'issue_date' === $key ? esc_attr( $today ) : '' ) . '"></label>'; }
        echo '<button type="submit">Revisar nueva factura</button></form></section>';
    }
    public static function recover( $order, $environment, $actor ) {
        if ( ! self::can_emit( $order, $actor ) ) { return new WP_Error( 'ge_arca_access', 'Acceso denegado.' ); }
        if ( ! in_array( $environment, array( 'production', 'homologation' ), true ) || ! get_option( 'ge_arca_schema_version' ) ) { return new WP_Error( 'ge_arca_missing', 'Registro fiscal no disponible.' ); }
        $row = self::journal( $order->get_id(), $environment );
        if ( ! $row ) { return new WP_Error( 'ge_arca_missing', 'No existe una solicitud para consultar.' ); }
        if ( 'authorized' === $row['state'] ) { return self::publish( $row ); }
        // Recover the immutable request even after its date/profile has changed.
        $config = GE_WTP_ARCA_Client::configuration( $row['payload']['issuer'] );
        if ( is_wp_error( $config ) ) { return $config; }
        return self::execute( $row['payload'], new GE_WTP_ARCA_Client( $config ), $actor, true );
    }
    public static function handle_recover() {
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! self::can_emit( $order, get_current_user_id() ) ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'ge_arca_recover_' . $order->get_id() );
        $result = self::recover( $order, sanitize_key( $_POST['environment'] ?? '' ), get_current_user_id() );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 422, 'back_link' => true ) ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id(), 'issued_status' => 'saved' ) ) ); exit;
    }
    public static function review() {
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! self::can_emit( $order, get_current_user_id() ) ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'ge_arca_invoice_' . $order->get_id() );
        $input = array_intersect_key( wp_unslash( $_POST ), array_flip( array( 'concept', 'issue_date', 'service_from', 'service_to', 'payment_due', 'sale_terms', 'refresh_fiscal' ) ) );
        $payload = self::prepare( $order, $input, get_current_user_id() );
        if ( is_wp_error( $payload ) ) { wp_die( esc_html( $payload->get_error_message() ), 'Revisión fiscal pendiente', array( 'response' => 422, 'back_link' => true ) ); }
        $html = '<h1>Revisar emisión ARCA</h1><p>Ambiente: <strong>' . esc_html( $payload['environment'] ) . '</strong></p><p>Emisor: ' . esc_html( GE_WTP_Billing_Issuers::label( $payload['issuer'] ) ) . '</p><p>Receptor: ' . esc_html( $payload['receiver']['legal_name'] . ' · CUIT ' . $payload['receiver']['cuit'] ) . '</p><p>Factura ' . esc_html( $payload['class'] . ' · Punto de venta ' . $payload['header']['PtoVta'] . ' · Fecha ' . $input['issue_date'] ) . '</p><p>Concepto: ' . esc_html( array( 1 => 'Productos', 2 => 'Servicios', 3 => 'Productos y servicios' )[$payload['detail']['Concepto']] ) . '</p>';
        $html .= '<p>Domicilio del emisor: ' . esc_html( $payload['issuer']['fiscal_address'] ) . ' · IIBB: ' . esc_html( $payload['issuer']['iibb'] ) . ' · Inicio de actividades: ' . esc_html( $payload['activity_start'] ) . '</p><p>Domicilio del receptor: ' . esc_html( $payload['receiver']['fiscal_address'] ) . ' · Condición IVA: ' . esc_html( $payload['receiver']['vat_status'] ) . '</p>';
        if ( $payload['fiscal_refresh_reviewed'] ) { $html .= '<p>Se revisan datos fiscales actuales para este comprobante. Los registros originales del pedido se conservan.</p>'; }
        foreach ( $payload['items'] as $item ) { $html .= '<p>' . nl2br( esc_html( $item['quantity'] . ' × ' . ( $item['description'] ?? $item['name'] ) ) ) . '</p>'; }
        $html .= '<p>Neto: $' . esc_html( $payload['detail']['ImpNeto'] ) . ' · IVA: $' . esc_html( $payload['detail']['ImpIVA'] ) . ' · Total: <strong>$' . esc_html( $payload['detail']['ImpTotal'] ) . '</strong></p><p>El número se obtiene de ARCA al emitir. Una emisión previa se recupera sin crear otra.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_arca_invoice_emit"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="review_hash" value="' . esc_attr( self::fingerprint( $payload ) ) . '">';
        foreach ( $input as $key => $value ) { $html .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
        $html .= wp_nonce_field( 'ge_arca_invoice_' . $order->get_id(), '_wpnonce', true, false ) . '<label><input type="checkbox" name="authorized" value="1" required> Revisé los datos y autorizo este comprobante fiscal. Confirmo que es una operación local ordinaria y no requiere FCE ni régimen especial.</label><p><button type="submit">' . ( 'production' === $payload['environment'] ? 'Autorizar emisión fiscal real / recuperar existente' : 'Autorizar prueba en homologación' ) . '</button></p></form>';
        wp_die( $html, 'Revisión de factura ARCA', array( 'response' => 200, 'back_link' => true ) );
    }
    public static function handle_emit() {
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! self::can_emit( $order, get_current_user_id() ) ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'ge_arca_invoice_' . $order->get_id() );
        $payload = self::prepare( $order, wp_unslash( $_POST ), get_current_user_id() );
        if ( is_wp_error( $payload ) ) { wp_die( esc_html( $payload->get_error_message() ), '', array( 'response' => 422, 'back_link' => true ) ); }
        if ( '1' !== ( $_POST['authorized'] ?? '' ) || ! hash_equals( self::fingerprint( $payload ), (string) ( $_POST['review_hash'] ?? '' ) ) ) { wp_die( 'Los datos cambiaron o falta autorización. Volvé a revisar el comprobante.', '', array( 'response' => 409, 'back_link' => true ) ); }
        $config = GE_WTP_ARCA_Client::configuration( GE_WTP_Billing_Issuers::get( $payload['issuer']['id'] ) );
        $result = is_wp_error( $config ) ? $config : self::execute( $payload, new GE_WTP_ARCA_Client( $config ), get_current_user_id() );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 422, 'back_link' => true ) ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id(), 'issued_status' => 'saved' ) ) ); exit;
    }
}
