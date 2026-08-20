<?php
/**
 * XPay_Wallet_Phone
 *
 * Decides which number goes to XPay as the valU wallet, and whether the
 * shopper has to be asked for one.
 *
 * XPay_Phone answers "is this number real". This answers the question the
 * checkout actually has: given what WooCommerce holds and what the shopper
 * may have just typed into the correction field, what do we send, and do we
 * stop and ask first.
 *
 * The two are separate because the checkout has two candidate numbers and a
 * precedence between them. Folding that into the validator would make it
 * answer a question it has no business knowing about.
 *
 * Only valU needs this. A card shopper's phone never reaches a wallet, so
 * asking them to fix it would be asking for something the payment does not
 * use. The plugin already refused that trade once by keeping
 * phoneNumberCollection off the combined session, which is session wide and
 * would have made the phone required for card shoppers too.
 *
 * Pure class: no WordPress dependencies, covered by tests/WalletPhoneTest.php.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Wallet_Phone {

	/**
	 * The mobile plans valU can actually charge, keyed by calling code and
	 * matched against the number in national form.
	 *
	 * valU operates in Egypt and Jordan, so a number outside those two
	 * plans names no wallet, however well formed it is. This is the whole
	 * point of the class: a British mobile is a real number and a valid
	 * contact detail, and still cannot be charged.
	 *
	 * Landlines are excluded by construction rather than by a separate
	 * rule. valU pays from a mobile wallet, so a valid Cairo or Amman
	 * landline is still the wrong number here.
	 *
	 * Egypt: a leading 1, one of the four operator digits, eight more.
	 * Jordan: a leading 7, one of the three operator digits, seven more.
	 */
	const MOBILE_PLANS = array(
		'20'  => '/^1[0125]\d{8}$/',
		'962' => '/^7[789]\d{7}$/',
	);


	/**
	 * The number to send, in E.164, or null when there is not an acceptable
	 * one anywhere.
	 *
	 * The shopper's correction wins when it holds up: they typed it while
	 * looking at the valU row, which is later and more deliberate than a
	 * billing field they filled for delivery. A correction that does not
	 * hold up does NOT fall back to the billing number, because falling
	 * back would quietly send a number the shopper has just tried to
	 * replace.
	 *
	 * @param string $submitted       Correction field, empty when untouched.
	 * @param string $billing_phone   Order or customer billing phone.
	 * @param string $billing_country Billing country, ISO 3166-1 alpha-2.
	 */
	public static function resolve( string $submitted, string $billing_phone, string $billing_country ): ?string {
		if ( '' !== trim( $submitted ) ) {
			return self::chargeable( XPay_Phone::to_e164( $submitted, $billing_country ) );
		}
		return self::chargeable( XPay_Phone::to_e164( $billing_phone, $billing_country ) );
	}

	/**
	 * Whether this checkout has to ask the shopper for a number.
	 *
	 * True only when the method actually spends a wallet and nothing we
	 * hold can be sent. A card shopper is never asked.
	 *
	 * @param string $method_type     Wire string of the method being paid with.
	 * @param string $submitted       Correction field, empty when untouched.
	 * @param string $billing_phone   Order or customer billing phone.
	 * @param string $billing_country Billing country, ISO 3166-1 alpha-2.
	 */
	public static function must_ask( string $method_type, string $submitted, string $billing_phone, string $billing_country ): bool {
		if ( ! self::spends_a_wallet( $method_type ) ) {
			return false;
		}
		return null === self::resolve( $submitted, $billing_phone, $billing_country );
	}

	/**
	 * A canonical number back again if valU could charge it, or null.
	 *
	 * Null here is not "this is not a number". It is "this is not a wallet",
	 * which is why it never reaches XPay_Phone: that class is still right
	 * that a British mobile is a real number.
	 *
	 * @param string|null $e164 Canonical number, or null from the canonicaliser.
	 */
	private static function chargeable( ?string $e164 ): ?string {
		if ( null === $e164 ) {
			return null;
		}
		$digits = ltrim( $e164, '+' );

		foreach ( self::MOBILE_PLANS as $calling_code => $plan ) {
			if ( 0 !== strpos( $digits, (string) $calling_code ) ) {
				continue;
			}
			$national = substr( $digits, strlen( (string) $calling_code ) );
			return 1 === preg_match( $plan, $national ) ? $e164 : null;
		}

		return null;
	}

	/**
	 * Whether a payment method draws on a phone-identified wallet.
	 *
	 * A method list rather than a not-card test: Fawry is not card either,
	 * and it pays by reference code at a kiosk, not from a number we hold.
	 *
	 * @param string $method_type Wire string of the method.
	 */
	public static function spends_a_wallet( string $method_type ): bool {
		return XPay_Payment_Methods::VALU === $method_type;
	}
}
