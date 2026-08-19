<?php
/**
 * XPay_WPFunnels_Compat
 *
 * WPFunnels filters woocommerce_get_checkout_order_received_url to rewrite
 * the after-payment URL into its funnel-routing format
 * (?wpfnl-order=N&wpfnl-key=K), expecting WPFunnels Pro's upsell handler to
 * consume it. On WPFunnels Free — or Pro without an upsell step — that URL
 * falls through to an empty-cart guard and the shopper bounces to /cart/
 * with no confirmation, often trying to pay again. The order itself is
 * fine; only the shopper-facing landing breaks. (Inherited verbatim from
 * the v2 plugin's support history; docs/COMPATIBILITY.md documents it.)
 *
 * This plugin feels it twice: the URL rides to the browser as the modal's
 * returnUrl AND into the checkout session as the hosted page's return URL.
 * Filtering at the source repairs both call sites at once.
 *
 * Off by default, deliberately: merchants with a real WPFunnels Pro upsell
 * flow want the funnel routing intact. The admin notice nudges everyone
 * else, and dismissal is per-user in user meta so it survives cache
 * flushes.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_WPFunnels_Compat {

	/** Gateway settings key of the opt-in checkbox. */
	const SETTING_KEY = 'wpfunnels_force_standard_redirect';

	/** Per-user persistent dismissal flag for the admin notice. */
	const DISMISS_META = 'xpay_wpfunnels_notice_dismissed';

	/** Query arg + nonce action of the notice's dismiss link. */
	const DISMISS_ARG = 'xpay-dismiss-wpfunnels';

	public static function register(): void {
		// Priority 20: WPFunnels rewrites at the default 10 — this must see
		// (and judge) the URL AFTER its turn, or there is nothing to restore.
		add_filter( 'woocommerce_get_checkout_order_received_url', array( __CLASS__, 'maybe_restore_standard_url' ), 20, 2 );
	}

	public static function register_admin(): void {
		add_action( 'admin_init', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Restore the standard order-received URL for XPay orders when the
	 * safeguard is on and WPFunnels actually rewrote this URL. Everything
	 * else — non-XPay orders, funnel-less orders, setting off — passes
	 * through untouched, so WPFunnels' own routing stays intact where the
	 * merchant wants it.
	 *
	 * @param string $url   URL after every other filter (WPFunnels included) has run.
	 * @param mixed  $order Order being routed (WC_Order on real calls).
	 * @return string
	 */
	public static function maybe_restore_standard_url( $url, $order ) {
		if ( ! self::is_wpfunnels_active() || ! $order instanceof WC_Order ) {
			return $url;
		}
		if ( ! XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) ) {
			return $url;
		}
		if ( ! self::setting_enabled() || ! self::looks_like_wpfunnels_url( $url ) ) {
			return $url;
		}

		// wc_get_endpoint_url is the builder WooCommerce itself uses under
		// this filter — calling $order->get_checkout_order_received_url()
		// here would recurse straight back into this method.
		$standard = add_query_arg(
			'key',
			$order->get_order_key(),
			wc_get_endpoint_url( 'order-received', $order->get_id(), wc_get_checkout_url() )
		);

		XPay_Logger::event(
			'compat.wpfunnels_url_restored',
			array( 'order_id' => $order->get_id() )
		);

		return $standard;
	}

	/**
	 * Nudge shown while WPFunnels is active and the safeguard is off —
	 * the /cart/ bounce is invisible in admin, so without this the
	 * merchant learns about it from confused shoppers.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::is_wpfunnels_active() || self::setting_enabled() ) {
			return;
		}
		if ( '1' === (string) get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . XPay_Constants::GATEWAY_ID );
		$dismiss_url  = wp_nonce_url( add_query_arg( self::DISMISS_ARG, '1' ), self::DISMISS_ARG );

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'XPay: WPFunnels is active. Unless you run a WPFunnels Pro upsell flow, shoppers who pay with XPay can land on the cart page instead of the order confirmation. Turn on the WPFunnels safeguard in the XPay settings.', 'xpay-for-woocommerce' );
		echo '</p><p>';
		echo '<a class="button button-primary" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open XPay settings', 'xpay-for-woocommerce' ) . '</a> ';
		echo '<a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss: I run a WPFunnels Pro upsell flow', 'xpay-for-woocommerce' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Persist the notice dismissal for this user. User meta, not a
	 * transient: a dismissal must survive cache flushes and upgrades.
	 */
	public static function handle_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_ARG ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, '1' );
		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Detect WPFunnels by class/function, not the active-plugins list —
	 * survives plugin folder renames (a v2 support lesson).
	 */
	public static function is_wpfunnels_active(): bool {
		return class_exists( '\WPFunnels\Wpfnl' ) || function_exists( 'wpfnl' );
	}

	private static function setting_enabled(): bool {
		$settings = get_option( 'woocommerce_' . XPay_Constants::GATEWAY_ID . '_settings', array() );
		return is_array( $settings ) && isset( $settings[ self::SETTING_KEY ] ) && 'yes' === $settings[ self::SETTING_KEY ];
	}

	/**
	 * A WPFunnels-rewritten URL is fingerprinted by its wpfnl-order query
	 * param — never by path, because the funnel checkout can live on any
	 * page the merchant configured.
	 *
	 * @param mixed $url Candidate URL.
	 */
	private static function looks_like_wpfunnels_url( $url ): bool {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return false;
		}
		parse_str( $query, $args );
		return isset( $args['wpfnl-order'] );
	}
}
