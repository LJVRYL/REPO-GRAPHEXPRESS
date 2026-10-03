<?php
define( 'ABSPATH', __DIR__ );
$meta = array( 21 => array( '_ge_delivery_addresses' => array( array( 'label' => 'Sede inicial', 'street' => 'Calle 1' ) ), '_ge_customer_internal_notes' => 'Conservar' ) );
$users = array( 21 => array( 'user_email' => 'qa@example.test', 'first_name' => 'Jorge', 'last_name' => 'Cohen' ) );
function current_user_can( $cap ) { return 'manage_woocommerce' === $cap; }
function get_current_user_id() { return 9; }
function check_admin_referer( $nonce ) { if ( ! str_starts_with( $nonce, 'ge_customer_workspace_21_' ) ) { throw new RuntimeException( 'Nonce inválido' ); } }
function get_userdata( $id ) { global $users; return isset( $users[ $id ] ) ? (object) $users[ $id ] : false; }
function wp_update_user( $data ) { global $users; $id = $data['ID']; unset( $data['ID'] ); $users[ $id ] = array_merge( $users[ $id ], $data ); return $id; }
function is_wp_error( $value ) { return false; }
function get_user_meta( $id, $key, $single = true ) { global $meta; return $meta[ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) { global $meta; $meta[ $id ][ $key ] = $value; }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_generate_uuid4() { static $n = 0; return 'new-address-' . ++$n; }
function wp_safe_redirect( $url ) { throw new RuntimeException( $url ); }
function wp_die( $message, $code = 500 ) { throw new RuntimeException( $message, $code ); }
class GE_WTP_Staff_Portal { public static function portal_url( $section, $args ) { return '/gestion/?' . http_build_query( $args ); } }
class GE_WTP_Customers { public static function addresses( $id ) { return get_user_meta( $id, '_ge_delivery_addresses', true ); } }
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-customer-workspace.php';
function save_card( $section, $fields ) {
    $_POST = array_merge( array( 'customer_id' => 21, 'section' => $section ), $fields );
    try { GE_WTP_Customer_Workspace::save(); } catch ( RuntimeException $e ) { if ( str_starts_with( $e->getMessage(), '/gestion/' ) ) { return; } throw $e; }
    throw new RuntimeException( 'Faltó la redirección' );
}
function check_card( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
save_card( 'identity', array( 'first_name' => 'Jorge', 'last_name' => 'Cohen', 'whatsapp' => '123', 'contact_person' => 'Contacto' ) );
check_card( get_user_meta( 21, '_ge_customer_internal_notes' ) === 'Conservar', 'Identidad sobrescribió información interna' );
check_card( count( GE_WTP_Customers::addresses( 21 ) ) === 1, 'Identidad sobrescribió destinos' );
save_card( 'delivery', array( 'address_id' => '', 'label' => 'Segunda sede', 'street' => 'Calle 2' ) );
check_card( count( GE_WTP_Customers::addresses( 21 ) ) === 2, 'No agregó segundo destino' );
check_card( GE_WTP_Customers::addresses( 21 )[0]['street'] === 'Calle 1', 'Alteró destino previo' );
save_card( 'delivery', array( 'address_id' => '0', 'label' => 'Sede inicial', 'street' => 'Calle corregida' ) );
check_card( count( GE_WTP_Customers::addresses( 21 ) ) === 2, 'Duplicó destino antiguo sin ID' );
check_card( GE_WTP_Customers::addresses( 21 )[0]['id'] !== '0', 'No asignó ID estable al destino antiguo' );
save_card( 'internal', array( 'internal_tags' => 'Corporativo', 'internal_notes' => 'Solo equipo' ) );
check_card( get_user_meta( 21, '_ge_customer_internal_notes' ) === 'Solo equipo', 'No guardó la card interna' );
check_card( count( GE_WTP_Customers::addresses( 21 ) ) === 2, 'La card interna sobrescribió destinos' );
save_card( 'delivery', array( 'address_id' => GE_WTP_Customers::addresses( 21 )[1]['id'], 'archive' => '1' ) );
check_card( count( GE_WTP_Customers::addresses( 21 ) ) === 1, 'No desactivó destino' );
check_card( count( get_user_meta( 21, '_ge_delivery_addresses_archive' ) ) === 1, 'El destino archivado no se conservó' );
echo "customer-workspace-card-save: OK\n";
