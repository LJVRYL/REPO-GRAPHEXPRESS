<?php

define( 'ABSPATH', __DIR__ );
if ( ! function_exists( 'absint' ) ) { function absint( $value ) { return abs( (int) $value ); } }

require dirname( __DIR__ ) . '/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-supplier-invoices.php';

$tests = array();
$assert = function( $condition, $message ) use ( &$tests ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    $tests[] = $message;
};

$assert( false === GE_WTP_Supplier_Invoices::auto_send_enabled(), 'AUTO_SEND permanece deshabilitado' );
$assert( false === GE_WTP_Supplier_Invoices::original_is_publishable(), 'El original nunca es publicable' );

$identity = GE_WTP_Supplier_Invoices::invoice_identity( array( 'supplier_tax_id' => '20-94854893-4', 'invoice_type' => 'a', 'point_of_sale' => '00003', 'invoice_number' => '00001205' ) );
$assert( '20948548934|A|3|1205' === $identity, 'La identidad fiscal se normaliza de forma estable' );
$assert( GE_WTP_Supplier_Invoices::should_invalidate_approval( $identity, $identity . '-cambio' ), 'Cambiar la identidad invalida la aprobación' );
$assert( ! GE_WTP_Supplier_Invoices::should_invalidate_approval( $identity, $identity ), 'La misma identidad conserva la aprobación' );

$assert( GE_WTP_Supplier_Invoices::hash_is_duplicate( 'abc', array( 'zzz', 'abc' ) ), 'Un hash repetido se detecta como duplicado' );
$assert( ! GE_WTP_Supplier_Invoices::hash_is_duplicate( 'abc', array( 'zzz', 'def' ) ), 'Un hash nuevo no se marca como duplicado' );
$assert( GE_WTP_Supplier_Invoices::approval_valid_for_hash( 'abc', 'abc' ), 'La copia aprobada conserva validez si no cambia' );
$assert( ! GE_WTP_Supplier_Invoices::approval_valid_for_hash( 'abc', 'def' ), 'Cambiar la copia invalida su aprobación' );

$candidate = GE_WTP_Supplier_Invoices::choose_candidate( array( 37 => 90, 44 => 50 ) );
$assert( 'proposed' === $candidate['status'] && 37 === $candidate['order_id'], 'Se propone sólo un pedido inequívoco' );
$assert( 'ambiguous' === GE_WTP_Supplier_Invoices::choose_candidate( array( 37 => 90, 44 => 90 ) )['status'], 'Dos coincidencias iguales quedan bloqueadas como ambiguas' );
$assert( 'none' === GE_WTP_Supplier_Invoices::choose_candidate( array( 37 => 40 ) )['status'], 'Una coincidencia débil no se asocia' );

$matching = GE_WTP_Supplier_Invoices::reconcile( array( 'net_amount' => '73.700,00', 'total_amount' => '89.177,00' ), array( 'net' => 73700, 'total' => 89177 ) );
$assert( true === $matching['matches'], 'La conciliación compara neto con neto y total con total' );
$matching_decimal = GE_WTP_Supplier_Invoices::reconcile( array( 'net_amount' => '73700.50', 'total_amount' => '89177.25' ), array( 'net' => 73700.50, 'total' => 89177.25 ) );
$assert( true === $matching_decimal['matches'], 'La conciliación admite decimales con punto sin multiplicarlos por cien' );
$different = GE_WTP_Supplier_Invoices::reconcile( array( 'net_amount' => '73.700,00', 'total_amount' => '89.177,00' ), array( 'net' => 89177, 'total' => 89177 ) );
$assert( false === $different['matches'] && in_array( 'Neto diferente', $different['messages'], true ), 'Una diferencia fiscal bloquea la conciliación' );

$profile = GE_WTP_Supplier_Invoices::redaction_profile( 'mardones' );
$assert( GE_WTP_Supplier_Invoices::redactions_are_safe( $profile['redactions'], $profile['protected'] ), 'El perfil Mardones no invade campos fiscales protegidos' );
$assert( ! GE_WTP_Supplier_Invoices::redactions_are_safe( array( array( .04, .12, .20, .02 ) ), $profile['protected'] ), 'Una redacción sobre un campo fiscal queda bloqueada' );

$source = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-supplier-invoices.php' );
$assert( false === strpos( $source, 'wp_mail(' ), 'El módulo no contiene envíos automáticos de correo' );
$assert( false !== strpos( $source, 'expected_supplier_net' ) && false !== strpos( $source, 'expected_supplier_total' ), 'El costo esperado del proveedor se separa del precio de venta' );
$assert( false !== strpos( $source, 'admin_post_ge_si_revoke' ) && false !== strpos( $source, 'publication_revoked' ), 'La publicación puede retirarse sin borrar la auditoría' );

echo 'OK ' . count( $tests ) . " pruebas\n";
foreach ( $tests as $test ) { echo '- ' . $test . "\n"; }
