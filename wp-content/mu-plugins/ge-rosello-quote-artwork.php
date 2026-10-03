<?php
/**
 * Connect the preloaded Roselló proof to the order created when the customer
 * accepts the quote. The customer still confirms the exact file in the portal.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'woocommerce_after_order_object_save', function ( $order, $data_store ) {
    static $busy = false;
    if ( $busy || ! $order instanceof WC_Order ) { return; }
    if ( 'ge_pending_quote_rosello_20260928' !== $order->get_meta( '_ge_customer_quote_key', true ) ) { return; }
    if ( 20 !== (int) $order->get_customer_id() || 'yes' === $order->get_meta( '_ge_rosello_artwork_prepared', true ) ) { return; }

    $art_id = (int) get_option( 'ge_rosello_20260928_artwork_id', 0 );
    if ( ! $art_id || 20 !== (int) get_post_meta( $art_id, '_ge_artwork_customer_id', true ) ) { return; }
    $original = get_post_meta( $art_id, '_ge_artwork_original', true );
    if ( ! is_array( $original ) || 'local' !== ( $original['provider'] ?? '' ) || empty( $original['stored_name'] ) ) { return; }
    $source = WP_CONTENT_DIR . '/ge-private/artwork-originals/' . wp_basename( $original['stored_name'] );
    if ( ! is_file( $source ) || '6fc93f44ea8ae84e733675dffd2593ff5a1c37c67da08afeef255beb1d47093d' !== hash_file( 'sha256', $source ) ) { return; }
    $items = $order->get_items( 'line_item' );
    if ( 1 !== count( $items ) ) { return; }
    $item = reset( $items );
    if ( 500 !== (int) $item->get_quantity() || abs( (float) $item->get_total() - 379500.0 ) > 0.01 ) { return; }
    if ( ! defined( 'GE_WTP_PRIVATE_UPLOAD_DIR' ) || ! class_exists( 'GE_WTP_VPS_Storage' ) || ! GE_WTP_VPS_Storage::ready() ) { return; }

    $busy = true;
    try {
        $relative = 'orders/' . $order->get_id() . '/' . $item->get_id() . '/' . wp_generate_uuid4() . '-rosello_carpeta_pliego_72x52.pdf';
        $target = trailingslashit( GE_WTP_PRIVATE_UPLOAD_DIR ) . $relative;
        if ( ! wp_mkdir_p( dirname( $target ) ) || ! copy( $source, $target ) ) { return; }
        chmod( $target, 0640 );
        if ( hash_file( 'sha256', $target ) !== hash_file( 'sha256', $source ) ) { return; }

        $doc_id = wp_generate_uuid4();
        $docs = GE_WTP_Documents::get_documents( $order->get_id() );
        $docs[] = array(
            'id' => $doc_id, 'provider' => 'vps', 'relative_path' => $relative,
            'name' => 'rosello_carpeta_pliego_72x52.pdf', 'mime' => 'application/pdf',
            'size' => filesize( $target ), 'category' => 'arte', 'order_item_id' => $item->get_id(),
            'uploaded_by' => 20, 'uploaded_at' => current_time( 'mysql' ),
            'analysis' => $original['analysis'] ?? array( 'sha256' => hash_file( 'sha256', $target ) ),
        );
        $order->update_meta_data( GE_WTP_Documents::META_KEY, $docs );
        $order->update_meta_data( GE_WTP_Artwork_Library::ORDER_META, array( $art_id ) );
        $order->update_meta_data( '_ge_rosello_artwork_prepared', 'yes' );
        $order->save();

        $item->update_meta_data( '_ge_item_artwork_sources', array( 'document:' . $doc_id ) );
        $item->update_meta_data( '_ge_item_artwork_version', 'v1 - 28/09/2026' );
        $item->update_meta_data( '_ge_item_artwork_expected', array(
            'dimensions' => 'Carpeta abierta 50 × 37 cm; pliego 72 × 52 cm',
            'pages' => 'Impresión simple faz', 'orientation' => 'Horizontal',
            'notes' => 'Blanco y negro, offset, Chambrill 240 g, hendido y perforado',
        ) );
        $item->save();
    } finally {
        $busy = false;
    }
}, 50, 2 );
