<?php

define( 'ABSPATH', __DIR__ );
define( 'MB_IN_BYTES', 1048576 );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_]/i', '', $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_file_name( $value ) { return basename( $value ); }
function wp_basename( $value ) { return basename( $value ); }
function wp_generate_uuid4() { static $id = 0; return 'file-' . ++$id; }
function wp_check_filetype_and_ext( $tmp, $name, $allowed ) { return array( 'ext' => strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ); }
function get_current_user_id() { return 9; }
function current_time( $type ) { return '2026-10-01 12:00:00'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error { private $code; public function __construct( $code, $message ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
class GE_WTP_VPS_Storage {
    public static function configured() { return true; }
    public static function limits() { return array( 'max_file_bytes' => 10 * MB_IN_BYTES ); }
    public static function store_order_upload( $file, $order_id, $item_id ) { return array( 'relative_path' => 'orders/' . $order_id . '/' . $file['name'] ); }
}
class Fake_Order {
    private $meta = array();
    public $notes = array();
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? array(); }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function save() {}
    public function add_order_note( $note ) { $this->notes[] = $note; }
}
$order = new Fake_Order();
function wc_get_order( $id ) { global $order; return 936 === $id ? $order : false; }
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-documents.php';
function check( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } }

$_FILES['ge_issued_document'] = array( 'name' => 'factura-1.pdf', 'tmp_name' => 'qa.pdf', 'type' => 'application/pdf', 'size' => 120, 'error' => UPLOAD_ERR_OK );
$first = GE_WTP_Documents::attach_issued( 936, 'factura', 'C-0001', '2026-10-01' );
check( ! is_wp_error( $first ) && ! empty( $first['issued_by_graphex'] ) && $first['document_number'] === 'C-0001', 'Factura emitida con metadatos' );
$_FILES['ge_issued_document']['name'] = 'factura-2.pdf';
$second = GE_WTP_Documents::attach_issued( 936, 'factura', 'C-0001', '2026-10-01', $first['id'] );
check( ! is_wp_error( $second ) && $second['replaces_id'] === $first['id'], 'Versión nueva vinculada' );
$all = GE_WTP_Documents::issued_documents( 936, true );
$current = GE_WTP_Documents::issued_documents( 936 );
check( count( $all ) === 2 && count( $current ) === 1 && $current[0]['id'] === $second['id'] && ! empty( $all[0]['superseded_at'] ), 'Historial preservado y sólo versión vigente al cliente' );
check( count( $order->notes ) === 2, 'Auditoría en notas del pedido' );
check( is_wp_error( GE_WTP_Documents::attach_issued( 936, 'comprobante', '', '' ) ), 'Comprobante separado' );
echo "issued-document-version: OK\n";
