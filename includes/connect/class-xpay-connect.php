<?php
/**
 * XPay_Connect
 *
 * Connect with XPay: the OAuth 2.1 handshake that signs a store into a
 * merchant's XPay account without anyone pasting keys. The OAuth 2.1 flow:
 *
 *   1. The merchant clicks Connect; the connect AJAX verb calls begin(),
 *      which registers this store as an OAuth client (once, lazily),
 *      mints a state token + PKCE verifier, and answers with the
 *      authorize URL. The browser navigates there — a full page exit,
 *      the one OAuth requires.
 *   2. The merchant signs in at XPay, picks a business, approves.
 *   3. XPay redirects back to the wc-api callback with ?code&state.
 *      handle_callback() verifies everything, exchanges the code for
 *      the key payload, and pushes both keys through the SAME
 *      validate-and-provision path a manual key save runs — proof,
 *      caches, webhook creation, notices, identically.
 *
 * OAuth is only the handshake; the deliverable is the two keys. The
 * access_token in the token response grants nothing and is discarded.
 *
 * Reconnect semantics: every completed connect mints a fresh restricted
 * key, and the platform retires THIS client's previous key for the same
 * merchant and mode at mint time. A key connected from another store or
 * an old address stays active until revoked on the XPay dashboard — the
 * server cannot tell a host move from a second store.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Connect {

	/** WC-API slug of the OAuth callback (the registered redirect URI). */
	const CALLBACK_ENDPOINT = 'xpay_connect';

	/**
	 * How long a started flow stays redeemable: 1 hour, in seconds.
	 *
	 * The authorization code itself expires in 10 minutes server-side;
	 * this bounds the stored state/verifier, which must survive the
	 * merchant dawdling on XPay's sign-in (OTP, business choice) without
	 * living forever. Generous is fine — the flow is single use.
	 */
	const FLOW_TTL_SECONDS = 3600;

	/**
	 * When a registration that never completed a connect is considered
	 * stale: 6 days, in seconds. The server deletes never-consented
	 * clients after 7 (OAUTH_CONNECT.md:67); a reaped client_id fails at
	 * authorize, on XPay's page, where we cannot catch it. Re-registering
	 * a day early costs one cheap call and keeps the failure impossible.
	 */
	const CLIENT_STALE_SECONDS = 518400;

	/**
	 * How long the callback's stored notices wait to be shown: 5 minutes,
	 * in seconds. The redirect lands on the settings screen immediately;
	 * the TTL only bounds the orphan left if the browser dies mid-redirect.
	 */
	const NOTICE_TTL_SECONDS = 300;

	public static function register(): void {
		// Trust boundary: a top-level GET from the internet carrying
		// code+state. The WP auth cookie (SameSite=Lax admits top-level
		// GET), the capability check and the single-use state token are
		// the gates.
		add_action( 'woocommerce_api_' . self::CALLBACK_ENDPOINT, array( __CLASS__, 'handle' ) );
	}

	/** The registered redirect URI — exact-match, so ONE fixed URL. */
	public static function callback_url(): string {
		return home_url( '/?wc-api=' . self::CALLBACK_ENDPOINT );
	}

	/** Where every callback outcome lands: the gateway's settings screen. */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . XPay_Constants::GATEWAY_ID );
	}

	/**
	 * Whether this store can register a callback at all: registration
	 * refuses non-https redirect URIs on non-loopback hosts (the server
	 * answers invalid_redirect_uri). Mirrored here so the Connect button
	 * can say so up front instead of relaying a protocol error.
	 */
	public static function https_ready(): bool {
		$home = home_url( '/' );
		if ( 'https' === wp_parse_url( $home, PHP_URL_SCHEME ) ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		// '[::1]' because wp_parse_url() keeps the brackets on IPv6 hosts.
		return in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
	}

	/* ── Starting a flow ─────────────────────────────────────────────── */

	/**
	 * Start one connect: ensure a registration, mint state + verifier,
	 * store the flow, answer the authorize URL for the browser to visit.
	 *
	 * @param bool $live Which mode the merchant is connecting.
	 * @return string The authorize URL.
	 * @throws XPay_Api_Exception When registration fails.
	 */
	public static function begin( bool $live ): string {
		$client = self::ensure_client();

		try {
			$state    = self::base64url( random_bytes( 32 ) );
			$verifier = self::base64url( random_bytes( 48 ) ); // 64 chars, inside RFC 7636's 43-128.
		} catch ( Exception $e ) {
			throw XPay_Api_Exception::connect_randomness_failed();
		}

		// One flow at a time: a new click replaces any unfinished one,
		// which is the single-use rule working forwards — the older
		// flow's state can no longer match anything.
		update_option(
			XPay_Constants::OPTION_CONNECT_FLOW,
			array(
				'state'      => $state,
				'verifier'   => $verifier,
				'live'       => $live,
				'user_id'    => get_current_user_id(),
				'created_at' => time(),
			),
			false
		);

		XPay_Logger::event(
			'connect.begin',
			array(
				'live_mode' => $live,
				'client_id' => $client['client_id'],
			)
		);

		// Values are pre-encoded because add_query_arg() does not encode.
		// state and the challenge are base64url and need none.
		return add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => rawurlencode( $client['client_id'] ),
				'redirect_uri'          => rawurlencode( $client['redirect_uri'] ),
				'scope'                 => rawurlencode( $live ? 'merchant.connect.live' : 'merchant.connect.test' ),
				'state'                 => $state,
				'code_challenge'        => self::challenge( $verifier ),
				'code_challenge_method' => 'S256',
			),
			XPay_Constants::oauth_base() . '/oauth2/authorize'
		);
	}

	/**
	 * The stored client registration, registering first when needed.
	 *
	 * @return array{client_id: string, redirect_uri: string, created_at: int, completed_at: int}
	 * @throws XPay_Api_Exception When registration fails.
	 */
	private static function ensure_client(): array {
		$stored = get_option( XPay_Constants::OPTION_CONNECT_CLIENT, null );
		$stored = is_array( $stored ) ? $stored : null;

		if ( ! self::client_needs_registration( $stored, self::callback_url(), time() ) ) {
			return $stored;
		}

		$body = array(
			// Shown on the consent screen AND used to name the minted key
			// in the merchant's dashboard ("Connected app: {name}") — so
			// it identifies THIS store, the doc's own example shape.
			'client_name'                => self::client_name(),
			'client_uri'                 => home_url( '/' ),
			'redirect_uris'              => array( self::callback_url() ),
			'token_endpoint_auth_method' => 'none', // Public client; PKCE is the proof.
		);
		$logo = get_site_icon_url();
		if ( '' !== $logo ) {
			$body['logo_uri'] = $logo;
		}

		$registered = XPay_Connect_Client::register_client( $body );

		$client = array(
			'client_id'    => (string) $registered['client_id'],
			'redirect_uri' => self::callback_url(),
			'created_at'   => time(),
			'completed_at' => 0,
		);
		update_option( XPay_Constants::OPTION_CONNECT_CLIENT, $client, false );

		XPay_Logger::event( 'connect.client_registered', array( 'client_id' => $client['client_id'] ) );

		return $client;
	}

	/**
	 * The registration's display name: site title plus host, the shape
	 * the contract's own example uses ("My Store (mystore.com)") — a
	 * bare title like "Shop" identifies nothing on a consent screen or
	 * an API-keys list. Entity-decoded the way Stripe sends its business
	 * name (class-wc-stripe-connect-api.php:31).
	 */
	private static function client_name(): string {
		$title = trim( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		$host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$name  = '' !== $title ? $title . ' (' . $host . ')' : $host;
		return mb_substr( $name, 0, 100 );
	}

	/* ── The callback ────────────────────────────────────────────────── */

	/**
	 * Hooked shim: compute the outcome, then leave. The redirect+exit
	 * lives here alone so handle_callback() stays a function tests can
	 * call and assert on.
	 */
	public static function handle(): void {
		wp_safe_redirect( esc_url_raw( self::handle_callback() ) );
		exit;
	}

	/**
	 * Process the return leg and answer where to send the browser.
	 *
	 * Every branch stores its notices for the settings screen and lands
	 * there — except a logged-out merchant, who is sent through wp-login
	 * with this callback (query intact) as the return destination, so
	 * the flow resumes after sign-in. wp_safe_redirect() in the shim
	 * keeps every answer on this site or its login.
	 *
	 * @return string Redirect destination.
	 */
	public static function handle_callback(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- an OAuth redirect cannot carry a WP nonce; the single-use state token bound to the initiating user is this endpoint's anti-forgery proof, verified below before anything acts.
		if ( ! is_user_logged_in() ) {
			return wp_login_url( self::current_callback_url() );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			self::store_notices(
				array(
					array(
						'type' => 'error',
						'text' => __( 'XPay: your WordPress account cannot manage store settings, so the connection was not applied. Sign in as a store manager and connect again.', 'xpay-for-woocommerce' ),
					),
				)
			);
			return self::settings_url();
		}

		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( '' !== $error ) {
			// The flow is spent either way: a retry starts from the button.
			delete_option( XPay_Constants::OPTION_CONNECT_FLOW );
			$description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
			XPay_Logger::event(
				'connect.refused',
				array(
					'code'        => $error,
					'description' => $description,
				)
			);
			self::store_notices( array( self::refusal_notice( $error, $description ) ) );
			return self::settings_url();
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$iss   = isset( $_GET['iss'] ) ? sanitize_text_field( wp_unslash( $_GET['iss'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$flow = get_option( XPay_Constants::OPTION_CONNECT_FLOW, null );
		$flow = is_array( $flow ) ? $flow : null;

		/*
		 * Claim the flow before the exchange. Single use is what makes the
		 * state token an anti-forgery proof, and delete_option() answers
		 * false when the row was already gone, so the delete IS the claim:
		 * exactly one request can win it, even when two arrive together.
		 *
		 * A callback that does not win is a DUPLICATE DELIVERY, not a
		 * forgery, and it leaves quietly. Observed on the dev store: the
		 * same redirect arrived twice within one second, the first
		 * connected the account completely, and the second reported a
		 * stale link over it — so a connection that had just written keys,
		 * proof and a webhook told the merchant nothing had happened.
		 * Whatever sends the second request (a prefetch, a retry, a
		 * refresh) is not ours to control; not lying about it is.
		 */
		if ( ! delete_option( XPay_Constants::OPTION_CONNECT_FLOW ) ) {
			XPay_Logger::event( 'connect.duplicate_callback', array() );
			return self::settings_url();
		}

		$flow_error = self::flow_error( $flow, $state, get_current_user_id(), time() );
		if ( null !== $flow_error ) {
			XPay_Logger::error( 'connect.flow_rejected', array( 'reason' => $flow_error ) );
			self::store_notices(
				array(
					array(
						'type' => 'error',
						'text' => __( 'XPay: this connection link is stale or was already used. Nothing changed. Start again from the Connect button.', 'xpay-for-woocommerce' ),
					),
				)
			);
			return self::settings_url();
		}

		/*
		 * RFC 9207 issuer check: absent passes (fail open on shape), a
		 * value that is not OUR configured issuer refuses (fail closed on
		 * value). There is exactly one authorization server, configured
		 * server-side, so this is one string compare — cheap insurance
		 * against a staging/production config mix.
		 */
		if ( '' !== $iss && ! hash_equals( XPay_Constants::oauth_base(), $iss ) ) {
			XPay_Logger::error( 'connect.issuer_mismatch', array( 'iss' => $iss ) );
			self::store_notices( array( self::generic_failure_notice() ) );
			return self::settings_url();
		}

		$client = get_option( XPay_Constants::OPTION_CONNECT_CLIENT, null );
		if ( ! is_array( $client ) || empty( $client['client_id'] ) || empty( $client['redirect_uri'] ) ) {
			// A flow cannot outlive its registration in any ordinary
			// story; reaching here means the options were tampered with
			// or half-deleted mid-flight.
			XPay_Logger::error( 'connect.client_missing', array() );
			self::store_notices( array( self::generic_failure_notice() ) );
			return self::settings_url();
		}

		try {
			$tokens = XPay_Connect_Client::exchange(
				(string) $client['client_id'],
				(string) $client['redirect_uri'],
				$code,
				(string) $flow['verifier']
			);
		} catch ( XPay_Api_Exception $e ) {
			// The platform's mint-and-retire is atomic: an exchange error
			// means no key was created, so retrying is always safe.
			XPay_Logger::error( 'connect.exchange_failed', array( 'code' => $e->get_error_code() ) );
			self::store_notices( array( self::generic_failure_notice() ) );
			return self::settings_url();
		}

		$live = ! empty( $flow['live'] );
		$keys = self::keys_from_token_response( $tokens, $live );
		if ( null === $keys ) {
			XPay_Logger::error( 'connect.response_invalid', array( 'live_mode' => $live ) );
			self::store_notices( array( self::generic_failure_notice() ) );
			return self::settings_url();
		}

		self::apply_keys( $keys, $live );

		// This client has now proved itself real: the staleness rule
		// (never-completed registrations re-register after 6 days) stops
		// applying to it.
		$client['completed_at'] = time();
		update_option( XPay_Constants::OPTION_CONNECT_CLIENT, $client, false );

		XPay_Logger::event( 'connect.completed', array( 'live_mode' => $live ) );

		return self::settings_url();
	}

	/**
	 * Write the delivered keys and run everything a manual key save runs.
	 *
	 * Written as a MERGE into the settings option (the unrendered-field
	 * rule: writing keys programmatically must never clobber the rest),
	 * then the decommission pass retires the plane's previous webhook
	 * endpoint with its old creating key, then validate_and_provision()
	 * produces the proof, the caches, the new webhook endpoint and the
	 * notices — the one shared path, so a connected store and a
	 * pasted-keys store are indistinguishable afterwards.
	 *
	 * The old endpoint's delete can 401 when the platform's same-client
	 * auto-revoke already retired its creating key; harmless — the new
	 * key's configure pass dedupes every other endpoint aimed at this
	 * store's URL.
	 *
	 * @param array{restricted: string, publishable: string} $keys The delivered pair.
	 * @param bool                                           $live Which mode.
	 */
	private static function apply_keys( array $keys, bool $live ): void {
		$mode = $live ? 'live' : 'test';

		XPay_Webhook_Configurator::merge_settings(
			array(
				$mode . '_api_key'         => $keys['restricted'],
				$mode . '_publishable_key' => $keys['publishable'],
				// The merchant clicked THIS pane's Connect button: the
				// intent to run in this mode is explicit. Enabled follows
				// Stripe's connect (a connected gateway that stays hidden
				// at checkout reads as broken, WC_Stripe_Connect
				// save_stripe_keys:282).
				'mode'                     => $mode,
				'enabled'                  => 'yes',
			)
		);

		$option_key = 'woocommerce_' . XPay_Constants::GATEWAY_ID . '_settings';
		$settings   = get_option( $option_key, array() );
		update_option( $option_key, XPay_Webhook_Configurator::decommission_after_key_update( is_array( $settings ) ? $settings : array() ) );

		$result  = XPay_Plugin::instance()->gateway()->validate_and_provision();
		$notices = $result['notices'];
		if ( array() === $notices ) {
			// The silent proved-keys path cannot ordinarily happen here (a
			// connect always delivers a fresh restricted key), but a
			// merchant must never return from XPay to a wordless screen.
			$notices[] = array(
				'type' => 'message',
				'text' => __( 'XPay connected.', 'xpay-for-woocommerce' ),
			);
		}
		self::store_notices( $notices );
	}

	/* ── Pure decision tables (unit tested) ──────────────────────────── */

	/**
	 * Whether a Connect click must (re)register before authorizing.
	 *
	 * @param array|null $stored       The stored client record, if any.
	 * @param string     $callback_url This store's callback URL today.
	 * @param int        $now          UTC seconds.
	 */
	public static function client_needs_registration( ?array $stored, string $callback_url, int $now ): bool {
		if ( null === $stored || ! isset( $stored['client_id'] ) || ! is_string( $stored['client_id'] ) || '' === $stored['client_id'] ) {
			return true;
		}
		// Redirect matching is exact: a moved host makes the stored
		// registration name a callback XPay would refuse.
		if ( ! isset( $stored['redirect_uri'] ) || $stored['redirect_uri'] !== $callback_url ) {
			return true;
		}
		$completed = isset( $stored['completed_at'] ) ? (int) $stored['completed_at'] : 0;
		$created   = isset( $stored['created_at'] ) ? (int) $stored['created_at'] : 0;
		return 0 === $completed && ( $now - $created ) > self::CLIENT_STALE_SECONDS;
	}

	/**
	 * Why a returning flow must be refused, or null when it holds.
	 *
	 * @param array|null $flow    The stored flow record, if any.
	 * @param string     $state   The state the redirect carried.
	 * @param int        $user_id The user at the callback.
	 * @param int        $now     UTC seconds.
	 * @return string|null Refusal reason for the log, null = valid.
	 */
	public static function flow_error( ?array $flow, string $state, int $user_id, int $now ): ?string {
		if ( null === $flow || ! isset( $flow['state'], $flow['verifier'], $flow['user_id'], $flow['created_at'] )
			|| ! is_string( $flow['state'] ) || ! is_string( $flow['verifier'] ) ) {
			return 'missing';
		}
		if ( '' === $state || ! hash_equals( $flow['state'], $state ) ) {
			return 'state_mismatch';
		}
		if ( (int) $flow['user_id'] !== $user_id ) {
			// Bound to the user who clicked Connect: another signed-in
			// manager landing here is a different conversation, not a
			// continuation of this one.
			return 'user_mismatch';
		}
		if ( ( $now - (int) $flow['created_at'] ) > self::FLOW_TTL_SECONDS ) {
			return 'expired';
		}
		return null;
	}

	/**
	 * The key pair out of a token response, or null when the response
	 * cannot be trusted. Fail closed on value: the mode must be the one
	 * this flow asked for, and each key must carry that mode's own
	 * prefix — a key written into the wrong plane's fields would charge
	 * the wrong plane.
	 *
	 * @param mixed $body Decoded token response.
	 * @param bool  $live The mode this flow requested.
	 * @return array{restricted: string, publishable: string}|null
	 */
	public static function keys_from_token_response( $body, bool $live ): ?array {
		if ( ! is_array( $body ) ) {
			return null;
		}
		$mode = $live ? 'live' : 'test';
		if ( ! isset( $body['xpay_mode'] ) || $body['xpay_mode'] !== $mode ) {
			return null;
		}
		$restricted  = isset( $body['xpay_restricted_key'] ) ? $body['xpay_restricted_key'] : null;
		$publishable = isset( $body['xpay_publishable_key'] ) ? $body['xpay_publishable_key'] : null;
		if ( ! is_string( $restricted ) || 0 !== strpos( $restricted, 'rk_' . $mode . '_' ) ) {
			return null;
		}
		if ( ! is_string( $publishable ) || 0 !== strpos( $publishable, 'pk_' . $mode . '_' ) ) {
			return null;
		}
		return array(
			'restricted'  => $restricted,
			'publishable' => $publishable,
		);
	}

	/**
	 * RFC 7636 S256 code challenge for a verifier. Public for the unit
	 * test that pins it to the RFC's own appendix B vector.
	 *
	 * @param string $verifier The PKCE verifier.
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/** @param string $bytes Raw bytes. */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 4648 base64url for OAuth tokens, not obfuscation.
	}

	/* ── Notices across the redirect ─────────────────────────────────── */

	/**
	 * Store notices for the settings screen to show after the redirect,
	 * bound to the returning user so another manager's screen stays
	 * quiet about a connection they did not make.
	 *
	 * @param array $notices Same shape validate_and_provision() returns.
	 */
	private static function store_notices( array $notices ): void {
		set_transient( 'xpay_wc_connect_notice_' . get_current_user_id(), $notices, self::NOTICE_TTL_SECONDS );
	}

	/**
	 * Take (and clear) the stored notices for the current user.
	 *
	 * @return array<int, array{type: string, text: string}>
	 */
	public static function take_notices(): array {
		$key     = 'xpay_wc_connect_notice_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || array() === $notices ) {
			return array();
		}
		delete_transient( $key );
		return $notices;
	}

	/**
	 * The notice for a refusal that came back on the redirect.
	 *
	 * access_denied's description is written by XPay for the merchant
	 * (why consent did not happen: canceled, wrong role, live not
	 * approved) and is more precise than anything composed here — shown
	 * when present, with our own wording as the fallback. The other wire
	 * errors describe protocol states no merchant caused; they get the
	 * generic notice and their detail stays in the log.
	 *
	 * @param string $error       OAuth wire error code.
	 * @param string $description Sanitized error_description ('' when absent).
	 * @return array{type: string, text: string}
	 */
	private static function refusal_notice( string $error, string $description ): array {
		if ( XPay_Error_Codes::OAUTH_ACCESS_DENIED === $error ) {
			return array(
				'type' => 'error',
				'text' => '' !== $description
					? sprintf(
						/* translators: %s is XPay's own explanation of why the connection was not approved. */
						__( 'XPay: the connection was not completed. %s', 'xpay-for-woocommerce' ),
						$description
					)
					: __( 'XPay: the connection was canceled before it finished. Nothing changed. To try again, click Connect.', 'xpay-for-woocommerce' ),
			);
		}
		return self::generic_failure_notice();
	}

	/** @return array{type: string, text: string} */
	private static function generic_failure_notice(): array {
		return array(
			'type' => 'error',
			'text' => __( 'XPay: the connection could not be completed. Nothing changed. Try again in a moment. If it keeps failing, the WooCommerce logs (source "xpay") carry the details.', 'xpay-for-woocommerce' ),
		);
	}

	/**
	 * This callback's own URL with the query it arrived with, rebuilt
	 * from allowlisted parameters only — never from REQUEST_URI, so a
	 * crafted extra parameter cannot ride into the login redirect.
	 */
	private static function current_callback_url(): string {
		$url = self::callback_url();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- values are relayed opaquely into the post-login return URL; the state check authenticates them when the flow resumes.
		foreach ( array( 'code', 'state', 'iss', 'error', 'error_description' ) as $param ) {
			if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
				$url = add_query_arg( $param, rawurlencode( sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) ), $url ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			}
		}
		return $url;
	}
}
