<?php

define( 'ABSPATH', __DIR__ );
$meta = array();
function get_user_meta( $id, $key ) { global $meta; return $meta[ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) { global $meta; $meta[ $id ][ $key ] = $value; }
function add_user_meta( $id, $key, $value ) { global $meta; $meta[ $id ][ $key ][] = $value; }
function get_userdata( $id ) { return $id === 7 ? (object) array( 'ID' => 7 ) : false; }
function user_can( $id, $capability ) { return 9 === $id && 'manage_woocommerce' === $capability; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_generate_uuid4() { static $n = 0; return 'profile-' . ++$n; }
class WP_Error { private $code; private $message; public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class GE_WTP_Customers { public static function addresses( $id ) { global $meta; return $meta[ $id ]['_ge_delivery_addresses'] ?? array(); } }
class Test_Order { private $meta; public function __construct( $meta ) { $this->meta = $meta; } public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? array(); } }
$order = new Test_Order( array( '_ge_markcom_documents' => array(
    array( 'id' => 'old', 'issued_by_graphex' => true, 'superseded_at' => '2026-09-30' ),
    array( 'id' => 'new', 'issued_by_graphex' => true ),
    array( 'id' => 'receipt', 'category' => 'comprobante' ),
) ) );
function wc_get_order( $id ) { global $order; return 936 === $id ? $order : false; }
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-billing.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-customer-branches.php';
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-documents.php';
function check( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( $label ); } }

$meta[7]['_ge_delivery_addresses'] = array( array( 'id' => 'belgrano', 'label' => 'Belgrano', 'street' => 'Calle QA 1' ), array( 'id' => 'lavalle', 'label' => 'Lavalle', 'street' => 'Calle QA 2' ) );
$profile = GE_WTP_Customer_Branches::save( 7, array( 'label' => 'Belgrano', 'billing_mode' => 'common', 'legal_name' => 'Entidad QA A', 'cuit' => '', 'vat_status' => 'monotributo', 'fiscal_address' => 'Fiscal QA 1' ), 9 );
check( ! is_wp_error( $profile ) && $profile['id'] !== 'default', 'Alta de perfil vinculado al cliente' );
check( GE_WTP_Customer_Branches::find( 7, $profile['id'] )['legal_name'] === 'Entidad QA A', 'Lectura del perfil propio' );
check( GE_WTP_Customer_Branches::delivery( 7, 'lavalle' )['street'] === 'Calle QA 2', 'Destino estable por ID' );
$snapshot = $profile;
GE_WTP_Customer_Branches::save( 7, array_merge( $profile, array( 'legal_name' => 'Entidad QA B' ) ), 9 );
check( $snapshot['legal_name'] === 'Entidad QA A' && GE_WTP_Customer_Branches::find( 7, $profile['id'] )['legal_name'] === 'Entidad QA B', 'Snapshot histórico independiente' );
check( is_wp_error( GE_WTP_Customer_Branches::save( 7, array( 'label' => 'Intruso' ), 8 ) ), 'Escritura interna protegida' );
GE_WTP_Customer_Branches::save( 7, array( 'id' => $profile['id'], 'archive' => 1 ), 9 );
check( null === GE_WTP_Customer_Branches::find( 7, $profile['id'] ) && GE_WTP_Customer_Branches::find( 7, $profile['id'], true ), 'Archivo conserva perfil histórico' );
check( count( GE_WTP_Documents::issued_documents( 936 ) ) === 1 && count( GE_WTP_Documents::issued_documents( 936, true ) ) === 2, 'Factura actual e historial separados de comprobante' );
check( is_wp_error( GE_WTP_Documents::attach_issued( 936, 'comprobante', '', '' ) ), 'Comprobante no se carga como emisión' );
echo "customer-branches-invoices: OK\n";
