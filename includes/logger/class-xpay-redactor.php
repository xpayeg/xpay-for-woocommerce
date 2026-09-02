<?php
/**
 * Redacts secrets and PII from log entries before they are written.
 *
 * Redaction happens at write time, not display time — anything that lands
 * on disk is already safe to share in a support ticket. The two-tier policy
 * fully masks secrets and last-4 masks PII, plus a
 * value-shape PAN scrub so a card number under an unexpected key name is
 * still caught. Conservative on purpose: false positives are acceptable,
 * false negatives are not.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Redactor {

	/** Recursion cap so a redaction bug can't blow up on pathological payloads. */
	const MAX_DEPTH = 10;

	/**
	 * Keys whose value is fully redacted wherever they appear.
	 * Compared case-insensitively.
	 */
	private static $secret_keys = array(
		'payment_api_key',
		'api_key',
		'test_api_key',
		'live_api_key',
		'test_webhook_secret',
		'live_webhook_secret',
		'x-api-key',
		'apikey',
		'webhook_secret',
		'secret',
		'secret_key',
		'clientsecret',
		'client_secret',
		'authorization',
		'cookie',
		'set-cookie',
		'password',
		'pass',
		'token',
		'access_token',
		'refresh_token',
		// Connect with XPay: the token response's minted key, and the PKCE
		// verifier (holding it plus an intercepted code redeems the code).
		'xpay_restricted_key',
		'code_verifier',
		'card_number',
		'cardnumber',
		'pan',
		'cvv',
		'cvc',
		'card_cvv',
		'security_code',
	);

	/** Keys whose value keeps its last 4 characters for traceability. */
	private static $pii_keys = array(
		'email',
		'billing_email',
		'phone',
		'phone_number',
		'billing_phone',
		'name',
		'first_name',
		'last_name',
		'billing_first_name',
		'billing_last_name',
		'billing_data',
		'customerdetails',
		'address',
		'billing_address',
		'shipping_address',
		// Order keys grant order-received page access — traceable last-4
		// keeps them matchable to an order without being replayable.
		'order_key',
		'wc_order_key',
	);

	/**
	 * Recursively redact a context value. Returns a new value — never
	 * mutates the input.
	 *
	 * @param mixed $value Log context.
	 * @param int   $depth Internal recursion counter.
	 * @return mixed
	 */
	public static function redact( $value, int $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return '[TRUNCATED:depth]';
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$lower = is_string( $k ) ? strtolower( $k ) : $k;
				if ( is_string( $lower ) && in_array( $lower, self::$secret_keys, true ) ) {
					$out[ $k ] = self::mask_secret( $v );
				} elseif ( is_string( $lower ) && in_array( $lower, self::$pii_keys, true ) ) {
					$out[ $k ] = self::mask_pii( $v, $depth + 1 );
				} else {
					$out[ $k ] = self::redact( $v, $depth + 1 );
				}
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return self::scrub_string( $value );
		}
		return $value;
	}

	/** Fixed marker plus length hint — confirms "the field was set" without leaking it. */
	public static function mask_secret( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '[REDACTED]';
		}
		$len = strlen( (string) $value );
		return 0 === $len ? '[empty]' : '[REDACTED:' . $len . 'b]';
	}

	/**
	 * Last-4 mask so one customer stays traceable through a flow.
	 *
	 * @param mixed $value PII value (arrays recurse through redact()).
	 * @param int   $depth Recursion depth carried from redact() — resetting
	 *                     it would let nested PII-key chains bypass MAX_DEPTH.
	 */
	public static function mask_pii( $value, int $depth = 0 ) {
		if ( is_array( $value ) ) {
			return self::redact( $value, $depth );
		}
		if ( ! is_scalar( $value ) ) {
			return '[REDACTED]';
		}
		$str = (string) $value;
		$len = strlen( $str );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return str_repeat( '*', $len - 4 ) . substr( $str, -4 );
	}

	/**
	 * Scrubs free-form strings for embedded secrets: PAN-shaped digit runs
	 * (the value-shape backstop), Bearer tokens, api_key= fragments.
	 */
	public static function scrub_string( string $str ): string {
		$str = preg_replace_callback(
			'/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/',
			function ( $m ) {
				$digits = preg_replace( '/\D/', '', $m[0] );
				if ( strlen( $digits ) < 13 ) {
					return $m[0];
				}
				return '****-****-****-' . substr( $digits, -4 );
			},
			$str
		);

		$str = preg_replace( '/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[REDACTED]', $str );
		$str = preg_replace( '/(x-api-key["\']?\s*[:=]\s*["\']?)[^"\'\s,&}]+/i', '$1[REDACTED]', $str );
		$str = preg_replace( '/(api_key["\']?\s*[:=]\s*["\']?)[^"\'\s,&}]+/i', '$1[REDACTED]', $str );
		$str = preg_replace( '/((?:sk|rk|pk)_(?:live|test)_)[A-Za-z0-9]+/', '$1[REDACTED]', $str );
		$str = preg_replace( '/(whsec_)[A-Za-z0-9_\-]+/', '$1[REDACTED]', $str );

		return $str;
	}
}
