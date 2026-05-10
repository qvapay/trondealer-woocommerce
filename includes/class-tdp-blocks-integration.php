<?php
/**
 * WooCommerce Blocks (the new block-based checkout) integration.
 *
 * Hooks straight into woocommerce_blocks_payment_method_type_registration
 * so the listener exists before WC Blocks fires the registration phase —
 * going through woocommerce_blocks_loaded is unreliable because Blocks
 * may already have fired the registration action by the time our hook
 * callback runs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_Blocks_Integration {

	public static function register( $registry = null ) {
		if ( null === $registry ) {
			return;
		}
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-blocks-method.php';

		if ( ! class_exists( 'TDP_Blocks_Method' ) ) {
			return;
		}

		$registry->register( new TDP_Blocks_Method() );
	}
}
