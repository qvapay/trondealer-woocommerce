<?php
/**
 * HTML email block with payment instructions.
 *
 * Available vars:
 *  - $order      WC_Order
 *  - $assignment array
 *  - $net        array
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2><?php esc_html_e( 'Payment instructions', 'trondealer-payments' ); ?></h2>
<p>
	<?php
	printf(
		/* translators: 1: amount 2: asset 3: network */
		esc_html__( 'Please send exactly %1$s %2$s on the %3$s network to the address below to complete your order.', 'trondealer-payments' ),
		'<strong>' . esc_html( $assignment['amount'] ) . '</strong>',
		'<strong>' . esc_html( $assignment['asset'] ) . '</strong>',
		'<strong>' . esc_html( $net['label'] ) . '</strong>'
	);
	?>
</p>
<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;border:1px solid #e5e5e5;">
	<tr>
		<td style="border:1px solid #e5e5e5;background:#f7f7f7;font-weight:600;width:30%;"><?php esc_html_e( 'Network', 'trondealer-payments' ); ?></td>
		<td style="border:1px solid #e5e5e5;"><?php echo esc_html( $net['label'] ); ?></td>
	</tr>
	<tr>
		<td style="border:1px solid #e5e5e5;background:#f7f7f7;font-weight:600;"><?php esc_html_e( 'Asset', 'trondealer-payments' ); ?></td>
		<td style="border:1px solid #e5e5e5;"><?php echo esc_html( $assignment['asset'] ); ?></td>
	</tr>
	<tr>
		<td style="border:1px solid #e5e5e5;background:#f7f7f7;font-weight:600;"><?php esc_html_e( 'Amount', 'trondealer-payments' ); ?></td>
		<td style="border:1px solid #e5e5e5;"><code><?php echo esc_html( $assignment['amount'] ); ?> <?php echo esc_html( $assignment['asset'] ); ?></code></td>
	</tr>
	<tr>
		<td style="border:1px solid #e5e5e5;background:#f7f7f7;font-weight:600;"><?php esc_html_e( 'Address', 'trondealer-payments' ); ?></td>
		<td style="border:1px solid #e5e5e5;word-break:break-all;"><code><?php echo esc_html( $assignment['address'] ); ?></code></td>
	</tr>
</table>
<p style="color:#a00;">
	<?php
	printf(
		/* translators: %s: asset label */
		esc_html__( 'Only send %s on the network shown above. Sending any other token or using a different network will result in lost funds.', 'trondealer-payments' ),
		esc_html( $assignment['asset'] )
	);
	?>
</p>
