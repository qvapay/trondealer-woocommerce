<?php
/**
 * Refund handler. Disabled until the V2 withdraw endpoint with x-api-key
 * auth ships in tron-dealer. See plan file for details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_Refunds {

	public static function is_available() {
		return (bool) get_option( 'tdp_refunds_enabled', false );
	}

	public static function process( $order_id, $amount = null, $reason = '' ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'tdp_refunds_unavailable',
				__( 'Automated crypto refunds are not yet available. Please refund manually from your Trondealer dashboard.', 'trondealer-payments' )
			);
		}

		return new WP_Error(
			'tdp_refunds_not_implemented',
			__( 'Refund flow is not yet implemented in this plugin version.', 'trondealer-payments' )
		);
	}
}
