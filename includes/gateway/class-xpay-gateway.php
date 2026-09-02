<?php
/**
 * XPay_Gateway
 *
 * The WooCommerce payment gateway. A thin dispatch surface, per the house
 * layering rule — every hook method extracts context, calls one service,
 * and returns; session/refund/order logic lives in the services.
 *
 * Checkout flow (deferred). The shopper pays WITHOUT LEAVING THE CHECKOUT
 * PAGE, and NOTHING exists on the platform until they do:
 *   1. payment_fields() mounts XPay Elements from an amount and a
 *      currency alone. No session. The fields live in an iframe and a
 *      navigation destroys them, which is what shapes everything below.
 *   2. process_payment() does NOT charge. The order now exists, so it
 *      gets (or reuses — one session per order) a session born carrying
 *      the order's id and key, and answers with its clientSecret plus
 *      xpay_confirm, telling the browser to confirm before it follows
 *      the redirect.
 *   3. The browser confirms against that secret. Only then does the card
 *      move, and only for exactly the amount the fields displayed.
 *
 * The session comes after the order so every payment has a WooCommerce
 * order to attach to.
 *
 * The order-pay page runs the same flow at a fixed amount: pay links,
 * retries and admin-created orders mount the same fields, and Pay asks
 * the order_session endpoint for this order's session.
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
		$this->id = XPay_Constants::GATEWAY_ID;
		/*
		 * This gateway renders payment fields, so it must say so. Core only
		 * draws the payment box — and so only calls payment_fields() — when
		 * `has_fields() || get_description()`
		 * (`templates/checkout/payment-method.php:28`). With this false, the
		 * card form on the classic checkout existed purely because the
		 * Description setting happened to ship non-empty: a merchant who
		 * cleared that box removed their own payment fields.
		 */
		$this->has_fields         = true;
		$this->method_title       = __( 'XPay', 'xpay-for-woocommerce' );
		$this->method_description = __( 'Accept cards, ValU and more via XPay (Egypt). Customers pay without leaving your store, and each payment method appears as its own option at checkout.', 'xpay-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );
		// The checkout button says what happens next when an XPay row is
		// selected: the payment window opens. Classic checkout reads this
		// property; Blocks gets the same label via payment method data.
		// The msgid is shared with the pay page's button on purpose.
		$this->order_button_text = __( 'Pay now', 'xpay-for-woocommerce' );
		// The brand tile WooCommerce's Payments page shows on the provider
		// row (it falls back to a generic placeholder icon without one).
		$this->icon = XPAY_WC_PLUGIN_URL . 'assets/images/xpay-icon.svg';

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



	public function init_form_fields(): void {
		$webhook_url = home_url( '/?wc-api=' . XPay_Constants::WEBHOOK_ENDPOINT );

		$this->form_fields = array(
			'enabled'                           => array(
				'title'   => __( 'Enable/Disable', 'xpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable XPay', 'xpay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                             => array(
				'title'       => __( 'Title', 'xpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to customers at checkout.', 'xpay-for-woocommerce' ),
				'default'     => __( 'XPay', 'xpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'                       => array(
				'title'       => __( 'Description', 'xpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'One sentence under the payment method name.', 'xpay-for-woocommerce' ),
				'default'     => __( 'Pay securely by card or ValU.', 'xpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'mode'                              => array(
				'title'       => __( 'Mode', 'xpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Test mode never charges real money. Keys and webhook secrets are separate per mode.', 'xpay-for-woocommerce' ),
				'default'     => 'test',
				'options'     => array(
					'test' => __( 'Test', 'xpay-for-woocommerce' ),
					'live' => __( 'Live', 'xpay-for-woocommerce' ),
				),
			),
			'test_api_key'                      => array(
				'title'       => __( 'Test secret key', 'xpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'A restricted key (rk_test_…) with Checkout Sessions, Refunds and Webhook Endpoints access, from your XPay dashboard → Developers → API keys.', 'xpay-for-woocommerce' ),
			),
			'test_publishable_key'              => array(
				'title'       => __( 'Test publishable key', 'xpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'pk_test_… key, used by the secure payment window in the browser.', 'xpay-for-woocommerce' ),
			),
			'test_webhook_secret'               => array(
				'title'       => __( 'Test webhook signing secret', 'xpay-for-woocommerce' ),
				'type'        => 'password',
				/* translators: %s is this store's webhook URL. */
				'description' => sprintf( __( 'whsec_… secret for a webhook endpoint pointing at %s (events: checkout.session.completed, checkout.session.expired, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, payment_intent.payment_failed, charge.refunded, refund.failed).', 'xpay-for-woocommerce' ), '<code>' . esc_html( $webhook_url ) . '</code>' ),
			),
			'live_api_key'                      => array(
				'title'       => __( 'Live secret key', 'xpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'A restricted key (rk_live_…) with Checkout Sessions, Refunds and Webhook Endpoints access.', 'xpay-for-woocommerce' ),
			),
			'live_publishable_key'              => array(
				'title'       => __( 'Live publishable key', 'xpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'pk_live_… key.', 'xpay-for-woocommerce' ),
			),
			'live_webhook_secret'               => array(
				'title'       => __( 'Live webhook signing secret', 'xpay-for-woocommerce' ),
				'type'        => 'password',
				/* translators: %s is this store's webhook URL. */
				'description' => sprintf( __( 'whsec_… secret for a live-mode webhook endpoint pointing at %s.', 'xpay-for-woocommerce' ), '<code>' . esc_html( $webhook_url ) . '</code>' ),
			),
			'display_heading'                   => array(
				'title'       => __( 'Checkout appearance', 'xpay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'XPay\'s payment fields appear on your checkout page. Which methods a shopper sees is decided by your XPay account, not here.', 'xpay-for-woocommerce' ),
			),
			'color_mode'                        => array(
				'title'       => __( 'Theme', 'xpay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'The payment fields are styled by your XPay dashboard branding. Automatic picks light or dark from your checkout page\'s own background. Choose Light or Dark to fix it.', 'xpay-for-woocommerce' ),
				'default'     => 'auto',
				'options'     => array(
					'auto'  => __( 'Automatic (match the page)', 'xpay-for-woocommerce' ),
					'light' => __( 'Always light', 'xpay-for-woocommerce' ),
					'dark'  => __( 'Always dark', 'xpay-for-woocommerce' ),
				),
			),
			'wpfunnels_heading'                 => array(
				'title'       => __( 'WPFunnels compatibility', 'xpay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Only relevant when the WPFunnels plugin is active.', 'xpay-for-woocommerce' ),
			),
			// Key must match XPay_WPFunnels_Compat::SETTING_KEY.
			'wpfunnels_force_standard_redirect' => array(
				'title'       => __( 'Confirmation page', 'xpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Force the standard order-received page after payment', 'xpay-for-woocommerce' ),
				'description' => __( 'WPFunnels reroutes the after-payment page into its funnel flow. Without a WPFunnels Pro upsell step, that bounces shoppers to the cart with no confirmation. Turn this on unless you run a working upsell flow. Applies to XPay orders only.', 'xpay-for-woocommerce' ),
				'default'     => 'no',
			),
			'debug'                             => array(
				'title'   => __( 'Diagnostic logging', 'xpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Write redacted diagnostic logs (WooCommerce → Status → Logs, source "xpay")', 'xpay-for-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Enabled and configured. The whole availability answer: the methods a
	 * shopper can pick are decided inside XPay's own fields, from what the
	 * merchant's XPay account has enabled.
	 */
	protected function base_available(): bool {
		// needs_setup() too: an enabled gateway with no keys can only dead-
		// end the shopper at "payment could not be started" — hiding the
		// XPay row (this check flows, via is_available, into Blocks) is the
		// honest state until keys exist.
		if ( 'yes' !== $this->get_option( 'enabled' ) || $this->needs_setup() ) {
			return false;
		}

		/*
		 * A store currency this ACCOUNT cannot charge would dead-end every
		 * shopper after Place Order — hiding the rows is the honest state.
		 * The list is the account's own, from GET /account at key save
		 * (merchant processors ∩ platform currencies with a rate to EGP),
		 * which is why the old machinery for the exchange_rate_not_found
		 * rejection is gone: a listed currency cannot meet it. The
		 * hardcoded platform enum (XPay_Money::DECIMALS) remains only as
		 * the fallback for a store whose keys were written around the
		 * save path (REST settings route), where no account was fetched.
		 */
		$map       = $this->account_methods_map();
		$supported = array() !== $map ? array_keys( $map ) : array_keys( XPay_Money::DECIMALS );
		return in_array( strtoupper( get_woocommerce_currency() ), array_map( 'strtoupper', $supported ), true );
	}

	/**
	 * The account's currency-to-methods map for the selected plane, as
	 * cached at key save. Empty when no account has been read yet (keys
	 * written around the save path) — every caller must treat empty as
	 * "unknown", never as "nothing".
	 *
	 * @return array<string, string[]> Uppercase currency code => method types.
	 */
	public function account_methods_map(): array {
		$map = get_option( XPay_Constants::account_methods_option( ! $this->is_test_mode() ), array() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$clean = array();
		foreach ( $map as $code => $types ) {
			if ( is_string( $code ) && '' !== $code && is_array( $types ) ) {
				$clean[ strtoupper( $code ) ] = array_values( array_filter( $types, 'is_string' ) );
			}
		}
		return $clean;
	}

	/**
	 * The method types this account can charge a given currency with, or
	 * null when no account map is cached — the callers' cue to fall back
	 * to the single unfiltered row rather than hiding everything.
	 *
	 * @param string $currency Currency code, any case.
	 * @return string[]|null
	 */
	public function methods_for_currency( string $currency ): ?array {
		$map = $this->account_methods_map();
		if ( array() === $map ) {
			return null;
		}
		return isset( $map[ strtoupper( $currency ) ] ) ? $map[ strtoupper( $currency ) ] : array();
	}

	/**
	 * Every method type the account can charge in ANY currency, in the
	 * account's own order (first seen wins). The raw material of the
	 * Payment Methods tab; empty when no account map is cached.
	 *
	 * @return string[]
	 */
	public function available_method_types(): array {
		$types = array();
		foreach ( $this->account_methods_map() as $methods ) {
			foreach ( $methods as $type ) {
				if ( ! in_array( $type, $types, true ) ) {
					$types[] = $type;
				}
			}
		}
		return $types;
	}

	/**
	 * The account's methods in the merchant's display order.
	 *
	 * The saved order (Payment Methods tab, drag to reorder) arranged
	 * against what the account can actually charge today: saved types the
	 * account has lost drop out, types it has gained append at the end in
	 * the account's own order — Stripe's self-healing rule for its
	 * stripe_upe_payment_method_order option. Healed IN MEMORY on every
	 * read and persisted only when the merchant saves an order, so a
	 * plane flip between accounts with different processors can never
	 * quietly rewrite the stored list.
	 *
	 * @return string[]
	 */
	public function ordered_method_types(): array {
		$available = $this->available_method_types();
		$saved     = get_option( XPay_Constants::OPTION_METHOD_ORDER, array() );
		$saved     = is_array( $saved ) ? array_values( array_filter( $saved, 'is_string' ) ) : array();

		$ordered = array_values( array_intersect( $saved, $available ) );
		foreach ( $available as $type ) {
			if ( ! in_array( $type, $ordered, true ) ) {
				$ordered[] = $type;
			}
		}
		return $ordered;
	}

	/**
	 * The methods this store OFFERS, in display order: the tab's checked
	 * list, intersected with what the account can charge.
	 *
	 * No stored list means every account method is offered — the state of
	 * every store that has never edited the tab (Stripe's default reads
	 * the same way). Once a list exists it is authoritative, INCLUDING
	 * when it no longer intersects the account: a merchant who checked
	 * only Card on an account that later lost its card processor gets an
	 * empty answer, and XPay hides from the checkout the same way an
	 * unsupported currency hides it. The tab
	 * still renders the account's methods, all unchecked, so checking
	 * one brings XPay back.
	 *
	 * @return string[]
	 */
	public function enabled_method_types(): array {
		$ordered = $this->ordered_method_types();
		$stored  = get_option( XPay_Constants::OPTION_ENABLED_METHODS, null );
		if ( ! is_array( $stored ) ) {
			return $ordered;
		}
		return array_values( array_intersect( $ordered, array_filter( $stored, 'is_string' ) ) );
	}

	/**
	 * What a session for a given currency may ACCEPT: the offered methods
	 * that can charge it, in display order. Null when no account map is
	 * cached — the fallback state, where nothing is pinned and the
	 * platform's own account default decides.
	 *
	 * This is the one list the whole feature hangs off: row availability
	 * reads membership, session create sends it as paymentMethodTypes,
	 * and session reuse supersedes when it changes.
	 *
	 * @param string $currency Currency code, any case.
	 * @return string[]|null
	 */
	public function accepted_types_for_currency( string $currency ): ?array {
		$chargeable = $this->methods_for_currency( $currency );
		if ( null === $chargeable ) {
			return null;
		}
		return array_values( array_intersect( $this->enabled_method_types(), $chargeable ) );
	}

	/**
	 * The method types that get their own checkout row beyond the card
	 * row: every non-card type the store offers, in display order.
	 * Per-currency availability is each row's own is_available —
	 * registration decides which rows EXIST.
	 *
	 * @return string[]
	 */
	public function method_row_types(): array {
		$types = array();
		foreach ( $this->enabled_method_types() as $type ) {
			if ( XPay_Payment_Methods::CARD !== $type ) {
				$types[] = $type;
			}
		}
		return $types;
	}

	/**
	 * True once the account map is cached: the checkout renders one row
	 * per method and this gateway presents itself as the Card row. False
	 * only for a store whose keys were written around the save path — the
	 * single unfiltered "XPay" row (with the method selector inside the
	 * fields) is the honest fallback there, and a key re-save converges it.
	 */
	public function method_rows_active(): bool {
		return array() !== $this->account_methods_map();
	}

	/**
	 * The checkout row's body: XPay's own payment fields, mounted here.
	 *
	 * With the account map cached this row IS the card row and mounts
	 * card-only fields; the other methods have rows of their own
	 * (XPay_Method_Gateway). Without the map it stays the single "XPay"
	 * row and the fields render the account's full method list themselves.
	 */
	public function payment_fields(): void {
		XPay_Checkout_Elements::render_mount( $this->method_rows_active() ? XPay_Payment_Methods::CARD : null );
	}

	public function is_available() {
		if ( ! $this->base_available() ) {
			return false;
		}
		// As the card row, hide when this store does not OFFER card for
		// this currency — the account cannot charge it, or the merchant
		// unchecked it on the Payment Methods tab. The no-map fallback
		// stays visible unfiltered.
		$accepted = $this->accepted_types_for_currency( get_woocommerce_currency() );
		return null === $accepted || in_array( XPay_Payment_Methods::CARD, $accepted, true );
	}

	/**
	 * Whether ONE method's row shows for the store's currency: the shared
	 * answer for the card row, the method rows and the Blocks 'active'
	 * flag, so the three can never disagree. False whenever no account
	 * map is cached — the fallback single row has no per-method rows.
	 *
	 * @param string $type Wire method type.
	 */
	public function method_active_for_currency( string $type ): bool {
		if ( ! $this->base_available() ) {
			return false;
		}
		$accepted = $this->accepted_types_for_currency( get_woocommerce_currency() );
		return null !== $accepted && in_array( $type, $accepted, true );
	}

	/**
	 * True when ANY XPay row can serve this store's currency: what decides
	 * whether the checkout scripts load at all. is_available() alone is
	 * the CARD row's answer, and a currency the account charges only
	 * through other methods still has rows to serve.
	 */
	public function offers_any_method(): bool {
		if ( ! $this->base_available() ) {
			return false;
		}
		$accepted = $this->accepted_types_for_currency( get_woocommerce_currency() );
		return null === $accepted || array() !== $accepted;
	}

	/**
	 * The row's shopper-facing title. As the card row it reads "Card" —
	 * the method names the row, exactly as on the ValU and Fawry rows;
	 * the merchant's title setting names the integration, not a method.
	 * The no-map fallback row keeps the merchant title, because it
	 * renders every method and a method name would be wrong there.
	 */
	public function get_title() {
		if ( ! is_admin() && $this->method_rows_active() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
			return apply_filters( 'woocommerce_gateway_title', XPay_Payment_Methods::label( XPay_Payment_Methods::CARD ), $this->id );
		}
		return parent::get_title();
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
	 * Whether this exact pair, on this exact plane, was already validated.
	 *
	 * Reads the same proof the settings screen reads for the Connected
	 * badge, so the badge and this decision can never disagree: if the
	 * screen is willing to call a merchant connected, there is nothing left
	 * to ask the API.
	 *
	 * A proof written before fingerprints existed carries none, and is not
	 * treated as proof of anything here — that merchant validates once more
	 * on their next save and converges.
	 *
	 * @param string $secret      Secret key for the selected mode.
	 * @param string $publishable Publishable key for the selected mode.
	 * @param bool   $test_mode   Plane the settings now select.
	 */
	private static function already_proved( string $secret, string $publishable, bool $test_mode ): bool {
		$proof = get_option( XPay_Constants::OPTION_KEY_VALIDATED );
		if ( ! is_array( $proof ) || ! isset( $proof['fingerprint'], $proof['mode'] ) ) {
			return false;
		}

		if ( ( $test_mode ? 'test' : 'live' ) !== $proof['mode'] ) {
			return false;
		}

		return hash_equals(
			(string) $proof['fingerprint'],
			XPay_Constants::key_fingerprint( $secret, $publishable )
		);
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
	 * As the Card row, the checkout shows the accepted networks at the
	 * row's far end (Stripe's card row does the same with its networks
	 * strip). The single-row fallback stays bare: a lone brand mark
	 * beside "Pay with XPay" would add noise, not information. The icon
	 * PROPERTY stays for the admin Payments page row either way.
	 */
	public function get_icon() {
		if ( ! is_admin() && $this->method_rows_active() ) {
			$icon = '<img src="' . esc_url( XPay_Payment_Methods::icon_url( XPay_Payment_Methods::CARD ) ) . '" class="xpay-method-icon xpay-method-icon--card" alt="' . esc_attr( XPay_Payment_Methods::label( XPay_Payment_Methods::CARD ) ) . '" />';
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
			return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
		}
		if ( XPay_Constants::GATEWAY_ID === $this->id ) {
			return '';
		}
		return parent::get_icon();
	}

	/* ── Payments-page row state ─────────────────────────────────────── */

	/*
	 * WooCommerce's Payments page probes gateways for the three methods
	 * below by name (PaymentsProviders\PaymentGateway) to pick each row's
	 * badge and button. Until "connected + started + completed" all hold,
	 * the row shows an "Action needed" badge with a primary "Complete
	 * setup" button pointing at this gateway's settings screen — where the
	 * guided activation takes over on a fresh install. Once all three hold,
	 * the row gets the normal Manage/Enable controls.
	 *
	 * The answers claim no more than the configuration proves: "connected"
	 * and "completed" are exactly what needs_setup() measures — the active
	 * mode holds both keys, so checkout can actually charge.
	 */

	/**
	 * Where WooCommerce's "Complete setup" button lands (PaymentsProviders
	 * probes for this method by name when building the row's onboarding
	 * link). Setup intent is explicit there, so it goes straight to the
	 * guided steps — skipping the welcome landing the settings screen
	 * greets a fresh install with.
	 *
	 * @param string $return_url Unused — setup happens entirely on our screen.
	 */
	/*
	 * The four methods below look unused and are not.
	 *
	 * WooCommerce's Payments settings screen duck-types them — it calls
	 * `method_exists( $gateway, 'is_account_connected' )` and friends before
	 * invoking them (`PaymentsProviders/PaymentGateway.php:536, 656, 691`,
	 * and `:815` for the connection URL). Nothing in this plugin calls them,
	 * so a search for callers finds none; deleting them silently downgrades
	 * how the gateway presents itself on that screen.
	 */

	public function get_connection_url( string $return_url = '' ): string {
		return add_query_arg( 'xpay-setup', '1', admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $this->id ) );
	}

	/** A processor account is "connected" when the active mode can charge. */
	public function is_account_connected(): bool {
		return ! $this->needs_setup();
	}

	/** Onboarding began once any key exists in either mode. */
	public function is_onboarding_started(): bool {
		$key_fields = array( 'test_api_key', 'test_publishable_key', 'live_api_key', 'live_publishable_key' );
		foreach ( $key_fields as $field ) {
			if ( '' !== (string) $this->get_option( $field ) ) {
				return true;
			}
		}
		return false;
	}

	/** Onboarding is complete when the active mode can charge. */
	public function is_onboarding_completed(): bool {
		return ! $this->needs_setup();
	}

	/**
	 * Guard against the half-configured state: a key that does not match
	 * the selected mode (only reachable when settings were written around
	 * Connect, over the REST settings route for example) is caught at save
	 * time with a specific message, and every key is validated with a real
	 * API call before the merchant can rely on it.
	 */
	public function process_admin_options() {
		/*
		 * Keep the fields this screen did not render.
		 *
		 * WooCommerce writes EVERY declared field from the POST, so a field
		 * absent from the page is saved as empty, and the settings screen
		 * shows a different subset in each of its states. Without restoring
		 * the missing values here, a save made from one state wipes the live
		 * secret key and the webhook signing secret.
		 *
		 * Checkboxes are deliberately excluded: for them, absence genuinely
		 * means "off", and a checkbox carries no secret.
		 */
		$before = get_option( $this->get_option_key(), array() );
		$before = is_array( $before ) ? $before : array();

		$restore = array();
		foreach ( $this->get_form_fields() as $key => $field ) {
			$type = $this->get_field_type( $field );
			if ( 'title' === $type || 'checkbox' === $type ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only; WooCommerce verifies the settings nonce before this method runs, and no value is read here.
			if ( ! isset( $_POST[ $this->get_field_key( $key ) ] ) && isset( $before[ $key ] ) ) {
				$restore[ $key ] = $before[ $key ];
			}
		}

		$restorer = static function ( $settings ) use ( $restore ) {
			return is_array( $settings ) ? array_merge( $settings, $restore ) : $settings;
		};
		add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $restorer );

		$saved = parent::process_admin_options();

		remove_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $restorer );

		// The parent wrote the merged values to the option, but this
		// instance's copy still holds the blanks it computed from the POST.
		$this->init_settings();

		/*
		 * Retire endpoints whose creating key just changed or vanished,
		 * BEFORE anything else can fail this save: each plane's recorded
		 * endpoint is deleted with the key that created it, and its stored
		 * secret, record and health state go with it. The plane whose key
		 * did not move is untouched. Best-effort by design — an orphaned
		 * endpoint at XPay is recoverable, a blocked save is not.
		 */
		$after_decommission = XPay_Webhook_Configurator::decommission_after_key_update( get_option( $this->get_option_key(), array() ) );
		update_option( $this->get_option_key(), $after_decommission );
		$this->init_settings();

		$this->save_enabled_methods();

		foreach ( $this->validate_and_provision()['notices'] as $notice ) {
			if ( 'error' === $notice['type'] ) {
				WC_Admin_Settings::add_error( $notice['text'] );
			} else {
				WC_Admin_Settings::add_message( $notice['text'] );
			}
		}

		return $saved;
	}

	/**
	 * Validate the active mode's keys with a real API call and provision
	 * everything that hangs off a proof: the fingerprint proof itself, the
	 * account caches, and the webhook endpoint.
	 *
	 * Separated from process_admin_options because admin notices are the
	 * settings form's output channel, not validation's: this method reports
	 * what happened and the caller decides how to show it. The rule it
	 * exists to keep: ANY path that writes keys into the settings must run
	 * exactly this afterwards — validation living inside the form handler
	 * is how keys written around the form end up unvalidated (the
	 * REST-route gap the fingerprint proof guards).
	 *
	 * @return array{connected: bool, notices: array<int, array{type: string, text: string}>}
	 *               connected: the active mode's keys are proved (now or
	 *               already). notices: what to tell the merchant, in order;
	 *               type is 'error' or 'message'.
	 */
	public function validate_and_provision(): array {
		$notices = array();

		// Every unsuccessful validation clears the saved proof.
		$key = $this->api_key();
		if ( '' === $key ) {
			// The one state guaranteed to hide checkout must not be the one
			// state that saves without a word.
			delete_option( XPay_Constants::OPTION_KEY_VALIDATED );
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'XPay: no API key is saved for the selected mode, so XPay stays hidden at checkout until you connect it.', 'xpay-for-woocommerce' ),
			);
			return array(
				'connected' => false,
				'notices'   => $notices,
			);
		}

		$mode_is_live = ! $this->is_test_mode();
		$key_is_live  = XPay_Api_Client::is_live_key( $key );
		if ( $key_is_live && ! $mode_is_live ) {
			delete_option( XPay_Constants::OPTION_KEY_VALIDATED );
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'XPay: the stored key for the selected mode is a LIVE key but the gateway is in Test mode. Connect the mode you selected from the connection dialog.', 'xpay-for-woocommerce' ),
			);
			return array(
				'connected' => false,
				'notices'   => $notices,
			);
		}
		if ( ! $key_is_live && $mode_is_live ) {
			delete_option( XPay_Constants::OPTION_KEY_VALIDATED );
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'XPay: the stored key for the selected mode is a TEST key but the gateway is in Live mode. Connect the mode you selected from the connection dialog.', 'xpay-for-woocommerce' ),
			);
			return array(
				'connected' => false,
				'notices'   => $notices,
			);
		}

		/*
		 * The publishable key rides in the browser and picks its own plane
		 * the same way. The emptiness guard in front of it is the whole
		 * point: is_live_key( '' ) is false (`class-xpay-api-client.php:54`),
		 * so a merchant with a live secret and an empty publishable field
		 * would otherwise be told their key is a TEST key — sending them off
		 * to fix something that is not wrong.
		 */
		$publishable = $this->publishable_key();
		if ( '' === $publishable ) {
			delete_option( XPay_Constants::OPTION_KEY_VALIDATED );
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'XPay: no publishable key is saved for the selected mode. The payment fields cannot load without it.', 'xpay-for-woocommerce' ),
			);
			return array(
				'connected' => false,
				'notices'   => $notices,
			);
		}
		if ( XPay_Api_Client::is_live_key( $publishable ) !== $mode_is_live ) {
			delete_option( XPay_Constants::OPTION_KEY_VALIDATED );
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'XPay: the stored publishable key does not belong to the mode you selected. Connect this mode again to store a matching pair.', 'xpay-for-woocommerce' ),
			);
			return array(
				'connected' => false,
				'notices'   => $notices,
			);
		}

		/*
		 * A key that has already been proved is not proved again.
		 *
		 * Without this, a merchant renaming the payment method or toggling
		 * diagnostic logging asks XPay whether keys they never touched still
		 * work: a request per save forever, on a route whose whole purpose
		 * is to answer a question already answered.
		 *
		 * Both halves have to match: the fingerprint covers the pair of
		 * keys, and the mode covers a merchant flipping test/live with the
		 * same pair saved, where the old proof says nothing about the new
		 * plane.
		 */
		if ( self::already_proved( $key, $publishable, $this->is_test_mode() ) ) {
			/*
			 * Proved keys are not re-validated — but the account FACTS this
			 * screen caches (chargeable currencies, merchant id) can change
			 * at XPay without the keys moving, and a save is the merchant's
			 * one deliberate refresh moment. So the account is still read,
			 * silently and best-effort: no notice on success (nothing about
			 * the keys was re-proved) and none on failure (the caches keep
			 * their last good values). Without this, "re-save your keys to
			 * pick up a new currency" — the documented refresh — did
			 * nothing, because this guard returned first.
			 */
			$this->client = null;
			try {
				$account = $this->api_client()->get_account();
				$this->cache_account_facts( $account );
				// Heal quietly: a store whose endpoint was never created
				// (or was deleted at XPay's side and decommissioned here)
				// gets one on the next save. The webhook notice is
				// discarded — nothing was re-validated, and no notice
				// should second-guess keys nobody touched.
				$this->maybe_configure_webhook( $account );
			} catch ( XPay_Api_Exception $e ) {
				unset( $e );
			}
			return array(
				'connected' => true,
				'notices'   => array(),
			);
		}

		/*
		 * One question, asked once, when the keys change: GET /account.
		 *
		 * The self-describing endpoint answers everything the old
		 * empty-POST probe inferred from status codes, plus what it could
		 * not: the key's effective permission set (a mis-scoped restricted
		 * key is named field by field instead of guessed at), the
		 * currencies this account can actually charge in (cached below,
		 * the availability gate reads them), the merchant id for dashboard
		 * deep links, and live activation as a fact — an
		 * unactivated live key answers 200 with livePaymentsEnabled false,
		 * never merchant_not_activated.
		 *
		 * Nothing asks again afterwards. A key revoked at XPay later
		 * announces itself where it matters — orders stop completing — and
		 * polling for it was machinery this plugin does not need to carry.
		 */
		$this->client = null; // Re-validate with the freshly saved key.

		try {
			$account = $this->api_client()->get_account();
		} catch ( XPay_Api_Exception $e ) {
			$status = $e->get_http_status();

			delete_option( XPay_Constants::OPTION_KEY_VALIDATED );

			if ( 401 === $status ) {
				$notices[] = array(
					'type' => 'error',
					'text' => __( 'XPay: the stored secret key was refused. It may have been revoked in your XPay dashboard. Connect again to store a fresh key.', 'xpay-for-woocommerce' ),
				);
			} elseif ( 403 === $status && XPay_Error_Codes::API_PERMISSION_DENIED === $e->get_error_code() ) {
				// The endpoint requires no permission, so a 403 here means
				// the key TYPE is wrong: a publishable key in the secret
				// field.
				$notices[] = array(
					'type' => 'error',
					'text' => __( 'XPay: the stored key cannot act for your account. It looks like a publishable key, which belongs in the browser, not on the server. Connect again to store the right keys.', 'xpay-for-woocommerce' ),
				);
			} else {
				/*
				 * XPay did not answer, or answered from in front of the API
				 * (5xx, rate limit): this store has learned nothing. The keys
				 * are saved and the screen simply will not claim a connection
				 * it has not seen — a message, not an error, because nothing
				 * here is the merchant's to fix.
				 */
				$notices[] = array(
					'type' => 'message',
					'text' => __( 'XPay: settings saved. XPay could not be reached just now, so the connection is unconfirmed. Reload this page in a moment to check it.', 'xpay-for-woocommerce' ),
				);
			}
			return array(
				'connected' => false,
				'notices'   => $notices,
			);
		}

		/*
		 * The two permissions this plugin cannot work without: opening
		 * checkout sessions is every payment, and refunds are the order
		 * screen's refund button. Secret keys carry every permission;
		 * restricted keys carry what was ticked at creation. Missing ones
		 * are named, so the merchant edits the right checkboxes instead of
		 * guessing from a status code.
		 */
		$permissions = array();
		if ( isset( $account['apiKey']['permissions'] ) && is_array( $account['apiKey']['permissions'] ) ) {
			$permissions = array_map( 'strval', $account['apiKey']['permissions'] );
		}
		$required = array(
			'CHECKOUT_SESSIONS_WRITE' => __( 'Checkout Sessions (write)', 'xpay-for-woocommerce' ),
			'REFUNDS_WRITE'           => __( 'Refunds (write)', 'xpay-for-woocommerce' ),
		);
		$missing  = array_values( array_diff_key( $required, array_flip( $permissions ) ) );
		if ( array() !== $missing ) {
			delete_option( XPay_Constants::OPTION_KEY_VALIDATED );
			$notices[] = array(
				'type' => 'error',
				'text' => sprintf(
					/* translators: %s is a comma-separated list of permission names, for example "Checkout Sessions (write), Refunds (write)". */
					__( 'XPay: this key is missing: %s. Connect again from the settings screen to mint a key with everything the plugin needs.', 'xpay-for-woocommerce' ),
					implode( ', ', $missing )
				),
			);
			return array(
				'connected' => false,
				'notices'   => $notices,
			);
		}

		// Persist proof for the settings screen's Connected badge.
		update_option(
			XPay_Constants::OPTION_KEY_VALIDATED,
			array(
				'mode'         => $this->is_test_mode() ? 'test' : 'live',
				'validated_at' => time(),
				// Which pair was proved, without storing either key.
				// This is the ONLY control that notices settings written
				// through the REST settings route, which never calls
				// this method: without it a merchant could swap in an
				// unvalidated key and keep a green badge that is proof
				// of a different key entirely.
				'fingerprint'  => XPay_Constants::key_fingerprint( $key, $publishable ),
			),
			false
		);

		$this->cache_account_facts( $account );

		// Keys proved: the webhook sets itself up for this plane, loudly —
		// success and every fallback report through the returned notice.
		$webhook_notice = $this->maybe_configure_webhook( $account );
		if ( null !== $webhook_notice ) {
			$notices[] = array(
				'type' => 'message',
				'text' => $webhook_notice,
			);
		}

		$live = ! $this->is_test_mode();
		if ( $live && isset( $account['livePaymentsEnabled'] ) && false === $account['livePaymentsEnabled'] ) {
			// A real key on an account XPay has not activated yet: the
			// badge may say connected, and the merchant deserves to know
			// payments stay off until activation — as a fact, not a fault.
			$notices[] = array(
				'type' => 'message',
				'text' => __( 'XPay connected (live mode). Your account is not activated for live payments yet, so payments stay off until XPay activates it. Test mode works fully in the meantime.', 'xpay-for-woocommerce' ),
			);
		} else {
			$notices[] = array(
				'type' => 'message',
				'text' => $this->is_test_mode()
					? __( 'XPay connected (test mode).', 'xpay-for-woocommerce' )
					: __( 'XPay connected (live mode).', 'xpay-for-woocommerce' ),
			);
		}

		return array(
			'connected' => true,
			'notices'   => $notices,
		);
	}

	/**
	 * Persist the Payment Methods tab's checked list from the settings
	 * form. Runs only when the tab was actually on the page (its marker
	 * field posted): a save made from a state that never rendered the
	 * checkboxes must not read their absence as "the merchant unchecked
	 * everything" — the same rule the unrendered-field restore above
	 * applies to the declared fields.
	 *
	 * All-unchecked is refused, not saved: a checkout with zero XPay
	 * methods is the Enable switch's job, and reaching it through
	 * checkboxes would look like a bug, not a choice. The previous list
	 * stands and the merchant is told.
	 */
	private function save_enabled_methods(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the settings nonce before process_admin_options ran; this method is only called from it.
		if ( ! isset( $_POST['xpay_methods_present'] ) ) {
			return;
		}

		$posted = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		if ( isset( $_POST['xpay_method_enabled'] ) && is_array( $_POST['xpay_method_enabled'] ) ) {
			// is_string first: a forged nested array would fatal sanitize_key.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; values are unslashed, restricted to strings, and sanitized below.
			$posted = array_map( 'sanitize_key', array_filter( wp_unslash( $_POST['xpay_method_enabled'] ), 'is_string' ) );
		}

		// Only types the account actually has: anything else in the POST
		// is a stale page or a forged value, and neither belongs stored.
		$enabled = array_values( array_intersect( $this->ordered_method_types(), $posted ) );

		if ( array() === $enabled ) {
			WC_Admin_Settings::add_error( __( 'XPay: keep at least one payment method on. Your payment method changes were not saved. To take XPay off the checkout entirely, turn off Enable XPay instead.', 'xpay-for-woocommerce' ) );
			return;
		}

		update_option( XPay_Constants::OPTION_ENABLED_METHODS, $enabled, false );
	}

	/**
	 * Cache what the account response states, for this key's plane: the
	 * merchant id (dashboard deep links) and the currencies the account
	 * can actually charge in (the availability gate reads them). The
	 * account already intersects the merchant's enabled processors with
	 * platform currencies holding a rate to EGP, so a listed currency
	 * cannot meet exchange_rate_not_found at session create. Refreshed at
	 * settings save, from the admin refresh action, and by the checkout's
	 * quiet re-read once the cache outlives ACCOUNT_CACHE_SECONDS; never
	 * per page load.
	 *
	 * @param array $account Decoded GET /account response.
	 */
	private function cache_account_facts( array $account ): void {
		$live = ! $this->is_test_mode();

		// Every successful read restarts the cache's shelf life, whichever
		// path fetched it (key save, admin refresh, the checkout's quiet
		// re-read).
		update_option( XPay_Constants::account_checked_option( $live ), time(), false );

		if ( isset( $account['id'] ) && is_string( $account['id'] ) && '' !== $account['id'] ) {
			update_option( XPay_Constants::merchant_id_option( $live ), $account['id'], false );
		}
		if ( isset( $account['displayName'] ) && is_string( $account['displayName'] ) && '' !== $account['displayName'] ) {
			update_option( 'xpay_wc_merchant_name_' . ( $live ? 'live' : 'test' ), $account['displayName'], false );
		}

		// Currency code => the method types that can charge it, in the
		// account's own order. Both availability gates read this: the
		// store-currency gate its keys, the per-method rows its values.
		$map = array();
		if ( isset( $account['supportedCurrencies'] ) && is_array( $account['supportedCurrencies'] ) ) {
			foreach ( $account['supportedCurrencies'] as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['code'] ) || ! is_string( $row['code'] ) || '' === $row['code'] ) {
					continue;
				}
				$types = array();
				if ( isset( $row['paymentMethodTypes'] ) && is_array( $row['paymentMethodTypes'] ) ) {
					$types = array_values( array_unique( array_filter( $row['paymentMethodTypes'], 'is_string' ) ) );
				}
				$map[ strtoupper( $row['code'] ) ] = $types;
			}
		}
		if ( array() !== $map ) {
			update_option( XPay_Constants::account_methods_option( $live ), $map, false );
			// The row set may have changed with the map; put the checkout's
			// gateway ordering back in step (new rows would otherwise land
			// at the very bottom, below every other plugin's gateways).
			XPay_Plugin::sync_gateway_order();
		}

		// Live activation, as a cached FACT for the status card's badge.
		// Present-and-false is the only state that claims a problem;
		// absent stays silent.
		if ( $live ) {
			if ( isset( $account['livePaymentsEnabled'] ) && false === $account['livePaymentsEnabled'] ) {
				update_option( 'xpay_wc_live_payments_disabled', '1', false );
			} else {
				delete_option( 'xpay_wc_live_payments_disabled' );
			}
		}
	}

	/**
	 * The status card's "Refresh account details": re-cache every account
	 * fact and quietly heal the webhook, from an already-fetched account.
	 *
	 * @param array $account Decoded GET /account response.
	 */
	public function refresh_account_facts( array $account ): void {
		$this->cache_account_facts( $account );
		$this->maybe_configure_webhook( $account );
	}

	/**
	 * How long the cached account facts stay trusted before the checkout
	 * quietly re-reads them: Stripe's number for the same cache
	 * (ACCOUNT_CACHE_EXPIRATION, class-wc-stripe-account.php:25).
	 *
	 * Bounding staleness keeps the account method list from becoming a
	 * second source of truth.
	 */
	const ACCOUNT_CACHE_SECONDS = 2 * HOUR_IN_SECONDS;

	/**
	 * Re-read the account once the cache is older than its shelf life.
	 *
	 * Called from the checkout's asset pass, so it runs where the cached
	 * facts are about to decide what a shopper can pay with — including
	 * when they currently hide every row, which is exactly the state a
	 * stale cache must be able to heal out of. Quiet and best-effort:
	 * on failure the caches keep their last good values, and the attempt
	 * is stamped BEFORE the call so a down API costs one bounded-timeout
	 * request per window, never one per page view.
	 *
	 * The webhook heal that rides the admin refresh paths deliberately
	 * does not ride this one: creating endpoints from a shopper-facing
	 * render is write traffic no checkout should carry.
	 */
	public function maybe_refresh_account_facts(): void {
		if ( $this->needs_setup() ) {
			return;
		}

		$option  = XPay_Constants::account_checked_option( ! $this->is_test_mode() );
		$checked = (int) get_option( $option, 0 );
		if ( time() - $checked < self::ACCOUNT_CACHE_SECONDS ) {
			return;
		}
		update_option( $option, time(), false );

		try {
			$account = $this->api_client()->get_account( XPay_Api_Client::SHOPPER_READ_TIMEOUT_SECONDS );
		} catch ( XPay_Api_Exception $e ) {
			XPay_Logger::event( 'account.refresh_failed', array( 'code' => $e->get_error_code() ) );
			return;
		}

		$this->cache_account_facts( $account );
		XPay_Logger::event( 'account.refreshed', array( 'live_mode' => ! $this->is_test_mode() ) );
	}

	/**
	 * Create this plane's webhook endpoint when the saved key can, and say
	 * exactly where things stand when it cannot.
	 *
	 * Runs on every save that read the account. It creates only when
	 * something actually needs creating: no endpoint recorded, or the
	 * recorded one was created by a DIFFERENT key (the decommission pass
	 * has then already retired it). A save that changes nothing about the
	 * keys does nothing here.
	 *
	 * Never fails the save. The manual signing-secret field remains the
	 * working fallback for every path out of here.
	 *
	 * Reports through its return value rather than printing: the silent
	 * paths (the proved-keys refresh, the admin refresh action) discard it,
	 * because nothing there was re-validated and no notice should
	 * second-guess keys nobody touched.
	 *
	 * @param array $account Decoded GET /account response for the saved key.
	 * @return string|null The notice to show the merchant, or null when
	 *                     nothing needed doing.
	 */
	private function maybe_configure_webhook( array $account ): ?string {
		$live = ! $this->is_test_mode();
		$key  = $this->api_key();

		$recorded = XPay_Webhook_Configurator::endpoint_data( $live );
		if ( null !== $recorded && isset( $recorded['secret'] ) && $recorded['secret'] === $key ) {
			return null; // This key's endpoint already stands.
		}

		$permissions = array();
		if ( isset( $account['apiKey']['permissions'] ) && is_array( $account['apiKey']['permissions'] ) ) {
			$permissions = array_map( 'strval', $account['apiKey']['permissions'] );
		}
		if ( ! in_array( XPay_Webhook_Configurator::REQUIRED_PERMISSION, $permissions, true ) ) {
			// Only reachable with a key written around Connect (Connect
			// mints its keys with this permission): name the way out.
			return __( 'XPay: this key cannot manage webhooks, so order confirmations are not connected. Use Connect with XPay on the settings screen to get a key that can, or allow Webhook Endpoints (write) on this key in your XPay dashboard and save again.', 'xpay-for-woocommerce' );
		}

		try {
			XPay_Webhook_Configurator::configure( $key );
			$this->init_settings(); // The secret was written into this option.
			return __( 'XPay: webhook set up automatically. Order confirmations are connected, with nothing to paste.', 'xpay-for-woocommerce' );
		} catch ( XPay_Api_Exception $e ) {
			XPay_Logger::error( 'webhook.configure_failed', array( 'code' => $e->get_error_code() ) );
			return __( 'XPay: the webhook could not be set up automatically just now. Use Reconfigure webhook in the connection dialog to retry.', 'xpay-for-woocommerce' );
		}
	}

	/**
	 * The Manage screen: the get-started card, the account status card and
	 * the settings form (XPay_Admin_Screen). Every control posts the
	 * standard woocommerce_xpay_* field names, so process_admin_options
	 * and storage are untouched — this override is presentation only.
	 */
	public function admin_options() {
		/*
		 * Heal the checkout's gateway ordering whenever the screen is
		 * looked at. The other sync triggers (key save, plugin update, the
		 * option's own update hook) can all lie in the past for an
		 * existing store — version already stamped, keys long saved —
		 * while woocommerce_gateway_order still carries entries from an
		 * older install that put the rows in the wrong order. The screen
		 * showing the merchant an order is the moment it must be true.
		 * Cheap: nothing is written unless the option actually differs.
		 */
		XPay_Plugin::sync_gateway_order();
		XPay_Admin_Screen::render( $this );
	}

	/**
	 * Make the payment id on the order a link to the payment.
	 *
	 * Overridden rather than set as `$this->view_transaction_url` because
	 * the URL needs the merchant's account segment, which is learned from an
	 * API response rather than configured, so it may arrive after the
	 * gateway was constructed.
	 *
	 * Falls back to core's behaviour (which yields nothing when no template
	 * is set) if the merchant id has not been seen yet — an unlinked id is
	 * better than a link to a 404.
	 *
	 * @param WC_Order $order Order being viewed.
	 * @return string
	 */
	public function get_transaction_url( $order ) {
		$intent = (string) $order->get_meta( XPay_Constants::META_PAYMENT_INTENT );
		if ( '' === $intent ) {
			$intent = (string) $order->get_transaction_id();
		}

		$url = XPay_Constants::payment_dashboard_url( $intent, ! $this->is_test_mode() );
		if ( '' === $url ) {
			return parent::get_transaction_url( $order );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
		return apply_filters( 'woocommerce_get_transaction_url', $url, $order, $this );
	}

	/* ── Checkout flow ───────────────────────────────────────────────── */

	/**
	 * The accepted-method list a SESSION for this currency should carry,
	 * or null to omit the field and let the account default decide.
	 *
	 * Null only when no account map is cached. An empty intersection stays
	 * empty so a forged or stale checkout cannot bypass the merchant's list.
	 *
	 * @param string $currency The order's currency.
	 * @return string[]|null
	 */
	public function accepted_types_for_session( string $currency ): ?array {
		return $this->accepted_types_for_currency( $currency );
	}

	/** Refresh the account and return the current safe session allow-list. */
	public function refresh_accepted_types_for_session( string $currency ): array {
		$account = $this->api_client()->get_account( XPay_Api_Client::SHOPPER_READ_TIMEOUT_SECONDS );
		$this->cache_account_facts( $account );

		$chargeable = array();
		if ( isset( $account['supportedCurrencies'] ) && is_array( $account['supportedCurrencies'] ) ) {
			foreach ( $account['supportedCurrencies'] as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['code'], $row['paymentMethodTypes'] ) || strtoupper( (string) $row['code'] ) !== strtoupper( $currency ) || ! is_array( $row['paymentMethodTypes'] ) ) {
					continue;
				}
				$chargeable = array_values( array_unique( array_filter( $row['paymentMethodTypes'], 'is_string' ) ) );
				break;
			}
		}

		return array_values( array_intersect( $this->enabled_method_types(), $chargeable ) );
	}

	/**
	 * @param int $order_id Order being paid.
	 * @return array result/redirect pair per the gateway contract.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		/*
		 * The order exists now, and the shopper's payment fields are still
		 * mounted on the checkout page in front of them. All this has to do
		 * is hand back a session to confirm against.
		 *
		 * ONE SESSION PER CHECKOUT: get_or_create_session is get-or-create
		 * per ORDER. A retry on an unchanged cart resumes the same order,
		 * so it reuses the same session and the same clientSecret, and the
		 * whole transaction — declines, retries, the final charge — stays on
		 * one Payment Intent. A changed total reprices that session in
		 * place. Only an expired or missing session mints a new one. See
		 * XPay_Checkout_Service::get_or_create_session.
		 *
		 * No adoption, and no session before the order: the session is born
		 * carrying wc_order_id and wc_order_key, so every webhook can find
		 * its order from the first event onward.
		 */
		try {
			$service = new XPay_Checkout_Service(
				$this->api_client(),
				$this->accepted_types_for_session( $order->get_currency() ),
				array( $this, 'refresh_accepted_types_for_session' )
			);
		} catch ( XPay_Api_Exception $e ) {
			/*
			 * Built here rather than inside the try below, because
			 * XPay_Api_Client's constructor throws on an empty key
			 * (class-xpay-api-client.php:42). is_available() should keep an
			 * unconfigured gateway off the checkout entirely, so reaching
			 * this means the keys were cleared while a shopper was checking
			 * out. Rare, and still not a fatal.
			 */
			XPay_Logger::event(
				'process_payment.failed',
				array(
					'order_id' => $order_id,
					'code'     => $e->get_error_code(),
				)
			);
			wc_add_notice( __( 'The payment could not be started. Please try again. Your card has not been charged.', 'xpay-for-woocommerce' ), 'error' );
			$order->add_order_note(
				sprintf(
					/* translators: 1: XPay error code, 2: error message. */
					__( 'XPay could not be reached [%1$s]: %2$s', 'xpay-for-woocommerce' ),
					$e->get_error_code(),
					$e->getMessage()
				)
			);
			return array( 'result' => 'failure' );
		}

		/*
		 * One writer per order. Two tabs on one cart, or a shopper who
		 * double-submits, would otherwise both reach the reuse check, both
		 * read the same stored session, and race to reprice or supersede
		 * it. Non-fatal if it cannot be had: XPay_Order_Lock answers true
		 * when the host has no GET_LOCK at all, and the platform's own
		 * idempotency keys still collapse duplicate writes.
		 */
		$locked = XPay_Order_Lock::acquire( (int) $order->get_id(), 5 );

		try {
			$session = $service->get_or_create_session( $order );
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
			wc_add_notice( __( 'The payment could not be started. Please try again. Your card has not been charged.', 'xpay-for-woocommerce' ), 'error' );
			$order->add_order_note(
				sprintf(
					/* translators: 1: XPay error code, 2: error message. */
					__( 'XPay session creation failed [%1$s]: %2$s', 'xpay-for-woocommerce' ),
					$e->get_error_code(),
					$e->getMessage()
				)
			);
			return array( 'result' => 'failure' );
		} finally {
			if ( $locked ) {
				XPay_Order_Lock::release( (int) $order->get_id() );
			}
		}

		// A COMPLETE session back from the session check means this order
		// was already paid (stale pay link, webhook still in flight) and
		// the check just applied it. Offering to charge again is not an
		// option; the confirmation page is the honest destination.
		if ( isset( $session['status'] ) && XPay_Session_Status::COMPLETE === $session['status'] ) {
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		$client_secret = isset( $session['clientSecret'] ) ? (string) $session['clientSecret'] : '';
		if ( '' === $client_secret ) {
			// Nothing to confirm against. Never fall through to a redirect
			// that would look like success.
			XPay_Logger::error(
				'process_payment.no_client_secret',
				array(
					'order_id'   => $order_id,
					'session_id' => isset( $session['id'] ) ? (string) $session['id'] : '',
				)
			);
			wc_add_notice( __( 'The payment could not be started. Please try again. Your card has not been charged.', 'xpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Stock is NOT reduced and the cart is NOT emptied here — both wait
		// for payment_complete(), so an abandoned payment never strands
		// stock.
		return array(
			'result'         => 'success',
			// The browser confirms BEFORE following this. Both drivers read
			// the flag below; a checkout that cannot see it simply
			// navigates, and the pay page picks the payment up there.
			'redirect'       => $order->get_checkout_order_received_url(),
			// Store API serialises payment_details as key/value STRINGS
			// (CheckoutSchema.php:171), so this is 'yes', not true.
			'xpay_confirm'   => 'yes',
			// The session to confirm against. It goes to the shopper's own
			// browser, which is the only party that needs it, and it is
			// useless without the publishable key already on the page.
			'xpay_secret'    => $client_secret,
			'xpay_order_id'  => (string) $order->get_id(),
			/*
			 * The order's own access token, so the outcome endpoint can
			 * tell this shopper's order from any other id posted to it.
			 * WooCommerce treats the key as the proof of access to an order
			 * for a guest, which is what this is: the confirm happens
			 * before the shopper has an account or a session naming the
			 * order.
			 */
			'xpay_order_key' => (string) $order->get_order_key(),
		);
	}

	/**
	 * Order-pay receipt body: the same deferred element, one fixed amount.
	 *
	 * No session is created here — that is the whole deferred contract.
	 * The pay-page driver asks the order_session endpoint at Pay, which
	 * runs the same get-or-create-per-order discipline as the checkout and
	 * answers "already paid" for a stale link. Assets come from
	 * XPay_Checkout_Elements::enqueue(), which serves this endpoint too.
	 *
	 * @param int $order_id Order being paid.
	 */
	public function receipt_page( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $order->is_paid() ) {
			echo '<p>' . esc_html__( 'This order has already been paid.', 'xpay-for-woocommerce' ) . ' <a href="' . esc_url( $order->get_checkout_order_received_url() ) . '">' . esc_html__( 'View your order confirmation', 'xpay-for-woocommerce' ) . '</a></p>';
			return;
		}

		XPay_Checkout_Elements::render_mount();
		echo '<button type="button" class="button alt" data-xpay-pay>' . esc_html__( 'Pay now', 'xpay-for-woocommerce' ) . '</button>';
	}

	/* ── Refunds ─────────────────────────────────────────────────────── */

	/**
	 * Whether to offer the "Refund via XPay" button on this order.
	 *
	 * Core's own control is the generic Refund button next to it, which it
	 * shows while EITHER money or unrefunded items remain
	 * (html-order-items.php:22). That `or` is deliberate — a zero-value
	 * refund is how a merchant records returned goods and puts stock back —
	 * so it stays as it is. What this decides is narrower: whether the
	 * PRIMARY button, the one that moves money at XPay, is worth offering.
	 *
	 * Three ways it is not, and all three currently render a confident
	 * button that can only produce an error:
	 *
	 *   1. Nothing left. WooCommerce keeps the ledger and this reads it
	 *      rather than asking XPay, because this runs on every order-screen
	 *      render and a network call there would slow the whole admin for a
	 *      figure most visits never need. The ledger stays truthful because
	 *      dashboard refunds mirror back through charge.refunded, and a
	 *      refund the ledger got wrong anyway is refused by the platform at
	 *      submit with a message naming why.
	 *   2. No payment intent recorded, so there is nothing to refund
	 *      against; XPay_Refund_Service throws not_configured on sight.
	 *   3. Not currency. A store priced in something other than EGP can be
	 *      refunded in full OR in part. A full refund leaves the amount
	 *      unstated and the platform returns the whole remaining balance;
	 *      a partial one is converted at the rate the session locked
	 *      (XPay_Fx) and checked against the presentment mirror before it
	 *      is sent. Currency is not a reason to withhold the button.
	 *
	 * @param WC_Order|false $order Order being viewed.
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		if ( ! $order instanceof WC_Order || ! $this->supports( 'refunds' ) ) {
			return false;
		}

		if ( '' === (string) $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ) ) {
			return false;
		}

		return 0 < (float) $order->get_total() - (float) $order->get_total_refunded();
	}

	/**
	 * Refund through XPay. Called by WooCommerce's own refund UI.
	 *
	 * @param int    $order_id Order id.
	 * @param float  $amount   Amount to refund, in the order's currency.
	 * @param string $reason   Admin-entered reason.
	 * @return bool|WP_Error True, or the reason it could not be taken.
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
