<?php
/**
 * XPay_Order_Panel
 *
 * The XPay meta box on the order edit screen: this order's XPay identifiers
 * (verbatim, copyable — support traceability) and its recent log entries,
 * so "what happened to this payment" is answered on the order itself, with
 * no log-digging. Registered for both the HPOS orders screen and the
 * legacy post-based screen.
 *
 * Read-only surface behind manage_woocommerce; everything shown was
 * redacted at write time.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Order_Panel {

	/** Rows shown inline on the order screen. */
	const ROWS = 10;

	public static function register(): void {
		$screen = class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order';

		add_meta_box(
			'xpay-order-panel',
			__( 'XPay', 'xpay-for-woocommerce' ),
			array( __CLASS__, 'render' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order Screen object (HPOS passes the order).
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof WC_Order || XPay_Constants::GATEWAY_ID !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'This order was not paid with XPay.', 'xpay-for-woocommerce' ) . '</p>';
			return;
		}

		$session_id = (string) $order->get_meta( XPay_Constants::META_SESSION_ID );
		$intent_id  = (string) $order->get_meta( XPay_Constants::META_PAYMENT_INTENT );
		$attempt    = (int) $order->get_meta( XPay_Constants::META_ATTEMPT );

		$customer_id = (string) $order->get_meta( XPay_Constants::META_CUSTOMER_ID );

		echo '<p style="word-break:break-all">';
		echo esc_html__( 'Session:', 'xpay-for-woocommerce' ) . ' <code>' . esc_html( '' !== $session_id ? $session_id : '—' ) . '</code><br />';
		echo esc_html__( 'Payment intent:', 'xpay-for-woocommerce' ) . ' <code>' . esc_html( '' !== $intent_id ? $intent_id : '—' ) . '</code><br />';
		echo esc_html__( 'Customer:', 'xpay-for-woocommerce' ) . ' <code>' . esc_html( '' !== $customer_id ? $customer_id : '—' ) . '</code><br />';
		/* translators: %d is how many payment attempts were made for this order. */
		echo esc_html( sprintf( __( 'Attempts: %d', 'xpay-for-woocommerce' ), max( $attempt, 0 ) ) );
		echo '</p>';

		$rows = XPay_Log_Store::query(
			array(
				'order_id' => $order->get_id(),
				'limit'    => self::ROWS,
			)
		);

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No log entries for this order. Enable diagnostic logging in the XPay settings to record payment events.', 'xpay-for-woocommerce' ) . '</p>';
		} else {
			echo '<ul style="font-family:monospace;font-size:11px;margin:0">';
			foreach ( $rows as $row ) {
				echo '<li style="margin-bottom:4px"><strong>' . esc_html( (string) $row['stage'] ) . '</strong> <span title="' . esc_attr( (string) $row['created_at'] ) . ' UTC">' . esc_html( (string) $row['created_at'] ) . '</span></li>';
			}
			echo '</ul>';
		}

		$log_url = add_query_arg(
			array(
				'page'       => 'xpay-log',
				'order_id'   => $order->get_id(),
				'_xpaynonce' => wp_create_nonce( 'xpay-log-filter' ),
			),
			admin_url( 'admin.php' )
		);
		echo '<p><a href="' . esc_url( $log_url ) . '">' . esc_html__( 'View full log for this order', 'xpay-for-woocommerce' ) . '</a></p>';
	}
}
