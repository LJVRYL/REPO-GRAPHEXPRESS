<?php
$load = getenv( 'GE_WP_LOAD' );
if ( ! $load || strpos( $load, '/job-flow-qa-' ) === false ) { throw new Exception( 'Isolated QA required' ); }
define( 'FS_METHOD', 'direct' ); require $load;
if ( DB_NAME !== 'graph_job_flow_20261007' ) { throw new Exception( 'Wrong DB' ); }
wp_set_current_user( 1 );
$checks = 0; $mails = 0;
function pc( $ok, $message ) { global $checks; if ( ! $ok ) { throw new Exception( $message ); } $checks++; }
add_filter( 'pre_wp_mail', function () use ( &$mails ) { $mails++; return true; }, PHP_INT_MAX );
$orgs = GE_Organization::all(); $orgs[GE_Organization::PRIMARY]['members']['1'] = 'owner'; update_option( GE_Organization::ROOT, $orgs, false );
$id = wp_insert_user( array( 'user_login' => 'policy-' . wp_generate_password( 10, false ), 'user_email' => 'policy-' . wp_generate_password( 10, false ) . '@example.invalid', 'user_pass' => wp_generate_password( 32 ), 'role' => 'customer' ) );
pc( GE_WTP_Payment_Policy::customer( $id )['percent'] === 50, 'Default deposit 50' );
$credit = array( 'kind' => 'credit', 'days' => 30, 'reason' => 'Approved QA corporate terms', 'approve_credit' => 1 );
pc( is_wp_error( GE_WTP_Payment_Policy::save_customer( $id, $credit, $id ) ), 'Customers cannot approve their own credit' );
$unapproved = $credit; unset( $unapproved['approve_credit'] );
pc( is_wp_error( GE_WTP_Payment_Policy::save_customer( $id, $unapproved, 1 ) ), 'Explicit approval required' );
$bad = $credit; $bad['reason'] = '';
pc( is_wp_error( GE_WTP_Payment_Policy::save_customer( $id, $bad, 1 ) ), 'Reason required' );
foreach ( array( 0, 121, '30.5' ) as $days ) { $bad = $credit; $bad['days'] = $days; pc( is_wp_error( GE_WTP_Payment_Policy::normalize( $bad, 1 ) ), 'Invalid credit days blocked' ); }
pc( ! is_wp_error( GE_WTP_Payment_Policy::save_customer( $id, $credit, 1 ) ), 'Approved customer credit saved' );
GE_WTP_Billing::save_profile( $id, array( 'legal_name' => 'QA POLICY', 'vat_status' => 'registered', 'billing_mode' => 'common', 'cuit' => '23336924529', 'fiscal_address' => 'QA 123' ), 1 );
$quote = GE_WTP_Commercial_Quotes::create_draft( $id, array(), array( 'billing_profile_id' => 'default', 'issuer_profile_id' => '' ), 1 );
if ( is_wp_error( $quote ) ) { throw new Exception( $quote->get_error_message() ); }
pc( ! is_wp_error( $quote ) && 'credit' === $quote['snapshot']['payment_policy']['kind'] && ! $quote['snapshot']['deposit_enabled'], 'New quote inherits approved credit and no required deposit' );
$updated = GE_WTP_Commercial_Quotes::revise( $quote['id'], array(), array( 'expected_version' => $quote['version'], 'billing_profile_id' => 'default', 'issuer_profile_id' => '', 'deposit_percent' => 50, 'deposit_enabled' => true ), 1 );
pc( ! is_wp_error( $updated ) && 'credit' === $updated['snapshot']['payment_policy']['kind'] && ! $updated['snapshot']['deposit_enabled'], 'Quote edit preserves frozen approved terms' );
$order = wc_create_order( array( 'customer_id' => $id, 'status' => 'pending' ) ); $order->set_total( 100 );
GE_WTP_Payment_Policy::freeze( $order ); $order->save();
pc( GE_WTP_Payment_Policy::order( $order )['kind'] === 'credit', 'New order inherits approved customer terms' );
pc( true === GE_WTP_Job_Flow::payment_check( $order ) && true === GE_WTP_Job_Flow::payment_check( $order, true ), 'Approved credit permits production and delivery finance gate' );
pc( ! $order->get_meta( '_ge_amount_paid_cents', true ) && ! $order->get_date_paid(), 'Credit never invents payment or paid date' );
pc( ! is_wp_error( GE_WTP_Payment_Policy::save_customer( $id, array( 'kind' => 'deposit' ), 1 ) ), 'Customer default restored' );
pc( GE_WTP_Payment_Policy::order( $order )['kind'] === 'credit', 'Customer edits do not change existing order' );
pc( ! is_wp_error( GE_WTP_Payment_Policy::save_order( $order, array( 'kind' => 'custom', 'percent' => 25, 'reason' => 'Agreed specific deposit' ), 1 ) ), 'Per-order exception recorded' );
pc( is_wp_error( GE_WTP_Job_Flow::payment_check( $order ) ), 'Unpaid custom deposit blocks release' );
pc( count( $order->get_meta( '_ge_payment_policy_history', true ) ) === 1, 'Order exception audited' );
$order->update_meta_data( '_ge_commercial_quote_id', 987654 ); $order->update_meta_data( '_ge_final_total_cents', 10000 );
$order->update_meta_data( '_ge_commercial_credited_attempts', array( array( 'key' => 'wc:payment:qa1', 'amount_cents' => 2500 ) ) ); $order->update_meta_data( '_ge_amount_paid_cents', 2500 ); $order->save();
pc( true === GE_WTP_Job_Flow::payment_check( $order ), 'Credited agreed deposit permits finance gate' );
pc( is_wp_error( GE_WTP_Job_Flow::payment_check( $order, true ) ), 'Deposit still blocks final delivery' );
$order->update_meta_data( '_ge_amount_paid_cents', 10000 );
pc( is_wp_error( GE_WTP_Job_Flow::payment_check( $order ) ), 'Claimed payment without ledger blocked' );
$order->update_meta_data( '_ge_amount_paid_cents', 2500 ); $order->set_total( 101 );
pc( is_wp_error( GE_WTP_Job_Flow::payment_check( $order ) ), 'Changed total blocks release' ); $order->set_total( 100 );
$order->delete_meta_data( GE_WTP_Payment_Policy::META ); $order->update_meta_data( '_ge_commercial_quote_snapshot', array( 'deposit_percent' => 30, 'deposit_enabled' => true ) );
pc( GE_WTP_Payment_Policy::order( $order )['percent'] === 30, 'Historical accepted quote percentage preserved' );
pc( is_wp_error( GE_WTP_Job_Flow::payment_check( $order ) ), 'Accepted 30 percent requires more than 25' );
$order->update_meta_data( '_ge_commercial_quote_snapshot', array( 'deposit_percent' => 50, 'deposit_enabled' => false ) );
pc( GE_WTP_Payment_Policy::order( $order )['percent'] === 100, 'No-deposit quote requires full prepayment' );
$order->update_meta_data( GE_WTP_Payment_Policy::META, array( 'kind' => 'credit', 'percent' => 0, 'days' => 30 ) );
pc( is_wp_error( GE_WTP_Job_Flow::payment_check( $order ) ), 'Unaudited injected credit blocked' );
$order->delete_meta_data( GE_WTP_Payment_Policy::META ); $order->update_meta_data( '_ge_delivery_confirmed_at', gmdate( 'c' ) );
pc( is_wp_error( GE_WTP_Payment_Policy::save_order( $order, $credit, 1 ) ), 'Delivered order terms locked' );
$order->delete_meta_data( '_ge_delivery_confirmed_at' ); $order->update_meta_data( '_ge_organization_id', 'other' );
pc( is_wp_error( GE_WTP_Payment_Policy::save_order( $order, $credit, 1 ) ), 'Cross-organization order blocked' );
update_user_meta( $id, '_ge_organization_id', 'other' );
pc( is_wp_error( GE_WTP_Payment_Policy::save_customer( $id, $credit, 1 ) ), 'Cross-organization customer blocked' );
pc( $mails === 0, 'No external notifications' );
echo 'PASS ' . $checks . " payment policy checks\n";
