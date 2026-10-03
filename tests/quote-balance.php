<?php

define( 'ABSPATH', __DIR__ );
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quote-balance.php';

function expect_equal( $actual, $expected, $label ) {
    if ( $actual !== $expected ) {
        throw new RuntimeException( $label . ': ' . var_export( $actual, true ) . ' != ' . var_export( $expected, true ) );
    }
}

function expect_exception( $callback, $class, $label ) {
    try {
        $callback();
    } catch ( Throwable $error ) {
        if ( $error instanceof $class ) { return; }
        throw new RuntimeException( $label . ': excepción incorrecta ' . get_class( $error ) );
    }
    throw new RuntimeException( $label . ': faltó excepción' );
}

expect_equal( GE_WTP_Quote_Balance::cents( '123.4' ), 12340, 'Decimal' );
expect_equal( GE_WTP_Quote_Balance::decimal( 12340 ), '123.40', 'Formato' );
expect_equal( GE_WTP_Quote_Balance::deposit( 10001, 5000 ), array( 'deposit_cents' => 5001, 'remaining_cents' => 5000 ), 'Centavo impar' );
expect_equal( GE_WTP_Quote_Balance::reconcile( 10001, array() )['payment_status'], 'unpaid', 'Sin pago' );
$credited = array( array( 'key' => 'mp:12345678', 'amount_cents' => 5001 ) );
expect_equal( GE_WTP_Quote_Balance::reconcile( 10001, $credited ), array( 'amount_paid_cents' => 5001, 'amount_due_cents' => 5000, 'payment_status' => 'deposit_paid' ), 'Seña' );
$credited[] = $credited[0];
expect_equal( GE_WTP_Quote_Balance::reconcile( 10001, $credited )['amount_paid_cents'], 5001, 'Webhook duplicado' );
$credited[] = array( 'key' => 'mp:87654321', 'amount_cents' => 5000 );
expect_equal( GE_WTP_Quote_Balance::reconcile( 10001, $credited )['payment_status'], 'paid', 'Saldo acreditado' );
expect_exception( function () { GE_WTP_Quote_Balance::cents( '100.999' ); }, InvalidArgumentException::class, 'No truncar decimales' );
expect_exception( function () { GE_WTP_Quote_Balance::reconcile( 10001, array( array( 'key' => 'mp:12345678', 'amount_cents' => 5001 ), array( 'key' => 'mp:12345678', 'amount_cents' => 5000 ) ) ); }, DomainException::class, 'Duplicado conflictivo' );
expect_exception( function () { GE_WTP_Quote_Balance::reconcile( 10001, array( array( 'key' => 'mp:12345678', 'amount_cents' => 10002 ) ) ); }, DomainException::class, 'Sobrepago' );
echo "quote-balance: OK\n";
