<?php
/**
 * A payment on a session the order had already left behind stays parked.
 *
 * apply_superseded_paid() moves the order to on-hold and asks a human to
 * decide. on-hold is in WooCommerce's PAYMENT_COMPLETE_STATUSES and is not
 * is_paid(), so anything calling mark_paid() afterwards completes the order
 * and undoes the park. The reachable route is the shopper then paying the
 * CURRENT session: verify_on_thankyou() finds it genuinely paid and marks
 * the order complete, shipping an order that carries two payments with the
 * review note buried under it.
 *
 * Exactly the defect that dad1a3c fixed for a cancelled order, one branch
 * over. That park got a durable marker; this one did not.
 *
 * @package XPay_For_WooCommerce
 */

class SupersededParkTest extends XPay_Integration_Test_Case {

	private function session( string $id ): array {
		return array(
			'id'            => $id,
			'status'        => 'complete',
			'paymentStatus' => 'paid',
			'currency'      => 'EGP',
			'amountTotal'   => 12300,
			'livemode'      => false,
			'paymentIntent' => array( 'id' => 'pi_test_' . $id ),
		);
	}

	private function order_on_its_second_session(): WC_Order {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_current' ) );
		$order->set_total( '123.00' );
		$order->set_status( 'pending' );
		$order->save();
		return $order;
	}

	/** The park itself. */
	public function test_money_on_an_old_session_parks_the_order(): void {
		$order = $this->order_on_its_second_session();

		XPay_Order_Sync::apply_superseded_paid( $order, $this->session( 'cs_superseded' ) );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertTrue( $fresh->has_status( 'on-hold' ) );
		$this->assertNotSame( '', (string) $fresh->get_meta( XPay_Constants::META_SUPERSEDED_PARKED ) );
	}

	/**
	 * And the shopper paying the current session afterwards does not ship it.
	 *
	 * This is the second call the park has to survive. Without the marker
	 * the order is on-hold and unpaid, which is precisely the state
	 * payment_complete() accepts.
	 */
	public function test_paying_the_current_session_does_not_unpark_it(): void {
		$order = $this->order_on_its_second_session();
		XPay_Order_Sync::apply_superseded_paid( $order, $this->session( 'cs_superseded' ) );

		XPay_Order_Sync::mark_paid(
			wc_get_order( $order->get_id() ),
			$this->session( 'cs_current' ),
			'thankyou'
		);

		$fresh = wc_get_order( $order->get_id() );
		$this->assertTrue(
			$fresh->has_status( 'on-hold' ),
			'The park was undone: an order with two payments on it was completed and the customer told it was on its way.'
		);
		$this->assertFalse( $fresh->is_paid() );
	}
}
