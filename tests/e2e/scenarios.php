<?php
/**
 * E2E scenarios. Run through tests/e2e/run.sh (wp eval-file inside the cli container).
 *
 * Checkout runs the gateway's real payment_fields() / Blocks data / process_payment(), the
 * cron fallback runs TDP_Cron_Fallback::run(), and Trondealer's webhooks are POSTed over HTTP
 * to /wp-json/tdp/v1/webhook, signed as Trondealer signs them. The Trondealer API itself is
 * the stub in mu-plugins/trondealer-e2e-stub.php.
 */

// wp eval-file runs this file inside a function: shared state goes through $GLOBALS
$GLOBALS['tdp_e2e_failures'] = array();

const TDP_E2E_SECRET = 'e2e-webhook-secret';

// What v0.1.0 saved as "Enabled Networks" when a merchant kept the admin form's defaults
const TDP_E2E_SAVED_COMBOS = array(
	'tron:USDT',
	'bsc:USDT', 'bsc:USDC',
	'eth:USDT', 'eth:USDC',
	'pol:USDT', 'pol:USDC',
	'arb:USDT', 'arb:USDC',
	'base:USDT', 'base:USDC',
	'opt:USDT', 'opt:USDC',
	'avax:USDT', 'avax:USDC',
	'sol:USDT', 'sol:USDC',
);

function tdp_e2e_expect( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		WP_CLI::log( "   ok    {$label}: {$actual}" );
		return;
	}
	$GLOBALS['tdp_e2e_failures'][] = $label;
	WP_CLI::log( "   FAIL  {$label}: got {$actual}, expected {$expected}" );
}

function tdp_e2e_gateway() {
	return new TDP_Gateway();
}

function tdp_e2e_order( $total ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Widget' );
	$product->set_regular_price( $total );
	$product->save();

	$order = wc_create_order();
	$order->add_product( $product, 1 );
	$order->set_payment_method( TDP_Gateway::ID );
	$order->calculate_totals();
	$order->save();
	return $order->get_id();
}

// The customer submits checkout with a network/asset card selected
function tdp_e2e_checkout( $order_id, $choice ) {
	$GLOBALS['tdp_e2e_requests'] = array();
	wc_clear_notices();
	$_POST['tdp_network_choice'] = $choice;
	$result = tdp_e2e_gateway()->process_payment( $order_id );
	unset( $_POST['tdp_network_choice'] );
	return isset( $result['result'] ) ? $result['result'] : 'none';
}

function tdp_e2e_requests() {
	return implode(
		', ',
		array_map(
			function ( $r ) {
				return $r['path'] . ( isset( $r['body']['network'] ) ? ' network=' . $r['body']['network'] : '' );
			},
			$GLOBALS['tdp_e2e_requests']
		)
	);
}

function tdp_e2e_notices() {
	return implode( ' ', array_map( 'wp_strip_all_tags', wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) ) );
}

// The webhook changed the order in another process: drop this process' cached copy
function tdp_e2e_status( $order_id ) {
	wp_cache_flush();
	$order = wc_get_order( $order_id );
	return $order->get_status() . ( $order->is_paid() ? ' (paid)' : '' );
}

// Output of a gateway method, plus any PHP warning it raised
function tdp_e2e_capture( callable $fn ) {
	$warnings = array();
	set_error_handler(
		function ( $errno, $errstr ) use ( &$warnings ) {
			$warnings[] = $errstr;
			return true;
		}
	);
	ob_start();
	$fn();
	$out = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( ob_get_clean() ) ) );
	restore_error_handler();
	return array( $out, implode( '; ', $warnings ) );
}

function tdp_e2e_offered_classic() {
	ob_start();
	tdp_e2e_gateway()->payment_fields();
	preg_match_all( '/name="tdp_network_choice" value="([^"]+)"/', ob_get_clean(), $m );
	return $m[1];
}

