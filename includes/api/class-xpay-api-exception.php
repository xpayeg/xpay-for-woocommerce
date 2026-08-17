<?php
/**
 * XPay_Api_Exception
 *
 * The one exception type for every client-facing failure in the plugin —
 * API errors, webhook verification failures, configuration problems. Carries
 * a stable machine-readable code (XPay_Error_Codes) separate from the
 * human message, plus the API's own doc_url when the failure came from XPay.
 *
 * Construction is via static factories only, mirroring the v3 ApiError
 * pattern: every throw site produces the same envelope, and the code
 * catalogue stays closed.
 *
 * High-cardinality values (order ids, session ids) belong in log context,
 * never interpolated into $message — messages are alert grouping keys.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Api_Exception extends Exception {

	/** @var string Stable code from XPay_Error_Codes (or the XPay API). */
	private $error_code;

	/** @var int HTTP status associated with the failure (0 = transport/none). */
	private $http_status;

	/** @var string Documentation deep link from the API response, '' if none. */
	private $doc_url;

	/** @var string Offending parameter name from the API response, '' if none. */
	private $param;

	private function __construct( string $message, string $error_code, int $http_status = 0, string $doc_url = '', string $param = '', ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->error_code  = $error_code;
		$this->http_status = $http_status;
		$this->doc_url     = $doc_url;
		$this->param       = $param;
	}

	public function get_error_code(): string {
		return $this->error_code;
	}

	public function get_http_status(): int {
		return $this->http_status;
	}

	public function get_doc_url(): string {
		return $this->doc_url;
	}

	public function get_param(): string {
		return $this->param;
	}

	/** The plugin itself is misconfigured (missing key/secret). Our fault, not the caller's. */
	public static function not_configured( string $what ): self {
		return new self( 'XPay gateway is not configured: ' . $what, XPay_Error_Codes::GATEWAY_NOT_CONFIGURED );
	}

	/** Transport-level failure (timeout, DNS, TLS). $previous carries the WP_Error text. */
	public static function transport( string $message, ?Throwable $previous = null ): self {
		return new self( $message, XPay_Error_Codes::TRANSPORT_ERROR, 0, '', '', $previous );
	}

	/**
	 * A structured error returned by the XPay API. Field names follow the
	 * documented envelope: { error: { type, code, message, param, doc_url } }.
	 *
	 * @param array $error_body Decoded `error` object.
	 * @param int   $http_status Response status.
	 */
	public static function from_api_response( array $error_body, int $http_status ): self {
		$code    = isset( $error_body['code'] ) && is_string( $error_body['code'] ) ? $error_body['code'] : XPay_Error_Codes::API_ERROR;
		$message = isset( $error_body['message'] ) && is_string( $error_body['message'] ) ? $error_body['message'] : 'XPay API request failed';
		$doc_url = isset( $error_body['doc_url'] ) && is_string( $error_body['doc_url'] ) ? $error_body['doc_url'] : '';
		$param   = isset( $error_body['param'] ) && is_string( $error_body['param'] ) ? $error_body['param'] : '';
		return new self( $message, $code, $http_status, $doc_url, $param );
	}

	/** Webhook signature/timestamp failures — thrown by XPay_Signature. */
	public static function webhook( string $error_code, string $message ): self {
		return new self( $message, $error_code, 401 );
	}

	/** A URL from an API response failed the xpay.app host allowlist. */
	public static function untrusted_url(): self {
		return new self( 'XPay returned a URL outside the allowed hosts', XPay_Error_Codes::SESSION_URL_UNTRUSTED );
	}

	/** Another refund for the same payment is in flight (advisory lock busy). */
	public static function refund_lock_busy(): self {
		return new self( 'Another refund for this payment is already being processed', XPay_Error_Codes::REFUND_LOCK_BUSY, 409 );
	}

	/** Another process is applying a payment transition to this order (advisory lock busy). */
	public static function order_lock_busy(): self {
		return new self( 'Another process is updating this order payment state', XPay_Error_Codes::ORDER_LOCK_BUSY, 409 );
	}

	/**
	 * The API accepted the refund request but the refund object came back in
	 * a non-accepted state (FAILED/CANCELED, or an unrecognized status). The
	 * actual status belongs in log context, not here — messages are alert
	 * grouping keys.
	 */
	public static function refund_rejected(): self {
		return new self( 'XPay did not return an accepted refund state', XPay_Error_Codes::REFUND_REJECTED );
	}
}
