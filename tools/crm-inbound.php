<?php
/** Private CLI entry point, outside the public web root. */
$site = getenv( 'GE_CRM_SITE' );
$allowed = array( '/home/graphexpress/public_html', '/home/graphexpress/job-flow-qa-20261007/site' );
if ( ! in_array( $site, $allowed, true ) || ! getenv( 'GE_CRM_INPUT' ) ) { exit( 2 ); }
define( 'DISABLE_WP_CRON', true ); define( 'FS_METHOD', 'direct' );
$_SERVER['HTTP_HOST'] = 'graphex.ar'; $_SERVER['HTTPS'] = 'on'; $_SERVER['REQUEST_URI'] = '/';
require $site . '/wp-load.php'; wp_set_current_user( 1 );
try {
    if ( ! GE_CRM::can( true ) || GE_CRM::org() !== 'graph-express' ) { throw new RuntimeException( 'Consumer principal unavailable' ); }
    $packet = json_decode( file_get_contents( getenv( 'GE_CRM_INPUT' ) ), true );
    if ( ! is_array( $packet ) ) { throw new RuntimeException( 'Invalid envelope' ); }
    if ( ( $packet['operation'] ?? '' ) === 'health' ) {
        $channels = get_option( 'ge_crm_attention_channels', array() );
        $channels['email']['transport_verified_at'] = gmdate( 'c' );
        $channels['email']['consumer_health'] = array(
            'checked_at' => gmdate( 'c' ),
            'receiver_running' => ! empty( $packet['receiver_running'] ),
            'pending_events' => min( 1000000, absint( $packet['pending_events'] ?? 0 ) ),
            'oldest_pending_age_seconds' => min( YEAR_IN_SECONDS, absint( $packet['oldest_pending_age_seconds'] ?? 0 ) ),
            'poll_failed' => ! empty( $packet['poll_failed'] ),
        );
        update_option( 'ge_crm_attention_channels', $channels, false );
        GE_CRM::attention_tick();
        echo wp_json_encode( array( 'committed' => true, 'health_recorded' => true ) );
        exit;
    }
    $receipt = GE_CRM::attention_ingest( $packet );
    try {
        $record = GE_CRM::attention_process( $receipt['record_id'] );
        $ack = GE_CRM::attention_ack_send( $receipt['record_id'] );
        $receipt['classification'] = $record['attention_classification']['category'] ?? 'pending';
        $receipt['attention_state'] = $record['attention_state'];
        $receipt['ack_state'] = $ack['state'];
        $receipt['customer_linked'] = (bool) $record['customer_id'];
    } catch ( Throwable $ex ) {
        // Receipt is already committed; the durable CRM queue owns the retry.
        $receipt['attention_state'] = 'queued_for_review';
    }
    echo wp_json_encode( $receipt );
} catch ( Throwable $ex ) {
    echo wp_json_encode( array( 'committed' => false, 'error' => 'crm_consumer_review_required', 'code' => (int) $ex->getCode() ) );
    exit( 1 );
}
