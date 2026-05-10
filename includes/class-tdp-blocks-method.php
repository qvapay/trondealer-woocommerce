<?php
/**
 * Block-based checkout payment method type. Loaded lazily by
 * TDP_Blocks_Integration::register() once we know the abstract base
 * class from WooCommerce Blocks is available.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	return;
}

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
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			TDP_VERSION,
			true
		);
		wp_register_style(
			'tdp-checkout',
			TDP_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			TDP_VERSION
		);
		wp_enqueue_style( 'tdp-checkout' );
		return array( $handle );
	}

	public function get_supported_features() {
		return array( 'products' );
	}

	public function get_payment_method_data() {
		$enabled_combos = isset( $this->settings['enabled_networks'] ) ? (array) $this->settings['enabled_networks'] : array();
		$networks       = array();

		foreach ( TDP_Networks::all() as $key => $net ) {
			$assets = array();
			foreach ( $net['assets'] as $asset ) {
				$combo_id = $key . ':' . $asset;
				if ( ! in_array( $combo_id, $enabled_combos, true ) ) {
					continue;
				}
				$assets[] = array(
					'id'     => $combo_id,
					'symbol' => $asset,
					'icon'   => TDP_Networks::asset_icon_url( $asset ),
				);
			}
			if ( empty( $assets ) ) {
				continue;
			}
			$networks[] = array(
				'key'      => $key,
				'label'    => $net['label'],
				'icon'     => TDP_Networks::network_icon_url( $key ),
				'eta_secs' => $net['confirm_eta'],
				'assets'   => $assets,
			);
		}

		return array(
			'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Pay with Crypto (USDT / USDC)', 'trondealer-payments' ),
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'networks'    => $networks,
			'supports'    => array( 'products' ),
		);
	}
}
