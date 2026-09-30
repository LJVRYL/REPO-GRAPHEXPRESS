<?php

defined( 'ABSPATH' ) || exit;

/** Charge full roll width for the requested linear length of supported vinyl. */
final class GE_WTP_Roll_Pricing {
    public static function widths_for_catalog_key( $key ) {
        if ( ! in_array( $key, array( 'vinilo-blanco', 'vinilo-base-gris', 'vinilo-cristal', 'vinilo-microperforado' ), true ) ) { return array(); }
        $products = GE_WTP_Public_Catalog::products();
        $widths = array();
        foreach ( (array) ( $products[ $key ]['attributes']['Anchos imprimibles'] ?? array() ) as $label ) {
            $width = (float) str_replace( ',', '.', (string) $label );
            if ( $width > 0 ) { $widths[] = $width; }
        }
        sort( $widths, SORT_NUMERIC );
        return array_values( array_unique( $widths ) );
    }

    public static function billable_width( $requested_cm, $available_cm ) {
        if ( ! is_numeric( $requested_cm ) || (float) $requested_cm <= 0 ) { return 0; }
        foreach ( (array) $available_cm as $width ) {
            if ( (float) $width >= (float) $requested_cm ) { return (float) $width; }
        }
        return 0;
    }
}
