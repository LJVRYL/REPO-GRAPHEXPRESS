<?php

define( 'ABSPATH', __DIR__ );
$user_meta = array();
function get_user_meta( $id, $key ) { global $user_meta; return $user_meta[$id][$key] ?? ''; }
function update_user_meta( $id, $key, $value ) { global $user_meta; $user_meta[$id][$key] = $value; }
function add_user_meta( $id, $key, $value ) { global $user_meta; $user_meta[$id][$key][] = $value; }
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-billing.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quote-balance.php';

function check( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } }
function blocked( $callback, $label ) {
    try { $callback(); } catch ( DomainException $e ) { return; }
    throw new RuntimeException( $label );
}
blocked( function () { GE_WTP_Billing::assert_can_accept_or_pay( array() ); }, 'Snapshot ausente no autoriza pago' );

$issuer = array( 'legal_name' => 'Graph Test', 'cuit' => '30712345671', 'point_of_sale' => '0001', 'vat_status' => 'registered', 'document_capabilities' => array( 'A', 'B' ), 'common_price_policy' => 'tax_inclusive', 'invoice_a_price_policy' => 'tax_exclusive' );
$common = GE_WTP_Billing::normalize_profile( array( 'billing_mode' => 'common' ) );
$common_result = GE_WTP_Billing::resolve( $issuer, $common, 12100, 2100 );
check( $common_result['blockers'] === array() && $common_result['document_type'] === 'B' && $common_result['total_cents'] === 12100 && $common_result['tax_cents'] === 2100, 'Cliente común sin CUIT y precio final' );
check( in_array( 'quote_requires_net_price_policy', GE_WTP_Billing::resolve_net_quote( $issuer, $common, 10000, 2100 )['blockers'], true ), 'Presupuesto neto no se reinterpreta como precio final' );

$a_missing = GE_WTP_Billing::normalize_profile( array( 'billing_mode' => 'invoice_a' ) );
$missing_result = GE_WTP_Billing::resolve( $issuer, $a_missing, 10000, 2100 );
check( in_array( 'cuit', $missing_result['blockers'], true ), 'Factura A requiere CUIT' );
blocked( function () use ( $issuer, $a_missing, $missing_result ) { GE_WTP_Billing::assert_can_accept_or_pay( GE_WTP_Billing::snapshot( $issuer, $a_missing, $missing_result ) ); }, 'No aceptar/pagar con datos faltantes' );

$a = GE_WTP_Billing::normalize_profile( array( 'billing_mode' => 'invoice_a', 'cuit' => '20-12345678-6', 'legal_name' => 'Cliente Test', 'vat_status' => 'registered', 'billing_email' => 'facturas@example.com', 'fiscal_address' => 'Calle 123' ) );
$a_result = GE_WTP_Billing::resolve( $issuer, $a, 10000, 2100 );
check( GE_WTP_Billing::resolve_net_quote( $issuer, $a, 10000, 2100 )['total_cents'] === 12100, 'Presupuesto A neto' );
check( $a_result['blockers'] === array() && $a_result['document_type'] === 'A' && $a_result['tax_cents'] === 2100 && $a_result['total_cents'] === 12100, 'Factura A base + IVA configurado' );
$snapshot = GE_WTP_Billing::snapshot( $issuer, $a, $a_result );
GE_WTP_Billing::assert_can_accept_or_pay( $snapshot );
blocked( function () use ( $snapshot, $issuer ) { $changed = $issuer; $changed['document_capabilities'] = array( 'B' ); GE_WTP_Billing::assert_can_accept_or_pay( $snapshot, $changed ); }, 'Cambio de emisor exige revisar presupuesto' );
GE_WTP_Billing::save_profile( 7, $a, 9 );
GE_WTP_Billing::save_profile( 7, $common, 7 );
check( $snapshot['profile']['billing_mode'] === 'invoice_a' && $snapshot['resolution']['total_cents'] === 12100, 'Cambio de perfil no altera snapshot' );
check( count( $user_meta[7]['_ge_billing_audit'] ) === 2, 'Corrección de staff deja auditoría' );
$deposit = GE_WTP_Quote_Balance::deposit( $snapshot['resolution']['total_cents'], 5000 );
check( $deposit['deposit_cents'] === 6050 && $deposit['remaining_cents'] === 6050, 'Seña sobre total fiscal' );

$issuer['document_capabilities'] = array( 'B' );
$blocked_result = GE_WTP_Billing::resolve( $issuer, $a, 10000, 2100 );
check( in_array( 'issuer_cannot_invoice_a', $blocked_result['blockers'], true ), 'Emisor sin A bloquea antes de cobrar' );
blocked( function () use ( $issuer, $a, $blocked_result ) { GE_WTP_Billing::assert_can_accept_or_pay( GE_WTP_Billing::snapshot( $issuer, $a, $blocked_result ) ); }, 'No pagar con emisor incompatible' );

$monotributo = array( 'legal_name' => 'Graph Test', 'cuit' => '30712345671', 'point_of_sale' => '0001', 'vat_status' => 'monotributo', 'document_capabilities' => array( 'C' ), 'common_price_policy' => 'tax_inclusive' );
$mono_result = GE_WTP_Billing::resolve( $monotributo, $common, 10000, 0 );
check( $mono_result['blockers'] === array() && $mono_result['document_type'] === 'C' && $mono_result['tax_cents'] === 0, 'Monotributo común emite C sin IVA discriminado' );
check( in_array( 'tax_rate_incompatible_with_issuer', GE_WTP_Billing::resolve( $monotributo, $common, 10000, 2100 )['blockers'], true ), 'Monotributo con tasa IVA bloqueado' );

echo "billing-flows: OK\n";
