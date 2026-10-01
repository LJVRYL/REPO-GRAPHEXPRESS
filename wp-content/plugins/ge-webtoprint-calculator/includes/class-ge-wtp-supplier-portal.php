<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Supplier dispatch service. Uses the existing workflow and supplier workspace. */
final class GE_WTP_Supplier_Portal {
    const META = '_ge_supplier_portal_dispatches';
    const AUDIT = '_ge_supplier_portal_audit';

    public static function init() {
        add_action( 'admin_post_ge_supplier_source', array( __CLASS__, 'handle_source' ) );
        add_action( 'admin_post_ge_supplier_prepare', array( __CLASS__, 'handle_prepare' ) );
        add_action( 'admin_post_ge_supplier_notify_v1', array( __CLASS__, 'handle_notify' ) );
        add_action( 'admin_post_ge_supplier_revoke', array( __CLASS__, 'handle_revoke' ) );
        add_action( 'admin_post_ge_supplier_status', array( __CLASS__, 'handle_status' ) );
        foreach ( array( 'ge_supplier_portal' => 'portal', 'ge_supplier_portal_file' => 'download' ) as $action => $method ) {
            add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
            add_action( 'admin_post_nopriv_' . $action, array( __CLASS__, $method ) );
        }
    }

    /** Shared adapter for authenticated Graph Actions; never returns storage paths. */
    public static function execute_action( $action, $input ) {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { return new WP_Error( 'forbidden', 'Actor interno no autorizado.' ); }
        $id = absint( $input['order_id'] ?? 0 ); $supplier = sanitize_key( $input['supplier_id'] ?? '' );
        switch ( $action ) {
            case 'graph.supplier.find':
                $q = remove_accents( strtolower( sanitize_text_field( $input['query'] ?? '' ) ) ); $found = array();
                foreach ( self::profiles() as $key => $profile ) { if ( ! $q || false !== strpos( remove_accents( strtolower( $profile['name'] . ' ' . $profile['category'] ) ), $q ) ) { $found[] = array( 'supplier_id' => $key, 'name' => $profile['name'], 'email' => $profile['email'], 'category' => $profile['category'] ); } }
                return array( 'suppliers' => $found );
            case 'graph.production.set_source':
            case 'graph.supplier.assign_order':
                $result = self::set_source( $id, 'graph.supplier.assign_order' === $action ? 'supplier' : sanitize_key( $input['source'] ?? '' ), $supplier );
                return is_wp_error( $result ) ? $result : array( 'order_id' => $id, 'saved' => true );
            case 'graph.supplier.preview_dispatch':
            case 'graph.supplier.create_portal_link':
                $row = self::prepare( $id, $supplier, sanitize_textarea_field( $input['notes'] ?? '' ) );
                if ( is_wp_error( $row ) ) { return $row; }
                return array( 'order_id' => $id, 'dispatch_id' => $row['id'], 'version' => $row['version'], 'state' => $row['state'], 'portal_url' => self::url( $id, $row ), 'message' => self::message( $row, self::url( $id, $row ) ), 'active' => ! empty( $row['sent_at'] ) );
            case 'graph.supplier.notify':
                $row = self::notify( $id, sanitize_text_field( $input['dispatch_id'] ?? '' ), ! empty( $input['manual'] ), ! empty( $input['retry'] ) );
                break;
            case 'graph.supplier.acknowledge_order':
            case 'graph.supplier.set_eta':
            case 'graph.supplier.set_status':
                $order = wc_get_order( $id ); if ( ! $order ) { return new WP_Error( 'order', 'Pedido inexistente.' ); }
                $existing = self::latest( $order, $supplier ); if ( ! $existing ) { return new WP_Error( 'dispatch', 'No hay envío activo para este proveedor.' ); }
                $next = 'graph.supplier.set_eta' === $action ? 'eta' : ( 'graph.supplier.acknowledge_order' === $action ? 'acknowledged' : sanitize_key( $input['status'] ?? '' ) );
                if ( in_array( $next, array( 'received', 'cancelled' ), true ) ) { $row = self::staff_status( $id, $existing['id'], $next ); break; }
                $row = self::supplier_action( $id, self::token( $existing ), $next, sanitize_text_field( $input['eta'] ?? '' ) );
                break;
            default: return new WP_Error( 'action', 'Acción no admitida.' );
        }
        if ( is_wp_error( $row ) ) { return $row; }
        return array( 'order_id' => $id, 'dispatch_id' => $row['id'], 'state' => $row['state'], 'email_status' => $row['email_status'], 'sent_at' => $row['sent_at'] ?? null, 'eta' => $row['eta'] ?? null );
    }
    private static function rows( $order ) {
        $rows = $order->get_meta( self::META, true );
        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
    }
    private static function lock( $id ) {
        global $wpdb;
        return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', 'ge_supplier_' . $wpdb->prefix . $id ) );
    }
    private static function unlock( $id ) {
        global $wpdb;
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'ge_supplier_' . $wpdb->prefix . $id ) );
    }
    public static function audit( $order, $action, $supplier, $data = array() ) {
        $rows = $order->get_meta( self::AUDIT, true ); $rows = is_array( $rows ) ? $rows : array();
        $rows[] = array( 'time' => time(), 'actor' => get_current_user_id(), 'action' => $action, 'supplier' => $supplier, 'data' => $data );
        $order->update_meta_data( self::AUDIT, array_slice( $rows, -250 ) );
    }
    public static function profiles() {
        $profiles = GE_WTP_Supplier_Dispatch::profiles();
        $ws = (array) get_option( GE_WTP_Supplier_Workspace::OPTION, array() );
        foreach ( $profiles as $key => &$profile ) {
            // Existing contacts remain selectable until explicitly marked inactive.
            if ( 'inactive' === ( $ws[ $key ]['status'] ?? '' ) ) { unset( $profiles[ $key ] ); continue; }
            $profile['category'] = $ws[ $key ]['category'] ?? '';
        }
        unset( $profile );
        return $profiles;
    }
    public static function set_source( $id, $source, $supplier = '' ) {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { return new WP_Error( 'forbidden', 'Acceso denegado.' ); }
        if ( ! in_array( $source, array( 'internal', 'supplier' ), true ) || ( 'supplier' === $source && ! isset( self::profiles()[ $supplier ] ) ) ) { return new WP_Error( 'supplier', 'Seleccioná un proveedor activo.' ); }
        if ( ! self::lock( $id ) ) { return new WP_Error( 'busy', 'Otra operación está en curso.' ); }
        try {
            $order = wc_get_order( $id );
            if ( ! GE_WTP_Workflow::enabled( $order ) ) { return new WP_Error( 'workflow', 'Usá el circuito de producción vigente.' ); }
            GE_WTP_Production::ensure_order( $order );
            $key = 'internal' === $source ? 'internal' : $supplier;
            $changed = false;
            foreach ( $order->get_items() as $item ) {
                if ( ! in_array( GE_WTP_Production::item_status( $item, $order ), array( 'pending', 'production' ), true ) ) { continue; }
                if ( $key !== $item->get_meta( '_ge_production_supplier', true ) ) { $changed = true; }
                $item->update_meta_data( '_ge_production_supplier', $key );
                $item->update_meta_data( '_ge_production_source', $source ); $item->save();
            }
            if ( $changed ) {
                $rows = self::rows( $order );
                foreach ( $rows as &$row ) { $row['revoked'] = true; $row['state'] = 'cancelled'; }
                unset( $row ); $order->update_meta_data( self::META, $rows );
            }
            $order->update_meta_data( '_ge_production_source', $source );
            $order->update_meta_data( '_ge_production_supplier', $key );
            self::audit( $order, 'graph.production.set_source', 'internal' === $source ? '' : $supplier, array( 'source' => $source ) ); $order->save();
            return true;
        } finally { self::unlock( $id ); }
    }
    private static function path( $doc ) {
        if ( 'vps' === ( $doc['provider'] ?? '' ) ) { return GE_WTP_VPS_Storage::download_path( $doc['relative_path'] ?? '' ); }
        if ( 'r2' === ( $doc['provider'] ?? '' ) ) { return false; }
        return trailingslashit( GE_WTP_Documents::private_directory() ) . wp_basename( $doc['stored_name'] ?? '' );
    }
    public static function snapshot( $order, $supplier, $notes ) {
        $docs = array(); foreach ( GE_WTP_Documents::get_documents( $order->get_id() ) as $doc ) { $docs[ (string) $doc['id'] ] = $doc; }
        $items = array(); $files = array();
        foreach ( $order->get_items() as $item ) {
            if ( $supplier !== $item->get_meta( '_ge_production_supplier', true ) || 'production' !== GE_WTP_Production::item_status( $item, $order ) ) { continue; }
            if ( ! GE_WTP_Artwork_Library::item_ready_for_production( $item, $order ) ) { return new WP_Error( 'artwork', 'Revisá la aprobación del archivo exacto.' ); }
            $sources = (array) $item->get_meta( '_ge_item_artwork_sources', true );
            if ( ! $sources ) { return new WP_Error( 'files', 'Faltan archivos finales privados.' ); }
            foreach ( $sources as $source ) {
                $doc_id = 0 === strpos( $source, 'document:' ) ? substr( $source, 9 ) : '';
                $doc = $docs[ $doc_id ] ?? array(); $path = $doc ? self::path( $doc ) : false;
                if ( ! $path || ! is_file( $path ) || 'arte' !== ( $doc['category'] ?? '' ) || ! in_array( absint( $doc['order_item_id'] ?? 0 ), array( 0, $item->get_id() ), true ) ) { return new WP_Error( 'files', 'El archivo final debe estar disponible en almacenamiento privado local/VPS.' ); }
                $hash = hash_file( 'sha256', $path );
                if ( ! empty( $doc['analysis']['sha256'] ) && ! hash_equals( $doc['analysis']['sha256'], $hash ) ) { return new WP_Error( 'checksum', 'El archivo cambió después de su revisión.' ); }
                $files[ $doc_id ] = array( 'version_id' => $doc_id, 'checksum' => $hash, 'record' => $doc );
            }
            $finish = array(); $catalog = GE_WTP_Workflow::finishing_catalog();
            foreach ( (array) $item->get_meta( GE_WTP_Workflow::FINISHES_META, true ) as $key ) { if ( isset( $catalog[ $key ] ) ) { $finish[] = $catalog[ $key ]; } }
            if ( $item->get_meta( '_ge_item_custom_finish', true ) ) { $finish[] = $item->get_meta( '_ge_item_custom_finish', true ); }
            // Explicit technical whitelist; never serialize arbitrary item metadata.
            $specs = array();
            foreach ( array( 'Especificaciones', 'Medidas', 'Medida', 'Material', 'Impresión', 'Colores' ) as $key ) {
                $value = $item->get_meta( $key, true ); if ( is_scalar( $value ) && '' !== (string) $value ) { $specs[ $key ] = sanitize_text_field( $value ); }
            }
            $items[] = array( 'item_id' => $item->get_id(), 'name' => sanitize_text_field( $item->get_name() ), 'quantity' => $item->get_quantity(), 'specs' => $specs, 'finishes' => $finish, 'release' => $item->get_meta( '_ge_item_artwork_release_hash', true ) );
        }
        if ( ! $items || ! $files ) { return new WP_Error( 'items', 'No hay trabajos liberados para este proveedor.' ); }
        return array( 'reference' => GE_WTP_Manual_Orders::reference( $order ), 'supplier' => $supplier, 'items' => $items, 'files' => $files, 'required_date' => $order->get_meta( '_ge_production_promised_date', true ), 'notes' => sanitize_textarea_field( $notes ), 'contact' => sanitize_email( GE_WTP_Notification_Center::settings()['sender_email'] ?? get_option( 'admin_email' ) ) );
    }
    private static function fingerprint( $snapshot ) { return hash( 'sha256', wp_json_encode( $snapshot ) ); }
    private static function encrypt( $token ) {
        $iv = random_bytes( 12 ); $tag = '';
        $cipher = openssl_encrypt( $token, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag );
        return base64_encode( $iv . $tag . $cipher );
    }
    private static function token( $row ) {
        $bytes = base64_decode( $row['secret'] ?? '', true );
        if ( ! $bytes || strlen( $bytes ) < 29 ) { return ''; }
        return openssl_decrypt( substr( $bytes, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 ) ) ?: '';
    }
    public static function url( $id, $row ) {
        return add_query_arg( array( 'action' => 'ge_supplier_portal', 'order_id' => $id, 'token' => self::token( $row ) ), admin_url( 'admin-post.php' ) );
    }
    public static function latest( $order, $supplier, $include_revoked = false ) {
        foreach ( array_reverse( self::rows( $order ) ) as $row ) { if ( $supplier === ( $row['supplier'] ?? '' ) && ( $include_revoked || empty( $row['revoked'] ) ) ) { return $row; } }
        return false;
    }
    public static function prepare( $id, $supplier, $notes ) {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { return new WP_Error( 'forbidden', 'Acceso denegado.' ); }
        if ( ! isset( self::profiles()[ $supplier ] ) ) { return new WP_Error( 'supplier', 'Proveedor no disponible.' ); }
        if ( ! self::lock( $id ) ) { return new WP_Error( 'busy', 'Otra operación está en curso.' ); }
        try {
            $order = wc_get_order( $id );
            if ( ! GE_WTP_Workflow::enabled( $order ) || 'production' !== $order->get_meta( GE_WTP_Workflow::STAGE_META, true ) ) { return new WP_Error( 'release', 'Liberá primero el pedido.' ); }
            $snapshot = self::snapshot( $order, $supplier, $notes ); if ( is_wp_error( $snapshot ) ) { return $snapshot; }
            $fingerprint = self::fingerprint( $snapshot ); $rows = self::rows( $order );
            $last = self::latest( $order, $supplier );
            if ( $last && $last['fingerprint'] === $fingerprint && $last['expires'] > time() ) { return $last; }
            $token = bin2hex( random_bytes( 32 ) );
            $row = array( 'id' => wp_generate_uuid4(), 'supplier' => $supplier, 'hash' => hash( 'sha256', $token ), 'secret' => self::encrypt( $token ), 'fingerprint' => $fingerprint, 'snapshot' => $snapshot, 'state' => 'ready_to_send', 'email_status' => 'not_sent', 'email' => self::profiles()[ $supplier ]['email'], 'expires' => time() + 30 * DAY_IN_SECONDS, 'created_at' => time(), 'created_by' => get_current_user_id(), 'version' => count( $rows ) + 1, 'events' => array() );
            $rows[] = $row; $order->update_meta_data( self::META, $rows );
            $order->update_meta_data( '_ge_supplier_portal_' . $supplier, 'yes' );
            self::audit( $order, 'graph.supplier.preview_dispatch', $supplier, array( 'dispatch_id' => $row['id'], 'fingerprint' => $fingerprint ) ); $order->save();
            return $row;
        } finally { self::unlock( $id ); }
    }
    public static function message( $row, $url ) {
        $s = $row['snapshot']; $profile = GE_WTP_Supplier_Dispatch::profile( $row['supplier'] );
        $text = 'Hola ' . $profile['name'] . ", te envío este trabajo para producir.\n\nOrden: " . $s['reference'] . "\n";
        foreach ( $s['items'] as $item ) {
            $text .= "Trabajo: " . $item['name'] . "\nCantidad: " . $item['quantity'] . "\n";
            foreach ( $item['specs'] as $key => $value ) { $text .= $key . ': ' . $value . "\n"; }
            $text .= 'Terminación: ' . ( implode( ', ', $item['finishes'] ) ?: 'Sin terminación adicional' ) . "\n";
        }
        $text .= 'Fecha requerida: ' . ( $s['required_date'] ?: 'A coordinar' ) . "\nNotas técnicas: " . ( $s['notes'] ?: 'Sin observaciones adicionales' ) . "\n";
        foreach ( $s['files'] as $file ) { $text .= 'Archivo: ' . $file['record']['name'] . "\n"; }
        return $text . "\nPortal y descarga segura: " . $url . "\n\nPor favor confirmame recepción y, si podés, fecha estimada de entrega.\n\nGracias, Graphex.\nContacto: " . $s['contact'];
    }
    public static function notify( $id, $dispatch_id, $manual = false, $retry = false ) {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { return new WP_Error( 'forbidden', 'Acceso denegado.' ); }
        if ( ! self::lock( $id ) ) { return new WP_Error( 'busy', 'Otra operación está en curso.' ); }
        try {
            $order = wc_get_order( $id ); if ( ! $order ) { return new WP_Error( 'order', 'Pedido inexistente.' ); }
            $rows = self::rows( $order );
            foreach ( $rows as $index => $row ) {
                if ( $dispatch_id !== $row['id'] ) { continue; }
                if ( ! empty( $row['revoked'] ) || $row['expires'] < time() || self::latest( $order, $row['supplier'] )['id'] !== $row['id'] ) { return new WP_Error( 'stale', 'Prepará la versión vigente.' ); }
                if ( ! empty( $row['sent_at'] ) ) { return $row; }
                if ( ! empty( $row['attempt_started'] ) && ! $retry ) { return new WP_Error( 'uncertain', 'Hubo un intento previo. Verificá Notificaciones antes de reintentar manualmente.' ); }
                $current = self::snapshot( $order, $row['supplier'], $row['snapshot']['notes'] );
                if ( is_wp_error( $current ) || self::fingerprint( $current ) !== $row['fingerprint'] ) { return new WP_Error( 'changed', 'Nueva versión disponible. Prepará y revisá una nueva orden.' ); }
                $email = self::profiles()[ $row['supplier'] ]['email'] ?? '';
                if ( ! $manual && ( ! is_email( $email ) || $email !== $row['email'] ) ) { return new WP_Error( 'email', 'Revisá el destinatario o usá envío manual.' ); }
                $row['attempt_started'] = time();
                $row['email_status'] = $manual ? 'not_sent' : 'pending';
                $rows[ $index ] = $row;
                $order->update_meta_data( self::META, $rows ); $order->save();
                $url = self::url( $id, $row );
                $html = nl2br( esc_html( self::message( $row, $url ) ) );
                $html = str_replace( esc_html( $url ), '<a href="' . esc_url( $url ) . '">Abrir portal y descargar archivos</a>', $html );
                $sent = $manual || GE_WTP_Notifications::send( $email, 'Orden de producción ' . $row['snapshot']['reference'] . ' · Graphex', $html, 'workflow_supplier_portal', $id );
                if ( ! $manual && $sent && isset( $GLOBALS['phpmailer'] ) && method_exists( $GLOBALS['phpmailer'], 'getLastMessageID' ) ) { $row['message_id'] = sanitize_text_field( $GLOBALS['phpmailer']->getLastMessageID() ); }
                $row['email_status'] = $manual ? 'not_sent' : ( $sent ? 'sent' : 'failed' );
                $row['channel'] = $manual ? 'manual' : 'email'; $row['state'] = $sent ? 'sent' : 'ready_to_send';
                if ( $sent ) { $row['sent_at'] = time(); } else { unset( $row['attempt_started'] ); }
                $row['events'][] = array( 'time' => time(), 'action' => $manual ? 'manual_dispatch' : 'email_' . $row['email_status'], 'actor' => get_current_user_id() );
                self::audit( $order, 'graph.supplier.notify', $row['supplier'], array( 'dispatch_id' => $row['id'], 'channel' => $row['channel'], 'status' => $row['email_status'], 'recipient' => $email ) );
                $rows[ $index ] = $row;
                $order->update_meta_data( self::META, $rows ); $order->save(); return $row;
            }
            return new WP_Error( 'dispatch', 'Orden técnica inexistente.' );
        } finally { self::unlock( $id ); }
    }
    public static function authorize( $order, $token ) {
        if ( ! $order || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { return false; }
        $hash = hash( 'sha256', $token );
        foreach ( self::rows( $order ) as $row ) {
            if ( hash_equals( $row['hash'], $hash ) && empty( $row['revoked'] ) && $row['expires'] >= time() && ! empty( $row['sent_at'] ) && self::assigned( $order, $row['supplier'] ) ) { return $row; }
        }
        return false;
    }
    private static function assigned( $order, $supplier ) {
        foreach ( $order->get_items() as $item ) { if ( $supplier === $item->get_meta( '_ge_production_supplier', true ) ) { return true; } }
        return false;
    }
    private static function csrf( $row, $token ) { return hash_hmac( 'sha256', $row['id'] . $token, wp_salt( 'nonce' ) ); }
    public static function supplier_action( $id, $token, $action, $eta = '' ) {
        if ( ! self::lock( $id ) ) { return new WP_Error( 'busy', 'Otra operación está en curso.' ); }
        try {
            $order = wc_get_order( $id ); $auth = self::authorize( $order, $token );
            if ( ! $auth || ! in_array( $action, array( 'acknowledged', 'eta', 'in_production', 'ready' ), true ) ) { return new WP_Error( 'forbidden', 'Enlace no válido.' ); }
            if ( self::latest( $order, $auth['supplier'] )['id'] !== $auth['id'] ) { return new WP_Error( 'version', 'Abrí la nueva versión antes de confirmar.' ); }
            if ( 'received' === $auth['state'] ) { return new WP_Error( 'closed', 'Graphex ya registró la recepción de este trabajo.' ); }
            $rows = self::rows( $order );
            foreach ( $rows as &$row ) {
                if ( $row['id'] !== $auth['id'] ) { continue; }
                if ( 'eta' === $action ) {
                    $date = DateTime::createFromFormat( '!Y-m-d', $eta );
                    if ( ! $date || $date->format( 'Y-m-d' ) !== $eta || $eta < wp_date( 'Y-m-d' ) || $eta > wp_date( 'Y-m-d', time() + 730 * DAY_IN_SECONDS ) ) { return new WP_Error( 'eta', 'Ingresá una fecha estimada válida.' ); }
                    $row['eta'] = $eta;
                } else {
                    $rank = array( 'sent' => 0, 'acknowledged' => 1, 'in_production' => 2, 'ready' => 3 );
                    if ( ( $rank[ $action ] ?? -1 ) <= ( $rank[ $row['state'] ] ?? -1 ) ) { return $row; }
                    $row['state'] = $action; if ( empty( $row['acknowledged_at'] ) ) { $row['acknowledged_at'] = time(); }
                }
                $row['last_activity'] = time(); $row['events'][] = array( 'time' => time(), 'action' => $action, 'eta' => 'eta' === $action ? $eta : '', 'actor' => 'supplier' );
                self::audit( $order, 'graph.supplier.' . ( 'eta' === $action ? 'set_eta' : ( 'acknowledged' === $action ? 'acknowledge_order' : 'set_status' ) ), $row['supplier'], array( 'dispatch_id' => $row['id'], 'state' => $row['state'], 'eta' => $row['eta'] ?? '' ) );
                $order->update_meta_data( self::META, $rows ); $order->save(); return $row;
            }
        } finally { self::unlock( $id ); }
    }
    public static function state_label( $row ) {
        $labels = array( 'ready_to_send' => 'Listo para enviar', 'sent' => 'Enviado · sin confirmación', 'acknowledged' => 'Recepción confirmada', 'in_production' => 'En producción', 'ready' => 'Listo para retirar', 'received' => 'Recibido por Graphex', 'cancelled' => 'Cancelado' );
        return $labels[ $row['state'] ] ?? $row['state'];
    }
    private static function guard( $action ) {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $id = absint( $_POST['order_id'] ?? 0 ); check_admin_referer( 'ge_supplier_' . $action . '_' . $id ); return $id;
    }
    private static function back( $id, $result ) {
        if ( is_wp_error( $result ) ) { set_transient( 'ge_supplier_error_' . get_current_user_id(), $result->get_error_message(), 60 ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $id, 'step' => 'supplier' ) ) ); exit;
    }
    public static function handle_source() { $id = self::guard( 'source' ); self::back( $id, self::set_source( $id, sanitize_key( $_POST['source'] ?? '' ), sanitize_key( $_POST['supplier'] ?? '' ) ) ); }
    public static function handle_prepare() { $id = self::guard( 'prepare' ); self::back( $id, self::prepare( $id, sanitize_key( $_POST['supplier'] ?? '' ), sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ) ) ); }
    public static function handle_notify() { $id = self::guard( 'notify' ); self::back( $id, self::notify( $id, sanitize_text_field( $_POST['dispatch_id'] ?? '' ), ! empty( $_POST['manual'] ), ! empty( $_POST['retry'] ) ) ); }
    public static function handle_status() { $id = self::guard( 'status' ); self::back( $id, self::staff_status( $id, sanitize_text_field( $_POST['dispatch_id'] ?? '' ), sanitize_key( $_POST['status'] ?? '' ) ) ); }
    public static function staff_status( $id, $dispatch_id, $status ) {
        if ( ! GE_WTP_Staff_Portal::can_access() || ! in_array( $status, array( 'received', 'cancelled' ), true ) ) { return new WP_Error( 'status', 'Acción interna no autorizada.' ); }
        if ( ! self::lock( $id ) ) { return new WP_Error( 'busy', 'Otra operación está en curso.' ); }
        try {
            $order = wc_get_order( $id ); if ( ! $order ) { return new WP_Error( 'order', 'Pedido inexistente.' ); }
            $rows = self::rows( $order );
            foreach ( $rows as $index => $row ) {
                if ( $row['id'] !== $dispatch_id || empty( $row['sent_at'] ) || ! empty( $row['revoked'] ) ) { continue; }
                $row['state'] = $status; $row['last_activity'] = time();
                if ( 'cancelled' === $status ) { $row['revoked'] = true; }
                $row['events'][] = array( 'time' => time(), 'actor' => get_current_user_id(), 'action' => $status ); $rows[ $index ] = $row;
                self::audit( $order, 'graph.supplier.set_status', $row['supplier'], array( 'dispatch_id' => $dispatch_id, 'state' => $status ) ); $order->update_meta_data( self::META, $rows ); $order->save(); return $row;
            }
            return new WP_Error( 'dispatch', 'No hay envío activo para esta acción.' );
        } finally { self::unlock( $id ); }
    }
    public static function handle_revoke() {
        $id = self::guard( 'revoke' ); if ( ! self::lock( $id ) ) { self::back( $id, new WP_Error( 'busy', 'Otra operación está en curso.' ) ); }
        try {
            $order = wc_get_order( $id ); $rows = self::rows( $order );
            foreach ( $rows as &$row ) { if ( $row['id'] === ( $_POST['dispatch_id'] ?? '' ) ) { $row['revoked'] = true; self::audit( $order, 'graph.supplier.revoke_portal_link', $row['supplier'], array( 'dispatch_id' => $row['id'] ) ); } }
            unset( $row ); $order->update_meta_data( self::META, $rows ); $order->save();
        } finally { self::unlock( $id ); } self::back( $id, true );
    }
    private static function form_start( $id, $action ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_supplier_' . esc_attr( 'notify' === $action ? 'notify_v1' : $action ) . '"><input type="hidden" name="order_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'ge_supplier_' . $action . '_' . $id );
    }
    public static function render( $order ) {
        wp_enqueue_style( 'ge-supplier-portal', GE_WTP_PLUGIN_URL . 'assets/css/supplier-portal.css', array(), GE_WTP_VERSION );
        wp_enqueue_script( 'ge-supplier-portal', GE_WTP_PLUGIN_URL . 'assets/js/supplier-portal.js', array(), GE_WTP_VERSION, true );
        $id = $order->get_id(); $profiles = self::profiles();
        $error = get_transient( 'ge_supplier_error_' . get_current_user_id() ); if ( $error ) { echo '<p class="ge-production-notice is-error" role="alert">' . esc_html( $error ) . '</p>'; delete_transient( 'ge_supplier_error_' . get_current_user_id() ); }
        echo '<section class="ge-production-card ge-sp"><span class="ge-sp-eyebrow">Destino de producción</span><h2>¿Quién produce este trabajo?</h2>';
        self::form_start( $id, 'source' ); $internal = 'internal' === $order->get_meta( '_ge_production_supplier', true );
        echo '<div class="ge-sp-choices"><label><input type="radio" name="source" value="internal" ' . checked( $internal, true, false ) . '> <strong>Lo hacemos nosotros</strong><small>Producción interna · sin envío</small></label><label><input type="radio" name="source" value="supplier" ' . checked( $internal, false, false ) . '> <strong>Enviar a proveedor</strong><small>Orden técnica y portal privado</small></label></div><label>Buscar proveedor<input type="search" data-ge-supplier-search placeholder="Nombre o categoría"></label><label>Proveedor<select name="supplier" data-ge-supplier-select><option value="">Seleccionar proveedor</option>';
        foreach ( $profiles as $key => $profile ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $order->get_meta( '_ge_production_supplier', true ), $key, false ) . '>' . esc_html( $profile['name'] . ( $profile['category'] ? ' · ' . $profile['category'] : '' ) ) . '</option>'; }
        echo '</select></label><button class="ge-staff-button" type="submit">Guardar destino</button></form></section>';
        if ( $internal ) { echo '<section class="ge-production-card"><h2>Producción interna</h2><p>Continúa por el flujo interno. No se generó ningún envío a proveedor.</p></section>'; return; }
        $keys = array(); foreach ( $order->get_items() as $item ) { $key = $item->get_meta( '_ge_production_supplier', true ); if ( isset( $profiles[ $key ] ) ) { $keys[ $key ] = true; } }
        foreach ( array_keys( $keys ) as $key ) {
            $profile = $profiles[ $key ]; $row = self::latest( $order, $key );
            echo '<section class="ge-production-card ge-sp"><span class="ge-sp-eyebrow">Orden técnica</span><h2>' . esc_html( $profile['name'] ) . '</h2><p>' . esc_html( $profile['email'] ?: 'Sin email · disponible el envío manual' ) . '</p><a href="' . esc_url( GE_WTP_Supplier_Workspace::detail_url( $key ) ) . '">Abrir ficha del proveedor →</a>';
            self::form_start( $id, 'prepare' ); echo '<input type="hidden" name="supplier" value="' . esc_attr( $key ) . '"><label>Notas técnicas para este proveedor<textarea name="notes" maxlength="3000" rows="4">' . esc_textarea( $row['snapshot']['notes'] ?? $order->get_meta( '_ge_production_technical_notes', true ) ) . '</textarea></label><button class="ge-staff-button" type="submit">Preparar / actualizar vista previa</button></form>';
            if ( $row ) {
                $current = self::snapshot( $order, $key, $row['snapshot']['notes'] ); $changed = is_wp_error( $current ) || self::fingerprint( $current ) !== $row['fingerprint'];
                echo '<p class="ge-sp-status">' . esc_html( self::state_label( $row ) ) . ' · Versión ' . esc_html( $row['version'] ) . '</p><p>Email: ' . esc_html( $row['email_status'] ) . ' · ETA: ' . esc_html( $row['eta'] ?? 'Sin informar' ) . '</p>';
                if ( $changed ) { echo '<p class="ge-production-notice is-error">Nueva versión disponible. Prepará una nueva vista previa y revisala antes de enviar.</p>'; }
                $url = self::url( $id, $row ); $message = self::message( $row, $url );
                echo '<label>Mensaje exacto que recibirá<textarea readonly rows="13" data-ge-copy-text>' . esc_textarea( $message ) . '</textarea></label><button type="button" data-ge-copy>Copiar mensaje</button><label>Link privado al portal<input readonly value="' . esc_attr( $url ) . '"></label><p>Válido hasta ' . esc_html( wp_date( 'd/m/Y', $row['expires'] ) ) . '. El proveedor verá sólo información técnica.</p>';
                if ( ! empty( $row['sent_at'] ) ) { echo '<a class="ge-staff-button" href="' . esc_url( $url ) . '" target="_blank" rel="noreferrer">Ver portal del proveedor</a><p>Envío registrado el ' . esc_html( wp_date( 'd/m/Y H:i', $row['sent_at'] ) ) . '. Un nuevo click no repite el email. Para reenviar, copiá el mensaje o prepará una nueva versión.</p>'; }
                elseif ( ! $changed ) {
                    self::form_start( $id, 'notify' ); echo '<input type="hidden" name="dispatch_id" value="' . esc_attr( $row['id'] ) . '">';
                    if ( is_email( $row['email'] ) ) { if ( ! empty( $row['attempt_started'] ) ) { echo '<label><input required type="checkbox" name="retry" value="1">Verifiqué Notificaciones; el intento previo no fue enviado.</label>'; } echo '<button class="ge-staff-button" type="submit">' . ( 'failed' === $row['email_status'] ? 'Reintentar email' : 'Enviar al proveedor' ) . '</button>'; }
                    echo '<button type="submit" name="manual" value="1">Registrar envío manual</button></form><p>Copiá el mensaje y enviá el link por tu canal habitual antes de registrar el envío manual. Esa acción habilita el portal.</p>';
                }
                self::form_start( $id, 'revoke' ); echo '<input type="hidden" name="dispatch_id" value="' . esc_attr( $row['id'] ) . '"><button type="submit">Revocar acceso</button></form>';
                if ( ! empty( $row['sent_at'] ) ) { self::form_start( $id, 'status' ); echo '<input type="hidden" name="dispatch_id" value="' . esc_attr( $row['id'] ) . '"><button name="status" value="received">Registrar recibido en Graphex</button><button name="status" value="cancelled">Cancelar despacho</button></form>'; }
                echo '<details><summary>Historial de actividad</summary><ul>'; foreach ( array_reverse( $row['events'] ) as $event ) { echo '<li>' . esc_html( wp_date( 'd/m/Y H:i', $event['time'] ) . ' · ' . $event['action'] . ( ! empty( $event['eta'] ) ? ' · ' . $event['eta'] : '' ) ) . '</li>'; } echo '</ul></details>';
            }
            echo '</section>';
        }
    }
    private static function headers() {
        nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); header( 'Referrer-Policy: no-referrer' ); header( 'X-Content-Type-Options: nosniff' ); header( 'X-Frame-Options: DENY' ); header( "Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'" );
    }
    public static function portal() {
        self::headers(); $id = absint( $_REQUEST['order_id'] ?? 0 ); $token = sanitize_text_field( $_REQUEST['token'] ?? '' ); $order = wc_get_order( $id ); $row = self::authorize( $order, $token );
        if ( ! $row ) { wp_die( 'Este acceso venció o fue revocado. Pedí un nuevo enlace a Graphex.', 403 ); }
        $notice = ! empty( $_GET['document_received'] ) ? 'Documento recibido y registrado en tu ficha de proveedor.' : '';
        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            if ( ! hash_equals( self::csrf( $row, $token ), (string) ( $_POST['csrf'] ?? '' ) ) ) { wp_die( 'Solicitud no válida.', 403 ); }
            $result = 'document' === ( $_POST['supplier_action'] ?? '' ) ? GE_WTP_Supplier_Workspace::portal_document( $id, $token, sanitize_key( $_POST['document_type'] ?? '' ) ) : self::supplier_action( $id, $token, sanitize_key( $_POST['supplier_action'] ?? '' ), sanitize_text_field( $_POST['eta'] ?? '' ) );
            if ( is_wp_error( $result ) ) { $notice = $result->get_error_message(); } else { $back = self::url( $id, $result ); if ( 'document' === ( $_POST['supplier_action'] ?? '' ) ) { $back = add_query_arg( 'document_received', 1, $back ); } wp_safe_redirect( $back ); exit; }
        }
        $s = $row['snapshot']; $profile = GE_WTP_Supplier_Dispatch::profile( $row['supplier'] ); $latest = self::latest( $order, $row['supplier'] );
        ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Orden <?php echo esc_html( $s['reference'] ); ?> · Graphex</title><link rel="stylesheet" href="<?php echo esc_url( GE_WTP_PLUGIN_URL . 'assets/css/supplier-portal.css' ); ?>"></head><body class="ge-sp-body"><header class="ge-sp-header"><a href="<?php echo esc_url( self::url( $id, $row ) ); ?>" aria-label="Graphex · portal proveedor"><img src="<?php echo esc_url( GE_WTP_PLUGIN_URL . 'assets/images/graphex-simbolo.svg' ); ?>" alt="" width="36" height="36"><strong>Graphex</strong></a><span>Portal de producción</span></header><main class="ge-sp-main"><div class="ge-sp-intro"><span class="ge-sp-eyebrow"><?php echo esc_html( $profile['name'] ); ?> · Orden técnica</span><h1><?php echo esc_html( $s['reference'] ); ?></h1><p>Todo lo que necesitás para producir este trabajo.</p></div><?php if ( $notice ) { echo '<p role="alert" class="ge-sp-alert">' . esc_html( $notice ) . '</p>'; } ?><?php if ( $latest['id'] !== $row['id'] ) { echo '<p class="ge-sp-alert">Nueva versión disponible. Esta es la versión ' . esc_html( $row['version'] ) . '. <a href="' . esc_url( self::url( $id, $latest ) ) . '">Abrir versión actual</a></p>'; } ?><div class="ge-sp-layout"><div><section class="ge-sp-panel"><div class="ge-sp-panel-head"><h2>Ficha de producción</h2><span class="ge-sp-tag">Versión <?php echo esc_html( $row['version'] ); ?></span></div><?php foreach ( $s['items'] as $item ) : ?><article class="ge-sp-item"><h3><?php echo esc_html( $item['name'] ); ?></h3><dl><dt>Cantidad</dt><dd><?php echo esc_html( $item['quantity'] ); ?> unidades</dd><?php foreach ( $item['specs'] as $key => $value ) : ?><dt><?php echo esc_html( $key ); ?></dt><dd><?php echo esc_html( $value ); ?></dd><?php endforeach; ?><dt>Terminación</dt><dd><?php echo esc_html( implode( ', ', $item['finishes'] ) ?: 'Sin terminación adicional' ); ?></dd></dl></article><?php endforeach; ?><h3>Notas de producción</h3><p class="ge-sp-notes"><?php echo nl2br( esc_html( $s['notes'] ?: 'Sin observaciones adicionales.' ) ); ?></p></section><section class="ge-sp-panel"><h2>Archivos para producir</h2><p>Estos archivos corresponden a la versión exacta de esta orden.</p><?php foreach ( $s['files'] as $file_id => $file ) : ?><article class="ge-sp-file"><div><strong><?php echo esc_html( $file['record']['name'] ); ?></strong><small><?php echo esc_html( size_format( $file['record']['size'] ?? 0 ) ); ?> · Versión <?php echo esc_html( $row['version'] ); ?></small></div><a class="ge-sp-button" href="<?php echo esc_url( add_query_arg( array( 'action' => 'ge_supplier_portal_file', 'order_id' => $id, 'token' => $token, 'file_id' => $file_id ), admin_url( 'admin-post.php' ) ) ); ?>">Descargar</a></article><?php endforeach; ?></section></div><aside><section class="ge-sp-panel"><span class="ge-sp-eyebrow">Seguimiento</span><h2><?php echo esc_html( self::state_label( $row ) ); ?></h2><dl><dt>Fecha requerida</dt><dd><?php echo esc_html( $s['required_date'] ?: 'A coordinar' ); ?></dd><dt>Entrega estimada</dt><dd><?php echo esc_html( $row['eta'] ?? 'Por confirmar' ); ?></dd></dl><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_supplier_portal"><input type="hidden" name="order_id" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>"><input type="hidden" name="csrf" value="<?php echo esc_attr( self::csrf( $row, $token ) ); ?>"><button class="ge-sp-button" name="supplier_action" value="acknowledged">Confirmar recepción</button><label>Fecha estimada de entrega<input type="date" name="eta" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" value="<?php echo esc_attr( $row['eta'] ?? '' ); ?>"></label><button name="supplier_action" value="eta">Guardar fecha estimada</button><button name="supplier_action" value="in_production">Marcar en producción</button><button name="supplier_action" value="ready">Marcar listo para retirar</button></form><p class="ge-sp-help">Marcar listo avisa a Graphex. La recepción y los pagos se registran por separado.</p></section><section class="ge-sp-panel"><h2>Contacto Graphex</h2><p>Para consultas o cambios en el trabajo:</p><a href="<?php echo esc_url( 'mailto:' . $s['contact'] ); ?>"><?php echo esc_html( $s['contact'] ); ?></a></section><section class="ge-sp-panel"><h2>Factura o remito</h2><p>Subí el documento de este trabajo. Queda registrado en tu ficha de proveedor.</p><form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_supplier_portal"><input type="hidden" name="order_id" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>"><input type="hidden" name="csrf" value="<?php echo esc_attr( self::csrf( $row, $token ) ); ?>"><input type="hidden" name="supplier_action" value="document"><label>Tipo de documento<select name="document_type"><option value="invoice">Factura</option><option value="delivery_note">Remito</option><option value="other">Otro documento</option></select></label><label>Archivo · hasta 20 MB<input type="file" name="supplier_file" accept=".pdf,.jpg,.jpeg,.png" required></label><button type="submit">Subir documento</button></form></section></aside></div><section class="ge-sp-panel"><h2>Tus trabajos</h2><p>Órdenes enviadas a <?php echo esc_html( $profile['name'] ); ?>.</p><?php self::dashboard( $row['supplier'] ); ?></section></main><footer class="ge-sp-footer">Graphex · Producción conectada</footer></body></html><?php exit;
    }
    private static function dashboard( $supplier ) {
        // Bounded scan; only dispatched snapshots for this supplier are exposed.
        $orders = wc_get_orders( array( 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => '_ge_supplier_portal_' . $supplier, 'value' => 'yes' ) ), 'type' => 'shop_order' ) );
        foreach ( $orders as $order ) {
            $row = self::latest( $order, $supplier, true );
            if ( ! $row || empty( $row['sent_at'] ) || ! self::assigned( $order, $supplier ) ) { continue; }
            if ( ! empty( $row['revoked'] ) || $row['expires'] < time() ) {
                echo '<div class="ge-sp-work"><strong>' . esc_html( $row['snapshot']['reference'] ) . '</strong><span>' . esc_html( self::state_label( $row ) ) . '</span><span>Histórico · acceso vencido o revocado</span></div>'; continue;
            }
            echo '<a class="ge-sp-work" href="' . esc_url( self::url( $order->get_id(), $row ) ) . '"><strong>' . esc_html( $row['snapshot']['reference'] ) . '</strong><span>' . esc_html( self::state_label( $row ) ) . '</span><span>ETA: ' . esc_html( $row['eta'] ?? 'Por confirmar' ) . '</span></a>';
        }
    }
    public static function download() {
        self::headers(); $id = absint( $_GET['order_id'] ?? 0 ); $token = sanitize_text_field( $_GET['token'] ?? '' ); $file_id = sanitize_text_field( $_GET['file_id'] ?? '' );
        if ( ! self::lock( $id ) ) { wp_die( 'Otra operación está en curso. Reintentá.', 409 ); }
        try {
            $order = wc_get_order( $id ); $row = self::authorize( $order, $token ); $file = $row['snapshot']['files'][ $file_id ] ?? false;
            if ( ! $file ) { wp_die( 'Acceso denegado.', 403 ); }
            $path = self::path( $file['record'] );
            if ( ! $path || ! is_file( $path ) || ! hash_equals( $file['checksum'], hash_file( 'sha256', $path ) ) ) { wp_die( 'Esta versión ya no está disponible. Contactá a Graphex.', 409 ); }
            self::audit( $order, 'graph.supplier.download', $row['supplier'], array( 'dispatch_id' => $row['id'], 'version_id' => $file_id, 'checksum' => $file['checksum'] ) ); $order->save();
        } finally { self::unlock( $id ); }
        GE_WTP_Documents::stream_record( $file['record'] );
    }
}
