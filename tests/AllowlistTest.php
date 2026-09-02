<?php
/**
 * URL allowlist tests.
 *
 * Pins browser-vs-PHP parser differential rejections. The allowlist is the
 * sole control on API-supplied redirect URLs, so ambiguous shapes must fail
 * closed. Runs against a wp_parse_url()
 * shim in bootstrap.php that wraps parse_url(), which is exactly what the
 * WordPress function does on our PHP floor.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class AllowlistTest extends TestCase {

	/** @return array<string, array{string}> */
	public function allowed_urls(): array {
		return array(
			'hosted checkout'   => array( 'https://checkout.xpay.app/c/cs_test_a1' ),
			'api host'          => array( 'https://api.xpay.app/checkout/sessions/cs_1' ),
			'apex'              => array( 'https://xpay.app/' ),
			'deep subdomain'    => array( 'https://staging.checkout.xpay.app/c/cs_1' ),
		);
	}

	/** @dataProvider allowed_urls */
	public function test_xpay_https_urls_pass( string $url ): void {
		$this->assertTrue( XPay_Constants::is_allowed_xpay_url( $url ) );
	}

	/** @return array<string, array{string}> */
	public function rejected_urls(): array {
		return array(
			'backslash authority trick' => array( 'https://evil.com\\@xpay.app/c/cs_1' ),
			'userinfo authority trick'  => array( 'https://evil.com@xpay.app/c/cs_1' ),
			'lookalike suffix domain'   => array( 'https://xpay.app.evil.com/' ),
			'prefix lookalike'          => array( 'https://notxpay.app/' ),
			'http downgrade'            => array( 'http://checkout.xpay.app/c/cs_1' ),
			'scheme-relative'           => array( '//checkout.xpay.app/c/cs_1' ),
			'javascript scheme'         => array( 'javascript:alert(1)' ),
			'empty'                     => array( '' ),
			'localhost without dev override' => array( 'http://localhost/c/cs_1' ),
		);
	}

	/** @dataProvider rejected_urls */
	public function test_hostile_and_downgraded_urls_fail_closed( string $url ): void {
		$this->assertFalse( XPay_Constants::is_allowed_xpay_url( $url ) );
	}
}
