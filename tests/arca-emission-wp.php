<?php
// Real WordPress/DB in named isolated QA; transport fixtures never contact ARCA.
$load = getenv( 'GE_WP_LOAD' );
if ( ! $load || strpos( $load, '/job-flow-qa-20261007/' ) === false ) { throw new Exception( 'Isolated QA required' ); }
define( 'FS_METHOD', 'direct' );
require $load;
if ( DB_NAME !== 'graph_job_flow_20261007' ) { throw new Exception( 'Wrong database' ); }
wp_set_current_user( 1 );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
$checks = 0;
function ae( $ok, $message ) { global $checks; if ( ! $ok ) { throw new Exception( $message ); } $checks++; }
ae( ! is_wp_error( GE_WTP_ARCA_Emission::install() ), 'Schema installation' );
$sample = array( 'order_id' => 0, 'environment' => 'homologation', 'issuer' => array( 'id' => 'test', 'cuit' => '23336924529', 'legal_name' => 'Emisor de prueba', 'vat_status' => 'monotributo', 'fiscal_address' => 'Domicilio QA', 'iibb' => 'Exento' ),
    'receiver' => array( 'cuit' => '23336924529', 'legal_name' => 'Receptor de prueba', 'vat_status' => 'monotributo', 'fiscal_address' => 'Domicilio QA' ), 'class' => 'C', 'header' => array( 'CantReg' => 1, 'PtoVta' => 999, 'CbteTipo' => 11 ),
    'detail' => array( 'Concepto' => 2, 'DocTipo' => 80, 'DocNro' => '23336924529', 'CbteFch' => '20261009', 'ImpTotal' => '100.00', 'ImpTotConc' => '0.00', 'ImpNeto' => '100.00', 'ImpOpEx' => '0.00', 'ImpTrib' => '0.00', 'ImpIVA' => '0.00', 'MonId' => 'PES', 'MonCotiz' => 1, 'CondicionIVAReceptorId' => 6, 'FchServDesde' => '20261001', 'FchServHasta' => '20261009', 'FchVtoPago' => '20261009' ),
    'items' => array( array( 'name' => 'Servicio gráfico de prueba con descripción extensa y caracteres españoles: impresión, diseño y terminación', 'quantity' => 2, 'net' => '100.00', 'tax' => '0.00' ) ), 'fees' => array(), 'shipping_net' => '0', 'activity_start' => '2010-01-01', 'sale_terms' => 'Contado' );
