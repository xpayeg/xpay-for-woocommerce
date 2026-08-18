<?php
/**
 * XPay_Gateway
 *
 * The WooCommerce payment gateway. A thin dispatch surface, per the house
 * layering rule — every hook method extracts context, calls one service,
 * and returns; session/refund/order logic lives in the services.
 *
 * Checkout flow (modal-first with invisible fallback):
 *   1. process_payment() creates/reuses the XPay session, then redirects to
 *      WooCommerce's own order-pay page. Hosting the payment step there —
 *      not injecting JS into the checkout form — gives ONE flow that works
 *      identically for classic checkout, Blocks checkout, and admin-created
 *      pay links, and it doubles as the natural retry surface.
 *   2. receipt_page() renders the payment container; checkout-modal.js
 *      loads the XPay SDK and opens the drop-in modal immediately.
 *   3. If the SDK cannot load, the same page auto-continues to the hosted
 *      checkout URL — the shopper never meets a dead end.
 *
 * Order truth is never written here: webhooks and the thank-you re-check
 * (XPay_Order_Sync) own all paid/expired transitions.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Gateway extends WC_Payment_Gateway {

	/** @var XPay_Api_Client|null Lazy — settings may be incomplete on admin screens. */
	private $client = null;

	/**
	 * Gateway ids whose hooks are already bound this request. WooCommerce
	 * constructs its own copy of every gateway AND the plugin keeps a
	 * settings-reader instance (which Blocks support touches on every
	 * page), so two instances of the same id coexist routinely. Hooks
	 * must bind once per id, not once per instance — otherwise
	 * woocommerce_receipt_<id> fires receipt_page twice and the shopper
	 * sees the pay page rendered double.
	 *
	 * @var array<string, bool>
	 */
	private static $hooked_ids = array();

	public function __construct() {
		$this->id                 = $this->gateway_id();
		$this->has_fields         = false;
		$this->method_title       = __( 'XPay', 'xpay-for-woocommerce' );
		$this->method_description = __( 'Accept cards, valU and more via XPay (Egypt). Customers pay in a secure XPay window without leaving your store.', 'xpay-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		if ( ! isset( self::$hooked_ids[ $this->id ] ) ) {
			self::$hooked_ids[ $this->id ] = true;
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		}
	}

	/**
	 * The WooCommerce gateway id. Overridable so the per-method rows
	 * (XPay_Method_Gateway) get their own id BEFORE the constructor wires
	 * the receipt/settings hooks that embed it.
	 */
	protected function gateway_id(): string {
		return XPay_Constants::GATEWAY_ID;
	}

	/**
	 * Session method restriction for this row: null = the merchant's full
	 * method list (combined row); the per-method rows return their one type.
	 *
	 * @return array|null Wire strings from XPay_Payment_Methods, or null.
	 */
	protected function pinned_method_types(): ?array {
		return null;
	}

	public function init_form_fields(): void {
		$webhook_url = home_url( '/?wc-api=' . XPay_Constants::WEBHOOK_ENDPOINT );

		$this->form_fields = array(
			'enabled'              => array(
				'title'   => __( 'Enable/Disable', 'xpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable XPay', 'xpay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                => array(
				'title'       => __( 'Title', 'xpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to customers at checkout.', 'xpay-for-woocommerce' ),
				'default'     => __( 'XPay', 'xpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'          => array(
				'title'       => __( 'Description', 'xpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'One sentence under the payment method name.', 'xpay-for-woocommerce' ),
				'default'     => __( 'Pay securely by card or valU.', 'xpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'mode'                 => array(
				'title'       => __( 'Mode', 'xpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Test mode never charges real money. Keys and webhook secrets are separate per mode.', 'xpay-for-woocommerce' ),
				'default'     => 'test',
				'options'     => array(
					'test' => __( 'Test', 'xpay-for-woocommerce' ),
					'live' => __( 'Live', 'xpay-for-woocommerce' ),
				),
			),
			'test_api_key'         => array(
				'title'       => __( 'Test secret key', 'xpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'A restricted key (rk_test_…) with Checkout Sessions and Refunds access, from your XPay dashboard → Developers → API keys.', 'xpay-for-woocommerce' ),
			),
			'test_publishable_key' => array(
				'title'       => __( 'Test publishable key', 'xpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'pk_test_… key, used by the secure payment window in the browser.', 'xpay-for-woocommerce' ),
			),
			'test_webhook_secret'  => array(
				'title'       => __( 'Test webhook signing secret', 'xpay-for-woocommerce' ),
				'type'        => 'password',
				/* translators: %s is this store's webhook URL. */
				'description' => sprintf( __( 'whsec_… secret for a webhook endpoint pointing at %s (events: checkout.session.completed, checkout.session.expired).', 'xpay-for-woocommerce' ), '<code>' . esc_html( $webhook_url ) . '</code>' ),
			),
			'live_api_key'         => array(
				'title'       => __( 'Live secret key', 'xpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'A restricted key (rk_live_…) with Checkout Sessions and Refunds access.', 'xpay-for-woocommerce' ),
			),
			'live_publishable_key' => array(
				'title'       => __( 'Live publishable key', 'xpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'pk_live_… key.', 'xpay-for-woocommerce' ),
			),
			'live_webhook_secret'  => array(
				'title'       => __( 'Live webhook signing secret', 'xpay-for-woocommerce' ),
				'type'        => 'password',
				/* translators: %s is this store's webhook URL. */
				'description' => sprintf( __( 'whsec_… secret for a live-mode webhook endpoint pointing at %s.', 'xpay-for-woocommerce' ), '<code>' . esc_html( $webhook_url ) . '</code>' ),
			),
			'display_heading'      => array(
				'title'       => __( 'Checkout display', 'xpay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Choose how XPay appears on your checkout page.', 'xpay-for-woocommerce' ),
			),
			'display_mode'         => array(
				'title'       => __( 'Payment options', 'xpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Separate options let shoppers pick their method before the payment window opens, so it opens directly on that method.', 'xpay-for-woocommerce' ),
				'default'     => 'combined',
				'options'     => array(
					'combined' => __( 'One XPay option for all methods', 'xpay-for-woocommerce' ),
					'split'    => __( 'A separate option per payment method', 'xpay-for-woocommerce' ),
				),
			),
			'split_card'           => array(
				'title'   => __( 'Card', 'xpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer Card (Visa, Mastercard, Meeza) as its own option', 'xpay-for-woocommerce' ),
				'default' => 'yes',
			),
			'split_valu'           => array(
				'title'   => __( 'valU', 'xpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer valU as its own option', 'xpay-for-woocommerce' ),
				'default' => 'yes',
			),
			'split_fawry'          => array(
				'title'       => __( 'Fawry', 'xpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Offer Fawry as its own option', 'xpay-for-woocommerce' ),
				'description' => __( 'Only tick methods that are enabled for your XPay account. Shoppers who pick a method your account does not have are shown the full XPay window instead, and you get a notice here in admin.', 'xpay-for-woocommerce' ),
				'default'     => 'no',
			),
			'debug'                => array(
				'title'   => __( 'Diagnostic logging', 'xpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Write redacted diagnostic logs (WooCommerce → Status → Logs, source "xpay")', 'xpay-for-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/* ── Checkout display (combined row vs per-method rows) ──────────── */

	/**
	 * Method types the merchant ticked for dedicated rows, or an empty
	 * array in combined mode. The registry order is preserved so the rows
	 * render in a stable order at checkout.
	 *
	 * @return array Wire strings from XPay_Payment_Methods::SPLITTABLE.
	 */
	public function split_types(): array {
		if ( 'split' !== $this->get_option( 'display_mode' ) ) {
			return array();
		}
		$types = array();
		foreach ( XPay_Payment_Methods::SPLITTABLE as $type ) {
			if ( 'yes' === $this->get_option( XPay_Payment_Methods::setting_key( $type ) ) ) {
				$types[] = $type;
			}
		}
		return $types;
	}

	/**
	 * Enabled/configured check shared by the combined row and the
	 * per-method rows — WITHOUT the display-mode split, which each row
	 * layers on differently in is_available(). Reads the SHARED switch
	 * from settings rather than $this->enabled: the per-method rows
	 * repurpose $this->enabled to show their own state in the payments
	 * list, so it cannot double as the plugin-wide switch.
	 */
	protected function base_available(): bool {
		// needs_setup() too: an enabled gateway with no keys can only dead-
		// end the shopper at "payment could not be started" — hiding every
		// XPay row (this check flows into the per-method rows and, via
		// is_available, into Blocks) is the honest state until keys exist.
		return 'yes' === $this->get_option( 'enabled' ) && ! $this->needs_setup();
	}

	/**
	 * The combined row steps aside when per-method rows are active: both
	 * at once would offer the same payment twice under different names.
	 */
	public function is_available() {
		return $this->base_available() && array() === $this->split_types();
	}

	/* ── Settings access (mode-aware) ────────────────────────────────── */

	public function is_test_mode(): bool {
		return 'live' !== $this->get_option( 'mode' );
	}

	public function api_key(): string {
		return (string) $this->get_option( $this->is_test_mode() ? 'test_api_key' : 'live_api_key' );
	}

	public function publishable_key(): string {
		return (string) $this->get_option( $this->is_test_mode() ? 'test_publishable_key' : 'live_publishable_key' );
	}

	public function webhook_secret(): string {
		// The webhook applies events for BOTH modes: the event's own
		// livemode stamp does not pick the secret — the configured mode
		// does, because test and live endpoints are separate XPay resources
		// with separate secrets, and this store subscribes as one of them.
		return (string) $this->get_option( $this->is_test_mode() ? 'test_webhook_secret' : 'live_webhook_secret' );
	}

	/**
	 * @throws XPay_Api_Exception When no key is configured.
	 */
	public function api_client(): XPay_Api_Client {
		if ( null === $this->client ) {
			$this->client = new XPay_Api_Client( $this->api_key() );
		}
		return $this->client;
	}

	public function needs_setup(): bool {
		return '' === $this->api_key() || '' === $this->publishable_key();
	}

	/**
	 * Guard against the half-configured state: a key pasted into the wrong
	 * mode field is caught at save time with a specific message, and a live
	 * key is validated with a real API call before the merchant can rely on it.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		// A settings save is the merchant acting on (or changing) their
		// method configuration — retire any standing pin-rejected notice.
		delete_option( XPay_Constants::OPTION_PIN_REJECTED );

		$key = $this->api_key();
		if ( '' === $key ) {
			// The one state guaranteed to hide checkout must not be the one
			// state that saves without a word.
			WC_Admin_Settings::add_error( __( 'XPay: no API key is saved for the selected mode, so XPay stays hidden at checkout until you add one.', 'xpay-for-woocommerce' ) );
			return $saved;
		}

		$key_is_live  = XPay_Api_Client::is_live_key( $key );
		$mode_is_live = ! $this->is_test_mode();
		if ( $key_is_live && ! $mode_is_live ) {
			WC_Admin_Settings::add_error( __( 'XPay: the key in the selected mode is a LIVE key but the gateway is in Test mode. Paste the matching key for the mode you selected.', 'xpay-for-woocommerce' ) );
			return $saved;
		}
		if ( ! $key_is_live && $mode_is_live ) {
			WC_Admin_Settings::add_error( __( 'XPay: the key in the selected mode is a TEST key but the gateway is in Live mode. Paste the matching key for the mode you selected.', 'xpay-for-woocommerce' ) );
			return $saved;
		}

		try {
			$this->client = null; // Re-validate with the freshly saved key.
			$this->api_client()->validate_key();
			WC_Admin_Settings::add_message(
				$this->is_test_mode()
					? __( 'XPay connected (test mode).', 'xpay-for-woocommerce' )
					: __( 'XPay connected (live mode).', 'xpay-for-woocommerce' )
			);
		} catch ( XPay_Api_Exception $e ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s is the error returned while validating the API key. */
					__( 'XPay: the API key did not validate — %s', 'xpay-for-woocommerce' ),
					$e->getMessage()
				)
			);
		}

		return $saved;
	}

	/* ── Checkout flow ───────────────────────────────────────────────── */

	/**
	 * @param int $order_id Order being paid.
	 * @return array result/redirect pair per the gateway contract.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		try {
			$service = new XPay_Checkout_Service( $this->api_client() );
			$service->get_or_create_session( $order, $this->pinned_method_types() );
		} catch ( XPay_Api_Exception $e ) {
			XPay_Logger::event(
				'process_payment.failed',
				array(
					'order_id' => $order_id,
					'code'     => $e->get_error_code(),
				)
			);
			// Shopper-safe message only — the real error is in the log and
			// the order note; internals never reach wc_add_notice().
			wc_add_notice( __( 'The payment could not be started. Please try again — your card has not been charged.', 'xpay-for-woocommerce' ), 'error' );
			$order->add_order_note(
				sprintf(
					/* translators: 1: XPay error code, 2: error message. */
					__( 'XPay session creation failed [%1$s]: %2$s', 'xpay-for-woocommerce' ),
					$e->get_error_code(),
					$e->getMessage()
				)
			);
			return array( 'result' => 'failure' );
		}

		// Stock is NOT reduced and the cart is NOT emptied here — both wait
		// for payment_complete(), so an abandoned modal never strands stock.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Order-pay page body: the modal mounts here.
	 *
	 * @param int $order_id Order being paid.
	 */
	public function receipt_page( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$session_id    = (string) $order->get_meta( XPay_Constants::META_SESSION_ID );
		$client_secret = (string) $order->get_meta( XPay_Constants::META_CLIENT_SECRET );
		if ( '' === $session_id || '' === $client_secret ) {
			// No session (direct order-pay visit): create one now so pay
			// links from admin emails work identically.
			try {
				$service       = new XPay_Checkout_Service( $this->api_client() );
				$session       = $service->get_or_create_session( $order, $this->pinned_method_types() );
				$client_secret = (string) $session['clientSecret'];
			} catch ( XPay_Api_Exception $e ) {
				echo '<p>' . esc_html__( 'The payment could not be started. Please refresh the page to try again.', 'xpay-for-woocommerce' ) . '</p>';
				return;
			}
		}

		$hosted_url = $this->hosted_url_for( $order );

		wp_enqueue_style(
			'xpay-pay-page',
			XPAY_WC_PLUGIN_URL . 'assets/css/pay-page.css',
			array(),
			XPAY_WC_VERSION
		);
		wp_enqueue_script(
			'xpay-checkout-modal',
			XPAY_WC_PLUGIN_URL . 'assets/js/checkout-modal.js',
			array(),
			XPAY_WC_VERSION,
			true
		);
		wp_localize_script(
			'xpay-checkout-modal',
			'xpayCheckoutParams',
			array(
				'sdkUrl'         => XPay_Constants::sdk_url(),
				'publishableKey' => $this->publishable_key(),
				'clientSecret'   => $client_secret,
				'hostedUrl'      => $hosted_url,
				'returnUrl'      => $order->get_checkout_order_received_url(),
				'locale'         => 0 === strpos( get_locale(), 'ar' ) ? 'ar' : 'en',
				'i18n'           => array(
					'preparing' => __( 'Opening secure payment…', 'xpay-for-woocommerce' ),
					'fallback'  => __( 'Taking you to the secure payment page…', 'xpay-for-woocommerce' ),
					'reopen'    => __( 'Pay now', 'xpay-for-woocommerce' ),
					'closed'    => __( 'Your order is saved. Pay when you are ready.', 'xpay-for-woocommerce' ),
				),
			)
		);

		XPay_Pay_Page::render(
			$order,
			$hosted_url,
			$this->pinned_method_types(),
			XPay_Branding::stage_from_primary( (string) get_option( XPay_Constants::OPTION_BRAND_PRIMARY, '' ) )
		);
	}

	/**
	 * The hosted checkout URL for this order's session, allowlist-checked.
	 * Empty string when unavailable — callers must handle both.
	 */
	private function hosted_url_for( WC_Order $order ): string {
		// The URL the API returned for THIS session, persisted and
		// allowlist-checked at creation. Re-checked here because order meta
		// is writable by any other code on the site. No rebuilt fallback:
		// every session ever written by a shipped build persists its URL in
		// the same save as its id, so a rebuild branch would be a shim for
		// a past that never shipped — and wrong under a staging override.
		$stored = (string) $order->get_meta( XPay_Constants::META_SESSION_URL );
		return ( '' !== $stored && XPay_Constants::is_allowed_xpay_url( $stored ) ) ? $stored : '';
	}

	/* ── Refunds ─────────────────────────────────────────────────────── */

	/**
	 * @param int    $order_id Order id.
	 * @param float  $amount   Amount to refund.
	 * @param string $reason   Admin-entered reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || null === $amount ) {
			return new WP_Error( 'xpay_refund', __( 'Refund amount is required.', 'xpay-for-woocommerce' ) );
		}

		try {
			$service = new XPay_Refund_Service( $this->api_client() );
			$service->refund_order( $order, (float) $amount, (string) $reason );
			return true;
		} catch ( XPay_Api_Exception $e ) {
			return new WP_Error( 'xpay_refund_' . $e->get_error_code(), XPay_Refund_Service::admin_message( $e ) );
		}
	}
}
