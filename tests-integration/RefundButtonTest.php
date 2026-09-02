<?php
/**
 * When the "Refund via XPay" button is offered.
 *
 * A fully refunded order still showed it. That is core's rule, not a bug in
 * it: core keeps the generic Refund button while EITHER money OR unrefunded
 * items remain (html-order-items.php:22), because a zero-value refund is how
 * a merchant records returned goods and restocks. Order 92 on the dev store
 * was refunded twice by AMOUNT, so its money was gone while its two items
 * were still unmarked, and the `or` kept the button.
 *
 * What the gateway controls is the primary button inside the panel — the one
 * that moves money at XPay — through can_refund_order(). These pin the three
 * cases where offering it can only produce an error.
 *
 * @package XPay_For_WooCommerce
 */

class RefundButtonTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		// The order-screen note is behind manage_woocommerce, and the
		// default test user has no capabilities at all.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// The currency guard sits behind the API client, which refuses to
		// build without a key. A test that never gets past that is testing
		// the wrong refusal.
		$this->configure_gateway(
			array(
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_integration',
				'test_publishable_key' => 'pk_test_integration',
			)
		);
	}

	/**
	 * A paid XPay order with a recorded payment intent.
	 *
	 * @param string $total    Order total.
	 * @param string $currency Order currency.
	 */
	private function paid_order( string $total = '100.00', string $currency = 'EGP' ): WC_Order {
		$order = new WC_Order();
		$order->set_payment_method( 'xpay' );
		$order->set_currency( $currency );
		$order->set_total( $total );
		$order->update_meta_data( XPay_Constants::META_PAYMENT_INTENT, 'pi_test' );
		$order->set_status( 'processing' );
		$order->save();
		return $order;
	}

	/**
	 * Refund by AMOUNT only, which is what a merchant does when they type a
	 * figure into the refund box without touching line quantities. It is
	 * also what leaves core's item counter untouched.
	 *
	 * @param WC_Order $order  Order to refund.
	 * @param string   $amount Amount to refund.
	 */
	private function refund_amount( WC_Order $order, string $amount ): void {
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $amount,
			)
		);
	}

	/* ── The case that started this ──────────────────────────────────── */

	public function test_a_fully_refunded_order_is_not_offered_to_xpay_again(): void {
		$order = $this->paid_order( '100.00' );
		$this->refund_amount( $order, '40.00' );
		$this->refund_amount( $order, '60.00' );

		$this->assertFalse(
			$this->gateway()->can_refund_order( wc_get_order( $order->get_id() ) ),
			'The order screen offered to refund money that is already fully refunded.'
		);
	}

	/**
	 * And core's own condition still keeps the generic Refund button, which
	 * is what a merchant needs to record the returned items and restock.
	 * Pinned so that narrowing the gateway button is never mistaken for
	 * hiding the refund UI.
	 */
	public function test_core_still_offers_the_refund_panel_for_recording_returned_items(): void {
		$order = $this->paid_order( '100.00' );
		// The whole point of the case is the second half of core's `or`, and
		// that half reads item counts. An order with no lines counts zero
		// against zero, so it reaches the same conclusion having exercised
		// nothing.
		$order->add_product( $this->make_product( '100' ), 1 );
		$order->set_total( '100.00' );
		$order->save();
		$this->refund_amount( $order, '100.00' );

		$fresh           = wc_get_order( $order->get_id() );
		$money_left      = 0 < $fresh->get_total() - $fresh->get_total_refunded();
		$items_left      = 0 < absint( $fresh->get_item_count() - $fresh->get_item_count_refunded() );
		$core_would_show = $money_left || $items_left;

		$this->assertSame( 1, $fresh->get_item_count(), 'Without a line to count, the assertion below pins nothing.' );
		$this->assertFalse( $money_left );
		$this->assertTrue(
			$core_would_show,
			'Core\'s own refund panel must stay reachable for a zero-value, restock-only refund.'
		);
	}

	public function test_a_partly_refunded_order_can_still_be_refunded(): void {
		$order = $this->paid_order( '100.00' );
		$this->refund_amount( $order, '30.00' );

		$this->assertTrue( $this->gateway()->can_refund_order( wc_get_order( $order->get_id() ) ) );
	}

	public function test_an_untouched_paid_order_can_be_refunded(): void {
		$this->assertTrue( $this->gateway()->can_refund_order( $this->paid_order() ) );
	}

	/* ── Nothing to refund against ───────────────────────────────────── */

	/**
	 * An order nobody ever paid.
	 *
	 * The whole refund gate rests on one invariant: `_xpay_payment_intent_id`
	 * is written in exactly two places (class-xpay-order-sync.php:134 and
	 * :599), both inside the handling of a session the platform has already
	 * reported PAID. So the meta being present means money moved, and its
	 * absence means none did. This pins the consequence.
	 *
	 * (WooCommerce's own "Refund manually" still works here and records a
	 * local figure without calling anyone. That is core's behaviour and is
	 * how an unpaid order can end up showing a refund with no XPay refund
	 * id against it.)
	 */
	public function test_an_unpaid_order_is_never_offered_to_xpay(): void {
		$order = new WC_Order();
		$order->set_payment_method( 'xpay' );
		$order->set_currency( 'EGP' );
		$order->set_total( '100.00' );
		$order->set_status( 'pending' );
		$order->save();

		$this->assertFalse( $order->is_paid() );
		$this->assertFalse(
			$this->gateway()->can_refund_order( $order ),
			'An order no money was ever taken for was offered a refund at XPay.'
		);
	}

	public function test_refunding_an_unpaid_order_through_xpay_is_refused(): void {
		$order = new WC_Order();
		$order->set_payment_method( 'xpay' );
		$order->set_currency( 'EGP' );
		$order->set_total( '100.00' );
		$order->set_status( 'pending' );
		$order->save();

		$result = $this->gateway()->process_refund( $order->get_id(), 50.0, '' );

		$this->assertInstanceOf( 'WP_Error', $result, 'There is no payment behind this order to refund.' );
	}

	public function test_an_order_with_no_payment_intent_is_not_offered(): void {
		$order = $this->paid_order();
		$order->delete_meta_data( XPay_Constants::META_PAYMENT_INTENT );
		$order->save();

		$this->assertFalse(
			$this->gateway()->can_refund_order( wc_get_order( $order->get_id() ) ),
			'XPay_Refund_Service throws not_configured on this order, so the button can only error.'
		);
	}

	/* ── A store priced in another currency ──────────────────────────── */

	/**
	 * A full refund works on any currency — the amount is left unstated and
	 * the platform returns the whole remaining balance at the rate it
	 * locked. Only a PART refund is impossible, and that is a choice the
	 * merchant makes in the panel rather than a property of the order, so
	 * the button belongs there.
	 */
	public function test_a_non_egp_order_still_gets_the_button(): void {
		$this->assertTrue( $this->gateway()->can_refund_order( $this->paid_order( '100.00', 'USD' ) ) );
	}

	/**
	 * And the part-refund the button cannot take is refused with a reason,
	 * before any HTTP call. Sending it would move EGP 25 for a $25 refund.
	 */
	public function test_a_part_refund_of_a_non_egp_order_is_refused_with_a_reason(): void {
		$order  = $this->paid_order( '100.00', 'USD' );
		$result = $this->gateway()->process_refund( $order->get_id(), 25.0, '' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertStringContainsString(
			'EGP',
			$result->get_error_message(),
			'The merchant has to be told WHY, not just refused.'
		);
	}

	/* ── Not an order at all ─────────────────────────────────────────── */

	public function test_a_missing_order_is_refused_rather_than_fataling(): void {
		$this->assertFalse( $this->gateway()->can_refund_order( false ) );
	}

	/* ── Telling the merchant, before they hit the wall ──────────────── */

	private function currency_note( WC_Order $order ): string {
		ob_start();
		XPay_Order_Panel::render_refund_currency_note( $order->get_id() );
		return (string) ob_get_clean();
	}

	public function test_a_non_egp_order_is_told_where_part_refunds_happen(): void {
		$note = $this->currency_note( $this->paid_order( '100.00', 'USD' ) );

		$this->assertStringContainsString( 'USD', $note );
		$this->assertStringContainsString( 'EGP', $note );
		$this->assertStringContainsString( 'dashboard', $note, 'The merchant must be told where to go, not just what fails.' );
	}

	public function test_an_egp_order_is_told_nothing_it_does_not_need(): void {
		$this->assertSame( '', $this->currency_note( $this->paid_order( '100.00', 'EGP' ) ) );
	}

	/**
	 * An order with no payment behind it has nothing to refund anywhere, so
	 * the note would only be noise.
	 */
	public function test_an_unpaid_non_egp_order_gets_no_note(): void {
		$order = new WC_Order();
		$order->set_payment_method( 'xpay' );
		$order->set_currency( 'USD' );
		$order->set_total( '100.00' );
		$order->save();

		$this->assertSame( '', $this->currency_note( $order ) );
	}

	public function test_another_gateways_order_gets_no_note(): void {
		$order = new WC_Order();
		$order->set_payment_method( 'cod' );
		$order->set_currency( 'USD' );
		$order->update_meta_data( XPay_Constants::META_PAYMENT_INTENT, 'pi_x' );
		$order->save();

		$this->assertSame( '', $this->currency_note( $order ) );
	}
}
