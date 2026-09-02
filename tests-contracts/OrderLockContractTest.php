<?php
/**
 * Pins XPay_Order_Lock's semantics: grant/busy/errored answers and the
 * acquire/release naming pairing. NULL-degrades-open is the contract
 * that keeps payment confirmation alive on hosts without GET_LOCK.
 *
 * @package XPay_For_WooCommerce
 */

class OrderLockContractTest extends ContractTestCase {

	public function test_granted_busy_and_errored_answers() {
		$GLOBALS['wpdb']->lock_results = array( '1', '0', null );

		$this->assertTrue( XPay_Order_Lock::acquire( 14, 5 ) );
		$this->assertFalse( XPay_Order_Lock::acquire( 14, 0 ) );
		$this->assertTrue( XPay_Order_Lock::acquire( 14, 5 ), 'An errored GET_LOCK degrades open, never dead-ends confirmation.' );
		$this->assertStageFired( 'order_lock.unavailable' );
	}

	public function test_release_pairs_with_the_same_namespaced_lock() {
		XPay_Order_Lock::acquire( 14, 5 );
		XPay_Order_Lock::release( 14 );

		$statements = $GLOBALS['wpdb']->statements;
		$this->assertStringContainsString( "GET_LOCK('xpay_order_14'", $statements[0] );
		$this->assertStringContainsString( "RELEASE_LOCK('xpay_order_14'", $statements[1] );
	}
}
