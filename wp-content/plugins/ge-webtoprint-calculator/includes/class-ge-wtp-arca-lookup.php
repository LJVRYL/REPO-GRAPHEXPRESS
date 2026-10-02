<?php
defined( 'ABSPATH' ) || exit;

/** Read-only WSCI adapter. Secrets and WSAA tickets stay outside WordPress and Git. */
final class GE_WTP_ARCA_Lookup {
    const SERVICE = 'ws_sr_constancia_inscripcion';
    const SOURCE = 'arca_wsci';
    const MANUAL = 'https://www.arca.gob.ar/ws/WSCI/manual_ws_sr_ws_constancia_inscripcion.pdf';
    const CACHE_PREFIX = 'ge_arca_customer_lookup_v1_';

    public static function normalize_cuit( $cuit ) {
        $value = trim( (string) $cuit );
        if ( ! preg_match( '/^(?:[0-9]{11}|[0-9]{2}-[0-9]{8}-[0-9])$/D', $value ) ) { return ''; }
        return str_replace( '-', '', $value );
    }
    public static function valid_cuit( $cuit ) {
        $cuit = self::normalize_cuit( $cuit );
        if ( strlen( $cuit ) !== 11 || ! in_array( substr( $cuit, 0, 2 ), array( '20','23','24','27','30','33','34' ), true ) ) { return false; }
        $sum = 0; $weights = array( 5,4,3,2,7,6,5,4,3,2 );
        for ( $i = 0; $i < 10; $i++ ) { $sum += (int) $cuit[$i] * $weights[$i]; }
        $digit = ( 11 - $sum % 11 ) % 11;
        return $digit < 10 && (int) $cuit[10] === $digit;
    }
    private static function result( $cuit, $status, $error = '' ) {
        return array( 'status' => $status, 'verified' => false, 'identity_verified' => false, 'cuit' => $cuit,
            'checked_at' => gmdate( 'c' ), 'source' => self::SOURCE, 'source_url' => self::MANUAL,
            'profile' => array( 'cuit' => $cuit, 'legal_name' => '', 'fiscal_address' => '', 'vat_status' => 'unknown', 'tax_status' => 'unknown', 'verification_status' => 'pending', 'source' => self::SOURCE ),
            'error_code' => $error, 'warnings' => array(), 'last_valid' => null );
    }
    private static function cache_key( $cuit ) { return self::CACHE_PREFIX . hash( 'sha256', $cuit ); }
    /** An explicit user action calls this method; never attach it to rendering hooks. */
    public static function lookup( $cuit, $force = false ) {
        $cuit = self::normalize_cuit( $cuit );
        if ( ! self::valid_cuit( $cuit ) ) { return self::result( $cuit, 'invalid_cuit', 'invalid_cuit' ); }
        $key = self::cache_key( $cuit );
        $stored = (array) get_option( $key, array() );
        $ttl = ! empty( $stored['verified'] ) ? 86400 : 120;
        $checked = strtotime( (string) ( $stored['checked_at'] ?? '' ) );
        if ( ! $force && $checked && $checked <= time() && $checked + $ttl > time() ) { $stored['cache_hit'] = true; return $stored; }
        // Trusted plugins may supply a transport adapter; browser input cannot select one.
        $response = apply_filters( 'ge_wtp_arca_wsci_response', null, $cuit );
        if ( null === $response ) {
            $config = self::configuration();
            if ( ! $config ) { $result = self::result( $cuit, 'not_configured', 'not_configured' ); }
            else {
                try { $response = self::request_person( $cuit, $config ); }
                catch ( Throwable $exception ) { $response = new WP_Error( 'arca_unavailable', 'ARCA no disponible.' ); }
            }
        }
        if ( ! isset( $result ) ) {
            $result = is_wp_error( $response ) ? self::result( $cuit, 'error', 'arca_unavailable' ) : self::parse_response( $response, $cuit );
        }
        // Failed/partial lookups never replace a valid snapshot or make it current.
        $last = isset( $stored['last_valid'] ) && is_array( $stored['last_valid'] ) ? $stored['last_valid'] : null;
        if ( $result['verified'] ) { $last = $result; unset( $last['last_valid'] ); }
        $result['last_valid'] = $last;
        update_option( $key, $result, false );
        return $result;
    }
    public static function cached( $cuit ) {
        $cuit = self::normalize_cuit( $cuit );
        return self::valid_cuit( $cuit ) ? get_option( self::cache_key( $cuit ), null ) : null;
    }
    private static function arrayify( $value ) {
        if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
        if ( is_array( $value ) ) { foreach ( $value as $key => $item ) { $value[$key] = self::arrayify( $item ); } }
        return $value;
    }
    private static function text( $value ) {
        return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
    }
    private static function listify( $value ) {
        $value = (array) $value;
        return isset( $value['idImpuesto'] ) ? array( $value ) : $value;
    }
    private static function active_tax( $section, $id ) {
        foreach ( self::listify( $section['impuesto'] ?? array() ) as $tax ) {
            if ( is_array( $tax ) && (string) $id === (string) ( $tax['idImpuesto'] ?? '' ) && 'AC' === strtoupper( (string) ( $tax['estadoImpuesto'] ?? '' ) ) ) { return true; }
        }
        return false;
    }
    private static function tax_evidence( $section ) {
        $result = array();
        foreach ( self::listify( $section['impuesto'] ?? array() ) as $tax ) {
            if ( ! is_array( $tax ) ) { continue; }
            $item = array();
            foreach ( array( 'idImpuesto','descripcionImpuesto','estadoImpuesto','periodo','motivo' ) as $key ) {
                if ( isset( $tax[$key] ) ) { $item[$key] = self::text( $tax[$key] ); }
            }
            if ( $item ) { $result[] = $item; }
        }
        return $result;
    }
    /** Public for deterministic fixture testing; no classification by absence or unverified tax IDs. */
    public static function parse_response( $response, $cuit ) {
        $r = self::result( $cuit, 'partial', 'incomplete_response' );
        $data = self::arrayify( $response );
        if ( ! is_array( $data ) ) { return $r; }
        $data = $data['personaReturn'] ?? $data['return'] ?? $data;
        if ( ! is_array( $data ) ) { return $r; }
        foreach ( array( 'errorConstancia','errorRegimenGeneral','errorMonotributo' ) as $key ) {
            if ( ! empty( $data[$key] ) ) {
                $r['error_code'] = 'section_error';
                $r['warnings'][] = 'ARCA devolvió un error en una sección; los datos no se consideran verificados.';
                // Not-found uses the service's explicit error text; never treat it as consumer status.
                $encoded = json_encode( $data[$key] );
                if ( 'errorConstancia' === $key && preg_match( '/no (?:se encuentra|existe)|inexistente|no encontrad/iu', $encoded ) ) { $r['status'] = 'not_found'; $r['error_code'] = 'not_found'; }
                return $r;
            }
        }
        $general = (array) ( $data['datosGenerales'] ?? array() );
        if ( (string) ( $general['idPersona'] ?? '' ) !== $cuit || 'CUIT' !== strtoupper( (string) ( $general['tipoClave'] ?? '' ) ) || 'ACTIVO' !== strtoupper( (string) ( $general['estadoClave'] ?? '' ) ) ) {
            $r['error_code'] = 'identity_or_status_mismatch'; return $r;
        }
        $name = self::text( $general['razonSocial'] ?? '' );
        if ( ! $name ) { $name = trim( self::text( $general['apellido'] ?? '' ) . ' ' . self::text( $general['nombre'] ?? '' ) ); }
        if ( ! $name ) { return $r; }
        $address = (array) ( $general['domicilioFiscal'] ?? array() );
        $parts = array();
        foreach ( array( 'direccion','localidad','descripcionProvincia','codPostal' ) as $field ) {
            $part = self::text( $address[$field] ?? '' ); if ( $part ) { $parts[] = $part; }
        }
        $vat = 'unknown';
        $rg = (array) ( $data['datosRegimenGeneral'] ?? array() ); $mono = (array) ( $data['datosMonotributo'] ?? array() );
        $ri = self::active_tax( $rg, 30 );
        $category = (array) ( $mono['categoriaMonotributo'] ?? array() );
        // The schema's category ID is positive only for a valid reported category.
        $category_valid = '20' === self::text( $category['idImpuesto'] ?? '' ) && preg_match( '/^[1-9][0-9]*$/D', self::text( $category['idCategoria'] ?? '' ) ) && '' !== self::text( $category['descripcionCategoria'] ?? '' );
        // A category may be historical: an active monotributo tax is required.
        $mt = self::active_tax( $mono, 20 );
        if ( $ri && $mt ) { $r['error_code'] = 'conflicting_tax_status'; return $r; }
        if ( $ri ) { $vat = 'registered'; } elseif ( $mt ) { $vat = 'monotributo'; }
        // No verified mapping for exemption IDs: unknown must remain unknown.
        $r['profile'] = array( 'cuit' => $cuit, 'legal_name' => $name, 'fiscal_address' => implode( ', ', $parts ),
            'vat_status' => $vat, 'tax_status' => $vat, 'verification_status' => 'pending',
            'checked_at' => $r['checked_at'], 'source' => self::SOURCE, 'source_url' => self::MANUAL,
            'arca_tipo_clave' => 'CUIT', 'arca_estado_clave' => 'ACTIVO' );
        $category_evidence = array();
        foreach ( array( 'idCategoria','idImpuesto','descripcionCategoria','periodo' ) as $key ) { if ( isset( $category[$key] ) ) { $category_evidence[$key] = self::text( $category[$key] ); } }
        $r['profile']['tax_evidence'] = array( 'regimen_general' => self::tax_evidence( $rg ), 'monotributo' => self::tax_evidence( $mono ), 'categoria_monotributo' => $category_evidence );
        $r['identity_verified'] = true;
        if ( 'unknown' === $vat || empty( $address['direccion'] ) || ( $mt && ! $category_valid ) ) {
            $r['warnings'][] = 'ARCA verificó la identidad; la condición fiscal o el domicilio requieren revisión.';
            if ( $category_valid && ! $mt ) { $r['warnings'][] = 'La categoría de monotributo sin impuesto activo no prueba una condición vigente.'; }
            if ( $mt && ! $category_valid ) { $r['warnings'][] = 'El impuesto de monotributo está activo, pero la categoría falta o es inválida; la verificación fiscal es parcial.'; }
            return $r;
        }
        $r['status'] = 'verified'; $r['verified'] = true; $r['error_code'] = '';
        $r['profile']['verification_status'] = 'verified'; $r['profile']['verified_at'] = $r['checked_at'];
        return $r;
    }