function tdp_e2e_offered_blocks() {
	require_once TDP_PLUGIN_DIR . 'includes/class-tdp-blocks-method.php';
	$method = new TDP_Blocks_Method();
	$method->initialize();
	$out = array();
	foreach ( $method->get_payment_method_data()['networks'] as $net ) {
		foreach ( $net['assets'] as $asset ) {
			$out[] = $asset['id'];
		}
	}
	return $out;
}

function tdp_e2e_on_optimism( array $combos ) {
	$opt = array_values( preg_grep( '/^opt:/', $combos ) );
	return $opt ? implode( ',', $opt ) : 'none';
}

function tdp_e2e_webhook( $order_id, $event, array $data ) {
	$order = wc_get_order( $order_id );
	$raw   = wp_json_encode(
		array(
			'event'     => $event,
			'timestamp' => gmdate( 'c' ),
			'data'      => $data + array(
				'to_address'   => $order->get_meta( TDP_Orders::META_ADDRESS ),
				'wallet_label' => TDP_Orders::build_label( $order_id ),
			),
		)
	);
	$res = wp_remote_post(
		'http://wp/?rest_route=/tdp/v1/webhook',
		array(
			'timeout' => 20,
			'body'    => $raw,
			'headers' => array(
				'Content-Type'    => 'application/json',
				'X-Signature-256' => 'sha256=' . hash_hmac( 'sha256', $raw, TDP_E2E_SECRET ),
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		WP_CLI::error( 'webhook request failed: ' . $res->get_error_message() );
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	return wp_remote_retrieve_response_code( $res ) . ( isset( $body['note'] ) ? ' ' . $body['note'] : '' );
}

function tdp_e2e_pay_in_full( $order_id, array $chain ) {
	$order = wc_get_order( $order_id );
	$data  = $chain + array(
		'from_address'  => 'payer',
		'asset'         => 'USDT',
		'amount'        => $order->get_total(),
		'amount_native' => $order->get_total(),
		'price_usd'     => 1.0,
	);
	tdp_e2e_expect( 'webhook transaction.incoming', tdp_e2e_webhook( $order_id, 'transaction.incoming', $data + array( 'confirmations' => 1 ) ), '200' );
	tdp_e2e_expect( 'order', tdp_e2e_status( $order_id ), 'on-hold' );
	tdp_e2e_expect( 'webhook transaction.confirmed', tdp_e2e_webhook( $order_id, 'transaction.confirmed', $data + array( 'confirmations' => 20 ) ), '200' );
	tdp_e2e_expect( 'order', tdp_e2e_status( $order_id ), 'processing (paid)' );
}

WC()->frontend_includes();
WC()->session  = new WC_Session_Handler();
WC()->session->init();
WC()->customer = new WC_Customer( 0, true );
WC()->cart     = new WC_Cart();

update_option(
	'woocommerce_' . TDP_Gateway::ID . '_settings',
	array(
		'enabled'          => 'yes',
		'title'            => 'Pay with Crypto (USDT / USDC)',
		'description'      => '',
		'api_key'          => 'td_e2e',
		'api_base'         => TDP_API_BASE,
		'enabled_networks' => TDP_E2E_SAVED_COMBOS,
		'tolerance'        => '1',
	)
);
update_option( 'tdp_api_key', 'td_e2e' );
update_option( 'tdp_api_base', TDP_API_BASE );
update_option( 'tdp_tolerance_pct', '1' );
update_option( 'tdp_webhook_secret', TDP_E2E_SECRET );

WP_CLI::log( '1. Store that kept the default networks: what checkout offers' );
tdp_e2e_expect( 'classic checkout, Optimism cards', tdp_e2e_on_optimism( tdp_e2e_offered_classic() ), 'none' );
tdp_e2e_expect( 'Blocks checkout, Optimism cards', tdp_e2e_on_optimism( tdp_e2e_offered_blocks() ), 'none' );
tdp_e2e_expect( 'classic checkout, cards offered', (string) count( tdp_e2e_offered_classic() ), '15' );
tdp_e2e_expect( 'fresh install default, Optimism cards', tdp_e2e_on_optimism( wp_list_pluck( TDP_Networks::combinations(), 'id' ) ), 'none' );

WP_CLI::log( '2. Customer picks USDT on Optimism and submits checkout' );
$order = tdp_e2e_order( '40.00' );
tdp_e2e_expect( 'process_payment', tdp_e2e_checkout( $order, 'opt:USDT' ), 'failure' );
tdp_e2e_expect( 'error shown', tdp_e2e_notices(), 'Selected asset is not available on that network.' );
tdp_e2e_expect( 'Trondealer calls', tdp_e2e_requests(), '' );
tdp_e2e_expect( 'deposit address on the order', (string) wc_get_order( $order )->get_meta( TDP_Orders::META_ADDRESS ), '' );
list( $thankyou ) = tdp_e2e_capture( function () use ( $order ) { tdp_e2e_gateway()->render_thankyou( $order ); } );
tdp_e2e_expect( 'thank-you page payment instructions', $thankyou ? substr( $thankyou, 0, 90 ) : 'none', 'none' );
// The only other way an order gets paid: the cron fallback asks Trondealer for the address' deposits
$wc_order = wc_get_order( $order );
$wc_order->set_date_created( time() - 10 * MINUTE_IN_SECONDS );
$wc_order->save();
$GLOBALS['tdp_e2e_requests'] = array();
TDP_Cron_Fallback::run();
tdp_e2e_expect( 'cron fallback Trondealer calls', tdp_e2e_requests(), '' );

WP_CLI::log( '3. Order a customer placed on Optimism before the update, still pending' );
$order = tdp_e2e_order( '40.00' );
TDP_Orders::set_assignment( wc_get_order( $order ), 'opt', 'USDT', '0x' . str_repeat( 'ab', 20 ), '40.00', 'w-opt' );
list( $thankyou, $warnings ) = tdp_e2e_capture( function () use ( $order ) { tdp_e2e_gateway()->render_thankyou( $order ); } );
tdp_e2e_expect( 'thank-you page payment instructions', $thankyou ? substr( $thankyou, 0, 90 ) : 'none', 'none' );
list( $email, $warnings ) = tdp_e2e_capture( function () use ( $order ) { tdp_e2e_gateway()->email_instructions( wc_get_order( $order ), false, true ); } );
tdp_e2e_expect( 'order email payment instructions', $email ? $email : 'none', 'none' );
tdp_e2e_expect( 'PHP warnings', $warnings ? $warnings : 'none', 'none' );

WP_CLI::log( '4. USDT on BNB Smart Chain, paid in full (unchanged)' );
$order = tdp_e2e_order( '25.00' );
tdp_e2e_expect( 'process_payment', tdp_e2e_checkout( $order, 'bsc:USDT' ), 'success' );
tdp_e2e_expect( 'Trondealer calls', tdp_e2e_requests(), '/wallets/assign network=bsc' );
list( $thankyou ) = tdp_e2e_capture( function () use ( $order ) { tdp_e2e_gateway()->render_thankyou( $order ); } );
tdp_e2e_expect( 'thank-you page asks for', preg_match( '/Send exactly ([\d.]+ USDT on the [\w ]+ network)/', $thankyou, $m ) ? $m[1] : 'nothing', '25.00 USDT on the BNB Smart Chain network' );
tdp_e2e_pay_in_full( $order, array( 'tx_hash' => '0x' . str_repeat( 'c', 64 ), 'block_number' => 1, 'network' => 'bsc' ) );

WP_CLI::log( '5. USDT on TRON, paid in full (unchanged)' );
$order = tdp_e2e_order( '25.00' );
tdp_e2e_expect( 'process_payment', tdp_e2e_checkout( $order, 'tron:USDT' ), 'success' );
tdp_e2e_expect( 'Trondealer calls', tdp_e2e_requests(), '/tron/wallets/assign' );
tdp_e2e_pay_in_full( $order, array( 'tx_hash' => str_repeat( 'd', 64 ), 'block_number' => 1, 'network' => 'tron' ) );

$failures = $GLOBALS['tdp_e2e_failures'];
if ( $failures ) {
	WP_CLI::error( count( $failures ) . ' expectation(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'All checkout scenarios passed.' );
