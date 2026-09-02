<?php
/**
 * XPay_Constants
 *
 * Plugin-wide constants: API/SDK hosts, option and order-meta keys, and the
 * redirect/iframe host allowlist. One authoritative registry — never inline
 * a host, meta key, or option name at a call site.
 *
 * Adding a new allowed host:
 *   1. Add it to ALLOWED_XPAY_HOSTS below.
 *   2. Say in a comment which XPay surface serves it.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Constants {

	/**
	 * Cache-busting version for an enqueued plugin asset. The plugin
	 * version alone left browsers serving stale CSS/JS whenever a file
	 * changed within one release (every dev/test iteration); the mtime
	 * suffix changes the URL the moment the file does.
	 *
	 * @param string $rel_path Asset path relative to the plugin root.
	 */
	public static function asset_version( string $rel_path ): string {
		$path  = XPAY_WC_PLUGIN_DIR . $rel_path;
		$mtime = is_file( $path ) ? filemtime( $path ) : false;
		return XPAY_WC_VERSION . ( false === $mtime ? '' : '.' . $mtime );
	}

	/**
	 * Business API base. Overridable for staging via the XPAY_WC_API_BASE
	 * wp-config constant — a deploy-time choice, deliberately NOT a settings
	 * field: merchants must never be able to point live credentials at an
	 * arbitrary host.
	 */
	public static function api_base(): string {
		if ( defined( 'XPAY_WC_API_BASE' ) && is_string( XPAY_WC_API_BASE ) ) {
			return untrailingslashit( XPAY_WC_API_BASE );
		}
		return 'https://api.xpay.app';
	}

	/**
	 * OAuth 2.1 base for Connect with XPay: the issuer every connect
	 * endpoint hangs off ({base}/oauth2/register, /oauth2/authorize,
	 * /oauth2/token). Derived from
	 * api_base() so the XPAY_WC_API_BASE staging override carries the
	 * OAuth surface with it; a separate override would let the two point
	 * at different environments, which can only produce keys that fail.
	 */
	public static function oauth_base(): string {
		return self::api_base() . '/api/auth';
	}

	/*
	 * Connect with XPay state, both halves.
	 *
	 * CONNECT_CLIENT is this store's OAuth client registration: client_id,
	 * the exact redirect_uri it was registered with (redirect matching is
	 * exact, so the stored URI is the one the exchange must repeat), when
	 * it was created, and when a connect last completed through it.
	 * Permanent — registration is once per install.
	 *
	 * CONNECT_FLOW is one in-progress connect: the state token, the PKCE
	 * verifier, the mode, the initiating user and the start time. Written
	 * when the merchant clicks Connect, consumed (deleted) by the callback
	 * whatever the outcome — single use is what makes the state token an
	 * anti-forgery proof.
	 */
	const OPTION_CONNECT_CLIENT = 'xpay_wc_connect_client';
	const OPTION_CONNECT_FLOW   = 'xpay_wc_connect_flow';

	/**
	 * Browser SDK (IIFE build, served by the checkout app).
	 * The /v1/ path segment is the SDK's own compatibility pin.
	 */
	public static function sdk_url(): string {
		if ( defined( 'XPAY_WC_SDK_URL' ) && is_string( XPAY_WC_SDK_URL ) ) {
			return XPAY_WC_SDK_URL;
		}
		return 'https://checkout.xpay.app/v1/sdk.js';
	}

	/**
	 * Hosts the plugin will allow in XPay-provided redirect/hosted-checkout
	 * URLs. esc_url() blocks bad schemes but NOT untrusted hosts, so every
	 * URL that came from an API response is checked against this list before
	 * the browser is sent to it.
	 */
	const ALLOWED_XPAY_HOSTS = array(
		'checkout.xpay.app', // Hosted checkout app.
		'api.xpay.app',      // Business API.
		'xpay.app',
	);

	/*
	 * Order meta keys. The XPay resource IDs are stored verbatim (cs_…, pi_…)
	 * for support traceability.
	 */
	const META_SESSION_ID     = '_xpay_session_id';
	const META_CLIENT_SECRET  = '_xpay_client_secret';
	const META_PAYMENT_INTENT = '_xpay_payment_intent_id';
	const META_ATTEMPT        = '_xpay_session_attempt';

	/**
	 * How many times this order's session has been repriced. Feeds the
	 * reprice Idempotency-Key: a key derived from the amount alone
	 * replays when the shopper edits the cart A -> B -> A -> B (the second
	 * "B" PATCH reuses the first's key and body, the platform replays the
	 * stored response without re-applying, and the session is left at "A"
	 * while the shopper sees "B" forever). The sequence makes every
	 * reprice a new operation; re-applying an identical full replacement
	 * on a transport retry is harmless.
	 */
	const META_REPRICE_SEQ      = '_xpay_reprice_seq';
	const META_PROCESSED_EVENTS = '_xpay_processed_events';
	const META_CUSTOMER_ID      = '_xpay_customer_id';


	/**
	 * Set once a completed-but-unpaid session put this order on hold to wait
	 * for a deferred payment (a Fawry reference the shopper pays later).
	 *
	 * Written by XPay_Order_Sync::mark_awaiting_payment() so a redelivered
	 * `completed` event under a fresh event id does not re-note the order,
	 * and so mark_expired() can refuse to touch an order whose fate now
	 * belongs to the async_payment events. Never cleared: the async success
	 * path completes the order through payment_complete(), which moves it
	 * out of every state this marker guards.
	 */
	const META_AWAITING_PAYMENT = '_xpay_awaiting_payment';

	/**
	 * Set when a payment arrived for an order that was already cancelled.
	 *
	 * The status cannot carry this. Parking the order moves it to on-hold,
	 * which erases the `cancelled` the guard tested — and on-hold is itself
	 * in WooCommerce's PAYMENT_COMPLETE_STATUSES, so the NEXT delivery of
	 * the same payment sails past the guard and completes the order. Two
	 * ordinary routes deliver twice: webhook then thank-you check, and a
	 * redelivery under a fresh event id, which the id-keyed dedupe does not
	 * catch.
	 *
	 * So the fact is written down instead of being inferred from the
	 * status. It is never cleared: a merchant who decides to fulfil moves
	 * the order themselves, and this must not re-arm behind them.
	 */
	const META_PAID_AFTER_CANCEL = '_xpay_paid_after_cancel';

	/**
	 * Money arrived on a session this order had already left behind, and a
	 * human was asked to decide about it.
	 *
	 * The same reasoning as the marker above, for the other park. That one
	 * moves a cancelled order to on-hold; this one parks an order whose
	 * payment landed on a superseded session. Both leave the order on-hold,
	 * which is in PAYMENT_COMPLETE_STATUSES and is not is_paid(), so
	 * anything that later calls mark_paid() completes it and the park is
	 * undone. The reachable route is the shopper paying the CURRENT session
	 * afterwards: the thank-you re-check finds that session genuinely paid
	 * and completes an order carrying two payments, burying the note that
	 * asked someone to look at the first one.
	 *
	 * Never cleared, for the same reason: a merchant who decides to fulfil
	 * moves the order themselves.
	 */
	const META_SUPERSEDED_PARKED = '_xpay_superseded_parked';

	/**
	 * Session ids this order left behind (bounded list, newest last).
	 * Written whenever a new session supersedes an old one. A paid event
	 * carrying one of these ids is provably THIS order's money on an
	 * outdated session (expire failed or raced, shopper finished it from
	 * an old tab or link) — it parks the order on-hold for a human
	 * instead of being dropped as anonymous. Foreign session ids still
	 * fail ownership outright.
	 */
	const META_SUPERSEDED_SESSIONS = '_xpay_superseded_sessions';

	/**
	 * XPay refund ids (re_…) recorded after each refund this plugin
	 * completed. The COUNT feeds the deterministic refund Idempotency-Key:
	 * a retry of a refund whose HTTP response was lost composes the same
	 * key (nothing was recorded), so the platform replays the original
	 * refund instead of paying out twice; a genuinely new refund follows a
	 * recorded success and composes a fresh key.
	 */
	const META_REFUND_IDS = '_xpay_refund_ids';

	/**
	 * Last successful save-time key validation: array{mode: string,
	 * validated_at: int}. Written by process_admin_options, cleared when a
	 * key fails validation or is removed. The settings screen's "Connected"
	 * badge reads it — a badge must never claim more than a real API call
	 * proved.
	 */
	const OPTION_KEY_VALIDATED = 'xpay_wc_key_validated';

	/**
	 * A stable, non-reversible stamp for one key pair.
	 *
	 * Stored alongside the validation proof so the settings screen can tell
	 * "these keys were proved" from "some keys were proved once". It has to
	 * exist because WooCommerce's REST settings route writes gateway
	 * settings without ever calling process_admin_options(), which is where
	 * validation lives — so a key can change with nothing noticing.
	 *
	 * Salted with the site's own auth salt so the digest cannot be compared
	 * against a table of known keys lifted from another store's options.
	 *
	 * @param string $secret_key      The secret (rk_/sk_) key.
	 * @param string $publishable_key The publishable (pk_) key.
	 */
	public static function key_fingerprint( string $secret_key, string $publishable_key ): string {
		$salt = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '';
		return substr( hash( 'sha256', $salt . '|' . $secret_key . '|' . $publishable_key ), 0, 32 );
	}

	/*
	 * When money first completed on each plane.
	 *
	 * Per plane because an order records no mode of its own: nothing on it
	 * says whether a test key or a live key took the payment, and the
	 * refund path just uses whichever key is configured now. So counting
	 * orders cannot answer "has THIS plane taken a payment", and a store
	 * that took one test payment would otherwise report its live side as
	 * finished setup, which is the one moment that answer matters.
	 */
	const OPTION_FIRST_PAID_AT_TEST = 'xpay_wc_first_paid_at_test';
	const OPTION_FIRST_PAID_AT_LIVE = 'xpay_wc_first_paid_at_live';

	/**
	 * Where the first completed payment is recorded, per plane.
	 *
	 * @param bool $live_mode Which plane.
	 */
	public static function first_paid_option( bool $live_mode ): string {
		return $live_mode ? self::OPTION_FIRST_PAID_AT_LIVE : self::OPTION_FIRST_PAID_AT_TEST;
	}

	/*
	 * The merchant's own XPay account id, snapshotted from any session
	 * response (which carries it) so the plugin can build a deep link
	 * straight to a payment in the dashboard. One option per plane: test and
	 * live are separate accounts.
	 *
	 * Learned rather than configured. Asking a merchant to find and paste an
	 * account id to make a link work would be a setup step for a
	 * convenience, and the API already tells us on the first session.
	 */
	const OPTION_MERCHANT_ID_TEST = 'xpay_wc_merchant_id_test';
	const OPTION_MERCHANT_ID_LIVE = 'xpay_wc_merchant_id_live';

	/**
	 * The stored merchant-id option name for one plane.
	 *
	 * @param bool $live_mode Which plane.
	 */
	public static function merchant_id_option( bool $live_mode ): string {
		return $live_mode ? self::OPTION_MERCHANT_ID_LIVE : self::OPTION_MERCHANT_ID_TEST;
	}

	/*
	 * What THIS account can actually charge, per plane: GET /account's
	 * supportedCurrencies as a map of uppercase currency code to the
	 * payment method types that can charge it (e.g. EGP carries card,
	 * valu and fawry while USD is card-only). Written at key save, by the
	 * admin refresh action, and by the checkout's quiet re-read once the
	 * cache outlives XPay_Gateway::ACCOUNT_CACHE_SECONDS. Read by the
	 * availability gates — the store-currency gate reads the keys, the
	 * per-method checkout rows read the values. The plane split
	 * matters: test and live are separate accounts with separate
	 * processor configs, so one shared map would let a currency enabled
	 * only in test keep the gateway visible in live.
	 */
	const OPTION_ACCOUNT_METHODS_TEST = 'xpay_wc_account_methods_test';
	const OPTION_ACCOUNT_METHODS_LIVE = 'xpay_wc_account_methods_live';

	/**
	 * The cached account currency-to-methods map option name for one plane.
	 *
	 * @param bool $live_mode Which plane.
	 */
	public static function account_methods_option( bool $live_mode ): string {
		return $live_mode ? self::OPTION_ACCOUNT_METHODS_LIVE : self::OPTION_ACCOUNT_METHODS_TEST;
	}

	/**
	 * When the account facts for one plane were last read (or last
	 * attempted), as UTC seconds. The shelf life the checkout's quiet
	 * refresh measures against — see XPay_Gateway::ACCOUNT_CACHE_SECONDS.
	 *
	 * @param bool $live_mode Which plane.
	 */
	public static function account_checked_option( bool $live_mode ): string {
		return 'xpay_wc_account_checked_at_' . ( $live_mode ? 'live' : 'test' );
	}

	/*
	 * The merchant's Payment Methods tab, both halves.
	 *
	 * ENABLED_METHODS is the checked list: which of the account's methods
	 * this store offers. ABSENT means every account method is offered —
	 * the state every store is in until the merchant first edits the tab,
	 * so installing this version changes nothing. Once the option exists
	 * it is authoritative: a method the account gains later arrives
	 * UNCHECKED, the same way Stripe's tab treats new methods, because a
	 * payment option appearing on a checkout nobody asked to change is a
	 * surprise in the wrong direction.
	 *
	 * METHOD_ORDER is the display order of the rows, as wire types. One
	 * list for both planes (Stripe's stripe_upe_payment_method_order is
	 * plane-less too): the order is the merchant's presentation choice,
	 * not an account fact. It is healed against the account map on read
	 * (vanished methods drop, new ones append) and persisted only when
	 * the merchant saves an order, so a plane flip can never quietly
	 * rewrite it.
	 */
	const OPTION_ENABLED_METHODS = 'xpay_wc_enabled_methods';
	const OPTION_METHOD_ORDER    = 'xpay_wc_method_order';

	/** The merchant-facing XPay dashboard (production host). */
	const DASHBOARD_URL = 'https://app.xpay.app';

	/**
	 * Deep link to one payment in the merchant's dashboard.
	 *
	 * The dashboard keys its transaction page by payment-intent id under the
	 * merchant's own segment: /{locale}/{merchantId}/transactions/{pi_…}.
	 *
	 * Returns '' when the merchant id has not been learned yet — a link that
	 * lands on a 404 is worse than no link.
	 *
	 * @param string $payment_intent_id pi_… id.
	 * @param bool   $live_mode         Which plane the payment belongs to.
	 */
	public static function payment_dashboard_url( string $payment_intent_id, bool $live_mode ): string {
		$merchant_id = (string) get_option( self::merchant_id_option( $live_mode ), '' );
		if ( '' === $merchant_id || '' === $payment_intent_id ) {
			return '';
		}
		$locale = 0 === strpos( get_locale(), 'ar' ) ? 'ar' : 'en';
		return self::DASHBOARD_URL . '/' . $locale . '/' . rawurlencode( $merchant_id ) . '/transactions/' . rawurlencode( $payment_intent_id );
	}

	/**
	 * User-meta key holding the shopper's XPay Customer id (cus_…), split
	 * per mode: test and live are separate XPay planes with separate
	 * customer records — one shared key would leak test ids into live
	 * sessions after go-live.
	 *
	 * @param bool $live_mode Which plane the id belongs to.
	 */
	public static function customer_user_meta_key( bool $live_mode ): string {
		return $live_mode ? '_xpay_customer_id_live' : '_xpay_customer_id_test';
	}

	/** Gateway id — also the settings option suffix (woocommerce_xpay_settings). */
	const GATEWAY_ID = 'xpay';

	/**
	 * The currency XPay actually moves money in.
	 *
	 * XPay settles supported foreign-currency payments in EGP. A store may
	 * price in any of the
	 * currencies XPay_Money knows; that price is converted at a rate locked
	 * when the session is created, and every charge, payout and refund is
	 * denominated here. Named rather than written 'EGP' inline so the
	 * places that depend on the distinction are greppable — the refund path
	 * above all, where an amount travels with no currency attached to it.
	 */
	const SETTLEMENT_CURRENCY = 'EGP';

	/**
	 * True for the combined gateway AND its per-method rows (xpay_card,
	 * xpay_valu, …). Every "was this order paid through us" check must use
	 * this, not an exact GATEWAY_ID comparison — a ValU-row order carries
	 * payment_method 'xpay_valu' and is just as much ours.
	 *
	 * @param string $gateway_id A WooCommerce payment method id.
	 */
	public static function is_xpay_gateway( string $gateway_id ): bool {
		return self::GATEWAY_ID === $gateway_id || 0 === strpos( $gateway_id, self::GATEWAY_ID . '_' );
	}

	/** WC-API endpoint slug for the webhook receiver. */
	const WEBHOOK_ENDPOINT = 'xpay_webhook';

	/**
	 * True when a URL is HTTPS and its host is one of ours (exact match or
	 * subdomain of xpay.app). The scheme check is deliberate: esc_url()
	 * allows http, and every URL passing this gate is browser-bound —
	 * a downgraded scheme is as untrusted as a foreign host. Localhost
	 * (http allowed) only when the API base itself was overridden for
	 * local development.
	 *
	 * @param string $url URL returned by the XPay API.
	 */
	public static function is_allowed_xpay_url( string $url ): bool {
		// Parser-differential guard: PHP reads https://evil.com\@xpay.app/
		// as host xpay.app, but a browser (WHATWG treats \ as /) navigates
		// to evil.com — so a backslash or userinfo anywhere defeats the
		// host check below. Neither ever appears in a legitimate XPay URL;
		// reject on sight rather than trying to parse like a browser.
		if ( false !== strpos( $url, '\\' ) || null !== wp_parse_url( $url, PHP_URL_USER ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( $host );

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$scheme = is_string( $scheme ) ? strtolower( $scheme ) : '';

		// '[::1]' because wp_parse_url() keeps the brackets on IPv6 hosts.
		// The loopback escape hatch opens ONLY when the API base itself
		// points at loopback — a staging override to a real staging domain
		// must not quietly start trusting localhost URLs.
		$local_hosts = array( 'localhost', '127.0.0.1', '[::1]' );
		if ( defined( 'XPAY_WC_API_BASE' ) && in_array( $host, $local_hosts, true ) ) {
			$base_host = wp_parse_url( self::api_base(), PHP_URL_HOST );
			$base_host = is_string( $base_host ) ? strtolower( $base_host ) : '';
			return in_array( $base_host, $local_hosts, true ) && ( 'https' === $scheme || 'http' === $scheme );
		}

		if ( 'https' !== $scheme ) {
			return false;
		}
		foreach ( self::ALLOWED_XPAY_HOSTS as $allowed ) {
			$suffix = '.' . $allowed;
			if ( $allowed === $host || 0 === substr_compare( $host, $suffix, -strlen( $suffix ) ) ) {
				return true;
			}
		}
		return false;
	}
}
