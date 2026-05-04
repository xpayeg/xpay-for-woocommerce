<?php
/**
 * Redacts secrets and PII from log entries before they are written to disk.
 *
 * Redaction must happen at write time, not display time — so anything that
 * lands on disk is already safe to share with the merchant or upload to a
 * support ticket. The redactor is intentionally conservative: it errs on
 * the side of redacting too much rather than leaking a key.
 */

defined( 'ABSPATH' ) or exit;

final class WC_XPay_Logger_Redactor {

	/**
	 * Keys whose value must be fully redacted regardless of where they appear
	 * in a context array. Compared case-insensitively.
	 */
	private static $secret_keys = array(
		'payment_api_key',
		'api_key',
		'x-api-key',
		'webhook_secret',
		'secret',
		'secret_key',
		'authorization',
		'cookie',
		'set-cookie',
		'password',
		'pass',
		'token',
		'access_token',
		'refresh_token',
		'card_number',
		'cardnumber',
		'pan',
		'cvv',
		'cvc',
		'card_cvv',
		'security_code',
	);

	/**
	 * Keys whose value should be partially masked (last 4 visible) — emails,
	 * phone numbers, names that often appear in webhook payloads.
	 */
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
	);

	/**
	 * Recursively redact a context array. Returns a new array — never
	 * mutates the input. Strings within values are scanned for embedded
	 * secret patterns even when their key is not on the list.
	 */
	public static function redact( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$lower = is_string( $k ) ? strtolower( $k ) : $k;
				if ( is_string( $lower ) && in_array( $lower, self::$secret_keys, true ) ) {
					$out[ $k ] = self::mask_secret( $v );
				} elseif ( is_string( $lower ) && in_array( $lower, self::$pii_keys, true ) ) {
					$out[ $k ] = self::mask_pii( $v );
				} else {
					$out[ $k ] = self::redact( $v );
				}
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return self::scrub_string( $value );
		}
		return $value;
	}

	/**
	 * Replaces a secret with a fixed marker plus a length hint. Useful so
	 * the merchant can confirm "yes the field was set" without leaking it.
	 */
	public static function mask_secret( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '[REDACTED]';
		}
		$len = strlen( (string) $value );
		if ( 0 === $len ) {
			return '[empty]';
		}
		return '[REDACTED:' . $len . 'b]';
	}

	/**
	 * Masks PII while keeping the last few characters so log entries are
	 * still useful for tracing a single customer through the flow without
	 * exposing the full identifier.
	 */
	public static function mask_pii( $value ) {
		if ( is_array( $value ) ) {
			return self::redact( $value );
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
	 * Scrubs free-form strings for embedded secrets (api keys printed inside
	 * a header line, card numbers in a debug dump, etc.). The patterns here
	 * are conservative — false positives are acceptable, false negatives are
	 * not.
	 */
	public static function scrub_string( $str ) {
		// 13-19 digit runs (PAN). Allow optional separators but only mask if
		// the digits-only form is at least 13 long.
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

		// Authorization: Bearer <token>
		$str = preg_replace( '/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[REDACTED]', $str );

		// x-api-key: <value>  or  api_key=<value>
		$str = preg_replace( '/(x-api-key["\']?\s*[:=]\s*["\']?)[^"\'\s,&}]+/i', '$1[REDACTED]', $str );
		$str = preg_replace( '/(api_key["\']?\s*[:=]\s*["\']?)[^"\'\s,&}]+/i', '$1[REDACTED]', $str );

		return $str;
	}
}
