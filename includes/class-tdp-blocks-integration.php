<?php
/**
 * WooCommerce Blocks (the new block-based checkout) integration.
 *
 * Registers the gateway with the Blocks Payment API. The actual React
 * payment block is shipped in assets/js/checkout-blocks.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_Blocks_Integration {

	public static function register() {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				$registry->register( new TDP_Blocks_Method() );
			}
		);
	}
}

if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	class TDP_Blocks_Method extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

		protected $name = TDP_Gateway::ID;

		public function initialize() {
			$this->settings = get_option( 'woocommerce_' . TDP_Gateway::ID . '_settings', array() );
		}

		public function is_active() {
			return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
		}

		public function get_payment_method_script_handles() {
			$handle = 'tdp-blocks';
			wp_register_script(
				$handle,
				TDP_PLUGIN_URL . 'assets/js/checkout-blocks.js',
				array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
				TDP_VERSION,
				true
			);
			return array( $handle );
		}

		public function get_supported_features() {
			return array( 'products' );
		}

		public function get_payment_method_data() {
			$enabled_combos = isset( $this->settings['enabled_networks'] ) ? (array) $this->settings['enabled_networks'] : array();
			$combos         = array_filter(
				TDP_Networks::combinations(),
				function ( $combo ) use ( $enabled_combos ) {
					return in_array( $combo['id'], $enabled_combos, true );
				}
			);

			return array(
				'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Pay with Crypto (USDT / USDC)', 'trondealer-payments' ),
				'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
				'combos'      => array_values( $combos ),
			);
		}
	}
}
