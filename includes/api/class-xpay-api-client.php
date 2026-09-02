<?php
/**
 * XPay_Api_Client
 *
 * The only HTTP path to the XPay API. Responsibilities:
 *   1. Authenticate with the merchant's restricted/secret key (Bearer).
 *   2. Send the explicit liveMode query param derived from the key prefix —
 *      the API rejects a mismatch, which is exactly the guard we want.
 *   3. Attach Idempotency-Key on writes so transport retries can never
 *      double-create sessions or refunds.
 *   4. Convert every non-2xx into a typed XPay_Api_Exception.
 *
 * Base URL and credentials always come from server-side configuration,
 * never from request input. Payload field names follow the XPay API's
 * camelCase contract.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Api_Client {

	/** Request timeout. XPay session/refund calls are fast; 30s covers 3DS-adjacent slow paths. */
	const TIMEOUT_SECONDS = 30;

	/**
	 * Timeout for reads that block a shopper-facing render (thank-you
	 * re-check). Failing open to the pending UI after 5s beats holding a
	 * PHP worker for the full write-path budget during an API brown-out.
	 */
	const SHOPPER_READ_TIMEOUT_SECONDS = 5;

	/** @var string */
	private $api_key;

	/** @var bool Derived once from the key prefix, sent explicitly on every call. */
	private $live_mode;

	public function __construct( string $api_key ) {
		if ( '' === $api_key ) {
			throw XPay_Api_Exception::not_configured( 'API key' );
		}
		$this->api_key   = $api_key;
		$this->live_mode = self::is_live_key( $api_key );
	}

	/**
	 * Live/test is authoritative from the key itself (sk_live_/rk_live_),
	 * mirroring how the API's own guard treats it — never from a settings
	 * toggle that could drift out of sync with the pasted key.
	 */
	public static function is_live_key( string $key ): bool {
		return 0 === strpos( $key, 'sk_live_' ) || 0 === strpos( $key, 'rk_live_' ) || 0 === strpos( $key, 'pk_live_' );
	}

	public function is_live_mode(): bool {
		return $this->live_mode;
	}

	/* ── Checkout sessions ───────────────────────────────────────────── */

	/**
	 * @param array  $body            CreateCheckoutSession payload (camelCase).
	 * @param string $idempotency_key Order-derived key.
	 * @return array Decoded session object.
	 * @throws XPay_Api_Exception
	 */
	public function create_checkout_session( array $body, string $idempotency_key ): array {
		return $this->request( 'POST', '/checkout/sessions', $body, $idempotency_key );
	}

	/**
	 * @param string   $session_id cs_… id.
	 * @param int|null $timeout    Override for shopper-facing reads; null = default.
	 * @return array Decoded session object.
	 * @throws XPay_Api_Exception
	 */
	public function get_checkout_session( string $session_id, ?int $timeout = null ): array {
		return $this->request( 'GET', '/checkout/sessions/' . rawurlencode( $session_id ), array(), null, $timeout );
	}

	/**
	 * Update an open session in place.
	 *
	 * The checkout page needs this and the pay page never does. On the pay
	 * page the total is already final; on the checkout page the shopper is
	 * still choosing shipping and typing coupons while the payment fields
	 * are mounted, and those fields are bound to a client secret. Creating a
	 * replacement session would mint a new secret and destroy the form the
	 * shopper is halfway through, so the amount moves and the session stays.
	 *
	 * The platform accepts this only while the session is open, and rejects
	 * mode, uiMode, submitType, currency and expiresAfterMinutes outright.
	 * It does NOT refuse an update while a payment is running on the
	 * session: an in-flight session is still "open". That guard is ours —
	 * the element refuses to move the displayed amount once a payment is in
	 * flight, and charge = display refuses any confirm whose session total
	 * differs from what was shown.
	 *
	 * @param string $session_id      cs_… id to update.
	 * @param array  $body            Partial payload (camelCase).
	 * @param string $idempotency_key Caller-derived key.
	 * @return array Decoded session object.
	 * @throws XPay_Api_Exception
	 */
	public function update_checkout_session( string $session_id, array $body, string $idempotency_key ): array {
		return $this->request( 'PATCH', '/checkout/sessions/' . rawurlencode( $session_id ), $body, $idempotency_key );
	}

	/**
	 * Expire a session this plugin has superseded. Idempotent server-side;
	 * the key is derived from the session id, so retries collapse.
	 *
	 * @param string $session_id cs_… id to expire.
	 * @throws XPay_Api_Exception
	 */
	public function expire_checkout_session( string $session_id ): void {
		$this->request( 'POST', '/checkout/sessions/' . rawurlencode( $session_id ) . '/expire', array(), 'expire_' . $session_id );
	}

	/* ── Refunds ─────────────────────────────────────────────────────── */

	/**
	 * @param array  $body            { paymentIntentId, amount?, reason? }.
	 * @param string $idempotency_key Refund-scoped key.
	 * @return array Decoded refund object.
	 * @throws XPay_Api_Exception
	 */
	public function create_refund( array $body, string $idempotency_key ): array {
		return $this->request( 'POST', '/refunds', $body, $idempotency_key );
	}

	/* ── Webhook endpoints ───────────────────────────────────────────── */

	/**
	 * Create a webhook endpoint for this key's plane. The response carries
	 * the signing secret EXACTLY ONCE — the caller must store it.
	 *
	 * @param array  $body            { url, enabledEvents, description? }.
	 * @param string $idempotency_key Caller-derived key.
	 * @return array Decoded endpoint object, secret included.
	 * @throws XPay_Api_Exception
	 */
	public function create_webhook_endpoint( array $body, string $idempotency_key ): array {
		return $this->request( 'POST', '/webhook-endpoints', $body, $idempotency_key );
	}

	/**
	 * @return array { object: "list", data: endpoint[] } for this plane.
	 * @throws XPay_Api_Exception
	 */
	public function list_webhook_endpoints(): array {
		return $this->request( 'GET', '/webhook-endpoints' );
	}

	/**
	 * @param string $endpoint_id we_… id to delete.
	 * @throws XPay_Api_Exception
	 */
	public function delete_webhook_endpoint( string $endpoint_id ): void {
		$this->request( 'DELETE', '/webhook-endpoints/' . rawurlencode( $endpoint_id ) );
	}

	/* ── Account ─────────────────────────────────────────────────────── */

	/**
	 * The account this key belongs to, from GET /account.
	 *
	 * The self-describing endpoint (Stripe's GET /v1/account analog): works
	 * with any secret or restricted key, requires no specific permission,
	 * and is exempt from the live-approval gate — an unactivated live key
	 * answers 200 with livePaymentsEnabled false instead of
	 * merchant_not_activated. Publishable keys are refused by the endpoint's
	 * own guard (403 permission_denied), and a key XPay will not accept at
	 * all answers 401.
	 *
	 * This replaced the empty-POST /checkout/sessions probe, which could
	 * only infer three coarse verdicts from status codes. The account
	 * response states everything the probe guessed at: the key's effective
	 * permission set (so a mis-scoped restricted key is named field by
	 * field), the currencies this account can actually charge in, the
	 * merchant id, and live activation as a fact rather than a 403.
	 *
	 * @param int|null $timeout_seconds Per-call override.
	 * @return array Decoded account object.
	 * @throws XPay_Api_Exception On any non-2xx, carrying the status.
	 */
	public function get_account( ?int $timeout_seconds = null ): array {
		return $this->request( 'GET', '/account', array(), null, $timeout_seconds );
	}

	/* ── Transport ───────────────────────────────────────────────────── */

	/**
	 * A key safe to log: prefix and last four, nothing in between —
	 * "rk_test_...4aE6" — the same masking Stripe's request log uses.
	 *
	 * @param string $key The API key.
	 */
	private static function masked_key( string $key ): string {
		if ( strlen( $key ) <= 12 ) {
			return substr( $key, 0, 3 ) . '...';
		}
		return substr( $key, 0, 8 ) . '...' . substr( $key, -4 );
	}

	/**
	 * Tie an idempotency key to the exact body it was minted for.
	 *
	 * The platform binds a key to the first request it saw with it —
	 * method, path, query, and body. Reusing a key for a different request
	 * returns `idempotency_key_in_use`. Every key this plugin builds names a
	 * logical operation instead: "adopt this session for this order",
	 * "refund this much of this order". Those are stable across a transport
	 * retry, which is the point, but they are also stable across a shopper
	 * who edits their email and presses Place Order again — and that second
	 * attempt is a DIFFERENT body under the same name, so the platform
	 * refuses it and the payment never starts.
	 *
	 * Suffixing the body's hash keeps both halves: an identical retry keeps
	 * the key and replays, a changed request gets a new key and is applied.
	 * Stripe reaches the same place from the other side — it sends no key at
	 * all on updates and a fresh uuid4 on intent creation
	 * (class-wc-stripe-api.php:194-205), and when a key does collide it
	 * rewrites it with a retry counter rather than failing the payment
	 * (abstract-wc-stripe-payment-gateway.php:1575).
	 *
	 * @param string      $key  Logical operation key.
	 * @param string|null $body Encoded request body, null for bodyless methods.
	 * @return string Key bound to this body, within the platform's 255 limit.
	 */
	private static function bind_key_to_body( string $key, ?string $body ): string {
		return substr( $key, 0, 200 ) . '_' . substr( md5( (string) $body ), 0, 12 );
	}

	/**
	 * @param string      $method          GET, POST or PATCH.
	 * @param string      $path            API path starting with '/'.
	 * @param array       $payload         Body for POST/PATCH, query args for GET.
	 * @param string|null $idempotency_key Sent only on writes.
	 * @param int|null    $timeout         Per-call override; null = TIMEOUT_SECONDS.
	 * @return array Decoded JSON response.
	 * @throws XPay_Api_Exception
	 */
	private function request( string $method, string $path, array $payload = array(), ?string $idempotency_key = null, ?int $timeout = null ): array {
		$url = XPay_Constants::api_base() . $path;

		$query = array( 'liveMode' => $this->live_mode ? 'true' : 'false' );
		if ( 'GET' === $method && array() !== $payload ) {
			$query = array_merge( $query, $payload );
		}
		$url = add_query_arg( $query, $url );

		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'XPay-WooCommerce/' . XPAY_WC_VERSION . '; ' . home_url(),
		);

		$request_body = null;
		// Every method that carries one, not POST alone: PATCH updates a
		// session in place and its whole meaning is in the body, so testing
		// for POST here would have sent an empty update that the platform
		// accepts and silently applies to nothing.
		if ( 'GET' !== $method && 'DELETE' !== $method ) {
			$request_body = wp_json_encode( $payload );
		}

		if ( null !== $idempotency_key ) {
			$headers['Idempotency-Key'] = self::bind_key_to_body( $idempotency_key, $request_body );
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout ?? self::TIMEOUT_SECONDS,
		);
		if ( null !== $request_body ) {
			$args['body'] = $request_body;
		}

		// The request, body included, before it leaves — Stripe's plugin
		// logs the same pair. Redaction happens in the logger; the key is
		// masked here because it must never reach the logger at all.
		XPay_Logger::event(
			'api.request',
			array(
				'path'      => $path,
				'method'    => $method,
				'api_key'   => self::masked_key( $this->api_key ),
				'body'      => null !== $request_body ? $payload : null,
				'live_mode' => $this->live_mode,
			),
			$method . ' ' . $path
		);

		$started  = microtime( true );
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			XPay_Logger::event(
				'api.transport_error',
				array(
					'path'        => $path,
					'method'      => $method,
					'duration_ms' => (int) ( ( microtime( true ) - $started ) * 1000 ),
					'wp_error'    => $response->get_error_message(),
				)
			);
			throw XPay_Api_Exception::transport( 'Could not reach the XPay API' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $body, true );

		XPay_Logger::event(
			'api.response',
			array(
				'path'        => $path,
				'method'      => $method,
				'status'      => $status,
				'duration_ms' => (int) ( ( microtime( true ) - $started ) * 1000 ),
				'live_mode'   => $this->live_mode,
				// The decoded body, whole: what support reads to answer
				// "what did XPay actually say". The logger's redactor
				// scrubs secrets and card-shaped values on the way through.
				'response'    => is_array( $json ) ? $json : $body,
			),
			$method . ' ' . $path . ' (' . $status . ')'
		);

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $json ) ? $json : array();
		}

		$error_body = ( is_array( $json ) && isset( $json['error'] ) && is_array( $json['error'] ) ) ? $json['error'] : array();
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- API error envelope values; every render site (admin notices, order notes) escapes on output, and messages never reach the shopper raw.
		throw XPay_Api_Exception::from_api_response( $error_body, $status );
	}
}
