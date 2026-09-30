<?php

define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class Fake_Vinyl_Product {
    public function get_id() { return 46; }
    public function get_meta() { return array(); }
    public function get_status() { return 'publish'; }
    public function is_type() { return false; }
}
function wc_get_product( $id ) { return 46 === $id ? new Fake_Vinyl_Product() : false; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return (string) $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_post_meta( $id, $key ) { return 46 === $id && '_ge_public_catalog_key' === $key ? 'vinilo-blanco' : ''; }
class GE_WTP_Storefront {
    public static function config() { return array( 'mode' => 'm2', 'options' => array( 'm2' => array( 'label' => 'Metro lineal según ancho de rollo', 'price' => 17940 ) ), 'roll_widths_cm' => array( 104, 124, 135, 150 ) ); }
}

require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-public-catalog.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-roll-pricing.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-quote-catalog.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-quotes.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-quote-ui.php';

if ( GE_WTP_Roll_Pricing::widths_for_catalog_key( 'vinilo-blanco' ) !== array( 104.0, 124.0, 135.0, 150.0 ) ) { throw new RuntimeException( 'Anchos de rollo incorrectos.' ); }
foreach ( array( 'vinilo-base-gris', 'vinilo-cristal', 'vinilo-microperforado' ) as $key ) {
    if ( ! GE_WTP_Roll_Pricing::widths_for_catalog_key( $key ) ) { throw new RuntimeException( 'Faltan los anchos de ' . $key ); }
}
$narrow = GE_WTP_Commercial_Quote_Catalog::price( 46, array( 'option_key' => 'm2', 'width' => 50, 'height' => 120 ), 1 );
$full = GE_WTP_Commercial_Quote_Catalog::price( 46, array( 'option_key' => 'm2', 'width' => 104, 'height' => 120 ), 1 );
if ( $narrow['price'] !== $full['price'] || $narrow['configuration']['width'] !== 50.0 || $narrow['configuration']['roll_width_cm'] !== 104.0 || false === strpos( $narrow['description'], 'rollo 104' ) ) { throw new RuntimeException( 'Un ancho menor a 104 cm abarató el vinilo.' ); }
$next = GE_WTP_Commercial_Quote_Catalog::price( 46, array( 'option_key' => 'm2', 'width' => 120, 'height' => 120 ), 1 );
if ( $next['configuration']['roll_width_cm'] !== 124.0 || $next['price'] !== round( 17940 * 1.24 * 1.2 ) ) { throw new RuntimeException( 'No se tomó el siguiente ancho de rollo.' ); }
$oversize = GE_WTP_Commercial_Quote_Catalog::price( 46, array( 'option_key' => 'm2', 'width' => 151, 'height' => 120 ), 1 );
if ( ! is_wp_error( $oversize ) ) { throw new RuntimeException( 'Se cotizó un ancho que excede los rollos.' ); }
$old_draft = array( 'items' => array( array( 'product_id' => 46, 'quantity' => 1, 'configuration' => array( 'option_key' => 'm2', 'width' => 50, 'height' => 120 ), 'unit_net_cents' => 1076400 ) ) );
if ( ! GE_WTP_Commercial_Quotes::needs_roll_reprice( $old_draft ) ) { throw new RuntimeException( 'El borrador anterior podría enviarse sin revisar.' ); }
$new_draft = array( 'items' => array( array( 'product_id' => 46, 'quantity' => 1, 'configuration' => $narrow['configuration'], 'unit_net_cents' => $narrow['price'] * 100 ) ) );
if ( GE_WTP_Commercial_Quotes::needs_roll_reprice( $new_draft ) ) { throw new RuntimeException( 'El borrador recalculado quedó bloqueado.' ); }
$public_label = GE_WTP_Commercial_Quote_UI::customer_configuration_label( array( 'sku' => 'GF-VIN-001', 'configuration' => $narrow['configuration'], 'configuration_label' => $narrow['description'] ) );
if ( '50 × 120 cm' !== $public_label || false !== strpos( $public_label, 'rollo' ) ) { throw new RuntimeException( 'La configuración pública expuso el cálculo interno.' ); }
echo "vinyl-roll-pricing: OK\n";
