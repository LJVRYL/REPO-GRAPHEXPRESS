<?php
defined( 'ABSPATH' ) || exit;

/** Private document register. Uploading an original does not issue a fiscal invoice. */
final class GE_WTP_Customer_Invoices {
    const TYPE = 'ge_customer_invoice';
    const CASE_TYPE = 'ge_invoice_case';
    const META = '_ge_customer_invoice';
    const CASE_META = '_ge_invoice_case';
    const MAX_BYTES = 20 * MB_IN_BYTES;

    public static function init() {
        add_action( 'wp_enqueue_scripts', function () {
            if ( ! is_page( array( 'cliente-markcom', 'gestion' ) ) ) { return; }
            $css = GE_WTP_PLUGIN_DIR . 'assets/css/customer-invoices.css';
            $js = GE_WTP_PLUGIN_DIR . 'assets/js/customer-invoices.js';
            wp_enqueue_style( 'ge-customer-invoices', GE_WTP_PLUGIN_URL . 'assets/css/customer-invoices.css', array(), (string) filemtime( $css ) );
            wp_enqueue_script( 'ge-customer-invoices', GE_WTP_PLUGIN_URL . 'assets/js/customer-invoices.js', array(), (string) filemtime( $js ), true );
        } );
        add_action( 'init', function () {
            foreach ( array( self::TYPE, self::CASE_TYPE ) as $type ) {
                register_post_type( $type, array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'supports' => array( 'title' ) ) );
            }
        } );
        add_action( 'admin_post_ge_invoice_upload', array( __CLASS__, 'upload' ) );
        add_action( 'admin_post_ge_invoice_download', array( __CLASS__, 'download' ) );
        add_action( 'admin_post_ge_invoice_review', array( __CLASS__, 'review_action' ) );
        add_action( 'admin_post_ge_invoice_response', array( __CLASS__, 'response_action' ) );
        add_action( 'admin_post_ge_invoice_notice', array( __CLASS__, 'notice_action' ) );
        foreach ( array( 'upload', 'download', 'review', 'response', 'notice' ) as $action ) {
            add_action( 'admin_post_nopriv_ge_invoice_' . $action, function () { self::fail( new WP_Error( 'forbidden', 'Ingresá a tu cuenta para continuar.' ), 403 ); } );
        }
    }

    public static function organization() {
        return class_exists( 'GE_Organization' ) ? GE_Organization::PRIMARY : 'graph-express';
    }

    public static function staff( $actor, $write = false ) {
        if ( class_exists( 'GE_Organization_Runtime' ) ) {
            return GE_Organization_Runtime::allowed( 'finance', $write, $actor );
        }
        return user_can( $actor, 'manage_woocommerce' );
    }

    private static function customer_in_scope( $customer ) {
        $user = get_userdata( $customer );
        if ( ! $user || ! GE_WTP_Portal::is_customer_user( $user ) ) { return false; }
        $org = get_user_meta( $customer, '_ge_organization_id', true );
        return ! $org || (string) $org === (string) self::organization();
    }

    private static function record_in_scope( $id ) {
        return (string) get_post_meta( $id, '_ge_organization_id', true ) === (string) self::organization();
    }

    private static function order_in_scope( $order ) {
        if ( ! $order ) { return false; }
        $org = $order->get_meta( '_ge_organization_id', true );
        return ! $org || (string) $org === (string) self::organization();
    }

    private static function quote_in_scope( $id ) {
        // Historical quotes predate organization metadata, inside the physically bound DB.
        $org = get_post_meta( $id, '_ge_organization_id', true );
        return ! $org || (string) $org === (string) self::organization();
    }

    public static function types() {
        return array( 'factura' => 'Factura emitida', 'comprobante' => 'Comprobante de pago', 'recibo' => 'Recibo', 'nota_credito' => 'Nota de crédito', 'nota_debito' => 'Nota de débito', 'presupuesto_emitido' => 'Presupuesto / proforma', 'remito' => 'Remito' );
    }

    public static function statuses() {
        return array( 'recibido' => 'Recibido', 'en_revision' => 'En revisión', 'respondido' => 'Respondido', 'resuelto' => 'Resuelto' );
    }

    public static function url( $ref = '', $staff = false, $customer = 0 ) {
        if ( $staff ) { return GE_WTP_Staff_Portal::portal_url( 'customers', array( 'customer_id' => $customer, 'invoice' => $ref ) ) . '#ge-workspace-invoices'; }
        return GE_WTP_Portal::portal_url( 'facturas', $ref ? array( 'invoice' => $ref ) : array() );
    }

    public static function resolve( $ref, $actor, $preview_customer = 0 ) {
        $row = null;
        if ( preg_match( '/^invoice:(\d+)$/D', $ref, $m ) ) {
            $id = (int) $m[1];
            if ( get_post_type( $id ) !== self::TYPE || ! self::record_in_scope( $id ) ) { return new WP_Error( 'forbidden', 'Documento no disponible.' ); }
            $row = get_post_meta( $id, self::META, true );
            if ( ! is_array( $row ) ) { return new WP_Error( 'forbidden', 'Documento no disponible.' ); }
            $row['ref'] = $ref;
        } elseif ( preg_match( '/^order:(\d+):([a-zA-Z0-9-]+)$/D', $ref, $m ) ) {
            $order = wc_get_order( (int) $m[1] );
            if ( ! self::order_in_scope( $order ) || ! $order->get_customer_id() ) { return new WP_Error( 'forbidden', 'Documento no disponible.' ); }
            foreach ( GE_WTP_Documents::get_documents( $order->get_id() ) as $doc ) {
                if ( (string) ( $doc['id'] ?? '' ) !== $m[2] || ! isset( self::types()[ $doc['category'] ?? '' ] ) || ! GE_WTP_Documents::customer_visible( $doc ) ) { continue; }
                $row = array( 'ref' => $ref, 'customer_id' => (int) $order->get_customer_id(), 'profile_id' => $doc['billing_profile_snapshot']['id'] ?? '', 'receiver' => $doc['billing_profile_snapshot'] ?? array(), 'type' => $doc['category'], 'number' => $doc['document_number'] ?? '', 'date' => $doc['issue_date'] ?? '', 'issuer_name' => '', 'currency' => '', 'amount' => '', 'public_note' => $doc['public_note'] ?? '', 'internal_note' => '', 'file' => $doc, 'order_id' => $order->get_id(), 'quote_id' => 0, 'replaces' => '', 'created_at' => $doc['uploaded_at'] ?? '', 'legacy' => true, 'superseded' => ! empty( $doc['superseded_at'] ) );
                break;
            }
        }
        if ( ! $row || ! self::customer_in_scope( $row['customer_id'] ) ) { return new WP_Error( 'forbidden', 'Documento no disponible.' ); }
        $staff = self::staff( $actor );
        if ( ! $staff && ( (int) $actor !== (int) $row['customer_id'] || ! GE_WTP_Portal::is_customer_user( get_userdata( $actor ) ) ) ) { return new WP_Error( 'forbidden', 'Documento no disponible.' ); }
        if ( $preview_customer && ( ! $staff || (int) $preview_customer !== (int) $row['customer_id'] ) ) { return new WP_Error( 'forbidden', 'Documento no disponible.' ); }
        // The profile belongs to this account; snapshots remain valid when profiles are archived.
        if ( ! empty( $row['profile_id'] ) && ! GE_WTP_Customer_Branches::find( $row['customer_id'], $row['profile_id'], true ) ) { return new WP_Error( 'forbidden', 'Documento no disponible.' ); }
        return $row;
    }

    public static function rows( $customer, $actor ) {
        if ( ! self::customer_in_scope( $customer ) || ( ! self::staff( $actor ) && (int) $actor !== (int) $customer ) ) { return array(); }
        $refs = array();
        foreach ( get_posts( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'numberposts' => -1, 'meta_key' => '_ge_invoice_customer', 'meta_value' => $customer ) ) as $post ) { $refs[] = 'invoice:' . $post->ID; }
        foreach ( wc_get_orders( array( 'customer_id' => $customer, 'limit' => -1, 'return' => 'objects' ) ) as $order ) {
            foreach ( GE_WTP_Documents::get_documents( $order->get_id() ) as $doc ) {
                if ( ! empty( $doc['invoice_ref'] ) || ! isset( self::types()[ $doc['category'] ?? '' ] ) ) { continue; }
                $refs[] = 'order:' . $order->get_id() . ':' . ( $doc['id'] ?? '' );
            }
        }
        $rows = array();
        foreach ( $refs as $ref ) { $row = self::resolve( $ref, $actor ); if ( ! is_wp_error( $row ) ) { $rows[] = $row; } }
        usort( $rows, function ( $a, $b ) { return strcmp( $b['date'] ?: $b['created_at'], $a['date'] ?: $a['created_at'] ); } );
        return $rows;
    }

    public static function normalize( $input, $actor ) {
        $customer = absint( $input['customer_id'] ?? 0 );
        if ( ! self::staff( $actor, true ) || ! self::customer_in_scope( $customer ) ) { return new WP_Error( 'forbidden', 'Sin permiso para cargar documentos de este cliente.' ); }
        $profile_id = sanitize_text_field( $input['profile_id'] ?? '' );
        $profile = GE_WTP_Customer_Branches::find( $customer, $profile_id, true );
        if ( ! $profile || empty( $profile['legal_name'] ) ) { return new WP_Error( 'profile', 'Seleccioná el receptor exacto del documento.' ); }
        $type = sanitize_key( $input['type'] ?? '' );
        $date = (string) ( $input['date'] ?? '' );
        $parts = explode( '-', $date );
        $amount = trim( (string) ( $input['amount'] ?? '' ) );
        if ( ! isset( self::types()[ $type ] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) || ! checkdate( (int) ( $parts[1] ?? 0 ), (int) ( $parts[2] ?? 0 ), (int) ( $parts[0] ?? 0 ) ) || ! preg_match( '/^\d{1,12}(\.\d{1,2})?$/D', $amount ) ) { return new WP_Error( 'metadata', 'Revisá tipo, fecha e importe (usá punto decimal, sin separadores de miles).' ); }
        $currency = strtoupper( sanitize_text_field( $input['currency'] ?? '' ) );
        if ( ! in_array( $currency, array( 'ARS', 'USD', 'EUR' ), true ) ) { return new WP_Error( 'currency', 'Seleccioná la moneda del documento.' ); }
        $number = sanitize_text_field( $input['number'] ?? '' );
        $issuer = sanitize_text_field( $input['issuer_name'] ?? '' );
        if ( ! $number || strlen( $number ) > 80 || ! $issuer || strlen( $issuer ) > 220 || empty( $input['confirmed'] ) ) { return new WP_Error( 'confirmation', 'Indicá número y emisor, y confirmá que los datos coinciden con el original.' ); }
        $order_id = absint( $input['order_id'] ?? 0 );
        $quote_id = absint( $input['quote_id'] ?? 0 );
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! self::order_in_scope( $order ) || (int) $order->get_customer_id() !== $customer ) { return new WP_Error( 'order', 'El pedido no pertenece a este cliente.' ); }
            $order_profile = $order->get_meta( '_ge_billing_profile_id', true );
            if ( $order_profile && (string) $order_profile !== $profile_id ) { return new WP_Error( 'order_profile', 'El receptor del pedido es distinto. Revisá la vinculación antes de cargar el original.' ); }
        }
        if ( $quote_id && ( get_post_type( $quote_id ) !== GE_WTP_Commercial_Quotes::POST_TYPE || (int) get_post_meta( $quote_id, GE_WTP_Commercial_Quotes::CUSTOMER_META, true ) !== $customer || ! self::quote_in_scope( $quote_id ) ) ) { return new WP_Error( 'quote', 'El presupuesto no pertenece a este cliente.' ); }
        if ( $quote_id ) {
            $versions = get_post_meta( $quote_id, GE_WTP_Commercial_Quotes::VERSIONS_META, true );
            $version = (int) get_post_meta( $quote_id, GE_WTP_Commercial_Quotes::CURRENT_META, true );
            $quote_profile = $versions[ $version ]['billing_profile_id'] ?? '';
            if ( $quote_profile && (string) $quote_profile !== $profile_id ) { return new WP_Error( 'quote_profile', 'El receptor del presupuesto es distinto. Revisá la vinculación antes de cargar el original.' ); }
        }
        $replaces = sanitize_text_field( $input['replaces'] ?? '' );
        if ( $replaces ) { $old = self::resolve( $replaces, $actor ); if ( is_wp_error( $old ) || (int) $old['customer_id'] !== $customer || $old['profile_id'] !== $profile_id || $old['type'] !== $type || $old['number'] !== $number ) { return new WP_Error( 'version', 'La versión anterior debe corresponder al mismo documento y receptor. Una nota de crédito se carga como otro comprobante.' ); } }
        return array( 'customer_id' => $customer, 'profile_id' => $profile_id, 'receiver' => array_intersect_key( $profile, array_flip( array( 'id', 'label', 'legal_name', 'cuit', 'fiscal_address' ) ) ), 'type' => $type, 'number' => $number, 'date' => $date, 'issuer_name' => $issuer, 'currency' => $currency, 'amount' => number_format( (float) $amount, 2, '.', '' ), 'order_id' => $order_id, 'quote_id' => $quote_id, 'public_note' => mb_substr( sanitize_textarea_field( $input['public_note'] ?? '' ), 0, 4000 ), 'internal_note' => mb_substr( sanitize_textarea_field( $input['internal_note'] ?? '' ), 0, 4000 ), 'replaces' => $replaces, 'created_at' => gmdate( 'c' ), 'created_by' => $actor );
    }

    public static function validate_file( $file, $uploaded = true ) {
        $path = $file['tmp_name'] ?? '';
        if ( (int) ( $file['error'] ?? -1 ) !== UPLOAD_ERR_OK || ! is_file( $path ) || ( $uploaded && ! is_uploaded_file( $path ) ) || filesize( $path ) < 8 || filesize( $path ) > self::MAX_BYTES ) { return new WP_Error( 'file', 'Elegí un PDF, JPG o PNG de hasta 20 MB.' ); }
        $name = sanitize_file_name( $file['name'] ?? '' );
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        $allowed = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png' );
        $mime = ( new finfo( FILEINFO_MIME_TYPE ) )->file( $path );
        if ( ! isset( $allowed[ $ext ] ) || $allowed[ $ext ] !== $mime ) { return new WP_Error( 'mime', 'El contenido del archivo no coincide con un PDF, JPG o PNG válido.' ); }
        if ( 'pdf' === $ext && '%PDF-' !== file_get_contents( $path, false, null, 0, 5 ) ) { return new WP_Error( 'mime', 'PDF inválido.' ); }
        if ( 'pdf' !== $ext && ! @getimagesize( $path ) ) { return new WP_Error( 'mime', 'Imagen inválida.' ); }
        return array( 'name' => $name, 'mime' => $mime, 'size' => filesize( $path ), 'sha256' => hash_file( 'sha256', $path ) );
    }

    private static function lock( $key ) { return add_option( $key, gmdate( 'c' ), '', false ); }

    public static function duplicate( $row, $sha, $actor ) {
        $ancestors = array(); $ref = $row['replaces'];
        while ( $ref && ! isset( $ancestors[ $ref ] ) && count( $ancestors ) < 100 ) {
            $ancestors[ $ref ] = true; $prior = self::resolve( $ref, $actor );
            $ref = is_wp_error( $prior ) ? '' : ( $prior['replaces'] ?? '' );
        }
        foreach ( self::rows( $row['customer_id'], $actor ) as $old ) {
            if ( ! empty( $old['file']['sha256'] ) && hash_equals( $old['file']['sha256'], $sha ) ) { return $old['ref']; }
            if ( $old['profile_id'] === $row['profile_id'] && $old['type'] === $row['type'] && $old['number'] === $row['number'] && ( $old['issuer_name'] ?? '' ) === $row['issuer_name'] && ! isset( $ancestors[ $old['ref'] ] ) ) { return $old['ref']; }
            if ( ! empty( $old['legacy'] ) ) { $path = self::file_path( $old ); if ( $path && hash_equals( hash_file( 'sha256', $path ), $sha ) ) { return $old['ref']; } }
        }
        return '';
    }

    public static function upload() {
        $actor = get_current_user_id(); $customer = absint( $_POST['customer_id'] ?? 0 );
        check_admin_referer( 'ge_invoice_upload_' . $customer );
        $row = self::normalize( wp_unslash( $_POST ), $actor );
        if ( is_wp_error( $row ) ) { self::fail( $row ); }
        $file = $_FILES['invoice_file'] ?? array(); $descriptor = self::validate_file( $file );
        if ( is_wp_error( $descriptor ) ) { self::fail( $descriptor ); }
        $lock = 'ge_invoice_upload_lock_' . self::organization() . '_' . $customer;
        if ( ! self::lock( $lock ) ) { self::fail( new WP_Error( 'busy', 'Hay una carga en curso. Revisá el listado antes de reintentar.' ) ); }
        try {
            $duplicate = self::duplicate( $row, $descriptor['sha256'], $actor );
            if ( $duplicate ) { $ref = $duplicate; $status = 'duplicate'; }
            else {
                $id = wp_insert_post( array( 'post_type' => self::TYPE, 'post_status' => 'private', 'post_title' => $row['type'] . ' ' . $row['number'], 'post_author' => $actor ), true );
                if ( is_wp_error( $id ) ) { throw new RuntimeException( 'No se pudo registrar el documento.' ); }
                update_post_meta( $id, '_ge_organization_id', self::organization() );
                if ( class_exists( 'GE_WTP_VPS_Storage' ) && GE_WTP_VPS_Storage::configured() ) {
                    $stored = GE_WTP_VPS_Storage::store_order_upload( $file, $id );
                    if ( is_wp_error( $stored ) ) { throw new RuntimeException( $stored->get_error_message() ); }
                } else {
                    if ( ! GE_WTP_Documents::ensure_private_directory() ) { throw new RuntimeException( 'Almacenamiento privado no disponible.' ); }
                    $name = wp_generate_uuid4() . '.' . strtolower( pathinfo( $descriptor['name'], PATHINFO_EXTENSION ) );
                    $destination = trailingslashit( GE_WTP_Documents::private_directory() ) . $name;
                    if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) { throw new RuntimeException( 'No se pudo guardar el original.' ); }
                    @chmod( $destination, 0640 ); $stored = array( 'stored_name' => $name );
                }
                $row['file'] = array_merge( $stored, $descriptor, array( 'id' => wp_generate_uuid4(), 'category' => $row['type'] ) );
                update_post_meta( $id, self::META, $row ); update_post_meta( $id, '_ge_invoice_customer', $customer );
                $ref = 'invoice:' . $id; $status = 'saved';
                add_post_meta( $id, '_ge_invoice_audit', array( 'at' => gmdate( 'c' ), 'actor' => $actor, 'action' => 'original_uploaded', 'sha256' => $descriptor['sha256'] ) );
            }
        } catch ( Throwable $error ) { $problem = new WP_Error( 'storage', $error->getMessage() ); }
        finally { delete_option( $lock ); }
        if ( isset( $problem ) ) { self::fail( $problem ); }
        wp_safe_redirect( add_query_arg( 'invoice_status', $status, self::url( $ref, true, $customer ) ) ); exit;
    }

    public static function file_path( $row ) {
        $file = $row['file'];
        if ( 'vps' === ( $file['provider'] ?? '' ) ) { return GE_WTP_VPS_Storage::download_path( $file['relative_path'] ?? '' ); }
        if ( ! empty( $file['stored_name'] ) ) {
            $base = realpath( GE_WTP_Documents::private_directory() ); $path = realpath( trailingslashit( $base ) . wp_basename( $file['stored_name'] ) );
            return $base && $path && strpos( $path, $base . DIRECTORY_SEPARATOR ) === 0 && is_file( $path ) ? $path : '';
        }
        return '';
    }

    public static function download_url( $ref ) {
        return wp_nonce_url( add_query_arg( array( 'action' => 'ge_invoice_download', 'invoice' => $ref ), admin_url( 'admin-post.php' ) ), 'ge_invoice_download_' . $ref );
    }

    public static function download() {
        $ref = sanitize_text_field( wp_unslash( $_GET['invoice'] ?? '' ) );
        check_admin_referer( 'ge_invoice_download_' . $ref );
        $row = self::resolve( $ref, get_current_user_id() ); if ( is_wp_error( $row ) ) { self::fail( $row, 403 ); }
        $path = self::file_path( $row );
        if ( ! $path && 'r2' === ( $row['file']['provider'] ?? '' ) && ! empty( $row['legacy'] ) ) {
            $url = GE_WTP_R2_Storage::download_url( $row['file']['object_key'], $row['file']['name'], $row['file']['mime'], 60 );
            if ( ! is_wp_error( $url ) ) { wp_redirect( $url ); exit; }
        }
        if ( ! $path ) { self::fail( new WP_Error( 'missing', 'El archivo no está disponible. Pedí una revisión.' ), 404 ); }
        if ( ! empty( $row['file']['sha256'] ) && ! hash_equals( $row['file']['sha256'], hash_file( 'sha256', $path ) ) ) { self::fail( new WP_Error( 'integrity', 'El original requiere revisión del equipo.' ), 409 ); }
        nocache_headers(); header( 'Cache-Control: private, no-store' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Type: ' . ( $row['file']['mime'] ?? 'application/octet-stream' ) );
        header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', sanitize_file_name( $row['file']['name'] ) ) . '"' ); header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path ); exit;
    }

    public static function case_for( $ref ) {
        $id = (int) get_option( 'ge_invoice_case_' . hash( 'sha256', self::organization() . ':' . $ref ) );
        if ( ! $id || get_post_type( $id ) !== self::CASE_TYPE || ! self::record_in_scope( $id ) ) { return null; }
        $case = get_post_meta( $id, self::CASE_META, true );
        return is_array( $case ) && ( $case['ref'] ?? '' ) === $ref ? array_merge( $case, array( 'id' => $id ) ) : null;
    }

    public static function comment( $ref, $message, $token, $actor, $staff = false, $status = 'recibido' ) {
        $row = self::resolve( $ref, $actor ); if ( is_wp_error( $row ) ) { return $row; }
        if ( $staff ? ! self::staff( $actor, true ) : ( (int) $actor !== (int) $row['customer_id'] || GE_WTP_Portal::is_staff_preview() ) ) { return new WP_Error( 'forbidden', 'Sin permiso para esta acción.' ); }
        $message = trim( sanitize_textarea_field( $message ) );
        if ( strlen( $message ) < 5 || mb_strlen( $message ) > 4000 || ! preg_match( '/^[a-f0-9-]{36}$/D', $token ) || ! isset( self::statuses()[ $status ] ) ) { return new WP_Error( 'comment', 'Contanos qué hay que revisar (entre 5 y 4000 caracteres).' ); }
        $key = 'ge_invoice_case_' . hash( 'sha256', self::organization() . ':' . $ref ); $lock = $key . '_lock';
        if ( ! self::lock( $lock ) ) { return new WP_Error( 'busy', 'Estamos guardando una actualización. Revisá el historial antes de reintentar.' ); }
        try {
            $case = self::case_for( $ref );
            if ( $staff && ! $case ) { return new WP_Error( 'case', 'No hay una revisión abierta.' ); }
            if ( ! $case ) {
                $id = wp_insert_post( array( 'post_type' => self::CASE_TYPE, 'post_status' => 'private', 'post_title' => 'Revisión de comprobante', 'post_author' => $actor ), true );
                if ( is_wp_error( $id ) ) { return $id; }
                update_post_meta( $id, '_ge_organization_id', self::organization() ); update_option( $key, $id, false );
                $case = array( 'id' => $id, 'ref' => $ref, 'customer_id' => $row['customer_id'], 'profile_id' => $row['profile_id'], 'status' => 'recibido', 'events' => array(), 'created_at' => gmdate( 'c' ) );
            }
            foreach ( $case['events'] as $event ) { if ( $event['token'] === $token ) { return $case; } }
            $status = $staff ? $status : ( in_array( $case['status'], array( 'recibido', 'en_revision' ), true ) ? $case['status'] : 'recibido' );
            $event = array( 'token' => $token, 'actor' => $actor, 'staff' => $staff, 'message' => $message, 'status' => $status, 'at' => gmdate( 'c' ) );
            $case['events'][] = $event; $case['status'] = $status; $case['updated_at'] = $event['at'];
            update_post_meta( $case['id'], self::CASE_META, $case );
            // Persist before mail. A retry never creates a second message or transport attempt.
            $notice = self::notify( $row, $case['id'], $token, $staff );
            $case['events'][ count( $case['events'] ) - 1 ]['notice'] = $notice;
            update_post_meta( $case['id'], self::CASE_META, $case );
            return $case;
        } finally { delete_option( $lock ); }
    }

    private static function notify( $row, $entity, $event, $response = false ) {
        $key = 'ge_invoice_mail_' . hash( 'sha256', self::organization() . ':' . $entity . ':' . $event );
        if ( ! add_option( $key, 'attempting', '', false ) ) { return get_option( $key ); }
        $customer = get_userdata( $row['customer_id'] ); $result = array();
        $subject = $response ? 'Hay una respuesta sobre tu comprobante' : 'Recibimos tu consulta sobre un comprobante';
        $html = '<p>' . esc_html( $subject ) . '.</p><p><a href="' . esc_url( self::url( $row['ref'] ) ) . '">Ver comprobante y conversación</a></p><p>Pedir una revisión no anula el comprobante ni cambia el estado de pago.</p>';
        $result['customer'] = GE_WTP_Notifications::send( $customer->user_email, $subject . ' · Graph Express', $html, $response ? 'invoice_review_response' : 'invoice_review_received', $entity ) ? 'accepted' : 'failed';
        if ( ! $response ) {
            $alert = GE_WTP_Internal_Alerts::create( 'invoice_review_' . $event, 'Revisión de comprobante · ' . $customer->display_name, $entity, $row['customer_id'] );
            $result['alert_id'] = is_wp_error( $alert ) ? 0 : $alert;
            $ok = true; $recipients = GE_WTP_Notification_Center::recipients();
            foreach ( $recipients as $email ) { $ok = GE_WTP_Notifications::send( $email, 'Solicitud de revisión de comprobante · Graph Express', '<p>Hay una consulta para revisar.</p><p><a href="' . esc_url( self::url( $row['ref'], true, $row['customer_id'] ) ) . '">Abrir revisión en Gestión</a></p>', 'invoice_review_staff', $entity ) && $ok; }
            $result['staff'] = $recipients && $ok ? 'accepted' : 'failed';
        }
        $result['at'] = gmdate( 'c' ); update_option( $key, $result, false ); return $result;
    }

    public static function review_action() { self::message_action( false ); }
    public static function response_action() { self::message_action( true ); }
    private static function message_action( $staff ) {
        $ref = sanitize_text_field( wp_unslash( $_POST['invoice'] ?? '' ) ); check_admin_referer( 'ge_invoice_message_' . $ref );
        $result = self::comment( $ref, wp_unslash( $_POST['message'] ?? '' ), sanitize_text_field( $_POST['operation_id'] ?? '' ), get_current_user_id(), $staff, sanitize_key( $_POST['status'] ?? 'recibido' ) );
        if ( is_wp_error( $result ) ) { self::fail( $result, 'forbidden' === $result->get_error_code() ? 403 : 400 ); }
        wp_safe_redirect( add_query_arg( 'invoice_status', 'review_saved', self::url( $ref, $staff, $result['customer_id'] ) ) ); exit;
    }

    public static function notice_action() {
        $ref = sanitize_text_field( wp_unslash( $_POST['invoice'] ?? '' ) ); check_admin_referer( 'ge_invoice_notice_' . $ref );
        $row = self::resolve( $ref, get_current_user_id() );
        if ( is_wp_error( $row ) || ! self::staff( get_current_user_id(), true ) ) { self::fail( new WP_Error( 'forbidden', 'Acceso denegado.' ), 403 ); }
        $key = 'ge_invoice_available_' . hash( 'sha256', self::organization() . ':' . $ref );
        if ( add_option( $key, 'attempting', '', false ) ) {
            $user = get_userdata( $row['customer_id'] );
            $ok = GE_WTP_Notifications::send( $user->user_email, 'Comprobante disponible · Graph Express', '<p>Tenés un comprobante disponible en tu portal.</p><p><a href="' . esc_url( self::url( $ref ) ) . '">Ver en Mis facturas</a></p>', 'invoice_available', $user->ID );
            update_option( $key, $ok ? 'accepted' : 'failed', false );
        }
        wp_safe_redirect( add_query_arg( 'invoice_status', get_option( $key ) === 'accepted' ? 'notice_accepted' : 'notice_failed', self::url( $ref, true, $row['customer_id'] ) ) ); exit;
    }

    private static function fail( $error, $status = 400 ) { wp_die( esc_html( $error->get_error_message() ), 'Comprobantes', array( 'response' => $status, 'back_link' => true ) ); }

    public static function card() {
        $rows = self::rows( GE_WTP_Portal::portal_customer_id(), get_current_user_id() );
        echo '<article><a href="' . esc_url( self::url() ) . '"><span>Mis facturas</span><strong>' . count( $rows ) . '</strong><small>Facturas y comprobantes · ver y descargar</small></a></article>';
    }

    private static function title( $row ) { return ( self::types()[ $row['type'] ] ?? 'Comprobante' ) . ( $row['number'] ? ' ' . $row['number'] : '' ); }

    private static function notice() {
        $labels = array( 'saved' => 'Original guardado. Ya está disponible para el cliente.', 'duplicate' => 'Este documento ya está registrado. No se creó una copia.', 'review_saved' => 'Tu comentario quedó registrado en el historial.', 'notice_accepted' => 'Aviso registrado y aceptado por el servicio de correo.', 'notice_failed' => 'El aviso no se confirmó. Revisá Notificaciones antes de reintentar.' );
        $status = sanitize_key( $_GET['invoice_status'] ?? '' );
        if ( isset( $labels[ $status ] ) ) { echo '<p class="ge-invoice-notice" role="status">' . esc_html( $labels[ $status ] ) . '</p>'; }
    }

    public static function render_portal() {
        $customer = GE_WTP_Portal::portal_customer_id(); $actor = get_current_user_id();
        echo '<section class="ge-page-heading"><div><span class="ge-eyebrow">Tus comprobantes</span><h1>Mis facturas</h1><p>Facturas emitidas, recibos y otros comprobantes. Descargá el original o pedinos que lo revisemos.</p></div></section>';
        self::notice();
        $ref = sanitize_text_field( wp_unslash( $_GET['invoice'] ?? '' ) );
        if ( $ref ) { $row = self::resolve( $ref, $actor, GE_WTP_Portal::is_staff_preview() ? $customer : 0 ); if ( is_wp_error( $row ) ) { echo '<section class="ge-panel"><p>Documento no disponible.</p></section>'; return; } self::detail( $row ); return; }
        self::listing( $customer, $actor );
    }

    private static function listing( $customer, $actor, $staff = false ) {
        $rows = self::rows( $customer, $actor );
        if ( ! $staff ) {
            echo '<form class="ge-invoice-filters" method="get" action="' . esc_url( GE_WTP_Portal::portal_url() ) . '"><input type="hidden" name="seccion" value="facturas">';
            if ( GE_WTP_Portal::is_staff_preview() ) { echo '<input type="hidden" name="ge_preview_customer" value="' . (int) $customer . '"><input type="hidden" name="ge_preview_token" value="' . esc_attr( wp_create_nonce( 'ge_preview_customer_' . $customer ) ) . '">'; }
            echo '<label>Tipo<select name="invoice_type"><option value="">Todos los comprobantes</option>'; foreach ( self::types() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $_GET['invoice_type'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label>';
            echo '<label>Desde<input type="date" name="invoice_from" value="' . esc_attr( $_GET['invoice_from'] ?? '' ) . '"></label><label>Hasta<input type="date" name="invoice_to" value="' . esc_attr( $_GET['invoice_to'] ?? '' ) . '"></label><label>Revisión<select name="invoice_review"><option value="">Todos los estados</option><option value="none">Sin revisión</option>'; foreach ( self::statuses() as $key => $label ) { echo '<option value="' . $key . '"' . selected( $_GET['invoice_review'] ?? '', $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label><button class="ge-button" type="submit">Filtrar</button></form>';
        }
        $shown = 0; echo '<div class="ge-invoice-list">';
        foreach ( $rows as $row ) {
            $case = self::case_for( $row['ref'] ); $state = $case['status'] ?? 'none';
            if ( ! $staff && ( ( ! empty( $_GET['invoice_type'] ) && $_GET['invoice_type'] !== $row['type'] ) || ( ! empty( $_GET['invoice_review'] ) && $_GET['invoice_review'] !== $state ) || ( ! empty( $_GET['invoice_from'] ) && ( ! $row['date'] || $row['date'] < $_GET['invoice_from'] ) ) || ( ! empty( $_GET['invoice_to'] ) && ( ! $row['date'] || $row['date'] > $_GET['invoice_to'] ) ) ) ) { continue; }
            $shown++; echo '<article class="ge-panel ge-invoice-row"><div><h3><a href="' . esc_url( self::url( $row['ref'], $staff, $customer ) ) . '">' . esc_html( self::title( $row ) ) . '</a></h3><p>' . esc_html( $row['receiver']['legal_name'] ?? '' ) . '</p><small>' . esc_html( $row['date'] ?: 'Fecha no informada' ) . '</small>' . ( $case ? '<p class="ge-invoice-status">Revisión: ' . esc_html( self::statuses()[ $state ] ) . '</p>' : '' ) . '</div><div><strong>' . esc_html( $row['amount'] ? $row['currency'] . ' ' . number_format_i18n( (float) $row['amount'], 2 ) : 'Importe en el documento' ) . '</strong><a class="ge-button" href="' . esc_url( self::url( $row['ref'], $staff, $customer ) ) . '">Ver comprobante</a></div></article>';
        }
        if ( ! $shown ) { echo '<section class="ge-panel"><h3>' . ( $rows ? 'No hay coincidencias' : 'Todavía no hay comprobantes' ) . '</h3><p>' . ( $rows ? 'Probá con otro tipo, fecha o estado de revisión.' : 'Cuando carguemos una factura o comprobante, lo vas a encontrar acá.' ) . '</p></section>'; }
        echo '</div>';
    }

    private static function detail( $row, $staff = false ) {
        echo '<section class="ge-panel ge-invoice-detail"><a href="' . esc_url( self::url( '', $staff, $row['customer_id'] ) ) . '">← Volver a comprobantes</a><h2>' . esc_html( self::title( $row ) ) . '</h2><dl>';
        $fields = array( 'Receptor' => $row['receiver']['legal_name'] ?? '', 'CUIT del receptor' => $row['receiver']['cuit'] ?? '', 'Fecha del comprobante' => $row['date'], 'Importe del comprobante' => $row['amount'] ? $row['currency'] . ' ' . number_format_i18n( (float) $row['amount'], 2 ) : '', 'Emisor del documento' => $row['issuer_name'], 'Pedido vinculado' => $row['order_id'] ? '#' . $row['order_id'] : '', 'Presupuesto vinculado' => $row['quote_id'] ? '#' . $row['quote_id'] : '' );
        if ( ! $staff ) { unset( $fields['Emisor del documento'] ); }
        foreach ( $fields as $label => $value ) { if ( $value !== '' ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>'; } } echo '</dl><p>El importe del comprobante no indica un saldo pendiente. Una consulta no anula este documento ni cambia un pago.</p>';
        if ( $row['public_note'] ) { echo '<aside class="ge-invoice-note"><h3>Aclaración sobre este comprobante</h3><p>' . nl2br( esc_html( $row['public_note'] ) ) . '</p></aside>'; }
        echo '<p><a class="ge-button ge-button-primary" href="' . esc_url( self::download_url( $row['ref'] ) ) . '">Descargar original</a></p>';
        if ( $row['replaces'] ) { echo '<p>Este archivo es una nueva versión del documento cargado. <a href="' . esc_url( self::url( $row['replaces'], $staff, $row['customer_id'] ) ) . '">Ver archivo anterior</a></p>'; }
        if ( ! empty( $row['superseded'] ) ) { echo '<p>Archivo histórico: existe una versión posterior.</p>'; }
        if ( $staff ) {
            echo '<details><summary>Registro interno</summary><p>' . nl2br( esc_html( $row['internal_note'] ) ) . '</p><p>SHA-256: <code>' . esc_html( $row['file']['sha256'] ?? 'Original histórico sin checksum registrado' ) . '</code></p></details>';
            if ( self::staff( get_current_user_id(), true ) ) { self::form_open( 'ge_invoice_notice', 'ge_invoice_notice_' . $row['ref'], $row['ref'] ); echo '<p>El aviso se registra una sola vez para este archivo. Revisá el resultado en Notificaciones.</p><button class="ge-button" type="submit">Avisar al cliente que está disponible</button></form>'; }
        }
        echo '</section>'; self::conversation( $row, $staff );
    }

    private static function form_open( $action, $nonce, $ref ) {
        echo '<form class="ge-invoice-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="invoice" value="' . esc_attr( $ref ) . '"><input type="hidden" name="operation_id" value="' . esc_attr( wp_generate_uuid4() ) . '">'; wp_nonce_field( $nonce );
    }

    private static function conversation( $row, $staff ) {
        $case = self::case_for( $row['ref'] );
        echo '<section class="ge-panel ge-invoice-conversation"><h2>Revisión del comprobante</h2>';
        if ( $case ) {
            echo '<p class="ge-invoice-status">' . esc_html( self::statuses()[ $case['status'] ] ) . ' · consulta #' . (int) $case['id'] . '</p><ol class="ge-invoice-history">';
            foreach ( $case['events'] as $event ) { echo '<li><strong>' . ( $event['staff'] ? 'Graph Express' : 'Cliente' ) . '</strong> <small>' . esc_html( wp_date( 'd/m/Y H:i', strtotime( $event['at'] ) ) ) . ' · ' . esc_html( self::statuses()[ $event['status'] ] ) . '</small><p>' . nl2br( esc_html( $event['message'] ) ) . '</p>'; if ( $staff && isset( $event['notice'] ) ) { echo '<small>Aviso cliente: ' . esc_html( $event['notice']['customer'] ?? 'Sin confirmar' ) . ( isset( $event['notice']['staff'] ) ? ' · Aviso equipo: ' . esc_html( $event['notice']['staff'] ) : '' ) . '</small>'; } echo '</li>'; } echo '</ol>';
        } else { echo '<p>¿Esta factura tiene un problema? Indicá qué datos o importes querés que revisemos.</p>'; }
        if ( ! $staff && ! GE_WTP_Portal::is_staff_preview() ) {
            self::form_open( 'ge_invoice_review', 'ge_invoice_message_' . $row['ref'], $row['ref'] );
            echo '<label>Contanos qué hay que revisar<textarea name="message" minlength="5" maxlength="4000" rows="4" required></textarea></label><p>Podés señalar el número, receptor, importe u otro detalle. La consulta y nuestras respuestas quedan acá.</p><button class="ge-button ge-button-primary" type="submit">' . ( $case ? 'Agregar comentario y avisar' : 'Informar un problema' ) . '</button></form>';
        } elseif ( $staff && $case && self::staff( get_current_user_id(), true ) ) {
            self::form_open( 'ge_invoice_response', 'ge_invoice_message_' . $row['ref'], $row['ref'] ); echo '<label>Estado<select name="status">'; foreach ( self::statuses() as $key => $label ) { echo '<option value="' . $key . '"' . selected( $case['status'], $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label><label>Respuesta pública al cliente<textarea name="message" minlength="5" maxlength="4000" rows="4" required></textarea></label><button class="ge-button ge-button-primary" type="submit">Guardar respuesta y avisar</button></form>';
        }
        echo '</section>';
    }

    public static function render_staff( $customer ) {
        if ( ! self::staff( get_current_user_id() ) || ! self::customer_in_scope( $customer ) ) { return; }
        echo '<section class="ge-invoices"><h2>Facturas y comprobantes</h2><p>Cargá el archivo original ya emitido y seleccioná su receptor exacto. Esta carga no emite una factura fiscal.</p>'; self::notice();
        $ref = sanitize_text_field( wp_unslash( $_GET['invoice'] ?? '' ) );
        if ( $ref ) { $row = self::resolve( $ref, get_current_user_id(), $customer ); if ( ! is_wp_error( $row ) ) { self::detail( $row, true ); } else { echo '<p>Documento no disponible para este cliente.</p>'; } }
        self::listing( $customer, get_current_user_id(), true );
        if ( self::staff( get_current_user_id(), true ) ) {
            echo '<details class="ge-invoice-upload"><summary class="ge-button ge-button-primary">Cargar factura o comprobante</summary><form class="ge-invoice-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_invoice_upload"><input type="hidden" name="customer_id" value="' . (int) $customer . '">'; wp_nonce_field( 'ge_invoice_upload_' . $customer );
            echo '<label>Receptor del documento<select name="profile_id" required><option value="">Seleccionar receptor exacto</option>'; foreach ( GE_WTP_Customer_Branches::profiles( $customer, true ) as $profile ) { echo '<option value="' . esc_attr( $profile['id'] ) . '">' . esc_html( ( $profile['legal_name'] ?: $profile['label'] ) . ' · ' . $profile['cuit'] ) . '</option>'; } echo '</select></label><label>Tipo<select name="type">'; foreach ( self::types() as $key => $label ) { echo '<option value="' . $key . '">' . esc_html( $label ) . '</option>'; } echo '</select></label>';
            foreach ( array( 'number' => 'Número del comprobante', 'issuer_name' => 'Emisor tal como aparece en el original', 'date' => 'Fecha de emisión', 'amount' => 'Importe total (punto decimal, sin miles)' ) as $key => $label ) { echo '<label>' . esc_html( $label ) . '<input name="' . $key . '" type="' . ( 'date' === $key ? 'date' : 'text' ) . '" maxlength="220" required></label>'; }
            echo '<label>Pedido vinculado (opcional)<select name="order_id"><option value="">Sin pedido vinculado</option>';
            foreach ( wc_get_orders( array( 'customer_id' => $customer, 'limit' => -1 ) ) as $order ) { if ( self::order_in_scope( $order ) ) { echo '<option value="' . (int) $order->get_id() . '">' . esc_html( '#' . $order->get_order_number() . ' · ' . ( $order->get_date_created() ? wc_format_datetime( $order->get_date_created(), 'd/m/Y' ) : '' ) ) . '</option>'; } }
            echo '</select></label><label>Presupuesto vinculado (opcional)<select name="quote_id"><option value="">Sin presupuesto vinculado</option>';
            foreach ( get_posts( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'numberposts' => -1, 'meta_key' => GE_WTP_Commercial_Quotes::CUSTOMER_META, 'meta_value' => $customer ) ) as $post ) { if ( self::quote_in_scope( $post->ID ) ) { echo '<option value="' . (int) $post->ID . '">' . esc_html( $post->post_title ) . '</option>'; } }
            echo '</select></label>';
            echo '<label>Moneda<select name="currency"><option>ARS</option><option>USD</option><option>EUR</option></select></label><label>Versión anterior (opcional)<select name="replaces"><option value="">Documento nuevo</option>'; foreach ( self::rows( $customer, get_current_user_id() ) as $row ) { echo '<option value="' . esc_attr( $row['ref'] ) . '">' . esc_html( self::title( $row ) . ' · ' . ( $row['receiver']['legal_name'] ?? '' ) ) . '</option>'; } echo '</select></label><p>Versionar conserva el archivo anterior. No anula ni reemplaza fiscalmente un comprobante.</p><label>Aclaración visible para el cliente<textarea name="public_note" maxlength="4000" rows="3"></textarea></label><label>Nota interna (solo equipo)<textarea name="internal_note" maxlength="4000" rows="3"></textarea></label><label>Original exacto · PDF, JPG o PNG · hasta 20 MB<input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" required></label><label><input type="checkbox" name="confirmed" value="1" required> Revisé cliente, receptor, tipo, número, fecha, emisor, moneda e importe contra el original.</label><p>La carga no envía correo automáticamente. Podés avisar desde el detalle después de revisarlo.</p><button class="ge-button ge-button-primary" type="submit">Guardar original y publicar en el portal</button></form></details>';
        }
        echo '</section>';
    }
}
