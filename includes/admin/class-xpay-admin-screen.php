<?php
/**
 * XPay_Admin_Screen
 *
 * The gateway's settings screen, in Stripe's shape: a "Get started" card
 * until an account is connected, then a status card whose badges answer
 * from real truth sources (a key proved by an actual API call, a webhook
 * the plugin set up, an event that verified) and a plain settings form.
 * A connection dialog carries one action panel per mode — diagram,
 * badges, Connect and Reconfigure webhook — never a form.
 *
 * The three-step gated wizard this replaces waited on proofs a merchant
 * could not produce from where they stood (its second step waited for a
 * webhook event that only its third step's test payment could cause).
 * Nothing here gates: connecting is ONE action — Connect with XPay —
 * and the callback validates the delivered keys and creates the webhook
 * endpoint. No key input exists anywhere on the screen: a pasted key
 * would be a second, unvalidated way in, and the exact field a phishing
 * overlay would imitate. The health line reports; it never blocks.
 *
 * Everything posts the standard woocommerce_xpay_* field names into
 * WooCommerce's own settings form, so saving, sanitization and
 * process_admin_options are untouched: this class is presentation plus
 * the small AJAX verbs the status card needs (refresh the health line,
 * refresh account facts, reconfigure a webhook, disconnect a mode).
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Admin_Screen {

	/** Nonce for the status card's AJAX verbs. */
	const NONCE_ACTION = 'xpay-admin-screen';

	/** The AJAX verbs, each registered as wp_ajax_xpay_admin_{verb}. */
	const AJAX_VERBS = array( 'health', 'refresh_account', 'reconfigure_webhooks', 'disconnect', 'save_method_order', 'connect' );

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		foreach ( self::AJAX_VERBS as $verb ) {
			add_action( 'wp_ajax_xpay_admin_' . $verb, array( __CLASS__, 'handle_' . $verb ) );
		}
		// The Connect callback ends in a full navigation back to this
		// screen; its outcome arrives as ordinary admin notices, the way
		// a page a browser genuinely left reports.
		add_action( 'admin_notices', array( __CLASS__, 'render_connect_notices' ) );
	}

	/** Show (and consume) the outcome a Connect callback stored. */
	public static function render_connect_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// This screen's section only; anywhere else the transient waits.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display routing on an admin screen.
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		if ( XPay_Constants::GATEWAY_ID !== $section ) {
			return;
		}
		foreach ( XPay_Connect::take_notices() as $notice ) {
			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				'error' === $notice['type'] ? 'notice-error' : 'notice-success',
				esc_html( $notice['text'] )
			);
		}
	}

	/* ── Assets ──────────────────────────────────────────────────────── */

	/**
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// The gateway's own section only; other sections keep core's page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing on an admin screen; nothing changes state here.
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		if ( XPay_Constants::GATEWAY_ID !== $section ) {
			return;
		}

		wp_enqueue_style(
			'xpay-admin',
			XPAY_WC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			XPay_Constants::asset_version( 'assets/css/admin.css' )
		);
		wp_enqueue_script(
			'xpay-admin',
			XPAY_WC_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			XPay_Constants::asset_version( 'assets/js/admin.js' ),
			true
		);
		wp_localize_script(
			'xpay-admin',
			'xpayAdminParams',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'connecting'        => __( 'Connecting…', 'xpay-for-woocommerce' ),
					'refreshing'        => __( 'Refreshing…', 'xpay-for-woocommerce' ),
					'saved'             => __( 'Settings saved.', 'xpay-for-woocommerce' ),
					'accountRefreshed'  => __( 'Account details refreshed.', 'xpay-for-woocommerce' ),
					'disconnected'      => __( 'Disconnected.', 'xpay-for-woocommerce' ),
					'failed'            => __( 'Could not reach the store. Reload the page and try again.', 'xpay-for-woocommerce' ),
					'disconnectConfirm' => __( 'Disconnect this mode? Its keys are removed from this store and its webhook endpoint is deleted at XPay. Payments in this mode stop until you connect again.', 'xpay-for-woocommerce' ),
				),
			)
		);
	}

	/* ── The screen ──────────────────────────────────────────────────── */

	/**
	 * Rendered by the gateway's admin_options() inside WooCommerce's own
	 * settings <form>, so every input posts through the standard save.
	 *
	 * @param XPay_Gateway $gateway The gateway whose settings these are.
	 */
	public static function render( XPay_Gateway $gateway ): void {
		$live      = ! $gateway->is_test_mode();
		$connected = self::keys_validated( $gateway, $live );
		$methods   = $gateway->ordered_method_types();

		echo '<div class="xpay-ad" data-xpay-admin>';

		self::header( $gateway, $live, $connected );

		/*
		 * With an account map cached, the screen is two tabs — Payment
		 * Methods first, then Settings, Stripe's order — switched in the
		 * browser like everything else here, never a reload. Both panes
		 * live inside the ONE settings <form>, so the page's Save posts
		 * the method checkboxes and the settings fields together. Before
		 * any account has been read there is nothing to put on a Payment
		 * Methods tab, and the screen stays one page.
		 */
		if ( array() !== $methods ) {
			self::page_tabs();

			echo '<div class="xpay-ad__page" data-xpay-page="methods">';
			self::methods_card( $gateway, $methods );
			echo '</div>';

			echo '<div class="xpay-ad__page" data-xpay-page="settings" hidden>';
			if ( ! $connected && ! self::keys_validated( $gateway, ! $live ) ) {
				self::get_started_card();
			} else {
				self::status_card( $gateway, $live, $connected );
			}
			self::settings_card( $gateway, $live );
			echo '</div>';
		} else {
			if ( ! $connected && ! self::keys_validated( $gateway, ! $live ) ) {
				self::get_started_card();
			} else {
				self::status_card( $gateway, $live, $connected );
			}
			self::settings_card( $gateway, $live );
		}

		self::connection_modal( $gateway, $live );

		echo '</div>';
	}

	/** The screen's two tabs. Payment Methods first, Stripe's order. */
	private static function page_tabs(): void {
		$tabs = array(
			'methods'  => __( 'Payment Methods', 'xpay-for-woocommerce' ),
			'settings' => __( 'Settings', 'xpay-for-woocommerce' ),
		);
		echo '<div class="xpay-ad__tabs xpay-ad__tabs--page" role="tablist">';
		foreach ( $tabs as $key => $label ) {
			$active = 'methods' === $key;
			echo '<button type="button" class="xpay-ad__tab' . ( $active ? ' is-active' : '' ) . '" role="tab" aria-selected="' . ( $active ? 'true' : 'false' ) . '" data-xpay-page-tab="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button>';
		}
		echo '</div>';
	}

	/**
	 * The Payment Methods card: one row per method the account can
	 * charge, checkbox to offer it, drag to reorder. The checkboxes are
	 * ordinary form fields saved by the page's own Save; the order has
	 * its own in-card save (the reorder verb), which is Stripe's split.
	 *
	 * @param XPay_Gateway $gateway Gateway.
	 * @param string[]     $ordered The account's methods in display order.
	 */
	private static function methods_card( XPay_Gateway $gateway, array $ordered ): void {
		$enabled = $gateway->enabled_method_types();

		echo '<div class="xpay-ad__card" data-xpay-methods>';

		echo '<div class="xpay-ad__card-head">';
		echo '<h2 class="xpay-ad__card-title">' . esc_html__( 'Payment methods', 'xpay-for-woocommerce' ) . '</h2>';
		echo '<span class="xpay-ad__spacer"></span>';
		/*
		 * Stripe's exact header controls (section-heading.js): outside
		 * reorder mode, a tertiary "Change display order" button and a
		 * kebab menu whose one item refreshes the methods from the
		 * account; in reorder mode both give way to tertiary Cancel and
		 * secondary "Save display order".
		 */
		echo '<span class="xpay-ad__methods-idle" data-xpay-methods-idle>';
		echo '<button type="button" class="xpay-ad__btn xpay-ad__btn--tertiary" data-xpay-reorder-start>' . esc_html__( 'Change display order', 'xpay-for-woocommerce' ) . '</button>';
		echo '<div class="xpay-ad__menu" data-xpay-menu>';
		echo '<button type="button" class="xpay-ad__menu-btn" data-xpay-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="' . esc_attr__( 'Payment methods menu', 'xpay-for-woocommerce' ) . '">⋮</button>';
		echo '<div class="xpay-ad__menu-list" hidden>';
		// The same account refresh the status card's menu offers, under
		// the name a merchant looks for here: it re-reads the account and
		// the tab follows.
		echo '<button type="button" class="xpay-ad__menu-item" data-xpay-refresh-account>' . esc_html__( 'Refresh payment methods', 'xpay-for-woocommerce' ) . '</button>';
		echo '</div></div>';
		echo '</span>';
		echo '<span class="xpay-ad__reorder-actions" hidden>';
		echo '<button type="button" class="xpay-ad__btn xpay-ad__btn--tertiary" data-xpay-reorder-cancel>' . esc_html__( 'Cancel', 'xpay-for-woocommerce' ) . '</button>';
		echo '<button type="button" class="xpay-ad__btn xpay-ad__btn--outline" data-xpay-reorder-save>' . esc_html__( 'Save display order', 'xpay-for-woocommerce' ) . '</button>';
		echo '</span>';
		echo '</div>';

		echo '<p class="xpay-ad__muted">' . esc_html__( 'Choose which payment methods appear at checkout. Manage available methods in your XPay dashboard.', 'xpay-for-woocommerce' ) . '</p>';

		echo '<ul class="xpay-ad__methods" data-xpay-method-list>';
		foreach ( $ordered as $type ) {
			self::method_row( $type, in_array( $type, $enabled, true ) );
		}
		echo '</ul>';

		// The marker save_enabled_methods() keys on: without it, a save
		// made from a page that never rendered these checkboxes would
		// read their absence as "uncheck everything".
		echo '<input type="hidden" name="xpay_methods_present" value="1">';

		echo '<p class="xpay-ad__muted xpay-ad__methods-note">' . esc_html__( 'This only changes the order of XPay methods. Manage all payment methods in WooCommerce → Settings → Payments.', 'xpay-for-woocommerce' ) . '</p>';

		echo '<div class="xpay-ad__actions"><button type="submit" name="save" class="button button-primary" value="save">' . esc_html__( 'Save changes', 'xpay-for-woocommerce' ) . '</button></div>';

		echo '</div>';
	}

	/**
	 * @param string $type    Wire method type.
	 * @param bool   $checked Whether this store offers it.
	 */
	private static function method_row( string $type, bool $checked ): void {
		$icon = XPay_Payment_Methods::icon_url( $type );

		echo '<li class="xpay-ad__method" data-xpay-method-row data-xpay-type="' . esc_attr( $type ) . '">';
		echo '<span class="xpay-ad__method-drag" aria-hidden="true">⋮⋮</span>';
		echo '<input type="checkbox" class="xpay-ad__method-check" name="xpay_method_enabled[]" id="xpay-method-' . esc_attr( $type ) . '" value="' . esc_attr( $type ) . '"' . checked( $checked, true, false ) . '>';
		echo '<span class="xpay-ad__method-icon">' . ( '' !== $icon ? '<img src="' . esc_url( $icon ) . '" alt="">' : '' ) . '</span>';
		echo '<label class="xpay-ad__method-main" for="xpay-method-' . esc_attr( $type ) . '">';
		echo '<span class="xpay-ad__method-name">' . esc_html( XPay_Payment_Methods::label( $type ) ) . '</span>';
		echo '<span class="xpay-ad__field-help">' . esc_html( XPay_Payment_Methods::description( $type ) ) . '</span>';
		echo '</label>';
		echo '</li>';
	}

	/**
	 * Brand band: mark, name, the active mode as a pill, and the one
	 * outbound link a merchant needs from here.
	 *
	 * @param XPay_Gateway $gateway   Gateway.
	 * @param bool         $live      Live mode selected.
	 * @param bool         $connected Active mode's keys are proved.
	 */
	private static function header( XPay_Gateway $gateway, bool $live, bool $connected ): void {
		unset( $gateway, $connected );
		echo '<div class="xpay-ad__header">';
		echo '<img class="xpay-ad__mark" src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/xpay-icon.svg' ) . '" alt="XPay">';
		echo '<span class="xpay-ad__name">XPay</span>';
		echo '<span class="xpay-ad__pill xpay-ad__pill--' . ( $live ? 'live' : 'test' ) . '">' . esc_html( $live ? __( 'Live mode', 'xpay-for-woocommerce' ) : __( 'Test mode', 'xpay-for-woocommerce' ) ) . '</span>';
		echo '<span class="xpay-ad__spacer"></span>';
		echo '<a class="xpay-ad__ext" href="' . esc_url( XPay_Constants::DASHBOARD_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open XPay dashboard', 'xpay-for-woocommerce' ) . ' ↗</a>';
		echo '</div>';
	}

	/**
	 * The one card a fresh install sees: one action, no steps.
	 *
	 * No decorative banner. What a merchant needs to judge this screen is
	 * what it can charge and what one click will do, so the payment marks
	 * they will actually offer carry the visual weight and the type does
	 * the rest. A brand strip with a logo pasted across it says nothing
	 * and dates immediately.
	 */
	private static function get_started_card(): void {
		echo '<div class="xpay-ad__card xpay-ad__card--hero">';

		echo '<img class="xpay-ad__hero-logo" src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/xpay-mark.svg' ) . '" alt="XPay">';

		echo '<h2 class="xpay-ad__hero-title">' . esc_html__( 'Start accepting payments', 'xpay-for-woocommerce' ) . '</h2>';
		echo '<p class="xpay-ad__hero-sub">' . esc_html__( 'Connect your XPay account to start accepting payments. Setup is automatic, and card details never touch your server.', 'xpay-for-woocommerce' ) . '</p>';

		/*
		 * The primary action starts the TEST connect: a fresh install has
		 * no reason to touch live before a test payment works, and the
		 * mode lock enforces the same order.
		 */
		echo '<div class="xpay-ad__hero-actions">';
		self::connect_button( 'test', __( 'Connect with XPay', 'xpay-for-woocommerce' ), 'button button-primary button-hero' );
		echo '<span class="xpay-ad__hero-aside">' . esc_html__( 'Starts in test mode. Nothing goes live until you switch it yourself.', 'xpay-for-woocommerce' ) . '</span>';
		echo '</div>';
		echo '<p class="xpay-ad__connect-error" data-xpay-connect-error hidden></p>';
		if ( ! XPay_Connect::https_ready() ) {
			echo '<p class="xpay-ad__auth-help">' . esc_html__( 'Connecting needs your site served over HTTPS.', 'xpay-for-woocommerce' ) . '</p>';
		}

		// The methods themselves, as their own marks: the one honest
		// picture of what connecting buys. Which of them a merchant can
		// actually charge is their account's answer, given after they
		// connect, so this claims nothing about their account.
		echo '<div class="xpay-ad__hero-methods">';
		foreach ( array( XPay_Payment_Methods::CARD, XPay_Payment_Methods::VALU, XPay_Payment_Methods::FAWRY ) as $type ) {
			$icon = XPay_Payment_Methods::icon_url( $type );
			if ( '' === $icon ) {
				continue;
			}
			echo '<span class="xpay-ad__hero-method"><img src="' . esc_url( $icon ) . '" alt="' . esc_attr( XPay_Payment_Methods::label( $type ) ) . '"></span>';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * A Connect button, or its honest disabled state when the store
	 * cannot register a callback (registration refuses plain-http
	 * redirect URIs on non-loopback hosts — the button says so up front
	 * instead of relaying a protocol error; Stripe disables its connect
	 * button on non-SSL stores the same way, connect-button.js:96).
	 * Callers render the inline error node and the HTTPS explanation.
	 *
	 * @param string $mode    Plane the button connects ('test'|'live').
	 * @param string $label   Button text.
	 * @param string $classes Button classes.
	 */
	private static function connect_button( string $mode, string $label, string $classes ): void {
		if ( XPay_Connect::https_ready() ) {
			echo '<button type="button" class="' . esc_attr( $classes ) . '" data-xpay-connect data-xpay-plane="' . esc_attr( $mode ) . '">' . esc_html( $label ) . '</button>';
			return;
		}
		echo '<button type="button" class="' . esc_attr( $classes ) . '" disabled>' . esc_html( $label ) . '</button>';
	}

	/**
	 * Account status: badges that answer only from real truth sources, the
	 * merchant identity, the webhook health line, and the connection menu.
	 *
	 * @param XPay_Gateway $gateway   Gateway.
	 * @param bool         $live      Live mode selected.
	 * @param bool         $connected Active mode's keys are proved.
	 */
	private static function status_card( XPay_Gateway $gateway, bool $live, bool $connected ): void {
		$mode            = $live ? 'live' : 'test';
		$endpoint        = XPay_Webhook_Configurator::endpoint_data( $live );
		$secret_present  = '' !== $gateway->get_option( $mode . '_webhook_secret', '' );
		$webhook_ready   = null !== $endpoint || $secret_present;
		$merchant_id     = (string) get_option( XPay_Constants::merchant_id_option( $live ), '' );
		$payments_denied = $live && self::live_payments_disabled();

		$merchant_name = (string) get_option( 'xpay_wc_merchant_name_' . $mode, '' );

		echo '<div class="xpay-ad__card" data-xpay-status-card>';
		echo '<div class="xpay-ad__card-head">';
		echo '<h2 class="xpay-ad__card-title">' . esc_html__( 'Account status', 'xpay-for-woocommerce' ) . '</h2>';
		echo '<span class="xpay-ad__spacer"></span>';
		if ( '' !== $merchant_name || '' !== $merchant_id ) {
			// The merchant's own name over their account id, exactly the
			// identity block Stripe's status card carries: "this is the
			// account these keys act for", answered from the account itself.
			echo '<span class="xpay-ad__identity">';
			if ( '' !== $merchant_name ) {
				echo '<span class="xpay-ad__identity-name">' . esc_html( $merchant_name ) . '</span>';
			}
			if ( '' !== $merchant_id ) {
				echo '<span class="xpay-ad__muted xpay-ad__mono">' . esc_html( $merchant_id ) . '</span>';
			}
			echo '</span>';
		}
		self::kebab_menu( $live, $connected );
		echo '</div>';

		echo '<div class="xpay-ad__badges">';
		self::badge( __( 'Account', 'xpay-for-woocommerce' ), $connected, $connected ? __( 'Connected', 'xpay-for-woocommerce' ) : __( 'Disconnected', 'xpay-for-woocommerce' ) );
		self::badge( __( 'Payments', 'xpay-for-woocommerce' ), $connected && ! $payments_denied, $payments_denied ? __( 'Awaiting activation', 'xpay-for-woocommerce' ) : ( $connected ? __( 'Enabled', 'xpay-for-woocommerce' ) : __( 'Disabled', 'xpay-for-woocommerce' ) ) );
		self::badge( __( 'Webhook', 'xpay-for-woocommerce' ), $webhook_ready, $webhook_ready ? __( 'Configured', 'xpay-for-woocommerce' ) : __( 'Not configured', 'xpay-for-woocommerce' ) );
		echo '</div>';

		if ( $webhook_ready ) {
			echo '<p class="xpay-ad__health" data-xpay-health data-xpay-plane="' . esc_attr( $mode ) . '">';
			echo '<span data-xpay-health-message>' . esc_html( XPay_Webhook_State::status_message( $live ) ) . '</span> ';
			echo '<button type="button" class="button-link" data-xpay-refresh-health>' . esc_html__( 'Refresh', 'xpay-for-woocommerce' ) . '</button>';
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * The connection menu: everything that acts on the CONNECTION rather
	 * than on a setting, exactly Stripe's three verbs.
	 *
	 * @param bool $live      Live mode selected.
	 * @param bool $connected Active mode's keys are proved.
	 */
	private static function kebab_menu( bool $live, bool $connected ): void {
		echo '<div class="xpay-ad__menu" data-xpay-menu>';
		echo '<button type="button" class="xpay-ad__menu-btn" data-xpay-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="' . esc_attr__( 'Connection actions', 'xpay-for-woocommerce' ) . '">⋮</button>';
		echo '<div class="xpay-ad__menu-list" hidden>';
		echo '<button type="button" class="xpay-ad__menu-item" data-xpay-open-modal>' . esc_html__( 'Configure connection', 'xpay-for-woocommerce' ) . '</button>';
		echo '<button type="button" class="xpay-ad__menu-item" data-xpay-refresh-account>' . esc_html__( 'Refresh account details', 'xpay-for-woocommerce' ) . '</button>';
		if ( $connected ) {
			echo '<button type="button" class="xpay-ad__menu-item xpay-ad__menu-item--danger" data-xpay-disconnect data-xpay-plane="' . esc_attr( $live ? 'live' : 'test' ) . '">' . esc_html__( 'Disconnect', 'xpay-for-woocommerce' ) . '</button>';
		}
		echo '</div></div>';
	}

	/**
	 * @param string $label Category label (Account, Payments, Webhook).
	 * @param bool   $ok    Green or amber.
	 * @param string $value State word.
	 */
	private static function badge( string $label, bool $ok, string $value ): void {
		echo '<span class="xpay-ad__badge"><span class="xpay-ad__badge-label">' . esc_html( $label ) . '</span><span class="xpay-ad__badge-value xpay-ad__badge-value--' . ( $ok ? 'ok' : 'warn' ) . '">' . esc_html( $value ) . '</span></span>';
	}

	/**
	 * The plain settings: the general switches and the display options.
	 * Real inputs with the standard field names; WooCommerce's save does
	 * the rest.
	 *
	 * @param XPay_Gateway $gateway Gateway.
	 * @param bool         $live    Live mode selected.
	 */
	private static function settings_card( XPay_Gateway $gateway, bool $live ): void {
		echo '<div class="xpay-ad__card">';
		echo '<h2 class="xpay-ad__card-title">' . esc_html__( 'Settings', 'xpay-for-woocommerce' ) . '</h2>';

		echo '<div class="xpay-ad__fields">';

		self::checkbox_field( $gateway, 'enabled', __( 'Enable XPay', 'xpay-for-woocommerce' ), __( 'When off, no XPay option appears at checkout.', 'xpay-for-woocommerce' ) );

		// The mode is stored as the select WooCommerce declared; the
		// control merchants understand is Stripe's checkbox. A hidden
		// input carries the real value.
		$test = ! $live;

		/*
		 * Stripe's mode lock (test-mode-checkbox.js): the checkbox is
		 * disabled whenever flipping it would land the gateway on a plane
		 * with no keys, which would run the checkout on a mode that
		 * cannot charge. Locked to test until live keys exist, and locked
		 * to live until test keys exist. One improvement over Stripe's
		 * plain notice: ours ends with a link opening the connection
		 * dialog on the plane that needs its keys.
		 */
		$has_live_keys = '' !== (string) $gateway->get_option( 'live_api_key', '' ) && '' !== (string) $gateway->get_option( 'live_publishable_key', '' );
		$has_test_keys = '' !== (string) $gateway->get_option( 'test_api_key', '' ) && '' !== (string) $gateway->get_option( 'test_publishable_key', '' );
		$locked_test   = $test && ! $has_live_keys;
		$locked_live   = ! $test && ! $has_test_keys;

		echo '<label class="xpay-ad__field xpay-ad__field--check">';
		echo '<input type="checkbox" data-xpay-testmode' . checked( $test, true, false ) . disabled( $locked_test || $locked_live, true, false ) . '>';
		echo '<span class="xpay-ad__field-main"><span class="xpay-ad__field-label">' . esc_html__( 'Enable test mode', 'xpay-for-woocommerce' ) . '</span>';
		echo '<span class="xpay-ad__field-help">' . esc_html__( 'Test mode never charges real money.', 'xpay-for-woocommerce' );
		if ( $locked_test ) {
			echo '<strong class="xpay-ad__lock-notice">' . esc_html__( 'Live mode cannot be enabled before you have connected a live XPay account.', 'xpay-for-woocommerce' ) . ' ';
			echo '<button type="button" class="button-link" data-xpay-open-modal data-xpay-modal-tab="live">' . esc_html__( 'Connect your live account', 'xpay-for-woocommerce' ) . '</button></strong>';
		}
		if ( $locked_live ) {
			echo '<strong class="xpay-ad__lock-notice">' . esc_html__( 'Test mode cannot be enabled before you have connected a test XPay account.', 'xpay-for-woocommerce' ) . ' ';
			echo '<button type="button" class="button-link" data-xpay-open-modal data-xpay-modal-tab="test">' . esc_html__( 'Connect your test account', 'xpay-for-woocommerce' ) . '</button></strong>';
		}
		echo '</span></span>';
		echo '</label>';
		echo '<input type="hidden" name="' . esc_attr( self::field_name( $gateway, 'mode' ) ) . '" value="' . esc_attr( $test ? 'test' : 'live' ) . '" data-xpay-mode-carrier>';

		/*
		 * No Title or Description fields. Each payment method is its own
		 * checkout row and names itself; the merchant strings only served
		 * the single-row fallback, which exists for the moment before the
		 * first key save and resolves itself. The stored options keep
		 * their defaults for that moment.
		 */

		echo '<div class="xpay-ad__field">';
		echo '<span class="xpay-ad__field-label">' . esc_html__( 'Theme', 'xpay-for-woocommerce' ) . '</span>';
		echo '<span class="xpay-ad__field-main">';
		$current = (string) $gateway->get_option( 'color_mode', 'auto' );
		echo '<div class="xpay-ad__segment" role="radiogroup" aria-label="' . esc_attr__( 'Theme', 'xpay-for-woocommerce' ) . '">';
		foreach ( array(
			'auto'  => __( 'Automatic', 'xpay-for-woocommerce' ),
			'light' => __( 'Light', 'xpay-for-woocommerce' ),
			'dark'  => __( 'Dark', 'xpay-for-woocommerce' ),
		) as $value => $label ) {
			echo '<label class="xpay-ad__segment-opt' . ( $current === $value ? ' is-active' : '' ) . '">';
			echo '<input type="radio" name="' . esc_attr( self::field_name( $gateway, 'color_mode' ) ) . '" value="' . esc_attr( $value ) . '"' . checked( $current, $value, false ) . '>';
			echo esc_html( $label );
			echo '</label>';
		}
		echo '</div>';
		echo '<span class="xpay-ad__field-help">' . esc_html__( 'Payment fields use the branding from your XPay dashboard. Automatic matches your checkout theme.', 'xpay-for-woocommerce' ) . '</span>';
		echo '</span></div>';

		self::checkbox_field( $gateway, 'debug', __( 'Diagnostic logging', 'xpay-for-woocommerce' ), __( 'Write redacted diagnostic logs to WooCommerce → Status → Logs (source "xpay"). Failures are always recorded either way.', 'xpay-for-woocommerce' ) );

		self::checkbox_field( $gateway, 'wpfunnels_force_standard_redirect', __( 'WPFunnels: force the standard confirmation page', 'xpay-for-woocommerce' ), __( 'Only relevant when the WPFunnels plugin is active. Keeps shoppers on the normal order-received page after paying, unless you run a working upsell flow.', 'xpay-for-woocommerce' ) );

		echo '</div>';

		echo '<div class="xpay-ad__actions"><button type="submit" name="save" class="button button-primary" value="save">' . esc_html__( 'Save changes', 'xpay-for-woocommerce' ) . '</button></div>';
		echo '<p class="xpay-ad__docs">' . wp_kses(
			sprintf(
				/* translators: %s is the documentation URL. */
				__( 'Guides and troubleshooting live at <a href="%s" target="_blank" rel="noopener noreferrer">docs.xpay.app</a>.', 'xpay-for-woocommerce' ),
				'https://docs.xpay.app'
			),
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		) . '</p>';
		echo '</div>';
	}

	/**
	 * The connection dialog: one tab per mode, each carrying its own
	 * badges, key pair and webhook controls. Saving submits the SAME
	 * settings form, so validation and webhook creation ride the standard
	 * save.
	 *
	 * @param XPay_Gateway $gateway Gateway.
	 * @param bool         $live    Live mode selected.
	 */
	private static function connection_modal( XPay_Gateway $gateway, bool $live ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display routing; nothing changes state.
		$autopen = isset( $_GET['xpay-setup'] );
		echo '<div class="xpay-ad__overlay" data-xpay-modal' . ( $autopen ? ' data-xpay-autopen' : '' ) . ' hidden>';
		echo '<div class="xpay-ad__modal" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'XPay account and webhooks', 'xpay-for-woocommerce' ) . '">';

		echo '<div class="xpay-ad__modal-head">';
		echo '<h2 class="xpay-ad__card-title">' . esc_html__( 'XPay account & webhooks', 'xpay-for-woocommerce' ) . '</h2>';
		echo '<button type="button" class="xpay-ad__close" data-xpay-close-modal aria-label="' . esc_attr__( 'Close', 'xpay-for-woocommerce' ) . '">×</button>';
		echo '</div>';

		echo '<div class="xpay-ad__tabs" role="tablist">';
		foreach ( array( 'test', 'live' ) as $tab ) {
			$selected = ( 'live' === $tab ) === $live;
			echo '<button type="button" class="xpay-ad__tab' . ( $selected ? ' is-active' : '' ) . '" role="tab" aria-selected="' . ( $selected ? 'true' : 'false' ) . '" data-xpay-tab="' . esc_attr( $tab ) . '">' . esc_html( 'live' === $tab ? __( 'Live', 'xpay-for-woocommerce' ) : __( 'Test', 'xpay-for-woocommerce' ) ) . '</button>';
		}
		echo '</div>';

		foreach ( array( 'test', 'live' ) as $tab ) {
			self::modal_pane( $gateway, 'live' === $tab, ( 'live' === $tab ) === $live );
		}

		echo '</div></div>';
	}

	/**
	 * One mode's pane in the connection dialog: Stripe's auth panel
	 * (StripeAuthAccount — diagram, status, heading, description,
	 * actions, webhook destination line), with one improvement — the
	 * diagram's store side is THIS store's own site icon when one
	 * exists, not a generic platform mark.
	 *
	 * No form lives here. Keys arrive only through Connect, so the pane
	 * is an action panel: the one Save on the screen belongs to the
	 * settings card.
	 *
	 * @param XPay_Gateway $gateway Gateway.
	 * @param bool         $pane_live This pane is the live one.
	 * @param bool         $visible   Shown initially.
	 */
	private static function modal_pane( XPay_Gateway $gateway, bool $pane_live, bool $visible ): void {
		$mode          = $pane_live ? 'live' : 'test';
		$connected     = self::keys_validated( $gateway, $pane_live );
		$endpoint      = XPay_Webhook_Configurator::endpoint_data( $pane_live );
		$secret        = '' !== $gateway->get_option( $mode . '_webhook_secret', '' );
		$has_key       = '' !== (string) $gateway->get_option( $mode . '_api_key', '' );
		$webhook_ready = null !== $endpoint || $secret;

		echo '<div class="xpay-ad__pane" data-xpay-pane="' . esc_attr( $mode ) . '"' . ( $visible ? '' : ' hidden' ) . '>';

		self::connect_diagram();

		echo '<div class="xpay-ad__badges">';
		self::badge( __( 'Account', 'xpay-for-woocommerce' ), $connected, $connected ? __( 'Connected', 'xpay-for-woocommerce' ) : __( 'Disconnected', 'xpay-for-woocommerce' ) );
		self::badge( __( 'Webhook', 'xpay-for-woocommerce' ), $webhook_ready, $webhook_ready ? __( 'Configured', 'xpay-for-woocommerce' ) : __( 'Not configured', 'xpay-for-woocommerce' ) );
		echo '</div>';

		echo '<h3 class="xpay-ad__auth-heading">' . esc_html( $pane_live ? __( 'Connect with XPay in live mode', 'xpay-for-woocommerce' ) : __( 'Connect with XPay in test mode', 'xpay-for-woocommerce' ) ) . '</h3>';
		echo '<p class="xpay-ad__auth-sub">' . esc_html__( 'Connect your XPay account. Payment methods and order updates are set up automatically.', 'xpay-for-woocommerce' ) . '</p>';

		echo '<div class="xpay-ad__auth-actions">';
		self::connect_button(
			$mode,
			$pane_live ? __( 'Connect live account', 'xpay-for-woocommerce' ) : __( 'Connect test account', 'xpay-for-woocommerce' ),
			'button button-primary'
		);
		if ( $has_key ) {
			// The recovery for an endpoint deleted at XPay's side. Only
			// with a key to create it with — Stripe gates its configure
			// button on the same condition (stripe-auth-account/index.js:73).
			echo '<button type="button" class="button" data-xpay-reconfigure data-xpay-plane="' . esc_attr( $mode ) . '">' . esc_html__( 'Reconfigure webhook', 'xpay-for-woocommerce' ) . '</button>';
		}
		echo '</div>';
		echo '<p class="xpay-ad__connect-error" data-xpay-connect-error hidden></p>';
		if ( ! XPay_Connect::https_ready() ) {
			echo '<p class="xpay-ad__auth-help">' . esc_html__( 'Connecting needs your site served over HTTPS.', 'xpay-for-woocommerce' ) . '</p>';
		}
		echo '<p class="xpay-ad__auth-help" data-xpay-reconfigure-result></p>';

		echo '<p class="xpay-ad__auth-help">' . wp_kses(
			sprintf(
				$webhook_ready
					/* translators: %s is this store's webhook URL. */
					? __( 'Your webhook endpoint is set up. Events are sent to: %s.', 'xpay-for-woocommerce' )
					/* translators: %s is this store's webhook URL. */
					: __( 'Webhook events will be sent to: %s.', 'xpay-for-woocommerce' ),
				'<strong>' . esc_html( XPay_Webhook_Configurator::webhook_url() ) . '</strong>'
			),
			array( 'strong' => array() )
		) . '</p>';

		echo '</div>';
	}

	/**
	 * The connection diagram: this store linked to XPay. The store side
	 * is the site's own icon when one is set — a personalized touch over
	 * Stripe's generic platform mark — with a storefront glyph as the
	 * fallback.
	 */
	private static function connect_diagram(): void {
		$site_icon = get_site_icon_url( 64 );
		echo '<div class="xpay-ad__diagram" aria-hidden="true">';
		if ( '' !== $site_icon ) {
			echo '<span class="xpay-ad__diagram-tile"><img src="' . esc_url( $site_icon ) . '" alt=""></span>';
		} else {
			echo '<span class="xpay-ad__diagram-tile xpay-ad__diagram-tile--store"><span class="dashicons dashicons-store"></span></span>';
		}
		echo '<span class="xpay-ad__diagram-line"></span>';
		echo '<span class="xpay-ad__diagram-tile xpay-ad__diagram-tile--xpay"><img src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/xpay-icon.svg' ) . '" alt=""></span>';
		echo '</div>';
	}

	/* ── Field primitives ────────────────────────────────────────────── */

	/**
	 * @param XPay_Gateway $gateway Gateway.
	 * @param string       $key     Settings key.
	 * @param string       $label   Field label.
	 * @param string       $help    One-line help.
	 */
	private static function checkbox_field( XPay_Gateway $gateway, string $key, string $label, string $help ): void {
		echo '<label class="xpay-ad__field xpay-ad__field--check">';
		echo '<input type="checkbox" name="' . esc_attr( self::field_name( $gateway, $key ) ) . '" value="1"' . checked( 'yes' === $gateway->get_option( $key, 'no' ), true, false ) . '>';
		echo '<span class="xpay-ad__field-main"><span class="xpay-ad__field-label">' . esc_html( $label ) . '</span>';
		echo '<span class="xpay-ad__field-help">' . esc_html( $help ) . '</span></span>';
		echo '</label>';
	}

	/** @param XPay_Gateway $gateway Gateway. @param string $key Settings key. */
	private static function field_name( XPay_Gateway $gateway, string $key ): string {
		return $gateway->plugin_id . $gateway->id . '_' . $key;
	}

	/* ── Truth sources ───────────────────────────────────────────────── */

	/**
	 * One mode's keys are present AND proved by a real validation of these
	 * exact keys. The fingerprint is what notices settings written around
	 * the save path (the REST settings route never validates).
	 *
	 * @param XPay_Gateway $gateway Gateway.
	 * @param bool         $live    Which plane.
	 */
	public static function keys_validated( XPay_Gateway $gateway, bool $live ): bool {
		$mode        = $live ? 'live' : 'test';
		$secret      = (string) $gateway->get_option( $mode . '_api_key', '' );
		$publishable = (string) $gateway->get_option( $mode . '_publishable_key', '' );
		if ( '' === $secret || '' === $publishable ) {
			return false;
		}
		$proof = get_option( XPay_Constants::OPTION_KEY_VALIDATED, array() );
		if ( ! is_array( $proof ) || ! isset( $proof['mode'] ) || $proof['mode'] !== $mode ) {
			return false;
		}
		if ( ! isset( $proof['fingerprint'] ) ) {
			return true;
		}
		return hash_equals( (string) $proof['fingerprint'], XPay_Constants::key_fingerprint( $secret, $publishable ) );
	}

	/**
	 * Whether the connected live account is still awaiting activation, as
	 * cached from the last GET /account. Absent means "not known to be
	 * disabled" — the badge never claims a problem it has not seen.
	 */
	private static function live_payments_disabled(): bool {
		return '1' === get_option( 'xpay_wc_live_payments_disabled', '' );
	}

	/* ── AJAX verbs ──────────────────────────────────────────────────── */

	/** Gate every verb: capability + nonce, always. */
	private static function verify(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/** The webhook health line, re-read on demand. */
	public static function handle_health(): void {
		self::verify();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer ran inside verify(); the sniff cannot see through the helper.
		$live = isset( $_POST['plane'] ) && 'live' === sanitize_text_field( wp_unslash( $_POST['plane'] ) );
		wp_send_json_success(
			array(
				'message' => XPay_Webhook_State::status_message( $live ),
				'code'    => XPay_Webhook_State::status_code( $live ),
			)
		);
	}

	/**
	 * Re-read the account: currencies, methods, merchant id, live
	 * activation — the facts the caches hold — plus a quiet webhook heal.
	 */
	public static function handle_refresh_account(): void {
		self::verify();
		$gateway = XPay_Plugin::instance()->gateway();
		try {
			$account = $gateway->api_client()->get_account();
		} catch ( XPay_Api_Exception $e ) {
			wp_send_json_error( array( 'reason' => $e->get_error_code() ), 502 );
		}
		$gateway->refresh_account_facts( $account );
		wp_send_json_success( array( 'refreshed' => true ) );
	}

	/**
	 * Recreate one mode's webhook endpoint on demand: the recovery path
	 * for an endpoint deleted at XPay's side. Rate limited the way
	 * Stripe's configure button is, per user per mode.
	 */
	public static function handle_reconfigure_webhooks(): void {
		self::verify();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer ran inside verify(); the sniff cannot see through the helper.
		$live = isset( $_POST['plane'] ) && 'live' === sanitize_text_field( wp_unslash( $_POST['plane'] ) );

		$rate_key = 'xpay-reconfigure-' . ( $live ? 'live' : 'test' ) . '-' . get_current_user_id();
		if ( WC_Rate_Limiter::retried_too_soon( $rate_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Just reconfigured. Give it a minute before trying again.', 'xpay-for-woocommerce' ) ), 429 );
		}
		WC_Rate_Limiter::set_rate_limit( $rate_key, 60 );

		$gateway = XPay_Plugin::instance()->gateway();
		$key     = (string) $gateway->get_option( ( $live ? 'live' : 'test' ) . '_api_key', '' );
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Save this mode\'s keys first.', 'xpay-for-woocommerce' ) ), 400 );
		}

		try {
			XPay_Webhook_Configurator::configure( $key );
		} catch ( XPay_Api_Exception $e ) {
			wp_send_json_error( array( 'message' => XPay_Webhook_State::reason_sentence( $e->get_error_code() ) ), 502 );
		}
		wp_send_json_success( array( 'message' => __( 'Webhook reconfigured. The signing secret was stored for you.', 'xpay-for-woocommerce' ) ) );
	}

	/**
	 * Persist the Payment Methods display order from the reorder mode's
	 * Save. The posted list must be a permutation of the account's
	 * methods as this store knows them: anything else is a stale page (an
	 * account refresh landed mid-edit) or a forged value, and both are
	 * refused rather than guessed at. The saved order becomes the real
	 * checkout order through the gateway-ordering sync.
	 */
	public static function handle_save_method_order(): void {
		self::verify();

		$posted = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer ran inside verify(); the sniff cannot see through the helper.
		if ( isset( $_POST['order'] ) && is_array( $_POST['order'] ) ) {
			// is_string first: a forged nested array would fatal sanitize_key.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; values are unslashed, restricted to strings, and sanitized below.
			$posted = array_map( 'sanitize_key', array_filter( wp_unslash( $_POST['order'] ), 'is_string' ) );
		}

		$gateway   = XPay_Plugin::instance()->gateway();
		$available = $gateway->ordered_method_types();

		$a = $posted;
		$b = $available;
		sort( $a );
		sort( $b );
		if ( array() === $posted || $a !== $b ) {
			wp_send_json_error( array( 'message' => __( 'The method list changed while you were editing. Reload the page and try again.', 'xpay-for-woocommerce' ) ), 409 );
		}

		update_option( XPay_Constants::OPTION_METHOD_ORDER, array_values( $posted ), false );
		XPay_Plugin::sync_gateway_order();

		XPay_Logger::event( 'admin.method_order_saved', array( 'order' => implode( ',', $posted ) ) );
		wp_send_json_success( array( 'message' => __( 'Display order saved.', 'xpay-for-woocommerce' ) ) );
	}

	/**
	 * Start a Connect with XPay flow: answer the authorize URL for the
	 * browser to navigate to. The URL is built server-side, on purpose —
	 * the browser never assembles an OAuth request from page state.
	 */
	public static function handle_connect(): void {
		self::verify();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer ran inside verify(); the sniff cannot see through the helper.
		$live = isset( $_POST['plane'] ) && 'live' === sanitize_text_field( wp_unslash( $_POST['plane'] ) );

		if ( ! XPay_Connect::https_ready() ) {
			wp_send_json_error( array( 'message' => __( 'Connecting needs your site served over HTTPS.', 'xpay-for-woocommerce' ) ), 400 );
		}

		try {
			$url = XPay_Connect::begin( $live );
		} catch ( XPay_Api_Exception $e ) {
			XPay_Logger::error( 'connect.begin_failed', array( 'code' => $e->get_error_code() ) );
			wp_send_json_error( array( 'message' => __( 'The connection could not be started. Check that your store can reach XPay and try again.', 'xpay-for-woocommerce' ) ), 502 );
		}

		wp_send_json_success( array( 'url' => $url ) );
	}

	/**
	 * Disconnect one mode: retire its webhook endpoint at XPay, then
	 * remove its keys, secret and proof from this store. The other mode
	 * is untouched.
	 */
	public static function handle_disconnect(): void {
		self::verify();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer ran inside verify(); the sniff cannot see through the helper.
		$live = isset( $_POST['plane'] ) && 'live' === sanitize_text_field( wp_unslash( $_POST['plane'] ) );
		$mode = $live ? 'live' : 'test';

		XPay_Webhook_Configurator::maybe_decommission( XPay_Webhook_Configurator::endpoint_data( $live ), '' );

		XPay_Webhook_Configurator::merge_settings(
			array(
				$mode . '_api_key'         => '',
				$mode . '_publishable_key' => '',
				$mode . '_webhook_secret'  => '',
				$mode . '_webhook_data'    => array(),
			)
		);
		XPay_Webhook_State::clear_state( $live );

		$proof = get_option( XPay_Constants::OPTION_KEY_VALIDATED, array() );
		if ( is_array( $proof ) && isset( $proof['mode'] ) && $proof['mode'] === $mode ) {
			delete_option( XPay_Constants::OPTION_KEY_VALIDATED );
		}
		delete_option( XPay_Constants::account_methods_option( $live ) );
		delete_option( XPay_Constants::merchant_id_option( $live ) );
		delete_option( 'xpay_wc_merchant_name_' . $mode );

		XPay_Logger::event( 'admin.disconnected', array( 'live_mode' => $live ) );
		wp_send_json_success( array( 'disconnected' => true ) );
	}
}
