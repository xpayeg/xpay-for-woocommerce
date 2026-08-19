<?php
/**
 * XPay_Settings_Screen
 *
 * The Manage screen, in XPay's own design language (Inter-family stack,
 * the dashboard's radius/spacing tokens, the Developers screen's
 * joined-row lists) — replacing WooCommerce's flat settings table.
 *
 * Two states:
 *   - Fresh install (no keys in either mode): a guided three-step
 *     activation — keys, webhook, test payment — with only step one open.
 *   - Configured: a live status list (keys / webhook / test payment /
 *     go-live nudge) above grouped sections for keys, webhook, and
 *     checkout appearance.
 *
 * Presentation only, by contract: every control posts the standard
 * woocommerce_xpay_* field names, so XPay_Gateway::process_admin_options
 * and the stored option shape are untouched. Fields a state does not show
 * are carried as hidden inputs — a save must never silently erase them.
 *
 * Truth sources for the status rows are real, not decorative:
 *   - Keys:    OPTION_KEY_VALIDATED, written only after validate_key()
 *              succeeded against the live API.
 *   - Webhook: OPTION_LAST_WEBHOOK_AT, stamped only by signature-verified
 *              events (never by rejected probes).
 *   - Payment: an actual paid order carrying an XPay gateway id.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Settings_Screen {

	/**
	 * Assets for this screen only — gated on the exact settings section so
	 * no other admin page pays for them.
	 */
	public static function enqueue(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen gating; no state is touched.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable
		if ( 'wc-settings' !== $page || 'checkout' !== $tab || XPay_Constants::GATEWAY_ID !== $section ) {
			return;
		}
		wp_enqueue_style(
			'xpay-admin-settings',
			XPAY_WC_PLUGIN_URL . 'assets/css/admin-settings.css',
			array(),
			XPay_Constants::asset_version( 'assets/css/admin-settings.css' )
		);
		wp_enqueue_script(
			'xpay-admin-settings',
			XPAY_WC_PLUGIN_URL . 'assets/js/admin-settings.js',
			array(),
			XPay_Constants::asset_version( 'assets/js/admin-settings.js' ),
			true
		);
	}

	/**
	 * Entry point, called from XPay_Gateway::admin_options().
	 *
	 * @param XPay_Gateway $gateway The main gateway instance.
	 */
	public static function render( XPay_Gateway $gateway ): void {
		$fresh = '' === $gateway->get_option( 'test_api_key', '' ) && '' === $gateway->get_option( 'live_api_key', '' );
		$live  = 'live' === $gateway->get_option( 'mode', 'test' );

		// A fresh install greets the merchant with the welcome landing;
		// any explicit setup intent — the welcome's own Activate button or
		// the Payments page's "Complete setup" — carries ?xpay-setup and
		// goes straight to the guided steps. Presence-only read of a view
		// switch: nothing from the request is echoed and no state changes,
		// so there is no nonce to verify.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$welcome = $fresh && ! isset( $_GET['xpay-setup'] );

		echo '<div class="xpay-adm' . ( $live ? ' xpay-adm--live' : '' ) . '">';
		self::header_band( $gateway, $fresh, $live, $welcome );
		echo '<div class="xpay-adm__card">';
		if ( $welcome ) {
			self::welcome( $gateway );
		} elseif ( $fresh ) {
			self::activation( $gateway );
		} else {
			self::configured( $gateway, $live );
		}
		echo '</div>';
		if ( ! $welcome ) {
			self::help_dialogs();
		}
		echo '</div>';
	}

	/**
	 * A "?" beside a title, opening one of the help dialogs below.
	 *
	 * @param string $topic 'keys' or 'webhook' — matched to a dialog id.
	 * @param string $label Accessible name for the icon.
	 */
	private static function help_icon( string $topic, string $label ): void {
		echo '<button type="button" class="xpay-adm__help" data-xpay-help="' . esc_attr( $topic ) . '" aria-haspopup="dialog" aria-label="' . esc_attr( $label ) . '">?</button>';
	}

	/**
	 * The two help dialogs the "?" icons open. Every step names the REAL
	 * dashboard controls — labels lifted from the dashboard's own strings
	 * (Developer hub, Restricted keys, Add endpoint, …), so a merchant
	 * reading here and looking there sees the same words. Each dialog
	 * carries a full test block and a full live block; the screen's
	 * existing mode mechanics show exactly one, so the instructions are
	 * always for the environment the merchant is configuring.
	 */
	private static function help_dialogs(): void {
		// ── Keys ─────────────────────────────────────────────────────
		echo '<div class="xpay-adm__dialog-backdrop xpay-adm__help-dialog" id="xpay-help-keys" hidden>';
		echo '<div class="xpay-adm__dialog" role="dialog" aria-modal="true" aria-labelledby="xpay-help-keys-title">';
		echo '<div class="xpay-adm__dialog-head"><h2 class="xpay-adm__dialog-title" id="xpay-help-keys-title">' . esc_html__( 'Get your API keys', 'xpay-for-woocommerce' ) . '</h2>';
		echo '<button type="button" class="xpay-adm__dialog-close" aria-label="' . esc_attr__( 'Close', 'xpay-for-woocommerce' ) . '">&times;</button></div>';

		foreach ( array( 'test', 'live' ) as $mode ) {
			echo '<div class="xpay-adm__help-body" data-xpay-mode="' . esc_attr( $mode ) . '">';
			if ( 'test' === $mode ) {
				echo '<p class="xpay-adm__help-intro">' . esc_html__( 'Test keys work on test data only — no real money ever moves.', 'xpay-for-woocommerce' ) . '</p>';
			} else {
				echo '<p class="xpay-adm__help-intro">' . esc_html__( 'Live keys move real money, and live mode unlocks only after XPay activates your account — if your dashboard still shows "Request payment activation", finish that first.', 'xpay-for-woocommerce' ) . '</p>';
			}
			echo '<ol class="xpay-adm__help-steps">';
			// The dashboard's single Test Mode switch sits at the BOTTOM of
			// the left menu (on = test data, off = the live account).
			if ( 'test' === $mode ) {
				echo '<li>' . esc_html__( 'Open your XPay dashboard — in the left menu, at the very bottom, make sure the Test Mode toggle is on.', 'xpay-for-woocommerce' ) . '</li>';
			} else {
				echo '<li>' . esc_html__( 'Open your XPay dashboard — in the left menu, at the very bottom, turn the Test Mode toggle off to switch to your live account.', 'xpay-for-woocommerce' ) . '</li>';
			}
			echo '<li>' . esc_html__( 'Open Developer hub from the bottom-left corner of the page, then the API keys tab.', 'xpay-for-woocommerce' ) . '</li>';
			echo '<li>' . esc_html__( 'Under Restricted keys, click Create restricted key: name it (for example, WooCommerce), allow Checkout Sessions and Refunds, then click Create key.', 'xpay-for-woocommerce' ) . '</li>';
			/* translators: %s is the key prefix, rk_test_ or rk_live_. */
			echo '<li>' . esc_html( sprintf( __( 'Copy the new key — it starts with %s — and paste it into the Secret key field here. Revealing a key later first emails you a 6-digit code.', 'xpay-for-woocommerce' ), 'test' === $mode ? 'rk_test_' : 'rk_live_' ) ) . '</li>';
			/* translators: %s is the key prefix, pk_test_ or pk_live_. */
			echo '<li>' . esc_html( sprintf( __( 'Copy the matching Publishable key (starts with %s) from the Standard keys table on the same page.', 'xpay-for-woocommerce' ), 'test' === $mode ? 'pk_test_' : 'pk_live_' ) ) . '</li>';
			echo '</ol>';
			echo '</div>';
		}
		echo '<div class="xpay-adm__dialog-foot"><a class="xpay-adm__btn xpay-adm__btn--secondary xpay-adm__btn--sm" href="' . esc_url( XPay_Constants::DASHBOARD_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open XPay dashboard ↗', 'xpay-for-woocommerce' ) . '</a></div>';
		echo '</div></div>';

		// ── Webhook ──────────────────────────────────────────────────
		$url = home_url( '/?wc-api=' . XPay_Constants::WEBHOOK_ENDPOINT );
		echo '<div class="xpay-adm__dialog-backdrop xpay-adm__help-dialog" id="xpay-help-webhook" hidden>';
		echo '<div class="xpay-adm__dialog" role="dialog" aria-modal="true" aria-labelledby="xpay-help-webhook-title">';
		echo '<div class="xpay-adm__dialog-head"><h2 class="xpay-adm__dialog-title" id="xpay-help-webhook-title">' . esc_html__( 'Connect the webhook', 'xpay-for-woocommerce' ) . '</h2>';
		echo '<button type="button" class="xpay-adm__dialog-close" aria-label="' . esc_attr__( 'Close', 'xpay-for-woocommerce' ) . '">&times;</button></div>';

		echo '<p class="xpay-adm__help-intro">' . esc_html__( 'XPay confirms payments by sending signed events to this address — it is what marks orders paid. Each mode needs its own endpoint with its own secret.', 'xpay-for-woocommerce' ) . '</p>';
		echo '<div class="xpay-adm__help-url"><code class="xpay-adm__mono">' . esc_html( $url ) . '</code><button type="button" class="xpay-adm__link" data-xpay-copy="' . esc_attr( $url ) . '" data-xpay-copied-label="' . esc_attr__( 'Copied', 'xpay-for-woocommerce' ) . '">' . esc_html__( 'Copy', 'xpay-for-woocommerce' ) . '</button></div>';

		foreach ( array( 'test', 'live' ) as $mode ) {
			echo '<div class="xpay-adm__help-body" data-xpay-mode="' . esc_attr( $mode ) . '">';
			echo '<ol class="xpay-adm__help-steps">';
			if ( 'test' === $mode ) {
				echo '<li>' . esc_html__( 'Open your XPay dashboard — in the left menu, at the very bottom, make sure the Test Mode toggle is on.', 'xpay-for-woocommerce' ) . '</li>';
			} else {
				echo '<li>' . esc_html__( 'Open your XPay dashboard — in the left menu, at the very bottom, turn the Test Mode toggle off to switch to your live account.', 'xpay-for-woocommerce' ) . '</li>';
			}
			echo '<li>' . esc_html__( 'Open Developer hub from the bottom-left corner of the page, then the Webhooks tab, and click Add endpoint.', 'xpay-for-woocommerce' ) . '</li>';
			echo '<li>' . esc_html__( 'Paste your store\'s address above into the Endpoint URL field.', 'xpay-for-woocommerce' ) . '</li>';
			echo '<li>' . esc_html__( 'Under "Select events to listen to", tick exactly these two, then click Add endpoint:', 'xpay-for-woocommerce' );
			echo '<span class="xpay-adm__help-events"><code class="xpay-adm__mono">checkout.session.completed</code><code class="xpay-adm__mono">checkout.session.expired</code></span></li>';
			echo '<li>' . esc_html__( 'The Webhook signing secret is shown only once, right after the endpoint is created — copy the whsec_… value immediately and paste it into the signing secret field here, then save.', 'xpay-for-woocommerce' ) . '</li>';
			echo '</ol>';
			echo '</div>';
		}
		echo '<div class="xpay-adm__dialog-foot"><a class="xpay-adm__btn xpay-adm__btn--secondary xpay-adm__btn--sm" href="' . esc_url( XPay_Constants::DASHBOARD_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open XPay dashboard ↗', 'xpay-for-woocommerce' ) . '</a></div>';
		echo '</div></div>';
	}

	/** The guided-activation URL — where every explicit setup intent lands. */
	private static function setup_url(): string {
		return add_query_arg( 'xpay-setup', '1', admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . XPay_Constants::GATEWAY_ID ) );
	}

	/* ── Header band ─────────────────────────────────────────────────── */

	/**
	 * @param XPay_Gateway $gateway Gateway.
	 * @param bool         $fresh   Fresh-install state.
	 * @param bool         $live    Live mode selected.
	 * @param bool         $welcome Welcome landing (fresh, no setup intent yet).
	 */
	private static function header_band( XPay_Gateway $gateway, bool $fresh, bool $live, bool $welcome = false ): void {
		echo '<div class="xpay-adm__band">';
		echo '<span class="xpay-adm__wordmark-pill"><img src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/xpay-wordmark.svg' ) . '" alt="XPay"></span>';
		echo '<span class="xpay-adm__band-title">' . esc_html( $fresh && ! $welcome ? __( 'Set up XPay', 'xpay-for-woocommerce' ) : __( 'XPay for WooCommerce', 'xpay-for-woocommerce' ) ) . '</span>';

		if ( $welcome ) {
			// The landing carries its own promise; the band stays a calm
			// masthead with the one useful outbound link.
			echo '<a class="xpay-adm__band-btn" href="' . esc_url( XPay_Constants::DASHBOARD_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open XPay dashboard ↗', 'xpay-for-woocommerce' ) . '</a>';
			echo '</div>';
			return;
		}

		if ( $fresh ) {
			echo '<span class="xpay-adm__badge xpay-adm__badge--amber">' . esc_html__( 'Not connected', 'xpay-for-woocommerce' ) . '</span>';
			echo '<span class="xpay-adm__band-note">' . esc_html__( 'Three steps · about five minutes', 'xpay-for-woocommerce' ) . '</span>';
		} else {
			$validated = self::keys_validated( $gateway, $live );
			if ( $validated ) {
				echo '<span class="xpay-adm__badge xpay-adm__badge--green">' . esc_html( $live ? __( 'Connected — Live mode', 'xpay-for-woocommerce' ) : __( 'Connected — Test mode', 'xpay-for-woocommerce' ) ) . '</span>';
			} else {
				echo '<span class="xpay-adm__badge xpay-adm__badge--amber">' . esc_html( $live ? __( 'Live mode — keys not validated yet', 'xpay-for-woocommerce' ) : __( 'Test mode — keys not validated yet', 'xpay-for-woocommerce' ) ) . '</span>';
			}
			echo '<a class="xpay-adm__band-btn" href="' . esc_url( XPay_Constants::DASHBOARD_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open XPay dashboard ↗', 'xpay-for-woocommerce' ) . '</a>';
		}
		echo '</div>';
	}

	/* ── Fresh install: welcome landing ──────────────────────────────── */

	/**
	 * The first thing a merchant sees after installing — what XPay is and
	 * why to connect it, before any field asks for anything. Modeled on the
	 * flagship providers' welcome pages, in XPay's own design language.
	 * The Activate button (and the Payments page's "Complete setup") go
	 * straight to the guided steps; this page is only the front door.
	 *
	 * @param XPay_Gateway $gateway Gateway.
	 */
	private static function welcome( XPay_Gateway $gateway ): void {
		echo '<div class="xpay-adm__welcome">';

		// The official two-tone mark, inline so it needs no extra request.
		echo '<svg class="xpay-adm__welcome-mark" viewBox="0 0 342.5 207.5" role="img" aria-label="XPay">';
		echo '<path d="M96 0H28.4L136 103.8l35.3-33.1L96 0zM28.4 207.5h70.8l72.1-69.6-35.3-34.1L28.4 207.5zM0 85.7h56.9v37.9H0z" fill="#413df6"></path>';
		echo '<path d="M314.1 0h-67.6l-75.2 70.7-35.3 33.1 35.3 34.1 72 69.6h70.8L206.5 103.8 314.1 0z" fill="#3eb8eb"></path>';
		echo '<path d="M285.7 85.7h56.9v37.9h-56.9z" fill="#3eb8eb"></path>';
		echo '</svg>';

		echo '<h2 class="xpay-adm__welcome-title">' . esc_html__( 'Accept payments in Egypt, on your own store', 'xpay-for-woocommerce' ) . '</h2>';
		echo '<p class="xpay-adm__welcome-sub">' . esc_html__( 'Cards, valU and Fawry open in a secure XPay window over your checkout — shoppers never leave your site, and orders are confirmed only by signed webhooks.', 'xpay-for-woocommerce' ) . '</p>';

		// Capability strip.
		$methods = implode(
			' · ',
			array(
				XPay_Payment_Methods::label( XPay_Payment_Methods::CARD ),
				XPay_Payment_Methods::label( XPay_Payment_Methods::VALU ),
				XPay_Payment_Methods::label( XPay_Payment_Methods::FAWRY ),
			)
		);
		echo '<div class="xpay-adm__welcome-caps">';
		echo '<div class="xpay-adm__welcome-cap"><span class="xpay-adm__welcome-cap-label xpay-adm__mono">' . esc_html__( 'Payment methods', 'xpay-for-woocommerce' ) . '</span><span class="xpay-adm__welcome-cap-value">' . esc_html( $methods ) . '</span></div>';
		echo '<div class="xpay-adm__welcome-cap"><span class="xpay-adm__welcome-cap-label xpay-adm__mono">' . esc_html__( 'Refunds', 'xpay-for-woocommerce' ) . '</span><span class="xpay-adm__welcome-cap-value">' . esc_html__( 'Full & partial, in WooCommerce', 'xpay-for-woocommerce' ) . '</span></div>';
		echo '<div class="xpay-adm__welcome-cap"><span class="xpay-adm__welcome-cap-label xpay-adm__mono">' . esc_html__( 'Languages', 'xpay-for-woocommerce' ) . '</span><span class="xpay-adm__welcome-cap-value">' . esc_html__( 'Arabic & English, RTL ready', 'xpay-for-woocommerce' ) . '</span></div>';
		echo '</div>';

		// Method artwork (the same licensed assets the checkout rows use;
		// Fawry stays a text chip until design ships the official mark).
		echo '<div class="xpay-adm__welcome-art">';
		echo '<img src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/card-networks.svg' ) . '" alt="' . esc_attr__( 'Visa, Mastercard, Meeza', 'xpay-for-woocommerce' ) . '">';
		echo '<img class="xpay-adm__welcome-art-valu" src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/valu.svg' ) . '" alt="valU">';
		echo '<span class="xpay-adm__welcome-chip">' . esc_html( XPay_Payment_Methods::label( XPay_Payment_Methods::FAWRY ) ) . '</span>';
		echo '</div>';

		// The one call to action — straight to the guided steps.
		echo '<a class="xpay-adm__btn xpay-adm__btn--hero" href="' . esc_url( self::setup_url() ) . '">' . esc_html__( 'Activate XPay', 'xpay-for-woocommerce' ) . '</a>';
		echo '<p class="xpay-adm__welcome-note">' . esc_html__( 'Three steps · about five minutes', 'xpay-for-woocommerce' ) . '</p>';
		echo '<p class="xpay-adm__welcome-signup">' . esc_html__( 'New to XPay?', 'xpay-for-woocommerce' ) . ' <a href="https://xpay.app/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Create your merchant account →', 'xpay-for-woocommerce' ) . '</a></p>';

		echo '<div class="xpay-adm__welcome-divider"></div>';

		echo '<div class="xpay-adm__welcome-info">';
		echo '<div>';
		echo '<h3 class="xpay-adm__welcome-h">' . esc_html__( 'How it works', 'xpay-for-woocommerce' ) . '</h3>';
		echo '<p class="xpay-adm__welcome-how"><span>1.</span>' . esc_html__( 'The shopper clicks Place order and the XPay window opens over your own payment page.', 'xpay-for-woocommerce' ) . '</p>';
		echo '<p class="xpay-adm__welcome-how"><span>2.</span>' . esc_html__( 'Card details are entered inside XPay\'s PCI-certified window — they never touch your server.', 'xpay-for-woocommerce' ) . '</p>';
		echo '<p class="xpay-adm__welcome-how"><span>3.</span>' . esc_html__( 'A cryptographically signed webhook marks the order paid, and the receipt is stamped PAID.', 'xpay-for-woocommerce' ) . '</p>';
		echo '</div>';
		echo '<div>';
		echo '<h3 class="xpay-adm__welcome-h">' . esc_html__( 'Docs & help', 'xpay-for-woocommerce' ) . '</h3>';
		self::welcome_doc_row( 'docs/GETTING_STARTED.md', __( 'Getting started guide', 'xpay-for-woocommerce' ), 'xpay-adm__welcome-doc--first' );
		self::welcome_doc_row( 'docs/CONFIGURATION.md', __( 'Configuration reference', 'xpay-for-woocommerce' ), '' );
		self::welcome_doc_row( 'docs/TROUBLESHOOTING.md', __( 'Troubleshooting', 'xpay-for-woocommerce' ), 'xpay-adm__welcome-doc--last' );
		echo '</div>';
		echo '</div>';

		echo '<p class="xpay-adm__trust">' . esc_html__( 'Nothing goes live until you switch the mode yourself. Card details never touch your server.', 'xpay-for-woocommerce' ) . '</p>';
		echo '</div>';

		// No visible controls, but the page still sits inside WooCommerce's
		// settings form — carry every field so no conceivable submit of
		// this view could erase saved values. Unlike activation, 'enabled'
		// is carried as-is: reading a welcome page enables nothing.
		self::hidden_field( $gateway, 'enabled' );
		self::hidden_field( $gateway, 'mode' );
		self::hidden_field( $gateway, 'title' );
		self::hidden_field( $gateway, 'description' );
		self::hidden_field( $gateway, 'display_mode' );
		self::hidden_checkbox( $gateway, 'split_card' );
		self::hidden_checkbox( $gateway, 'split_valu' );
		self::hidden_checkbox( $gateway, 'split_fawry' );
		self::hidden_field( $gateway, 'test_api_key' );
		self::hidden_field( $gateway, 'test_publishable_key' );
		self::hidden_field( $gateway, 'test_webhook_secret' );
		self::hidden_field( $gateway, 'live_api_key' );
		self::hidden_field( $gateway, 'live_publishable_key' );
		self::hidden_field( $gateway, 'live_webhook_secret' );
		self::hidden_checkbox( $gateway, 'wpfunnels_force_standard_redirect' );
		self::hidden_checkbox( $gateway, 'debug' );
	}

	/**
	 * One joined row in the welcome's docs list. The guides ship inside the
	 * plugin, so the links open the bundled files themselves — no external
	 * docs site to go stale.
	 *
	 * @param string $rel_path Doc path relative to the plugin root.
	 * @param string $label    Translated link text.
	 * @param string $classes  Extra row classes (first/last radii).
	 */
	private static function welcome_doc_row( string $rel_path, string $label, string $classes ): void {
		echo '<div class="xpay-adm__welcome-doc ' . esc_attr( $classes ) . '">';
		echo '<a href="' . esc_url( XPAY_WC_PLUGIN_URL . $rel_path ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
		echo '</div>';
	}

	/* ── Fresh install: guided activation ────────────────────────────── */

	/**
	 * @param XPay_Gateway $gateway Gateway.
	 */
	private static function activation( XPay_Gateway $gateway ): void {
		echo '<div class="xpay-adm__steps">';

		// Step 1 — active: the test keys.
		echo '<div class="xpay-adm__step xpay-adm__step--active">';
		echo '<div class="xpay-adm__step-head"><span class="xpay-adm__step-no xpay-adm__step-no--active">1</span><span class="xpay-adm__step-title">' . esc_html__( 'Connect your test keys', 'xpay-for-woocommerce' ) . '</span>';
		self::help_icon( 'keys', __( 'How to get your API keys', 'xpay-for-woocommerce' ) );
		echo '</div>';
		echo '<p class="xpay-adm__step-sub">' . esc_html__( 'From your XPay dashboard → Developers → API keys. Test keys never move real money.', 'xpay-for-woocommerce' ) . '</p>';
		echo '<div class="xpay-adm__grid2 xpay-adm__step-body">';
		echo '<div><label class="xpay-adm__label" for="xpay-adm-test-key">' . esc_html__( 'Secret key', 'xpay-for-woocommerce' ) . '</label>';
		echo '<input id="xpay-adm-test-key" class="xpay-adm__input xpay-adm__mono" type="password" autocomplete="off" placeholder="rk_test_" name="' . esc_attr( self::name( $gateway, 'test_api_key' ) ) . '" value=""></div>';
		echo '<div><label class="xpay-adm__label" for="xpay-adm-test-pk">' . esc_html__( 'Publishable key', 'xpay-for-woocommerce' ) . '</label>';
		echo '<input id="xpay-adm-test-pk" class="xpay-adm__input xpay-adm__mono" type="text" autocomplete="off" placeholder="pk_test_" name="' . esc_attr( self::name( $gateway, 'test_publishable_key' ) ) . '" value=""></div>';
		echo '</div>';
		echo '<div class="xpay-adm__step-body"><button type="submit" name="save" value="save" class="xpay-adm__btn">' . esc_html__( 'Validate & save keys', 'xpay-for-woocommerce' ) . '</button></div>';
		echo '</div>';

		// Steps 2 and 3 — pending.
		echo '<div class="xpay-adm__step xpay-adm__step--pending"><span class="xpay-adm__step-no">2</span><span class="xpay-adm__step-title xpay-adm__step-title--muted">' . esc_html__( 'Connect the webhook', 'xpay-for-woocommerce' ) . '</span>';
		self::help_icon( 'webhook', __( 'How to set up the webhook', 'xpay-for-woocommerce' ) );
		echo '<span class="xpay-adm__step-hint">' . esc_html__( 'Unlocks after your keys validate — one URL to paste, one secret to copy back', 'xpay-for-woocommerce' ) . '</span></div>';
		echo '<div class="xpay-adm__step xpay-adm__step--pending xpay-adm__step--last"><span class="xpay-adm__step-no">3</span><span class="xpay-adm__step-title xpay-adm__step-title--muted">' . esc_html__( 'Place a test payment', 'xpay-for-woocommerce' ) . '</span><span class="xpay-adm__step-hint">' . esc_html__( 'We confirm the whole loop — window, webhook, receipt — end to end', 'xpay-for-woocommerce' ) . '</span></div>';

		echo '</div>';

		echo '<p class="xpay-adm__trust">' . esc_html__( 'Nothing goes live until you switch the mode yourself. Card details never touch your server.', 'xpay-for-woocommerce' ) . '</p>';

		// Every field this state does not show rides along hidden, so the
		// step-one save can never erase saved settings or defaults. The
		// gateway is enabled here on purpose: it stays invisible at
		// checkout until keys validate (needs_setup), and the activation
		// flow must end with a payable store, not a second hidden toggle.
		self::hidden_field( $gateway, 'enabled', 'yes' );
		self::hidden_field( $gateway, 'mode' );
		self::hidden_field( $gateway, 'title' );
		self::hidden_field( $gateway, 'description' );
		self::hidden_field( $gateway, 'display_mode' );
		self::hidden_checkbox( $gateway, 'split_card' );
		self::hidden_checkbox( $gateway, 'split_valu' );
		self::hidden_checkbox( $gateway, 'split_fawry' );
		self::hidden_field( $gateway, 'test_webhook_secret' );
		self::hidden_field( $gateway, 'live_api_key' );
		self::hidden_field( $gateway, 'live_publishable_key' );
		self::hidden_field( $gateway, 'live_webhook_secret' );
		self::hidden_checkbox( $gateway, 'wpfunnels_force_standard_redirect' );
		self::hidden_checkbox( $gateway, 'debug' );
	}

	/* ── Configured: status + grouped sections ───────────────────────── */

	/**
	 * @param XPay_Gateway $gateway Gateway.
	 * @param bool         $live    Live mode selected.
	 */
	private static function configured( XPay_Gateway $gateway, bool $live ): void {
		self::status_list( $gateway, $live );
		self::keys_section( $gateway, $live );
		self::webhook_section( $gateway, $live );
		self::checkout_section( $gateway );
		self::footer( $gateway );
	}

	/**
	 * @param XPay_Gateway $gateway Gateway.
	 * @param bool         $live    Live mode selected.
	 */
	private static function status_list( XPay_Gateway $gateway, bool $live ): void {
		$mode           = $live ? 'live' : 'test';
		$keys_ok        = self::keys_validated( $gateway, $live );
		$keys_present   = '' !== $gateway->get_option( $mode . '_api_key', '' );
		$secret_present = '' !== $gateway->get_option( $mode . '_webhook_secret', '' );
		$last_event     = (int) get_option( XPay_Constants::OPTION_LAST_WEBHOOK_AT, 0 );
		$paid_order     = self::latest_paid_order();

		echo '<div class="xpay-adm__list">';

		// Keys.
		$nudge = ! $live && $keys_ok && $secret_present && $last_event > 0 && null !== $paid_order;
		$last  = $nudge ? '' : 'xpay-adm__row--last';

		if ( $keys_ok ) {
			$masked = self::masked( (string) $gateway->get_option( $mode . '_api_key', '' ) ) . ' · ' . self::masked( (string) $gateway->get_option( $mode . '_publishable_key', '' ) );
			self::status_row( 'green', __( 'Keys validated', 'xpay-for-woocommerce' ), $masked, '', '', true, 'xpay-adm__row--first' );
		} elseif ( $keys_present ) {
			self::status_row( 'amber', __( 'Keys saved — not validated yet', 'xpay-for-woocommerce' ), __( 'Save changes runs a live check against the XPay API', 'xpay-for-woocommerce' ), '', '', false, 'xpay-adm__row--first' );
		} else {
			self::status_row( 'amber', __( 'No keys for the selected mode', 'xpay-for-woocommerce' ), __( 'Paste them below from your XPay dashboard', 'xpay-for-woocommerce' ), '', '', false, 'xpay-adm__row--first' );
		}

		// Webhook.
		if ( ! $secret_present ) {
			self::status_row( 'amber', __( 'Webhook not connected', 'xpay-for-woocommerce' ), __( 'Paste the signing secret below — the webhook is what marks orders paid', 'xpay-for-woocommerce' ), '', '' );
		} elseif ( $last_event > 0 ) {
			/* translators: %s is a human time difference, for example "2 minutes". */
			self::status_row( 'green', __( 'Webhook healthy', 'xpay-for-woocommerce' ), sprintf( __( 'signing secret saved · last event received %s ago', 'xpay-for-woocommerce' ), human_time_diff( $last_event ) ), admin_url( 'admin.php?page=xpay-log' ), __( 'View log', 'xpay-for-woocommerce' ) );
		} else {
			self::status_row( 'amber', __( 'Webhook waiting for its first event', 'xpay-for-woocommerce' ), __( 'signing secret saved · send a test event from your XPay dashboard', 'xpay-for-woocommerce' ), admin_url( 'admin.php?page=xpay-log' ), __( 'View log', 'xpay-for-woocommerce' ) );
		}

		// Test payment.
		if ( null !== $paid_order ) {
			/* translators: 1: order number, 2: formatted order total. */
			self::status_row( 'green', __( 'Payment confirmed end-to-end', 'xpay-for-woocommerce' ), sprintf( __( 'order #%1$s · %2$s', 'xpay-for-woocommerce' ), $paid_order->get_order_number(), wp_strip_all_tags( $paid_order->get_formatted_order_total() ) ), $paid_order->get_edit_order_url(), __( 'View order', 'xpay-for-woocommerce' ), false, $last );
		} else {
			self::status_row( 'amber', __( 'No payment yet', 'xpay-for-woocommerce' ), __( 'Place a test order to prove the whole loop', 'xpay-for-woocommerce' ), '', '', false, $last );
		}

		// Go-live nudge — only when everything above is green in test mode.
		if ( $nudge ) {
			echo '<div class="xpay-adm__row xpay-adm__row--nudge xpay-adm__row--last">';
			echo '<span class="xpay-adm__row-icon xpay-adm__row-icon--nudge">→</span>';
			echo '<span class="xpay-adm__row-main"><strong>' . esc_html__( 'Ready to go live', 'xpay-for-woocommerce' ) . '</strong><span class="xpay-adm__row-sub">' . esc_html__( 'paste your live keys, create a live webhook endpoint, switch the mode', 'xpay-for-woocommerce' ) . '</span></span>';
			echo '<button type="button" class="xpay-adm__btn xpay-adm__btn--sm" data-xpay-golive>' . esc_html__( 'Go live', 'xpay-for-woocommerce' ) . '</button>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * @param XPay_Gateway $gateway Gateway.
	 * @param bool         $live    Live mode selected.
	 */
	private static function keys_section( XPay_Gateway $gateway, bool $live ): void {
		echo '<div class="xpay-adm__section">';
		echo '<div class="xpay-adm__section-head">';
		echo '<div><div class="xpay-adm__section-title">' . esc_html__( 'Account & keys', 'xpay-for-woocommerce' );
		self::help_icon( 'keys', __( 'How to get your API keys', 'xpay-for-woocommerce' ) );
		echo '</div>';
		echo '<div class="xpay-adm__section-sub">' . esc_html__( 'Each mode keeps its own keys — switching never overwrites anything.', 'xpay-for-woocommerce' ) . '</div></div>';
		self::segment(
			self::name( $gateway, 'mode' ),
			array(
				'test' => __( 'Test', 'xpay-for-woocommerce' ),
				'live' => __( 'Live', 'xpay-for-woocommerce' ),
			),
			$live ? 'live' : 'test',
			'mode'
		);
		echo '</div>';

		echo '<div class="xpay-adm__list">';
		foreach ( array( 'test', 'live' ) as $mode ) {
			self::secret_row( $gateway, $mode . '_api_key', __( 'Secret key', 'xpay-for-woocommerce' ), 'rk_' . $mode . '_', false, 'xpay-adm__row--first', $mode );
			self::secret_row( $gateway, $mode . '_publishable_key', __( 'Publishable key', 'xpay-for-woocommerce' ), 'pk_' . $mode . '_', true, 'xpay-adm__row--last', $mode );
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @param XPay_Gateway $gateway Gateway.
	 * @param bool         $live    Live mode selected.
	 */
	private static function webhook_section( XPay_Gateway $gateway, bool $live ): void {
		$url            = home_url( '/?wc-api=' . XPay_Constants::WEBHOOK_ENDPOINT );
		$mode           = $live ? 'live' : 'test';
		$secret_present = '' !== $gateway->get_option( $mode . '_webhook_secret', '' );
		$last_event     = (int) get_option( XPay_Constants::OPTION_LAST_WEBHOOK_AT, 0 );

		echo '<div class="xpay-adm__section">';
		echo '<div class="xpay-adm__section-head"><div>';
		echo '<div class="xpay-adm__section-title">' . esc_html__( 'Webhook', 'xpay-for-woocommerce' );
		self::help_icon( 'webhook', __( 'How to set up the webhook', 'xpay-for-woocommerce' ) );
		echo '</div>';
		echo '<div class="xpay-adm__section-sub">' . esc_html__( 'XPay confirms orders through this endpoint — it is what marks orders paid.', 'xpay-for-woocommerce' ) . '</div>';
		echo '</div></div>';

		echo '<div class="xpay-adm__list">';

		echo '<div class="xpay-adm__row xpay-adm__row--first">';
		echo '<span class="xpay-adm__row-label">' . esc_html__( 'Endpoint URL', 'xpay-for-woocommerce' ) . '</span>';
		echo '<span class="xpay-adm__row-value xpay-adm__mono">' . esc_html( $url ) . '</span>';
		echo '<button type="button" class="xpay-adm__link" data-xpay-copy="' . esc_attr( $url ) . '">' . esc_html__( 'Copy', 'xpay-for-woocommerce' ) . '</button>';
		echo '</div>';

		foreach ( array( 'test', 'live' ) as $mode_block ) {
			self::secret_row( $gateway, $mode_block . '_webhook_secret', __( 'Signing secret', 'xpay-for-woocommerce' ), 'whsec_', false, '', $mode_block );
		}

		echo '<div class="xpay-adm__row xpay-adm__row--last">';
		echo '<span class="xpay-adm__row-label">' . esc_html__( 'Health', 'xpay-for-woocommerce' ) . '</span>';
		if ( $secret_present && $last_event > 0 ) {
			/* translators: %s is a human time difference, for example "2 minutes". */
			echo '<span class="xpay-adm__health xpay-adm__health--green"><span class="xpay-adm__dot"></span>' . esc_html( sprintf( __( 'Healthy — last event received %s ago', 'xpay-for-woocommerce' ), human_time_diff( $last_event ) ) ) . '</span>';
		} elseif ( $secret_present ) {
			echo '<span class="xpay-adm__health xpay-adm__health--amber"><span class="xpay-adm__dot"></span>' . esc_html__( 'No events received yet — send a test event from your XPay dashboard', 'xpay-for-woocommerce' ) . '</span>';
		} else {
			echo '<span class="xpay-adm__health xpay-adm__health--amber"><span class="xpay-adm__dot"></span>' . esc_html__( 'Signing secret missing for the selected mode', 'xpay-for-woocommerce' ) . '</span>';
		}
		echo '<a class="xpay-adm__link" href="' . esc_url( admin_url( 'admin.php?page=xpay-log' ) ) . '">' . esc_html__( 'View in XPay Log', 'xpay-for-woocommerce' ) . '</a>';
		echo '</div>';

		echo '</div>';
		echo '</div>';
	}

	/**
	 * @param XPay_Gateway $gateway Gateway.
	 */
	private static function checkout_section( XPay_Gateway $gateway ): void {
		echo '<div class="xpay-adm__section">';
		echo '<div class="xpay-adm__section-head">';
		echo '<div><div class="xpay-adm__section-title">' . esc_html__( 'Checkout appearance', 'xpay-for-woocommerce' ) . '</div>';
		echo '<div class="xpay-adm__section-sub">' . esc_html__( 'Separate options open the payment window directly on the shopper’s method.', 'xpay-for-woocommerce' ) . '</div></div>';
		self::segment(
			self::name( $gateway, 'display_mode' ),
			array(
				'combined' => __( 'One XPay option', 'xpay-for-woocommerce' ),
				'split'    => __( 'Separate options', 'xpay-for-woocommerce' ),
			),
			(string) $gateway->get_option( 'display_mode', 'combined' ),
			'display'
		);
		echo '</div>';

		echo '<div class="xpay-adm__tiles">';
		foreach ( XPay_Payment_Methods::SPLITTABLE as $type ) {
			$key     = XPay_Payment_Methods::setting_key( $type );
			$checked = 'yes' === $gateway->get_option( $key, 'no' );
			$icon    = XPay_Payment_Methods::icon_url( $type );
			echo '<label class="xpay-adm__tile' . ( $checked ? ' is-on' : '' ) . '">';
			echo '<input type="checkbox" name="' . esc_attr( self::name( $gateway, $key ) ) . '" value="1"' . checked( $checked, true, false ) . '>';
			if ( '' !== $icon ) {
				echo '<img src="' . esc_url( $icon ) . '" alt="">';
				echo '<span class="xpay-adm__tile-name">' . esc_html( XPay_Payment_Methods::label( $type ) ) . '</span>';
			} else {
				echo '<span class="xpay-adm__tile-name xpay-adm__tile-name--brand">' . esc_html( XPay_Payment_Methods::label( $type ) ) . '</span>';
			}
			echo '<span class="xpay-adm__switch" aria-hidden="true"></span>';
			echo '</label>';
		}
		echo '</div>';

		echo '<div class="xpay-adm__grid2">';
		echo '<div><label class="xpay-adm__label" for="xpay-adm-title">' . esc_html__( 'Title at checkout', 'xpay-for-woocommerce' ) . '</label>';
		echo '<input id="xpay-adm-title" class="xpay-adm__input" type="text" name="' . esc_attr( self::name( $gateway, 'title' ) ) . '" value="' . esc_attr( (string) $gateway->get_option( 'title', 'XPay' ) ) . '"></div>';
		echo '<div><label class="xpay-adm__label" for="xpay-adm-desc">' . esc_html__( 'Description', 'xpay-for-woocommerce' ) . '</label>';
		echo '<input id="xpay-adm-desc" class="xpay-adm__input" type="text" name="' . esc_attr( self::name( $gateway, 'description' ) ) . '" value="' . esc_attr( (string) $gateway->get_option( 'description', '' ) ) . '"></div>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * @param XPay_Gateway $gateway Gateway.
	 */
	private static function footer( XPay_Gateway $gateway ): void {
		echo '<div class="xpay-adm__footer">';
		self::switch_control( $gateway, 'enabled', __( 'Enable XPay', 'xpay-for-woocommerce' ) );
		self::switch_control( $gateway, 'wpfunnels_force_standard_redirect', __( 'WPFunnels safeguard', 'xpay-for-woocommerce' ) );
		self::switch_control( $gateway, 'debug', __( 'Diagnostic logging', 'xpay-for-woocommerce' ) );
		echo '<button type="submit" name="save" value="save" class="xpay-adm__btn xpay-adm__btn--save">' . esc_html__( 'Save changes', 'xpay-for-woocommerce' ) . '</button>';
		echo '</div>';
	}

	/* ── Primitives ──────────────────────────────────────────────────── */

	/**
	 * WooCommerce's field name for a settings key.
	 *
	 * @param XPay_Gateway $gateway Gateway (carries plugin_id + id).
	 * @param string       $key     Settings key.
	 */
	private static function name( XPay_Gateway $gateway, string $key ): string {
		return $gateway->plugin_id . $gateway->id . '_' . $key;
	}

	/**
	 * Dashboard-style masking: known prefix + eight dots, never the value.
	 *
	 * @param string $value Stored secret.
	 */
	private static function masked( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		foreach ( array( 'rk_test_', 'rk_live_', 'pk_test_', 'pk_live_', 'whsec_' ) as $prefix ) {
			if ( 0 === strpos( $value, $prefix ) ) {
				return $prefix . '••••••••';
			}
		}
		return substr( $value, 0, 4 ) . '••••••••';
	}

	/**
	 * A joined-list row holding one secret: masked value, Reveal/Replace,
	 * and the real (hidden) input that actually posts.
	 *
	 * @param XPay_Gateway $gateway     Gateway.
	 * @param string       $key         Settings key.
	 * @param string       $label       Row label.
	 * @param string       $placeholder Expected key prefix, shown when empty.
	 * @param bool         $copyable    Offer Copy (publishable keys only).
	 * @param string       $classes     Extra row classes (joined-list radius).
	 * @param string       $mode        Mode this row belongs to ('' = both).
	 */
	private static function secret_row( XPay_Gateway $gateway, string $key, string $label, string $placeholder, bool $copyable = false, string $classes = '', string $mode = '' ): void {
		$value = (string) $gateway->get_option( $key, '' );
		echo '<div class="xpay-adm__row' . esc_attr( '' !== $classes ? ' ' . $classes : '' ) . '" data-xpay-secret' . ( '' !== $mode ? ' data-xpay-mode="' . esc_attr( $mode ) . '"' : '' ) . '>';
		echo '<span class="xpay-adm__row-label">' . esc_html( $label ) . '</span>';
		if ( '' !== $value ) {
			echo '<span class="xpay-adm__row-value xpay-adm__mono" data-xpay-masked>' . esc_html( self::masked( $value ) ) . '</span>';
		} else {
			echo '<span class="xpay-adm__row-value xpay-adm__row-value--empty" data-xpay-masked>' . esc_html__( 'Not set', 'xpay-for-woocommerce' ) . '</span>';
		}
		echo '<input class="xpay-adm__input xpay-adm__mono" type="password" autocomplete="off" placeholder="' . esc_attr( $placeholder ) . '" name="' . esc_attr( self::name( $gateway, $key ) ) . '" value="' . esc_attr( $value ) . '" hidden>';
		if ( '' !== $value ) {
			echo '<button type="button" class="xpay-adm__link" data-xpay-reveal>' . esc_html__( 'Reveal', 'xpay-for-woocommerce' ) . '</button>';
			if ( $copyable ) {
				echo '<button type="button" class="xpay-adm__link" data-xpay-copy-input>' . esc_html__( 'Copy', 'xpay-for-woocommerce' ) . '</button>';
			}
		}
		echo '<button type="button" class="xpay-adm__link" data-xpay-replace>' . esc_html( '' === $value ? __( 'Add', 'xpay-for-woocommerce' ) : __( 'Replace', 'xpay-for-woocommerce' ) ) . '</button>';
		echo '</div>';
	}

	/**
	 * Segmented control over a pair of real radio inputs.
	 *
	 * @param string $field_name POST field name.
	 * @param array  $options    value => label.
	 * @param string $current    Selected value.
	 * @param string $role       JS hook: 'mode' or 'display'.
	 */
	private static function segment( string $field_name, array $options, string $current, string $role ): void {
		echo '<span class="xpay-adm__segment" data-xpay-segment="' . esc_attr( $role ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<label class="xpay-adm__seg' . ( $current === (string) $value ? ' is-active' : '' ) . '">';
			echo '<input type="radio" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( (string) $value ) . '"' . checked( $current, (string) $value, false ) . '>';
			echo '<span>' . esc_html( $label ) . '</span>';
			echo '</label>';
		}
		echo '</span>';
	}

	/**
	 * Toggle switch over a real checkbox.
	 *
	 * @param XPay_Gateway $gateway Gateway.
	 * @param string       $key     Settings key.
	 * @param string       $label   Visible label.
	 */
	private static function switch_control( XPay_Gateway $gateway, string $key, string $label ): void {
		$checked = 'yes' === $gateway->get_option( $key, 'no' );
		echo '<label class="xpay-adm__toggle">';
		echo '<input type="checkbox" name="' . esc_attr( self::name( $gateway, $key ) ) . '" value="1"' . checked( $checked, true, false ) . '>';
		echo '<span class="xpay-adm__switch" aria-hidden="true"></span>';
		echo '<span>' . esc_html( $label ) . '</span>';
		echo '</label>';
	}

	/**
	 * One status row in the joined list.
	 *
	 * @param string $tone     'green' or 'amber'.
	 * @param string $title    Bold lead.
	 * @param string $sub      Muted detail.
	 * @param string $link     Action URL ('' for none).
	 * @param string $link_txt Action label.
	 * @param bool   $mono_sub Detail is mono (key prefixes).
	 * @param string $classes  Extra row classes (joined-list radius).
	 */
	private static function status_row( string $tone, string $title, string $sub, string $link, string $link_txt, bool $mono_sub = false, string $classes = '' ): void {
		echo '<div class="xpay-adm__row' . esc_attr( '' !== $classes ? ' ' . $classes : '' ) . '">';
		echo '<span class="xpay-adm__row-icon xpay-adm__row-icon--' . esc_attr( $tone ) . '">' . ( 'green' === $tone ? '✓' : '!' ) . '</span>';
		echo '<span class="xpay-adm__row-main"><strong>' . esc_html( $title ) . '</strong><span class="xpay-adm__row-sub' . ( $mono_sub ? ' xpay-adm__mono' : '' ) . '">' . esc_html( $sub ) . '</span></span>';
		if ( '' !== $link ) {
			echo '<a class="xpay-adm__link" href="' . esc_url( $link ) . '">' . esc_html( $link_txt ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * Hidden carrier for a field the current state does not render.
	 *
	 * @param XPay_Gateway $gateway  Gateway.
	 * @param string       $key      Settings key.
	 * @param string|null  $override Value to force (null = stored/default).
	 */
	private static function hidden_field( XPay_Gateway $gateway, string $key, ?string $override = null ): void {
		$value = null !== $override ? $override : (string) $gateway->get_option( $key, '' );
		echo '<input type="hidden" name="' . esc_attr( self::name( $gateway, $key ) ) . '" value="' . esc_attr( $value ) . '">';
	}

	/**
	 * Hidden carrier for a checkbox: present only when currently on —
	 * WooCommerce reads absence as 'no'.
	 *
	 * @param XPay_Gateway $gateway Gateway.
	 * @param string       $key     Settings key.
	 */
	private static function hidden_checkbox( XPay_Gateway $gateway, string $key ): void {
		if ( 'yes' === $gateway->get_option( $key, 'no' ) ) {
			echo '<input type="hidden" name="' . esc_attr( self::name( $gateway, $key ) ) . '" value="1">';
		}
	}

	/**
	 * Whether the stored save-time validation proof covers the current mode
	 * and keys still exist for it.
	 *
	 * @param XPay_Gateway $gateway Gateway.
	 * @param bool         $live    Live mode selected.
	 */
	private static function keys_validated( XPay_Gateway $gateway, bool $live ): bool {
		$mode = $live ? 'live' : 'test';
		if ( '' === $gateway->get_option( $mode . '_api_key', '' ) || '' === $gateway->get_option( $mode . '_publishable_key', '' ) ) {
			return false;
		}
		$proof = get_option( XPay_Constants::OPTION_KEY_VALIDATED, array() );
		return is_array( $proof ) && isset( $proof['mode'] ) && $proof['mode'] === $mode;
	}

	/**
	 * The most recent paid order on any XPay gateway id, or null.
	 */
	private static function latest_paid_order(): ?WC_Order {
		$ids = array_merge(
			array( XPay_Constants::GATEWAY_ID ),
			array_map( array( 'XPay_Payment_Methods', 'gateway_id' ), XPay_Payment_Methods::SPLITTABLE )
		);
		foreach ( $ids as $gateway_id ) {
			$found = wc_get_orders(
				array(
					'payment_method' => $gateway_id,
					'status'         => array( 'processing', 'completed' ),
					'limit'          => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
			if ( is_array( $found ) && isset( $found[0] ) && $found[0] instanceof WC_Order ) {
				return $found[0];
			}
		}
		return null;
	}
}
