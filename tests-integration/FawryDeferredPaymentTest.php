<?php
/**
 * The full Fawry story against real WooCommerce.
 *
 * Pins the deferred-payments money bug: `checkout.session.completed` used
 * to route straight to mark_paid() with no paymentStatus gate, so a Fawry
 * session that completed `unpaid` at reference issuance marked the order
 * PAID before any money moved, and the merchant shipped goods for unpaid
 * references. The contract suite pins the same story against the shim;
 * this one asserts against WooCommerce's real status machinery, real
 * payment_complete() from on-hold, and the real unpaid-order sweep
 * decision, which the shim can only agree with.
 *
 * @package XPay_For_WooCommerce
 */

class FawryDeferredPaymentTest extends XPay_Integration_Test_Case {

	/** A pending order wired to a session, the way process_payment leaves it. */
	private function pending_fawry_order(): WC_Order {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_fawry_it' ) );
		$order->set_total( '290.00' );
		$order->set_status( 'pending' );
		$order->save();
		return $order;
	}

	/**
	 * @param string $payment_status Session paymentStatus wire value.
	 * @param array  $extra          Payload overrides.
	 */
	private function fawry_session( string $payment_status, array $extra = array() ): array {
		return array_merge(
			array(
				'id'             => 'cs_fawry_it',
				'status'         => XPay_Session_Status::COMPLETE,
				'paymentStatus'  => $payment_status,
				'amountSubtotal' => 29000,
				'amountTotal'    => 29000,
				'currency'       => 'EGP',
				'paymentIntent'  => array( 'id' => 'pi_fawry_it' ),
				'livemode'       => false,
			),
			$extra
		);
	}

	private function apply( string $type, string $event_id, array $payload ): void {
		$method = new ReflectionMethod( 'XPay_Webhook_Controller', 'apply_event' );
		$method->setAccessible( true );
		$method->invoke( null, $type, $event_id, $payload );
	}

	public function test_completed_unpaid_holds_the_order_and_the_sweep_leaves_it_alone(): void {
		$order = $this->pending_fawry_order();
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_fawry_it' );
		$order->save();

		$this->apply(
			XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
			'evt_it_f1',
			$this->fawry_session( XPay_Payment_Status::UNPAID, array( 'metadata' => array( 'wc_order_id' => (string) $order->get_id() ) ) )
		);

		$fresh = wc_get_order( $order->get_id() );
		$this->assertFalse( $fresh->is_paid(), 'A completed-but-unpaid session must never complete the order.' );
		$this->assertSame( 'on-hold', $fresh->get_status() );
		$this->assertNotSame( '', (string) $fresh->get_meta( XPay_Constants::META_AWAITING_PAYMENT ) );
		// The real sweep decision: on-hold is not `pending`, and the filter
		// must also leave a paid-nothing on-hold order alone.
		$this->assertFalse(
			XPay_Order_Sync::should_cancel_unpaid( true, $fresh ),
			'The unpaid-order protection must hold an awaiting order back from cancellation.'
		);
	}

	public function test_async_success_completes_the_held_order(): void {
		$order = $this->pending_fawry_order();
		$this->apply(
			XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
			'evt_it_f1',
			$this->fawry_session( XPay_Payment_Status::UNPAID, array( 'metadata' => array( 'wc_order_id' => (string) $order->get_id() ) ) )
		);

		$this->apply(
			XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED,
			'evt_it_f2',
			$this->fawry_session( XPay_Payment_Status::PAID, array( 'metadata' => array( 'wc_order_id' => (string) $order->get_id() ) ) )
		);

		$fresh = wc_get_order( $order->get_id() );
		$this->assertTrue( $fresh->is_paid() );
		$this->assertSame( 'pi_fawry_it', $fresh->get_transaction_id() );
		$this->assertSame( 'pi_fawry_it', (string) $fresh->get_meta( XPay_Constants::META_PAYMENT_INTENT ) );
	}

	public function test_async_failure_fails_the_held_order_with_the_reason(): void {
		$order = $this->pending_fawry_order();
		$this->apply(
			XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
			'evt_it_f1',
			$this->fawry_session( XPay_Payment_Status::UNPAID, array( 'metadata' => array( 'wc_order_id' => (string) $order->get_id() ) ) )
		);

		$this->apply(
			XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED,
			'evt_it_f2',
			$this->fawry_session(
				XPay_Payment_Status::UNPAID,
				array(
					'metadata'      => array( 'wc_order_id' => (string) $order->get_id() ),
					'paymentIntent' => array(
						'id'               => 'pi_fawry_it',
						'lastPaymentError' => array( 'merchantMessage' => 'The customer did not pay the reference before it expired' ),
					),
				)
			)
		);

		$fresh = wc_get_order( $order->get_id() );
		$this->assertFalse( $fresh->is_paid() );
		$this->assertSame( 'failed', $fresh->get_status() );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$texts = implode( ' ', wp_list_pluck( $notes, 'content' ) );
		$this->assertStringContainsString( 'did not pay the reference', $texts );
	}

	/**
	 * Delivery order is not guaranteed. Two events, two independent
	 * deliveries, two retry schedules: a `completed` that 404s because it
	 * outran Place Order is retried for days, so the reference can die and
	 * its failure arrive first. The late `completed` must not reopen the
	 * failed order as on-hold awaiting a confirmation that already came.
	 */
	public function test_a_late_completed_never_reopens_a_failed_order(): void {
		$order = $this->pending_fawry_order();

		$this->apply(
			XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED,
			'evt_it_f1',
			$this->fawry_session( XPay_Payment_Status::UNPAID, array( 'metadata' => array( 'wc_order_id' => (string) $order->get_id() ) ) )
		);
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );

		// The delayed (or redelivered) reference-issued event, under an
		// event id the dedupe has never seen.
		$this->apply(
			XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
			'evt_it_f2',
			$this->fawry_session( XPay_Payment_Status::UNPAID, array( 'metadata' => array( 'wc_order_id' => (string) $order->get_id() ) ) )
		);

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $fresh->get_status(), 'A dead reference must not be parked as awaiting payment.' );
		$this->assertSame(
			'',
			(string) $fresh->get_meta( XPay_Constants::META_AWAITING_PAYMENT ),
			'The awaiting marker would also block mark_expired() from ever closing this order.'
		);
	}

	/** The regression the gate must never reintroduce: paid still completes. */
	public function test_completed_paid_still_completes(): void {
		$order = $this->pending_fawry_order();

		$this->apply(
			XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
			'evt_it_f1',
			$this->fawry_session( XPay_Payment_Status::PAID, array( 'metadata' => array( 'wc_order_id' => (string) $order->get_id() ) ) )
		);

		$fresh = wc_get_order( $order->get_id() );
		$this->assertTrue( $fresh->is_paid() );
	}
}
