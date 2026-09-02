<?php
/**
 * The gateway's availability follows the ACCOUNT's currencies, not a table.
 *
 * The list is cached from GET /account at key save. A store currency the
 * account cannot charge hides the gateway (the honest state: showing it
 * dead-ends every shopper after Place Order). The hardcoded platform enum
 * survives only as the fallback for a store whose keys were written around
 * the save path (REST settings route), where no account was ever fetched.
 *
 * @package XPay_For_WooCommerce
 */

class CurrencyGateTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		$this->configure_gateway(
			array(
				'enabled'              => 'yes',
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_gate',
				'test_publishable_key' => 'pk_test_gate',
			)
		);
	}

	public function tear_down(): void {
		// Both planes, so a failed assertion cannot leak one cache into the
		// next test: the per-plane test writes the live one.
		delete_option( XPay_Constants::account_methods_option( false ) );
		delete_option( XPay_Constants::account_methods_option( true ) );
		update_option( 'woocommerce_currency', 'EGP' );
		parent::tear_down();
	}

	private function available(): bool {
		return ( new XPay_Gateway() )->is_available();
	}

	public function test_a_cached_currency_shows_the_gateway(): void {
		update_option( XPay_Constants::account_methods_option( false ), array( 'EGP' => array( 'card' ), 'USD' => array( 'card' ) ) );
		update_option( 'woocommerce_currency', 'USD' );

		$this->assertTrue( $this->available() );
	}

	public function test_a_currency_the_account_lacks_hides_the_gateway(): void {
		update_option( XPay_Constants::account_methods_option( false ), array( 'EGP' => array( 'card' ) ) );
		update_option( 'woocommerce_currency', 'USD' );

		$this->assertFalse(
			$this->available(),
			'USD is in the platform enum but not on this account; showing the row dead-ends every shopper.'
		);
	}

	public function test_no_cache_falls_back_to_the_platform_enum(): void {
		delete_option( XPay_Constants::account_methods_option( false ) );
		update_option( 'woocommerce_currency', 'USD' );

		$this->assertTrue( $this->available(), 'Keys written around the save path must not hide the gateway.' );
	}

	public function test_the_fallback_still_refuses_what_the_platform_cannot_charge(): void {
		delete_option( XPay_Constants::account_methods_option( false ) );
		update_option( 'woocommerce_currency', 'JPY' );

		$this->assertFalse( $this->available() );
	}

	public function test_the_cache_is_per_plane(): void {
		// Live cache says USD; the gateway is in TEST mode, whose cache
		// says EGP only. The test plane's answer must win.
		update_option( XPay_Constants::account_methods_option( true ), array( 'USD' => array( 'card' ) ) );
		update_option( XPay_Constants::account_methods_option( false ), array( 'EGP' => array( 'card' ) ) );
		update_option( 'woocommerce_currency', 'USD' );

		$this->assertFalse( $this->available() );
	}
}
