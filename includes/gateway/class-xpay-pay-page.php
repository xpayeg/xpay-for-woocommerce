<?php
/**
 * XPay_Pay_Page
 *
 * Renders the order-pay page body: the store's receipt floating on the
 * brand-colored stage, with the XPay window opening over it. Pure display —
 * no session or order-state logic lives here.
 *
 * Two states, one markup, toggled by checkout-modal.js via the
 * `xpay-paused` class on the container:
 *   - Opening (default): spinning ring + "Opening secure payment…". The
 *     stamp and Pay now button are hidden — the window opens by itself,
 *     so the happy path shows no controls at all.
 *   - Paused (shopper closed the window): ring stops, the "Awaiting
 *     payment" stamp appears, and the JS reveals the Pay now button.
 *
 * Identity hierarchy mirrors the hosted checkout's own: the STORE leads the
 * receipt (site logo and name — a receipt is issued by the store), XPay
 * signs as "Secured by" with its real wordmark, exactly like the platform's
 * 3DS panel. Line amounts reuse WooCommerce's own formatted totals rows, so
 * the receipt can never disagree with what WooCommerce shows elsewhere.
 *
 * Fonts are the system stacks, not the platform's webfonts: wp.org forbids
 * loading remote assets, and shipping font binaries for one page is weight
 * the shopper pays on every load. The XPay window itself carries the brand
 * fonts.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Pay_Page {

	/**
	 * @param WC_Order   $order        Order being paid.
	 * @param array|null $pinned_types This row's method restriction (trust strip icons), null = all.
	 * @param array      $stage        Gradient stops from XPay_Branding::stage_from_primary().
	 */
	public static function render( WC_Order $order, ?array $pinned_types, array $stage ): void {
		// Custom properties, not classes: the stage and the button must take
		// the merchant's synced primary, and inline style is the only place
		// a per-merchant color can live without emitting a <style> block.
		$style = sprintf( '--xpay-brand-from:%s;--xpay-brand-to:%s', $stage['from'], $stage['to'] );

		echo '<div id="xpay-payment" class="xpay-pay" data-order="' . esc_attr( (string) $order->get_id() ) . '" style="' . esc_attr( $style ) . '">';
		self::render_halo();
		self::render_receipt( $order, $pinned_types );
		echo '</div>';
	}

	/**
	 * The ring (the checkout app's own spinner recipe — 3px stroke, track +
	 * leading arc, 1s linear) scaled up around the XPay mark, with the
	 * status line under it.
	 */
	private static function render_halo(): void {
		echo '<div class="xpay-pay__halo">';
		echo '<div class="xpay-pay__ring">';
		echo '<svg class="xpay-pay__ring-svg" viewBox="0 0 74 74" fill="none" aria-hidden="true"><circle cx="37" cy="37" r="33" stroke="rgba(255,255,255,0.28)" stroke-width="3"></circle><path class="xpay-pay__ring-arc" d="M37 4 A 33 33 0 0 1 70 37" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round"></path></svg>';
		echo '<img class="xpay-pay__mark" src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/xpay-mark.svg' ) . '" alt="">';
		echo '</div>';
		echo '<p id="xpay-payment-status" class="xpay-pay__status" role="status">' . esc_html__( 'Opening secure payment…', 'xpay-for-woocommerce' ) . '</p>';
		echo '</div>';
	}

	/**
	 * @param WC_Order   $order        Order being paid.
	 * @param array|null $pinned_types This row's method restriction, null = all.
	 */
	private static function render_receipt( WC_Order $order, ?array $pinned_types ): void {
		echo '<div class="xpay-pay__card">';

		self::render_head( $order );
		self::render_lines( $order );

		echo '<div class="xpay-pay__stamp">' . esc_html__( 'Awaiting payment', 'xpay-for-woocommerce' ) . '</div>';

		// Hidden until checkout-modal.js reveals it in the paused state —
		// the happy path never shows a control on this page. No hosted-page
		// link here: the SDK-failure path auto-redirects to the hosted
		// checkout, so a visible second path would only split the shopper's
		// attention between two buttons that do the same thing.
		echo '<div class="xpay-pay__actions">';
		echo '<button type="button" class="button alt xpay-pay__button" id="xpay-pay-button" style="display:none">' . esc_html__( 'Pay now', 'xpay-for-woocommerce' ) . '</button>';
		echo '</div>';

		self::render_trust( $pinned_types );

		echo '</div>';
	}

	/**
	 * Store identity leads the receipt — a receipt is issued by the store,
	 * and the hosted checkout applies the same hierarchy (merchant at the
	 * top, XPay as a trust mark). The site logo is used when the theme has
	 * one; the name renders regardless, so the header never depends on it.
	 *
	 * @param WC_Order $order Order being paid.
	 */
	private static function render_head( WC_Order $order ): void {
		echo '<div class="xpay-pay__head">';

		$logo_id  = (int) get_theme_mod( 'custom_logo' );
		$logo_url = $logo_id > 0 ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		if ( '' !== $logo_url ) {
			echo '<img class="xpay-pay__store-logo" src="' . esc_url( $logo_url ) . '" alt="">';
		}
		echo '<div class="xpay-pay__store">' . esc_html( get_bloginfo( 'name' ) ) . '</div>';

		$count = $order->get_item_count();
		/* translators: %d is the number of items in the order. */
		$items = sprintf( _n( '%d item', '%d items', $count, 'xpay-for-woocommerce' ), $count );
		/* translators: 1: order number, 2: item count ("3 items"). */
		echo '<div class="xpay-pay__order-line">' . esc_html( sprintf( __( 'Order #%1$s · %2$s', 'xpay-for-woocommerce' ), $order->get_order_number(), $items ) ) . '</div>';

		echo '</div>';
	}

	/**
	 * Item lines, then WooCommerce's own totals rows (shipping, fees, taxes,
	 * discounts, refunds), then the total. The totals rows come from
	 * get_order_item_totals() rather than re-summing anything here: the
	 * receipt must show exactly what WooCommerce shows, or a piaster of
	 * display drift becomes a "was I overcharged?" ticket.
	 *
	 * @param WC_Order $order Order being paid.
	 */
	private static function render_lines( WC_Order $order ): void {
		$currency = array( 'currency' => $order->get_currency() );

		echo '<div class="xpay-pay__lines">';
		foreach ( $order->get_items() as $item ) {
			$qty  = (int) $item->get_quantity();
			$name = $item->get_name();
			if ( $qty > 1 ) {
				/* translators: 1: product name, 2: quantity. */
				$name = sprintf( __( '%1$s × %2$d', 'xpay-for-woocommerce' ), $name, $qty );
			}
			echo '<div class="xpay-pay__line"><span class="xpay-pay__line-label">' . esc_html( $name ) . '</span><span class="xpay-pay__line-amount">' . wp_kses_post( wc_price( $order->get_line_total( $item, true, true ), $currency ) ) . '</span></div>';
		}

		// cart_subtotal is redundant once the items are itemized above;
		// payment_method is this page; order_total gets the TOTAL row below.
		// Refund rows are skipped and the total renders GROSS
		// (display_refunded=false) because that is what the XPay session
		// charges: get_total() ignores refunds, and the amount guard
		// compares the same number. A partially-refunded order made
		// payable again must show the amount the window will actually
		// take, or the receipt promises a discount the charge won't give.
		$skip = array( 'cart_subtotal', 'payment_method', 'order_total' );
		foreach ( $order->get_order_item_totals() as $key => $row ) {
			if ( in_array( $key, $skip, true ) || 0 === strpos( (string) $key, 'refund_' ) ) {
				continue;
			}
			$label = rtrim( wp_strip_all_tags( (string) $row['label'] ), ':' );
			echo '<div class="xpay-pay__line"><span class="xpay-pay__line-label">' . esc_html( $label ) . '</span><span class="xpay-pay__line-amount">' . wp_kses_post( (string) $row['value'] ) . '</span></div>';
		}

		echo '<div class="xpay-pay__total"><span>' . esc_html__( 'Total', 'xpay-for-woocommerce' ) . '</span><span class="xpay-pay__total-amount">' . wp_kses_post( $order->get_formatted_order_total( '', false ) ) . '</span></div>';
		echo '</div>';
	}

	/**
	 * "Secured by" + the real XPay wordmark — the platform's own 3DS-panel
	 * signature — with the method artwork for this row on the right.
	 *
	 * @param array|null $pinned_types This row's method restriction, null = all.
	 */
	private static function render_trust( ?array $pinned_types ): void {
		echo '<div class="xpay-pay__trust">';

		echo '<span class="xpay-pay__secured">';
		echo '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true" class="xpay-pay__lock"><rect x="3" y="7" width="10" height="7" rx="1.5" stroke="currentColor" stroke-width="1.5"></rect><path d="M5.5 7V5.5a2.5 2.5 0 0 1 5 0V7" stroke="currentColor" stroke-width="1.5"></path></svg>';
		echo '<span>' . esc_html__( 'Secured by', 'xpay-for-woocommerce' ) . '</span>';
		echo '<img class="xpay-pay__wordmark" src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/xpay-wordmark.svg' ) . '" alt="XPay">';
		echo '</span>';

		$types = null === $pinned_types ? array( XPay_Payment_Methods::CARD, XPay_Payment_Methods::VALU ) : $pinned_types;
		$icons = array();
		foreach ( $types as $type ) {
			$url = XPay_Payment_Methods::icon_url( (string) $type );
			if ( '' !== $url ) {
				$icons[] = $url;
			}
		}
		if ( array() !== $icons ) {
			echo '<span class="xpay-pay__methods">';
			foreach ( $icons as $url ) {
				echo '<img class="xpay-pay__method-icon" src="' . esc_url( $url ) . '" alt="">';
			}
			echo '</span>';
		}

		echo '</div>';
	}
}
