<?php
/**
 * Main plugin singleton. Bootstraps autoload, hooks, and integrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'wc_missing_notice' ) );
			return;
		}

		$this->load_dependencies();
		$this->register_hooks();
	}

	private function load_dependencies() {
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-networks.php';
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-api-client.php';
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-orders.php';
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-gateway.php';
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-webhook.php';
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-cron-fallback.php';
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-admin.php';
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-refunds.php';
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-blocks-integration.php';
	}

	private function register_hooks() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( 'TDP_Webhook', 'register_routes' ) );
		add_action( 'tdp_reconcile_pending_orders', array( 'TDP_Cron_Fallback', 'run' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( 'TDP_Blocks_Integration', 'register' ), 5 );

		TDP_Cron_Fallback::register_schedule();
		TDP_Admin::init();
	}

	public function register_gateway( $gateways ) {
		$gateways[] = 'TDP_Gateway';
		return $gateways;
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'trondealer-payments', false, dirname( plugin_basename( TDP_PLUGIN_FILE ) ) . '/languages' );
	}

	public static function activate() {
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-cron-fallback.php';
		TDP_Cron_Fallback::register_schedule();
	}

	public static function deactivate() {
		require_once TDP_PLUGIN_DIR . 'includes/class-tdp-cron-fallback.php';
		TDP_Cron_Fallback::clear_schedule();
	}

	public function wc_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Trondealer Payments requires WooCommerce to be installed and active.', 'trondealer-payments' );
		echo '</p></div>';
	}
}
