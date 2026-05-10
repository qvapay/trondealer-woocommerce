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
		$this->icon               = apply_filters( 'tdp_gateway_icon', TDP_PLUGIN_URL . 'assets/images/trondealer.svg', $this );
		$this->method_title       = __( 'Trondealer', 'trondealer-payments' );
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
		$default_combos = array();
		foreach ( TDP_Networks::combinations() as $combo ) {
			$default_combos[] = $combo['id'];
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
				'title'       => __( 'Enabled Networks', 'trondealer-payments' ),
				'type'        => 'tdp_network_matrix',
				'description' => __( 'Pick which network/asset combinations are available at checkout.', 'trondealer-payments' ),
				'default'     => $default_combos,
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

	public function generate_tdp_network_matrix_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$value     = (array) $this->get_option( $key, isset( $data['default'] ) ? $data['default'] : array() );
		$desc      = isset( $data['description'] ) ? $data['description'] : '';
		$title     = isset( $data['title'] ) ? $data['title'] : '';

		wp_enqueue_style(
			'tdp-admin',
			TDP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			TDP_VERSION
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $title ); ?></label>
			</th>
			<td class="forminp">
				<fieldset class="tdp-admin-matrix">
					<?php foreach ( TDP_Networks::all() as $net_key => $net ) : ?>
						<div class="tdp-admin-matrix__row">
							<div class="tdp-admin-matrix__network">
								<?php $icon = TDP_Networks::network_icon_url( $net_key ); ?>
								<?php if ( $icon ) : ?>
									<img src="<?php echo esc_url( $icon ); ?>" alt="" class="tdp-admin-matrix__icon" width="24" height="24" />
								<?php endif; ?>
								<span class="tdp-admin-matrix__label"><?php echo esc_html( $net['label'] ); ?></span>
								<span class="tdp-admin-matrix__eta">~<?php echo esc_html( $net['confirm_eta'] < 60 ? $net['confirm_eta'] . 's' : max( 1, (int) round( $net['confirm_eta'] / 60 ) ) . 'm' ); ?></span>
							</div>
							<div class="tdp-admin-matrix__assets">
								<?php foreach ( $net['assets'] as $asset ) :
									$combo  = $net_key . ':' . $asset;
									$icon_a = TDP_Networks::asset_icon_url( $asset );
								?>
									<label class="tdp-admin-matrix__pill">
										<input type="checkbox" name="<?php echo esc_attr( $field_key ); ?>[]" value="<?php echo esc_attr( $combo ); ?>" <?php checked( in_array( $combo, $value, true ) ); ?> />
										<?php if ( $icon_a ) : ?>
											<img src="<?php echo esc_url( $icon_a ); ?>" alt="" width="16" height="16" />
										<?php endif; ?>
										<span><?php echo esc_html( $asset ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
					<?php if ( $desc ) : ?>
						<p class="description"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function validate_tdp_network_matrix_field( $key, $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$valid_combos = array();
		foreach ( TDP_Networks::combinations() as $combo ) {
			$valid_combos[] = $combo['id'];
		}
		return array_values( array_intersect( $value, $valid_combos ) );
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
		$networks       = array();
		foreach ( TDP_Networks::all() as $key => $net ) {
			$assets = array();
			foreach ( $net['assets'] as $asset ) {
				$combo_id = $key . ':' . $asset;
				if ( in_array( $combo_id, $enabled_combos, true ) ) {
					$assets[] = array( 'id' => $combo_id, 'symbol' => $asset );
				}
			}
			if ( ! empty( $assets ) ) {
				$networks[] = array( 'key' => $key, 'net' => $net, 'assets' => $assets );
			}
		}

		if ( empty( $networks ) ) {
			echo '<p>' . esc_html__( 'No networks enabled. Please contact the merchant.', 'trondealer-payments' ) . '</p>';
			return;
		}

		wp_enqueue_style(
			'tdp-checkout',
			TDP_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			TDP_VERSION
		);

		$first = true;
		echo '<fieldset id="tdp-network-selector" class="tdp-network-grid">';
		foreach ( $networks as $row ) {
			$net  = $row['net'];
			$key  = $row['key'];
			$icon = TDP_Networks::network_icon_url( $key );
			?>
			<div class="tdp-network-card" data-network="<?php echo esc_attr( $key ); ?>">
				<div class="tdp-network-card__header">
					<?php if ( $icon ) : ?>
						<img src="<?php echo esc_url( $icon ); ?>" alt="" class="tdp-icon tdp-icon--network" width="28" height="28" />
					<?php else : ?>
						<span class="tdp-icon tdp-icon--fallback" aria-hidden="true"><?php echo esc_html( strtoupper( substr( $net['label'], 0, 2 ) ) ); ?></span>
					<?php endif; ?>
					<div class="tdp-network-card__meta">
						<span class="tdp-network-card__label"><?php echo esc_html( $net['label'] ); ?></span>
						<span class="tdp-network-card__eta">~<?php echo esc_html( $this->format_eta( $net['confirm_eta'] ) ); ?></span>
					</div>
				</div>
				<div class="tdp-network-card__assets">
					<?php foreach ( $row['assets'] as $a ) :
						$asset_icon = TDP_Networks::asset_icon_url( $a['symbol'] );
					?>
						<label class="tdp-asset-pill">
							<input type="radio" name="tdp_network_choice" value="<?php echo esc_attr( $a['id'] ); ?>" <?php checked( $first ); ?> required />
							<?php if ( $asset_icon ) : ?>
								<img src="<?php echo esc_url( $asset_icon ); ?>" alt="" width="16" height="16" class="tdp-asset-pill__icon" />
							<?php endif; ?>
							<span class="tdp-asset-pill__symbol"><?php echo esc_html( $a['symbol'] ); ?></span>
						</label>
						<?php $first = false; ?>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}
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
		if ( $order->is_paid() ) {
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
