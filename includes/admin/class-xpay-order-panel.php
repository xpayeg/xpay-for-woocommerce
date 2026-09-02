<?php
/**
 * XPay_Order_Panel
 *
 * The XPay meta box on the order edit screen: this order's XPay
 * identifiers (verbatim, copyable, support traceability), plus the
 * refund-currency note above the refund controls. Registered for both the
 * HPOS orders screen and the legacy post-based screen. The payment's
 * story lives in the order notes; the diagnostic log lives in
 * WooCommerce → Status → Logs (source "xpay").
 *
 * Everything here is server-rendered from stored order meta. WooCommerce's
 * refunded/remaining ledger and XPay's refund response are authoritative.
 *
 * Read-only surface behind manage_woocommerce; everything shown was
 * redacted at write time.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Order_Panel {

	/**
	 * The order edit screen's id under whichever order storage is active.
	 */
	private static function screen_id(): string {
		return class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order';
	}

	public static function register(): void {
		// The box exposes payment identifiers — manage_woocommerce only,
		// enforced at registration so the box never appears for lesser
		// roles with order-screen access.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		add_meta_box(
			'xpay-order-panel',
			__( 'XPay', 'xpay-for-woocommerce' ),
			array( __CLASS__, 'render' ),
			self::screen_id(),
			'side',
			'default'
		);
	}

	/**
	 * A line directly above the refund controls, for orders where WooCommerce
	 * cannot take the whole job.
	 *
	 * Rendered here rather than in the XPay box in the sidebar on purpose:
	 * the merchant's failure mode is to open the refund panel, type a part
	 * amount and press the button. This sits where their eye already is at
	 * that moment. Server-rendered from the order's own currency, so it
	 * needs no request and is there before anything loads.
	 *
	 * @param int $order_id Order being viewed.
	 */
	public static function render_refund_currency_note( $order_id ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) ) {
			return;
		}
		if ( XPay_Constants::SETTLEMENT_CURRENCY === strtoupper( (string) $order->get_currency() ) ) {
			return;
		}
		if ( '' === (string) $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ) ) {
			return;
		}

		echo '<tr><td class="label" colspan="2" style="padding-top:10px">';
		echo '<div style="background:#fcf9e8;border-left:3px solid #dba617;padding:8px 10px;font-weight:400;line-height:1.5">';
		echo esc_html(
			sprintf(
				/* translators: 1: the order's currency code, e.g. USD. 2: the currency XPay settles in, e.g. EGP. */
				__( 'This order is priced in %1$s and XPay settles in %2$s. You can refund it in full from here. For part of it, use your XPay dashboard: the refund is recorded on this order automatically, usually within a minute.', 'xpay-for-woocommerce' ),
				strtoupper( (string) $order->get_currency() ),
				XPay_Constants::SETTLEMENT_CURRENCY
			)
		);
		echo '</div></td></tr>';
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order Screen object (HPOS passes the order).
	 */
	public static function render( $post_or_order ): void {
		// Belt and braces: register() already gates on this, but a meta box
		// callback must never rely on registration-time state alone.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof WC_Order || ! XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) ) {
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
	}
}