    private static function outside_public_tree( $path ) {
        $real = realpath( $path ); if ( false === $real ) { return false; }
        $real = str_replace( '\\', '/', $real );
        $roots = array( ABSPATH, dirname( __DIR__ ) );
        if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) { $roots[] = $_SERVER['DOCUMENT_ROOT']; }
        foreach ( $roots as $root ) {
            $root = realpath( $root );
            if ( false !== $root && 0 === stripos( $real . '/', rtrim( str_replace( '\\', '/', $root ), '/' ) . '/' ) ) { return false; }
        }
        // Reject any ancestor tracked as a repository, even when outside the public tree.
        $dir = is_dir( $real ) ? $real : dirname( $real );
        while ( dirname( $dir ) !== $dir ) { if ( file_exists( $dir . '/.git' ) ) { return false; } $dir = dirname( $dir ); }
        return $real;
    }
    private static function configuration() {
        if ( ! defined( 'GE_WTP_ARCA_CONFIG_FILE' ) ) { return null; }
        $path = self::outside_public_tree( GE_WTP_ARCA_CONFIG_FILE );
        if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) { return null; }
        try { $config = require $path; } catch ( Throwable $exception ) { return null; }
        if ( ! is_array( $config ) || ! in_array( $config['environment'] ?? '', array( 'production','homologation' ), true ) || ! self::valid_cuit( $config['represented_cuit'] ?? '' ) ) { return null; }
        foreach ( array( 'certificate_path','private_key_path','runtime_dir' ) as $key ) {
            $real = self::outside_public_tree( $config[$key] ?? '' ); if ( ! $real ) { return null; } $config[$key] = $real;
        }
        if ( ! is_file( $config['certificate_path'] ) || ! is_readable( $config['certificate_path'] ) || ! is_file( $config['private_key_path'] ) || ! is_readable( $config['private_key_path'] ) || ! is_dir( $config['runtime_dir'] ) || ! is_writable( $config['runtime_dir'] ) ) { return null; }
        // Secure permissions are required on Unix; runtime is never a public or Git path.
        if ( '/' === DIRECTORY_SEPARATOR ) {
            foreach ( array( $path, $config['private_key_path'], $config['runtime_dir'] ) as $file ) { if ( fileperms( $file ) & 0077 ) { return null; } }
        }
        $config['represented_cuit'] = self::normalize_cuit( $config['represented_cuit'] );
        return $config;
    }
    private static function client( $wsdl ) {
        if ( ! class_exists( 'SoapClient' ) ) { throw new RuntimeException( 'SOAP unavailable' ); }
        return new SoapClient( $wsdl, array( 'soap_version' => SOAP_1_1, 'trace' => false, 'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE, 'connection_timeout' => 10,
            'stream_context' => stream_context_create( array( 'http' => array( 'timeout' => 15 ), 'ssl' => array( 'verify_peer' => true, 'verify_peer_name' => true ) ) ) ) );
    }
    private static function request_person( $cuit, $config ) {
        $ticket = self::ticket( $config );
        $host = 'production' === $config['environment'] ? 'aws.arca.gob.ar' : 'awshomo.arca.gob.ar';
        $client = self::client( 'https://' . $host . '/sr-padron/webservices/personaServiceA5?WSDL' );
        return $client->__soapCall( 'getPersona_v2', array( array( 'token' => $ticket['token'], 'sign' => $ticket['sign'], 'cuitRepresentada' => $config['represented_cuit'], 'idPersona' => $cuit ) ) );
    }
    private static function ticket( $config ) {
        $identity = $config['environment'] . '|' . $config['represented_cuit'] . '|' . hash_file( 'sha256', $config['certificate_path'] );
        $path = $config['runtime_dir'] . '/wsaa-' . hash( 'sha256', $identity ) . '.json';
        // c+ plus an exclusive lock avoids two WSAA requests for the same certificate.
        if ( is_link( $path ) ) { throw new RuntimeException( 'Unsafe ticket path' ); }
        $file = fopen( $path, 'c+' ); if ( ! $file ) { throw new RuntimeException( 'Ticket cache unavailable' ); }
        chmod( $path, 0600 );
        try {
            if ( ! flock( $file, LOCK_EX | LOCK_NB ) ) { throw new RuntimeException( 'Ticket cache busy' ); }
            $cached = json_decode( stream_get_contents( $file ), true );
            if ( is_array( $cached ) && ! empty( $cached['token'] ) && ! empty( $cached['sign'] ) && (int) ( $cached['expires'] ?? 0 ) > time() + 300 ) { return $cached; }
            $ticket = self::authenticate( $config );
            rewind( $file ); ftruncate( $file, 0 ); fwrite( $file, json_encode( $ticket ) ); fflush( $file );
            return $ticket;
        } finally { flock( $file, LOCK_UN ); fclose( $file ); }
    }
    private static function authenticate( $config ) {
        if ( ! function_exists( 'openssl_pkcs7_sign' ) || ! function_exists( 'simplexml_load_string' ) ) { throw new RuntimeException( 'Authentication runtime unavailable' ); }
        $now = time();
        $request = '<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"><header><uniqueId>' . $now . '</uniqueId><generationTime>' . gmdate( 'c', $now - 300 ) . '</generationTime><expirationTime>' . gmdate( 'c', $now + 3600 ) . '</expirationTime></header><service>' . self::SERVICE . '</service></loginTicketRequest>';
        $input = tempnam( $config['runtime_dir'], 'tra-' ); $output = tempnam( $config['runtime_dir'], 'cms-' );
        if ( ! $input || ! $output ) { if ( $input ) { unlink( $input ); } if ( $output ) { unlink( $output ); } throw new RuntimeException( 'Secure temporary files unavailable' ); }
        chmod( $input, 0600 ); chmod( $output, 0600 );
        try {
            if ( false === file_put_contents( $input, $request ) ) { throw new RuntimeException( 'Cannot create login request' ); }
            $key = openssl_pkey_get_private( 'file://' . $config['private_key_path'], (string) ( $config['private_key_passphrase'] ?? '' ) );
            if ( ! $key || ! openssl_pkcs7_sign( $input, $output, 'file://' . $config['certificate_path'], $key, array(), PKCS7_BINARY | PKCS7_NOATTR ) ) { throw new RuntimeException( 'Cannot sign login request' ); }
            $mime = file_get_contents( $output );
            $parts = preg_split( '/\r?\n\r?\n/', $mime, 2 );
            $cms = isset( $parts[1] ) ? preg_replace( '/\s+/', '', $parts[1] ) : '';
            if ( ! $cms || false === base64_decode( $cms, true ) ) { throw new RuntimeException( 'Invalid CMS output' ); }
            $host = 'production' === $config['environment'] ? 'wsaa.afip.gov.ar' : 'wsaahomo.afip.gov.ar';
            $response = self::client( 'https://' . $host . '/ws/services/LoginCms?WSDL' )->__soapCall( 'loginCms', array( array( 'in0' => $cms ) ) );
            $xml = is_object( $response ) ? (string) ( $response->loginCmsReturn ?? '' ) : (string) $response;
            if ( stripos( $xml, '<!DOCTYPE' ) !== false || stripos( $xml, '<!ENTITY' ) !== false ) { throw new RuntimeException( 'Unsafe login response' ); }
            $previous = libxml_use_internal_errors( true );
            try { $ticket = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET ); }
            finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
            $expiry = $ticket ? strtotime( (string) $ticket->header->expirationTime ) : false;
            if ( ! $ticket || ! $expiry || $expiry <= time() + 300 || ! (string) $ticket->credentials->token || ! (string) $ticket->credentials->sign ) { throw new RuntimeException( 'Invalid login ticket' ); }
            return array( 'token' => (string) $ticket->credentials->token, 'sign' => (string) $ticket->credentials->sign, 'expires' => $expiry );
        } finally { unlink( $input ); unlink( $output ); }
    }
}
