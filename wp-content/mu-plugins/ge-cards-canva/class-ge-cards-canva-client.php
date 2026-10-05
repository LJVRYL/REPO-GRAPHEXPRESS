<?php
/** Canva protocol only. Credentials/tokens are never sent to the browser or logs. */
defined( 'ABSPATH' ) || exit;
final class GE_Cards_Canva_Client {
    const API = 'https://api.canva.com/rest/v1';
    const SCOPES = 'design:meta:read design:content:read';
    private $client_id, $secret, $redirect, $transport, $clock;
    public function __construct( $client_id, $secret, $redirect, $transport, $clock = null ) {
        if ( ! preg_match( '/^[A-Za-z0-9_-]{1,200}$/D', $client_id ) || strlen( $secret ) < 16 || strlen( $secret ) > 256 || ! self::https_host( $redirect, array( 'graphex.ar' ) ) ) { throw new RuntimeException( 'La conexión Canva no está configurada.' ); }
        $this->client_id = $client_id; $this->secret = $secret; $this->redirect = $redirect;
        $this->transport = $transport; $this->clock = $clock ?: 'time';
    }
    private function now() { return (int) call_user_func( $this->clock ); }
    public static function random( $bytes = 32 ) { return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); }
    public static function https_host( $url, $hosts ) {
        $p = parse_url( $url );
        return is_array( $p ) && ( $p['scheme'] ?? '' ) === 'https' && in_array( $p['host'] ?? '', $hosts, true ) && ! isset( $p['user'] ) && ! isset( $p['pass'] ) && ( ! isset( $p['port'] ) || $p['port'] === 443 );
    }
    public static function id( $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $id ) ) { throw new RuntimeException( 'Identificador Canva inválido.' ); }
        return $id;
    }
    public function start() {
        $flow = array( 'state' => self::random(), 'verifier' => self::random( 64 ), 'expires' => $this->now() + 600 );
        $challenge = rtrim( strtr( base64_encode( hash( 'sha256', $flow['verifier'], true ) ), '+/', '-_' ), '=' );
        $url = 'https://www.canva.com/api/oauth/authorize?' . http_build_query( array( 'client_id' => $this->client_id, 'redirect_uri' => $this->redirect, 'response_type' => 'code', 'scope' => self::SCOPES, 'state' => $flow['state'], 'code_challenge' => $challenge, 'code_challenge_method' => 'S256' ), '', '&', PHP_QUERY_RFC3986 );
        return array( 'flow' => $flow, 'url' => $url );
    }
    private function request( $method, $path, $headers = array(), $body = null ) {
        $url = self::API . $path;
        if ( ! self::https_host( $url, array( 'api.canva.com' ) ) ) { throw new RuntimeException( 'Destino API inválido.' ); }
        $result = call_user_func( $this->transport, $method, $url, $headers, $body );
        if ( ! is_array( $result ) ) { throw new RuntimeException( 'Respuesta Canva incompleta.' ); }
        return $result;
    }
    private function tokens( $data ) {
        $r = $this->request( 'POST', '/oauth/token', array( 'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->secret ), 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( $data, '', '&', PHP_QUERY_RFC3986 ) );
        if ( empty( $r['access_token'] ) || empty( $r['refresh_token'] ) || strtolower( $r['token_type'] ?? '' ) !== 'bearer' || ! is_numeric( $r['expires_in'] ?? null ) || $r['expires_in'] <= 0 || $r['expires_in'] > 86400 ) { throw new RuntimeException( 'Autorización Canva incompleta.' ); }
        foreach ( array( 'access_token', 'refresh_token' ) as $field ) { if ( ! is_string( $r[$field] ) || strlen( $r[$field] ) > 8192 || preg_match( '/[\x00-\x20\x7f]/', $r[$field] ) ) { throw new RuntimeException( 'Autorización Canva inválida.' ); } }
        return array( 'access_token' => $r['access_token'], 'refresh_token' => $r['refresh_token'], 'expires' => $this->now() + (int) $r['expires_in'] );
    }
    public function callback( $flow, $state, $code ) {
        if ( ! is_array( $flow ) || ( $flow['expires'] ?? 0 ) < $this->now() || ! is_string( $state ) || ! hash_equals( $flow['state'] ?? '', $state ) || ! is_string( $code ) || ! $code || strlen( $code ) > 4096 ) { throw new RuntimeException( 'La autorización venció o no corresponde a esta sesión.' ); }
        $tokens = $this->tokens( array( 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $flow['verifier'], 'redirect_uri' => $this->redirect ) );
        $identity = $this->request( 'GET', '/users/me', array( 'Authorization' => 'Bearer ' . $tokens['access_token'] ) )['team_user'] ?? array();
        self::id( $identity['user_id'] ?? null ); self::id( $identity['team_id'] ?? null );
        return array( 'tokens' => $tokens, 'identity' => array( 'user_id' => $identity['user_id'], 'team_id' => $identity['team_id'] ) );
    }
    private function api( &$context, $method, $path, $body = null ) {
        if ( empty( $context['tokens']['refresh_token'] ) ) { throw new RuntimeException( 'Conectá tu cuenta de Canva primero.' ); }
        if ( ( $context['tokens']['expires'] ?? 0 ) < $this->now() + 60 ) { $context['tokens'] = $this->tokens( array( 'grant_type' => 'refresh_token', 'refresh_token' => $context['tokens']['refresh_token'] ) ); }
        return $this->request( $method, $path, array( 'Authorization' => 'Bearer ' . $context['tokens']['access_token'], 'Content-Type' => 'application/json' ), null === $body ? null : json_encode( $body ) );
    }
    public function designs( &$context, $continuation = '' ) {
        if ( ! is_string( $continuation ) || strlen( $continuation ) > 2048 ) { throw new RuntimeException( 'Paginación inválida.' ); }
        $query = array( 'ownership' => 'owned', 'sort_by' => 'modified_descending', 'limit' => 20 );
        if ( $continuation ) { $query['continuation'] = $continuation; }
        $r = $this->api( $context, 'GET', '/designs?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
        $items = array();
        foreach ( array_slice( $r['items'] ?? array(), 0, 20 ) as $d ) {
            if ( ( $d['owner']['user_id'] ?? '' ) !== $context['identity']['user_id'] ) { continue; }
            $items[] = array( 'id' => self::id( $d['id'] ), 'title' => mb_substr( (string) ( $d['title'] ?? 'Diseño sin título' ), 0, 200 ), 'page_count' => isset( $d['page_count'] ) ? (int) $d['page_count'] : null );
        }
        return array( 'items' => $items, 'continuation' => $r['continuation'] ?? null );
    }
    public function design( &$context, $id ) {
        $d = $this->api( $context, 'GET', '/designs/' . self::id( $id ) )['design'] ?? array();
        if ( ( $d['id'] ?? '' ) !== $id || ( $d['owner']['user_id'] ?? '' ) !== ( $context['identity']['user_id'] ?? null ) || ( $d['owner']['team_id'] ?? '' ) !== ( $context['identity']['team_id'] ?? null ) ) { throw new RuntimeException( 'Elegí un diseño propio de la cuenta Canva conectada.' ); }
        return $d;
    }
    public function edit( &$context, $id ) {
        $d = $this->design( $context, $id ); $url = $d['urls']['edit_url'] ?? '';
        if ( ! self::https_host( $url, array( 'www.canva.com' ) ) || strlen( $url ) > 4096 || null !== parse_url( $url, PHP_URL_FRAGMENT ) ) { throw new RuntimeException( 'Canva no devolvió un enlace válido de edición.' ); }
        $correlation = self::random( 24 );
        return array( 'url' => $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'correlation_state=' . rawurlencode( $correlation ), 'pending' => array( 'design_id' => $id, 'correlation_state' => $correlation, 'expires' => $this->now() + 86400 ) );
    }
    private static function unbase64( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) { throw new RuntimeException( 'Firma de regreso inválida.' ); }
        $decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
        if ( false === $decoded ) { throw new RuntimeException( 'Firma de regreso inválida.' ); }
        return $decoded;
    }
    public function verify_return( &$context, $jwt, $jwks, $pending ) {
        if ( ! is_string( $jwt ) || strlen( $jwt ) > 16384 || count( $parts = explode( '.', $jwt ) ) !== 3 ) { throw new RuntimeException( 'Regreso Canva inválido.' ); }
        $header = json_decode( self::unbase64( $parts[0] ), true, 8 );
        $payload = json_decode( self::unbase64( $parts[1] ), true, 8 );
        if ( ! is_array( $header ) || ! is_array( $payload ) || ( $header['alg'] ?? '' ) !== 'EdDSA' || ! is_string( $header['kid'] ?? null ) || isset( $header['crit'] ) ) { throw new RuntimeException( 'Firma de regreso inválida.' ); }
        $signature = self::unbase64( $parts[2] ); $valid = false;
        foreach ( array_slice( $jwks['keys'] ?? array(), 0, 20 ) as $key ) {
            if ( ( $key['kid'] ?? '' ) !== $header['kid'] || ( $key['kty'] ?? '' ) !== 'OKP' || ( $key['crv'] ?? '' ) !== 'Ed25519' || ! empty( $key['alg'] ) && $key['alg'] !== 'EdDSA' || isset( $key['use'] ) && $key['use'] !== 'sig' ) { continue; }
            $public = self::unbase64( $key['x'] ?? '' );
            if ( strlen( $public ) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && strlen( $signature ) === SODIUM_CRYPTO_SIGN_BYTES ) { $valid = sodium_crypto_sign_verify_detached( $signature, $parts[0] . '.' . $parts[1], $public ); }
        }
        if ( ! $valid ) { throw new RuntimeException( 'No pudimos verificar la firma del regreso Canva.' ); }
        foreach ( array( 'aud', 'exp', 'sub', 'team_id', 'type', 'jti', 'design_id', 'correlation_state' ) as $claim ) { if ( ! array_key_exists( $claim, $payload ) ) { throw new RuntimeException( 'Regreso Canva incompleto.' ); } }
        $aud = is_array( $payload['aud'] ) ? $payload['aud'] : array( $payload['aud'] );
        if ( ! in_array( $this->client_id, $aud, true ) || ! is_int( $payload['exp'] ) || $payload['exp'] < $this->now() || $payload['exp'] > $this->now() + 86415 || isset( $payload['nbf'] ) && $payload['nbf'] > $this->now() + 15 || isset( $payload['iat'] ) && $payload['iat'] > $this->now() + 15 || $payload['sub'] !== $context['identity']['user_id'] || $payload['team_id'] !== $context['identity']['team_id'] || $payload['type'] !== 'rti' ) { throw new RuntimeException( 'El regreso no corresponde a tu cuenta o está vencido.' ); }
        self::id( $payload['jti'] ); self::id( $payload['design_id'] );
        if ( ! is_array( $pending ) || ( $pending['expires'] ?? 0 ) < $this->now() || ! is_string( $payload['correlation_state'] ) || ! hash_equals( $pending['correlation_state'] ?? '', $payload['correlation_state'] ) || ( $pending['design_id'] ?? '' ) !== $payload['design_id'] ) { throw new RuntimeException( 'El regreso no corresponde al diseño y sesión originales.' ); }
        $this->design( $context, $payload['design_id'] );
        return array( 'design_id' => $payload['design_id'], 'jti' => $payload['jti'], 'expires' => $payload['exp'] );
    }
    public function export( &$context, $id, $pages ) {
        if ( ! in_array( $pages, array( 1, 2 ), true ) ) { throw new RuntimeException( 'Cantidad de páginas inválida.' ); }
        $d = $this->design( $context, $id );
        if ( ( $d['page_count'] ?? 0 ) !== $pages ) { throw new RuntimeException( 'El diseño debe tener ' . $pages . ' página(s), según la impresión elegida.' ); }
        $r = $this->api( $context, 'POST', '/exports', array( 'design_id' => $id, 'format' => array( 'type' => 'pdf', 'export_quality' => 'pro', 'pages' => range( 1, $pages ) ) ) );
        return array( 'id' => self::id( $r['job']['id'] ?? null ), 'design_id' => $id, 'expires' => $this->now() + 600, 'last_poll' => 0, 'result' => null );
    }
    public function poll( &$context, &$job ) {
        if ( ( $job['expires'] ?? 0 ) < $this->now() ) { throw new RuntimeException( 'La exportación venció. Volvé a solicitarla.' ); }
        if ( ! empty( $job['result'] ) ) { return $job['result']; }
        if ( $job['last_poll'] && $this->now() - $job['last_poll'] < 3 ) { return array( 'status' => 'in_progress' ); }
        $job['last_poll'] = $this->now();
        $r = $this->api( $context, 'GET', '/exports/' . self::id( $job['id'] ) )['job'] ?? array();
        if ( ( $r['status'] ?? '' ) === 'failed' ) { throw new RuntimeException( 'Canva no pudo exportar el diseño. Revisá el plan, los elementos premium y los permisos; podés descargarlo y subirlo manualmente.' ); }
        if ( ( $r['status'] ?? '' ) !== 'success' ) { return array( 'status' => 'in_progress' ); }
        $urls = $r['urls'] ?? array();
        if ( count( $urls ) !== 1 || ! self::https_host( $urls[0], array( 'export-download.canva.com', 'document-export.canva.com' ) ) || strlen( $urls[0] ) > 8192 ) { throw new RuntimeException( 'Canva no devolvió un único PDF de descarga válido.' ); }
        $job['result'] = array( 'status' => 'ready', 'url' => $urls[0] );
        return $job['result'];
    }
}
