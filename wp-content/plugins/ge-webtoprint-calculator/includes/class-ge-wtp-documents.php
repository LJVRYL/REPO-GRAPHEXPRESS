<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Documents {
    const META_KEY = '_ge_markcom_documents';

    public static function init() {
        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_customer_order_files' ), 25 );
        add_action( 'admin_post_ge_customer_order_upload', array( __CLASS__, 'handle_customer_order_upload' ) );
    }

    public static function render_customer_order_files( $order ) {
        if ( ! self::can_access_order( $order ) ) { return; }
        $documents = self::get_documents( $order->get_id() );
        echo '<section class="ge-customer-order-files"><h2>Archivos del pedido</h2>';
        if ( ! $documents ) { echo '<p>Todavía no hay archivos vinculados a este pedido.</p>'; }
        else {
            echo '<ul>';
            foreach ( $documents as $document ) {
                if ( empty( $document['id'] ) || empty( $document['name'] ) ) { continue; }
                echo '<li><a href="' . esc_url( self::download_url( $order->get_id(), $document['id'] ) ) . '">' . esc_html( $document['name'] ) . '</a> · ' . esc_html( size_format( (int) ( $document['size'] ?? 0 ) ) ) . '</li>';
            }
            echo '</ul>';
        }
        if ( 'entregado' !== GE_WTP_Order_Lifecycle::stage( $order ) && ! in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'checkout-draft' ), true ) ) {
            $vps_ready = class_exists( 'GE_WTP_VPS_Storage' ) && GE_WTP_VPS_Storage::ready();
            if ( $vps_ready ) {
                wp_enqueue_script( 'ge-order-files', GE_WTP_PLUGIN_URL . 'assets/js/order-files.js', array(), GE_WTP_VERSION, true );
                wp_localize_script( 'ge-order-files', 'geOrderUpload', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'action' => GE_WTP_VPS_Storage::AJAX_ACTION, 'nonce' => wp_create_nonce( GE_WTP_VPS_Storage::AJAX_ACTION ), 'maxFileBytes' => (int) GE_WTP_VPS_Storage::limits()['max_file_bytes'] ) );
            }
            echo '<form method="post" enctype="multipart/form-data" data-ge-order-upload="' . esc_attr( $vps_ready ? '1' : '0' ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_customer_order_upload"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="ge_vps_uploads" value="[]">';
            wp_nonce_field( 'ge_customer_order_upload_' . $order->get_id() );
            echo '<label>Adjuntar original al pedido <input type="file" name="ge_documents[]" accept=".pdf,.ai,.eps,.psd,.tif,.tiff,.svg,.cdr,.zip,.jpg,.jpeg,.png" required></label><button type="submit">Subir archivo</button><progress max="100" value="0" hidden aria-label="Progreso de carga"></progress><p role="status" aria-live="polite"></p></form>';
            echo '<p>Máximo 250 MB por archivo. Para archivos más grandes, vinculá un enlace compartido desde <a href="' . esc_url( wc_get_account_endpoint_url( GE_WTP_Artwork_Library::ENDPOINT ) ) . '">Mis archivos</a>.</p>';
        }
        echo '</section>';
    }

    public static function handle_customer_order_upload() {
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        check_admin_referer( 'ge_customer_order_upload_' . $order_id );
        $order = wc_get_order( $order_id );
        if ( ! self::can_access_order( $order ) || 'entregado' === GE_WTP_Order_Lifecycle::stage( $order ) || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'checkout-draft' ), true ) ) { wp_die( 'No podés adjuntar archivos a este pedido.', 403 ); }
        $claims = isset( $_POST['ge_vps_uploads'] ) ? json_decode( wp_unslash( $_POST['ge_vps_uploads'] ), true ) : array();
        $saved = array();
        if ( is_array( $claims ) && $claims && class_exists( 'GE_WTP_VPS_Storage' ) ) {
            $tokens = wp_list_pluck( $claims, 'token' );
            $uploads = GE_WTP_VPS_Storage::validate_uploaded_claims( $tokens, get_current_user_id() );
            if ( ! is_wp_error( $uploads ) ) {
                $documents = self::get_documents( $order_id );
                foreach ( $uploads as $upload ) {
                    $final = GE_WTP_VPS_Storage::finalize_descriptor( $upload, $order_id, 0 );
                    if ( is_wp_error( $final ) ) { continue; }
                    $documents[] = array( 'id' => wp_generate_uuid4(), 'provider' => 'vps', 'relative_path' => $final['relative_path'], 'name' => $final['name'], 'size' => $final['size'], 'mime' => $final['mime'], 'category' => 'arte', 'uploaded_by' => get_current_user_id(), 'uploaded_at' => current_time( 'mysql' ), 'analysis' => array( 'confidence' => 'pending', 'warning' => 'Pendiente de control de preprensa.' ) );
                    $saved[] = $final;
                }
                if ( $saved ) { $order->update_meta_data( self::META_KEY, $documents ); $order->save(); }
            }
        } else {
            $saved = self::handle_uploaded_files( $order_id, 'ge_documents', 'arte' );
        }
        $ok = is_array( $saved ) && ! empty( $saved );
        wc_add_notice( $ok ? 'Archivo vinculado al pedido.' : 'No se pudo cargar el archivo. Revisá el formato y el tamaño.', $ok ? 'success' : 'error' );
        wp_safe_redirect( $order->get_view_order_url() );
        exit;
    }

    public static function private_directory() {
        return WP_CONTENT_DIR . '/ge-private/markcom';
    }

    public static function ensure_private_directory() {
        $directory = self::private_directory();
        if ( ! is_dir( $directory ) ) {
            wp_mkdir_p( $directory );
        }

        if ( is_dir( $directory ) ) {
            $htaccess = $directory . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }

            $index = $directory . '/index.php';
            if ( ! file_exists( $index ) ) {
                file_put_contents( $index, "<?php\nhttp_response_code( 404 );\nexit;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }
        }

        return is_dir( $directory ) && is_writable( $directory );
    }

    public static function categories() {
        return array(
            'arte'        => 'Arte / original',
            'po'          => 'Orden de compra',
            'factura'     => 'Factura',
            'nota_credito' => 'Nota de crédito',
            'nota_debito' => 'Nota de débito',
            'presupuesto_emitido' => 'Presupuesto PDF emitido',
            'comprobante' => 'Comprobante de pago',
            'remito'      => 'Remito',
            'produccion'  => 'Producción / entrega',
            'otro'        => 'Otro documento',
        );
    }

    public static function upload_categories() {
        $categories = self::categories();
        foreach ( array( 'factura', 'nota_credito', 'nota_debito', 'presupuesto_emitido' ) as $issued ) { unset( $categories[ $issued ] ); }
        return $categories;
    }

    public static function handle_uploaded_files( $order_id, $field = 'ge_documents', $category = 'arte', $context = array() ) {
        if ( empty( $_FILES[ $field ] ) || empty( $_FILES[ $field ]['name'] ) ) {
            return array();
        }

        $vps_storage = class_exists( 'GE_WTP_VPS_Storage' ) && GE_WTP_VPS_Storage::configured();
        if ( ! $vps_storage && ! self::ensure_private_directory() ) {
            return new WP_Error( 'ge_storage_unavailable', 'No fue posible preparar el almacenamiento privado.' );
        }

        $files = self::normalize_files_array( $_FILES[ $field ] );
        $saved = array();
        $allowed = array(
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'zip'  => 'application/zip',
        );
        if ( 'arte' === $category ) {
            $allowed += array( 'ai' => 'application/postscript', 'eps' => 'application/postscript', 'psd' => 'image/vnd.adobe.photoshop', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'svg' => 'image/svg+xml', 'cdr' => 'application/octet-stream' );
        }
        if ( ! empty( $context['allowed_extensions'] ) && is_array( $context['allowed_extensions'] ) ) {
            $allowed = array_intersect_key( $allowed, array_fill_keys( array_map( 'sanitize_key', $context['allowed_extensions'] ), true ) );
        }
        foreach ( $files as $file ) {
            if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
                continue;
            }
            if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > ( $vps_storage ? GE_WTP_VPS_Storage::limits()['max_file_bytes'] : 1024 * MB_IN_BYTES ) ) {
                continue;
            }

            $original = sanitize_file_name( wp_basename( $file['name'] ) );
            $check = wp_check_filetype_and_ext( $file['tmp_name'], $original, $allowed );
            $extension = ! empty( $check['ext'] ) ? strtolower( $check['ext'] ) : '';
            if ( ! isset( $allowed[ $extension ] ) ) {
                continue;
            }

            if ( $vps_storage ) {
                $stored = GE_WTP_VPS_Storage::store_order_upload( $file, $order_id, $context['order_item_id'] ?? 0 );
                if ( is_wp_error( $stored ) ) { continue; }
                $analysis = array( 'confidence' => 'pending', 'warning' => 'Pendiente de control de preprensa.' );
            } else {
                $stored_name = wp_generate_uuid4() . '.' . $extension;
                $destination = trailingslashit( self::private_directory() ) . $stored_name;
                if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) { continue; }
                $analysis = self::analyze_file( $destination, $allowed[ $extension ] );
            }
            $record = array(
                'id'          => wp_generate_uuid4(),
                'stored_name' => $vps_storage ? '' : $stored_name,
                'name'        => $original,
                'mime'        => $allowed[ $extension ],
                'size'        => (int) $file['size'],
                'category'    => isset( self::categories()[ $category ] ) ? $category : 'otro',
                'uploaded_by' => get_current_user_id(),
                'uploaded_at' => current_time( 'mysql' ),
                'analysis'    => $analysis,
            );
            if ( $vps_storage ) { $record['provider'] = 'vps'; $record['relative_path'] = $stored['relative_path']; }
            if ( ! empty( $context['order_item_id'] ) ) {
                $record['order_item_id'] = absint( $context['order_item_id'] );
            }
            if ( ! empty( $context['artwork_side'] ) ) {
                $record['artwork_side'] = sanitize_key( $context['artwork_side'] );
            }
            if ( ! empty( $context['issued_document'] ) ) {
                $record['issued_by_graphex'] = true;
                $record['document_number'] = sanitize_text_field( $context['document_number'] ?? '' );
                $record['issue_date'] = sanitize_text_field( $context['issue_date'] ?? '' );
                $record['replaces_id'] = sanitize_text_field( $context['replaces_id'] ?? '' );
                $record['billing_profile_snapshot'] = $context['billing_profile_snapshot'] ?? array();
            }
            $saved[] = $record;
        }

        if ( $saved ) {
            $documents = self::get_documents( $order_id );
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
            if ( $order ) {
                $order->update_meta_data( self::META_KEY, array_merge( $documents, $saved ) );
                $order->save();
            }
        }

        return $saved;
    }

    private static function normalize_files_array( $input ) {
        if ( ! is_array( $input['name'] ) ) {
            return array( $input );
        }

        $result = array();
        foreach ( $input['name'] as $index => $name ) {
            $result[] = array(
                'name'     => $name,
                'type'     => isset( $input['type'][ $index ] ) ? $input['type'][ $index ] : '',
                'tmp_name' => isset( $input['tmp_name'][ $index ] ) ? $input['tmp_name'][ $index ] : '',
                'error'    => isset( $input['error'][ $index ] ) ? $input['error'][ $index ] : UPLOAD_ERR_NO_FILE,
                'size'     => isset( $input['size'][ $index ] ) ? $input['size'][ $index ] : 0,
            );
        }

        return $result;
    }

    public static function get_documents( $order_id ) {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
        $documents = $order ? $order->get_meta( self::META_KEY, true ) : array();
        return is_array( $documents ) ? $documents : array();
    }

    public static function issued_documents( $order_id, $include_history = false ) {
        return array_values( array_filter( self::get_documents( $order_id ), function ( $document ) use ( $include_history ) {
            return ! empty( $document['issued_by_graphex'] ) && ( $include_history || empty( $document['superseded_at'] ) );
        } ) );
    }

    /** Keep every fiscal file immutable. A replacement only supersedes its predecessor. */
    public static function attach_issued( $order_id, $type, $number, $issue_date, $replaces_id = '' ) {
        $types = array( 'factura', 'nota_credito', 'nota_debito', 'presupuesto_emitido', 'otro' );
        if ( ! in_array( $type, $types, true ) ) { return new WP_Error( 'ge_issued_type', 'Tipo de documento inválido.' ); }
        if ( $issue_date && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue_date ) || gmdate( 'Y-m-d', strtotime( $issue_date ) ) !== $issue_date ) ) {
            return new WP_Error( 'ge_issued_date', 'Fecha de emisión inválida.' );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return new WP_Error( 'ge_issued_order', 'Pedido inválido.' ); }
        $documents = self::get_documents( $order_id );
        $previous = null;
        if ( $replaces_id ) {
            foreach ( $documents as $document ) {
                if ( ( $document['id'] ?? '' ) === $replaces_id && ! empty( $document['issued_by_graphex'] ) && empty( $document['superseded_at'] ) ) { $previous = $document; break; }
            }
            if ( ! $previous || ( $previous['category'] ?? '' ) !== $type ) { return new WP_Error( 'ge_issued_version', 'La versión anterior no corresponde a este documento.' ); }
        }
        $files = $_FILES['ge_issued_document'] ?? array();
        if ( is_array( $files['name'] ?? null ) || empty( $files['name'] ) ) { return new WP_Error( 'ge_issued_file', 'Seleccioná un solo PDF.' ); }
        $snapshot = $order->get_meta( '_ge_billing_profile_snapshot', true );
        $saved = self::handle_uploaded_files( $order_id, 'ge_issued_document', $type, array(
            'allowed_extensions' => array( 'pdf' ), 'issued_document' => true,
            'document_number' => $number, 'issue_date' => $issue_date, 'replaces_id' => $replaces_id,
            'billing_profile_snapshot' => is_array( $snapshot ) ? $snapshot : array(),
        ) );
        if ( is_wp_error( $saved ) ) { return $saved; }
        if ( count( $saved ) !== 1 ) { return new WP_Error( 'ge_issued_upload', 'No se pudo guardar el PDF.' ); }
        if ( $previous ) {
            $documents = self::get_documents( $order_id );
            foreach ( $documents as &$document ) {
                if ( ( $document['id'] ?? '' ) === $replaces_id ) {
                    $document['superseded_at'] = current_time( 'mysql' );
                    $document['superseded_by'] = $saved[0]['id'];
                    break;
                }
            }
            unset( $document );
            $order->update_meta_data( self::META_KEY, $documents );
            $order->save();
        }
        $order->add_order_note( sprintf( 'Documento emitido %s %s cargado por usuario #%d. Archivo #%s.', $type, $number, get_current_user_id(), $saved[0]['id'] ) );
        return $saved[0];
    }

    public static function get_documents_with_analysis( $order_id ) {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
        if ( ! $order ) { return array(); }
        $documents = self::get_documents( $order_id );
        $changed = false;
        foreach ( $documents as $index => $document ) {
            if ( ! empty( $document['analysis'] ) || empty( $document['stored_name'] ) ) { continue; }
            $path = trailingslashit( self::private_directory() ) . wp_basename( $document['stored_name'] );
            if ( ! is_file( $path ) ) { continue; }
            $documents[ $index ]['analysis'] = self::analyze_file( $path, $document['mime'] ?? '' );
            $changed = true;
        }
        if ( $changed ) {
            $order->update_meta_data( self::META_KEY, $documents );
            $order->save();
        }
        return $documents;
    }

    public static function analyze_file( $path, $mime ) {
        $analysis = array(
            'sha256'      => is_file( $path ) ? hash_file( 'sha256', $path ) : '',
            'pages'       => 0,
            'width'       => 0,
            'height'      => 0,
            'unit'        => '',
            'orientation' => '',
            'confidence'  => 'basic',
            'warning'     => '',
        );
        if ( 0 === strpos( (string) $mime, 'image/' ) ) {
            $size = @getimagesize( $path );
            if ( $size ) {
                $analysis['pages'] = 1;
                $analysis['width'] = absint( $size[0] );
                $analysis['height'] = absint( $size[1] );
                $analysis['unit'] = 'px';
                $analysis['orientation'] = $size[0] === $size[1] ? 'cuadrado' : ( $size[0] > $size[1] ? 'horizontal' : 'vertical' );
                $analysis['confidence'] = 'high';
            }
            return $analysis;
        }
        if ( 'application/pdf' !== $mime ) { $analysis['warning'] = 'Formato sin análisis interno automático.'; return $analysis; }
        $handle = @fopen( $path, 'rb' );
        if ( ! $handle ) { $analysis['warning'] = 'No se pudo leer el PDF.'; return $analysis; }
        $carry = ''; $read = 0; $limit = 256 * MB_IN_BYTES; $media_box = array();
        while ( ! feof( $handle ) && $read < $limit ) {
            $chunk = fread( $handle, min( MB_IN_BYTES, $limit - $read ) );
            if ( false === $chunk || '' === $chunk ) { break; }
            $read += strlen( $chunk ); $carry_length = strlen( $carry ); $scan = $carry . $chunk;
            if ( preg_match_all( '/\/Type\s*\/Page\b/', $scan, $matches, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $matches[0] as $match ) { if ( $match[1] + strlen( $match[0] ) > $carry_length ) { $analysis['pages']++; } }
            }
            if ( ! $media_box && preg_match( '/\/MediaBox\s*\[\s*[-0-9.]+\s+[-0-9.]+\s+([-0-9.]+)\s+([-0-9.]+)\s*\]/', $scan, $box ) ) { $media_box = array( (float) $box[1], (float) $box[2] ); }
            $carry = substr( $scan, -256 );
        }
        $truncated = ! feof( $handle ); fclose( $handle );
        if ( $media_box ) {
            $analysis['width'] = round( $media_box[0] * 25.4 / 72, 1 );
            $analysis['height'] = round( $media_box[1] * 25.4 / 72, 1 );
            $analysis['unit'] = 'mm';
            $analysis['orientation'] = abs( $media_box[0] - $media_box[1] ) < 0.1 ? 'cuadrado' : ( $media_box[0] > $media_box[1] ? 'horizontal' : 'vertical' );
        }
        $analysis['confidence'] = $analysis['pages'] && $media_box && ! $truncated ? 'medium' : 'basic';
        if ( ! $analysis['pages'] || ! $media_box ) { $analysis['warning'] = 'El PDF necesita control visual: parte de sus datos internos no pudo verificarse.'; }
        elseif ( $truncated ) { $analysis['warning'] = 'PDF muy pesado: el análisis se limitó a los primeros 256 MB.'; }
        return $analysis;
    }

    public static function can_access_order( $order ) {
        if ( ! $order || ! is_user_logged_in() ) {
            return false;
        }

        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'ge_manage_operations' ) || (int) $order->get_customer_id() === get_current_user_id() ) {
            return true;
        }

        $user = wp_get_current_user();
        return 0 === (int) $order->get_customer_id()
            && $user->exists()
            && $order->get_billing_email()
            && 0 === strcasecmp( $order->get_billing_email(), $user->user_email );
    }

    public static function download_url( $order_id, $document_id, $inline = false ) {
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=ge_markcom_download_document&order_id=' . absint( $order_id ) . '&document_id=' . rawurlencode( $document_id ) ),
            'ge_markcom_download_' . absint( $order_id ) . '_' . $document_id
        );
        return $inline ? add_query_arg( 'inline', '1', $url ) : $url;
    }

    public static function handle_download() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $document_id = isset( $_GET['document_id'] ) ? sanitize_text_field( wp_unslash( $_GET['document_id'] ) ) : '';
        check_admin_referer( 'ge_markcom_download_' . $order_id . '_' . $document_id );

        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
        if ( ! self::can_access_order( $order ) ) {
            wp_die( 'No tenés permiso para descargar este documento.', 403 );
        }

        foreach ( self::get_documents( $order_id ) as $document ) {
            if ( isset( $document['id'] ) && hash_equals( (string) $document['id'], $document_id ) ) {
                if ( ! empty( $document['superseded_at'] ) && ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'ge_manage_operations' ) ) { wp_die( 'Esta versión fue reemplazada.', 403 ); }
                self::stream_record( $document, ! empty( $_GET['inline'] ) );
            }
        }

        wp_die( 'El documento solicitado no existe.', 404 );
    }

    /** Streams one already-authorized document. Callers must enforce access first. */
    public static function stream_record( $document, $inline = false ) {
        $provider = $document['provider'] ?? 'local';
        if ( 'vps' === $provider ) {
            if ( empty( $document['relative_path'] ) || ! class_exists( 'GE_WTP_VPS_Storage' ) ) { wp_die( 'El almacenamiento privado no está disponible.', 503 ); }
            $path = GE_WTP_VPS_Storage::download_path( $document['relative_path'] );
        } elseif ( 'r2' === $provider ) {
            if ( empty( $document['object_key'] ) || ! class_exists( 'GE_WTP_R2_Storage' ) || ! GE_WTP_R2_Storage::configured() ) { wp_die( 'El almacenamiento privado no está disponible.', 503 ); }
            $url = GE_WTP_R2_Storage::download_url( $document['object_key'], $document['name'] ?? 'archivo', $document['mime'] ?? 'application/octet-stream', 300 );
            nocache_headers();
            wp_redirect( esc_url_raw( $url ), 302, 'Graph Express' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
            exit;
        } else {
            $path = trailingslashit( self::private_directory() ) . wp_basename( $document['stored_name'] ?? '' );
        }
        if ( ! $path || ! is_file( $path ) ) { wp_die( 'El documento solicitado no existe.', 404 ); }
        $mime = sanitize_text_field( $document['mime'] ?? 'application/octet-stream' );
        $name = sanitize_file_name( $document['name'] ?? 'archivo' );
        $inline = $inline && ( 'application/pdf' === $mime || 0 === strpos( $mime, 'image/' ) );
        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . rawurlencode( $name ) . '"' );
        readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }
}
