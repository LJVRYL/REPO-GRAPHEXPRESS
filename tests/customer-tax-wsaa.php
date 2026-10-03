<?php
/** Offline WSAA adapter test: ephemeral key, real CMS signature, simulated SOAP only. */
if ( extension_loaded( 'soap' ) ) { echo "SKIP: run php -n -d extension=simplexml tests/customer-tax-wsaa.php\n"; exit( 0 ); }
define( 'ABSPATH', __DIR__ . '/fixture-wordpress/' );
define( 'SOAP_1_1', 1 ); define( 'WSDL_CACHE_NONE', 0 );
class SoapClient {
    public static $wsdl; public static $options; public static $cms; public static $reply = 'valid';
    public function __construct( $wsdl, $options ) { self::$wsdl = $wsdl; self::$options = $options; }
    public function __soapCall( $method, $args ) {
        if ( 'loginCms' !== $method ) { throw new RuntimeException( 'Unexpected network method' ); }
        self::$cms = $args[0]['in0'];
        if ( 'doctype' === self::$reply ) { return (object) array( 'loginCmsReturn' => '<!DOCTYPE test [<!ENTITY x SYSTEM "file:///etc/passwd">]><loginTicketResponse/>' ); }
        $expiry = 'expired' === self::$reply ? time() - 100 : time() + 3600;
        return (object) array( 'loginCmsReturn' => '<loginTicketResponse><header><expirationTime>' . gmdate( 'c', $expiry ) . '</expirationTime></header><credentials><token>fixture-token</token><sign>fixture-sign</sign></credentials></loginTicketResponse>' );
    }
}
require dirname( __DIR__ ) . '/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-arca-lookup.php';
$checks = 0;
function check( $ok, $label ) { global $checks; $checks++; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } }
$dir = sys_get_temp_dir() . '/ge-wsaa-test-' . bin2hex( random_bytes( 8 ) );
mkdir( $dir, 0700 );
try {
    $key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
    $csr = openssl_csr_new( array( 'commonName' => 'Offline test fixture' ), $key, array( 'digest_alg' => 'sha256' ) );
    $cert = openssl_csr_sign( $csr, null, $key, 1, array( 'digest_alg' => 'sha256' ) );
    openssl_pkey_export( $key, $private ); openssl_x509_export( $cert, $public );
    file_put_contents( $dir . '/key.pem', $private ); file_put_contents( $dir . '/cert.pem', $public );
    chmod( $dir . '/key.pem', 0600 ); chmod( $dir . '/cert.pem', 0600 );
    unset( $private );
    $config = array( 'environment' => 'production', 'represented_cuit' => '23336924529', 'certificate_path' => $dir . '/cert.pem', 'private_key_path' => $dir . '/key.pem', 'runtime_dir' => $dir );
    $method = new ReflectionMethod( 'GE_WTP_ARCA_Lookup', 'authenticate' ); $method->setAccessible( true );
    $ticket = $method->invoke( null, $config );
    check( 'fixture-token' === $ticket['token'] && $ticket['expires'] > time(), 'valid SOAP ticket parsed' );
    check( 'https://wsaa.afip.gov.ar/ws/services/LoginCms?WSDL' === SoapClient::$wsdl, 'official WSAA production host' );
    check( false === SoapClient::$options['trace'] && 10 === SoapClient::$options['connection_timeout'], 'trace disabled and timeout set' );
    $context = stream_context_get_options( SoapClient::$options['stream_context'] );
    check( $context['ssl']['verify_peer'] && $context['ssl']['verify_peer_name'] && 15 === $context['http']['timeout'], 'TLS validation and read timeout' );
    check( false !== base64_decode( SoapClient::$cms, true ), 'actual CMS base64' );
    $mime = "MIME-Version: 1.0\nContent-Type: application/x-pkcs7-mime; smime-type=signed-data; name=smime.p7m\nContent-Transfer-Encoding: base64\n\n" . chunk_split( SoapClient::$cms, 64, "\n" );
    file_put_contents( $dir . '/signed.mime', $mime );
    check( openssl_pkcs7_verify( $dir . '/signed.mime', PKCS7_NOVERIFY, $dir . '/signer.pem', array(), null, $dir . '/verified.xml' ), 'actual CMS signature verifies' );
    $xml = file_get_contents( $dir . '/verified.xml' );
    check( strpos( $xml, '<service>ws_sr_constancia_inscripcion</service>' ) !== false, 'TRA requests correct service' );
    $config['environment'] = 'homologation'; $method->invoke( null, $config );
    check( 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL' === SoapClient::$wsdl, 'official WSAA homologation host' );
    foreach ( array( 'expired','doctype' ) as $reply ) {
        SoapClient::$reply = $reply; $rejected = false;
        try { $method->invoke( null, $config ); } catch ( RuntimeException $exception ) { $rejected = true; }
        check( $rejected, 'reject unsafe ticket ' . $reply );
    }
    check( 0 === count( glob( $dir . '/tra-*' ) ) && 0 === count( glob( $dir . '/cms-*' ) ), 'signing temporary files always removed' );
    echo 'PASS ' . $checks . " WSAA offline checks\n";
} finally {
    foreach ( scandir( $dir ) as $name ) { if ( '.' !== $name && '..' !== $name && is_file( $dir . '/' . $name ) ) { unlink( $dir . '/' . $name ); } }
    rmdir( $dir );
}
