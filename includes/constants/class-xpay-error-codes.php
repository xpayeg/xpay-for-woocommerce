<?php
/**
 * XPay_Error_Codes
 *
 * Every stable error code the plugin throws itself, plus the XPay API codes
 * it special-cases. Single source of truth — never string-compare a raw code
 * literal at a call site, and never invent a new code inline.
 *
 * Codes the PLUGIN mints are prefixed with their subsystem; codes without a
 * comment come verbatim from the XPay API error catalogue and must match it
 * exactly (the API's doc_url deep-links are keyed on them).
 *
 * @see https://docs.xpay.app/en/integrate/errors/api-error-codes
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Error_Codes {

	/* ── Plugin-minted: webhook receiver ─────────────────────────────── */
	const WEBHOOK_SIGNATURE_MISSING   = 'webhook_signature_missing';
	const WEBHOOK_SIGNATURE_INVALID   = 'webhook_signature_invalid';
	const WEBHOOK_TIMESTAMP_TOLERANCE = 'webhook_timestamp_out_of_tolerance';
	const WEBHOOK_PAYLOAD_MALFORMED   = 'webhook_payload_malformed';
	const WEBHOOK_NOT_CONFIGURED      = 'webhook_not_configured';
	const WEBHOOK_APPLY_FAILED        = 'webhook_apply_failed';
	const WEBHOOK_ORDER_NOT_FOUND     = 'webhook_order_not_found';

	/* ── Plugin-minted: gateway/config ───────────────────────────────── */
	const GATEWAY_NOT_CONFIGURED      = 'gateway_not_configured';
	const SESSION_URL_UNTRUSTED       = 'session_url_untrusted';
	const AMOUNT_ABOVE_LINE_CEILING   = 'amount_above_line_ceiling';
	const REFUND_REJECTED             = 'refund_rejected';
	const REFUND_PENDING              = 'refund_pending';
	const REFUND_CURRENCY_UNSUPPORTED = 'refund_currency_unsupported';
	const REFUND_RESULT_MISMATCH      = 'refund_result_mismatch';
	const ORDER_LOCK_BUSY             = 'order_lock_busy';
	const ORDER_MISMATCH              = 'order_session_mismatch';
	const PAYMENT_METHODS_UNAVAILABLE = 'payment_methods_unavailable';

	/* ── Plugin-minted: Connect with XPay (OAuth) ────────────────────── */
	const CONNECT_REGISTRATION_FAILED = 'connect_registration_failed';
	const CONNECT_EXCHANGE_FAILED     = 'connect_exchange_failed';
	const CONNECT_RANDOMNESS_FAILED   = 'connect_randomness_failed';

	/*
	 * OAuth wire code (RFC 6749 section 4.1.2.1) the callback branches on:
	 * the merchant canceled, lacks the role, or the business is not
	 * approved for live. Everything else on that wire (invalid_scope,
	 * server_error) gets one generic try-again notice and needs no
	 * constant.
	 */
	const OAUTH_ACCESS_DENIED = 'access_denied';

	/* ── Plugin-minted: API client fallbacks ─────────────────────────── */
	const TRANSPORT_ERROR = 'transport_error'; // No HTTP response at all (timeout, DNS, TLS).
	const API_ERROR       = 'api_error';       // API error response carried no usable code.

	/* ── XPay API codes the plugin branches on ───────────────────────── */
	const API_RESOURCE_MISSING        = 'resource_missing';
	const API_PARAMETER_INVALID       = 'parameter_invalid';
	const API_RESOURCE_INVALID_STATE  = 'resource_invalid_state';
	const API_RATE_LIMIT              = 'rate_limit';
	const API_PERMISSION_DENIED       = 'permission_denied';
	const API_MERCHANT_NOT_ACTIVATED  = 'merchant_not_activated';
	const API_KEY_INVALID             = 'invalid_api_key';
	const API_KEY_INACTIVE            = 'api_key_inactive';
	const API_EXCHANGE_RATE_NOT_FOUND = 'exchange_rate_not_found';
}