class AE_Client {
    public $calls = array(); public $mode = 'accept'; public $saved; public $last = 0; public $blocked = false;
    public function environment() { return 'homologation'; }
    public function call( $m, $params = array() ) {
        $this->calls[] = $m;
        if ( 'FEParamGetPtosVenta' === $m ) { return array( 'ResultGet' => array( 'PtoVenta' => array( 'Nro' => 999, 'Bloqueado' => $this->blocked ? 'S' : 'N', 'FchBaja' => 'NULL', 'EmisionTipo' => 'CAE - Monotributo' ) ) ); }
        if ( 'FEParamGetTiposCbte' === $m ) { return array( 'ResultGet' => array( 'CbteTipo' => array( 'Id' => 11 ) ) ); }
        if ( 'FEParamGetCondicionIvaReceptor' === $m ) { return array( 'ResultGet' => array( 'CondicionIvaReceptor' => array( 'Id' => 6 ) ) ); }
        if ( 'FECompUltimoAutorizado' === $m ) { return array( 'CbteNro' => $this->last, 'PtoVta' => 999, 'CbteTipo' => 11 ); }
        if ( 'FECAESolicitar' === $m ) {
            $this->saved = $params['FeCAEReq']['FeDetReq']['FECAEDetRequest'][0];
            if ( 'timeout' === $this->mode ) { return new WP_Error( 'ge_arca_unavailable', 'Fixture timeout' ); }
            if ( 'rejected' === $this->mode ) { return array( 'FeDetResp' => array( 'FECAEDetResponse' => array( 'Resultado' => 'R', 'Observaciones' => array( 'Obs' => array( 'Code' => 10016, 'Msg' => 'Fixture rejection' ) ) ) ) ); }
            if ( 'malformed' === $this->mode ) { return array( 'FeDetResp' => array( 'FECAEDetResponse' => array( 'Resultado' => 'A', 'CAE' => 'invalid' ) ) ); }
            return array( 'FeCabResp' => array( 'PtoVta' => 999, 'CbteTipo' => 11, 'Cuit' => '23336924529' ), 'FeDetResp' => array( 'FECAEDetResponse' => array( 'Resultado' => 'A', 'CbteDesde' => $this->saved['CbteDesde'], 'CbteHasta' => $this->saved['CbteHasta'], 'CbteFch' => $this->saved['CbteFch'], 'CAE' => '12345678901234', 'CAEFchVto' => '20261019' ) ) );
        }
        if ( 'FECompConsultar' === $m ) {
            if ( 'notfound' === $this->mode ) { return array( 'Errors' => array( 'Err' => array( 'Code' => 602, 'Msg' => 'Fixture not found' ) ) ); }
            $d = $this->saved; $d += array( 'Resultado' => 'A', 'EmisionTipo' => 'CAE', 'CodAutorizacion' => '12345678901234', 'FchVto' => '20261019', 'PtoVta' => 999, 'CbteTipo' => 11 );
            if ( 'conflict' === $this->mode ) { $d['DocNro'] = '20948548934'; }
            return array( 'ResultGet' => $d );
        }
        throw new Exception( 'Unexpected transport method' );
    }
}
function ao( $sample ) {
    $order = wc_create_order(); $order->set_currency( 'ARS' ); $order->set_total( '100.00' ); $order->save();
    $sample['order_id'] = $order->get_id(); return $sample;
}
$client = new AE_Client(); $client->last = random_int( 100000, 200000 );
$p = ao( $sample );
$result = GE_WTP_ARCA_Emission::execute( $p, $client, 1 );
ae( ! is_wp_error( $result ), 'Successful authorization' );
$row = GE_WTP_ARCA_Emission::journal( $p['order_id'], 'homologation' );
ae( 'authorized' === $row['state'] && $row['number'] == $client->last + 1, 'Journal numbering and authorized state' );
ae( '12345678901234' === $row['authorization']['cae'], 'CAE persisted' );
ae( $row['payload']['approved_by'] === 1 && ! empty( $row['payload']['approved_at'] ), 'Approval audit persisted' );
$before = count( $client->calls ); $again = GE_WTP_ARCA_Emission::execute( $p, $client, 1 );
ae( ! is_wp_error( $again ) && count( $client->calls ) === $before, 'Double click uses existing CAE without network emission' );
ae( empty( GE_WTP_Documents::issued_documents( $p['order_id'] ) ), 'Homologation never publishes into real invoice portal' );
ae( is_string( $result['pdf_bytes'] ) && substr( $result['pdf_bytes'], 0, 5 ) === '%PDF-', 'Authorized PDF generated' );
file_put_contents( '/home/graphexpress/job-flow-qa-20261007/arca-test-invoice.pdf', $result['pdf_bytes'] );
$url = GE_WTP_ARCA_Invoice_PDF::qr_url( $row ); $qr = json_decode( base64_decode( substr( $url, strpos( $url, '?p=' ) + 3 ) ), true );
ae( $qr['ver'] === 1 && $qr['tipoCodAut'] === 'E' && $qr['codAut'] === 12345678901234 && $qr['cuit'] === 23336924529 && $qr['tipoCmp'] === 11 && $qr['importe'] === 100, 'Official QR schema and numeric identifiers' );
$remote = $row['payload']['detail'] + array( 'Resultado' => 'A', 'EmisionTipo' => 'CAE', 'CodAutorizacion' => '12345678901234', 'FchVto' => '20261019', 'PtoVta' => 999, 'CbteTipo' => 11 );
ae( GE_WTP_ARCA_Emission::matches( $remote, $row['payload'], $row['number'] ), 'Recovery matches exact request' );
foreach ( array( 'DocNro', 'CbteFch', 'ImpTotal', 'ImpIVA', 'PtoVta', 'CbteTipo', 'FchServDesde', 'MonId', 'MonCotiz', 'CbteHasta' ) as $key ) {
    $bad = $remote; $bad[$key] = is_numeric( $bad[$key] ) ? $bad[$key] + 1 : 'DIFFERENT';
    ae( ! GE_WTP_ARCA_Emission::matches( $bad, $row['payload'], $row['number'] ), 'Mismatch rejected: ' . $key );
}
$scope = $row['scope']; $lock = 'ge_arca_' . substr( $scope, 0, 48 );
$second = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
ae( $second->query( "SELECT GET_LOCK('$lock',0) AS acquired" )->fetch_assoc()['acquired'] == 1, 'Independent DB connection owns lock' );
$busy = GE_WTP_ARCA_Emission::execute( ao( $sample ), $client, 1 );
ae( is_wp_error( $busy ) && 'ge_arca_busy' === $busy->get_error_code(), 'Concurrent issuance blocked by DB lock' );
$second->query( "SELECT RELEASE_LOCK('$lock')" ); $second->close();
$blocked = new AE_Client(); $blocked->blocked = true;
ae( is_wp_error( GE_WTP_ARCA_Emission::execute( ao( $sample ), $blocked, 1 ) ) && ! in_array( 'FECAESolicitar', $blocked->calls, true ), 'Blocked point of sale never submitted' );
$client = new AE_Client(); $client->last = $row['number']; $client->mode = 'timeout'; $uncertain = ao( $sample );
ae( is_wp_error( GE_WTP_ARCA_Emission::execute( $uncertain, $client, 1 ) ), 'Timeout retained as uncertain' );
$r = GE_WTP_ARCA_Emission::journal( $uncertain['order_id'], 'homologation' ); ae( 'uncertain' === $r['state'], 'Uncertain journal durable' );
$other = new AE_Client(); $pending = GE_WTP_ARCA_Emission::execute( ao( $sample ), $other, 1 );
ae( is_wp_error( $pending ) && 'ge_arca_pending' === $pending->get_error_code() && empty( $other->calls ), 'Unresolved number blocks next order' );
$client->mode = 'notfound'; $attempts = count( array_filter( $client->calls, function( $m ) { return $m === 'FECAESolicitar'; } ) );
ae( is_wp_error( GE_WTP_ARCA_Emission::execute( $uncertain, $client, 1 ) ), 'Unconfirmed absence stays uncertain' );
ae( $attempts === count( array_filter( $client->calls, function( $m ) { return $m === 'FECAESolicitar'; } ) ), 'No blind resend after not found' );
$client->mode = 'accept'; $recovered = GE_WTP_ARCA_Emission::execute( $uncertain, $client, 1 );
ae( ! is_wp_error( $recovered ), 'Timeout after ARCA authorization recovered' );
$r = GE_WTP_ARCA_Emission::journal( $uncertain['order_id'], 'homologation' ); ae( 'authorized' === $r['state'] && $r['authorization']['recovered'], 'Recovered CAE audited' );
ae( $attempts === count( array_filter( $client->calls, function( $m ) { return $m === 'FECAESolicitar'; } ) ), 'Recovery creates no duplicate' );
$rejection = new AE_Client(); $rejection->last = $r['number']; $rejection->mode = 'rejected'; $reject_p = ao( $sample );
ae( is_wp_error( GE_WTP_ARCA_Emission::execute( $reject_p, $rejection, 1 ) ), 'Business rejection returned' );
ae( 'rejected' === GE_WTP_ARCA_Emission::journal( $reject_p['order_id'], 'homologation' )['state'], 'Rejection and ARCA observations retained' );
$old_rejection = GE_WTP_ARCA_Emission::journal( $reject_p['order_id'], 'homologation' );
$before_recovery = count( $rejection->calls );
ae( is_wp_error( GE_WTP_ARCA_Emission::execute( $reject_p, $rejection, 1, true ) ) && count( $rejection->calls ) === $before_recovery, 'Recovering rejection never submits a new request' );
$rejection->mode = 'accept';
ae( ! is_wp_error( GE_WTP_ARCA_Emission::execute( $reject_p, $rejection, 1 ) ), 'Explicit reviewed retry after rejection can reuse unconsumed number' );
$new_attempt = GE_WTP_ARCA_Emission::journal( $reject_p['order_id'], 'homologation' );
ae( $new_attempt['id'] !== $old_rejection['id'] && $new_attempt['number'] === $old_rejection['number'] && 'rejected' === $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . GE_WTP_ARCA_Emission::table() . ' WHERE id=%d', $old_rejection['id'] ) ), 'Rejected immutable attempt retained separately' );
$no_record = new AE_Client();
ae( is_wp_error( GE_WTP_ARCA_Emission::execute( ao( $sample ), $no_record, 1, true ) ) && empty( $no_record->calls ), 'Recovery without record cannot issue' );
ae( ! is_wp_error( GE_WTP_ARCA_Emission::recover( wc_get_order( $p['order_id'] ), 'homologation', 1 ) ), 'Authorized PDF recoverable without current profile, date or credentials' );
$fixture = $wpdb->insert( GE_WTP_ARCA_Emission::table(), array( 'order_id' => $p['order_id'], 'environment' => 'production', 'scope' => 'qa-fixture-' . $p['order_id'], 'number' => 1, 'state' => 'uncertain', 'payload' => '{}', 'authorization' => '{}', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
ae( $fixture && GE_WTP_ARCA_Emission::locks_order( $p['order_id'] ), 'Pending production request protects issuer and manual invoice replacement' );
ae( 'ge_issuer_order_locked' === GE_WTP_Billing_Issuers::change_order( wc_get_order( $p['order_id'] ), array(), 1 )->get_error_code(), 'Issuer change blocked before fiscal recovery' );
ae( 'ge_issued_arca_locked' === GE_WTP_Documents::attach_issued( $p['order_id'], 'factura', '', '' )->get_error_code(), 'Manual upload cannot hide pending or authorized ARCA invoice' );
$long = $row; $long['payload']['items'] = array_fill( 0, 65, $sample['items'][0] );
$long_pdf = GE_WTP_ARCA_Invoice_PDF::build( $long );
ae( is_string( $long_pdf ) && substr( $long_pdf, 0, 5 ) === '%PDF-', 'Long multi-page authorized PDF generated' );
file_put_contents( '/home/graphexpress/job-flow-qa-20261007/arca-multipage.pdf', $long_pdf );
$bad_actor = wp_insert_user( array( 'user_login' => 'arca-qa-' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'arca-' . wp_generate_password( 8, false ) . '@example.invalid', 'role' => 'customer' ) );
ae( ! GE_WTP_ARCA_Emission::can_emit( wc_get_order( $p['order_id'] ), $bad_actor ), 'Customer cannot issue invoices' );
ae( is_wp_error( GE_WTP_ARCA_Emission::prepare( wc_get_order( $p['order_id'] ), array(), 1 ) ), 'Unverified issuer cannot pass review' );
ae( GE_WTP_ARCA_Emission::cents( '81000.01' ) === 8100001, 'Amounts parsed without float rounding' );
try { GE_WTP_ARCA_Emission::cents( '10.001' ); ae( false, 'Invalid precision rejected' ); } catch ( InvalidArgumentException $e ) { ae( true, 'Invalid precision rejected' ); }
echo wp_json_encode( array( 'checks' => $checks, 'status' => 'PASS', 'transport' => 'deterministic fixtures; no ARCA network calls', 'database' => DB_NAME, 'pdf' => '/home/graphexpress/job-flow-qa-20261007/arca-test-invoice.pdf' ) ) . "\n";
