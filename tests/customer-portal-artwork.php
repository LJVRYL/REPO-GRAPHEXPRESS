<?php

$wp_load = getenv( 'GE_WP_LOAD' );
if ( ! $wp_load || ! is_file( $wp_load ) ) {
    fwrite( STDERR, "Definí GE_WP_LOAD con la ruta a wp-load.php.\n" );
    exit( 2 );
}

require $wp_load;

$checks = array();
$assert = static function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    $checks[] = $message;
};

$assert( class_exists( 'GE_WTP_Portal' ), 'El portal carga dentro de WordPress' );
$assert( method_exists( 'GE_WTP_Portal', 'preview_url' ), 'Gestión puede crear enlaces de vista previa' );
$assert( method_exists( 'GE_WTP_Artwork_Library', 'attach_customer_uploads_to_item' ), 'Los archivos pueden asociarse al renglón exacto' );

$slots_method = new ReflectionMethod( 'GE_WTP_Portal', 'artwork_slots_for_item' );
$slots_method->setAccessible( true );
$duplex = new WC_Order_Item_Product();
$duplex->set_name( 'Tarjetas corporativas full color doble faz' );
$assert( array( 'front' => 'Frente', 'back' => 'Dorso' ) === $slots_method->invoke( null, $duplex ), 'Doble faz genera casilleros Frente y Dorso' );
$simple = new WC_Order_Item_Product();
$simple->set_name( 'Afiche full color frente' );
$assert( array( 'front' => 'Frente' ) === $slots_method->invoke( null, $simple ), 'Simple faz genera un único casillero Frente' );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$customers = get_users( array( 'role__in' => array( 'customer', 'ge_markcom_client' ), 'number' => 1 ) );
if ( $admins && $customers ) {
    wp_set_current_user( $admins[0]->ID );
    $url = GE_WTP_Portal::preview_url( $customers[0]->ID, 'pedidos' );
    parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
    $_GET['ge_preview_customer'] = $query['ge_preview_customer'];
    $_GET['ge_preview_token'] = $query['ge_preview_token'];
    $assert( $customers[0]->ID === GE_WTP_Portal::preview_customer_id(), 'El enlace firmado conserva el cliente seleccionado' );
    $_GET['ge_preview_token'] = 'token-invalido';
    $assert( 0 === GE_WTP_Portal::preview_customer_id(), 'Un enlace alterado no permite la vista previa' );

    $customer_with_order = null;
    $customer_orders = array();
    foreach ( get_users( array( 'role__in' => array( 'customer', 'ge_markcom_client' ), 'number' => 100 ) ) as $candidate ) {
        $candidate_orders = GE_WTP_Orders::get_customer_orders( $candidate->ID, 5 );
        if ( GE_WTP_Portal::is_markcom_user( $candidate ) ) {
            $candidate_orders = array_values( array_filter( $candidate_orders, static function ( $order ) { return 'yes' === $order->get_meta( '_ge_markcom_order' ); } ) );
        }
        if ( $candidate_orders ) { $customer_with_order = $candidate; $customer_orders = $candidate_orders; break; }
    }
    if ( $customer_with_order ) {
        $url = GE_WTP_Portal::preview_url( $customer_with_order->ID, 'pedidos', array( 'pedido' => $customer_orders[0]->get_id() ) );
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );
        $html = GE_WTP_Portal::render();
        $assert( false !== strpos( $html, 'ge-portal-preview-banner' ) && false !== strpos( $html, $customer_with_order->user_email ), 'La vista previa identifica al cliente correcto' );
        $assert( count( $customer_orders[0]->get_items( 'line_item' ) ) === substr_count( $html, 'class="ge-item-artwork-card"' ), 'El portal crea una ficha de archivos por cada renglón del pedido' );
        $assert( false === strpos( $html, 'name="ge_item_artwork"' ), 'La vista previa administrativa no permite subir archivos' );
    }
}

echo 'OK ' . count( $checks ) . " pruebas\n";
foreach ( $checks as $check ) { echo '- ' . $check . "\n"; }
