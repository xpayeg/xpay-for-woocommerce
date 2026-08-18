<?php
/**
 * XPay_Api_Client
 *
 * The only HTTP path to the XPay v3 API. Responsibilities:
 *   1. Authenticate with the merchant's restricted/secret key (Bearer).
 *   2. Send the explicit liveMode query param derived from the key prefix —
 *      the API rejects a mismatch, which is exactly the guard we want.
 *   3. Attach Idempotency-Key on writes so transport retries can never
 *      double-create sessions or refunds.
 *   4. Convert every non-2xx into a typed XPay_Api_Exception.
 *
 * Base URL and credentials always come from server-side configuration,
 * never from request input (the v2 SSRF lesson, kept as a hard rule).
 * Payload field names are camelCase — the v3 API's deliberate deviation
 * from Stripe's snake_case.
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

	/**
	 * Cheapest authenticated call on the restricted-key surface — used only
	 * to validate a pasted key on the settings screen.
	 *
	 * @throws XPay_Api_Exception
	 */
	public function validate_key(): void {
		$this->request( 'GET', '/refunds', array( 'limit' => 1 ) );
	}

	/* ── Transport ───────────────────────────────────────────────────── */

	/**
	 * @param string      $method          GET|POST.
	 * @param string      $path            API path starting with '/'.
	 * @param array       $payload         Body for POST, query args for GET.
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
		if ( null !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => $timeout ?? self::TIMEOUT_SECONDS,
		);
		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $payload );
		}

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
			)
		);

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $json ) ? $json : array();
		}

		$error_body = ( is_array( $json ) && isset( $json['error'] ) && is_array( $json['error'] ) ) ? $json['error'] : array();
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- API error envelope values; every render site (admin notices, order notes) escapes on output, and messages never reach the shopper raw.
		throw XPay_Api_Exception::from_api_response( $error_body, $status );
	}
}
