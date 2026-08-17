<?php
/**
 * XPay_Money
 *
 * The only place amounts are converted between WooCommerce's decimal
 * strings and XPay's integer minor units. All arithmetic is string-based —
 * a float can silently corrupt piasters (0.1 + 0.2 problems), and the v3
 * monorepo lint-bans hardcoded 2-decimal assumptions for the same reason:
 * KWD/JOD/OMR/BHD/LYD are 3-decimal currencies.
 *
 * Pure class: no WordPress dependencies, covered by tests/MoneyTest.php.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Money {

	/**
	 * ISO 4217 minor-unit exponents for the currencies XPay supports
	 * (currencies.seed.ts). Everything else defaults to 2.
	 */
	const DECIMALS = array(
		'EGP' => 2,
		'USD' => 2,
		'EUR' => 2,
		'GBP' => 2,
		'SAR' => 2,
		'AED' => 2,
		'QAR' => 2,
		'KWD' => 3,
		'JOD' => 3,
		'OMR' => 3,
		'BHD' => 3,
		'LYD' => 3,
		'AUD' => 2,
		'CAD' => 2,
		'CNY' => 2,
	);

	public static function decimals( string $currency ): int {
		$currency = strtoupper( $currency );
		return isset( self::DECIMALS[ $currency ] ) ? self::DECIMALS[ $currency ] : 2;
	}

	/**
	 * Decimal amount (string or numeric, as WooCommerce order totals arrive)
	 * to integer minor units. Half-up rounding on any digits beyond the
	 * currency's exponent, matching wc_format_decimal behavior.
	 *
	 * @param string|int|float $amount   e.g. "149.50".
	 * @param string           $currency ISO code, e.g. "EGP".
	 *
	 * @throws InvalidArgumentException On a non-numeric amount.
	 */
	public static function to_minor( $amount, string $currency ): int {
		$str = trim( (string) $amount );
		if ( '' === $str || 1 !== preg_match( '/^-?\d*(\.\d+)?$/', $str ) || '-' === $str ) {
			throw new InvalidArgumentException( 'Amount is not a plain decimal number' );
		}

		$negative = 0 === strpos( $str, '-' );
		if ( $negative ) {
			$str = substr( $str, 1 );
		}

		$decimals = self::decimals( $currency );
		$parts    = explode( '.', $str, 2 );
		$whole    = '' === $parts[0] ? '0' : $parts[0];
		$fraction = isset( $parts[1] ) ? $parts[1] : '';

		// Pad the fraction to exponent+1 digits, then round half-up on the extra digit.
		$fraction = str_pad( substr( $fraction, 0, $decimals + 1 ), $decimals + 1, '0' );
		$rounder  = (int) $fraction[ $decimals ];
		$minor    = (int) ( $whole . substr( $fraction, 0, $decimals ) );
		if ( $rounder >= 5 ) {
			++$minor;
		}

		return $negative ? -$minor : $minor;
	}

	/**
	 * Integer minor units back to a decimal string for display/refund calls.
	 */
	public static function from_minor( int $minor, string $currency ): string {
		$decimals = self::decimals( $currency );
		$negative = $minor < 0;
		$digits   = str_pad( (string) abs( $minor ), $decimals + 1, '0', STR_PAD_LEFT );
		$whole    = substr( $digits, 0, strlen( $digits ) - $decimals );
		$fraction = $decimals > 0 ? '.' . substr( $digits, -1 * $decimals ) : '';
		return ( $negative ? '-' : '' ) . $whole . $fraction;
	}
}
