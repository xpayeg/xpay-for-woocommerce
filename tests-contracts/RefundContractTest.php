<?php
/**
 * Pins XPay_Refund_Service's money contracts:
 *
 *   1. Non-EGP orders are refused BEFORE any API call — the platform
 *      interprets refund amounts in the charge's processing currency
 *      (always EGP), so a WooCommerce amount in any other currency would
 *      move the wrong money and come back SUCCEEDED.
 *   2. The Idempotency-Key is deterministic per logical refund: a retry
 *      after a lost response replays the original refund instead of
 *      paying out twice, while a refund following a recorded success
 *      advances the sequence and gets its own key.
 *   3. SUCCEEDED alone is not trusted: a returned amount or currency
 *      that differs from the request fails closed (absent fields pass —
 *      fail open on shape, closed on value).
 *
 * @package XPay_For_WooCommerce
 */

class RefundContractTest extends ContractTestCase {

	/** @var XPay_Capture_Client */
	private $client;

	/** @var XPay_Refund_Service */
	private $service;

	protected function setUp(): void {
		parent::setUp();
		$this->client  = new XPay_Capture_Client();
		$this->service = new XPay_Refund_Service( $this->client );
	}

	private function paidOrder( array $props = array() ): WC_Order {
		return $this->makeOrder(
			14,
			array_merge(
				array(
					'total' => '290.00',
					'paid'  => true,
					'meta'  => array( XPay_Constants::META_PAYMENT_INTENT => 'pi_test_contract' ),
				),
				$props
			)
		);
	}

	public function test_non_egp_order_is_refused_before_any_api_call() {
		$order = $this->paidOrder( array( 'currency' => 'USD' ) );

		try {
			$this->service->refund_order( $order, 100.0, 'damaged' );
			$this->fail( 'A non-EGP refund must fail closed: the platform would interpret the amount as EGP.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::REFUND_CURRENCY_UNSUPPORTED, $e->get_error_code() );
		}

		$this->assertSame( array(), $this->client->refunds, 'The refusal must happen before any money-moving call.' );
		$this->assertStageFired( 'refund.currency_unsupported' );
	}

	public function test_retry_after_lost_response_replays_the_same_key() {
		$order = $this->paidOrder();

		$this->client->refund_failure = XPay_Api_Exception::transport( 'response lost' );
		try {
			$this->service->refund_order( $order, 100.0, '' );
			$this->fail( 'Transport failure must surface to the admin.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::TRANSPORT_ERROR, $e->get_error_code() );
		}

		$this->service->refund_order( $order, 100.0, '' );

		$this->assertCount( 2, $this->client->refund_keys );
		$this->assertSame( $this->client->refund_keys[0], $this->client->refund_keys[1], 'The retry must replay the original key, or a refund that committed server-side pays out twice.' );
	}

	public function test_second_deliberate_refund_advances_the_key() {
		$order = $this->paidOrder();

		$this->service->refund_order( $order, 100.0, '' );
		$this->service->refund_order( $order, 100.0, '' );

		$this->assertSame( 'wcref_14_n0_10000', $this->client->refund_keys[0] );
		$this->assertSame( 'wcref_14_n1_10000', $this->client->refund_keys[1], 'A refund after a recorded success is a NEW refund and needs a fresh key.' );
		$this->assertCount( 2, $order->get_meta( XPay_Constants::META_REFUND_IDS ) );
	}

	public function test_succeeded_with_mismatched_amount_fails_closed() {
		$order                = $this->paidOrder();
		$this->client->refund = array( 'amount' => 99999 );

		try {
			$this->service->refund_order( $order, 100.0, '' );
			$this->fail( 'SUCCEEDED with a different amount must not be recorded as the requested refund.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::REFUND_RESULT_MISMATCH, $e->get_error_code() );
		}

		$this->assertSame( '', $order->get_meta( XPay_Constants::META_REFUND_IDS ), 'A mismatched refund must not advance the idempotency ledger.' );
		$this->assertStageFired( 'refund.result_mismatch' );
		$this->assertNotEmpty( $order->notes, 'Money moved: the trail must live on the order, not only in the log.' );
	}

	public function test_succeeded_with_mismatched_currency_fails_closed() {
		$order                = $this->paidOrder();
		$this->client->refund = array( 'currency' => 'USD' );

		try {
			$this->service->refund_order( $order, 100.0, '' );
			$this->fail( 'SUCCEEDED in a different currency must not be recorded as the requested refund.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::REFUND_RESULT_MISMATCH, $e->get_error_code() );
		}
	}

	public function test_absent_result_fields_pass_shape_open() {
		$order                = $this->paidOrder();
		$this->client->refund = array(
			'amount'   => null,
			'currency' => null,
		);

		$refund = $this->service->refund_order( $order, 100.0, 'ok' );

		$this->assertSame( XPay_Refund_Status::SUCCEEDED, $refund['status'] );
		$this->assertCount( 1, $order->get_meta( XPay_Constants::META_REFUND_IDS ), 'Fail open on shape: only a present-but-different value blocks.' );
		$this->assertStageFired( 'refund.submitted' );
	}
}
