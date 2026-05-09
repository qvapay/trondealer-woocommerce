<?php
/**
 * WooCommerce payment gateway. Handles checkout-time wallet assignment,
 * thank-you page payment instructions, and admin settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TDP_Gateway extends WC_Payment_Gateway {

	const ID = 'trondealer';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Trondealer (Crypto)', 'trondealer-payments' );
		$this->method_description = __( 'Accept USDT and USDC stablecoin payments across 9 blockchains.', 'trondealer-payments' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Pay with Crypto (USDT / USDC)', 'trondealer-payments' ) );
		$this->description = $this->get_option( 'description', __( 'Pay with stablecoins across 9 blockchains.', 'trondealer-payments' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'render_thankyou' ), 10 );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	public function init_form_fields() {
		$network_options = array();
		foreach ( TDP_Networks::combinations() as $combo ) {
			$network_options[ $combo['id'] ] = $combo['label'];
		}

		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'trondealer-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Trondealer Payments', 'trondealer-payments' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Title', 'trondealer-payments' ),
				'type'        => 'text',
				'description' => __( 'Title shown to the customer at checkout.', 'trondealer-payments' ),
				'default'     => __( 'Pay with Crypto (USDT / USDC)', 'trondealer-payments' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'   => __( 'Description', 'trondealer-payments' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with stablecoins on TRON, Ethereum, BSC, Polygon, Arbitrum, Base, Optimism, Avalanche, or Solana.', 'trondealer-payments' ),
			),
			'api_key'          => array(
				'title'       => __( 'API Key', 'trondealer-payments' ),
				'type'        => 'password',
				'description' => __( 'Your Trondealer API key. Get one at https://trondealer.com.', 'trondealer-payments' ),
				'default'     => '',
			),
			'api_base'         => array(
				'title'       => __( 'API Base URL', 'trondealer-payments' ),
				'type'        => 'text',
				'description' => __( 'Override only if running a self-hosted Trondealer backend.', 'trondealer-payments' ),
				'default'     => TDP_API_BASE,
				'desc_tip'    => true,
			),
			'enabled_networks' => array(
				'title'   => __( 'Enabled Networks', 'trondealer-payments' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => $network_options,
				'default' => array_keys( $network_options ),
			),
			'tolerance'        => array(
				'title'             => __( 'Underpayment tolerance (%)', 'trondealer-payments' ),
				'type'              => 'number',
				'description'       => __( 'Allow payments up to this percentage below the order total before flagging as underpaid.', 'trondealer-payments' ),
				'default'           => '1',
				'custom_attributes' => array( 'min' => '0', 'max' => '10', 'step' => '0.1' ),
				'desc_tip'          => true,
			),
			'whitelabel'       => array(
				'title'   => __( 'White-label mode', 'trondealer-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Hide Trondealer branding from the customer-facing checkout.', 'trondealer-payments' ),
				'default' => 'no',
			),
		);
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( empty( $this->get_option( 'api_key' ) ) ) {
			return false;
		}
		if ( 'USD' !== get_woocommerce_currency() ) {
			return false;
		}
		return parent::is_available();
	}

	public function admin_options() {
		echo '<h2>' . esc_html( $this->method_title ) . '</h2>';
		echo '<p>' . esc_html( $this->method_description ) . '</p>';

		$test_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=tdp_connection_test' ),
			'tdp_connection_test'
		);
		echo '<p><a href="' . esc_url( $test_url ) . '" class="button button-secondary">' . esc_html__( 'Run connection test', 'trondealer-payments' ) . '</a></p>';

		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}

		$enabled_combos = (array) $this->get_option( 'enabled_networks', array() );
		$combos = array_filter(
			TDP_Networks::combinations(),
			function ( $combo ) use ( $enabled_combos ) {
				return in_array( $combo['id'], $enabled_combos, true );
			}
		);

		if ( empty( $combos ) ) {
			echo '<p>' . esc_html__( 'No networks enabled. Please contact the merchant.', 'trondealer-payments' ) . '</p>';
			return;
		}

		echo '<fieldset id="tdp-network-selector">';
		echo '<p class="form-row form-row-wide"><label for="tdp_network_choice">' . esc_html__( 'Choose network and asset', 'trondealer-payments' ) . ' <span class="required">*</span></label>';
		echo '<select name="tdp_network_choice" id="tdp_network_choice" required>';
		foreach ( $combos as $combo ) {
			printf(
				'<option value="%s">%s (~%s)</option>',
				esc_attr( $combo['id'] ),
				esc_html( $combo['label'] ),
				esc_html( $this->format_eta( $combo['eta_secs'] ) )
			);
		}
		echo '</select></p>';
		echo '</fieldset>';
	}

	public function validate_fields() {
		if ( empty( $_POST['tdp_network_choice'] ) ) {
			wc_add_notice( __( 'Please choose a network and asset.', 'trondealer-payments' ), 'error' );
			return false;
		}
		$choice = sanitize_text_field( wp_unslash( $_POST['tdp_network_choice'] ) );
		if ( ! preg_match( '/^[a-z]+:[A-Z]+$/', $choice ) ) {
			wc_add_notice( __( 'Invalid network selection.', 'trondealer-payments' ), 'error' );
			return false;
		}
		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$choice  = sanitize_text_field( wp_unslash( $_POST['tdp_network_choice'] ?? '' ) );
		$parts   = explode( ':', $choice );
		if ( count( $parts ) !== 2 ) {
			wc_add_notice( __( 'Invalid network selection.', 'trondealer-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}
		list( $network, $asset ) = $parts;

		if ( ! TDP_Networks::asset_supported( $network, $asset ) ) {
			wc_add_notice( __( 'Selected asset is not available on that network.', 'trondealer-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$client = new TDP_API_Client(
			$this->get_option( 'api_key' ),
			$this->get_option( 'api_base', TDP_API_BASE )
		);
		$response = $client->assign_wallet( $network, TDP_Orders::build_label( $order_id ) );

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( sprintf( 'Trondealer wallet assignment failed: %s', $response->get_error_message() ) );
			wc_add_notice( __( 'Could not generate a payment address. Please try again.', 'trondealer-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$wallet  = isset( $response['wallet'] ) ? $response['wallet'] : null;
		$address = $wallet && isset( $wallet['address'] ) ? $wallet['address'] : null;
		if ( empty( $address ) ) {
			$order->add_order_note( 'Trondealer returned no wallet address.' );
			wc_add_notice( __( 'Could not generate a payment address. Please try again.', 'trondealer-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		TDP_Orders::set_assignment(
			$order,
			$network,
			$asset,
			$address,
			$order->get_total(),
			isset( $wallet['id'] ) ? $wallet['id'] : null
		);

		$order->update_status(
			'pending',
			sprintf(
				/* translators: 1: asset, 2: network */
				__( 'Awaiting %1$s payment on %2$s.', 'trondealer-payments' ),
				$asset,
				$network
			)
		);

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	public function render_thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== self::ID ) {
			return;
		}
		$assignment = TDP_Orders::get_assignment( $order );
		if ( empty( $assignment['address'] ) ) {
			return;
		}

		$net = TDP_Networks::get( $assignment['network'] );
		if ( ! $net ) {
			return;
		}

		$payment_uri = TDP_Networks::build_payment_uri(
			$assignment['network'],
			$assignment['asset'],
			$assignment['address'],
			$assignment['amount']
		);

		$status_url = rest_url( 'tdp/v1/order-status/' . $order->get_id() );
		$status_key = wp_hash( 'tdp_order_status_' . $order->get_id() );

		include TDP_PLUGIN_DIR . 'templates/checkout-payment.php';
	}

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || $order->get_payment_method() !== self::ID ) {
			return;
		}
		if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return;
		}
		$assignment = TDP_Orders::get_assignment( $order );
		if ( empty( $assignment['address'] ) ) {
			return;
		}
		$net = TDP_Networks::get( $assignment['network'] );
		if ( $plain_text ) {
			printf(
				"%s\n%s: %s\n%s: %s %s\n\n",
				esc_html__( 'Send your payment to the address below.', 'trondealer-payments' ),
				esc_html__( 'Network', 'trondealer-payments' ),
				esc_html( $net['label'] ),
				esc_html__( 'Amount', 'trondealer-payments' ),
				esc_html( $assignment['amount'] ),
				esc_html( $assignment['asset'] )
			);
			echo esc_html( $assignment['address'] ) . "\n";
		} else {
			include TDP_PLUGIN_DIR . 'templates/emails/crypto-payment-instructions.php';
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return TDP_Refunds::process( $order_id, $amount, $reason );
	}

	private function format_eta( $secs ) {
		if ( $secs < 60 ) {
			return sprintf( '%ds', $secs );
		}
		return sprintf( '%dm', max( 1, (int) round( $secs / 60 ) ) );
	}
}
