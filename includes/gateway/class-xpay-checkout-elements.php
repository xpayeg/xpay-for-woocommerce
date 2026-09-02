<?php
/**
 * XPay_Checkout_Elements
 *
 * Serves the assets and the two server endpoints behind XPay's payment
 * fields, on the checkout page and the order-pay page alike.
 *
 * The fields are deferred: they mount from an amount and currency. The
 * session is created only after the order exists.
 *
 * WHAT CROSSES BETWEEN THE PAGE AND THE SERVER
 *
 *   outcome       — the browser could not read how a payment ended. Ask
 *                   the platform rather than guessing, because guessing
 *                   wrong either charges twice or strands a paid shopper.
 *   order_session — the order-pay page's Pay click: get-or-create THIS
 *                   order's session (one per order: reuse, reprice,
 *                   supersede) and hand back its clientSecret, or say the
 *                   order is already paid.
 *
 * Both are order-key authenticated. The nonce only proves the request
 * came from a page carrying one, and every visitor has that; the key is
 * WooCommerce's own proof of access to an order for someone with no
 * account, which is exactly who pays here.
 *
 * The amount is never taken from the request. The checkout reads it from
 * the cart, the order-pay page from the order, and the platform enforces
 * charge = display at confirm — a browser that can name its own price is
 * a browser that can pay one pound for a television.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Checkout_Elements {

	/**
	 * Nonce action shared by both endpoints above.
	 *
	 * The `woocommerce` prefix is load-bearing, not decoration. WooCommerce
	 * binds a logged-out shopper's nonce to their own session id only when
	 * the action string starts with it (`class-wc-session-handler.php:627`);
	 * without the prefix every guest in the shop shares one nonce.
	 */
	const NONCE_ACTION = 'woocommerce_xpay_checkout_elements';

	/** Script handle for the mount module. */
	const HANDLE = 'xpay-elements';

	/** Script handle for the page driver that mounts and pays. */
	const DRIVER_HANDLE = 'xpay-checkout-driver';

	/** Style handle carrying the row-icon rule. */
	const STYLE_HANDLE = 'xpay-checkout';

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		/*
		 * Two endpoints, and the number is the story. There were nine: the
		 * other seven (session, sync, applied, restart, replace, fees,
		 * paying, paid) existed to manage a session created BEFORE its
		 * order — minting it when the shopper picked XPay, walking its
		 * amount after every cart change, locking it while a payment ran,
		 * trading it in when it died underneath them. The session is
		 * created at Pay now, after the order, so the choreography has
		 * nothing left to choreograph. What survives is what was never
		 * about the cart: the outcome verdict, and the order-pay session.
		 */
		foreach ( array( 'outcome', 'order_session' ) as $action ) {
			add_action( 'wp_ajax_xpay_elements_' . $action, array( __CLASS__, 'handle_' . $action ) );
			add_action( 'wp_ajax_nopriv_xpay_elements_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/* ── Assets ──────────────────────────────────────────────────────── */

	/**
	 * REGISTER the checkout scripts. Registration only, never enqueue.
	 *
	 * Split out because the Blocks bundle declares `xpay-elements` as a
	 * dependency, and WooCommerce Blocks resolves that dependency while it
	 * builds its own asset registry — which happens on every page, not only
	 * the checkout. When the handle was registered inside enqueue() alone,
	 * behind an is_checkout() guard, Blocks found nothing on every other
	 * page and logged:
	 *
	 *   Payment gateway with handle 'xpay-blocks' has been deactivated in
	 *   Cart and Checkout blocks because its dependency 'xpay-elements' is
	 *   not registered.
	 *
	 * Registering costs nothing and outputs nothing: WordPress emits a
	 * registered script only when something enqueues it. So this is safe to
	 * call from anywhere, and both callers do.
	 */
	public static function register_scripts(): void {
		/*
		 * The row icons' one rule, attached to a handle of its own: the
		 * brand mark sits at the row's FAR END, floated and size-capped,
		 * exactly as Stripe's classed payment icons are. Inline, because
		 * one declaration is not worth a stylesheet request.
		 */
		wp_register_style( self::STYLE_HANDLE, false, array(), XPAY_WC_VERSION );
		wp_add_inline_style(
			self::STYLE_HANDLE,
			'ul.payment_methods li img.xpay-method-icon{float:right;max-height:24px;max-width:56px;margin:0;padding-left:3px}'
			/*
			 * Optical compensation, Fawry only, copied from the platform's
			 * own FawryIcon: every sibling mark is WIDE (ValU's swirl, the
			 * card networks), so at a shared height the roundel reads as
			 * the small one. transform leaves the layout box alone.
			 */
			. 'ul.payment_methods li img.xpay-method-icon--fawry{transform:scale(1.25);transform-origin:right center}'
		);

		wp_register_script(
			self::HANDLE,
			XPAY_WC_PLUGIN_URL . 'assets/js/checkout-elements.js',
			array(),
			XPay_Constants::asset_version( 'assets/js/checkout-elements.js' ),
			true
		);

		wp_register_script(
			self::DRIVER_HANDLE,
			XPAY_WC_PLUGIN_URL . 'assets/js/checkout-driver.js',
			// jQuery is not optional here: the Place Order takeover is bound
			// through it, and the file's own `if ( window.jQuery )` guard
			// turns a late jQuery into a checkout that silently does
			// nothing rather than an error anybody would notice.
			array( self::HANDLE, 'jquery' ),
			XPay_Constants::asset_version( 'assets/js/checkout-driver.js' ),
			true
		);
	}

	/**
	 * Load the mount module on the checkout page only.
	 *
	 * Not on the pay page, which still opens the window, and not on the
	 * order-received page, which has nothing to pay.
	 *
	 * This runs on every checkout the gateway is offered on, and creates
	 * nothing. It publishes what the page needs in order to ask later: the
	 * endpoint, a nonce for it, the publishable key, and every line of
	 * wording the browser side may have to show a shopper. The session
	 * itself waits for the page to ask, which it does only once XPay is the
	 * row in front of the shopper.
	 */
	public static function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$gateway = self::gateway();
		if ( null === $gateway ) {
			return;
		}

		// BEFORE the availability gate, because a stale cache is exactly
		// what the gate answers from: the account facts get a quiet
		// re-read once they outlive their shelf life, so a method or
		// currency the account gained or lost since the last key save
		// reaches the checkout within the window instead of never.
		$gateway->maybe_refresh_account_facts();

		// offers_any_method(), not is_available(): the main gateway is the
		// CARD row now, and a currency the account charges only through
		// other methods still needs these scripts for those rows.
		if ( ! $gateway->offers_any_method() ) {
			return;
		}

		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			self::enqueue_pay_page( $gateway );
			return;
		}

		self::register_scripts();
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_script( self::DRIVER_HANDLE );

		/*
		 * Localised onto the LIBRARY handle, not the classic driver.
		 *
		 * Both checkouts read xpayElementsParams, and the Blocks bundle
		 * reads it at module scope — so hanging it off a classic-only script
		 * meant the Blocks row could initialise with an empty params object:
		 * no nonce, no publishable key, and no way to say why. Attaching it
		 * to the one handle both surfaces depend on makes the ordering a
		 * fact rather than a coincidence.
		 */
		wp_localize_script(
			self::HANDLE,
			'xpayElementsParams',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'publishableKey' => $gateway->publishable_key(),
				'sdkUrl'         => XPay_Constants::sdk_url(),
				'colorMode'      => self::color_mode( $gateway ),
				'locale'         => 0 === strpos( get_locale(), 'ar' ) ? 'ar' : 'en',
				'gatewayId'      => XPay_Constants::GATEWAY_ID,
				// Every checkout row's gateway id, main row first. The
				// Blocks bundle registers one payment method per name;
				// the classic driver binds one Place Order takeover per
				// name. Data for each row rides on the row itself.
				'rows'           => self::row_names( $gateway ),
				/*
				 * What the element DISPLAYS, and therefore what may be
				 * charged. No session exists while the shopper fills the
				 * form; this amount is the whole of the deferred contract,
				 * and the server refuses to charge a session that does not
				 * total exactly it.
				 *
				 * Seeded from the cart here and moved by the driver on
				 * every recalculation, which costs no API call at all.
				 */
				'amount'         => self::cart_total_minor(),
				'currency'       => strtoupper( get_woocommerce_currency() ),
				'i18n'           => array(
					'unavailable'  => __( 'Payment is unavailable right now. Please try again in a moment.', 'xpay-for-woocommerce' ),
					'totalChanged' => __( 'Your order total changed. Check the new total and try again.', 'xpay-for-woocommerce' ),
					'emptyCart'    => __( 'Your basket is empty, so there is nothing to pay for. Add something to it and try again.', 'xpay-for-woocommerce' ),
					'notCompleted' => __( 'Payment was not completed. Your order is saved, and you can try again.', 'xpay-for-woocommerce' ),
					'confirmSlow'  => __( 'This payment is taking longer than expected. Do not pay again. Check your order status or your XPay app before retrying, and contact us if you are unsure.', 'xpay-for-woocommerce' ),
					'notReady'     => __( 'The payment fields are still loading. Please try again in a moment.', 'xpay-for-woocommerce' ),
					'incomplete'   => __( 'Please finish filling in the payment details before placing your order.', 'xpay-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * The order-pay page's assets: the same element, one fixed amount.
	 *
	 * The order already exists here (pay links, retries from emails,
	 * admin-created orders), so the driver is the checkout's with the
	 * moving-cart machinery removed — and the session still follows the
	 * one-per-order discipline through the order_session endpoint.
	 *
	 * @param XPay_Gateway $gateway Configured gateway.
	 */
	private static function enqueue_pay_page( XPay_Gateway $gateway ): void {
		global $wp;
		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || ! $order->needs_payment() ) {
			return;
		}

		self::register_scripts();
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_script(
			'xpay-pay-page',
			XPAY_WC_PLUGIN_URL . 'assets/js/pay-page.js',
			array( self::HANDLE ),
			XPay_Constants::asset_version( 'assets/js/pay-page.js' ),
			true
		);

		$currency = strtoupper( $order->get_currency() );
		wp_localize_script(
			'xpay-pay-page',
			'xpayPayPageParams',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'publishableKey' => $gateway->publishable_key(),
				'sdkUrl'         => XPay_Constants::sdk_url(),
				'colorMode'      => self::color_mode( $gateway ),
				'locale'         => 0 === strpos( get_locale(), 'ar' ) ? 'ar' : 'en',
				'gatewayId'      => XPay_Constants::GATEWAY_ID,
				'amount'         => XPay_Money::to_minor( $order->get_total(), $currency ),
				'currency'       => $currency,
				'orderId'        => (string) $order->get_id(),
				'orderKey'       => (string) $order->get_order_key(),
				'returnUrl'      => $order->get_checkout_order_received_url(),
				// Who the shopper is, read off the ORDER: the pay page has
				// no billing form of its own to collect it from.
				'customer'       => array_filter(
					array(
						'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
						'email' => (string) $order->get_billing_email(),
						'phone' => (string) $order->get_billing_phone(),
					)
				),
				'i18n'           => array(
					'unavailable'  => __( 'Payment is unavailable right now. Please try again in a moment.', 'xpay-for-woocommerce' ),
					'retry'        => __( 'The payment form could not load. Reload the page to try again.', 'xpay-for-woocommerce' ),
					'notCompleted' => __( 'Payment was not completed. Your order is saved, and you can try again.', 'xpay-for-woocommerce' ),
					'confirmSlow'  => __( 'This payment is taking longer than expected. Do not pay again. Check your order status or your XPay app before retrying, and contact us if you are unsure.', 'xpay-for-woocommerce' ),
					'notReady'     => __( 'The payment fields are still loading. Please try again in a moment.', 'xpay-for-woocommerce' ),
					'incomplete'   => __( 'Please finish filling in the payment details before placing your order.', 'xpay-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Every checkout row's gateway id, main row first — the classic
	 * driver's binding list and the Blocks bundle's registration list.
	 *
	 * @param XPay_Gateway $gateway Configured gateway.
	 * @return string[]
	 */
	private static function row_names( XPay_Gateway $gateway ): array {
		$names = array( XPay_Constants::GATEWAY_ID );
		foreach ( $gateway->method_row_types() as $type ) {
			$names[] = XPay_Constants::GATEWAY_ID . '_' . $type;
		}
		return $names;
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
	 * The container XPay's fields mount into.
	 *
	 * Rendered for the classic checkout and the order-pay page by each
	 * row's payment_fields(). Blocks builds the equivalent nodes in
	 * JavaScript, because Blocks renders its rows in the browser.
	 *
	 * @param string|null $method The row's method type ('card', 'valu',
	 *                            ...), stamped on the container so the
	 *                            driver mounts fields restricted to it.
	 *                            Null for the no-map fallback row, which
	 *                            renders every method unfiltered.
	 */
	public static function render_mount( ?string $method = null ): void {
		$gateway = self::gateway();
		if ( null === $gateway ) {
			return;
		}

		// A method row describes ITS method; the fallback row keeps the
		// merchant's own sentence, because it renders every method.
		$description = null !== $method
			? XPay_Payment_Methods::description( $method )
			: (string) $gateway->get_option( 'description', '' );
		if ( '' !== $description ) {
			echo '<p class="xpay-el__description">' . esc_html( $description ) . '</p>';
		}

		/*
		 * The cart total rides on the container, re-rendered by WooCommerce
		 * on every recalculation. That is what lets the page move the
		 * DISPLAYED amount without asking the server for a number it has
		 * just rendered into the order-review table anyway.
		 */
		$amount = self::cart_total_minor();
		echo '<div class="xpay-el" data-xpay-elements data-xpay-method="' . esc_attr( (string) $method ) . '" data-xpay-amount="' . esc_attr( (string) ( null === $amount ? 0 : $amount ) ) . '">';
		echo '<div class="xpay-el__mount"></div>';
		echo '<div class="xpay-el__notice" data-xpay-elements-error hidden role="alert"></div>';

		/*
		 * The pass-through fee line is NOT rendered in the deferred flow,
		 * and its absence is the honest state rather than an omission.
		 *
		 * The fee is priced by the platform onto a SESSION, once the
		 * shopper picks a method (and for cards once the BIN routes). No
		 * session exists here any more, so there is no number to show and
		 * no moment at which one could appear — the line's own promise,
		 * "you will see the exact amount before you pay", could not be
		 * kept. Charge = display is the reason it cannot come back
		 * silently: a session the platform inflated with a fee would no
		 * longer total what these fields displayed, and the confirmation
		 * would be refused rather than quietly charging more.
		 *
		 * The rest of the fee machinery is gone too, in its own commit.
		 */

		echo '</div>';
	}


	/* ── Endpoints ───────────────────────────────────────────────────── */

	/**
	 * The order-pay page's session, minted (or reused) at Pay.
	 *
	 * The deferred flow's server half for pay links: the ORDER already
	 * exists, so this is get_or_create_session under the same one-session-
	 * per-order discipline as the checkout — a retry reuses the session
	 * and its clientSecret, a changed total (an admin editing the order
	 * between attempts) reprices it, and only an expired one is replaced.
	 *
	 * Order-key authenticated, exactly like the outcome endpoint and for
	 * the same reason: the nonce only proves the request came from a page,
	 * and every visitor has one. The key is WooCommerce's own proof of
	 * access to an order for someone with no account, which is who pays a
	 * pay link.
	 *
	 * The already-paid answer is the stale-pay-link guard: an emailed link
	 * opened after a webhook completed the order must offer the receipt,
	 * never a second charge. get_or_create_session applies a COMPLETE/PAID
	 * session to the order on its way through, so both are told here.
	 */
	public static function handle_order_session(): void {
		self::verify();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::verify() ran check_ajax_referer before this line; the sniff cannot see through the helper.
		$order_id = isset( $_POST['order'] ) ? absint( wp_unslash( $_POST['order'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::verify() ran check_ajax_referer before this line; the sniff cannot see through the helper.
		$order_key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || '' === $order_key || ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( array( 'reason' => 'not-found' ), 404 );
		}

		if ( $order->is_paid() ) {
			wp_send_json_success(
				array(
					'paid'     => true,
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		$gateway = self::gateway();
		if ( null === $gateway || $gateway->needs_setup() ) {
			wp_send_json_error( array( 'reason' => 'unavailable' ), 503 );
		}

		/*
		 * One writer per order, the same lock process_payment holds around
		 * the same call. Two tabs on one pay link would otherwise both find
		 * no stored session and both create one: the loser's write is
		 * overwritten, its session stays OPEN and payable but is in neither
		 * the order's meta nor the superseded ledger, and a payment on it
		 * would be judged 'foreign' and dropped — money taken, order never
		 * marked paid. Non-fatal if it cannot be had (hosts without
		 * GET_LOCK answer true), same as the checkout path.
		 */
		$locked  = XPay_Order_Lock::acquire( (int) $order->get_id(), 5 );
		$session = null;
		$failure = null;

		try {
			$service = new XPay_Checkout_Service(
				$gateway->api_client(),
				$gateway->accepted_types_for_session( $order->get_currency() ),
				array( $gateway, 'refresh_accepted_types_for_session' )
			);
			$session = $service->get_or_create_session( $order );
		} catch ( XPay_Api_Exception $e ) {
			$failure = $e;
		} finally {
			// Released HERE, before any response: wp_send_json_* exits the
			// request, and exit skips finally blocks.
			if ( $locked ) {
				XPay_Order_Lock::release( (int) $order->get_id() );
			}
		}

		if ( null !== $failure ) {
			XPay_Logger::event(
				'pay_page.session_failed',
				array(
					'order_id' => $order->get_id(),
					'code'     => $failure->get_error_code(),
				)
			);
			wp_send_json_error( array( 'reason' => 'unavailable' ), 502 );
		}

		if ( isset( $session['status'] ) && XPay_Session_Status::COMPLETE === $session['status'] ) {
			// A stale pay link: the session check found the payment and
			// applied it. The receipt is the only honest offer.
			wp_send_json_success(
				array(
					'paid'     => true,
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		wp_send_json_success(
			array(
				'paid'         => false,
				'clientSecret' => isset( $session['clientSecret'] ) ? (string) $session['clientSecret'] : '',
			)
		);
	}

	/**
	 * Did the payment go through? Asked when the browser cannot tell.
	 *
	 * The SDK DOES carry a code for a payment the platform could not decide
	 * on: `payment_still_confirming`, raised both when the status poll runs
	 * out and when XPay reports the charge unconfirmable, then passes through
	 * the SDK unchanged. It is
	 * deliberately not read, and the reason is not that it is missing.
	 *
	 * It reports how ONE attempt ended in THIS browser. What has to be
	 * decided here is what becomes of the WooCommerce order, and that turns
	 * on whether the session at XPay is paid: the webhook may have settled
	 * it while this browser was still waiting. So the browser stops guessing
	 * and this answers instead, from the only authority there is, the
	 * session at XPay.
	 *
	 * Three answers, and only one of them lets a shopper try again:
	 *
	 *   'paid'    XPay has the money. Nothing to retry, and the order page
	 *             is where they belong.
	 *   'unpaid'  XPay is certain no money moved AND the session can still
	 *             be paid. The only safe time to offer the button back.
	 *   'unknown' anything else, including an unreachable API. Fails to the
	 *             order page and lets the webhook settle it.
	 *
	 * Asked only when a confirm did not come back clean, so the ordinary
	 * payment never pays for it.
	 */
	public static function handle_outcome(): void {
		self::verify();

		/*
		 * THE KEY IS WHY AN ID CAN BE TRUSTED. The nonce only proves the
		 * request came from a page carrying one, and every visitor to the
		 * checkout has that. Without this check any visitor could post any
		 * order id and read back whether it is paid, one id at a time, and
		 * each probe would spend a live session read against the API. The
		 * order key is WooCommerce's own proof of access to an order for
		 * someone with no account, which is exactly the shopper here.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::verify() ran check_ajax_referer before this line; the sniff cannot see through the helper.
		$order_id = isset( $_POST['order'] ) ? absint( wp_unslash( $_POST['order'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::verify() ran check_ajax_referer before this line; the sniff cannot see through the helper.
		$order_key  = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$session_id = self::owned_session_id( $order_id, $order_key );

		if ( '' === $session_id ) {
			wp_send_json_success( array( 'verdict' => 'unknown' ) );
		}

		try {
			$session = XPay_Plugin::instance()->gateway()->api_client()->get_checkout_session(
				$session_id,
				XPay_Api_Client::SHOPPER_READ_TIMEOUT_SECONDS
			);
		} catch ( XPay_Api_Exception $e ) {
			// Cannot reach XPay, so nothing is known — and "nothing known"
			// must never read as "safe to charge again".
			XPay_Logger::event(
				'elements.outcome_unreadable',
				array(
					'session_id' => $session_id,
					'code'       => $e->get_error_code(),
				)
			);
			wp_send_json_success( array( 'verdict' => self::verdict_for( null ) ) );
		}

		$verdict = self::verdict_for( $session );
		if ( 'unknown' === $verdict ) {
			// in_flight is the third field the verdict now turns on, so it
			// belongs here: without it a session that reads open and unpaid
			// and still answers 'unknown' looks like a bug in the log.
			$in_flight = self::attempt_in_flight( $session );
			XPay_Logger::event(
				'elements.outcome_undecided',
				array(
					'session_id'     => $session_id,
					'payment_status' => isset( $session['paymentStatus'] ) ? (string) $session['paymentStatus'] : '',
					'status'         => isset( $session['status'] ) ? (string) $session['status'] : '',
					'in_flight'      => null === $in_flight ? 'unreadable' : ( $in_flight ? 'yes' : 'no' ),
				)
			);
		}

		wp_send_json_success( array( 'verdict' => $verdict ) );
	}

	/**
	 * The session an order owns, but only for someone holding its key.
	 *
	 * Separated from the transport for the same reason verdict_for() is:
	 * what it decides is whether a stranger may read an order's payment
	 * state, and that deserves to be readable without a transport in the
	 * way.
	 *
	 * @param int    $order_id  Order the browser named.
	 * @param string $order_key The order's access token, as WooCommerce issues it.
	 * @return string The session id, or '' when the pair does not check out.
	 */
	private static function owned_session_id( int $order_id, string $order_key ): string {
		if ( $order_id <= 0 || '' === $order_key ) {
			return '';
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		// hash_equals, not ===: the key is a secret being compared against
		// attacker-supplied input, which is what timing attacks are for.
		if ( ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			return '';
		}
		return (string) $order->get_meta( XPay_Constants::META_SESSION_ID );
	}

	/**
	 * The verdict itself, separated from the transport so it can be read and
	 * tested as the rule it is.
	 *
	 * @param array|null $session Session as XPay returned it, null if it
	 *                            could not be read at all.
	 * @return string 'paid', 'unpaid' or 'unknown'.
	 */
	private static function verdict_for( ?array $session ): string {
		if ( null === $session ) {
			return 'unknown';
		}

		$payment_status = isset( $session['paymentStatus'] ) ? (string) $session['paymentStatus'] : '';
		if ( XPay_Payment_Status::PAID === $payment_status ) {
			return 'paid';
		}

		/*
		 * UNPAID alone is not enough to offer a retry: a session that is no
		 * longer payable reports unpaid too, an expired one above all, and
		 * inviting a shopper to try again there sends them into a wall.
		 * Hence OPEN and not expired as well.
		 */
		$open = XPay_Session_Status::OPEN === ( isset( $session['status'] ) ? (string) $session['status'] : '' )
			&& empty( $session['isExpired'] );

		if ( XPay_Payment_Status::UNPAID !== $payment_status || ! $open ) {
			return 'unknown';
		}

		/*
		 * The session's own two fields cannot tell a charge that is still
		 * settling from one nobody has tried to pay. COMPLETE and PAID are
		 * written together at the end, so everything before
		 * that end reads OPEN and unpaid: the untouched session and the
		 * charge halfway through the bank both. This verdict promises the
		 * opposite of that ("XPay is certain no money moved"), so it has to
		 * read the object that CAN tell them apart.
		 *
		 * That object is on the payload already. `GET /checkout/sessions/:id`
		 * expands `paymentIntent.charges[]`. An attempt is PENDING before the
		 * processor is called and only leaves PENDING when
		 * its outcome is known. So a PENDING charge IS the in-flight money,
		 * named.
		 *
		 * Anything that cannot be read that far is 'unknown' too. Not
		 * because a retry would certainly be wrong, but because the only
		 * mistake this endpoint must never make is offering one over money
		 * that is already moving.
		 */
		return ( false === self::attempt_in_flight( $session ) ) ? 'unpaid' : 'unknown';
	}

	/**
	 * Is one of this session's payment attempts still settling?
	 *
	 * Null for "cannot tell", the same shape and for the same reason as
	 * XPay_Refundable::from_session(): a payload that did not expand its
	 * charges says nothing about them, and absent is not empty.
	 *
	 * A missing `paymentIntent` key is a payload we do not recognise, so it
	 * answers null. A `paymentIntent` of null is not: the response always
	 * carries the key and sets it null when the session has no intent yet,
	 * and no intent means no charge,
	 * which means nothing in flight.
	 *
	 * @param array $session Session payload from the API.
	 * @return bool|null True if an attempt is still open, false if none is,
	 *                   null when the payload cannot answer.
	 */
	private static function attempt_in_flight( array $session ): ?bool {
		if ( ! array_key_exists( 'paymentIntent', $session ) ) {
			return null;
		}

		$intent = $session['paymentIntent'];
		if ( null === $intent ) {
			return false;
		}
		if ( ! is_array( $intent ) || ! isset( $intent['charges'] ) || ! is_array( $intent['charges'] ) ) {
			return null;
		}

		foreach ( $intent['charges'] as $charge ) {
			if ( ! is_array( $charge ) || ! isset( $charge['status'] ) ) {
				// An attempt we cannot read may be the one still moving.
				return null;
			}
			if ( XPay_Charge_Status::PENDING === (string) $charge['status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reject anything that is not a genuine request from our own checkout.
	 *
	 * These endpoints move money-shaped state, so a failed check ends the
	 * request rather than falling through to a default.
	 */
	private static function verify(): void {
		// POST only. Every one of these endpoints changes state, and both
		// drivers already POST; without this a plain link or an <img> src
		// could drive them from anywhere a shopper's browser follows one.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			wp_send_json_error( array( 'reason' => 'bad-method' ), 405 );
		}

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

		self::recalculate_in_checkout_context();

		$total = WC()->cart->get_total( 'edit' );
		if ( '' === (string) $total ) {
			return null;
		}
		try {
			$minor = XPay_Money::to_minor( (string) $total, get_woocommerce_currency() );
		} catch ( InvalidArgumentException $e ) {
			// `woocommerce_cart_get_total` is a filter, so what arrives here
			// is not guaranteed to be a plain decimal, and a total this
			// plugin cannot read is not worth a fatal on the mount path.
			// Converted once, ahead of the zero check below, so that the
			// conversion which can throw is the one this guards.
			return null;
		}

		/*
		 * Nothing to pay is not an amount. A basket covered entirely by a
		 * coupon still HAS items, so the empty-cart guard above lets it
		 * through, and a zero then missed the minimum-quantity gate in
		 * sync_amount(), fell through to replace, and minted a session
		 * whose quantity line_items() clamped up to one — a live EGP 0.01
		 * session in the merchant's dashboard, against a local record of
		 * nothing. The MIN_QUANTITY docblock asserts this cannot happen.
		 *
		 * Answered the same way as an empty cart because it is the same
		 * situation from here: WooCommerce does not call a gateway for an
		 * order that needs no payment, on either checkout
		 * (class-wc-checkout.php:1444, StoreApi/Routes/V1/Checkout.php:682),
		 * both of which ask WC_Order::needs_payment rather than the cart's.
		 */
		if ( 0 === $minor ) {
			return null;
		}

		return $minor;
	}

	/**
	 * Make the cart answer the question checkout would ask it.
	 *
	 * These endpoints run over AJAX, where is_checkout() is false and the
	 * cached total is whatever the last page view left behind. Tax and fee
	 * plugins routinely behave differently at checkout — a checkout-only fee,
	 * a tax rate that depends on the entered address — so the number this
	 * plugin compares against, and charges, could be a cart-page number.
	 *
	 * Both halves matter. The constant is how core itself puts a request into
	 * checkout context (`class-wc-ajax.php:399`, `StoreApi/Legacy.php:38`),
	 * and is_checkout() reports it (`wc-conditional-functions.php:121`);
	 * the recalculation is what makes third-party totals re-run under it.
	 *
	 * Not memoised. One endpoint runs per request and each reads the total
	 * once, so a "have I already done this" flag would save nothing and
	 * would mean the second read in any request answered from a snapshot of
	 * the first — which is the class of bug this method exists to fix.
	 */
	private static function recalculate_in_checkout_context(): void {
		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );

		if ( WC()->customer ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * The configured XPay gateway, or null before WooCommerce loads.
	 *
	 * Through XPay_Plugin::gateway(), never out of WC()'s registry: the
	 * registry holds the SAME shared instance (register_gateway registers
	 * it), but reading it there skips the init_settings() refresh the
	 * accessor performs — so this file answered from whatever settings
	 * snapshot the instance happened to hold, while every other reader
	 * (webhook controller, thank-you check) got a fresh read. One answer
	 * per question.
	 */
	private static function gateway(): ?XPay_Gateway {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return null;
		}
		return XPay_Plugin::instance()->gateway();
	}
}
