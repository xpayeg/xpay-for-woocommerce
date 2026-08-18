<?php
/**
 * XPay_Thankyou_Notice
 *
 * The order-received page's payment confirmation: the pay page's receipt
 * returns, stamped with the payment's true state. WooCommerce's own copy
 * here is deliberately status-blind ("your order has been received"), and
 * its order-overview bullets repeat what the receipt already says — so
 * when the receipt renders, both are suppressed (the text via the filter
 * below, the bullets via a sibling-scoped CSS rule) and the receipt
 * carries the whole conversation.
 *
 * Truth source: XPay_Order_Sync re-verifies the session on the same hook
 * one priority earlier, so by the time this renders, is_paid() is the
 * truth — and the page can say it.
 *
 * Two honest states, nothing invented:
 *   - Paid (processing/completed): the receipt stamped PAID in green.
 *   - Pending: stamped CONFIRMING PAYMENT in the brand color, with one
 *     mono note — the shopper can safely leave, email carries it home.
 *
 * Everything else (failed, cancelled, on-hold mismatch) renders nothing
 * and suppresses nothing: WooCommerce keeps its own words, and the admin
 * flows own those conversations.
 *
 * The receipt signs off with "Secured by" + the XPay wordmark, centered —
 * no method badges here by design: the payment is done, so the trust row
 * is a signature, not a menu.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Thankyou_Notice {

	/**
	 * Enqueue the receipt stylesheet on the order-received page for XPay
	 * orders. Hooked on wp_enqueue_scripts — deliberately status-blind:
	 * verify_on_thankyou can flip a pending order to paid AFTER the head
	 * is printed, so the style must already be there. Loading it when the
	 * receipt ends up not rendering is harmless — every rule is scoped
	 * under .xpay-ty, and the suppression rules use the `.xpay-ty ~`
	 * sibling scope, so nothing hides unless the receipt exists.
	 */
	public static function enqueue(): void {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}
		global $wp;
		$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || ! XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) ) {
			return;
		}
		wp_enqueue_style(
			'xpay-thankyou',
			XPAY_WC_PLUGIN_URL . 'assets/css/thankyou.css',
			array(),
			XPay_Constants::asset_version( 'assets/css/thankyou.css' )
		);
	}

	/**
	 * Blank WooCommerce's "Thank you. Your order has been received." line
	 * exactly when the receipt renders — the receipt says it better, and
	 * with a real status. Failed/cancelled orders keep WooCommerce's words:
	 * a page with no receipt must never lose its only copy.
	 *
	 * @param string        $text  WooCommerce's received text.
	 * @param WC_Order|null $order Order being viewed (null on malformed calls).
	 * @return string
	 */
	public static function filter_received_text( $text, $order ) {
		if ( $order instanceof WC_Order
			&& XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() )
			&& ( $order->is_paid() || $order->has_status( 'pending' ) ) ) {
			return '';
		}
		return $text;
	}

	/**
	 * Render the stamped receipt. Hooked on woocommerce_before_thankyou at
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

		$paid = $order->is_paid();
		if ( ! $paid && ! $order->has_status( 'pending' ) ) {
			return;
		}

		// The same per-merchant stage the pay page paints — one synced
		// primary, one gradient formula, so the two pages can never drift.
		$stage = XPay_Branding::stage_from_primary( (string) get_option( XPay_Constants::OPTION_BRAND_PRIMARY, '' ) );
		$style = sprintf( '--xpay-brand-from:%s;--xpay-brand-to:%s', $stage['from'], $stage['to'] );

		echo '<div class="xpay-ty ' . ( $paid ? 'xpay-ty--paid' : 'xpay-ty--pending' ) . '" role="status" style="' . esc_attr( $style ) . '">';
		echo '<div class="xpay-ty__card">';

		self::render_head( $order );
		self::render_lines( $order );
		self::render_stamp( $paid );
		if ( ! $paid ) {
			echo '<p class="xpay-ty__note">' . esc_html__( 'Usually under a minute. We will email you the moment it is confirmed.', 'xpay-for-woocommerce' ) . '</p>';
		}
		self::render_trust();

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Store identity leads the receipt — the same hierarchy as the pay
	 * page: site logo when the theme has one, name regardless, then the
	 * order line.
	 *
	 * @param WC_Order $order Order being viewed.
	 */
	private static function render_head( WC_Order $order ): void {
		echo '<div class="xpay-ty__head">';

		$logo_id  = (int) get_theme_mod( 'custom_logo' );
		$logo_url = $logo_id > 0 ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		if ( '' !== $logo_url ) {
			echo '<img class="xpay-ty__store-logo" src="' . esc_url( $logo_url ) . '" alt="">';
		}
		echo '<div class="xpay-ty__store">' . esc_html( get_bloginfo( 'name' ) ) . '</div>';

		$count = $order->get_item_count();
		/* translators: %d is the number of items in the order. */
		$items = sprintf( _n( '%d item', '%d items', $count, 'xpay-for-woocommerce' ), $count );
		/* translators: 1: order number, 2: item count ("3 items"). */
		echo '<div class="xpay-ty__order-line">' . esc_html( sprintf( __( 'Order #%1$s · %2$s', 'xpay-for-woocommerce' ), $order->get_order_number(), $items ) ) . '</div>';

		echo '</div>';
	}

	/**
	 * Item lines, WooCommerce's own totals rows, then the total — the pay
	 * page's exact recipe (get_order_item_totals, never re-summed), so the
	 * receipt the shopper paid on and the receipt that confirms it can
	 * never disagree by a piaster.
	 *
	 * @param WC_Order $order Order being viewed.
	 */
	private static function render_lines( WC_Order $order ): void {
		$currency = array( 'currency' => $order->get_currency() );

		echo '<div class="xpay-ty__lines">';
		foreach ( $order->get_items() as $item ) {
			$qty  = (int) $item->get_quantity();
			$name = $item->get_name();
			if ( $qty > 1 ) {
				/* translators: 1: product name, 2: quantity. */
				$name = sprintf( __( '%1$s × %2$d', 'xpay-for-woocommerce' ), $name, $qty );
			}
			echo '<div class="xpay-ty__line"><span class="xpay-ty__line-label">' . esc_html( $name ) . '</span><span class="xpay-ty__line-amount">' . wp_kses_post( wc_price( $order->get_line_total( $item, true, true ), $currency ) ) . '</span></div>';
		}

		// cart_subtotal is redundant once the items are itemized above;
		// payment_method is the signature row; order_total gets TOTAL below.
		$skip = array( 'cart_subtotal', 'payment_method', 'order_total' );
		foreach ( $order->get_order_item_totals() as $key => $row ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			$label = rtrim( wp_strip_all_tags( (string) $row['label'] ), ':' );
			echo '<div class="xpay-ty__line"><span class="xpay-ty__line-label">' . esc_html( $label ) . '</span><span class="xpay-ty__line-amount">' . wp_kses_post( (string) $row['value'] ) . '</span></div>';
		}

		echo '<div class="xpay-ty__total"><span>' . esc_html__( 'Total', 'xpay-for-woocommerce' ) . '</span><span>' . wp_kses_post( $order->get_formatted_order_total() ) . '</span></div>';
		echo '</div>';
	}

	/**
	 * The stamp: green PAID with a check, or brand-colored CONFIRMING
	 * PAYMENT — the pay page's "Awaiting payment" stamp anatomy, resolved.
	 *
	 * @param bool $paid Whether the order is paid.
	 */
	private static function render_stamp( bool $paid ): void {
		echo '<div class="xpay-ty__stamp">';
		if ( $paid ) {
			echo '<svg class="xpay-ty__stamp-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
			echo '<span>' . esc_html__( 'Paid', 'xpay-for-woocommerce' ) . '</span>';
		} else {
			echo '<span>' . esc_html__( 'Confirming payment', 'xpay-for-woocommerce' ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Centered signature: "Secured by" + the real XPay wordmark. No method
	 * badges — the payment is complete, so this row signs, it doesn't sell.
	 */
	private static function render_trust(): void {
		echo '<div class="xpay-ty__trust">';
		echo '<svg class="xpay-ty__lock" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="3" y="7" width="10" height="7" rx="1.5" stroke="currentColor" stroke-width="1.5"></rect><path d="M5.5 7V5.5a2.5 2.5 0 0 1 5 0V7" stroke="currentColor" stroke-width="1.5"></path></svg>';
		echo '<span>' . esc_html__( 'Secured by', 'xpay-for-woocommerce' ) . '</span>';
		echo '<img class="xpay-ty__wordmark" src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/xpay-wordmark.svg' ) . '" alt="XPay">';
		echo '</div>';
	}
}
