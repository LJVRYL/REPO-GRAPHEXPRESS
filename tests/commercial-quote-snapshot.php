<?php

define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class Fake_Product {
    public $price = '25.00';
    public function get_status() { return 'publish'; }
    public function get_name() { return 'Stickers'; }
    public function get_sku() { return 'ST-1'; }
}
$product = new Fake_Product();
function wc_get_product( $id ) { global $product; return 77 === $id ? $product : false; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function get_option( $name, $default ) { return $default; }
function wp_date( $format ) { return '2026-09-30'; }

require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quote-balance.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-quotes.php';

$lines = array(
    array( 'product_id' => 77, 'name' => 'Stickers 5 cm', 'quantity' => 3, 'unit_net' => '100.10', 'details' => 'Vinilo blanco' ),
    array( 'name' => 'Diseño especial', 'quantity' => 1, 'unit_net' => '75.00' ),
);
$snapshot = GE_WTP_Commercial_Quotes::build_snapshot( $lines, array( 'valid_until' => '2026-10-30', 'deposit_percent' => 50 ) );
if ( $snapshot instanceof WP_Error || $snapshot['net_cents'] !== 37530 || $snapshot['items'][0]['sku'] !== 'ST-1' ) {
    throw new RuntimeException( 'El snapshot no conserva el importe o el producto.' );
}
$product->price = '999.00';
if ( $snapshot['items'][0]['unit_net_cents'] !== 10010 || $snapshot['items'][0]['name'] !== 'Stickers 5 cm' ) {
    throw new RuntimeException( 'El catálogo alteró un snapshot existente.' );
}
$bad = GE_WTP_Commercial_Quotes::build_snapshot( array( array( 'name' => 'A', 'quantity' => 1, 'unit_net' => '10.999' ) ) );
if ( ! $bad instanceof WP_Error || 'ge_quote_price' !== $bad->get_error_code() ) {
    throw new RuntimeException( 'No se rechazó un precio con precisión inválida.' );
}
echo "commercial-quote-snapshot: OK\n";
