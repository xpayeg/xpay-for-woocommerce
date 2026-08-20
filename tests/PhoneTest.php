<?php
/**
 * Guards for XPay_Phone.
 *
 * This class canonicalises and nothing more. Several cases below are real
 * numbers that valU cannot charge, and they are expected to canonicalise
 * cleanly here: refusing them is XPay_Bnpl_Phone's job, and the tests are
 * split the same way the classes are so that neither drifts into answering
 * the other's question.
 *
 * Expected values are hand-written literals, never recomputed with the
 * implementation's own formula.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase {

	/** @return array<string, array{string, string, string|null}> */
	public function e164_cases(): array {
		return array(
			// Egyptian mobiles, one per operator digit, in national form.
			'EG Vodafone national'          => array( '01012345678', 'EG', '+201012345678' ),
			'EG Etisalat national'          => array( '01112345678', 'EG', '+201112345678' ),
			'EG Orange national'            => array( '01212345678', 'EG', '+201212345678' ),
			'EG WE national'                => array( '01512345678', 'EG', '+201512345678' ),

			// The same number written every way a person writes it.
			'EG spaced'                     => array( '010 1234 5678', 'EG', '+201012345678' ),
			'EG dashed'                     => array( '010-1234-5678', 'EG', '+201012345678' ),
			'EG bracketed trunk'            => array( '(010) 1234 5678', 'EG', '+201012345678' ),
			'EG plus form'                  => array( '+201012345678', 'EG', '+201012345678' ),
			'EG plus form spaced'           => array( '+20 101 234 5678', 'EG', '+201012345678' ),
			'EG double-zero trunk'          => array( '00201012345678', 'EG', '+201012345678' ),
			'EG no trunk zero'              => array( '1012345678', 'EG', '+201012345678' ),

			// A number that names its own country beats the billing country.
			'Emirati mobile, plus form'     => array( '+971563333431', 'EG', '+971563333431' ),
			'Emirati mobile, own country'   => array( '0563333431', 'AE', '+971563333431' ),
			'Jordanian mobile, own country' => array( '0791234567', 'JO', '+962791234567' ),
			'British mobile, own country'   => array( '07700900123', 'GB', '+447700900123' ),

			// Numbers this class canonicalises and does NOT judge. Each one
			// is a real, well-formed number that valU cannot charge, and
			// each is refused by XPay_Bnpl_Phone rather than here. Pinned
			// so the two questions stay separate: a Cairo landline is a
			// perfectly good contact number for a card shopper.
			'EG Cairo landline'             => array( '0223456789', 'EG', '+20223456789' ),
			'EG Alexandria landline'        => array( '033456789', 'EG', '+2033456789' ),
			'EG unassigned operator 3'      => array( '01312345678', 'EG', '+201312345678' ),
			'EG unassigned operator 9'      => array( '01912345678', 'EG', '+201912345678' ),
			'EG mobile one digit short'     => array( '0101234567', 'EG', '+20101234567' ),
			'EG mobile one digit long'      => array( '010123456789', 'EG', '+2010123456789' ),
			// The Emirati number typed with the picker on Egypt. Still a
			// number; still not a valU account.
			'EG billing, Emirati digits'    => array( '563333431', 'EG', '+20563333431' ),

			// A national number for a country whose calling code we do not
			// carry cannot be completed, so it is asked about, not guessed.
			'unknown country, national'     => array( '0712345678', 'ZZ', null ),
			'no country given, national'    => array( '01012345678', '', null ),

			// The same shopper is fine the moment the number says who it is.
			'no country given, plus form'   => array( '+201012345678', '', '+201012345678' ),

			// E.164's own bounds.
			'too short to be anyone'        => array( '+1234567', '', null ),
			'longer than E.164 allows'      => array( '+1234567890123456', '', null ),

			// Nothing to judge.
			'empty'                         => array( '', 'EG', null ),
			'punctuation only'              => array( '+-() ', 'EG', null ),
			'letters only'                  => array( 'call me', 'EG', null ),

			// Lowercase country codes arrive from stores that store them
			// that way.
			'lowercase country code'        => array( '01012345678', 'eg', '+201012345678' ),
		);
	}

	/**
	 * @dataProvider e164_cases
	 *
	 * @param string      $raw      Number as typed.
	 * @param string      $country  Billing country.
	 * @param string|null $expected Hand-written expectation.
	 */
	public function test_to_e164( string $raw, string $country, ?string $expected ): void {
		$this->assertSame( $expected, XPay_Phone::to_e164( $raw, $country ) );
	}

	/**
	 * @dataProvider e164_cases
	 *
	 * @param string      $raw      Number as typed.
	 * @param string      $country  Billing country.
	 * @param string|null $expected Hand-written expectation.
	 */
	public function test_is_valid_agrees_with_to_e164( string $raw, string $country, ?string $expected ): void {
		$this->assertSame( null !== $expected, XPay_Phone::is_valid( $raw, $country ) );
	}

	/**
	 * No three-digit calling code begins with Egypt's two-digit 20, so
	 * matching on that prefix cannot capture another country's number.
	 * Pinned because XPay_Bnpl_Phone applies Egypt's mobile plan behind
	 * exactly that prefix test.
	 */
	public function test_neighbouring_calling_codes_are_not_treated_as_egypt(): void {
		// +211 South Sudan, +212 Morocco, +213 Algeria: all begin "21".
		$this->assertSame( '+211912345678', XPay_Phone::to_e164( '+211912345678', '' ) );
		$this->assertSame( '+212612345678', XPay_Phone::to_e164( '+212612345678', '' ) );
		$this->assertSame( '+213612345678', XPay_Phone::to_e164( '+213612345678', '' ) );
	}

	/**
	 * A number already in canonical form must survive a second pass
	 * unchanged. The field prefills from whatever we stored last time, so
	 * this runs on its own output constantly.
	 */
	public function test_canonical_form_is_stable(): void {
		$once  = XPay_Phone::to_e164( '010 1234 5678', 'EG' );
		$twice = XPay_Phone::to_e164( (string) $once, 'EG' );
		$this->assertSame( '+201012345678', $once );
		$this->assertSame( $once, $twice );
	}
}
