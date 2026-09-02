<?php
/**
 * A payment that lands after the order was cancelled must not ship it.
 *
 * WooCommerce's payment_complete() accepts a cancelled order — core lists
 * it in OrderStatus::PAYMENT_COMPLETE_STATUSES beside pending and failed —
 * so an unguarded call flips a closed order to processing, emails the
 * shopper that it is on its way, and hands it to fulfilment with nobody
 * having decided that. Orders reach cancelled routinely: core's
 * wc_cancel_unpaid_orders() sweeps unpaid ones on the stock-hold timer.
 *
 * Refusing outright would be worse — real money with nothing recording it —
 * so the payment is written down and the order is parked for a human.
 *
 * Stripe answers the same question by REFUNDING
 * (class-wc-stripe-webhook-handler.php:1921, shipped 10.9.0). That fits
 * their case, a shopper who cancelled their own order while the payment
 * settled. Ours is a shopper who paid slowly and is owed goods.
 *
 * @package XPay_For_WooCommerce
 */

class PaidAfterCancelTest extends XPay_Integration_Test_Case {

	/** A paid session for an order, as the webhook delivers it. */
	private function paid_session( WC_Order $order ): array {
		return array(
			'id'            => 'cs_test_late',
			'status'        => 'complete',
			'paymentStatus' => 'paid',
			'currency'      => 'EGP',
			'amountTotal'   => XPay_Money::to_minor( $order->get_total(), 'EGP' ),
			'paymentIntent' => array( 'id' => 'pi_test_late' ),
		);
	}

	private function cancelled_order(): WC_Order {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_test_late' ) );
		$order->set_total( '123.00' );
		$order->set_status( 'cancelled' );
		$order->save();
		return $order;
	}

	/* ── The defect ──────────────────────────────────────────────────── */

	public function test_a_cancelled_order_is_not_shipped_by_a_late_payment(): void {
		$order = $this->cancelled_order();

		XPay_Order_Sync::mark_paid( $order, $this->paid_session( $order ), 'webhook' );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertFalse(
			$fresh->is_paid(),
			'payment_complete() accepts a cancelled order, so an unguarded call resurrects it to processing and emails the shopper.'
		);
		$this->assertTrue( $fresh->has_status( 'on-hold' ), 'The order was left cancelled with money against it.' );
	}

	public function test_the_payment_is_still_recorded(): void {
		$order = $this->cancelled_order();

		XPay_Order_Sync::mark_paid( $order, $this->paid_session( $order ), 'webhook' );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame(
			'pi_test_late',
			(string) $fresh->get_transaction_id(),
			'Refusing without recording the payment leaves real money with nothing pointing at it.'
		);
		$this->assertSame( 'pi_test_late', (string) $fresh->get_meta( XPay_Constants::META_PAYMENT_INTENT ) );
	}

	public function test_the_merchant_is_told_what_happened(): void {
		$order = $this->cancelled_order();

		XPay_Order_Sync::mark_paid( $order, $this->paid_session( $order ), 'webhook' );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$found = false;
		foreach ( $notes as $note ) {
			if ( false !== strpos( $note->content, 'after it had already been cancelled' ) ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'An order on hold with no explanation is a mystery, not a decision.' );
	}

	/**
	 * The guard must survive its own effect.
	 *
	 * Parking moves the order to on-hold, which erases the `cancelled` state
	 * the guard tested, and on-hold is itself in
	 * OrderStatus::PAYMENT_COMPLETE_STATUSES.
	 *
	 * Two ordinary routes deliver twice: the webhook followed by the
	 * thank-you re-check, and a redelivery under a fresh event id, which
	 * the id-keyed dedupe does not catch.
	 */
	public function test_a_second_delivery_does_not_ship_it_after_all(): void {
		$order   = $this->cancelled_order();
		$session = $this->paid_session( $order );

		XPay_Order_Sync::mark_paid( $order, $session, 'webhook' );
		$after_first = wc_get_order( $order->get_id() );
		$this->assertTrue( $after_first->has_status( 'on-hold' ) );

		XPay_Order_Sync::mark_paid( $after_first, $session, 'thankyou' );

		$after_second = wc_get_order( $order->get_id() );
		$this->assertFalse(
			$after_second->is_paid(),
			'The second delivery completed an order that was cancelled, because parking it erased the status the guard tested.'
		);
		$this->assertTrue( $after_second->has_status( 'on-hold' ) );
	}

	/** Three deliveries is not different in kind from two. */
	public function test_it_holds_across_repeated_deliveries(): void {
		$order   = $this->cancelled_order();
		$session = $this->paid_session( $order );

		for ( $i = 0; $i < 3; $i++ ) {
			XPay_Order_Sync::mark_paid( wc_get_order( $order->get_id() ), $session, 'webhook' );
		}

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	/* ── No regression on the ordinary path ──────────────────────────── */

	public function test_a_pending_order_still_completes(): void {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_test_late' ) );
		$order->set_total( '123.00' );
		$order->set_status( 'pending' );
		$order->save();

		XPay_Order_Sync::mark_paid( $order, $this->paid_session( $order ), 'webhook' );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_a_failed_order_still_completes(): void {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_test_late' ) );
		$order->set_total( '123.00' );
		$order->set_status( 'failed' );
		$order->save();

		XPay_Order_Sync::mark_paid( $order, $this->paid_session( $order ), 'webhook' );

		$this->assertTrue(
			wc_get_order( $order->get_id() )->is_paid(),
			'A failed order is a shopper retrying, not a closed one. It must still complete.'
		);
	}
}
