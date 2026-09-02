<?php
/**
 * A refund-family event whose order cannot be found.
 *
 * A refund's order is located by payment intent, and the
 * ORDER side of that join is written only by mark_paid() and
 * apply_superseded_paid(), so a dashboard refund taken seconds after a
 * payment can arrive while no order carries the intent yet.
 *
 * A non-2xx response asks XPay to retry after the payment has been applied.
 * This pins both halves against real WooCommerce: the
 * failure leaves nothing behind and
 * and a redelivery after mark_paid() mirrors the refund.
 *
 * @package XPay_For_WooCommerce
 */

class RefundEventNoOrderTest extends XPay_Integration_Test_Case {

	private function apply( string $type, array $payload, string $event_id ): void {
		$method = new ReflectionMethod( 'XPay_Webhook_Controller', 'apply_event' );
		$method->setAccessible( true );
		$method->invoke( null, $type, $event_id, $payload );
	}

	private function charge_refunded_payload(): array {
		return array(
			'id'              => 'ch_test_no_order',
			'paymentIntentId' => 'pi_test_race',
			'refunds'         => array(
				array(
					'id'       => 're_test_1',
					'status'   => XPay_Refund_Status::SUCCEEDED,
					'amount'   => 1000,
					'currency' => 'EGP',
				),
			),
		);
	}

	public function test_a_refund_event_with_no_order_fails_for_redelivery(): void {
		try {
			$this->apply( XPay_Event_Names::CHARGE_REFUNDED, $this->charge_refunded_payload(), 'evt_race_1' );
			$this->fail( 'Expected the order-not-found refusal to throw.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_ORDER_NOT_FOUND, $e->get_error_code() );
		}

		$this->assertFalse(
			function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'xpay_retry_webhook_event' ),
			'Nothing may queue locally; the platform retries.'
		);
	}

	/** The redelivery is what resolves the race, so it has to actually work. */
	public function test_a_redelivery_after_the_payment_applies_the_refund(): void {
		try {
			$this->apply( XPay_Event_Names::CHARGE_REFUNDED, $this->charge_refunded_payload(), 'evt_race_1' );
		} catch ( XPay_Api_Exception $e ) {
			unset( $e ); // The first delivery failing is the premise, pinned above.
		}

		// The payment lands: the order now carries the intent.
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_test_race' ) );
		$order->set_total( '290.00' );
		$order->save();
		XPay_Order_Sync::mark_paid(
			$order,
			array(
				'id'             => 'cs_test_race',
				'status'         => XPay_Session_Status::COMPLETE,
				'paymentStatus'  => XPay_Payment_Status::PAID,
				'amountSubtotal' => 29000,
				'currency'       => 'EGP',
				'paymentIntent'  => array( 'id' => 'pi_test_race' ),
			),
			'webhook'
		);

		// XPay redelivers the same event under a fresh delivery id.
		$this->apply( XPay_Event_Names::CHARGE_REFUNDED, $this->charge_refunded_payload(), 'evt_race_2' );

		$fresh   = wc_get_order( $order->get_id() );
		$refunds = $fresh->get_refunds();
		$this->assertCount( 1, $refunds, 'The redelivery must mirror the dashboard refund.' );
		$this->assertSame( '10.00', wc_format_decimal( $refunds[0]->get_amount(), 2 ) );
	}
}
