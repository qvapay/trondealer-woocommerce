<?php
/**
 * Order metadata helpers and idempotency logic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_Orders {

	const META_ADDRESS         = '_tdp_address';
	const META_AMOUNT           = '_tdp_amount';
	const META_ASSET            = '_tdp_asset';
	const META_CHAIN            = '_tdp_chain';
	const META_ASSIGNED_AT      = '_tdp_assigned_at';
	const META_WALLET_ID        = '_tdp_wallet_id';
	const META_PROCESSED_TX_IDS = '_tdp_processed_tx_ids';

	const LABEL_PREFIX = 'wc_';

	public static function build_label( $order_id ) {
		return self::LABEL_PREFIX . (int) $order_id;
	}

	public static function parse_label( $label ) {
		if ( ! is_string( $label ) || 0 !== strpos( $label, self::LABEL_PREFIX ) ) {
			return null;
		}
		$tail = substr( $label, strlen( self::LABEL_PREFIX ) );
		if ( ! ctype_digit( $tail ) ) {
			return null;
		}
		return (int) $tail;
	}

	public static function set_assignment( WC_Order $order, $network, $asset, $address, $amount, $wallet_id = null ) {
		$order->update_meta_data( self::META_CHAIN, sanitize_text_field( $network ) );
		$order->update_meta_data( self::META_ASSET, strtoupper( sanitize_text_field( $asset ) ) );
		$order->update_meta_data( self::META_ADDRESS, sanitize_text_field( $address ) );
		$order->update_meta_data( self::META_AMOUNT, (string) $amount );
		$order->update_meta_data( self::META_ASSIGNED_AT, gmdate( 'c' ) );
		if ( $wallet_id ) {
			$order->update_meta_data( self::META_WALLET_ID, sanitize_text_field( $wallet_id ) );
		}
		$order->save();
	}

	public static function get_assignment( WC_Order $order ) {
		return array(
			'network'     => $order->get_meta( self::META_CHAIN ),
			'asset'       => $order->get_meta( self::META_ASSET ),
			'address'     => $order->get_meta( self::META_ADDRESS ),
			'amount'      => $order->get_meta( self::META_AMOUNT ),
			'assigned_at' => $order->get_meta( self::META_ASSIGNED_AT ),
			'wallet_id'   => $order->get_meta( self::META_WALLET_ID ),
		);
	}

	public static function build_tx_uid( $network_key, array $tx ) {
		$family = TDP_Networks::family( $network_key );
		switch ( $family ) {
			case TDP_Networks::FAMILY_EVM:
				$hash  = isset( $tx['tx_hash'] ) ? $tx['tx_hash'] : '';
				$index = isset( $tx['log_index'] ) ? $tx['log_index'] : '';
				return $hash . ':' . $index;
			case TDP_Networks::FAMILY_TRON:
				$id    = isset( $tx['tx_id'] ) ? $tx['tx_id'] : '';
				$index = isset( $tx['event_index'] ) ? $tx['event_index'] : '';
				return $id . ':' . $index;
			case TDP_Networks::FAMILY_SOL:
				$sig   = isset( $tx['tx_signature'] ) ? $tx['tx_signature'] : '';
				$index = isset( $tx['instruction_index'] ) ? $tx['instruction_index'] : '';
				return $sig . ':' . $index;
			default:
				return '';
		}
	}

	public static function was_processed( WC_Order $order, $tx_uid ) {
		if ( empty( $tx_uid ) ) {
			return false;
		}
		$processed = $order->get_meta( self::META_PROCESSED_TX_IDS );
		if ( ! is_array( $processed ) ) {
			$processed = array();
		}
		return in_array( $tx_uid, $processed, true );
	}

	public static function mark_processed( WC_Order $order, $tx_uid ) {
		if ( empty( $tx_uid ) ) {
			return;
		}
		$processed = $order->get_meta( self::META_PROCESSED_TX_IDS );
		if ( ! is_array( $processed ) ) {
			$processed = array();
		}
		if ( ! in_array( $tx_uid, $processed, true ) ) {
			$processed[] = $tx_uid;
			$order->update_meta_data( self::META_PROCESSED_TX_IDS, $processed );
			$order->save();
		}
	}

	/**
	 * Compare paid amount against expected amount with tolerance.
	 *
	 * @return string 'paid' (>= expected), 'underpaid' (below tolerance), or 'overpaid' (> expected).
	 */
	public static function classify_amount( $paid, $expected, $tolerance_pct = 1.0 ) {
		$paid     = (float) $paid;
		$expected = (float) $expected;
		if ( $expected <= 0 ) {
			return 'paid';
		}
		$min_acceptable = $expected * ( 1 - ( $tolerance_pct / 100 ) );
		if ( $paid < $min_acceptable ) {
			return 'underpaid';
		}
		if ( $paid > $expected ) {
			return 'overpaid';
		}
		return 'paid';
	}
}
