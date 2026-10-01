<?php

defined( 'ABSPATH' ) || exit;

/** Private artwork attached to a commercial quote before an order exists. */
final class GE_WTP_Commercial_Quote_Files {
    const META = '_ge_commercial_artwork_files';
    const RECEIPT_META = '_ge_commercial_receipt_files';

    public static function init() {
        add_action( 'admin_post_ge_commercial_quote_file', array( __CLASS__, 'handle_upload' ) );
        add_action( 'admin_post_ge_commercial_quote_receipt', array( __CLASS__, 'handle_receipt' ) );
        add_action( 'admin_post_ge_commercial_quote_file_download', array( __CLASS__, 'handle_download' ) );
    }

    public static function all( $quote_id, $category = 'arte' ) {
        $files = get_post_meta( absint( $quote_id ), 'comprobante' === $category ? self::RECEIPT_META : self::META, true );
        return is_array( $files ) ? $files : array();
    }

    public static function upload( $quote_id, $file, $source_type, $actor_id, $category = 'arte' ) {
        $quote = GE_WTP_Commercial_Quotes::get( $quote_id, $actor_id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        $staff = GE_WTP_Staff_Portal::can_access();
        if ( ! $staff && (int) $actor_id !== (int) $quote['customer_id'] ) { return new WP_Error( 'ge_quote_file_access', 'Acceso denegado.' ); }
        if ( ! $staff && in_array( $quote['status'], array( 'draft', 'rejected', 'cancelled' ), true ) ) { return new WP_Error( 'ge_quote_file_state', 'No se pueden adjuntar archivos a este presupuesto.' ); }
        if ( ! is_array( $file ) || empty( $file['name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $file['tmp_name'] ?? '' ) ) { return new WP_Error( 'ge_quote_file_missing', 'Seleccioná un archivo válido.' ); }
        $size = (int) ( $file['size'] ?? 0 );
        if ( $size < 1 || $size > ( 'comprobante' === $category ? 20 : 250 ) * MB_IN_BYTES || count( self::all( $quote_id, $category ) ) >= 30 ) { return new WP_Error( 'ge_quote_file_limit', 'Revisá el tamaño o la cantidad de archivos.' ); }
        $name = sanitize_file_name( wp_basename( $file['name'] ) );
        $allowed = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'ai' => 'application/postscript', 'eps' => 'application/postscript', 'psd' => 'image/vnd.adobe.photoshop', 'zip' => 'application/zip' );
        if ( 'comprobante' === $category ) { $allowed = array_intersect_key( $allowed, array_flip( array( 'pdf', 'jpg', 'jpeg', 'png' ) ) ); }
        $checked = wp_check_filetype_and_ext( $file['tmp_name'], $name, $allowed );
        $extension = strtolower( (string) ( $checked['ext'] ?? '' ) );
        if ( ! isset( $allowed[ $extension ] ) ) { return new WP_Error( 'ge_quote_file_type', 'Formato de archivo no permitido.' ); }
        if ( ! GE_WTP_Documents::ensure_private_directory() ) { return new WP_Error( 'ge_quote_file_storage', 'El almacenamiento privado no está disponible.' ); }
        $stored_name = wp_generate_uuid4() . '.' . $extension;
        $path = trailingslashit( GE_WTP_Documents::private_directory() ) . $stored_name;
        if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) { return new WP_Error( 'ge_quote_file_storage', 'No se pudo guardar el archivo.' ); }
        @chmod( $path, 0600 );
        $analysis = GE_WTP_Documents::analyze_file( $path, $allowed[ $extension ] );
        $analysis['resolution_dpi'] = null;
        $analysis['colorspace'] = 'unverified';
        $analysis['preflight_state'] = ! empty( $analysis['warning'] ) ? 'warning' : 'unverified';
        $analysis['warnings'] = ! empty( $analysis['warning'] ) ? array( $analysis['warning'] ) : array();
        $analysis['blockers'] = array();
        $analysis['unverified'] = array( 'colorspace', 'resolution_dpi', 'bleed', 'cut_path' );
        $source_type = 'comprobante' === $category ? 'payment_proof' : ( in_array( $source_type, array( 'preliminary', 'final' ), true ) ? $source_type : 'preliminary' );
        $record = array( 'id' => wp_generate_uuid4(), 'stored_name' => $stored_name, 'name' => $name, 'mime' => $allowed[ $extension ], 'size' => $size, 'category' => 'comprobante' === $category ? 'comprobante' : 'arte', 'source_type' => $source_type, 'uploaded_by' => (int) $actor_id, 'uploaded_at' => gmdate( 'c' ), 'analysis' => $analysis );
        $files = self::all( $quote_id, $category );
        $files[] = $record;
        update_post_meta( $quote_id, 'comprobante' === $category ? self::RECEIPT_META : self::META, $files );
        GE_WTP_Commercial_Quotes::record_event( $quote_id, 'comprobante' === $category ? 'receipt_uploaded' : 'file_uploaded', $actor_id, array( 'file_id' => $record['id'], 'source_type' => $source_type, 'sha256' => $analysis['sha256'] ) );
        if ( 'comprobante' === $category ) {
            $payment_id = absint( get_post_meta( $quote_id, '_ge_commercial_initial_payment_order', true ) );
            if ( $payment_id ) { self::link_receipts_to_payment( $quote_id, wc_get_order( $payment_id ) ); }
        } elseif ( ! empty( $quote['converted_order_id'] ) ) { self::inherit( $quote_id, wc_get_order( $quote['converted_order_id'] ) ); }
        return $record;
    }

    public static function inherit( $quote_id, $order ) {
        if ( ! $order instanceof WC_Order ) { return; }
        $documents = GE_WTP_Documents::get_documents( $order->get_id() );
        $ids = array_column( $documents, 'id' );
        $added = false;
        foreach ( self::all( $quote_id ) as $file ) {
            if ( empty( $file['id'] ) || in_array( $file['id'], $ids, true ) ) { continue; }
            $file['source_quote_id'] = (int) $quote_id;
            $documents[] = $file;
            $added = true;
        }
        if ( ! $added ) { return; }
        $order->update_meta_data( GE_WTP_Documents::META_KEY, $documents );
        $order->save();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $item->delete_meta_data( '_ge_item_artwork_customer_approval' );
            $item->delete_meta_data( '_ge_item_artwork_staff_approval' );
            $item->delete_meta_data( '_ge_item_artwork_release_hash' );
            $item->delete_meta_data( '_ge_item_artwork_released_at' );
            $item->delete_meta_data( '_ge_item_artwork_released_by' );
            $item->save();
        }
        $order->add_order_note( 'Nuevo archivo heredado del presupuesto; revisar versión vigente y solicitar nueva aprobación de arte antes de liberar.' );
        $order->save();
    }

    public static function link_receipts_to_payment( $quote_id, $payment ) {
        if ( ! $payment instanceof WC_Order ) { return; }
        $documents = GE_WTP_Documents::get_documents( $payment->get_id() );
        $ids = array_column( $documents, 'id' );
        foreach ( self::all( $quote_id, 'comprobante' ) as $receipt ) {
            if ( empty( $receipt['id'] ) || in_array( $receipt['id'], $ids, true ) ) { continue; }
            $receipt['source_quote_id'] = (int) $quote_id;
            $documents[] = $receipt;
        }
        $payment->update_meta_data( GE_WTP_Documents::META_KEY, $documents );
        $payment->update_meta_data( '_ge_commercial_receipt_state', $documents ? 'uploaded' : 'none' );
        $payment->save();
    }

    /** Reuse a private artwork file from a prior order of the same customer. */
    public static function attach_from_order( $quote_id, $source_order_id, $file_id, $source_type, $actor_id ) {
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) { return new WP_Error( 'ge_quote_forbidden', 'Acceso denegado.' ); }
        $quote = GE_WTP_Commercial_Quotes::get( $quote_id, $actor_id );
        $source = wc_get_order( absint( $source_order_id ) );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( ! $source || (int) $source->get_customer_id() !== (int) $quote['customer_id'] ) { return new WP_Error( 'ge_quote_file_source', 'El pedido origen no corresponde al cliente.' ); }
        foreach ( self::all( $quote_id ) as $existing ) { if ( ( $existing['id'] ?? '' ) === $file_id ) { return $existing; } }
        foreach ( GE_WTP_Documents::get_documents( $source->get_id() ) as $document ) {
            if ( ( $document['id'] ?? '' ) !== $file_id ) { continue; }
            if ( 'arte' !== ( $document['category'] ?? '' ) || ! empty( $document['provider'] ) || empty( $document['stored_name'] ) ) { return new WP_Error( 'ge_quote_file_source', 'El archivo no puede reutilizarse desde este almacenamiento.' ); }
            $path = trailingslashit( GE_WTP_Documents::private_directory() ) . wp_basename( $document['stored_name'] );
            if ( ! is_file( $path ) ) { return new WP_Error( 'ge_quote_file_missing', 'El original privado no está disponible.' ); }
            $document['source_type'] = 'final' === $source_type ? 'final' : 'preliminary';
            $document['source_order_id'] = $source->get_id();
            $files = self::all( $quote_id ); $files[] = $document;
            update_post_meta( $quote_id, self::META, $files );
            GE_WTP_Commercial_Quotes::record_event( $quote_id, 'file_attached', $actor_id, array( 'file_id' => $file_id, 'source_order_id' => $source->get_id() ) );
            if ( $quote['converted_order_id'] ) { self::inherit( $quote_id, wc_get_order( $quote['converted_order_id'] ) ); }
            return $document;
        }
        return new WP_Error( 'ge_quote_file_missing', 'Archivo no encontrado en el pedido origen.' );
    }

