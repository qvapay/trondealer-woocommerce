<?php
/**
 * WP-Cron polling fallback. Catches transactions that the API webhook failed
 * to deliver (the V2 backend currently only retries 3 times without backoff,
 * so a brief site outage can drop a confirmation permanently).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_Cron_Fallback {

	const HOOK     = 'tdp_reconcile_pending_orders';
	const SCHEDULE = 'tdp_5min';

	public static function register_schedule() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
		}
	}

	public static function clear_schedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public static function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Trondealer)', 'trondealer-payments' ),
		);
		return $schedules;
	}

	public static function run() {
		$gateway_settings = get_option( 'woocommerce_' . TDP_Gateway::ID . '_settings', array() );
		if ( empty( $gateway_settings['api_key'] ) ) {
			return;
		}

		$client = new TDP_API_Client(
			$gateway_settings['api_key'],
			isset( $gateway_settings['api_base'] ) ? $gateway_settings['api_base'] : TDP_API_BASE
		);

		$orders = wc_get_orders(
			array(
				'limit'          => 50,
				'payment_method' => TDP_Gateway::ID,
				'status'         => array( 'pending', 'on-hold' ),
				'date_created'   => '>' . ( time() - ( 14 * DAY_IN_SECONDS ) ),
			)
		);

		foreach ( $orders as $order ) {
			self::reconcile_order( $client, $order );
		}
	}

	private static function reconcile_order( TDP_API_Client $client, WC_Order $order ) {
		$assignment = TDP_Orders::get_assignment( $order );
		if ( empty( $assignment['address'] ) || empty( $assignment['network'] ) ) {
			return;
		}

		$age = time() - strtotime( $order->get_date_created()->date( 'c' ) );
		if ( $age < 2 * MINUTE_IN_SECONDS ) {
			return;
		}

		$response = $client->get_transactions( $assignment['network'], $assignment['address'], 20 );
		if ( is_wp_error( $response ) || empty( $response['transactions'] ) ) {
			return;
		}

		$tolerance = (float) get_option( 'tdp_tolerance_pct', 1 );

		foreach ( $response['transactions'] as $tx ) {
			$tx_uid = TDP_Orders::build_tx_uid( $assignment['network'], $tx );
			if ( empty( $tx_uid ) ) {
				continue;
			}

			$paid_asset = isset( $tx['asset'] ) ? strtoupper( $tx['asset'] ) : '';
			if ( $paid_asset !== strtoupper( $assignment['asset'] ) ) {
				continue;
			}

			$paid_amount = isset( $tx['amount'] ) ? $tx['amount'] : '0';
			$status_cls  = TDP_Orders::classify_amount( $paid_amount, $assignment['amount'], $tolerance );
			if ( 'underpaid' === $status_cls ) {
				continue;
			}

			$tx_status = isset( $tx['status'] ) ? $tx['status'] : '';

			if ( 'detected' === $tx_status && ! TDP_Orders::was_processed( $order, $tx_uid . ':transaction.incoming' ) ) {
				if ( ! $order->has_status( array( 'completed', 'processing' ) ) ) {
					$order->update_status(
						'on-hold',
						sprintf(
							'Trondealer (poll): payment detected (%s %s).',
							$paid_amount,
							$paid_asset
						)
					);
				}
				TDP_Orders::mark_processed( $order, $tx_uid . ':transaction.incoming' );
			}

			if ( in_array( $tx_status, array( 'confirmed', 'notified', 'swept' ), true )
				&& ! TDP_Orders::was_processed( $order, $tx_uid . ':transaction.confirmed' ) ) {
				$tx_ref = '';
				if ( isset( $tx['tx_hash'] ) ) {
					$tx_ref = $tx['tx_hash'];
				} elseif ( isset( $tx['tx_id'] ) ) {
					$tx_ref = $tx['tx_id'];
				} elseif ( isset( $tx['tx_signature'] ) ) {
					$tx_ref = $tx['tx_signature'];
				}
				if ( ! $order->has_status( array( 'completed', 'processing' ) ) ) {
					$order->payment_complete( $tx_ref );
					$order->add_order_note(
						sprintf(
							'Trondealer (poll): payment confirmed (%s %s, tx %s).',
							$paid_amount,
							$paid_asset,
							$tx_ref
						)
					);
				}
				TDP_Orders::mark_processed( $order, $tx_uid . ':transaction.confirmed' );
			}
		}
	}
}
