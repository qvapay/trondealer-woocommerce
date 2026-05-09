<?php
/**
 * HTTP wrapper around the Trondealer V2 API.
 *
 * All methods return either decoded JSON arrays on success or WP_Error on
 * failure. The caller is responsible for surfacing errors to the UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_API_Client {

	private $api_key;
	private $base_url;
	private $timeout;

	public function __construct( $api_key = null, $base_url = null ) {
		$this->api_key  = $api_key ? $api_key : get_option( 'tdp_api_key', '' );
		$this->base_url = $base_url ? rtrim( $base_url, '/' ) : rtrim( get_option( 'tdp_api_base', TDP_API_BASE ), '/' );
		$this->timeout  = (int) apply_filters( 'tdp_api_timeout', 15 );
	}

	public function get_client_info() {
		return $this->request( 'GET', '/clients/me' );
	}

	public function update_client( $fields ) {
		return $this->request( 'PATCH', '/clients/me', $fields );
	}

	public function webhook_test( $payload ) {
		return $this->request( 'POST', '/clients/me/webhook-test', array( 'payload' => $payload ), 30 );
	}

	public function assign_wallet( $network_key, $label ) {
		$family = TDP_Networks::family( $network_key );
		if ( ! $family ) {
			return new WP_Error( 'tdp_unknown_network', sprintf( 'Unknown network: %s', $network_key ) );
		}

		$body = array( 'label' => $label );

		switch ( $family ) {
			case TDP_Networks::FAMILY_TRON:
				$path = '/tron/wallets/assign';
				break;
			case TDP_Networks::FAMILY_SOL:
				$path = '/sol/wallets/assign';
				break;
			case TDP_Networks::FAMILY_EVM:
				$path = '/wallets/assign';
				$net  = TDP_Networks::get( $network_key );
				$body['network'] = $net['network_param'];
				break;
			default:
				return new WP_Error( 'tdp_unknown_family', sprintf( 'Unknown family: %s', $family ) );
		}

		return $this->request( 'POST', $path, $body );
	}

	public function get_transactions( $network_key, $address, $limit = 50, $offset = 0, $status = null ) {
		$family = TDP_Networks::family( $network_key );
		if ( ! $family ) {
			return new WP_Error( 'tdp_unknown_network', sprintf( 'Unknown network: %s', $network_key ) );
		}

		$body = array(
			'address' => $address,
			'limit'   => (int) $limit,
			'offset'  => (int) $offset,
		);
		if ( $status ) {
			$body['status'] = $status;
		}

		switch ( $family ) {
			case TDP_Networks::FAMILY_TRON:
				$path = '/tron/wallets/transactions';
				break;
			case TDP_Networks::FAMILY_SOL:
				$path = '/sol/wallets/transactions';
				break;
			case TDP_Networks::FAMILY_EVM:
				$path = '/wallets/transactions';
				$net  = TDP_Networks::get( $network_key );
				$body['network'] = $net['network_param'];
				break;
			default:
				return new WP_Error( 'tdp_unknown_family', sprintf( 'Unknown family: %s', $family ) );
		}

		return $this->request( 'POST', $path, $body );
	}

	public function get_balance( $network_key, $address ) {
		$family = TDP_Networks::family( $network_key );
		if ( ! $family ) {
			return new WP_Error( 'tdp_unknown_network', sprintf( 'Unknown network: %s', $network_key ) );
		}

		$path = '/wallets/balance';
		if ( TDP_Networks::FAMILY_TRON === $family ) {
			$path = '/tron/wallets/balance';
		} elseif ( TDP_Networks::FAMILY_SOL === $family ) {
			$path = '/sol/wallets/balance';
		}

		return $this->request( 'POST', $path, array( 'address' => $address ) );
	}

	private function request( $method, $path, $body = null, $timeout = null ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'tdp_no_api_key', __( 'API key not configured.', 'trondealer-payments' ) );
		}

		$url  = $this->base_url . $path;
		$args = array(
			'method'  => $method,
			'timeout' => null !== $timeout ? $timeout : $this->timeout,
			'headers' => array(
				'x-api-key'    => $this->api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => 'Trondealer-Payments/' . TDP_VERSION . '; ' . home_url( '/' ),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? $data['error'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'tdp_api_error', $message, array( 'status' => $code, 'body' => $data ) );
		}

		if ( null === $data && '' !== $raw ) {
			return new WP_Error( 'tdp_invalid_json', __( 'Invalid JSON response from API.', 'trondealer-payments' ) );
		}

		return $data;
	}
}
