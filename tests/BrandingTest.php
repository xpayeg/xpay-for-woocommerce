<?php
/**
 * XPay_Branding tests.
 *
 * Pins the gradient math to the platform's hero formula
 * (packages/ui/src/lib/checkout-colors.ts: channel + (255 − channel) × 0.35,
 * rounded). Expected values below are hand-computed from that formula, never
 * re-derived through brighten() itself — an implementation that drifted from
 * the platform (e.g. the plausible-but-wrong channel × 1.35 reading) must
 * fail here, because the pay page stage and the XPay window derive their
 * gradients from the same merchant primary and must agree.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class BrandingTest extends TestCase {

	/** @return array<string, array{string, string}> */
	public function provide_normalize_cases(): array {
		return array(
			'six digit passes lowercased'    => array( '#4F46E5', '#4f46e5' ),
			'already lowercase'              => array( '#0f766e', '#0f766e' ),
			'short form expands'             => array( '#fff', '#ffffff' ),
			'short form with alpha expands'  => array( '#f00f', '#ff0000' ),
			'eight digit drops alpha'        => array( '#4f46e5cc', '#4f46e5' ),
			'fully transparent rejected'     => array( '#00000000', '' ),
			'short transparent rejected'     => array( '#0000', '' ),
			'missing hash rejected'          => array( '4f46e5', '' ),
			'named color rejected'           => array( 'red', '' ),
			'five digits rejected'           => array( '#12345', '' ),
			'non-hex characters rejected'    => array( '#gggggg', '' ),
			'css injection rejected'         => array( '#fff;background:url(x)', '' ),
			'empty rejected'                 => array( '', '' ),
		);
	}

	/** @dataProvider provide_normalize_cases */
	public function test_normalize_hex( string $input, string $expected ): void {
		$this->assertSame( $expected, XPay_Branding::normalize_hex( $input ) );
	}

	/** @return array<string, array{string, string}> */
	public function provide_brighten_cases(): array {
		return array(
			// 79→141 (0x8d), 70→135 (0x87), 229→238 (0xee).
			'platform primary' => array( '#4f46e5', '#8d87ee' ),
			// 15→99 (0x63), 118→166 (0xa6), 110→161 (0xa1).
			'sample teal'      => array( '#0f766e', '#63a6a1' ),
			// 0 + 255 × 0.35 = 89.25 → 89 (0x59) on every channel.
			'black lifts'      => array( '#000000', '#595959' ),
			'white saturates'  => array( '#ffffff', '#ffffff' ),
		);
	}

	/** @dataProvider provide_brighten_cases */
	public function test_brighten_matches_the_platform_formula( string $input, string $expected ): void {
		$this->assertSame( $expected, XPay_Branding::brighten( $input ) );
	}

	public function test_stage_uses_the_synced_primary(): void {
		$this->assertSame(
			array(
				'from' => '#0f766e',
				'to'   => '#63a6a1',
			),
			XPay_Branding::stage_from_primary( '#0f766e' )
		);
	}

	/** @return array<string, array{string}> */
	public function provide_fallback_primaries(): array {
		return array(
			'never synced'      => array( '' ),
			'tampered option'   => array( 'javascript:alert(1)' ),
			'transparent color' => array( '#00000000' ),
		);
	}

	/**
	 * The stored option is writable by any code on the site and lands in a
	 * style attribute — anything not a clean hex must fall back, never pass
	 * through.
	 *
	 * @dataProvider provide_fallback_primaries
	 */
	public function test_stage_falls_back_to_xpay_indigo( string $stored ): void {
		$this->assertSame(
			array(
				'from' => '#4f46e5',
				'to'   => '#8d87ee',
			),
			XPay_Branding::stage_from_primary( $stored )
		);
	}

	public function test_primary_extracts_from_a_session_response(): void {
		$session = array(
			'id'               => 'cs_123',
			'brandingSettings' => array(
				'colorMode' => 'system',
				'colors'    => array( 'primary' => '#0F766E' ),
			),
		);
		$this->assertSame( '#0f766e', XPay_Branding::primary_from_session( $session ) );
	}

	/** @return array<string, array{array}> */
	public function provide_unbranded_sessions(): array {
		return array(
			'no branding at all'    => array( array( 'id' => 'cs_123' ) ),
			'branding without colors' => array( array( 'brandingSettings' => array( 'colorMode' => 'dark' ) ) ),
			'colors without primary'  => array( array( 'brandingSettings' => array( 'colors' => array( 'background' => '#ffffff' ) ) ) ),
			'primary not a string'    => array( array( 'brandingSettings' => array( 'colors' => array( 'primary' => 42 ) ) ) ),
			'primary invalid'         => array( array( 'brandingSettings' => array( 'colors' => array( 'primary' => 'blue' ) ) ) ),
		);
	}

	/** @dataProvider provide_unbranded_sessions */
	public function test_unbranded_sessions_yield_empty( array $session ): void {
		$this->assertSame( '', XPay_Branding::primary_from_session( $session ) );
	}
}
