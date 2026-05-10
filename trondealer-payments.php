<?php
/**
 * Plugin Name: Trondealer Payments
 * Plugin URI: https://trondealer.com/woocommerce
 * Description: Accept USDT and USDC stablecoin payments in WooCommerce across 9 blockchains (TRON, Ethereum, BSC, Polygon, Arbitrum, Base, Optimism, Avalanche, Solana) via the Trondealer V2 API.
 * Version: 0.1.0
 * Author: Trondealer
 * Author URI: https://trondealer.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: trondealer-payments
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TDP_VERSION', '0.1.0' );
define( 'TDP_PLUGIN_FILE', __FILE__ );
define( 'TDP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TDP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TDP_API_BASE', 'https://trondealer.com/api/v2' );

require_once TDP_PLUGIN_DIR . 'includes/class-tdp-plugin.php';

add_action( 'plugins_loaded', array( 'TDP_Plugin', 'instance' ), 11 );

register_activation_hook( __FILE__, array( 'TDP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TDP_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
