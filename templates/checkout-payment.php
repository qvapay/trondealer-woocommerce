<?php
/**
 * Thank-you page payment instructions.
 *
 * Available vars:
 *  - $order      WC_Order
 *  - $assignment array (network, asset, address, amount, assigned_at)
 *  - $net        array from TDP_Networks::get
 *  - $payment_uri string
 *  - $status_url string
 *  - $status_key string
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$qr_url = add_query_arg(
	array(
		'size' => '220x220',
		'data' => rawurlencode( $payment_uri ),
	),
	'https://api.qrserver.com/v1/create-qr-code/'
);
?>

<section class="tdp-payment-instructions" data-order="<?php echo esc_attr( $order->get_id() ); ?>" data-status-url="<?php echo esc_url( $status_url ); ?>" data-status-key="<?php echo esc_attr( $status_key ); ?>">
	<h2><?php esc_html_e( 'Send your payment', 'trondealer-payments' ); ?></h2>
	<p class="tdp-instructions-intro">
		<?php
		printf(
			/* translators: 1: amount 2: asset 3: network label */
			esc_html__( 'Send exactly %1$s %2$s on the %3$s network to the address below. The page will refresh automatically when the payment is detected.', 'trondealer-payments' ),
			'<strong>' . esc_html( $assignment['amount'] ) . '</strong>',
			'<strong>' . esc_html( $assignment['asset'] ) . '</strong>',
			'<strong>' . esc_html( $net['label'] ) . '</strong>'
		);
		?>
	</p>

	<div class="tdp-payment-grid">
		<div class="tdp-payment-qr">
			<img src="<?php echo esc_url( $qr_url ); ?>" alt="<?php esc_attr_e( 'Payment QR code', 'trondealer-payments' ); ?>" width="220" height="220" />
		</div>
		<div class="tdp-payment-details">
			<dl>
				<dt><?php esc_html_e( 'Network', 'trondealer-payments' ); ?></dt>
				<dd><?php echo esc_html( $net['label'] ); ?></dd>
				<dt><?php esc_html_e( 'Asset', 'trondealer-payments' ); ?></dt>
				<dd><?php echo esc_html( $assignment['asset'] ); ?></dd>
				<dt><?php esc_html_e( 'Amount', 'trondealer-payments' ); ?></dt>
				<dd><code><?php echo esc_html( $assignment['amount'] ); ?> <?php echo esc_html( $assignment['asset'] ); ?></code></dd>
				<dt><?php esc_html_e( 'Address', 'trondealer-payments' ); ?></dt>
				<dd>
					<code class="tdp-address"><?php echo esc_html( $assignment['address'] ); ?></code>
					<button type="button" class="button tdp-copy" data-target=".tdp-address"><?php esc_html_e( 'Copy', 'trondealer-payments' ); ?></button>
				</dd>
			</dl>
		</div>
	</div>

	<p class="tdp-status" aria-live="polite">
		<span class="tdp-status-dot"></span>
		<span class="tdp-status-text"><?php esc_html_e( 'Waiting for payment...', 'trondealer-payments' ); ?></span>
	</p>

	<p class="tdp-warning">
		<?php
		printf(
			/* translators: %s: asset label */
			esc_html__( 'Important: only send %s on the network shown above. Sending any other token, or sending on a different network, will result in lost funds.', 'trondealer-payments' ),
			esc_html( $assignment['asset'] )
		);
		?>
	</p>
</section>

<?php
wp_enqueue_script(
	'tdp-thankyou-poller',
	TDP_PLUGIN_URL . 'assets/js/thankyou-poller.js',
	array(),
	TDP_VERSION,
	true
);
wp_enqueue_style(
	'tdp-checkout',
	TDP_PLUGIN_URL . 'assets/css/checkout.css',
	array(),
	TDP_VERSION
);
