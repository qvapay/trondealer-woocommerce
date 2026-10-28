<?php
/**
 * Stands in for the Trondealer V2 API during tests/e2e. Every request the plugin makes is
 * recorded in $GLOBALS['tdp_e2e_requests'], and answered the way the API documents it
 * (https://www.trondealer.com/llms-full.txt):
 *  - EVM assign takes no `network`: one address is valid on every EVM chain.
 *  - EVM `transactions` reads one chain and rejects a network outside the live catalog
 *    (GET /api/v2/networks) with 400; Optimism left it on 2026-09-15.
 */

const TDP_E2E_EVM_NETWORKS = array( 'bsc', 'eth', 'pol', 'arb', 'base', 'avax', 'rh' );

$GLOBALS['tdp_e2e_requests'] = array();

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$prefix = 'https://trondealer.com/api/v2';
		if ( 0 !== strpos( $url, $prefix ) ) {
			return $pre;
		}
		$path = substr( $url, strlen( $prefix ) );
		$body = isset( $args['body'] ) ? (array) json_decode( $args['body'], true ) : array();

		$GLOBALS['tdp_e2e_requests'][] = array( 'path' => $path, 'body' => $body );

		$label = isset( $body['label'] ) ? $body['label'] : '';
		switch ( $path ) {
			case '/wallets/assign':
				return tdp_e2e_response( 201, array( 'success' => true, 'wallet' => array( 'id' => md5( 'evm' . $label ), 'address' => '0x' . substr( md5( $label ), 0, 40 ) . '00000000', 'label' => $label, 'status' => 'active' ) ) );
			case '/tron/wallets/assign':
				return tdp_e2e_response( 201, array( 'success' => true, 'wallet' => array( 'id' => md5( 'tron' . $label ), 'address' => 'T' . substr( md5( $label ), 0, 33 ), 'label' => $label, 'status' => 'active' ) ) );
			case '/wallets/transactions':
				$network = isset( $body['network'] ) ? $body['network'] : 'eth';
				if ( ! in_array( $network, TDP_E2E_EVM_NETWORKS, true ) ) {
					return tdp_e2e_response( 400, array( 'error' => "Invalid network: {$network}" ) );
				}
				return tdp_e2e_response( 200, array( 'success' => true, 'transactions' => array() ) );
		}
		return tdp_e2e_response( 404, array( 'error' => 'not stubbed' ) );
	},
	10,
	3
);

function tdp_e2e_response( $code, array $body ) {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $body ),
		'response' => array( 'code' => $code, 'message' => '' ),
		'cookies'  => array(),
		'filename' => null,
	);
}
