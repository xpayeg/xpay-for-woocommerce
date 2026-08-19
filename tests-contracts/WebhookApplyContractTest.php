<?php
/**
 * Pins the webhook controller's routing contract: ownership (IDOR),
 * dedupe, lock behavior, and forward compatibility. The claimable set
 * IS the contract — a new code path must not loosen any of these.
 *
 * @package XPay_For_WooCommerce
 */

class WebhookApplyContractTest extends ContractTestCase {

	/** An order wired the way process_payment leaves it. */
	private function wiredOrder( int $id = 14 ): WC_Order {
		$order = $this->makeOrder( $id, array( 'total' => '290.00' ) );
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_contract' );
		return $order;
	}

	public function test_completed_event_marks_paid_and_records_event_id() {
		$order = $this->wiredOrder();

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );

		$this->assertTrue( $order->paid );
		$this->assertContains( 'evt_1', $order->get_meta( XPay_Constants::META_PROCESSED_EVENTS ) );
	}

	public function test_duplicate_event_id_is_ignored() {
		$order = $this->wiredOrder();

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );
		$notes = count( $order->notes );
		$order->paid = false; // Even if state regressed, the dedupe alone must block a replay.

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );

		$this->assertFalse( $order->paid, 'A replayed event id must be a no-op.' );
		$this->assertCount( $notes, $order->notes );
	}

	public function test_ownership_mismatch_throws_and_applies_nothing() {
		$order = $this->wiredOrder();
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_DIFFERENT' );

		try {
			$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );
			$this->fail( 'Expected the IDOR guard to throw.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::ORDER_MISMATCH, $e->get_error_code() );
		}

		$this->assertFalse( $order->paid );
		$this->assertSame( '', $order->get_meta( XPay_Constants::META_PROCESSED_EVENTS ) );
	}

	public function test_order_without_stored_session_id_fails_ownership() {
		$this->makeOrder( 14, array( 'total' => '290.00' ) ); // No META_SESSION_ID at all.

		$this->expectException( XPay_Api_Exception::class );
		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );
	}

	public function test_unknown_event_types_are_acknowledged_untouched() {
		$order = $this->wiredOrder();

		$this->applyEvent( 'payment_intent.arrived_from_the_future', 'evt_1', $this->paidSession() );

		$this->assertFalse( $order->paid, 'Unsubscribed types are forward-compatibility no-ops.' );
	}

	public function test_missing_or_foreign_order_is_acknowledged_and_logged() {
		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );
		$this->assertStageFired( 'webhook.order_not_found' );

		$foreign = $this->makeOrder( 14, array( 'payment_method' => 'cod' ) );
		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_2', $this->paidSession() );
		$this->assertFalse( $foreign->paid, 'An order paid by another gateway is never ours to touch.' );
	}

	public function test_busy_lock_throws_so_xpay_retries() {
		$this->wiredOrder();
		$GLOBALS['wpdb']->lock_results = array( '0' );

		try {
			$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );
			$this->fail( 'A busy lock must surface as an error (500 to the retry engine).' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertNotSame( XPay_Error_Codes::ORDER_MISMATCH, $e->get_error_code() );
		}
	}

	public function test_errored_lock_proceeds_unserialized_and_logs() {
		$order = $this->wiredOrder();
		$GLOBALS['wpdb']->lock_results = array( null );

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );

		$this->assertTrue( $order->paid, 'A host without GET_LOCK must degrade, not dead-end payment confirmation.' );
		$this->assertStageFired( 'order_lock.unavailable' );
	}

	public function test_expired_event_cancels_pending_order() {
		$order = $this->wiredOrder();

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_EXPIRED, 'evt_1', $this->paidSession() );

		$this->assertSame( 'cancelled', $order->status );
	}

	public function test_processed_event_list_is_capped() {
		$order = $this->wiredOrder();

		$total = XPay_Webhook_Controller::PROCESSED_EVENTS_KEPT + 3;
		for ( $i = 1; $i <= $total; $i++ ) {
			$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_' . $i, $this->paidSession() );
		}

		$processed = $order->get_meta( XPay_Constants::META_PROCESSED_EVENTS );
		$this->assertCount( XPay_Webhook_Controller::PROCESSED_EVENTS_KEPT, $processed );
		$this->assertContains( 'evt_' . $total, $processed, 'Newest ids survive the cap.' );
		$this->assertNotContains( 'evt_1', $processed, 'Oldest ids age out.' );
	}
}
