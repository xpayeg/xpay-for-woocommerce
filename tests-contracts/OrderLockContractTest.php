<?php
/**
 * Pins XPayEG_Order_Lock's semantics: grant/busy/errored answers and the
 * acquire/release naming pairing. NULL-degrades-open is the contract
 * that keeps payment confirmation alive on hosts without GET_LOCK.
 *
 * @package XPayEG_For_WooCommerce
 */

class OrderLockContractTest extends ContractTestCase {

	public function test_granted_busy_and_errored_answers() {
		$GLOBALS['wpdb']->lock_results = array( '1', '0', null );

		$this->assertTrue( XPayEG_Order_Lock::acquire( 14, 5 ) );
		$this->assertFalse( XPayEG_Order_Lock::acquire( 14, 0 ) );
		$this->assertTrue( XPayEG_Order_Lock::acquire( 14, 5 ), 'An errored GET_LOCK degrades open, never dead-ends confirmation.' );
		$this->assertStageFired( 'order_lock.unavailable' );
	}

	public function test_release_pairs_with_the_same_namespaced_lock() {
		XPayEG_Order_Lock::acquire( 14, 5 );
		XPayEG_Order_Lock::release( 14 );

		$statements = $GLOBALS['wpdb']->statements;
		$this->assertStringContainsString( "GET_LOCK('xpayeg_order_14'", $statements[0] );
		$this->assertStringContainsString( "RELEASE_LOCK('xpayeg_order_14'", $statements[1] );
	}
}
