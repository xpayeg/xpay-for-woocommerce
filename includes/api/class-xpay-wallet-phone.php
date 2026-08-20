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
			return XPay_Phone::to_e164( $submitted, $billing_country );
		}
		return XPay_Phone::to_e164( $billing_phone, $billing_country );
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
