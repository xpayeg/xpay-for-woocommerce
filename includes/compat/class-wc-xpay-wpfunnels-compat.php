<?php
/**
 * WPFunnels compatibility shim.
 *
 * Background
 * ----------
 * WPFunnels filters `woocommerce_get_checkout_order_received_url` to rewrite
 * the post-payment URL into its own funnel-routing format
 * (`/checkout/?wpfnl-order=N&wpfnl-key=K`). The intent is for WPFunnels Pro's
 * upsell handler to consume those query params and render an upsell page.
 *
 * On WPFunnels Free (no Pro upsell handler installed), `/checkout/` falls
 * through to its empty-cart guard — because our `process_payment` already
 * cleared the cart — and the customer is bounced to `/cart/` instead of
 * landing on a "Thank you for your order" page. The order itself is correctly
 * marked `processing`; only the customer-facing UX is broken.
 *
 * What this shim does
 * -------------------
 * When the gateway setting `wpfunnels_force_standard_redirect` is enabled
 * AND WPFunnels is active AND the URL we're about to use was rewritten by
 * WPFunnels for an XPay order, we restore the standard WooCommerce
 * order-received URL.
 *
 * The setting is OFF by default — merchants on WPFunnels Pro with a real
 * upsell flow want WPFunnels' routing intact. The admin notice nudges
 * everyone else to turn it on.
 */

defined( 'ABSPATH' ) or exit;

final class WC_XPay_WPFunnels_Compat {

	const SETTING_KEY = 'wpfunnels_force_standard_redirect';

	public static function init() {
		// Priority 20 so we run AFTER WPFunnels' filter (default priority 10)
		// has had its turn at the URL.
		add_filter(
			'woocommerce_get_checkout_order_received_url',
			array( __CLASS__, 'maybe_restore_standard_url' ),
			20,
			2
		);
	}

	/**
	 * @param string   $url   URL after WPFunnels (and any other filter) has run
	 * @param WC_Order $order The order being routed
	 * @return string Possibly-restored URL
	 */
	public static function maybe_restore_standard_url( $url, $order ) {
		if ( ! self::is_wpfunnels_active() ) {
			return $url;
		}
		if ( ! ( $order instanceof WC_Order ) ) {
			return $url;
		}
		if ( 'xpay_gateway' !== $order->get_payment_method() ) {
			return $url;
		}
		if ( ! self::setting_enabled() ) {
			return $url;
		}
		if ( ! self::looks_like_wpfunnels_url( $url ) ) {
			// WPFunnels didn't actually rewrite this — nothing to restore.
			// Could happen if the order wasn't placed through a funnel at all.
			return $url;
		}

		// Build the canonical WC order-received URL by hand. We can't just
		// call $order->get_checkout_order_received_url() because we'd recurse
		// into our own filter. wc_get_endpoint_url() is the underlying
		// builder WC uses internally and skips the high-level filter chain.
		$standard = add_query_arg(
			'key',
			$order->get_order_key(),
			wc_get_endpoint_url( 'order-received', $order->get_id(), wc_get_checkout_url() )
		);

		// Surface the override in the log so a support engineer can see why
		// the customer landed where they did.
		do_action( 'xpay_logger_event', 'wpfunnels.url_override', array(
			'order_id'         => $order->get_id(),
			'wpfunnels_url'    => $url,
			'restored_url'     => $standard,
			'wpfunnels_funnel' => (int) $order->get_meta( '_wpfunnels_funnel_id' ),
		), 'restored standard order-received URL (compat setting on)' );

		return $standard;
	}

	private static function setting_enabled() {
		$settings = get_option( 'woocommerce_xpay_gateway_settings', array() );
		return ! empty( $settings[ self::SETTING_KEY ] ) && 'yes' === $settings[ self::SETTING_KEY ];
	}

	/**
	 * Detect WPFunnels by class name. Cheaper and more reliable than scanning
	 * active_plugins because it survives plugin folder renames.
	 */
	public static function is_wpfunnels_active() {
		return class_exists( '\WPFunnels\Wpfnl' ) || function_exists( 'wpfnl' );
	}

	/**
	 * The signature of a WPFunnels-rewritten order-received URL is the
	 * `wpfnl-order` query param. We match on the param presence rather than
	 * the path, because WPFunnels can route through any page the merchant
	 * configured as the funnel checkout (not always /checkout/).
	 */
	private static function looks_like_wpfunnels_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! $query ) {
			return false;
		}
		parse_str( $query, $args );
		return isset( $args['wpfnl-order'] );
	}
}
