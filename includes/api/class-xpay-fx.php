<?php
/**
 * XPay_Fx
 *
 * Deterministic presentment/settlement conversion using a locked rate.
 *
 *   settlementMinor = presentmentMinor x rate x 10^(toDecimals - fromDecimals)
 *
 * Two details keep the calculation exact:
 *
 *   1. Integer division truncates the remainder.
 *   2. The exponent adjustment. KWD has three decimals and EGP two, so a
 *      naive minor-unit multiply is out by a factor of ten.
 *
 * The rate remains a decimal string so float conversion cannot change it.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Fx {

	/**
	 * Presentment minor units to settlement minor units.
	 *
	 * @param int    $presentment_minor Amount in the shopper's currency.
	 * @param string $rate              Locked rate, "to major per from major".
	 * @param string $from              Presentment currency (the shopper's).
	 * @param string $to                Settlement currency (EGP).
	 * @return int|null Settlement minor units, or null when the inputs cannot
	 *                  be trusted to produce one.
	 */
	public static function to_settlement( int $presentment_minor, string $rate, string $from, string $to ): ?int {
		$from = strtoupper( $from );
		$to   = strtoupper( $to );
		if ( $from === $to ) {
			return $presentment_minor;
		}

		$parsed = self::parse_rate( $rate );
		if ( null === $parsed ) {
			return null;
		}

		$exponent = XPay_Money::decimals( $to ) - XPay_Money::decimals( $from );

		if ( $exponent >= 0 ) {
			$numerator = self::multiply( array( $presentment_minor, $parsed['int'], self::pow10( $exponent ) ) );
			return null === $numerator ? null : intdiv( $numerator, self::pow10( $parsed['scale'] ) );
		}

		$numerator = self::multiply( array( $presentment_minor, $parsed['int'] ) );
		return null === $numerator ? null : intdiv( $numerator, self::pow10( $parsed['scale'] - $exponent ) );
	}

	/**
	 * Settlement minor units back to presentment minor units.
	 *
	 * The inverse, and used for the same reason the platform uses it: to say
	 * what a given EGP figure looks like to the shopper.
	 *
	 * @param int    $settlement_minor Amount in EGP.
	 * @param string $rate             Locked rate, "to major per from major".
	 * @param string $from             Presentment currency (the shopper's).
	 * @param string $to               Settlement currency (EGP).
	 * @return int|null Presentment minor units, or null when the inputs cannot
	 *                  be trusted to produce one.
	 */
	public static function to_presentment( int $settlement_minor, string $rate, string $from, string $to ): ?int {
		$from = strtoupper( $from );
		$to   = strtoupper( $to );
		if ( $from === $to ) {
			return $settlement_minor;
		}

		$parsed = self::parse_rate( $rate );
		if ( null === $parsed ) {
			return null;
		}

		$exponent = XPay_Money::decimals( $from ) - XPay_Money::decimals( $to );

		if ( $exponent >= 0 ) {
			$numerator = self::multiply( array( $settlement_minor, self::pow10( $exponent ), self::pow10( $parsed['scale'] ) ) );
			return null === $numerator ? null : intdiv( $numerator, $parsed['int'] );
		}

		$divisor = self::multiply( array( $parsed['int'], self::pow10( -$exponent ) ) );
		if ( null === $divisor ) {
			return null;
		}
		$numerator = self::multiply( array( $settlement_minor, self::pow10( $parsed['scale'] ) ) );
		return null === $numerator ? null : intdiv( $numerator, $divisor );
	}

	/**
	 * A rate string as an integer and its decimal scale: "51.01" is 5101
	 * scaled by 2. Refuses anything that is not a plain positive decimal —
	 * a zero rate would divide by zero and a negative one would quietly
	 * produce negative money.
	 *
	 * @param string $rate Rate as stored.
	 * @return array{int:int,scale:int}|null
	 */
	private static function parse_rate( string $rate ): ?array {
		$rate = trim( $rate );
		if ( 1 !== preg_match( '/^(\d+)(?:\.(\d+))?$/', $rate, $parts ) ) {
			return null;
		}

		$fraction = isset( $parts[2] ) ? $parts[2] : '';
		$digits   = $parts[1] . $fraction;
		// A rate long enough to overflow is a rate this cannot be trusted
		// with, and 18 digits is far beyond any real one.
		if ( strlen( $digits ) > 18 ) {
			return null;
		}

		$scaled = (int) $digits;
		if ( $scaled <= 0 ) {
			return null;
		}

		return array(
			'int'   => $scaled,
			'scale' => strlen( $fraction ),
		);
	}

	/**
	 * Multiply, answering null rather than a wrapped integer.
	 *
	 * PHP turns an overflowing int into a float, and a float amount here
	 * would be a silently wrong amount of money. Every caller treats null as
	 * "cannot say", which falls back to refunding the whole balance and
	 * letting the platform do the arithmetic.
	 *
	 * @param int[] $factors Numbers to multiply.
	 * @return int|null
	 */
	private static function multiply( array $factors ): ?int {
		$product = 1;
		foreach ( $factors as $factor ) {
			if ( 0 === $factor ) {
				return 0;
			}
			if ( abs( $product ) > intdiv( PHP_INT_MAX, abs( $factor ) ) ) {
				return null;
			}
			$product *= $factor;
		}
		return $product;
	}

	/**
	 * @param int $exponent Power of ten, 0 or more.
	 */
	private static function pow10( int $exponent ): int {
		$result = 1;
		for ( $i = 0; $i < $exponent; $i++ ) {
			$result *= 10;
		}
		return $result;
	}
}
