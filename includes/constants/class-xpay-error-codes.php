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

	/* ── Plugin-minted: gateway/config ───────────────────────────────── */
	const GATEWAY_NOT_CONFIGURED = 'gateway_not_configured';
	const SESSION_URL_UNTRUSTED  = 'session_url_untrusted';
	const REFUND_LOCK_BUSY       = 'refund_lock_busy';
	const REFUND_REJECTED        = 'refund_rejected';
	const REFUND_PENDING         = 'refund_pending';
	const ORDER_LOCK_BUSY        = 'order_lock_busy';
	const ORDER_MISMATCH         = 'order_session_mismatch';

	/* ── Plugin-minted: API client fallbacks ─────────────────────────── */
	const TRANSPORT_ERROR = 'transport_error'; // No HTTP response at all (timeout, DNS, TLS).
	const API_ERROR       = 'api_error';       // API error response carried no usable code.

	/* ── XPay API codes the plugin branches on ───────────────────────── */
	const API_RESOURCE_MISSING       = 'resource_missing';
	const API_RESOURCE_INVALID_STATE = 'resource_invalid_state';
	const API_RATE_LIMIT             = 'rate_limit';
	const API_KEY_INVALID            = 'api_key_invalid';
	const API_KEY_INACTIVE           = 'api_key_inactive';
}
