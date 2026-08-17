<?php
/**
 * XPay_Signature
 *
 * Verifies the `XPay-Signature: t=<unix>,v1=<hex>` header on inbound
 * webhooks. The scheme is Stripe-compatible: v1 = HMAC-SHA256(secret,
 * "<timestamp>.<raw body>") as lowercase hex, verified constant-time,
 * with a replay window the RECEIVER must enforce (XPay signs but does not
 * enforce tolerance server-side — its docs put the 300s check on us).
 *
 * Pure class: no WordPress dependencies, fully covered by unit tests in
 * tests/SignatureTest.php. Fail-closed by design — every branch that is
 * not a proven-valid signature throws.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Signature {

	/** Replay window in seconds, per XPay's webhook verification docs. */
	const DEFAULT_TOLERANCE = 300;

	/**
	 * Verify a webhook signature header against the raw request body.
	 *
	 * @param string   $header    Raw XPay-Signature header value.
	 * @param string   $raw_body  Exact request body bytes (never re-encoded
	 *                            JSON — re-encoding changes the bytes and
	 *                            breaks the HMAC).
	 * @param string   $secret    The endpoint's whsec_… signing secret.
	 * @param int      $tolerance Max allowed |now - t| in seconds.
	 * @param int|null $now       Current unix time (injectable for tests).
	 *
	 * @throws XPay_Api_Exception On any verification failure.
	 */
	public static function verify( string $header, string $raw_body, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null ): void {
		if ( '' === $secret ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constant registry-code message with no request data; render sites escape on output.
			throw XPay_Api_Exception::webhook( XPay_Error_Codes::WEBHOOK_NOT_CONFIGURED, 'No webhook signing secret is configured' );
		}
		if ( '' === trim( $header ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constant registry-code message with no request data; render sites escape on output.
			throw XPay_Api_Exception::webhook( XPay_Error_Codes::WEBHOOK_SIGNATURE_MISSING, 'XPay-Signature header is missing' );
		}

		$timestamp  = null;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] && ctype_digit( $pair[1] ) ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] && '' !== $pair[1] ) {
				$signatures[] = strtolower( $pair[1] );
			}
		}

		if ( null === $timestamp || array() === $signatures ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constant registry-code message with no request data; render sites escape on output.
			throw XPay_Api_Exception::webhook( XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID, 'XPay-Signature header is malformed' );
		}

		$now = null !== $now ? $now : time();
		if ( abs( $now - $timestamp ) > $tolerance ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constant registry-code message with no request data; render sites escape on output.
			throw XPay_Api_Exception::webhook( XPay_Error_Codes::WEBHOOK_TIMESTAMP_TOLERANCE, 'Webhook timestamp is outside the allowed tolerance' );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
		foreach ( $signatures as $candidate ) {
			// hash_equals, never ===: constant-time compare closes the
			// byte-by-byte timing side channel on signature guessing.
			if ( hash_equals( $expected, $candidate ) ) {
				return;
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constant registry-code message with no request data (AGENTS.md rule: ids live in log context, never messages); render sites escape on output.
		throw XPay_Api_Exception::webhook( XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID, 'Webhook signature does not match' );
	}
}
