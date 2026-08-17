<?php
/**
 * XPay_Payment_Methods registry tests.
 *
 * Pins the wire contract the per-method rows depend on. Origin: the PR #10
 * review round proved wire-string casing is where this integration breaks
 * silently (refund statuses are UPPERCASE, session statuses lowercase) —
 * these tests hold the method-type strings and the pin normalization to
 * the platform enum (payment-method-type.enum.ts, lowercase) so a drift
 * fails the suite instead of failing checkout.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class PaymentMethodsTest extends TestCase {

	public function test_wire_strings_match_the_platform_enum(): void {
		$this->assertSame( 'card', XPay_Payment_Methods::CARD );
		$this->assertSame( 'valu', XPay_Payment_Methods::VALU );
		$this->assertSame( 'fawry', XPay_Payment_Methods::FAWRY );
		$this->assertSame( array( 'card', 'valu', 'fawry' ), XPay_Payment_Methods::SPLITTABLE );
	}

	public function test_card_networks_exclude_amex(): void {
		$this->assertSame( array( 'visa', 'mastercard', 'meeza' ), XPay_Payment_Methods::CARD_NETWORKS );
		$this->assertNotContains( 'amex', XPay_Payment_Methods::CARD_NETWORKS );
	}

	public function test_gateway_ids_derive_from_the_family_prefix(): void {
		$this->assertSame( 'xpay_card', XPay_Payment_Methods::gateway_id( XPay_Payment_Methods::CARD ) );
		$this->assertSame( 'xpay_valu', XPay_Payment_Methods::gateway_id( XPay_Payment_Methods::VALU ) );
		$this->assertTrue( XPay_Constants::is_xpay_gateway( 'xpay' ) );
		$this->assertTrue( XPay_Constants::is_xpay_gateway( 'xpay_valu' ) );
		$this->assertFalse( XPay_Constants::is_xpay_gateway( 'xpayother' ) );
		$this->assertFalse( XPay_Constants::is_xpay_gateway( 'cod' ) );
	}

	/** @return array<string, array{array, array, bool}> */
	public function pin_pairs(): array {
		return array(
			'same single type'          => array( array( 'valu' ), array( 'valu' ), true ),
			'order never matters'       => array( array( 'card', 'valu' ), array( 'valu', 'card' ), true ),
			'different types differ'    => array( array( 'card' ), array( 'valu' ), false ),
			'subset is not equality'    => array( array( 'card' ), array( 'card', 'valu' ), false ),
			'unknown types are ignored' => array( array( 'card', 'bitcoin' ), array( 'card' ), true ),
			'empty equals empty'        => array( array(), array(), true ),
		);
	}

	/** @dataProvider pin_pairs */
	public function test_pin_normalization_defines_equality( array $a, array $b, bool $equal ): void {
		$this->assertSame(
			$equal,
			XPay_Payment_Methods::normalize_pin( $a ) === XPay_Payment_Methods::normalize_pin( $b )
		);
	}

	public function test_normalized_pin_is_stable_for_storage(): void {
		// The stored order meta and a freshly computed pin must compare as
		// plain strings across requests: sorted and comma-joined.
		$this->assertSame( 'card,valu', XPay_Payment_Methods::normalize_pin( array( 'valu', 'card' ) ) );
		$this->assertSame( '', XPay_Payment_Methods::normalize_pin( array() ) );
	}
}
