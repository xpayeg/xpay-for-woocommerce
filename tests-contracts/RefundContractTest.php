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

	/**
	 * Refund the way WooCommerce does it, ledger included.
	 *
	 * wc_create_refund() SAVES the refund record (wc-order-functions.php:667)
	 * and only then calls the gateway (:669), so by the time the service
	 * runs, get_total_refunded() already counts the refund in flight. Tests
	 * that called the service directly left the ledger at zero, which made
	 * the whole-order branch answer on a number no real store would present
	 * and hid a refund of exactly half being sent as a bare request.
	 *
	 * @param WC_Order $order  Order being refunded.
	 * @param float    $amount Amount in the order's currency.
	 * @param string   $reason Refund reason.
	 */
	private function refund( WC_Order $order, float $amount, string $reason = '' ) {
		$order->total_refunded = (string) ( (float) $order->get_total_refunded() + $amount );
		return $this->service->refund_order( $order, $amount, $reason );
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

	/* ── A store priced in something other than EGP ──────────────────── */

	/**
	 * Script a charge the way the live API returns one for a store priced
	 * in something other than EGP.
	 *
	 * @param int         $presentment_charged Charge amount in the customer's currency.
	 * @param array[]     $refunds             Refunds already taken.
	 * @param string|null $rate                Locked rate, or null for a charge that carries none.
	 */
	private function xpay_holds( int $presentment_charged, array $refunds = array(), ?string $rate = '50' ) {
		$presentment = array(
			'amount'   => $presentment_charged,
			'currency' => 'USD',
		);
		if ( null !== $rate ) {
			$presentment['exchangeRate'] = $rate;
		}

		$settled = 0;
		foreach ( $refunds as $refund ) {
			$settled += $refund['amount'];
		}

		$this->client->session = array(
			'paymentIntent' => array(
				'charges' => array(
					array(
						'status'             => 'SUCCEEDED',
						'currency'           => 'EGP',
						'amount'             => $presentment_charged * 50,
						'amountRefunded'     => $settled,
						'presentmentDetails' => $presentment,
						'refunds'            => $refunds,
					),
				),
			),
		);
	}

	/**
	 * A PART refund of a non-EGP order is converted at the rate XPay locked
	 * when the shopper paid. The endpoint has no currency field and reads
	 * the integer as EGP, so the number sent has to be the EGP one — $100.00
	 * at 50 is EGP 5,000.00.
	 */
	public function test_a_part_refund_of_a_non_egp_order_is_converted_at_the_locked_rate() {
		$order = $this->paidOrder(
			array(
				'currency' => 'USD',
				'total'    => '300.00',
				'meta'     => array(
					XPay_Constants::META_PAYMENT_INTENT => 'pi_test_contract',
					XPay_Constants::META_SESSION_ID     => 'cs_test_contract',
				),
			)
		);
		$this->xpay_holds( 30000 );

		$this->refund( $order, 100.0, 'damaged' );

		$this->assertCount( 1, $this->client->refunds );
		$this->assertSame(
			500000,
			$this->client->refunds[0]['amount'],
			'The amount on the wire has to be EGP: the platform reads the integer as its own currency.'
		);
	}

	/**
	 * Half is a PART refund, however the ledger happens to line up.
	 *
	 * WooCommerce saves the refund before calling the gateway, so on a
	 * 290.00 order with nothing refunded before, a 145.00 request leaves
	 * 145.00 outstanding. Testing "what remains equals what was asked" was
	 * therefore true for exactly half, and a non-EGP order took the bare
	 * branch: no amount stated, and the platform returns the WHOLE
	 * remaining balance. The merchant asks to refund half and gives back
	 * all of it, with no error anywhere.
	 */
	public function test_half_a_non_egp_order_is_not_treated_as_the_whole_of_it() {
		$order = $this->paidOrder(
			array(
				'currency' => 'USD',
				'total'    => '290.00',
				'meta'     => array(
					XPay_Constants::META_PAYMENT_INTENT => 'pi_test_contract',
					XPay_Constants::META_SESSION_ID     => 'cs_test_contract',
				),
			)
		);
		$this->xpay_holds( 29000 );

		$this->refund( $order, 145.0, 'half' );

		$this->assertCount( 1, $this->client->refunds );
		$this->assertArrayHasKey(
			'amount',
			$this->client->refunds[0],
			'No amount was stated, so the platform refunds everything that is left.'
		);
		// 145.00 at the locked rate of 50 is EGP 7,250.00.
		$this->assertSame( 725000, $this->client->refunds[0]['amount'] );
	}

	/**
	 * Asking for everything that is left is not a partial. Stating no amount
	 * lets the platform work the remainder out itself, which takes rounding
	 * out of the commonest case entirely.
	 */
	public function test_asking_for_the_whole_remaining_balance_states_no_amount() {
		$order = $this->paidOrder(
			array(
				'currency' => 'USD',
				'total'    => '300.00',
				'meta'     => array(
					XPay_Constants::META_PAYMENT_INTENT => 'pi_test_contract',
					XPay_Constants::META_SESSION_ID     => 'cs_test_contract',
				),
			)
		);
		// $300 charged, $100 already back, so $200 is what is left.
		$this->xpay_holds(
			30000,
			array(
				array(
					'amount'             => 500000,
					'status'             => 'SUCCEEDED',
					'presentmentDetails' => array( 'amount' => 10000, 'currency' => 'USD' ),
				),
			)
		);

		$this->refund( $order, 200.0, 'damaged' );

		$this->assertArrayNotHasKey(
			'amount',
			$this->client->refunds[0],
			'The remainder was converted and stated when the platform could have worked it out exactly.'
		);
	}

	/**
	 * Without a locked rate there is no honest conversion. Reaching for
	 * today's rate would price an old order at a number nobody agreed to,
	 * so this refuses — and refuses before any money moves.
	 */
	public function test_a_part_refund_without_a_locked_rate_is_refused() {
		$order = $this->paidOrder(
			array(
				'currency' => 'USD',
				'meta'     => array(
					XPay_Constants::META_PAYMENT_INTENT => 'pi_test_contract',
					XPay_Constants::META_SESSION_ID     => 'cs_test_contract',
				),
			)
		);
		$this->xpay_holds( 30000, array(), null );

		try {
			$this->refund( $order, 100.0, 'damaged' );
			$this->fail( 'A part refund with no rate to convert at must fail closed.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::REFUND_CURRENCY_UNSUPPORTED, $e->get_error_code() );
		}

		$this->assertSame( array(), $this->client->refunds, 'The refusal must happen before any money-moving call.' );
		$this->assertStageFired( 'refund.no_locked_rate' );
	}

	/**
	 * A FULL refund needs no amount at all, and that is the whole trick:
	 * omitting `amount` refunds the entire remaining balance in EGP at the
	 * rate the platform locked when the shopper paid. Verified against the
	 * live test API — a charge of 11000 with 100 already refunded answered
	 * a bare request with exactly 10900.
	 */
	public function test_a_full_refund_of_a_non_egp_order_states_no_amount() {
		$order = $this->paidOrder( array( 'currency' => 'USD' ) );
		$this->client->refund = array(
			'presentmentDetails' => array(
				'amount'   => 29000,
				'currency' => 'USD',
			),
		);

		$this->refund( $order, 290.0, '' );

		$this->assertCount( 1, $this->client->refunds );
		$this->assertArrayNotHasKey(
			'amount',
			$this->client->refunds[0],
			'Stating an amount here is the bug: the platform would read 29000 as EGP 290.00.'
		);
		$this->assertSame( 'pi_test_contract', $this->client->refunds[0]['paymentIntentId'] );
	}

	/**
	 * "Full" means what is LEFT, not what the order once was. A dollar
	 * order already part-refunded from the dashboard is refunded in full by
	 * asking for the remainder.
	 */
	public function test_the_remainder_of_a_part_refunded_non_egp_order_counts_as_full() {
		$order = $this->paidOrder(
			array(
				'currency'       => 'USD',
				'total_refunded' => '90.00',
			)
		);
		$this->client->refund = array(
			'presentmentDetails' => array(
				'amount'   => 20000,
				'currency' => 'USD',
			),
		);

		$this->refund( $order, 200.0, '' );

		$this->assertArrayNotHasKey( 'amount', $this->client->refunds[0] );
	}

	/**
	 * With no amount stated there is nothing to compare the settlement
	 * figure to — so the CUSTOMER-facing mirror is what gets checked. A
	 * dashboard refund that landed first leaves less remaining, the mirror
	 * comes back smaller, and this fails closed with the money moved.
	 */
	public function test_a_bare_refund_is_verified_against_the_customers_own_currency() {
		$order = $this->paidOrder( array( 'currency' => 'USD' ) );
		$this->client->refund = array(
			'presentmentDetails' => array(
				'amount'   => 12000,
				'currency' => 'USD',
			),
		);

		try {
			$this->refund( $order, 290.0, '' );
			$this->fail( 'XPay refunded a different amount than this order is for and it was recorded anyway.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::REFUND_RESULT_MISMATCH, $e->get_error_code() );
		}
	}

	/**
	 * A full refund on a non-EGP order sends no amount, so the platform
	 * returns the whole remaining balance. That balance round-trips through
	 * EGP and both conversions truncate, so the customer-facing figure comes
	 * back one unit below what WooCommerce asked for — FxTest pins the same
	 * shape: 2501 becomes 127576 becomes 2500. A completed refund must still
	 * be recorded.
	 */
	public function test_a_rounding_cent_does_not_fail_a_refund_that_happened() {
		$order = $this->paidOrder( array( 'currency' => 'USD' ) );
		$this->client->refund = array(
			'presentmentDetails' => array(
				// Asked for 290.00, reported back as 289.99.
				'amount'   => 28999,
				'currency' => 'USD',
			),
		);

		$this->refund( $order, 290.0, '' );

		$this->assertNotEmpty( $this->client->refunds, 'The refund never reached the platform.' );
		$this->assertStageFired( 'refund.submitted' );
	}

	/** And the merchant is told, rather than it happening silently. */
	public function test_the_rounding_gap_is_written_on_the_order() {
		$order = $this->paidOrder( array( 'currency' => 'USD' ) );
		$this->client->refund = array(
			'presentmentDetails' => array( 'amount' => 28999, 'currency' => 'USD' ),
		);

		$this->refund( $order, 290.0, '' );

		$this->assertStageFired( 'refund.presentment_rounding' );
	}

	/**
	 * The bound is what keeps the protection. One unit is rounding; a real
	 * difference — a dashboard refund that landed first, leaving less
	 * remaining — is not, and must still fail with the money moved.
	 */
	public function test_a_difference_larger_than_rounding_still_fails() {
		$order = $this->paidOrder( array( 'currency' => 'USD' ) );
		$this->client->refund = array(
			'presentmentDetails' => array( 'amount' => 28900, 'currency' => 'USD' ),
		);

		try {
			$this->refund( $order, 290.0, '' );
			$this->fail( 'A dollar short is not a rounding artefact, and it was recorded anyway.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::REFUND_RESULT_MISMATCH, $e->get_error_code() );
		}
	}

	/**
	 * The same bound, on the CONVERTED branch.
	 *
	 * A part refund of a non-EGP order states an amount, so it takes the
	 * other path: the mirror is compared against what the merchant asked
	 * for. That branch carried the reasoning in its comment and never
	 * implemented it, so any returned figure was accepted and written on
	 * the order as "rounding" — a $100 refund reported back as $2.50 was
	 * recorded in WooCommerce as $100 refunded, with the customer short
	 * $97.50 and the books saying otherwise.
	 */
	public function test_a_converted_part_refund_short_by_more_than_rounding_still_fails() {
		$order = $this->paidOrder(
			array(
				'currency' => 'USD',
				'total'    => '300.00',
				'meta'     => array(
					XPay_Constants::META_PAYMENT_INTENT => 'pi_test_contract',
					XPay_Constants::META_SESSION_ID     => 'cs_test_contract',
				),
			)
		);
		$this->xpay_holds( 30000 );
		// Asked for $100.00, reported back to the customer as $2.50.
		$this->client->refund = array(
			'presentmentDetails' => array( 'amount' => 250, 'currency' => 'USD' ),
		);

		try {
			$this->refund( $order, 100.0, 'damaged' );
			$this->fail( 'A refund reported ninety-seven dollars short is not rounding, and it was recorded anyway.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::REFUND_RESULT_MISMATCH, $e->get_error_code() );
		}
	}

	/** And the artefact the bound exists to allow still passes through. */
	public function test_a_converted_part_refund_one_unit_short_is_still_recorded() {
		$order = $this->paidOrder(
			array(
				'currency' => 'USD',
				'total'    => '300.00',
				'meta'     => array(
					XPay_Constants::META_PAYMENT_INTENT => 'pi_test_contract',
					XPay_Constants::META_SESSION_ID     => 'cs_test_contract',
				),
			)
		);
		$this->xpay_holds( 30000 );
		// Asked for $100.00, reported back as $99.99: two truncations.
		$this->client->refund = array(
			'presentmentDetails' => array( 'amount' => 9999, 'currency' => 'USD' ),
		);

		$this->refund( $order, 100.0, 'damaged' );

		$this->assertNotEmpty( $this->client->refunds, 'The refund never reached the platform.' );
		$this->assertStageFired( 'refund.presentment_rounding' );
	}

	/**
	 * The settlement figure is the platform's business on this path. It
	 * comes back in EGP by design, and reading it as a disagreement would
	 * reject every single non-EGP refund.
	 */
	public function test_the_egp_settlement_figure_is_not_mistaken_for_a_mismatch() {
		$order = $this->paidOrder( array( 'currency' => 'USD' ) );
		$this->client->refund = array(
			'amount'             => 1406500,
			'currency'           => 'EGP',
			'presentmentDetails' => array(
				'amount'   => 29000,
				'currency' => 'USD',
			),
		);

		$refund = $this->refund( $order, 290.0, '' );

		$this->assertSame( XPay_Refund_Status::SUCCEEDED, $refund['status'] );
	}

	/**
	 * An EGP order is unchanged: it states its amount, because in the one
	 * currency the platform reads the number is unambiguous and saying it
	 * lets the answer be checked against it.
	 */
	public function test_an_egp_order_still_states_its_amount() {
		$this->refund( $this->paidOrder(), 100.0, '' );

		$this->assertSame( 10000, $this->client->refunds[0]['amount'] );
	}

	public function test_retry_after_lost_response_replays_the_same_key() {
		$order = $this->paidOrder();

		$this->client->refund_failure = XPay_Api_Exception::transport( 'response lost' );
		try {
			$this->refund( $order, 100.0, '' );
			$this->fail( 'Transport failure must surface to the admin.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::TRANSPORT_ERROR, $e->get_error_code() );
		}

		$this->refund( $order, 100.0, '' );

		$this->assertCount( 2, $this->client->refund_keys );
		$this->assertSame( $this->client->refund_keys[0], $this->client->refund_keys[1], 'The retry must replay the original key, or a refund that committed server-side pays out twice.' );
	}

	public function test_second_deliberate_refund_advances_the_key() {
		$order = $this->paidOrder();

		$this->refund( $order, 100.0, '' );
		$this->refund( $order, 100.0, '' );

		$this->assertSame( 'wcref_14_n0_10000', $this->client->refund_keys[0] );
		$this->assertSame( 'wcref_14_n1_10000', $this->client->refund_keys[1], 'A refund after a recorded success is a NEW refund and needs a fresh key.' );
		$this->assertCount( 2, $order->get_meta( XPay_Constants::META_REFUND_IDS ) );
	}

	public function test_succeeded_with_mismatched_amount_fails_closed() {
		$order                = $this->paidOrder();
		$this->client->refund = array( 'amount' => 99999 );

		try {
			$this->refund( $order, 100.0, '' );
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
			$this->refund( $order, 100.0, '' );
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

		$refund = $this->refund( $order, 100.0, 'ok' );

		$this->assertSame( XPay_Refund_Status::SUCCEEDED, $refund['status'] );
		$this->assertCount( 1, $order->get_meta( XPay_Constants::META_REFUND_IDS ), 'Fail open on shape: only a present-but-different value blocks.' );
		$this->assertStageFired( 'refund.submitted' );
	}
}
