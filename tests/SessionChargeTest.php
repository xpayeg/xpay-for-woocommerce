<?php
/**
 * Which number a session says it is charging.
 *
 * One helper, three former call sites, and one bug repeated at all of them:
 * reading `amountTotal`, which the platform inflates with fees and collected
 * VAT, instead of `amountSubtotal`, which mirrors the single line item this
 * plugin actually sends.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

class SessionChargeTest extends TestCase {

	public function test_a_plain_session_reports_its_subtotal(): void {
		$this->assertSame(
			array(
				'amount'   => 29000,
				'currency' => 'EGP',
			),
			XPay_Money::session_charge(
				array(
					'amountSubtotal' => 29000,
					'amountTotal'    => 29000,
					'currency'       => 'EGP',
				)
			)
		);
	}

	/**
	 * amountTotal = subtotal − discount + platformFee + collectedVat. On a
	 * VAT-collecting account it is always
	 * bigger than what we sent, and reading it marked every good payment as
	 * a mismatch.
	 */
	public function test_collected_vat_does_not_change_the_answer(): void {
		$charge = XPay_Money::session_charge(
			array(
				'amountSubtotal' => 29000,
				'amountTotal'    => 33060,
				'currency'       => 'EGP',
			)
		);

		$this->assertSame( 29000, $charge['amount'] );
	}

	public function test_a_pass_through_platform_fee_does_not_change_the_answer(): void {
		$charge = XPay_Money::session_charge(
			array(
				'amountSubtotal' => 29000,
				'amountTotal'    => 29725,
				'currency'       => 'EGP',
			)
		);

		$this->assertSame( 29000, $charge['amount'] );
	}

	/**
	 * When the merchant prices in one currency and settles in another, the
	 * presentment block is the shopper-facing mirror and is the figure the
	 * WooCommerce order total corresponds to.
	 */
	public function test_presentment_outranks_the_processing_figure(): void {
		$this->assertSame(
			array(
				'amount'   => 29000,
				'currency' => 'EGP',
			),
			XPay_Money::session_charge(
				array(
					'amountSubtotal'     => 61000,
					'currency'           => 'USD',
					'presentmentDetails' => array(
						'amountSubtotal' => 29000,
						'amountTotal'    => 33060,
						'currency'       => 'EGP',
					),
				)
			)
		);
	}

	public function test_a_presentment_block_missing_its_subtotal_falls_through(): void {
		$this->assertSame(
			array(
				'amount'   => 61000,
				'currency' => 'USD',
			),
			XPay_Money::session_charge(
				array(
					'amountSubtotal'     => 61000,
					'currency'           => 'USD',
					'presentmentDetails' => array( 'amountTotal' => 33060 ),
				)
			)
		);
	}

	/**
	 * A shape gap must never block a payment — only a present-and-different
	 * value may do that.
	 */
	public function test_a_session_that_states_no_subtotal_answers_null(): void {
		$this->assertNull( XPay_Money::session_charge( array() ) );
		$this->assertNull( XPay_Money::session_charge( array( 'currency' => 'EGP' ) ) );
		$this->assertNull( XPay_Money::session_charge( array( 'amountSubtotal' => 29000 ) ) );
		$this->assertNull(
			XPay_Money::session_charge(
				array(
					'amountTotal' => 29000,
					'currency'    => 'EGP',
				)
			),
			'amountTotal alone must not be read as the charge.'
		);
	}

	public function test_a_malformed_amount_answers_null(): void {
		$this->assertNull(
			XPay_Money::session_charge(
				array(
					'amountSubtotal' => 'not a number',
					'currency'       => 'EGP',
				)
			)
		);
		$this->assertNull(
			XPay_Money::session_charge(
				array(
					'amountSubtotal' => 29000,
					'currency'       => '',
				)
			)
		);
	}

	public function test_the_currency_is_normalised(): void {
		$charge = XPay_Money::session_charge(
			array(
				'amountSubtotal' => 100,
				'currency'       => 'egp',
			)
		);

		$this->assertSame( 'EGP', $charge['currency'] );
	}

	public function test_a_presentment_block_of_the_wrong_type_is_ignored(): void {
		$this->assertSame(
			array(
				'amount'   => 500,
				'currency' => 'EGP',
			),
			XPay_Money::session_charge(
				array(
					'amountSubtotal'     => 500,
					'currency'           => 'EGP',
					'presentmentDetails' => 'nonsense',
				)
			)
		);
	}
}
