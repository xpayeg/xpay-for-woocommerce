<?php
/**
 * When money first completes on a plane, and which plane it belongs to.
 *
 * The setup screen needs to know whether THIS plane has taken a payment,
 * and nothing on an order can answer that: no meta records the mode, and
 * the refund path just uses whichever key is configured now. So the answer
 * is stamped per plane when the payment lands, from the session's own
 * livemode rather than from whatever the settings say at that moment.
 *
 * @package XPay_For_WooCommerce
 */

class FirstPaidStampTest extends XPay_Integration_Test_Case {

	private function paid_session( WC_Order $order, ?bool $livemode ): array {
		$session = array(
			'id'            => 'cs_test_stamp',
			'status'        => 'complete',
			'paymentStatus' => 'paid',
			'currency'      => 'EGP',
			'amountTotal'   => XPay_Money::to_minor( $order->get_total(), 'EGP' ),
			'paymentIntent' => array( 'id' => 'pi_test_stamp' ),
		);
		if ( null !== $livemode ) {
			$session['livemode'] = $livemode;
		}
		return $session;
	}

	private function paid_order(): WC_Order {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_test_stamp' ) );
		$order->set_total( '123.00' );
		$order->save();
		return $order;
	}

	/** A test payment stamps the test plane, and only that one. */
	public function test_a_test_payment_stamps_the_test_plane(): void {
		$order = $this->paid_order();

		XPay_Order_Sync::mark_paid( $order, $this->paid_session( $order, false ), 'webhook' );

		$this->assertGreaterThan( 0, (int) get_option( XPay_Constants::first_paid_option( false ), 0 ) );
		$this->assertSame( 0, (int) get_option( XPay_Constants::first_paid_option( true ), 0 ), 'The live plane was stamped by a test payment.' );
	}

	/** And a live payment stamps the live one. */
	public function test_a_live_payment_stamps_the_live_plane(): void {
		$order = $this->paid_order();

		XPay_Order_Sync::mark_paid( $order, $this->paid_session( $order, true ), 'webhook' );

		$this->assertGreaterThan( 0, (int) get_option( XPay_Constants::first_paid_option( true ), 0 ) );
		$this->assertSame( 0, (int) get_option( XPay_Constants::first_paid_option( false ), 0 ), 'The test plane was stamped by a live payment.' );
	}

	/**
	 * The FIRST payment, not the latest.
	 *
	 * add_option rather than update_option: the screen reads this as "has
	 * this plane ever been paid", and a later order moving the timestamp
	 * would quietly turn it into "when did it last take one".
	 *
	 * The stamp is wound back to a date no payment in this test could have
	 * written before the second one lands. Comparing the two time() values
	 * as they fall is no test at all: both payments happen inside the same
	 * second, so update_option and add_option write the identical number
	 * and the comparison holds either way.
	 */
	public function test_a_second_payment_does_not_move_the_stamp(): void {
		$first = $this->paid_order();
		XPay_Order_Sync::mark_paid( $first, $this->paid_session( $first, false ), 'webhook' );
		$this->assertGreaterThan( 0, (int) get_option( XPay_Constants::first_paid_option( false ), 0 ), 'The first payment left no stamp to defend.' );

		$long_ago = time() - ( 30 * DAY_IN_SECONDS );
		update_option( XPay_Constants::first_paid_option( false ), $long_ago, false );

		$second = $this->paid_order();
		XPay_Order_Sync::mark_paid( $second, $this->paid_session( $second, false ), 'webhook' );

		$this->assertSame(
			$long_ago,
			(int) get_option( XPay_Constants::first_paid_option( false ), 0 ),
			'A later payment moved the stamp, so the screen now answers "when did this plane last take one".'
		);
	}

	/** A session that says nothing about its plane stamps neither. */
	public function test_a_session_without_livemode_stamps_nothing(): void {
		$order = $this->paid_order();

		XPay_Order_Sync::mark_paid( $order, $this->paid_session( $order, null ), 'webhook' );

		$this->assertSame( 0, (int) get_option( XPay_Constants::first_paid_option( false ), 0 ) );
		$this->assertSame( 0, (int) get_option( XPay_Constants::first_paid_option( true ), 0 ) );
	}
}
