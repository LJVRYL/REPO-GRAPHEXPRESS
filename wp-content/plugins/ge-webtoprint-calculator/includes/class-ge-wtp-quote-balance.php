<?php

defined( 'ABSPATH' ) || exit;

/** Exact ARS arithmetic for commercial quote deposits and balance payments. */
final class GE_WTP_Quote_Balance {
    /** Convert a positive decimal amount to integer centavos without floating point. */
    public static function cents( $amount ) {
        if ( ! is_string( $amount ) && ! is_int( $amount ) ) {
            throw new InvalidArgumentException( 'El importe debe expresarse como decimal.' );
        }
        $value = (string) $amount;
        if ( ! preg_match( '/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $value, $parts ) ) {
            throw new InvalidArgumentException( 'Importe inválido.' );
        }
        return (int) $parts[1] * 100 + (int) str_pad( $parts[2] ?? '', 2, '0' );
    }

    public static function decimal( $cents ) {
        self::assert_cents( $cents );
        return (string) intdiv( $cents, 100 ) . '.' . str_pad( (string) ( $cents % 100 ), 2, '0', STR_PAD_LEFT );
    }

    /** Percent is stored as basis points: 5000 means 50.00%. */
    public static function deposit( $total_cents, $percent_basis_points ) {
        self::assert_cents( $total_cents );
        if ( ! is_int( $percent_basis_points ) || $percent_basis_points < 1 || $percent_basis_points > 10000 ) {
            throw new InvalidArgumentException( 'Porcentaje de seña inválido.' );
        }
        $deposit = intdiv( $total_cents * $percent_basis_points + 5000, 10000 );
        return array( 'deposit_cents' => $deposit, 'remaining_cents' => $total_cents - $deposit );
    }

    /**
     * Derive payment state from credited attempts. Each attempt has an immutable
     * idempotency key and credited amount. Repeated delivery of the same event
     * contributes once; conflicting repeats must be investigated, not guessed.
     */
    public static function reconcile( $total_cents, $credited_attempts ) {
        self::assert_cents( $total_cents );
        if ( ! is_array( $credited_attempts ) ) {
            throw new InvalidArgumentException( 'Pagos inválidos.' );
        }
        $seen = array();
        $paid = 0;
        foreach ( $credited_attempts as $attempt ) {
            $key = $attempt['key'] ?? '';
            $amount = $attempt['amount_cents'] ?? null;
            if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9:_-]{8,128}$/D', $key ) ) {
                throw new InvalidArgumentException( 'Identificador de pago inválido.' );
            }
            self::assert_cents( $amount );
            if ( isset( $seen[ $key ] ) ) {
                if ( $seen[ $key ] !== $amount ) {
                    throw new DomainException( 'Evento de pago duplicado con importe diferente.' );
                }
                continue;
            }
            $seen[ $key ] = $amount;
            $paid += $amount;
            if ( $paid > $total_cents ) {
                throw new DomainException( 'Los pagos superan el total del pedido.' );
            }
        }
        return array(
            'amount_paid_cents' => $paid,
            'amount_due_cents' => $total_cents - $paid,
            'payment_status' => 0 === $paid ? 'unpaid' : ( $paid === $total_cents ? 'paid' : 'deposit_paid' ),
        );
    }

    private static function assert_cents( $amount ) {
        if ( ! is_int( $amount ) || $amount < 0 || $amount > 999999999999 ) {
            throw new InvalidArgumentException( 'Importe en centavos inválido.' );
        }
    }
}
