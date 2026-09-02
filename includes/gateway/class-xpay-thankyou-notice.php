<?php
/**
 * XPay_Thankyou_Notice
 *
 * Payment-state information on the order-received page, and nothing else.
 * WooCommerce's own page renders untouched: its "thank you" sentence, its
 * order-details table, its styling. What this adds is the one thing core
 * cannot know, the state of the MONEY, and only when that state needs
 * saying. Stripe's plugin holds the same line: nothing for an ordinary
 * paid order, an additive instructions block for its pay-later method
 * (Multibanco), core's own markup throughout.
 *
 * Truth source: XPay_Order_Sync re-verifies the session on the same hook
 * one priority earlier, so by the time this renders, the order's status is
 * the payment's truth.
 *
 * Three cases, all in WooCommerce's own notice markup, no stylesheet:
 *   - Paid: nothing. Core's page already says everything true.
 *   - Awaiting a payment reference (a Fawry code, on-hold with the
 *     awaiting marker): what the shopper holds and what happens next.
 *   - Pending: the payment is still being confirmed, and, while the order
 *     can still be paid, the way back in. The checkout sends shoppers
 *     here whenever it cannot see what happened, and refusing to guess
 *     must not mean leaving them with no way to pay.
 *
 * Everything else (failed, cancelled, an on-hold park with money behind
 * it) renders nothing: WooCommerce keeps its own words, and the admin
 * flows own those conversations.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Thankyou_Notice {

	/**
	 * Render the payment-state notice. Hooked on woocommerce_before_thankyou
	 * at priority 20 — AFTER XPay_Order_Sync::verify_on_thankyou (10), so
	 * the checks below read post-verification truth. Fires on both the
	 * classic thankyou.php template and the block-based Order Confirmation
	 * (its Status block replays this hook's output).
	 *
	 * @param int $order_id Order being viewed.
	 */
	public static function render( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) ) {
			return;
		}

		if ( $order->is_paid() ) {
			return;
		}

		/*
		 * A completed checkout holding a payment reference: the session is
		 * done, the money is not. The marker is the same fact the webhook
		 * flow wrote when it parked the order, so the page and the order
		 * screen can never tell this state apart differently.
		 */
		if ( $order->has_status( 'on-hold' ) && '' !== (string) $order->get_meta( XPay_Constants::META_AWAITING_PAYMENT ) ) {
			self::notice( __( 'Your order is reserved and waiting for your payment reference to be paid, for example at a Fawry point or in the Fawry app. We confirm automatically the moment it is paid and email you then. Nothing ships before that confirmation.', 'xpay-for-woocommerce' ) );
			return;
		}

		if ( ! $order->has_status( 'pending' ) ) {
			return;
		}

		$message = __( 'Your payment is still being confirmed. Usually under a minute. We will email you the moment it is confirmed.', 'xpay-for-woocommerce' );

		/*
		 * The way out of a pending order. The checkout sends a shopper here
		 * whenever it cannot see what happened to their payment, and a page
		 * that only promises an email is a lie when nothing was ever
		 * charged. The link is safe because it is deliberate rather than
		 * blind: once the webhook marks the order paid, needs_payment() is
		 * false, WooCommerce's own order-pay endpoint turns the link away,
		 * and this stops offering it.
		 */
		if ( $order->needs_payment() ) {
			$message .= ' <a href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html__( 'Pay for this order', 'xpay-for-woocommerce' ) . '</a>';
		}

		self::notice( $message );
	}

	/**
	 * One notice in WooCommerce's own markup, so every theme styles it the
	 * way it styles core's notices and this plugin ships no CSS for it.
	 *
	 * @param string $message Notice content; contains only markup this class built.
	 */
	private static function notice( string $message ): void {
		echo '<div class="woocommerce-info xpay-payment-notice">' . wp_kses(
			$message,
			array( 'a' => array( 'href' => true ) )
		) . '</div>';
	}
}
