<?php
require_once __DIR__ . '/fixtures/wordpress-defaults.php';

define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class Fake_Product {
    public $price = '25.00';
    public $mode = 'fixed';
    public function get_id() { return 77; }
    public function get_meta() { return array(); }
    public function is_type() { return false; }
    public function get_status() { return 'publish'; }
    public function get_name() { return 'Stickers'; }
    public function get_sku() { return 'ST-1'; }
}
$product = new Fake_Product();
function wc_get_product( $id ) { global $product; return 77 === $id ? $product : false; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function sanitize_key( $value ) { return trim( (string) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $name, $default ) { return $default; }
function wp_date( $format ) { return '2026-09-30'; }
function current_time( $format ) { return strtotime( '2026-09-30' ); }
function wc_get_price_excluding_tax( $product ) { return (float) $product->price; }
function wc_format_decimal( $price, $decimals ) { return number_format( (float) $price, $decimals, '.', '' ); }
class GE_WTP_Storefront { public static function config() { global $product; return 'option' === $product->mode ? array( 'options' => array( 'premium' => array( 'label' => 'Papel premium', 'price' => 12.5, 'min_qty' => 10 ) ) ) : array(); } }
class GE_WTP_Workflow { public static function finishing_catalog() { return array( 'corte' => 'Corte' ); } }

require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quote-balance.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-quote-catalog.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quote-artwork-v2.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-quotes.php';

$lines = array(
    array( 'product_id' => 77, 'name' => 'Stickers 5 cm', 'quantity' => 3, 'unit_net' => '100.10', 'details' => 'Vinilo blanco', 'finishes' => array( 'corte' ) ),
    array( 'name' => 'Diseño especial', 'quantity' => 1, 'unit_net' => '75.00' ),
);
$snapshot = GE_WTP_Commercial_Quotes::build_snapshot( $lines, array( 'valid_until' => '2026-10-30', 'deposit_percent' => 50 ) );
if ( $snapshot instanceof WP_Error || $snapshot['net_cents'] !== 15000 || $snapshot['items'][0]['sku'] !== 'ST-1' || $snapshot['items'][0]['finishes'] !== array( 'corte' ) ) {
    throw new RuntimeException( 'El snapshot no conserva el importe o el producto.' );
}
if ( $snapshot['items'][0]['source_type'] !== 'catalog_product' || $snapshot['items'][1]['source_type'] !== 'custom' || $snapshot['items'][1]['product_id'] !== null ) {
    throw new RuntimeException( 'El presupuesto mixto perdió el origen de sus ítems.' );
}
$custom = GE_WTP_Commercial_Quotes::build_snapshot( array( array( 'source_type' => 'custom', 'name' => 'Almohada bamboo 45×30 cm', 'details' => 'Trabajo a medida', 'notes' => 'Terminación acordada', 'quantity' => 10, 'unit' => 'u', 'unit_net' => '33000.00' ) ) );
if ( $custom instanceof WP_Error || $custom['net_cents'] !== 33000000 || $custom['items'][0]['unit_net_cents'] !== 3300000 || $custom['items'][0]['notes'] !== 'Terminación acordada' ) {
    throw new RuntimeException( 'El ítem personalizado no conserva el acuerdo de ARS 330.000.' );
}
$invalid_source = GE_WTP_Commercial_Quotes::build_snapshot( array( array( 'source_type' => 'catalog_product', 'name' => 'Inventado', 'quantity' => 1, 'unit_net' => '1.00' ) ) );
if ( ! $invalid_source instanceof WP_Error || 'ge_quote_source' !== $invalid_source->get_error_code() ) {
    throw new RuntimeException( 'Se aceptó un catálogo sin producto.' );
}
$invalid_quantity = GE_WTP_Commercial_Quotes::build_snapshot( array( array( 'source_type' => 'custom', 'name' => 'Trabajo', 'quantity' => '-2', 'unit_net' => '1.00' ) ) );
if ( ! $invalid_quantity instanceof WP_Error || 'ge_quote_line' !== $invalid_quantity->get_error_code() ) {
    throw new RuntimeException( 'Se aceptó una cantidad negativa.' );
}
$zero_price = GE_WTP_Commercial_Quotes::build_snapshot( array( array( 'source_type' => 'custom', 'name' => 'Bonificación', 'quantity' => 1, 'unit_net' => '0.00' ) ) );
if ( $zero_price instanceof WP_Error || $zero_price['items'][0]['net_cents'] !== 0 ) {
    throw new RuntimeException( 'Se rechazó un precio cero válido.' );
}
$product->price = '999.00';
if ( $snapshot['items'][0]['unit_net_cents'] !== 2500 || $snapshot['items'][0]['name'] !== 'Stickers 5 cm' ) {
    throw new RuntimeException( 'El catálogo alteró un snapshot existente.' );
}
$product->mode = 'option';
$configured = GE_WTP_Commercial_Quotes::build_snapshot( array( array( 'product_id' => 77, 'name' => 'Stickers', 'quantity' => 10, 'unit_net' => '1.00', 'configuration' => array( 'option_key' => 'premium' ) ) ) );
if ( $configured instanceof WP_Error || 12500 !== $configured['net_cents'] || 1250 !== $configured['items'][0]['unit_net_cents'] || 'Papel premium' !== $configured['items'][0]['configuration_label'] ) {
    throw new RuntimeException( 'La configuración no usó el precio autoritativo del catálogo.' );
}
$invalid = GE_WTP_Commercial_Quotes::build_snapshot( array( array( 'product_id' => 77, 'name' => 'Stickers', 'quantity' => 10, 'unit_net' => '1.00', 'configuration' => array( 'option_key' => 'inexistente' ) ) ) );
if ( ! $invalid instanceof WP_Error || 'ge_quote_configuration' !== $invalid->get_error_code() ) {
    throw new RuntimeException( 'Se aceptó una configuración inexistente.' );
}
$bad = GE_WTP_Commercial_Quotes::build_snapshot( array( array( 'name' => 'A', 'quantity' => 1, 'unit_net' => '10.999' ) ) );
if ( ! $bad instanceof WP_Error || 'ge_quote_price' !== $bad->get_error_code() ) {
    throw new RuntimeException( 'No se rechazó un precio con precisión inválida.' );
}
echo "commercial-quote-snapshot: OK\n";
