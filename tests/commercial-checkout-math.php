<?php

define( 'ABSPATH', __DIR__ . '/' );
final class GE_WTP_Payments { const BANK_DISCOUNT = 10; const MP_SURCHARGE = 10; }
require __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quote-balance.php';
require __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-checkout.php';

function same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) { throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
}

$base = GE_WTP_Quote_Balance::cents( '1000.01' );
$transfer = $base + GE_WTP_Commercial_Checkout::adjustment_cents( $base, 'bacs' );
$mp = $base + GE_WTP_Commercial_Checkout::adjustment_cents( $base, 'mercadopago' );
same( 90001, $transfer, 'Transferencia sobre total final' );
same( 110001, $mp, 'Mercado Pago sobre total final' );
same( array( 'deposit_cents' => 45001, 'remaining_cents' => 45000 ), GE_WTP_Quote_Balance::deposit( $transfer, 5000 ), 'Seña transferencia' );
same( array( 'deposit_cents' => 55001, 'remaining_cents' => 55000 ), GE_WTP_Quote_Balance::deposit( $mp, 5000 ), 'Seña Mercado Pago' );
echo "commercial-checkout-math: OK\n";
