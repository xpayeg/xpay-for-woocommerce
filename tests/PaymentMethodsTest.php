<?php
/**
 * XPay_Payment_Methods registry tests.
 *
 * Payment-method registry tests.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class PaymentMethodsTest extends TestCase {


	public function test_card_networks_exclude_amex(): void {
		$this->assertSame( array( 'visa', 'mastercard', 'meeza' ), XPay_Payment_Methods::CARD_NETWORKS );
		$this->assertNotContains( 'amex', XPay_Payment_Methods::CARD_NETWORKS );
	}


}
