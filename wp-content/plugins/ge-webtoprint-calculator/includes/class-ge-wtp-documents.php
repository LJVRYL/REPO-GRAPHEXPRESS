<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Documents {
    const META_KEY = '_ge_markcom_documents';

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
            'comprobante' => 'Comprobante de pago',
            'remito'      => 'Remito',
            'produccion'  => 'Producción / entrega',
            'otro'        => 'Otro documento',
        );
    }

    public static function handle_uploaded_files( $order_id, $field = 'ge_documents', $category = 'arte' ) {
        if ( empty( $_FILES[ $field ] ) || empty( $_FILES[ $field ]['name'] ) ) {
            return array();
        }

        if ( ! self::ensure_private_directory() ) {
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

        foreach ( $files as $file ) {
            if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
                continue;
            }
            if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 1024 * MB_IN_BYTES ) {
                continue;
            }

            $original = sanitize_file_name( wp_basename( $file['name'] ) );
            $check = wp_check_filetype_and_ext( $file['tmp_name'], $original, $allowed );
            $extension = ! empty( $check['ext'] ) ? strtolower( $check['ext'] ) : '';
            if ( ! isset( $allowed[ $extension ] ) ) {
                continue;
            }

            $stored_name = wp_generate_uuid4() . '.' . $extension;
            $destination = trailingslashit( self::private_directory() ) . $stored_name;
            if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
                continue;
            }

            $saved[] = array(
                'id'          => wp_generate_uuid4(),
                'stored_name' => $stored_name,
                'name'        => $original,
                'mime'        => $allowed[ $extension ],
                'size'        => (int) $file['size'],
                'category'    => isset( self::categories()[ $category ] ) ? $category : 'otro',
                'uploaded_by' => get_current_user_id(),
                'uploaded_at' => current_time( 'mysql' ),
                'analysis'    => self::analyze_file( $destination, $allowed[ $extension ] ),
            );
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

    public static function download_url( $order_id, $document_id ) {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=ge_markcom_download_document&order_id=' . absint( $order_id ) . '&document_id=' . rawurlencode( $document_id ) ),
            'ge_markcom_download_' . absint( $order_id ) . '_' . $document_id
        );
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
                $path = trailingslashit( self::private_directory() ) . wp_basename( $document['stored_name'] );
                if ( ! is_file( $path ) ) {
                    break;
                }

                nocache_headers();
                header( 'Content-Type: ' . $document['mime'] );
                header( 'Content-Length: ' . filesize( $path ) );
                header( 'Content-Disposition: attachment; filename="' . rawurlencode( $document['name'] ) . '"' );
                readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
                exit;
            }
        }

        wp_die( 'El documento solicitado no existe.', 404 );
    }
}
