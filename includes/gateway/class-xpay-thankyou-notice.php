<?php
/**
 * XPay_Thankyou_Notice
 *
 * The payment-status strip on the order-received page. WooCommerce's own
 * confirmation copy is deliberately status-blind ("your order has been
 * received") because a shopper can land here before the payment settles.
 * This plugin knows better at render time: XPay_Order_Sync re-verifies the
 * session on the same hook one priority earlier, so by the time this
 * renders, is_paid() is the truth — and the page can say it.
 *
 * Two honest states, nothing invented:
 *   - Paid (processing/completed): "Payment confirmed" with the amount.
 *   - Pending: the payment is genuinely still settling — say so, and tell
 *     the shopper they can safely leave (webhooks + email carry it home).
 *
 * Everything else (failed, cancelled, on-hold mismatch) renders nothing:
 * WooCommerce and the admin flows own those conversations, and a wrong
 * word on a payment page is worse than no word.
 *
 * Inline styles, deliberately: the strip must read correctly on any theme
 * without shipping a stylesheet for one element, and fixed light-chip
 * colors keep the text legible on dark themes too.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Thankyou_Notice {

	/**
	 * Render the status strip. Hooked on woocommerce_before_thankyou at
	 * priority 20 — AFTER XPay_Order_Sync::verify_on_thankyou (10), so the
	 * paid check below reads post-verification truth. Fires on both the
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
			self::strip(
				'#14532d',
				'#ecf9f0',
				'#b5e3c4',
				'M20 6 9 17l-5-5',
				sprintf(
					/* translators: %s is the formatted order total (for example "EGP 2,220.00"). */
					__( 'Payment confirmed — %s paid securely via XPay.', 'xpay-for-woocommerce' ),
					wp_strip_all_tags( $order->get_formatted_order_total() )
				)
			);
			return;
		}

		if ( $order->has_status( 'pending' ) ) {
			self::strip(
				'#57534e',
				'#f7f6f4',
				'#e0ddd8',
				'M12 6v6l4 2M12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18Z',
				__( 'We’re confirming your payment with XPay — this usually takes under a minute. You can safely leave this page; we’ll email you as soon as it’s confirmed.', 'xpay-for-woocommerce' )
			);
		}
	}

	/**
	 * One strip: icon + sentence in a tinted chip.
	 *
	 * @param string $color  Text/icon color.
	 * @param string $bg     Chip background.
	 * @param string $border Chip border color.
	 * @param string $path   SVG path data for the 24x24 stroke icon.
	 * @param string $text   Already-translated sentence (plain text).
	 */
	private static function strip( string $color, string $bg, string $border, string $path, string $text ): void {
		$style = sprintf(
			'display:flex;align-items:center;gap:10px;margin:0 0 24px;padding:12px 16px;border:1px solid %s;border-radius:8px;background:%s;color:%s;font-size:15px;line-height:1.5;',
			$border,
			$bg,
			$color
		);
		echo '<div class="xpay-thankyou-status" role="status" style="' . esc_attr( $style ) . '">';
		echo '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true" style="flex:none;width:18px;height:18px"><path d="' . esc_attr( $path ) . '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
		echo '<span>' . esc_html( $text ) . '</span>';
		echo '</div>';
	}
}
