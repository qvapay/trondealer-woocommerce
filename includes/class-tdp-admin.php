<?php
/**
 * Admin glue: connection test, settings sync, transient notices.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_Admin {

	const NOTICE_TRANSIENT = 'tdp_admin_notice';

	public static function init() {
		add_action( 'admin_post_tdp_connection_test', array( __CLASS__, 'handle_connection_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'update_option_woocommerce_' . TDP_Gateway::ID . '_settings', array( __CLASS__, 'sync_settings' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( TDP_PLUGIN_FILE ), array( __CLASS__, 'plugin_action_links' ) );
	}

	public static function plugin_action_links( $links ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . TDP_Gateway::ID );
		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'trondealer-payments' ) . '</a>'
		);
		return $links;
	}

	public static function sync_settings( $old, $new ) {
		if ( is_array( $new ) ) {
			update_option( 'tdp_api_key', isset( $new['api_key'] ) ? $new['api_key'] : '' );
			update_option( 'tdp_api_base', isset( $new['api_base'] ) ? $new['api_base'] : TDP_API_BASE );
			update_option( 'tdp_tolerance_pct', isset( $new['tolerance'] ) ? (float) $new['tolerance'] : 1 );
		}
	}

	public static function handle_connection_test() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'tdp_connection_test' );

		$settings = get_option( 'woocommerce_' . TDP_Gateway::ID . '_settings', array() );
		$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		$api_base = isset( $settings['api_base'] ) ? $settings['api_base'] : TDP_API_BASE;

		if ( empty( $api_key ) ) {
			self::add_notice( 'error', __( 'API key is empty. Save it before running the test.', 'trondealer-payments' ) );
			self::redirect_back();
		}

		$client = new TDP_API_Client( $api_key, $api_base );

		$me = $client->get_client_info();
		if ( is_wp_error( $me ) ) {
			self::add_notice( 'error', sprintf( /* translators: %s: error message */ __( 'API key check failed: %s', 'trondealer-payments' ), $me->get_error_message() ) );
			self::redirect_back();
		}

		$webhook_url = rest_url( TDP_Webhook::ROUTE_NAMESPACE . '/webhook' );
		$secret      = get_option( 'tdp_webhook_secret', '' );
		if ( empty( $secret ) ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( 'tdp_webhook_secret', $secret );
		}

		$update = $client->update_client(
			array(
				'webhook_url'    => $webhook_url,
				'webhook_secret' => $secret,
			)
		);
		if ( is_wp_error( $update ) ) {
			self::add_notice( 'error', sprintf( /* translators: %s: error message */ __( 'Failed to register webhook URL: %s', 'trondealer-payments' ), $update->get_error_message() ) );
			self::redirect_back();
		}

		$test = $client->webhook_test(
			array(
				'event' => 'connection.test',
				'data'  => array( 'note' => 'Sent from WordPress admin connection test' ),
			)
		);
		if ( is_wp_error( $test ) ) {
			self::add_notice( 'warning', sprintf( /* translators: %s: error message */ __( 'API key OK and webhook URL saved, but the test ping failed: %s', 'trondealer-payments' ), $test->get_error_message() ) );
			self::redirect_back();
		}

		$resp_status = isset( $test['response']['status'] ) ? $test['response']['status'] : 0;
		if ( (int) $resp_status >= 200 && (int) $resp_status < 300 ) {
			self::add_notice(
				'success',
				sprintf(
					/* translators: 1: client name 2: webhook URL */
					__( 'Connected as %1$s. Webhook URL %2$s registered and verified.', 'trondealer-payments' ),
					isset( $me['client']['name'] ) ? $me['client']['name'] : '(unknown)',
					$webhook_url
				)
			);
		} else {
			self::add_notice(
				'warning',
				sprintf(
					/* translators: 1: HTTP status 2: response body */
					__( 'Webhook test returned HTTP %1$d. Body: %2$s', 'trondealer-payments' ),
					(int) $resp_status,
					isset( $test['response']['body'] ) ? wp_strip_all_tags( $test['response']['body'] ) : ''
				)
			);
		}

		self::redirect_back();
	}

	private static function add_notice( $type, $message ) {
		set_transient( self::NOTICE_TRANSIENT, array( 'type' => $type, 'message' => $message ), 60 );
	}

	private static function redirect_back() {
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . TDP_Gateway::ID );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function render_notice() {
		$notice = get_transient( self::NOTICE_TRANSIENT );
		if ( ! $notice ) {
			return;
		}
		delete_transient( self::NOTICE_TRANSIENT );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice['type'] ),
			wp_kses_post( $notice['message'] )
		);
	}
}
