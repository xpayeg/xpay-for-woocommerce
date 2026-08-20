<?php
/**
 * Guards for XPay_Bnpl_Phone.
 *
 * Two rules carry the weight here and both are easy to regress into
 * something that looks kinder and is worse: a card shopper must never be
 * asked for a number their payment does not spend, and a correction that
 * does not hold up must never quietly fall back to the billing number the
 * shopper was in the middle of replacing.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class BnplPhoneTest extends TestCase {

	const GOOD     = '01012345678';
	const GOOD_E164 = '+201012345678';
	const OTHER    = '01112345678';
	const OTHER_E164 = '+201112345678';
	/** An Emirati mobile typed by a shopper billed in Egypt. */
	const TRAP     = '563333431';

	/* ── resolve ─────────────────────────────────────────────────────── */

	public function test_billing_phone_is_used_when_nothing_was_corrected(): void {
		$this->assertSame(
			self::GOOD_E164,
			XPay_Bnpl_Phone::resolve( '', self::GOOD, 'EG' )
		);
	}

	public function test_correction_outranks_the_billing_phone(): void {
		$this->assertSame(
			self::OTHER_E164,
			XPay_Bnpl_Phone::resolve( self::OTHER, self::GOOD, 'EG' )
		);
	}

	/**
	 * The rule worth pinning. A shopper who starts correcting and gets it
	 * wrong must be stopped, not silently charged on the number they were
	 * replacing.
	 */
	public function test_a_bad_correction_does_not_fall_back_to_billing(): void {
		$this->assertNull(
			XPay_Bnpl_Phone::resolve( self::TRAP, self::GOOD, 'EG' )
		);
	}

	public function test_correction_rescues_an_unusable_billing_phone(): void {
		$this->assertSame(
			self::GOOD_E164,
			XPay_Bnpl_Phone::resolve( self::GOOD, '0223456789', 'EG' )
		);
	}

	public function test_whitespace_only_correction_counts_as_untouched(): void {
		$this->assertSame(
			self::GOOD_E164,
			XPay_Bnpl_Phone::resolve( "  \t ", self::GOOD, 'EG' )
		);
	}

	public function test_nothing_usable_anywhere(): void {
		$this->assertNull( XPay_Bnpl_Phone::resolve( '', '', 'EG' ) );
		$this->assertNull( XPay_Bnpl_Phone::resolve( '', self::TRAP, 'EG' ) );
	}

	/* ── must_ask ────────────────────────────────────────────────────── */

	public function test_card_shoppers_are_never_asked(): void {
		// Every one of these would stop a valU shopper. None may stop a
		// card shopper: their payment does not spend a wallet.
		$this->assertFalse( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::CARD, '', '', 'EG' ) );
		$this->assertFalse( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::CARD, '', self::TRAP, 'EG' ) );
		$this->assertFalse( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::CARD, self::TRAP, '', 'EG' ) );
	}

	public function test_fawry_shoppers_are_never_asked(): void {
		// Fawry is not card either, and pays by reference code at a kiosk.
		// "Not card" is the wrong test; spending a wallet is the right one.
		$this->assertFalse( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::FAWRY, '', self::TRAP, 'EG' ) );
	}

	public function test_valu_shopper_with_a_good_billing_phone_is_not_asked(): void {
		$this->assertFalse( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::VALU, '', self::GOOD, 'EG' ) );
	}

	public function test_valu_shopper_with_an_unusable_billing_phone_is_asked(): void {
		$this->assertTrue( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::VALU, '', '', 'EG' ) );
		$this->assertTrue( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::VALU, '', self::TRAP, 'EG' ) );
		$this->assertTrue( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::VALU, '', '0223456789', 'EG' ) );
	}

	public function test_valu_shopper_stops_being_asked_once_corrected(): void {
		$this->assertFalse( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::VALU, self::GOOD, self::TRAP, 'EG' ) );
	}

	public function test_valu_shopper_is_still_asked_after_a_bad_correction(): void {
		$this->assertTrue( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::VALU, self::TRAP, self::GOOD, 'EG' ) );
	}

	/**
	 * A card shopper anywhere in the world must reach Place Order.
	 *
	 * The wallet rule is built around Egypt's mobile plan because valU is
	 * Egyptian. Nothing about that may reach a card payment: a British
	 * shopper with a British number, an Emirati with an Emirati one, a
	 * shopper whose country the plugin carries no calling code for, and a
	 * shopper who left the field blank entirely all pay by card without
	 * being asked anything.
	 *
	 * @dataProvider foreign_card_shoppers
	 *
	 * @param string $phone   Billing phone as typed.
	 * @param string $country Billing country.
	 */
	public function test_card_is_never_restricted_to_egyptian_numbers( string $phone, string $country ): void {
		$this->assertFalse(
			XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::CARD, '', $phone, $country ),
			sprintf( 'A card shopper with %s in %s was asked for a wallet number.', $phone, $country )
		);
	}

	/** @return array<string, array{string, string}> */
	public function foreign_card_shoppers(): array {
		return array(
			'UK mobile, UK billing'        => array( '+447700900123', 'GB' ),
			'UK national, UK billing'      => array( '07700900123', 'GB' ),
			'Emirati mobile, UAE billing'  => array( '+971563333431', 'AE' ),
			'Emirati national, UAE billing' => array( '0563333431', 'AE' ),
			'US number, US billing'        => array( '+12125550123', 'US' ),
			'Saudi mobile, Saudi billing'  => array( '+966512345678', 'SA' ),
			// A country the plugin carries no calling code for. The wallet
			// path refuses to guess one; card must not care.
			'unknown country'              => array( '0712345678', 'ZZ' ),
			// Shapes that fail the wallet rule outright.
			'no phone at all'              => array( '', 'GB' ),
			'Egyptian landline'            => array( '0223456789', 'EG' ),
			'the Emirati trap, EG billing' => array( '563333431', 'EG' ),
			'nonsense'                     => array( 'call the shop', 'GB' ),
		);
	}

	/**
	 * The same for Fawry, which is also not a wallet.
	 *
	 * @dataProvider foreign_card_shoppers
	 *
	 * @param string $phone   Billing phone as typed.
	 * @param string $country Billing country.
	 */
	public function test_fawry_is_never_restricted_to_egyptian_numbers( string $phone, string $country ): void {
		$this->assertFalse( XPay_Bnpl_Phone::must_ask( XPay_Payment_Methods::FAWRY, '', $phone, $country ) );
	}

	/* ── which numbers valU can actually charge ──────────────────────── */

	/**
	 * valU operates in Egypt and Jordan, so only those two mobile plans
	 * name a wallet. Everything else is refused however real it is: this is
	 * the difference between "is this a number" and "can this be charged",
	 * and the reason the plans live here rather than in XPay_Phone.
	 *
	 * @dataProvider wallet_numbers
	 *
	 * @param string      $phone    Billing phone as typed.
	 * @param string      $country  Billing country.
	 * @param string|null $expected Hand-written expectation.
	 */
	public function test_only_egyptian_and_jordanian_mobiles_are_chargeable( string $phone, string $country, ?string $expected ): void {
		$this->assertSame( $expected, XPay_Bnpl_Phone::resolve( '', $phone, $country ) );
	}

	/** @return array<string, array{string, string, string|null}> */
	public function wallet_numbers(): array {
		return array(
			// Egypt: the four operator digits.
			'EG Vodafone'                 => array( '01012345678', 'EG', '+201012345678' ),
			'EG Etisalat'                 => array( '01112345678', 'EG', '+201112345678' ),
			'EG Orange'                   => array( '01212345678', 'EG', '+201212345678' ),
			'EG WE'                       => array( '01512345678', 'EG', '+201512345678' ),
			'EG in plus form'             => array( '+201012345678', 'EG', '+201012345678' ),

			// Jordan: 077, 078 and 079 are the mobile prefixes.
			'JO Umniah 077'               => array( '0771234567', 'JO', '+962771234567' ),
			'JO Orange 078'               => array( '0781234567', 'JO', '+962781234567' ),
			'JO Zain 079'                 => array( '0791234567', 'JO', '+962791234567' ),
			'JO in plus form'             => array( '+962791234567', 'JO', '+962791234567' ),
			'JO number, EG billing'       => array( '+962791234567', 'EG', '+962791234567' ),

			// Jordan, but not a mobile.
			'JO 076 is not a mobile'      => array( '0761234567', 'JO', null ),
			'JO Amman landline'           => array( '062345678', 'JO', null ),
			'JO mobile one digit short'   => array( '079123456', 'JO', null ),

			// Egypt, but not a mobile.
			'EG Cairo landline'           => array( '0223456789', 'EG', null ),
			'EG unassigned operator 3'    => array( '01312345678', 'EG', null ),
			'EG unassigned operator 9'    => array( '01912345678', 'EG', null ),
			'EG mobile one digit short'   => array( '0101234567', 'EG', null ),
			'EG mobile one digit long'    => array( '010123456789', 'EG', null ),

			// The trap: an Emirati mobile typed with the picker on Egypt.
			'EG billing, Emirati mobile'  => array( '563333431', 'EG', null ),

			// Real numbers, real people, no valU account behind them. This is
			// the tightening: before it, each of these was accepted because
			// only +20 was checked against a plan.
			'British mobile'              => array( '07700900123', 'GB', null ),
			'British mobile, plus form'   => array( '+447700900123', 'GB', null ),
			'Emirati mobile, own country' => array( '0563333431', 'AE', null ),
			'Saudi mobile'                => array( '+966512345678', 'SA', null ),
			'US number'                   => array( '+12125550123', 'US', null ),

			// Nothing to charge.
			'empty'                       => array( '', 'EG', null ),
			'unknown country'             => array( '0712345678', 'ZZ', null ),
		);
	}

	/**
	 * Egypt's code is two digits and Jordan's is three, so the plan lookup
	 * walks prefixes of different lengths. Pinned so a future third country
	 * cannot be matched by the wrong plan: no three-digit calling code
	 * begins with 20, and 962 shares no prefix with it.
	 */
	public function test_neighbouring_calling_codes_do_not_borrow_a_plan(): void {
		// +211 South Sudan and +212 Morocco both begin "21", not "20".
		$this->assertNull( XPay_Bnpl_Phone::resolve( '+211912345678', '', '' ) );
		$this->assertNull( XPay_Bnpl_Phone::resolve( '+212612345678', '', '' ) );
		// +96 is not a country code; +965 Kuwait must not read as +962.
		$this->assertNull( XPay_Bnpl_Phone::resolve( '+96512345678', '', '' ) );
	}

	/* ── needs_bnpl_number ─────────────────────────────────────────────── */

	public function test_only_valu_needs_bnpl_number(): void {
		$this->assertTrue( XPay_Bnpl_Phone::needs_bnpl_number( XPay_Payment_Methods::VALU ) );
		$this->assertFalse( XPay_Bnpl_Phone::needs_bnpl_number( XPay_Payment_Methods::CARD ) );
		$this->assertFalse( XPay_Bnpl_Phone::needs_bnpl_number( XPay_Payment_Methods::FAWRY ) );
		$this->assertFalse( XPay_Bnpl_Phone::needs_bnpl_number( '' ) );
	}

	/* ── The prompt's placeholder ────────────────────────────────────── */

	/**
	 * A Jordanian shopper must see a Jordanian example. An Egyptian one
	 * would have to be mentally translated before it helped, which is the
	 * opposite of what a placeholder is for.
	 *
	 * @dataProvider placeholder_cases
	 *
	 * @param string $country  Billing country.
	 * @param string $expected Hand-written expectation.
	 */
	public function test_example_follows_the_shoppers_country( string $country, string $expected ): void {
		$this->assertSame( $expected, XPay_Bnpl_Phone::example_for( $country ) );
	}

	/** @return array<string, array{string, string}> */
	public function placeholder_cases(): array {
		return array(
			'Jordan'            => array( 'JO', '07 9012 3456' ),
			'Jordan lowercase'  => array( 'jo', '07 9012 3456' ),
			'Jordan padded'     => array( ' JO ', '07 9012 3456' ),
			'Egypt'             => array( 'EG', '010 1234 5678' ),
			// Everywhere else falls back to Egypt, where most valU
			// shoppers are, rather than showing nothing.
			'unknown country'   => array( 'ZZ', '010 1234 5678' ),
			'no country given'  => array( '', '010 1234 5678' ),
		);
	}

	/**
	 * Both examples must be numbers this plugin would actually accept,
	 * or the placeholder is teaching the shopper a shape we then refuse.
	 */
	public function test_both_examples_would_pass_our_own_rules(): void {
		$this->assertNotNull( XPay_Bnpl_Phone::resolve( XPay_Bnpl_Phone::example_for( 'EG' ), '', 'EG' ) );
		$this->assertNotNull( XPay_Bnpl_Phone::resolve( XPay_Bnpl_Phone::example_for( 'JO' ), '', 'JO' ) );
	}

	/**
	 * The published method list and the per-method check must never
	 * disagree: the page hides or shows the prompt from the list, and the
	 * server validates from the check.
	 */
	public function test_the_published_list_agrees_with_the_check(): void {
		foreach ( XPay_Bnpl_Phone::METHODS as $method ) {
			$this->assertTrue( XPay_Bnpl_Phone::needs_bnpl_number( $method ) );
		}
		$this->assertFalse( XPay_Bnpl_Phone::needs_bnpl_number( 'card' ) );
		$this->assertFalse( XPay_Bnpl_Phone::needs_bnpl_number( 'fawry' ) );
	}
}
