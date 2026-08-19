<?php
/**
 * Pins XPay_Order_Sync's transition table. These rules are the plugin's
 * money truth: loosening any of them silently is how an order gets
 * marked paid twice, for the wrong amount, or cancelled over real money.
 *
 * @package XPay_For_WooCommerce
 */

class OrderSyncContractTest extends ContractTestCase {

	public function test_mark_paid_completes_payment_with_intent_id() {
		$order = $this->makeOrder( 14, array( 'total' => '290.00' ) );

		XPay_Order_Sync::mark_paid( $order, $this->paidSession(), 'webhook' );

		$this->assertTrue( $order->paid );
		$this->assertSame( 'processing', $order->status );
		$this->assertSame( 'pi_contract_1', $order->transaction_id );
		$this->assertSame( 'pi_contract_1', $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ) );
		$this->assertCount( 1, $order->notes );
		$this->assertStringContainsString( 'webhook', $order->notes[0] );
		$this->assertStageFired( 'order.paid' );
	}

	public function test_mark_paid_is_idempotent() {
		$order = $this->makeOrder( 14, array( 'total' => '290.00' ) );

		XPay_Order_Sync::mark_paid( $order, $this->paidSession(), 'webhook' );
		XPay_Order_Sync::mark_paid( $order, $this->paidSession(), 'thankyou' );

		$this->assertCount( 1, $order->notes, 'A duplicate delivery must not add a second confirmation note.' );
		$this->assertCount( 1, array_filter( $this->firedStages(), static function ( $s ) {
			return 'order.paid' === $s;
		} ) );
	}

	public function test_amount_mismatch_parks_on_hold_instead_of_completing() {
		$order = $this->makeOrder( 14, array( 'total' => '290.00' ) );

		XPay_Order_Sync::mark_paid( $order, $this->paidSession( array( 'amountTotal' => 12345 ) ), 'webhook' );

		$this->assertFalse( $order->paid, 'A drifted amount must never complete the order.' );
		$this->assertSame( 'on-hold', $order->status );
		$this->assertStageFired( 'order.amount_mismatch' );
		$this->assertStageNotFired( 'order.paid' );
		$this->assertSame( 'pi_contract_1', $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ), 'Identifiers are kept even when parked.' );
	}

	public function test_currency_mismatch_parks_on_hold() {
		$order = $this->makeOrder( 14, array( 'total' => '290.00' ) );

		XPay_Order_Sync::mark_paid( $order, $this->paidSession( array( 'currency' => 'USD' ) ), 'webhook' );

		$this->assertFalse( $order->paid );
		$this->assertSame( 'on-hold', $order->status );
	}

	public function test_missing_amount_fields_fail_open() {
		$order   = $this->makeOrder( 14, array( 'total' => '290.00' ) );
		$session = $this->paidSession();
		unset( $session['amountTotal'], $session['currency'] );

		XPay_Order_Sync::mark_paid( $order, $session, 'webhook' );

		$this->assertTrue( $order->paid, 'Shape gaps fail open; only present-but-different values may block.' );
	}

	public function test_presentment_details_win_over_top_level_amount() {
		$order   = $this->makeOrder( 14, array( 'total' => '290.00' ) );
		$session = $this->paidSession(
			array(
				'amountTotal'        => 999999,
				'presentmentDetails' => array(
					'amountTotal' => 29000,
					'currency'    => 'EGP',
				),
			)
		);

		XPay_Order_Sync::mark_paid( $order, $session, 'webhook' );

		$this->assertTrue( $order->paid, 'presentmentDetails is the shopper-currency mirror and takes precedence.' );
	}

	public function test_already_held_mismatch_saves_without_second_note() {
		$order         = $this->makeOrder( 14, array( 'total' => '290.00' ) );
		$order->status = 'on-hold';

		XPay_Order_Sync::mark_paid( $order, $this->paidSession( array( 'amountTotal' => 12345 ) ), 'webhook' );

		$this->assertCount( 0, $order->notes );
		$this->assertGreaterThan( 0, $order->saves, 'Identifiers written above must still be persisted.' );
	}

	public function test_mark_expired_cancels_only_unpaid_pending_orders() {
		$order = $this->makeOrder( 14 );

		XPay_Order_Sync::mark_expired( $order );

		$this->assertSame( 'cancelled', $order->status );
		$this->assertStageFired( 'order.session_expired' );
	}

	public function test_mark_expired_refuses_paid_and_terminal_orders() {
		$paid       = $this->makeOrder( 1, array( 'paid' => true, 'status' => 'processing' ) );
		$processing = $this->makeOrder( 2, array( 'status' => 'processing' ) );

		XPay_Order_Sync::mark_expired( $paid );
		XPay_Order_Sync::mark_expired( $processing );

		$this->assertSame( 'processing', $paid->status );
		$this->assertSame( 'processing', $processing->status );
	}

	public function test_mark_expired_refuses_orders_with_recorded_payment_intent() {
		$order = $this->makeOrder( 14, array( 'status' => 'on-hold' ) );
		$order->update_meta_data( XPay_Constants::META_PAYMENT_INTENT, 'pi_real_money' );

		XPay_Order_Sync::mark_expired( $order );

		$this->assertSame( 'on-hold', $order->status, 'Money moved for this order; only a human may resolve it.' );
	}

	public function test_customer_id_remembered_per_livemode_plane() {
		$order = $this->makeOrder( 14, array( 'total' => '290.00', 'user_id' => 7 ) );

		XPay_Order_Sync::mark_paid( $order, $this->paidSession( array( 'customer' => 'cus_ABC', 'livemode' => true ) ), 'webhook' );

		$this->assertSame( 'cus_ABC', $order->get_meta( XPay_Constants::META_CUSTOMER_ID ) );
		$this->assertSame( 'cus_ABC', get_user_meta( 7, XPay_Constants::customer_user_meta_key( true ), true ) );
		$this->assertSame( '', get_user_meta( 7, XPay_Constants::customer_user_meta_key( false ), true ), 'Test and live planes never share a key.' );
	}

	public function test_non_customer_ids_and_guests_write_no_user_meta() {
		$guest = $this->makeOrder( 14, array( 'total' => '290.00' ) );

		XPay_Order_Sync::mark_paid( $guest, $this->paidSession( array( 'customer' => 'cus_GUEST' ) ), 'webhook' );

		$this->assertSame( 'cus_GUEST', $guest->get_meta( XPay_Constants::META_CUSTOMER_ID ) );
		$this->assertSame( array(), $GLOBALS['xpay_test_user_meta'] );

		$bogus = $this->makeOrder( 15, array( 'total' => '290.00', 'user_id' => 7 ) );
		XPay_Order_Sync::mark_paid( $bogus, $this->paidSession( array( 'customer' => 'not_a_customer_id' ) ), 'webhook' );
		$this->assertSame( '', $bogus->get_meta( XPay_Constants::META_CUSTOMER_ID ) );
	}
}
