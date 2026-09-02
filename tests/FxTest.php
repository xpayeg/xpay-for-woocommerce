<?php
/**
 * The port of the platform's FX conversion (includes/api/class-xpay-fx.php).
 *
 * These are not "does the arithmetic look right" tests. They pin the two
 * behaviours that make a hand-rolled equivalent wrong:
 *
 *   - TRUNCATION, not rounding. The platform divides with BigInt and throws
 *     the remainder away; rounding half-up here would disagree by a piaster
 *     on a large share of amounts, and the merchant would be shown a figure
 *     that is not the one that moves.
 *   - The EXPONENT adjustment. KWD has three decimals to EGP's two.
 *
 * The expected values below are taken from live sessions on api.xpay.app,
 * not computed from the same formula being tested.
 *
 * @package XPay_For_WooCommerce
 */

class FxTest extends PHPUnit\Framework\TestCase {

	/* ── Verified against the live API ───────────────────────────────── */

	public function test_a_usd_amount_settles_as_the_platform_settled_it(): void {
		// Live: quantity 11000 USD at 51.01 answered amountSubtotal 561110.
		$this->assertSame( 561110, XPay_Fx::to_settlement( 11000, '51.01', 'USD', 'EGP' ) );
	}

	public function test_a_kwd_amount_accounts_for_the_extra_decimal(): void {
		// Live: quantity 110000 KWD at 165.47 answered amountSubtotal 1820170.
		// A naive minor-unit multiply gives 18201700 — ten times too much.
		$this->assertSame( 1820170, XPay_Fx::to_settlement( 110000, '165.47', 'KWD', 'EGP' ) );
	}

	public function test_a_bhd_amount_does_the_same(): void {
		// Live: quantity 110000 BHD at 135.27 answered amountSubtotal 1487970.
		$this->assertSame( 1487970, XPay_Fx::to_settlement( 110000, '135.27', 'BHD', 'EGP' ) );
	}

	/* ── Truncation, in both directions ──────────────────────────────── */

	/**
	 * 2501 x 51.01 = 127576.01. The platform keeps 127576 and drops the
	 * remainder; rounding half-up would agree here, so the next test is the
	 * one that actually separates them.
	 */
	public function test_a_remainder_is_dropped_not_rounded_down(): void {
		$this->assertSame( 127576, XPay_Fx::to_settlement( 2501, '51.01', 'USD', 'EGP' ) );
	}

	/**
	 * The case that separates truncation from rounding, which the one above
	 * does not: 2550 x 51.01 = 130,075.50 exactly. Half-up answers 130076.
	 * The platform answers 130075, and so must this.
	 */
	public function test_a_remainder_of_exactly_a_half_is_still_dropped(): void {
		$this->assertSame( 130075, XPay_Fx::to_settlement( 2550, '51.01', 'USD', 'EGP' ) );
	}

	public function test_the_inverse_truncates_too(): void {
		// 127576 EGP back at 51.01 is 2500.99..., which the platform reports
		// to the customer as 2500. This is the cent the merchant is told
		// about rather than protected from.
		$this->assertSame( 2500, XPay_Fx::to_presentment( 127576, '51.01', 'USD', 'EGP' ) );
	}

	public function test_an_exact_amount_survives_the_round_trip(): void {
		$settlement = XPay_Fx::to_settlement( 2500, '51.01', 'USD', 'EGP' );
		$this->assertSame( 127525, $settlement );
		$this->assertSame( 2500, XPay_Fx::to_presentment( $settlement, '51.01', 'USD', 'EGP' ) );
	}

	public function test_the_inverse_accounts_for_the_extra_decimal(): void {
		$this->assertSame( 110000, XPay_Fx::to_presentment( 1820170, '165.47', 'KWD', 'EGP' ) );
	}

	/* ── Same currency is not a conversion ───────────────────────────── */

	public function test_egp_to_egp_is_the_number_itself(): void {
		$this->assertSame( 24999, XPay_Fx::to_settlement( 24999, '1', 'EGP', 'EGP' ) );
		$this->assertSame( 24999, XPay_Fx::to_presentment( 24999, '1', 'EGP', 'EGP' ) );
	}

	/* ── A rate that cannot be trusted produces no number ────────────── */

	/**
	 * @dataProvider unusable_rates
	 * @param string $rate A rate the converter must refuse.
	 */
	public function test_an_unusable_rate_answers_nothing( string $rate ): void {
		$this->assertNull(
			XPay_Fx::to_settlement( 2500, $rate, 'USD', 'EGP' ),
			'A rate that cannot be parsed produced an amount of money anyway.'
		);
	}

	/** @return array<string,array{0:string}> */
	public function unusable_rates(): array {
		return array(
			'empty'      => array( '' ),
			'zero'       => array( '0' ),
			'zero point' => array( '0.00' ),
			'negative'   => array( '-51.01' ),
			'scientific' => array( '5.101e1' ),
			'words'      => array( 'about fifty' ),
			'comma'      => array( '51,01' ),
		);
	}

	/**
	 * Full precision is kept. A six-decimal rate rounded to two would move a
	 * different amount of money.
	 */
	public function test_a_long_rate_is_used_in_full(): void {
		$this->assertSame( 127527, XPay_Fx::to_settlement( 2500, '51.011', 'USD', 'EGP' ) );
	}

	/**
	 * An amount large enough to overflow answers nothing rather than a
	 * wrapped number: PHP turns an overflowing int into a float, and a float
	 * here would be a silently wrong amount of money.
	 */
	public function test_an_amount_too_large_to_multiply_answers_nothing(): void {
		$this->assertNull( XPay_Fx::to_settlement( PHP_INT_MAX, '51.01', 'USD', 'EGP' ) );
	}

	public function test_zero_is_zero(): void {
		$this->assertSame( 0, XPay_Fx::to_settlement( 0, '51.01', 'USD', 'EGP' ) );
	}
}
