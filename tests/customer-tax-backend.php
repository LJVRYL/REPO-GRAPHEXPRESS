<?php
/** Deterministic backend contract tests. No network, credentials, DB or WordPress required. */
define( 'ABSPATH', __DIR__ . '/fixture-wordpress/' );
$options = array(); $transport = null; $checks = 0;
class WP_Error { public $code; public function __construct( $code, $message = '' ) { $this->code = $code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function get_option( $key, $default = false ) { global $options; return $options[$key] ?? $default; }
function update_option( $key, $value, $autoload = true ) { global $options; $options[$key] = $value; return true; }
function apply_filters( $hook, $value, $cuit = '' ) { global $transport; return 'ge_wtp_arca_wsci_response' === $hook ? $transport : $value; }
class GE_WTP_Billing_Issuers { public static $issuers = array(); public static function all() { return self::$issuers; } }
$includes = dirname( __DIR__ ) . '/wp-content/plugins/ge-webtoprint-calculator/includes/';
require $includes . 'class-ge-wtp-customer-tax.php';
require $includes . 'class-ge-wtp-arca-lookup.php';
function check( $condition, $label ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); } }
function fixture( $taxes = array(), $mono = array() ) {
    return array( 'personaReturn' => array( 'datosGenerales' => array( 'idPersona' => '23336924529', 'tipoClave' => 'CUIT', 'estadoClave' => 'ACTIVO', 'apellido' => 'TEST', 'nombre' => 'PERSONA', 'domicilioFiscal' => array( 'direccion' => 'CALLE 123', 'localidad' => 'CABA', 'codPostal' => '1000' ) ), 'datosRegimenGeneral' => array( 'impuesto' => $taxes ), 'datosMonotributo' => $mono ) );
}
$issuer = array( 'id' => 'mardones-a', 'active' => true, 'relationship_confirmed' => true, 'verification_status' => 'verified', 'verified_at' => gmdate( 'c', time() - 60 ), 'vat_status' => 'registered', 'invoice_types_allowed' => array( 'A','B' ) );
foreach ( array( 'registered' => 'A','monotributo' => 'A','final_consumer' => 'B','exempt' => 'B','unknown' => 'unknown' ) as $status => $class ) {
    $r = GE_WTP_Customer_Tax::resolve( $issuer, array( 'vat_status' => $status, 'verification_status' => 'verified' ) );
    check( $class === $r['suggested_document_class'], 'registered matrix ' . $status );
    check( $r['requires_review'], 'all guidance reviewable' );
}
foreach ( array( 'monotributo','exempt' ) as $status ) {
    $i = $issuer; $i['vat_status'] = $status; $i['invoice_types_allowed'] = array( 'C' );
    check( 'C' === GE_WTP_Customer_Tax::resolve( $i, array() )['suggested_document_class'], 'domestic C ' . $status );
}
check( 'unknown' === GE_WTP_Customer_Tax::resolve( $issuer, array() )['suggested_document_class'], 'absence never final consumer' );
check( 'unknown' === GE_WTP_Customer_Tax::resolve( $issuer, array( 'vat_status' => 'registered' ), array( 'export' => true ) )['suggested_document_class'], 'export scope' );
check( 'unknown' === GE_WTP_Customer_Tax::resolve( $issuer, array( 'vat_status' => 'registered','country' => 'UY' ) )['suggested_document_class'], 'foreign receiver scope' );
$i = $issuer; $i['verification_status'] = 'pending'; $i['relationship_confirmed'] = false; $i['invoice_types_allowed'] = array();
$r = GE_WTP_Customer_Tax::resolve( $i, array( 'vat_status' => 'registered' ) );
check( count( $r['warnings'] ) >= 4 && $r['requires_review'], 'issuer and recipient guards' );
check( strpos( implode( ' ', GE_WTP_Customer_Tax::resolve( $issuer, array( 'vat_status' => 'monotributo' ) )['warnings'] ), '27.618' ) !== false, 'monotributo legend' );
$r = GE_WTP_Customer_Tax::resolve( $issuer, array( 'vat_status' => 'final_consumer' ) );
check( 'vat_applies' === $r['tax_treatment'] && 'itemized_transparency' === $r['vat_display_requirement'] && null === $r['tax_rate_basis_points'], 'B transparency and no inferred rate' );
$issuer['default_for_scenarios'] = array( 'invoice_a' );
$c = $issuer; $c['id'] = 'leonardo-c'; $c['vat_status'] = 'monotributo'; $c['invoice_types_allowed'] = array( 'C' ); $c['default_for_scenarios'] = array( 'common' );
GE_WTP_Billing_Issuers::$issuers = array( 'mardones-a' => $issuer, 'leonardo-c' => $c );
check( 'mardones-a' === GE_WTP_Customer_Tax::suggestion( array( 'vat_status' => 'monotributo' ) )['issuer_profile_id'], 'reuse real RI ID' );
check( 'leonardo-c' === GE_WTP_Customer_Tax::suggestion( array( 'vat_status' => 'final_consumer' ) )['issuer_profile_id'], 'reuse real C ID' );
$duplicate = $issuer; $duplicate['id'] = 'duplicate'; GE_WTP_Billing_Issuers::$issuers['duplicate'] = $duplicate;
check( 'unknown' === GE_WTP_Customer_Tax::suggestion( array( 'vat_status' => 'registered' ) )['issuer_profile_id'], 'ambiguous defaults never first-wins' );
GE_WTP_Billing_Issuers::$issuers = array( 'mardones-a' => $issuer, 'leonardo-c' => $c );
GE_WTP_Billing_Issuers::$issuers['mardones-a']['verification_status'] = 'pending';
check( 'leonardo-c' === GE_WTP_Customer_Tax::suggestion( array( 'vat_status' => 'registered' ) )['issuer_profile_id'], 'ready compatible issuer preferred over pending default' );
$i = $issuer; $i['invoice_types_allowed'] = array( 'A' );
$r = GE_WTP_Customer_Tax::resolve( $i, array( 'vat_status' => 'final_consumer' ) );
check( 'B' === $r['required_document_class'] && 'unknown' === $r['suggested_document_class'] && ! $r['compatible'] && ! $r['ready'], 'capability mismatch never looks compatible' );
check( GE_WTP_ARCA_Lookup::valid_cuit( '23-33692452-9' ), 'valid formatted CUIT' );
check( ! GE_WTP_ARCA_Lookup::valid_cuit( '23336924528' ), 'checksum invalid' );
check( ! GE_WTP_ARCA_Lookup::valid_cuit( '00000000000' ), 'invalid prefix' );
check( ! GE_WTP_ARCA_Lookup::valid_cuit( 'abc23336924529' ), 'reject junk rather than silently stripping' );
check( 'invalid_cuit' === GE_WTP_ARCA_Lookup::lookup( '23336924528' )['status'], 'invalid lookup no network' );
$transport = fixture( array( 'idImpuesto' => 30, 'descripcionImpuesto' => 'IVA', 'estadoImpuesto' => 'AC', 'periodo' => '202601' ) );
$r = GE_WTP_ARCA_Lookup::lookup( '23336924529' );
check( $r['verified'] && 'registered' === $r['profile']['vat_status'], 'official active IVA' );
check( is_string( $r['profile']['fiscal_address'] ) && 'TEST PERSONA' === $r['profile']['legal_name'], 'safe UI shape' );
check( isset( $r['last_valid']['profile']['tax_evidence'] ), 'valid cache has evidence' );
$transport = new WP_Error( 'timeout', 'SECRET MUST NEVER APPEAR' );
check( ! empty( GE_WTP_ARCA_Lookup::lookup( '23336924529' )['cache_hit'] ), 'positive TTL avoids transport' );
$failed = GE_WTP_ARCA_Lookup::lookup( '23336924529', true );
check( ! $failed['verified'] && 'error' === $failed['status'] && 'registered' === $failed['last_valid']['profile']['vat_status'], 'outage preserves last valid, never current' );
check( strpos( json_encode( $failed ), 'SECRET' ) === false, 'fault sanitized' );
check( 'error' === GE_WTP_ARCA_Lookup::cached( '23336924529' )['status'], 'last attempt persisted' );
check( ! empty( GE_WTP_ARCA_Lookup::lookup( '23336924529' )['cache_hit'] ), 'negative TTL avoids repeated outage calls' );
$transport = null;
$r = GE_WTP_ARCA_Lookup::lookup( '23336924529', true );
check( 'not_configured' === $r['status'] && $r['last_valid'] && ! $r['verified'], 'absent config supports manual and preserves valid cache' );
$f = fixture(); $f['personaReturn']['errorConstancia'] = array( 'error' => 'No existe el CUIT solicitado' );
check( 'not_found' === GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['status'], 'explicit not found' );
$f = fixture( array( 'idImpuesto' => 30,'estadoImpuesto' => 'AC' ) ); $f['personaReturn']['errorMonotributo'] = array( 'error' => 'Sección no disponible' );
check( ! GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['verified'], 'section error invalidates partial RI' );
$f = fixture( array( 'idImpuesto' => 30,'estadoImpuesto' => 'BA' ) );
check( 'unknown' === GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['profile']['vat_status'], 'inactive IVA not RI or consumer' );
$f = fixture( array( 'idImpuesto' => 32,'descripcionImpuesto' => 'NO VERIFIED MAPPING','estadoImpuesto' => 'AC' ) );
check( 'unknown' === GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['profile']['vat_status'], 'no guessed exemption mapping' );
$f = fixture( array(), array( 'impuesto' => array( 'idImpuesto' => 20,'estadoImpuesto' => 'AC' ), 'categoriaMonotributo' => array( 'idCategoria' => 1,'idImpuesto' => 20,'descripcionCategoria' => 'A' ) ) );
check( 'monotributo' === GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['profile']['vat_status'], 'active monotributo evidence' );
check( GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['verified'], 'active tax and complete category verified' );
unset( $f['personaReturn']['datosMonotributo']['categoriaMonotributo'] );
$r = GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' );
check( ! $r['verified'] && $r['identity_verified'] && 'monotributo' === $r['profile']['vat_status'] && 'partial' === $r['status'], 'active tax missing category partial indicative only' );
$f['personaReturn']['datosMonotributo']['categoriaMonotributo'] = array( 'idCategoria' => 0,'idImpuesto' => 20,'descripcionCategoria' => 'A' );
check( ! GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['verified'], 'active tax invalid category partial' );
$f = fixture( array(), array( 'categoriaMonotributo' => array( 'idCategoria' => 1,'idImpuesto' => 20,'descripcionCategoria' => 'A' ) ) );
check( 'unknown' === GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['profile']['vat_status'], 'category alone does not prove current monotributo' );
$f['personaReturn']['datosMonotributo']['categoriaMonotributo']['idImpuesto'] = 999;
check( 'unknown' === GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['profile']['vat_status'], 'other category not monotributo' );
$f = fixture( array( 'idImpuesto' => 30,'estadoImpuesto' => 'AC' ), array( 'impuesto' => array( 'idImpuesto' => 20,'estadoImpuesto' => 'AC' ) ) );
check( ! GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['verified'], 'conflicting categories review' );
$f = fixture(); $f['personaReturn']['datosGenerales']['idPersona'] = '20948548934';
check( ! GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['verified'], 'identity mismatch never verified' );
$f = fixture(); $f['personaReturn']['datosGenerales']['estadoClave'] = 'INACTIVO';
check( ! GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' )['verified'], 'inactive CUIT never verified' );
$f = fixture( array( 'idImpuesto' => 30,'estadoImpuesto' => 'AC' ) ); unset( $f['personaReturn']['datosGenerales']['domicilioFiscal']['direccion'] );
$r = GE_WTP_ARCA_Lookup::parse_response( $f, '23336924529' );
check( ! $r['verified'] && $r['identity_verified'] && 'pending' === $r['profile']['verification_status'], 'missing address is identity-only partial' );
$r = GE_WTP_ARCA_Lookup::parse_response( fixture(), '23336924529' );
check( ! $r['verified'] && $r['identity_verified'] && 'partial' === $r['status'], 'unknown VAT is identity-only partial' );
$profile = array( 'vat_status' => 'registered', 'verification_status' => 'verified', 'checked_at' => gmdate( 'c', time() - 60 ) );
$r = GE_WTP_Customer_Tax::resolve( $issuer, $profile );
check( $r['ready'] && $r['customer_evidence_fresh'] && $r['issuer_evidence_fresh'], 'recent evidence ready for review' );
foreach ( array( 'expired' => gmdate( 'c', time() - 86401 ), 'missing' => '', 'future' => gmdate( 'c', time() + 60 ), 'malformed' => 'tomorrow' ) as $label => $date ) {
    $p = $profile; $p['checked_at'] = $date;
    $r = GE_WTP_Customer_Tax::resolve( $issuer, $p );
    check( ! $r['ready'] && ! $r['customer_evidence_fresh'] && $r['customer_verified'] && $r['requires_review'], 'customer freshness ' . $label );
    check( $p['checked_at'] === $date, 'historical customer evidence unchanged ' . $label );
}
foreach ( array( 'expired' => gmdate( 'c', time() - 2592001 ), 'missing' => '', 'future' => gmdate( 'c', time() + 60 ), 'malformed' => 'next week' ) as $label => $date ) {
    $i = $issuer; $i['verified_at'] = $date;
    $r = GE_WTP_Customer_Tax::resolve( $i, $profile );
    check( ! $r['ready'] && ! $r['issuer_ready'] && ! $r['issuer_evidence_fresh'] && $r['requires_review'], 'issuer freshness ' . $label );
    check( $i['verified_at'] === $date, 'historical issuer evidence unchanged ' . $label );
}
$p = $profile; unset( $p['checked_at'] ); $p['verified_at'] = gmdate( 'c', time() - 60 );
check( GE_WTP_Customer_Tax::resolve( $issuer, $p )['customer_evidence_fresh'], 'customer verified_at fallback' );
$p['verified_at'] = gmdate( 'Y-m', time() ) . '-32T00:00:00+00:00';
check( ! GE_WTP_Customer_Tax::resolve( $issuer, $p )['customer_evidence_fresh'], 'invalid calendar date rejected without normalization' );
echo 'PASS ' . $checks . " backend checks\n";
