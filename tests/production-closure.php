<?php
// Focused contract test: closing a work order must not change its commercial state.
define( 'ABSPATH', __DIR__ );
class WP_Error { private $code; public function __construct( $code ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
class WC_Order {
    public $meta = array(); public $status = 'ge-produccion'; public $notes = array();
    public function get_meta( $key, $single = false ) { return $this->meta[ $key ] ?? ''; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function get_status() { return $this->status; }
    public function save() {}
    public function add_order_note( $note ) { $this->notes[] = $note; }
}
function get_current_user_id() { return 7; }
function user_can( $id, $cap ) { return 7 === $id && 'ge_manage_operations' === $cap; }
function sanitize_textarea_field( $text ) { return trim( strip_tags( $text ) ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
require dirname( __DIR__ ) . '/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-production.php';
$order = new WC_Order();
$order->meta['_ge_production_status'] = 'production';
$order->meta['_ge_amount_due_cents'] = 150000;
$result = GE_WTP_Production::close_order( $order, 'external', 'Resuelto fuera del portal' );
if ( true !== $result || ! GE_WTP_Production::is_closed( $order ) ) { throw new RuntimeException( 'Close failed.' ); }
if ( 'ge-produccion' !== $order->get_status() || 'production' !== $order->get_meta( '_ge_production_status' ) || 150000 !== $order->get_meta( '_ge_amount_due_cents' ) ) { throw new RuntimeException( 'Commercial or production stage mutated.' ); }
if ( 7 !== $order->get_meta( '_ge_production_closed_by' ) || 'external' !== $order->get_meta( '_ge_production_close_reason' ) ) { throw new RuntimeException( 'Closure audit missing.' ); }
if ( ! is_wp_error( GE_WTP_Production::close_order( $order, 'external' ) ) ) { throw new RuntimeException( 'Duplicate close accepted.' ); }
if ( true !== GE_WTP_Production::reopen_order( $order ) || GE_WTP_Production::is_closed( $order ) ) { throw new RuntimeException( 'Reopen failed.' ); }
if ( 2 !== count( $order->get_meta( '_ge_production_closure_history' ) ) ) { throw new RuntimeException( 'Closure history missing.' ); }
if ( ! is_wp_error( GE_WTP_Production::close_order( $order, 'invalid' ) ) ) { throw new RuntimeException( 'Invalid reason accepted.' ); }
echo "production-closure: OK\n";
