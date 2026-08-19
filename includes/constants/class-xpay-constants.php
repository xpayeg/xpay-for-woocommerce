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
	 * arbitrary host (the v2 SSRF lesson).
	 */
	public static function api_base(): string {
		if ( defined( 'XPAY_WC_API_BASE' ) && is_string( XPAY_WC_API_BASE ) ) {
			return untrailingslashit( XPAY_WC_API_BASE );
		}
		return 'https://api.xpay.app';
	}

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
	 * the browser is sent to it (carried over from v2, where it guarded the
	 * iframe src).
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
	const META_SESSION_ID       = '_xpay_session_id';
	const META_SESSION_URL      = '_xpay_session_url';
	const META_CLIENT_SECRET    = '_xpay_client_secret';
	const META_PAYMENT_INTENT   = '_xpay_payment_intent_id';
	const META_ATTEMPT          = '_xpay_session_attempt';
	const META_METHOD_PIN       = '_xpay_method_pin';
	const META_PROCESSED_EVENTS = '_xpay_processed_events';
	const META_CUSTOMER_ID      = '_xpay_customer_id';

	/**
	 * Unix time the stored session was last confirmed against the API
	 * (stamped at creation and on every successful reuse validation). The
	 * pay page trusts the stored client secret only within a short window
	 * of this stamp — the hot path right after process_payment — and
	 * re-validates through the checkout service for anything older, so a
	 * bookmarked or emailed pay page can never mount a dead session.
	 */
	const META_SESSION_CHECKED_AT = '_xpay_session_checked_at';

	/**
	 * XPay refund ids (re_…) recorded after each refund this plugin
	 * completed. The COUNT feeds the deterministic refund Idempotency-Key:
	 * a retry of a refund whose HTTP response was lost composes the same
	 * key (nothing was recorded), so the platform replays the original
	 * refund instead of paying out twice; a genuinely new refund follows a
	 * recorded success and composes a fresh key.
	 */
	const META_REFUND_IDS = '_xpay_refund_ids';

	/** Option flagging a method pin the API rejected (drives the admin notice). */
	const OPTION_PIN_REJECTED = 'xpay_wc_method_pin_rejected';

	/**
	 * The merchant's primary brand color ('#rrggbb'), snapshotted from the
	 * latest checkout session response (the API resolves the merchant's XPay
	 * dashboard branding into every session). Read by the pay page's stage;
	 * '' / absent means unbranded, and the page falls back to XPay indigo.
	 */
	const OPTION_BRAND_PRIMARY = 'xpay_wc_brand_primary';

	/**
	 * Last successful save-time key validation: array{mode: string,
	 * validated_at: int}. Written by process_admin_options, cleared when a
	 * key fails validation or is removed. The settings screen's "Connected"
	 * badge reads it — a badge must never claim more than a real API call
	 * proved.
	 */
	const OPTION_KEY_VALIDATED = 'xpay_wc_key_validated';

	/*
	 * Unix time of the last webhook that PASSED signature verification, one
	 * option per plane: a test event must never paint the LIVE health row
	 * green (test and live endpoints are separate XPay resources, and the
	 * settings screen reads the selected mode's stamp only). Keyed by the
	 * event's own livemode — which always matches the verifying secret.
	 * Stamped by the webhook controller regardless of the logging setting —
	 * the health row must stay truthful with diagnostics off. Never stamped
	 * for rejected requests: an attacker probing the endpoint must not be
	 * able to paint the health dot green.
	 */
	const OPTION_LAST_WEBHOOK_AT_TEST = 'xpay_wc_last_webhook_at_test';
	const OPTION_LAST_WEBHOOK_AT_LIVE = 'xpay_wc_last_webhook_at_live';

	/**
	 * The last-verified-webhook option name for one plane.
	 *
	 * @param bool $live_mode Which plane the event belongs to.
	 */
	public static function last_webhook_option( bool $live_mode ): string {
		return $live_mode ? self::OPTION_LAST_WEBHOOK_AT_LIVE : self::OPTION_LAST_WEBHOOK_AT_TEST;
	}

	/** The merchant-facing XPay dashboard (production host). */
	const DASHBOARD_URL = 'https://app.xpay.app';

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
	 * True for the combined gateway AND its per-method rows (xpay_card,
	 * xpay_valu, …). Every "was this order paid through us" check must use
	 * this, not an exact GATEWAY_ID comparison — a valU-row order carries
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
