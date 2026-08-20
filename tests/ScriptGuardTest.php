<?php
/**
 * Guards for XPay_Script_Guard.
 *
 * The bug these pin is not a crash. An unprotected payment script still
 * loads; it just loads late, after an optimizer has deferred it to first
 * interaction. The shopper sees a checkout that does nothing. Under
 * Elements that includes the SDK loader, so late means no payment form.
 *
 * The previous implementation named two handles in a list and stopped
 * covering the third the day one was added. These tests exist so the
 * namespace rule cannot quietly regress to a list again.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class ScriptGuardTest extends TestCase {

	private const TAG = "<script src='x.js' id='h-js'></script>\n";

	/** Every opt-out attribute an optimizer looks for. */
	private const ATTRS = array(
		'data-cfasync="false"', // Cloudflare Rocket Loader.
		'nowprocket',           // WP Rocket.
		'data-no-optimize="1"', // LiteSpeed.
		'data-noptimize="1"',   // Autoptimize.
		'data-no-defer="1"',    // Perfmatters and friends.
	);

	/** @return array<string, array{string}> */
	public function protected_handles(): array {
		return array(
			// The two the old list named.
			'checkout modal'   => array( 'xpay-checkout-modal' ),
			'blocks'           => array( 'xpay-blocks' ),
			// The one it silently stopped covering.
			'bnpl phone'       => array( 'xpay-checkout-bnpl-phone' ),
			// The one that matters most under Elements.
			'elements'         => array( 'xpay-elements' ),
			'sdk loader'       => array( 'xpay-sdk' ),
			// Anything else this plugin may register later.
			'not yet invented' => array( 'xpay-something-future' ),
		);
	}

	/**
	 * @dataProvider protected_handles
	 *
	 * @param string $handle Registered script handle.
	 */
	public function test_every_xpay_handle_is_hardened( string $handle ): void {
		$out = XPay_Script_Guard::harden_tag( self::TAG, $handle );
		foreach ( self::ATTRS as $attr ) {
			$this->assertStringContainsString( $attr, $out, "missing $attr on $handle" );
		}
	}

	/** @return array<string, array{string}> */
	public function foreign_handles(): array {
		return array(
			'jquery'            => array( 'jquery' ),
			'a theme script'    => array( 'storefront-navigation' ),
			'another gateway'   => array( 'stripe-checkout' ),
			// Near misses: the prefix must anchor at the start.
			'xpay in the middle' => array( 'woo-xpay-thing' ),
			'no separator'      => array( 'xpayment' ),
		);
	}

	/**
	 * @dataProvider foreign_handles
	 *
	 * @param string $handle A handle that is not ours.
	 */
	public function test_foreign_scripts_are_left_alone( string $handle ): void {
		$this->assertSame( self::TAG, XPay_Script_Guard::harden_tag( self::TAG, $handle ) );
	}

	/**
	 * Some setups run script_loader_tag twice. Stamping a second time would
	 * emit duplicate attributes into the markup.
	 */
	public function test_stamping_is_idempotent(): void {
		$once  = XPay_Script_Guard::harden_tag( self::TAG, 'xpay-elements' );
		$twice = XPay_Script_Guard::harden_tag( $once, 'xpay-elements' );
		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $twice, 'data-cfasync' ) );
	}

	/** A non-string tag from another filter must pass through untouched. */
	public function test_non_string_tag_passes_through(): void {
		$this->assertNull( XPay_Script_Guard::harden_tag( null, 'xpay-elements' ) );
	}
}
