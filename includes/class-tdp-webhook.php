<?php
/**
 * Webhook receiver and order status poller endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_Webhook {

	const ROUTE_NAMESPACE = 'tdp/v1';

	public static function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/order-status/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'order_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'  => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ),
					'key' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$raw_body  = $request->get_body();
		$signature = $request->get_header( 'X-Signature-256' );
		if ( ! $signature ) {
			$signature = $request->get_header( 'x_signature_256' );
		}

		$secret = get_option( 'tdp_webhook_secret', '' );
		if ( empty( $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'webhook_secret_not_configured' ), 503 );
		}

		if ( ! self::verify_signature( $raw_body, $signature, $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) || empty( $payload['event'] ) || empty( $payload['data'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}

		$event = sanitize_text_field( $payload['event'] );
		$data  = $payload['data'];

		$label    = isset( $data['wallet_label'] ) ? $data['wallet_label'] : '';
		$order_id = TDP_Orders::parse_label( $label );
		if ( ! $order_id ) {
			return new WP_REST_Response( array( 'received' => true, 'note' => 'no_matching_order' ), 200 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== TDP_Gateway::ID ) {
			return new WP_REST_Response( array( 'received' => true, 'note' => 'order_not_managed' ), 200 );
		}

		$assignment = TDP_Orders::get_assignment( $order );
		$network    = $assignment['network'];

		if ( 'transaction.swept' === $event ) {
			self::handle_swept( $order, $data );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$tx_uid = TDP_Orders::build_tx_uid( $network, $data );
		if ( TDP_Orders::was_processed( $order, $tx_uid . ':' . $event ) ) {
			return new WP_REST_Response( array( 'received' => true, 'note' => 'duplicate' ), 200 );
		}

		$paid_asset   = isset( $data['asset'] ) ? strtoupper( $data['asset'] ) : '';
		$paid_network = isset( $data['network'] ) ? $data['network'] : '';

		if ( TDP_Networks::FAMILY_EVM === TDP_Networks::family( $network ) ) {
			$expected_param = TDP_Networks::get( $network )['network_param'];
			if ( $paid_network && $paid_network !== $expected_param ) {
				$order->add_order_note( sprintf( 'Trondealer: payment received on unexpected network "%s" (expected "%s"). Holding for review.', $paid_network, $expected_param ) );
				$order->update_status( 'on-hold' );
				TDP_Orders::mark_processed( $order, $tx_uid . ':' . $event );
				return new WP_REST_Response( array( 'received' => true, 'note' => 'network_mismatch' ), 200 );
			}
		}

		if ( $paid_asset && $assignment['asset'] && $paid_asset !== strtoupper( $assignment['asset'] ) ) {
			$order->add_order_note( sprintf( 'Trondealer: paid asset "%s" differs from selected asset "%s". Holding for review.', $paid_asset, $assignment['asset'] ) );
			$order->update_status( 'on-hold' );
			TDP_Orders::mark_processed( $order, $tx_uid . ':' . $event );
			return new WP_REST_Response( array( 'received' => true, 'note' => 'asset_mismatch' ), 200 );
		}

		$paid_amount = isset( $data['amount'] ) ? $data['amount'] : '0';
		$tolerance   = (float) get_option( 'tdp_tolerance_pct', 1 );
		$status      = TDP_Orders::classify_amount( $paid_amount, $assignment['amount'], $tolerance );

		if ( 'underpaid' === $status ) {
			$order->add_order_note( sprintf( 'Trondealer: underpayment received. Paid %s %s, expected %s %s.', $paid_amount, $paid_asset, $assignment['amount'], $assignment['asset'] ) );
			$order->update_status( 'on-hold' );
			TDP_Orders::mark_processed( $order, $tx_uid . ':' . $event );
			return new WP_REST_Response( array( 'received' => true, 'note' => 'underpaid' ), 200 );
		}

		if ( 'transaction.incoming' === $event ) {
			self::handle_incoming( $order, $data, $paid_amount, $paid_asset );
		} elseif ( 'transaction.confirmed' === $event ) {
			self::handle_confirmed( $order, $data, $paid_amount, $paid_asset );
		}

		TDP_Orders::mark_processed( $order, $tx_uid . ':' . $event );
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function handle_incoming( WC_Order $order, array $data, $amount, $asset ) {
		if ( $order->has_status( array( 'completed', 'processing' ) ) ) {
			return;
		}
		$order->update_status(
			'on-hold',
			sprintf(
				'Trondealer: payment detected (%s %s), waiting for %d confirmations.',
				$amount,
				$asset,
				isset( $data['confirmations'] ) ? (int) $data['confirmations'] : 0
			)
		);
	}

	private static function handle_confirmed( WC_Order $order, array $data, $amount, $asset ) {
		if ( $order->has_status( array( 'completed', 'processing' ) ) ) {
			return;
		}
		$tx_ref = '';
		if ( isset( $data['tx_hash'] ) ) {
			$tx_ref = $data['tx_hash'];
		} elseif ( isset( $data['tx_id'] ) ) {
			$tx_ref = $data['tx_id'];
		} elseif ( isset( $data['tx_signature'] ) ) {
			$tx_ref = $data['tx_signature'];
		}
		$order->payment_complete( $tx_ref );
		$order->add_order_note(
			sprintf(
				'Trondealer: payment confirmed (%s %s, tx %s).',
				$amount,
				$asset,
				$tx_ref
			)
		);
	}

	private static function handle_swept( WC_Order $order, array $data ) {
		$dest    = isset( $data['destination'] ) ? $data['destination'] : '';
		$tx_hash = isset( $data['sweep_tx_hash'] ) ? $data['sweep_tx_hash'] : '';
		$order->add_order_note( sprintf( 'Trondealer: funds swept to %s (tx %s).', $dest, $tx_hash ) );
	}

	private static function verify_signature( $body, $signature, $secret ) {
		if ( empty( $signature ) || empty( $secret ) ) {
			return false;
		}
		$prefix = 'sha256=';
		if ( 0 !== strpos( $signature, $prefix ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $body, $secret );
		$received = substr( $signature, strlen( $prefix ) );
		return hash_equals( $expected, $received );
	}

	public static function order_status( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$key = (string) $request->get_param( 'key' );
		if ( ! $key || ! hash_equals( wp_hash( 'tdp_order_status_' . $id ), $key ) ) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}
		$order = wc_get_order( $id );
		if ( ! $order ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		return new WP_REST_Response(
			array(
				'status'   => $order->get_status(),
				'is_paid'  => $order->is_paid(),
				'redirect' => $order->is_paid() ? $order->get_view_order_url() : null,
			),
			200
		);
	}
}
