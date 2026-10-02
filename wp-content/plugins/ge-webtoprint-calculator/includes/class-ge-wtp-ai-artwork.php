<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Internal, order-scoped bridge. No endpoint approves or releases artwork. */
final class GE_WTP_AI_Artwork {
    const REQUESTS_META = '_ge_ai_artwork_requests';
    const MAX_BYTES = 100 * MB_IN_BYTES;

    public static function init() {
        add_action( 'wp_ajax_ge_ai_artwork', array( __CLASS__, 'ajax' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    private static function secret() {
        return defined( 'GE_AI_GRUPO_SHARED_SECRET' ) ? (string) GE_AI_GRUPO_SHARED_SECRET : '';
    }

    private static function document_path( $document ) {
        if ( 'vps' === ( $document['provider'] ?? '' ) ) {
            return class_exists( 'GE_WTP_VPS_Storage' ) ? GE_WTP_VPS_Storage::download_path( $document['relative_path'] ?? '' ) : '';
        }
        if ( 'r2' === ( $document['provider'] ?? '' ) ) { return ''; }
        $name = $document['stored_name'] ?? '';
        return $name ? trailingslashit( GE_WTP_Documents::private_directory() ) . wp_basename( $name ) : '';
    }

    public static function authorized( $request ) {
        $secret = self::secret();
        $header = $request->get_header( 'authorization' );
        return strlen( $secret ) >= 32 && is_string( $header ) && hash_equals( 'Bearer ' . $secret, $header );
    }

    public static function register_routes() {
        register_rest_route( 'ge/v1', '/ai-artwork/(?P<order>\d+)/(?P<version>[A-Za-z0-9_-]{1,80})', array(
            'methods' => 'GET', 'callback' => array( __CLASS__, 'read_artifact' ),
            'permission_callback' => array( __CLASS__, 'authorized' ),
        ) );
        register_rest_route( 'ge/v1', '/ai-artwork/result', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'receive_result' ),
            'permission_callback' => array( __CLASS__, 'authorized' ),
        ) );
    }

    private static function requests( $order ) {
        $value = $order->get_meta( self::REQUESTS_META, true );
        return is_array( $value ) ? $value : array();
    }

    private static function save_request( $order, $request ) {
        $rows = self::requests( $order );
        $rows[ $request['request_id'] ] = $request;
        $order->update_meta_data( self::REQUESTS_META, $rows );
        $order->save();
    }

    private static function update_version( $order, $version_id, $changes ) {
        $documents = GE_WTP_Documents::get_documents( $order->get_id() );
        foreach ( $documents as $index => $document ) {
            if ( ! hash_equals( GE_WTP_Documents::version_id( $document ), $version_id ) ) { continue; }
            $documents[ $index ] = array_merge( $document, $changes );
            $order->update_meta_data( GE_WTP_Documents::META_KEY, $documents );
            $order->save();
            return $documents[ $index ];
        }
        return null;
    }

    public static function assets() {
        static $done = false;
        if ( $done ) { return; } $done = true;
        echo '<link rel="stylesheet" href="' . esc_url( GE_WTP_PLUGIN_URL . 'assets/css/ai-artwork.css?v=2' ) . '">';
        echo '<script defer src="' . esc_url( GE_WTP_PLUGIN_URL . 'assets/js/ai-artwork.js?v=2' ) . '"></script>';
    }

    public static function runtime( $kind ) {
        // A method being loadable is not an image executor certification.
        $caps = defined( 'GE_AI_GRUPO_RUNTIME_CAPABILITIES' ) ? json_decode( GE_AI_GRUPO_RUNTIME_CAPABILITIES, true ) : array();
        $cap = is_array( $caps ) ? ( $caps[ $kind ] ?? array() ) : array();
        $endpoint = defined( 'GE_AI_GRUPO_URL' ) ? GE_AI_GRUPO_URL : '';
        $available = ! empty( $cap['runtime_available'] ) && ! empty( $cap['executor_available'] ) &&
            absint( $cap['checked_at'] ?? 0 ) > time() - 300 && absint( $cap['checked_at'] ?? 0 ) <= time() &&
            wp_http_validate_url( $endpoint ) && 0 === strpos( $endpoint, 'https://' ) && strlen( self::secret() ) >= 32;
        return array( 'runtime_available' => (bool) $available, 'skill' => $cap['skill'] ?? null,
            'reason' => $available ? '' : 'AI-GRUPO todavía no tiene un executor de edición certificado y conectado por HTTPS. La solicitud queda registrada; no se generó un archivo.' );
    }

    public static function render_button( $order, $document ) {
        if ( ! GE_WTP_Staff_Portal::can_access() || 'arte' !== ( $document['category'] ?? '' ) || 'discarded' === ( $document['status'] ?? '' ) ) { return; }
        $version = GE_WTP_Documents::version_id( $document );
        $payload = array( 'order_id' => $order->get_id(), 'version_id' => $version, 'name' => $document['name'],
            'mime' => $document['mime'] ?? '', 'url' => html_entity_decode( GE_WTP_Documents::download_url( $order->get_id(), $document['id'], true ), ENT_QUOTES, 'UTF-8' ),
            'analysis' => $document['analysis'] ?? array(), 'preflight_status' => $document['preflight_status'] ?? null, 'nonce' => wp_create_nonce( 'ge_ai_artwork_' . $order->get_id() ),
            'endpoint' => admin_url( 'admin-ajax.php' ) );
        echo '<button type="button" class="ge-ai-open" data-ge-ai="' . esc_attr( wp_json_encode( $payload ) ) . '">Mejorar con IA <small>Beta</small></button>';
    }

    public static function ajax() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_send_json_error( array( 'message' => 'Acceso denegado.' ), 403 ); }
        $order_id = absint( $_POST['order_id'] ?? 0 );
        check_ajax_referer( 'ge_ai_artwork_' . $order_id, 'nonce' );
        $input = array( 'order_id' => $order_id, 'version_id' => sanitize_text_field( wp_unslash( $_POST['version_id'] ?? '' ) ),
            'request_id' => sanitize_text_field( wp_unslash( $_POST['request_id'] ?? '' ) ),
            'instruction' => sanitize_textarea_field( wp_unslash( $_POST['instruction'] ?? '' ) ),
            'kind' => sanitize_key( $_POST['kind'] ?? 'design_edit' ), 'decision' => sanitize_key( $_POST['decision'] ?? '' ) );
        $op = sanitize_key( $_POST['op'] ?? '' );
        $ids = array( 'request' => 'graph.artwork.ai_improve.request', 'status' => 'graph.artwork.ai_improve.status', 'decision' => 'graph.artwork.select_version' );
        if ( ! isset( $ids[ $op ] ) ) { wp_send_json_error( array( 'message' => 'Acción inválida.' ), 400 ); }
        $result = self::execute_action( $ids[ $op ], $input );
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
        wp_send_json_success( $result );
    }

    public static function execute_action( $action, $input ) {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { return new WP_Error( 'actor', 'Acceso denegado.' ); }
        $order = wc_get_order( absint( $input['order_id'] ?? 0 ) );
        if ( ! $order ) { return new WP_Error( 'order', 'Pedido inválido.' ); }
        $rows = self::requests( $order );
        if ( 'graph.artwork.ai_improve.request' === $action ) {
            $base = GE_WTP_Documents::find_version( $order->get_id(), $input['version_id'] ?? '' );
            if ( ! $base || 'arte' !== ( $base['category'] ?? '' ) || 'discarded' === ( $base['status'] ?? '' ) ) { return new WP_Error( 'version', 'Versión no elegible de este pedido.' ); }
            $base = GE_WTP_File_Analysis::record( $base, GE_WTP_Documents::private_directory() );
            $instruction = sanitize_textarea_field( $input['instruction'] ?? '' );
            $kind = sanitize_key( $input['kind'] ?? 'design_edit' );
            if ( strlen( $instruction ) < 8 || strlen( $instruction ) > 2000 || ! in_array( $kind, array( 'design_edit', 'resize', 'text_edit', 'file_prepare', 'prepress_check' ), true ) ) { return new WP_Error( 'instruction', 'Describí la mejora (8 a 2000 caracteres).' ); }
            $id = $input['request_id'] ?? '';
            if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) ) { return new WP_Error( 'request', 'Identificador de solicitud inválido.' ); }
            $lock = 'ge_ai_request_' . md5( $order->get_id() );
            if ( ! add_option( $lock, time(), '', 'no' ) ) { return new WP_Error( 'busy', 'Hay otra solicitud en curso. Reintentá.' ); }
            try {
                $order = wc_get_order( $order->get_id() ); $rows = self::requests( $order );
                if ( isset( $rows[ $id ] ) ) {
                    $row = $rows[ $id ];
                    if ( $row['base_version_id'] !== $input['version_id'] || $row['instruction'] !== $instruction || $row['kind'] !== $kind ) { return new WP_Error( 'conflict', 'La clave ya pertenece a otra solicitud.' ); }
                    return self::view( $order, $row );
                }
                $key = 'ge_ai_artwork_rate_' . get_current_user_id(); $rate = absint( get_transient( $key ) );
                if ( $rate >= 10 ) { return new WP_Error( 'rate', 'Límite de diez solicitudes por hora.' ); }
                $path = self::document_path( $base );
                if ( ! $path || ! is_file( $path ) || filesize( $path ) > self::MAX_BYTES ) { return new WP_Error( 'storage', 'El original no está disponible en almacenamiento privado compatible (máximo 100 MB).' ); }
                $checksum = hash_file( 'sha256', $path );
                if ( ! empty( $base['checksum_sha256'] ) && ! hash_equals( $base['checksum_sha256'], $checksum ) ) { return new WP_Error( 'checksum', 'El original cambió. Revisá la versión.' ); }
                $runtime = self::runtime( $kind );
                $body = array( 'project' => 'graph-express', 'request_id' => $id, 'order_id' => $order->get_id(),
                    'order_item_id' => absint( $base['order_item_id'] ?? 0 ), 'base_version_id' => GE_WTP_Documents::version_id( $base ),
                    'artifact_ref' => 'graph://orders/' . $order->get_id() . '/artwork/' . GE_WTP_Documents::version_id( $base ), 'kind' => $kind, 'instruction' => $instruction );
                $pack = array_merge( $body, array( 'artwork_id' => $base['id'], 'version_id' => $body['base_version_id'],
                    'checksum_sha256' => $checksum, 'file_analysis_ref' => $base['file_analysis_ref'] ?? '', 'preflight_summary' => GE_WTP_File_Analysis::task_summary( $base ),
                    'requested_capability' => $kind, 'requested_skill' => $runtime['skill'], 'output_contract' => 'new_candidate_version', 'runtime' => $runtime ) );
                $row = array( 'request_id' => $id, 'order_id' => $order->get_id(), 'order_item_id' => $body['order_item_id'],
                    'base_version_id' => $body['base_version_id'], 'base_checksum_sha256' => $checksum, 'instruction' => $instruction, 'kind' => $kind,
                    'status' => $runtime['runtime_available'] ? 'sending' : 'blocked', 'task_id' => '', 'task_pack' => $pack,
                    'error' => $runtime['reason'], 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) );
                self::save_request( $order, $row ); set_transient( $key, $rate + 1, HOUR_IN_SECONDS );
                if ( $runtime['runtime_available'] ) {
                    $response = wp_remote_post( GE_AI_GRUPO_URL, array( 'timeout' => 12, 'redirection' => 0,
                        'headers' => array( 'Authorization' => 'Bearer ' . self::secret(), 'Content-Type' => 'application/json', 'Idempotency-Key' => $id ), 'body' => wp_json_encode( $pack ) ) );
                    $data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
                    if ( ! is_wp_error( $response ) && 201 === wp_remote_retrieve_response_code( $response ) && preg_match( '/^TASK-\d{8}-[A-Z0-9]{8}$/D', $data['task_id'] ?? '' ) ) { $row['status'] = 'queued'; $row['task_id'] = $data['task_id']; }
                    else { $row['status'] = 'failed'; $row['error'] = 'AI-GRUPO no confirmó la tarea. No se generó un archivo.'; }
                    $order = wc_get_order( $order->get_id() ); $latest = self::requests( $order );
                    if ( isset( $latest[ $id ] ) && in_array( $latest[ $id ]['status'], array( 'running', 'completed', 'blocked', 'failed' ), true ) ) { $row = $latest[ $id ]; }
                    self::save_request( $order, $row );
                }
                $order->add_order_note( 'Mejorar con IA: ' . $id . ' · ' . $row['status'] . ' · base ' . $row['base_version_id'] ); $order->save();
                return self::view( $order, $row );
            } finally { delete_option( $lock ); }
        }
        if ( in_array( $action, array( 'graph.artwork.ai_improve.status', 'graph.artwork.ai_improve.result' ), true ) ) {
            $id = $input['request_id'] ?? '';
            if ( ! $id ) {
                $matches = array_filter( $rows, function( $row ) use ( $input ) { return $row['base_version_id'] === ( $input['version_id'] ?? '' ); } );
                $row = $matches ? end( $matches ) : null;
            } else { $row = $rows[ $id ] ?? null; }
            return $row ? self::view( $order, $row ) : array( 'request' => null, 'candidate' => null, 'runtime' => self::runtime( 'design_edit' ) );
        }
        if ( 'graph.artwork.select_version' === $action ) {
            $doc = GE_WTP_Documents::find_version( $order->get_id(), $input['version_id'] ?? '' );
            if ( ! $doc || 'ai_candidate' !== ( $doc['source_type'] ?? '' ) || 'candidate' !== ( $doc['status'] ?? '' ) ) { return new WP_Error( 'candidate', 'Sólo una candidata pendiente puede revisarse.' ); }
            $decision = $input['decision'] ?? ''; if ( ! in_array( $decision, array( 'select', 'discard' ), true ) ) { return new WP_Error( 'decision', 'Decisión inválida.' ); }
            $item = $order->get_item( absint( $doc['order_item_id'] ?? 0 ) );
            if ( 'select' === $decision ) {
                if ( ! $item || in_array( GE_WTP_Production::item_status( $item, $order ), array( 'production', 'ready', 'delivered' ), true ) ) { return new WP_Error( 'released', 'Asigná el archivo a un producto en revisión antes de usarlo. Un producto liberado debe volver a revisión.' ); }
                $path = self::document_path( $doc ); if ( ! is_file( $path ) || ! hash_equals( $doc['checksum_sha256'], hash_file( 'sha256', $path ) ) ) { return new WP_Error( 'checksum', 'El archivo candidato cambió.' ); }
                $sources = (array) $item->get_meta( '_ge_item_artwork_sources', true );
                $sources = array_values( array_filter( $sources, function( $token ) use ( $doc ) { return is_string( $token ) && '' !== $token && $token !== 'document:' . $doc['parent_version_id']; } ) );
                $sources[] = 'document:' . $doc['id'];
                $item->update_meta_data( '_ge_item_artwork_sources', array_values( array_unique( $sources ) ) );
                $item->update_meta_data( '_ge_item_artwork_version', $doc['version_id'] );
                $item->update_meta_data( '_ge_item_artwork_client_required', 'yes' );
                foreach ( array( '_ge_item_artwork_customer_approval', '_ge_item_artwork_staff_approval', '_ge_item_artwork_release_hash', '_ge_item_artwork_released_at', '_ge_item_artwork_released_by' ) as $key ) { $item->delete_meta_data( $key ); }
                if ( class_exists( 'GE_WTP_Workflow' ) && GE_WTP_Workflow::enabled( $order ) ) { $item->update_meta_data( GE_WTP_Workflow::ITEM_STATE_META, 'received' ); }
                $item->save();
            }
            self::update_version( $order, $doc['version_id'], array( 'status' => 'select' === $decision ? 'client_review' : 'discarded', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => gmdate( 'c' ) ) );
            $order->add_order_note( 'Candidata ' . $doc['version_id'] . ': ' . $decision . '. SHA-256 ' . $doc['checksum_sha256'] . '. No implica aprobación del cliente.' ); $order->save();
            return array( 'version_id' => $doc['version_id'], 'decision' => $decision, 'client_approval_required' => 'select' === $decision );
        }
        return new WP_Error( 'action', 'Acción no disponible.' );
    }

    private static function view( $order, $row ) {
        $candidate = empty( $row['candidate_version_id'] ) ? null : GE_WTP_Documents::find_version( $order->get_id(), $row['candidate_version_id'] );
        if ( $candidate ) { $candidate = array_intersect_key( $candidate, array_flip( array( 'id', 'version_id', 'parent_version_id', 'checksum_sha256', 'status', 'mime', 'name', 'analysis', 'preflight_status' ) ) ); $candidate['url'] = html_entity_decode( GE_WTP_Documents::download_url( $order->get_id(), $candidate['id'], true ), ENT_QUOTES, 'UTF-8' ); }
        return array( 'request' => $row, 'candidate' => $candidate, 'runtime' => self::runtime( $row['kind'] ) );
    }

    public static function read_artifact( $request ) {
        $order_id = absint( $request['order'] );
        $order = wc_get_order( $order_id );
        $request_id = sanitize_text_field( $request->get_header( 'x-graph-request-id' ) );
        $task_id = sanitize_text_field( $request->get_header( 'x-ai-task-id' ) );
        $row = $order ? ( self::requests( $order )[ $request_id ] ?? null ) : null;
        if ( ! $row || ! hash_equals( (string) ( $row['task_id'] ?? '' ), $task_id ) ||
            ! in_array( $row['status'] ?? '', array( 'queued', 'running' ), true ) ||
            ! hash_equals( (string) ( $row['base_version_id'] ?? '' ), (string) $request['version'] ) ) {
            return new WP_Error( 'forbidden_artifact', 'Arte no autorizado para esta tarea.', array( 'status' => 403 ) );
        }
        $document = GE_WTP_Documents::find_version( $order_id, $request['version'] );
        if ( ! $document || 'arte' !== ( $document['category'] ?? '' ) ||
            absint( $document['order_item_id'] ?? 0 ) !== absint( $row['order_item_id'] ?? 0 ) ) {
            return new WP_Error( 'not_found', 'Arte no encontrado.', array( 'status' => 404 ) );
        }
        $path = self::document_path( $document );
        if ( ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > self::MAX_BYTES ) {
            return new WP_Error( 'unavailable', 'Arte no disponible.', array( 'status' => 404 ) );
        }
        if ( empty( $row['base_checksum_sha256'] ) || ! hash_equals( $row['base_checksum_sha256'], hash_file( 'sha256', $path ) ) ) { return new WP_Error( 'source_changed', 'El original cambió.', array( 'status' => 409 ) ); }
        nocache_headers();
        header( 'Content-Type: ' . $document['mime'] );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Disposition: attachment; filename="artwork"' );
        readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }

    public static function receive_result( $request ) {
        $order_id = absint( $request->get_param( 'order_id' ) );
        $request_id = sanitize_text_field( $request->get_param( 'request_id' ) );
        $task_id = sanitize_text_field( $request->get_param( 'task_id' ) );
        $order = wc_get_order( $order_id );
        $rows = $order ? self::requests( $order ) : array();
        $row = $rows[ $request_id ] ?? null;
        if ( ! $row || ! preg_match( '/^TASK-\d{8}-[A-Z0-9]{8}$/', $task_id ) ||
            ( ! empty( $row['task_id'] ) && ! hash_equals( (string) $row['task_id'], $task_id ) ) ||
            ( empty( $row['task_id'] ) && 'sending' !== ( $row['status'] ?? '' ) ) ) {
            return new WP_Error( 'invalid_task', 'Tarea no reconocida.', array( 'status' => 403 ) );
        }
        if ( in_array( $row['status'] ?? '', array( 'blocked', 'failed', 'discarded' ), true ) ) { return new WP_Error( 'terminal_task', 'La solicitud está cerrada.', array( 'status' => 409 ) ); }
        $row['task_id'] = $task_id;
        if ( ! empty( $row['candidate_version_id'] ) ) { return rest_ensure_response( array( 'version_id' => $row['candidate_version_id'], 'idempotent' => true ) ); }
        $status = sanitize_key( $request->get_param( 'status' ) );
        if ( in_array( $row['status'] ?? '', array( 'completed', 'blocked', 'failed' ), true ) ) { return new WP_Error( 'terminal_task', 'La tarea está cerrada.', array( 'status' => 409 ) ); }
        if ( 'running' === $status ) {
            $row['status'] = 'running';
            self::save_request( $order, $row );
            return rest_ensure_response( array( 'status' => 'running' ) );
        }
        if ( in_array( $status, array( 'blocked', 'failed' ), true ) ) {
            $row['status'] = $status;
            $row['error'] = sanitize_text_field( $request->get_param( 'message' ) );
            self::save_request( $order, $row );
            return rest_ensure_response( array( 'status' => $status ) );
        }
        if ( 'completed' !== $status ) { return new WP_Error( 'invalid_status', 'Estado inválido.', array( 'status' => 400 ) ); }
        $files = $request->get_file_params();
        $file = $files['candidate'] ?? null;
        if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? -1 ) || ! is_uploaded_file( $file['tmp_name'] ?? '' ) ||
            (int) ( $file['size'] ?? 0 ) < 1 || (int) $file['size'] > self::MAX_BYTES ) {
            return new WP_Error( 'invalid_file', 'Falta el archivo candidato válido.', array( 'status' => 400 ) );
        }
        $base = GE_WTP_Documents::find_version( $order_id, $row['base_version_id'] );
        if ( ! $base || absint( $base['order_item_id'] ?? 0 ) !== absint( $row['order_item_id'] ) ) {
            return new WP_Error( 'invalid_parent', 'La versión base ya no es válida.', array( 'status' => 409 ) );
        }
        $base_path = self::document_path( $base );
        if ( ! is_file( $base_path ) || ! hash_equals( (string) ( $row['base_checksum_sha256'] ?? '' ), hash_file( 'sha256', $base_path ) ) ) { return new WP_Error( 'source_changed', 'El original cambió desde la solicitud.', array( 'status' => 409 ) ); }
        $filename = sanitize_file_name( wp_basename( $file['name'] ) );
        $allowed = array( 'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg' );
        $check = wp_check_filetype_and_ext( $file['tmp_name'], $filename, $allowed );
        $extension = strtolower( $check['ext'] ?? '' );
        $vps = class_exists( 'GE_WTP_VPS_Storage' ) && GE_WTP_VPS_Storage::configured();
        if ( ! isset( $allowed[ $extension ] ) || ( ! $vps && ! GE_WTP_Documents::ensure_private_directory() ) ) {
            return new WP_Error( 'invalid_type', 'Formato no admitido.', array( 'status' => 400 ) );
        }
        $checksum = hash_file( 'sha256', $file['tmp_name'] );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $request->get_param( 'checksum_sha256' ) ) ||
            ! hash_equals( $checksum, (string) $request->get_param( 'checksum_sha256' ) ) ) {
            return new WP_Error( 'checksum_mismatch', 'La huella del archivo no coincide.', array( 'status' => 422 ) );
        }
        $result_pack_id = sanitize_text_field( $request->get_param( 'result_pack_id' ) );
        if ( $result_pack_id !== 'rp_' . strtolower( $task_id ) ) { return new WP_Error( 'invalid_result_pack', 'Result Pack inválido.', array( 'status' => 400 ) ); }
        $lock = 'ge_ai_artwork_import_' . md5( $request_id );
        if ( ! add_option( $lock, time(), '', 'no' ) ) {
            if ( time() - absint( get_option( $lock ) ) > 600 ) { delete_option( $lock ); }
            if ( ! add_option( $lock, time(), '', 'no' ) ) { return new WP_Error( 'import_busy', 'La devolución ya se está importando.', array( 'status' => 409 ) ); }
        }
        $order = wc_get_order( $order_id );
        $fresh = self::requests( $order );
        if ( ! empty( $fresh[ $request_id ]['candidate_version_id'] ) ) { delete_option( $lock ); return rest_ensure_response( array( 'version_id' => $fresh[ $request_id ]['candidate_version_id'], 'idempotent' => true ) ); }
        $rows = $fresh;
        if ( $vps ) {
            $stored = GE_WTP_VPS_Storage::store_order_upload( $file, $order_id, $row['order_item_id'] );
            if ( is_wp_error( $stored ) ) { delete_option( $lock ); return new WP_Error( 'storage_failed', $stored->get_error_message(), array( 'status' => 500 ) ); }
            $stored_name = '';
            $path = GE_WTP_VPS_Storage::download_path( $stored['relative_path'] );
        } else {
            $stored_name = wp_generate_uuid4() . '.' . $extension;
            $path = trailingslashit( GE_WTP_Documents::private_directory() ) . $stored_name;
            if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) { delete_option( $lock ); return new WP_Error( 'storage_failed', 'No se pudo guardar el candidato.', array( 'status' => 500 ) ); }
        }
        if ( ! $path || ! is_file( $path ) ) { delete_option( $lock ); return new WP_Error( 'storage_failed', 'No se pudo verificar el candidato guardado.', array( 'status' => 500 ) ); }
        $version_id = wp_generate_uuid4();
        $record = array( 'id' => $version_id, 'version_id' => $version_id, 'parent_version_id' => $row['base_version_id'],
            'source_type' => 'ai_candidate', 'status' => 'candidate', 'name' => $filename, 'stored_name' => $stored_name,
            'mime' => $allowed[ $extension ], 'size' => filesize( $path ), 'checksum_sha256' => $checksum,
            'category' => 'arte', 'order_item_id' => $row['order_item_id'], 'artwork_side' => $base['artwork_side'] ?? 'general',
            'preflight_status' => 'pending_human_review', 'created_by' => 0, 'created_at' => current_time( 'mysql', true ), 'notes' => $row['instruction'],
            'ai_task_id' => $task_id, 'result_pack_id' => $result_pack_id,
            'analysis' => GE_WTP_Documents::analyze_file( $path, $allowed[ $extension ] ) );
        if ( $vps ) { $record['provider'] = 'vps'; $record['relative_path'] = $stored['relative_path']; }
        $documents = (array) $order->get_meta( GE_WTP_Documents::META_KEY, true );
        $documents[] = $record;
        $order->update_meta_data( GE_WTP_Documents::META_KEY, $documents );
        $row['status'] = 'completed';
        $row['candidate_version_id'] = $version_id;
        $rows[ $request_id ] = $row;
        $order->update_meta_data( self::REQUESTS_META, $rows );
        $order->add_order_note( 'AI-GRUPO devolvió la versión candidata ' . $version_id . ' desde ' . $row['base_version_id'] . '. Pendiente de revisión interna.' );
        $order->save();
        delete_option( $lock );
        return rest_ensure_response( array( 'version_id' => $version_id, 'status' => 'candidate' ) );
    }

}
