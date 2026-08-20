<?php
/**
 * XPay_Phone
 *
 * Turns a phone number as WooCommerce stores it into canonical E.164, or
 * says it cannot.
 *
 * This is the general question, asked of every shopper's number before it is
 * sent to XPay as `customerDetails.phone`. It has no opinion about payment
 * methods: a Cairo landline and a British mobile are both real numbers and
 * both come back canonicalised. Whether a number can be CHARGED is a
 * different question, asked by XPay_Bnpl_Phone, which is where the mobile
 * plans live.
 *
 * The split matters. Holding every number to valU's rules here would refuse
 * a card shopper's perfectly good landline as their contact number, and
 * would make "is this a real number" and "can valU charge it" the same
 * question when they are not.
 *
 * Deliberately not libphonenumber: the plugin ships no third-party runtime
 * code, and this layer needs only E.164's own limits. A number it cannot
 * vouch for comes back null, which callers turn into "ask the shopper",
 * never into a silent send.
 *
 * Pure class: no WordPress dependencies, covered by tests/PhoneTest.php.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Phone {

	/**
	 * Calling codes for the countries XPay settles in, keyed by ISO 3166-1
	 * alpha-2. Used only to complete a number typed without one.
	 *
	 * A country absent from this map is not rejected: it simply cannot have
	 * a national number completed for it, so such a number is returned null
	 * and the shopper is asked. Adding a row is safe; guessing one is not.
	 */
	const CALLING_CODES = array(
		'EG' => '20',
		'SA' => '966',
		'AE' => '971',
		'QA' => '974',
		'KW' => '965',
		'JO' => '962',
		'OM' => '968',
		'BH' => '973',
		'LY' => '218',
		'US' => '1',
		'CA' => '1',
		'GB' => '44',
		'AU' => '61',
		'CN' => '86',
	);

	/** E.164 caps the whole number, calling code included, at 15 digits. */
	const E164_MAX_DIGITS = 15;

	/** Below this a string is too short to be anyone's number. */
	const E164_MIN_DIGITS = 8;

	/**
	 * Canonical E.164 for a number as WooCommerce stores it, or null when
	 * this class cannot vouch for it.
	 *
	 * Null is the signal to ask the shopper. It never means "send it anyway".
	 *
	 * @param string $raw     Billing phone, in whatever shape it was typed.
	 * @param string $country Billing country, ISO 3166-1 alpha-2. Used only
	 *                        when the number carries no calling code.
	 */
	public static function to_e164( string $raw, string $country = '' ): ?string {
		$digits = self::digits_of( $raw );
		if ( '' === $digits ) {
			return null;
		}

		$country = strtoupper( trim( $country ) );

		// A number that names its own country wins over the billing country:
		// a shopper billed in Egypt may still pay from a Gulf mobile.
		$international = self::international_digits( $raw, $digits );

		if ( null === $international ) {
			$code = isset( self::CALLING_CODES[ $country ] ) ? self::CALLING_CODES[ $country ] : null;
			if ( null === $code ) {
				return null;
			}
			// National numbers are written with a trunk zero across every
			// plan we list; E.164 has no trunk prefix.
			$international = $code . ltrim( $digits, '0' );
		}

		return self::vouch( $international );
	}

	/**
	 * Whether this number can be sent as-is.
	 *
	 * @param string $raw     Billing phone.
	 * @param string $country Billing country, ISO 3166-1 alpha-2.
	 */
	public static function is_valid( string $raw, string $country = '' ): bool {
		return null !== self::to_e164( $raw, $country );
	}

	/**
	 * Judge a number already in international digits, and return it in E.164
	 * form when it holds up.
	 *
	 * @param string $international Digits including the calling code.
	 */
	private static function vouch( string $international ): ?string {
		$length = strlen( $international );
		if ( $length < self::E164_MIN_DIGITS || $length > self::E164_MAX_DIGITS ) {
			return null;
		}

		return '+' . $international;
	}

	/**
	 * The international digits a number states for itself, or null when it
	 * states none.
	 *
	 * Both spellings of "this is international" are honored: a leading plus,
	 * and the 00 trunk used across the region.
	 *
	 * @param string $raw    The number as typed.
	 * @param string $digits Its digits, already extracted.
	 */
	private static function international_digits( string $raw, string $digits ): ?string {
		if ( 0 === strpos( ltrim( $raw ), '+' ) ) {
			return $digits;
		}
		if ( 0 === strpos( $digits, '00' ) ) {
			return substr( $digits, 2 );
		}
		return null;
	}

	/**
	 * Digits only. Everything a person might type between them — spaces,
	 * dashes, dots, brackets, the plus itself — is punctuation here.
	 *
	 * @param string $raw The number as typed.
	 */
	private static function digits_of( string $raw ): string {
		return (string) preg_replace( '/\D+/', '', $raw );
	}
}
