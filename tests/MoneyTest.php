<?php
/**
 * Money-truth guards for XPay_Money.
 *
 * Pinned because float money math has a canonical failure the string
 * implementation must never regress into: (float) '1.005' * 100 is
 * 100.49999…, which rounds to 100 — silently losing a piaster. The v3
 * monorepo lint-bans hardcoded 2-decimal assumptions for the sibling
 * reason: KWD/JOD/OMR/BHD/LYD carry 3 decimals and a 2-decimal shortcut
 * corrupts every amount in those currencies.
 *
 * Expected values are hand-computed literals — never recomputed with the
 * implementation's own formula (the failure-catalogue Class H rule).
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase {

	/** @return array<string, array{string, string, int}> */
	public function to_minor_cases(): array {
		return array(
			'EGP simple'                    => array( '149.50', 'EGP', 14950 ),
			'EGP integer string'            => array( '10', 'EGP', 1000 ),
			'EGP the float trap 1.005'      => array( '1.005', 'EGP', 101 ),
			'EGP round half-up carries'     => array( '99.999', 'EGP', 10000 ),
			'EGP truncates beyond rounder'  => array( '5.0049', 'EGP', 500 ),
			'EGP zero'                      => array( '0', 'EGP', 0 ),
			'EGP bare fraction'             => array( '.5', 'EGP', 50 ),
			'KWD three decimals'            => array( '1.2345', 'KWD', 1235 ),
			'KWD integer'                   => array( '7', 'KWD', 7000 ),
			'Unknown currency defaults to 2' => array( '3.14', 'XXX', 314 ),
			'Negative amount'               => array( '-2.50', 'EGP', -250 ),
		);
	}

	/** @dataProvider to_minor_cases */
	public function test_to_minor_is_exact( string $amount, string $currency, int $expected ): void {
		$this->assertSame( $expected, XPay_Money::to_minor( $amount, $currency ) );
	}

	/** @return array<string, array{int, string, string}> */
	public function from_minor_cases(): array {
		return array(
			'EGP simple'      => array( 14950, 'EGP', '149.50' ),
			'EGP sub-pound'   => array( 5, 'EGP', '0.05' ),
			'KWD 3 decimals'  => array( 1235, 'KWD', '1.235' ),
			'Negative'        => array( -250, 'EGP', '-2.50' ),
			'Zero'            => array( 0, 'EGP', '0.00' ),
		);
	}

	/** @dataProvider from_minor_cases */
	public function test_from_minor_formats_exactly( int $minor, string $currency, string $expected ): void {
		$this->assertSame( $expected, XPay_Money::from_minor( $minor, $currency ) );
	}

	public function test_round_trip_is_lossless_for_every_supported_currency(): void {
		// Sweep the full currency registry (data-provider-over-enum rule):
		// a newly added currency inherits this guard automatically.
		foreach ( array_keys( XPay_Money::DECIMALS ) as $currency ) {
			$minor = 1234567;
			$this->assertSame(
				$minor,
				XPay_Money::to_minor( XPay_Money::from_minor( $minor, $currency ), $currency ),
				"Round trip lost money for {$currency}"
			);
		}
	}

	/** @return array<string, array{string}> */
	public function invalid_amounts(): array {
		return array(
			'empty string'   => array( '' ),
			'letters'        => array( 'abc' ),
			'thousands sep'  => array( '1,000.50' ),
			'double dot'     => array( '1.2.3' ),
			'lone minus'     => array( '-' ),
			'scientific'     => array( '1e3' ),
		);
	}

	/** @dataProvider invalid_amounts */
	public function test_rejects_non_decimal_input_loudly( string $amount ): void {
		// Throwing beats silently charging 0 — a malformed total must never
		// reach the API as a real session amount.
		$this->expectException( InvalidArgumentException::class );
		XPay_Money::to_minor( $amount, 'EGP' );
	}
}
