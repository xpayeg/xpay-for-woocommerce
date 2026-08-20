<?php
/**
 * XPay_Checkout_Elements
 *
 * Puts XPay's payment fields on the store's own checkout page, and keeps
 * the session behind them in step with a cart that is still moving.
 *
 * This is the checkout page only. The pay page keeps its drop-in window,
 * because there the order and its total are already final and none of the
 * machinery below is needed. Everything here exists to answer one problem:
 * the payment fields have to be mounted while the shopper is still choosing
 * shipping and typing coupons, so the session must exist before the order
 * does and its amount must follow the cart.
 *
 * THREE THINGS CROSS BETWEEN THE PAGE AND THE SERVER
 *
 * The browser cannot be trusted with any of them, so each has its own
 * endpoint and each re-derives its answer server-side:
 *
 *   sync   — the cart total changed; bring the session in line. Refused
 *            while a payment is running (XPay_Cart_Session holds that lock).
 *   paying — a payment is starting. Takes the lock.
 *   paid   — the payment ended, however it ended. Releases the lock.
 *
 * The amount is never taken from the request. It is recomputed from the
 * cart on every call, because a browser that can name its own price is a
 * browser that can pay one pound for a television.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Checkout_Elements {

	/** Nonce action shared by all three endpoints. */
	const NONCE_ACTION = 'xpay_checkout_elements';

	/** Script handle for the mount module. Protected by XPay_Script_Guard. */
	const HANDLE = 'xpay-elements';

	/** Script handle for the appearance detector. */
	const APPEARANCE_HANDLE = 'xpay-appearance';

	/** Script handle for the page driver that mounts and pays. */
	const DRIVER_HANDLE = 'xpay-checkout-driver';

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		foreach ( array( 'session', 'sync', 'paying', 'paid' ) as $action ) {
			add_action( 'wp_ajax_xpay_elements_' . $action, array( __CLASS__, 'handle_' . $action ) );
			add_action( 'wp_ajax_nopriv_xpay_elements_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}

		// A placed order must not leave its session behind for the next cart
		// to inherit: the next shopper in this browser starts clean.
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'forget_cart_session' ) );
	}

	/* ── Assets ──────────────────────────────────────────────────────── */

	/**
	 * Load the mount module on the checkout page only.
	 *
	 * Not on the pay page, which still opens the window, and not on the
	 * order-received page, which has nothing to pay.
	 */
	public static function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$gateway = self::gateway();
		if ( null === $gateway || ! $gateway->is_available() ) {
			return;
		}

		wp_enqueue_script(
			self::APPEARANCE_HANDLE,
			XPAY_WC_PLUGIN_URL . 'assets/js/checkout-appearance.js',
			array(),
			XPay_Constants::asset_version( 'assets/js/checkout-appearance.js' ),
			true
		);

		wp_enqueue_script(
			self::HANDLE,
			XPAY_WC_PLUGIN_URL . 'assets/js/checkout-elements.js',
			array( self::APPEARANCE_HANDLE ),
			XPay_Constants::asset_version( 'assets/js/checkout-elements.js' ),
			true
		);

		wp_enqueue_script(
			self::DRIVER_HANDLE,
			XPAY_WC_PLUGIN_URL . 'assets/js/checkout-driver.js',
			array( self::HANDLE ),
			XPay_Constants::asset_version( 'assets/js/checkout-driver.js' ),
			true
		);

		wp_localize_script(
			self::DRIVER_HANDLE,
			'xpayElementsParams',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'publishableKey' => $gateway->publishable_key(),
				'sdkUrl'         => XPay_Constants::sdk_url(),
				'colorMode'      => self::color_mode( $gateway ),
				'gatewayId'      => XPay_Constants::GATEWAY_ID,
				'bnplPhone'      => self::bnpl_copy(),
				'i18n'           => array(
					'unavailable'  => __( 'Payment is unavailable right now. Please try again in a moment.', 'xpay-for-woocommerce' ),
					'totalChanged' => __( 'Your order total changed. Check the new total and try again.', 'xpay-for-woocommerce' ),
					'notCompleted' => __( 'Payment was not completed. Your order is saved, and you can try again.', 'xpay-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * The theme the payment fields should follow.
	 *
	 * "auto" means follow the shopper's own device, which is the honest
	 * default: a store cannot reliably tell us whether it is currently
	 * showing a dark page, but the shopper's browser can say what they
	 * prefer. The merchant can overrule it either way.
	 *
	 * @param XPay_Gateway $gateway Configured gateway.
	 */
	private static function color_mode( XPay_Gateway $gateway ): string {
		$mode = (string) $gateway->get_option( 'color_mode', 'auto' );
		return in_array( $mode, array( 'light', 'dark' ), true ) ? $mode : 'system';
	}

	/**
	 * The container XPay's fields mount into, plus the valU number prompt
	 * that may appear beneath them.
	 *
	 * Rendered for the classic checkout by the gateway's payment_fields().
	 * Blocks builds the same two nodes in JavaScript, because Blocks renders
	 * its rows in the browser.
	 *
	 * The prompt is markup only here. Whether it is shown is decided at
	 * runtime by which method the shopper picks inside XPay's accordion, so
	 * it starts hidden and the mount reveals it.
	 */
	public static function render_mount(): void {
		$gateway = self::gateway();
		if ( null === $gateway ) {
			return;
		}

		$description = (string) $gateway->get_option( 'description', '' );
		if ( '' !== $description ) {
			echo '<p class="xpay-el__description">' . esc_html( $description ) . '</p>';
		}

		echo '<div class="xpay-el" data-xpay-elements>';
		echo '<div class="xpay-el__mount" id="xpay-elements-mount"></div>';
		echo '<div class="xpay-el__notice" data-xpay-elements-error hidden role="alert"></div>';

		echo '<div class="xpay-el__bnpl" data-xpay-bnpl-phone hidden>';
		echo '<label class="xpay-el__label" for="xpay-bnpl-phone">' . esc_html__( 'Mobile number registered with valU', 'xpay-for-woocommerce' ) . '</label>';
		echo '<input type="tel" inputmode="tel" autocomplete="tel" class="xpay-el__input" id="xpay-bnpl-phone" name="xpay_bnpl_phone" value="" data-xpay-bnpl-input>';
		echo '<p class="xpay-el__hint" data-xpay-bnpl-hint></p>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Copy and context for the valU number prompt.
	 *
	 * The list of methods that need a number is a server fact, published
	 * rather than inferred: the page must not decide which methods charge a
	 * registered mobile by reading names it happens to recognise.
	 *
	 * The placeholder follows the shopper's own country, so a Jordanian
	 * shopper is shown a Jordanian number rather than an Egyptian one they
	 * would have to mentally translate.
	 */
	private static function bnpl_copy(): array {
		$country = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$country = (string) WC()->customer->get_billing_country();
		}

		return array(
			'methods'     => XPay_Bnpl_Phone::METHODS,
			'prefill'     => self::billing_phone(),
			'placeholder' => XPay_Bnpl_Phone::example_for( $country ),
			'label'       => __( 'Mobile number registered with valU', 'xpay-for-woocommerce' ),
			'whyKnown'    => __( 'valU charges the mobile number registered with it, and the number on this order is not an Egyptian or Jordanian mobile. Enter the one your valU account uses.', 'xpay-for-woocommerce' ),
			'whyMissing'  => __( 'valU charges the mobile number registered with it. Enter the Egyptian or Jordanian mobile your valU account uses.', 'xpay-for-woocommerce' ),
		);
	}

	/** The billing phone the shopper already gave, if any. */
	private static function billing_phone(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}
		return (string) WC()->customer->get_billing_phone();
	}

	/* ── Endpoints ───────────────────────────────────────────────────── */

	/**
	 * Bring the session's amount in line with the cart.
	 *
	 * The amount is recomputed here from the cart, never read from the
	 * request. The browser is only telling us that something changed.
	 */
	public static function handle_sync(): void {
		self::verify();

		$cart_session = self::cart_session();
		if ( null === $cart_session ) {
			wp_send_json_error( array( 'reason' => 'unavailable' ), 503 );
		}

		$total    = self::cart_total_minor();
		$currency = get_woocommerce_currency();

		if ( null === $total ) {
			wp_send_json_error( array( 'reason' => 'no-cart' ), 409 );
		}

		try {
			$outcome = $cart_session->sync_amount( $total, $currency );
		} catch ( XPay_Api_Exception $e ) {
			XPay_Logger::event( 'elements.sync_failed', array( 'error' => $e->getMessage() ) );
			wp_send_json_error( array( 'reason' => 'api' ), 502 );
		}

		// A refusal is not an error. The shopper is mid-payment and the
		// amount they agreed to is the one that must stand, so the page is
		// told plainly rather than shown a failure it cannot act on.
		wp_send_json_success(
			array(
				'outcome' => $outcome,
				'amount'  => $cart_session->known_amount(),
			)
		);
	}

	/**
	 * The session the page mounts against, created on first ask.
	 *
	 * The browser asks for this once the checkout form is on screen. It
	 * carries no amount: the total is read from the cart here, so a page
	 * that lies about the price gets the real one anyway.
	 */
	public static function handle_session(): void {
		self::verify();

		$cart_session = self::cart_session();
		if ( null === $cart_session ) {
			wp_send_json_error( array( 'reason' => 'unavailable' ), 503 );
		}

		$total = self::cart_total_minor();
		if ( null === $total ) {
			wp_send_json_error( array( 'reason' => 'no-cart' ), 409 );
		}

		try {
			$session = $cart_session->ensure( $total, get_woocommerce_currency(), self::return_url() );
		} catch ( XPay_Api_Exception $e ) {
			XPay_Logger::event( 'elements.session_failed', array( 'error' => $e->getMessage() ) );
			wp_send_json_error( array( 'reason' => 'api' ), 502 );
		}

		if ( null === $session ) {
			wp_send_json_error( array( 'reason' => 'no-session' ), 502 );
		}

		wp_send_json_success(
			array(
				'clientSecret' => $session['clientSecret'],
				'amount'       => $total,
			)
		);
	}

	/**
	 * Where a shopper lands after a payment that had to leave the page.
	 *
	 * Elements navigates only when the method genuinely needs it, so most
	 * shoppers never see this. It is required at creation all the same.
	 */
	private static function return_url(): string {
		return wc_get_checkout_url();
	}

	/** Take the payment lock. Amount changes are refused from here. */
	public static function handle_paying(): void {
		self::verify();
		$cart_session = self::cart_session();
		if ( null === $cart_session ) {
			wp_send_json_error( array( 'reason' => 'unavailable' ), 503 );
		}

		// Last word before the shopper pays: if the cart has drifted since
		// the session was last told, the fields on screen are showing a
		// number the session does not hold. Refuse rather than let them pay
		// it, and let the page resync and ask again.
		$total = self::cart_total_minor();
		$known = $cart_session->known_amount();
		if ( null !== $total && null !== $known && $total !== $known ) {
			XPay_Logger::event(
				'elements.stale_amount_at_pay',
				array(
					'cart'    => $total,
					'session' => $known,
				)
			);
			wp_send_json_error(
				array(
					'reason' => 'stale-amount',
					'cart'   => $total,
				),
				409
			);
		}

		$cart_session->payment_started();
		wp_send_json_success( array( 'locked' => true ) );
	}

	/** Release the payment lock, however the payment ended. */
	public static function handle_paid(): void {
		self::verify();
		$cart_session = self::cart_session();
		if ( null !== $cart_session ) {
			$cart_session->payment_finished();
		}
		wp_send_json_success( array( 'locked' => false ) );
	}

	/**
	 * Drop the cart's session once an order exists.
	 *
	 * @param mixed $order_id Order that was just placed.
	 */
	public static function forget_cart_session( $order_id = 0 ): void {
		unset( $order_id );
		$cart_session = self::cart_session();
		if ( null !== $cart_session ) {
			$cart_session->forget();
		}
	}

	/* ── Internals ───────────────────────────────────────────────────── */

	/**
	 * Reject anything that is not a genuine request from our own checkout.
	 *
	 * These endpoints move money-shaped state, so a failed check ends the
	 * request rather than falling through to a default.
	 */
	private static function verify(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'reason' => 'bad-nonce' ), 403 );
		}
	}

	/**
	 * The cart total in minor units, or null when there is no cart.
	 *
	 * Read through XPay_Money so the same string-based conversion that
	 * protects order totals protects this one: a float multiplication here
	 * would lose a piaster on exactly the amounts that end in .005.
	 */
	private static function cart_total_minor(): ?int {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return null;
		}
		$total = WC()->cart->get_total( 'edit' );
		if ( '' === (string) $total ) {
			return null;
		}
		try {
			return XPay_Money::to_minor( (string) $total, get_woocommerce_currency() );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	/** The cart session service, or null when the gateway is not usable. */
	private static function cart_session(): ?XPay_Cart_Session {
		$gateway = self::gateway();
		if ( null === $gateway || $gateway->needs_setup() ) {
			return null;
		}
		return new XPay_Cart_Session( $gateway->api_client() );
	}

	/** The configured XPay gateway, or null when WooCommerce has none. */
	private static function gateway(): ?XPay_Gateway {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return null;
		}
		$gateways = WC()->payment_gateways->payment_gateways();
		$gateway  = $gateways[ XPay_Constants::GATEWAY_ID ] ?? null;
		return $gateway instanceof XPay_Gateway ? $gateway : null;
	}
}