    public static function attach_receipt_to_payment( $quote_id, $payment_order_id, $file_id, $actor_id ) {
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) { return new WP_Error( 'ge_quote_forbidden', 'Acceso denegado.' ); }
        $payment = wc_get_order( absint( $payment_order_id ) );
        if ( ! $payment || 'yes' !== $payment->get_meta( '_ge_commercial_payment_order', true ) || (int) $payment->get_meta( '_ge_commercial_quote_id', true ) !== (int) $quote_id ) { return new WP_Error( 'ge_receipt_payment', 'Cobro no vinculado al presupuesto.' ); }
        $receipt = null;
        foreach ( self::all( $quote_id, 'comprobante' ) as $candidate ) { if ( ( $candidate['id'] ?? '' ) === $file_id ) { $receipt = $candidate; break; } }
        if ( ! $receipt ) { return new WP_Error( 'ge_receipt_missing', 'Comprobante no encontrado.' ); }
        $documents = GE_WTP_Documents::get_documents( $payment->get_id() );
        foreach ( $documents as $document ) { if ( ( $document['id'] ?? '' ) === $file_id ) { return $receipt; } }
        $documents[] = $receipt;
        $payment->update_meta_data( GE_WTP_Documents::META_KEY, $documents );
        $payment->update_meta_data( '_ge_commercial_receipt_state', 'uploaded' );
        $payment->save();
        GE_WTP_Commercial_Quotes::record_event( $quote_id, 'receipt_attached', $actor_id, array( 'file_id' => $file_id, 'payment_order_id' => $payment_order_id ) );
        return $receipt;
    }

    /** Assign the exact inherited file to one item; this never grants artwork approval. */
    public static function attach_to_item( $order_id, $order_item_id, $file_id, $actor_id ) {
        if ( ! user_can( $actor_id, 'ge_manage_operations' ) && ! user_can( $actor_id, 'manage_woocommerce' ) ) { return new WP_Error( 'ge_quote_forbidden', 'Acceso denegado.' ); }
        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order || ! $order->get_meta( '_ge_commercial_quote_id', true ) ) { return new WP_Error( 'ge_artwork_order', 'Pedido derivado de presupuesto no encontrado.' ); }
        $item = $order->get_item( absint( $order_item_id ) );
        if ( ! $item || ! ( $item instanceof WC_Order_Item_Product ) ) { return new WP_Error( 'ge_artwork_item', 'Ítem de pedido no encontrado.' ); }
        $documents = GE_WTP_Documents::get_documents( $order->get_id() );
        $found = false; $changed = false;
        foreach ( $documents as &$document ) {
            if ( ( $document['id'] ?? '' ) !== $file_id || 'arte' !== ( $document['category'] ?? '' ) ) { continue; }
            $found = true;
            if ( ! empty( $document['order_item_id'] ) && (int) $document['order_item_id'] !== $item->get_id() ) {
                unset( $document );
                return new WP_Error( 'ge_artwork_assigned', 'El archivo ya está asignado a otro ítem.' );
            }
            if ( (int) ( $document['order_item_id'] ?? 0 ) !== $item->get_id() ) { $document['order_item_id'] = $item->get_id(); $document['artwork_side'] = 'general'; $changed = true; }
            break;
        }
        unset( $document );
        if ( ! $found ) { return new WP_Error( 'ge_artwork_file', 'Archivo de arte no encontrado en el pedido.' ); }
        $token = 'document:' . $file_id;
        $tokens = (array) $item->get_meta( '_ge_item_artwork_sources', true );
        if ( ! in_array( $token, $tokens, true ) ) { $tokens[] = $token; $item->update_meta_data( '_ge_item_artwork_sources', $tokens ); $changed = true; }
        if ( ! $changed ) { return array( 'order_id' => $order->get_id(), 'order_item_id' => $item->get_id(), 'file_id' => $file_id ); }
        $item->update_meta_data( '_ge_item_artwork_version', 'Archivo ' . substr( $file_id, 0, 8 ) );
        foreach ( array( '_ge_item_artwork_customer_approval', '_ge_item_artwork_staff_approval', '_ge_item_artwork_release_hash', '_ge_item_artwork_released_at', '_ge_item_artwork_released_by' ) as $key ) { $item->delete_meta_data( $key ); }
        $item->save();
        $order->update_meta_data( GE_WTP_Documents::META_KEY, $documents );
        $order->add_order_note( 'Archivo de presupuesto asignado al ítem #' . $item->get_id() . ' por usuario #' . $actor_id . '; aprobación de arte pendiente.' );
        $order->save();
        return array( 'order_id' => $order->get_id(), 'order_item_id' => $item->get_id(), 'file_id' => $file_id );
    }

    public static function download_url( $quote_id, $file_id, $inline = false, $category = 'arte' ) {
        return wp_nonce_url( add_query_arg( array( 'action' => 'ge_commercial_quote_file_download', 'quote_id' => absint( $quote_id ), 'file_id' => sanitize_text_field( $file_id ), 'inline' => $inline ? 1 : 0, 'category' => 'comprobante' === $category ? 'comprobante' : 'arte' ), admin_url( 'admin-post.php' ) ), 'ge_commercial_quote_file_download_' . $quote_id . '_' . $file_id );
    }

    public static function handle_download() {
        $quote_id = absint( $_GET['quote_id'] ?? 0 );
        $file_id = sanitize_text_field( wp_unslash( $_GET['file_id'] ?? '' ) );
        check_admin_referer( 'ge_commercial_quote_file_download_' . $quote_id . '_' . $file_id );
        if ( ! is_user_logged_in() || is_wp_error( GE_WTP_Commercial_Quotes::get( $quote_id, get_current_user_id() ) ) ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $category = sanitize_key( wp_unslash( $_GET['category'] ?? 'arte' ) );
        foreach ( self::all( $quote_id, $category ) as $file ) {
            if ( $file_id !== ( $file['id'] ?? '' ) ) { continue; }
            $path = trailingslashit( GE_WTP_Documents::private_directory() ) . wp_basename( $file['stored_name'] ?? '' );
            if ( ! is_file( $path ) ) { break; }
            $inline = ! empty( $_GET['inline'] ) && in_array( $file['mime'], array( 'application/pdf', 'image/jpeg', 'image/png' ), true );
            nocache_headers();
            header( 'Content-Type: ' . $file['mime'] );
            header( 'Content-Length: ' . filesize( $path ) );
            header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', $file['name'] ) . '"' );
            readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
            exit;
        }
        wp_die( 'Archivo no disponible.', '', array( 'response' => 404 ) );
    }

    public static function handle_upload() {
        if ( ! is_user_logged_in() || GE_WTP_Portal::is_staff_preview() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $quote_id = absint( $_POST['quote_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_quote_file_' . $quote_id );
        $result = self::upload( $quote_id, $_FILES['ge_quote_file'] ?? array(), sanitize_key( wp_unslash( $_POST['source_type'] ?? 'preliminary' ) ), get_current_user_id() );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 422 ) ); }
        $url = GE_WTP_Staff_Portal::can_access() ? GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote_id ) ) : GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $quote_id ) );
        wp_safe_redirect( $url ); exit;
    }

    public static function handle_receipt() {
        if ( ! is_user_logged_in() || GE_WTP_Portal::is_staff_preview() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $quote_id = absint( $_POST['quote_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_quote_receipt_' . $quote_id );
        $result = self::upload( $quote_id, $_FILES['ge_quote_receipt'] ?? array(), 'payment_proof', get_current_user_id(), 'comprobante' );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 422 ) ); }
        $url = GE_WTP_Staff_Portal::can_access() ? GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote_id ) ) : GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $quote_id ) );
        wp_safe_redirect( $url ); exit;
    }
}
