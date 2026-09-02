<?php
/**
 * An abandoned checkout is not a lost payment.
 *
 * The session is created when the shopper picks XPay, which is before the
 * order exists — the order is written at Place Order. So a shopper who
 * reaches the payment fields and leaves produces a session that expires
 * unpaid with no order behind it. That is the ordinary outcome of a
 * checkout: it is acknowledged quietly (200), because failing it would
 * make XPay redeliver an event that can never apply. Everything else with
 * no order FAILS the delivery so XPay's engine redelivers, and raises
 * `webhook.order_not_found` at ERROR — the alarm for money that cannot be
 * located, which must fire only when money might actually be involved.
 *
 * @package XPay_For_WooCommerce
 */

class AbandonedCartNoiseTest extends XPay_Integration_Test_Case {

	/** Every log line this test provoked: array of [stage, level]. */
	private array $logged = array();

	public function set_up(): void {
		parent::set_up();
		$this->logged = array();
		add_action(
			'xpay_logger_event',
			function ( $stage, $context, $message, $level ) {
				unset( $context, $message );
				$this->logged[] = array( (string) $stage, (string) $level );
			},
			10,
			4
		);
	}

	private function apply( string $type, array $payload ): void {
		$method = new ReflectionMethod( 'XPay_Webhook_Controller', 'apply_event' );
		$method->setAccessible( true );
		$method->invoke( null, $type, 'evt_test', $payload );
	}

	/** Stages logged at a given level. */
	private function stages_at( string $level ): array {
		$stages = array();
		foreach ( $this->logged as $line ) {
			if ( $line[1] === $level ) {
				$stages[] = $line[0];
			}
		}
		return $stages;
	}

	private function expired_session( string $payment_status = XPay_Payment_Status::UNPAID ): array {
		return array(
			'id'            => 'cs_test_abandoned',
			'status'        => XPay_Session_Status::EXPIRED,
			'paymentStatus' => $payment_status,
		);
	}

	public function test_an_abandoned_cart_is_acknowledged_and_not_an_error(): void {
		$this->apply( XPay_Event_Names::CHECKOUT_SESSION_EXPIRED, $this->expired_session() );

		$this->assertNotContains(
			'webhook.order_not_found',
			$this->stages_at( XPay_Logger::LEVEL_ERROR ),
			'The lost-payment alarm fired on a healthy abandoned cart.'
		);
		$this->assertContains( 'webhook.abandoned_cart_expired', array_column( $this->logged, 0 ) );
	}

	public function test_a_completed_session_with_no_order_is_an_error_and_fails(): void {
		try {
			$this->apply(
				XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
				array(
					'id'            => 'cs_test_abandoned',
					'status'        => XPay_Session_Status::COMPLETE,
					'paymentStatus' => XPay_Payment_Status::PAID,
				)
			);
			$this->fail( 'Money with no order must fail the delivery, never be acknowledged.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_ORDER_NOT_FOUND, $e->get_error_code() );
		}
		$this->assertContains( 'webhook.order_not_found', $this->stages_at( XPay_Logger::LEVEL_ERROR ) );
	}

	public function test_a_paid_expiry_with_no_order_is_never_the_quiet_path(): void {
		try {
			$this->apply( XPay_Event_Names::CHECKOUT_SESSION_EXPIRED, $this->expired_session( XPay_Payment_Status::PAID ) );
			$this->fail( 'A paid session must never take the abandoned-cart shortcut.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_ORDER_NOT_FOUND, $e->get_error_code() );
		}
	}

	public function test_an_expiry_that_omits_payment_status_is_never_the_quiet_path(): void {
		$session = $this->expired_session();
		unset( $session['paymentStatus'] );
		try {
			$this->apply( XPay_Event_Names::CHECKOUT_SESSION_EXPIRED, $session );
			$this->fail( 'Unknown is not unpaid; treating it as routine could downgrade a real payment.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_ORDER_NOT_FOUND, $e->get_error_code() );
		}
	}
}
