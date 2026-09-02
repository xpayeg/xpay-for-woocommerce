<?php
/**
 * What XPay will still give back, read from a session payload.
 *
 * The order screen's own refund figures are a local copy. Refunds issued
 * from the XPay dashboard reach it only through a webhook, so a lost
 * delivery leaves WooCommerce offering to refund money that is already
 * gone. These pin the arithmetic that answers from the platform instead.
 *
 * The two fixtures below are real: both were read from the live test API
 * on 2026-08-22, one fully refunded and one untouched.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

class RefundableTest extends TestCase {

	/**
	 * @param array $charges Charge payloads.
	 * @return array Session payload shaped like the API's.
	 */
	private function session( array $charges ): array {
		return array(
			'id'            => 'cs_test_x',
			'paymentStatus' => 'paid',
			'paymentIntent' => array(
				'id'      => 'pi_x',
				'amount'  => 11000,
				'charges' => $charges,
			),
		);
	}

	/**
	 * @param int    $amount   Captured minor units.
	 * @param int    $refunded Refunded minor units.
	 * @param string $status   Charge status.
	 * @return array
	 */
	private function charge( int $amount, int $refunded, string $status = XPay_Charge_Status::SUCCEEDED ): array {
		return array(
			'id'             => 'ch_x',
			'status'         => $status,
			'amount'         => $amount,
			'amountRefunded' => $refunded,
			'currency'       => 'EGP',
		);
	}

	/* ── The two live fixtures ───────────────────────────────────────── */

	public function test_an_untouched_payment_is_fully_refundable(): void {
		$answer = XPay_Refundable::from_session( $this->session( array( $this->charge( 11000, 0 ) ) ) );

		$this->assertSame( 11000, $answer['refundable'] );
		$this->assertSame( 'EGP', $answer['currency'] );
	}

	public function test_a_fully_refunded_payment_has_nothing_left(): void {
		// Read from the live API: charge ch_7eNquFLgEaPwXog9GxeZbF,
		// amount 34999, amountRefunded 34999.
		$answer = XPay_Refundable::from_session( $this->session( array( $this->charge( 34999, 34999 ) ) ) );

		$this->assertSame( 0, $answer['refundable'] );
		$this->assertSame( 34999, $answer['refunded'] );
	}

	/**
	 * The live fixture above is the reason the status is not the test: that
	 * charge was fully refunded and STILL read SUCCEEDED. Anything keying
	 * off the status alone would have called it fully refundable.
	 */
	public function test_a_fully_refunded_charge_still_reading_succeeded_is_read_correctly(): void {
		$answer = XPay_Refundable::from_session(
			$this->session( array( $this->charge( 34999, 34999, XPay_Charge_Status::SUCCEEDED ) ) )
		);

		$this->assertSame( 0, $answer['refundable'] );
	}

	public function test_a_partly_refunded_payment_reports_the_remainder(): void {
		$answer = XPay_Refundable::from_session(
			$this->session( array( $this->charge( 10000, 3000, XPay_Charge_Status::PARTIALLY_REFUNDED ) ) )
		);

		$this->assertSame( 7000, $answer['refundable'] );
		$this->assertSame( 10000, $answer['captured'] );
	}

	public function test_a_charge_marked_refunded_is_still_counted_as_captured_money(): void {
		$answer = XPay_Refundable::from_session(
			$this->session( array( $this->charge( 5000, 5000, XPay_Charge_Status::REFUNDED ) ) )
		);

		$this->assertSame( 5000, $answer['captured'], 'A refunded charge is settled money, not a failure.' );
		$this->assertSame( 0, $answer['refundable'] );
	}

	/* ── Attempts that took no money ─────────────────────────────────── */

	public function test_a_declined_attempt_is_not_refundable(): void {
		$answer = XPay_Refundable::from_session(
			$this->session(
				array(
					$this->charge( 11000, 0, XPay_Charge_Status::FAILED ),
					$this->charge( 11000, 0 ),
				)
			)
		);

		$this->assertSame( 11000, $answer['refundable'], 'A shopper\'s failed retry was counted as refundable money.' );
	}

	public function test_a_cancelled_or_pending_charge_is_not_refundable(): void {
		foreach ( array( XPay_Charge_Status::CANCELED, XPay_Charge_Status::PENDING ) as $status ) {
			$answer = XPay_Refundable::from_session( $this->session( array( $this->charge( 9000, 0, $status ) ) ) );
			$this->assertNull( $answer, "A $status charge is not captured money and must not answer a figure." );
		}
	}

	/* ── When it must refuse to answer ───────────────────────────────── */

	public function test_a_session_that_did_not_expand_its_charges_answers_nothing(): void {
		$this->assertNull(
			XPay_Refundable::from_session(
				array( 'paymentIntent' => array( 'id' => 'pi_x', 'amount' => 11000 ) )
			),
			'Absent charges are not zero charges.'
		);
	}

	public function test_a_session_with_no_payment_intent_answers_nothing(): void {
		$this->assertNull( XPay_Refundable::from_session( array( 'id' => 'cs_x' ) ) );
	}

	public function test_a_charge_with_no_amount_answers_nothing(): void {
		$this->assertNull(
			XPay_Refundable::from_session(
				$this->session( array( array( 'status' => XPay_Charge_Status::SUCCEEDED, 'currency' => 'EGP' ) ) )
			)
		);
	}

	public function test_a_charge_with_no_currency_answers_nothing(): void {
		$this->assertNull(
			XPay_Refundable::from_session(
				$this->session( array( array( 'status' => XPay_Charge_Status::SUCCEEDED, 'amount' => 5000 ) ) )
			)
		);
	}

	public function test_charges_in_two_currencies_cannot_be_added_up(): void {
		$mixed    = $this->charge( 5000, 0 );
		$mixed['currency'] = 'USD';

		$this->assertNull(
			XPay_Refundable::from_session( $this->session( array( $this->charge( 5000, 0 ), $mixed ) ) )
		);
	}

	/**
	 * An over-refund recorded upstream is not money this store may claim.
	 */
	public function test_more_refunded_than_captured_reports_zero_not_a_negative(): void {
		$answer = XPay_Refundable::from_session( $this->session( array( $this->charge( 5000, 6000 ) ) ) );

		$this->assertSame( 0, $answer['refundable'] );
	}

	public function test_two_captured_charges_are_summed(): void {
		$answer = XPay_Refundable::from_session(
			$this->session( array( $this->charge( 5000, 1000 ), $this->charge( 3000, 0 ) ) )
		);

		$this->assertSame( 8000, $answer['captured'] );
		$this->assertSame( 1000, $answer['refunded'] );
		$this->assertSame( 7000, $answer['refundable'] );
	}

	/* ── The customer-facing mirror ──────────────────────────────────── */

	public function test_an_egp_charge_has_no_mirror_to_show(): void {
		$answer = XPay_Refundable::from_session( $this->session( array( $this->charge( 11000, 0 ) ) ) );

		$this->assertNull( $answer['presentment'] );
	}

	/**
	 * A store pricing in dollars: XPay settles EGP and carries the dollar
	 * figure alongside, so the merchant can join their payout to their order.
	 */
	public function test_a_foreign_currency_charge_reports_what_the_customer_saw(): void {
		$charge = $this->charge( 485000, 0 );
		$charge['presentmentDetails'] = array(
			'amount'         => 10000,
			'currency'       => 'usd',
			'exchangeRate'   => 48.5,
			'exchangeRateId' => 'rate_x',
		);

		$answer = XPay_Refundable::from_session( $this->session( array( $charge ) ) );

		$this->assertSame( 485000, $answer['captured'], 'Settlement figures stay in the settlement currency.' );
		$this->assertSame( 'EGP', $answer['currency'] );
		$this->assertSame( 10000, $answer['presentment']['amount'] );
		$this->assertSame( 'USD', $answer['presentment']['currency'] );
		$this->assertSame( '48.5', $answer['presentment']['rate'] );
	}

	/* ── The balance in the customer's own currency ──────────────────── */

	/**
	 * Summed from the refunds' OWN presentment amounts, which the platform
	 * projected at the rate locked on the charge. Nothing is divided out of
	 * a rate here.
	 */
	public function test_the_customers_balance_is_summed_from_the_refunds(): void {
		$charge                       = $this->charge( 485000, 161650 );
		$charge['presentmentDetails'] = array( 'amount' => 10000, 'currency' => 'USD' );
		$charge['refunds']            = array(
			array(
				'amount'             => 161650,
				'status'             => 'SUCCEEDED',
				'presentmentDetails' => array( 'amount' => 3333, 'currency' => 'USD' ),
			),
		);

		$answer = XPay_Refundable::from_session( $this->session( array( $charge ) ) );

		$this->assertSame( 3333, $answer['presentment']['refunded'] );
		$this->assertSame( 6667, $answer['presentment']['refundable'] );
	}

	/**
	 * The check that makes the sum safe. A charge that HAS given money back
	 * but did not expand its refunds cannot be accounted for, and reporting
	 * the whole charge as refundable would offer back money already gone.
	 */
	public function test_an_unaccounted_refund_reports_no_customer_balance(): void {
		$charge                       = $this->charge( 485000, 161650 );
		$charge['presentmentDetails'] = array( 'amount' => 10000, 'currency' => 'USD' );

		$answer = XPay_Refundable::from_session( $this->session( array( $charge ) ) );

		$this->assertArrayNotHasKey(
			'refundable',
			$answer['presentment'],
			'A third of this charge is already refunded and the whole of it was offered back.'
		);
		$this->assertSame( 323350, $answer['refundable'], 'The settlement figure is unaffected.' );
	}

	/** Refunds that do not add up to what the charge says went back. */
	public function test_refunds_that_do_not_reconcile_report_no_customer_balance(): void {
		$charge                       = $this->charge( 485000, 161650 );
		$charge['presentmentDetails'] = array( 'amount' => 10000, 'currency' => 'USD' );
		$charge['refunds']            = array(
			array(
				'amount'             => 100000,
				'status'             => 'SUCCEEDED',
				'presentmentDetails' => array( 'amount' => 2061, 'currency' => 'USD' ),
			),
		);

		$answer = XPay_Refundable::from_session( $this->session( array( $charge ) ) );

		$this->assertArrayNotHasKey( 'refundable', $answer['presentment'] );
	}

	/** A refund that never completed gave nothing back. */
	public function test_a_failed_refund_does_not_reduce_the_customers_balance(): void {
		$charge                       = $this->charge( 485000, 0 );
		$charge['presentmentDetails'] = array( 'amount' => 10000, 'currency' => 'USD' );
		$charge['refunds']            = array(
			array(
				'amount'             => 161650,
				'status'             => 'FAILED',
				'presentmentDetails' => array( 'amount' => 3333, 'currency' => 'USD' ),
			),
		);

		$answer = XPay_Refundable::from_session( $this->session( array( $charge ) ) );

		$this->assertSame( 0, $answer['presentment']['refunded'] );
		$this->assertSame( 10000, $answer['presentment']['refundable'] );
	}

	public function test_an_untouched_charge_has_its_whole_amount_refundable(): void {
		$charge                       = $this->charge( 485000, 0 );
		$charge['presentmentDetails'] = array( 'amount' => 10000, 'currency' => 'USD' );

		$answer = XPay_Refundable::from_session( $this->session( array( $charge ) ) );

		$this->assertSame( 10000, $answer['presentment']['refundable'] );
	}

	public function test_a_mirror_missing_its_currency_is_not_shown(): void {
		$charge = $this->charge( 485000, 0 );
		$charge['presentmentDetails'] = array( 'amount' => 10000 );

		$this->assertNull( XPay_Refundable::from_session( $this->session( array( $charge ) ) )['presentment'] );
	}

	public function test_mirrors_in_two_currencies_are_not_shown_at_all(): void {
		$a = $this->charge( 100000, 0 );
		$a['presentmentDetails'] = array( 'amount' => 2000, 'currency' => 'USD' );
		$b = $this->charge( 100000, 0 );
		$b['presentmentDetails'] = array( 'amount' => 1800, 'currency' => 'EUR' );

		$this->assertNull( XPay_Refundable::from_session( $this->session( array( $a, $b ) ) )['presentment'] );
	}
}
