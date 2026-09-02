<?php
/**
 * Whether a shopper may be offered the Place Order button back.
 *
 * The browser cannot answer this. The SDK does hand back a code for a
 * payment XPay could not decide on, `payment_still_confirming`, but that
 * code reports
 * how one attempt ended in one browser. What the order's fate turns on is
 * whether the session at XPay is paid, which the webhook may have settled
 * while that browser was still waiting.
 *
 * So the rule is inverted, and that is the point: a retry is offered only
 * when XPay is CERTAIN nothing moved. Everything else, an unreachable API
 * included, goes to the order page and lets the webhook settle it.
 *
 * "Certain" is why these tests read the payment intent as well as the
 * session. A session stays OPEN and unpaid right up to the moment a charge
 * succeeds, so its own two
 * fields cannot tell a settling charge from an untouched session, and only
 * the PENDING charge underneath can.
 *
 * @see https://docs.xpay.app/en/api-reference/objects/checkout-session
 * @see https://docs.xpay.app/en/api-reference/objects/charge
 *
 * @package XPay_For_WooCommerce
 */

class OutcomeVerdictTest extends WP_UnitTestCase {

	/**
	 * The rule, read directly. It is separated from the endpoint on purpose:
	 * what it decides is whether a shopper may be charged twice, and that
	 * deserves to be readable without a transport in the way.
	 *
	 * @param array|null $session What XPay says, or null when it could not
	 *                            be read at all.
	 */
	private function verdict( ?array $session ): string {
		$method = new ReflectionMethod( 'XPay_Checkout_Elements', 'verdict_for' );
		$method->setAccessible( true );
		return (string) $method->invoke( null, $session );
	}

	/**
	 * A session the shopper could still pay, carrying the attempts named.
	 *
	 * Open, unpaid and unexpired throughout: that is the whole point. Every
	 * one of these payloads passes the session's own two fields, so what
	 * each test is actually reading is the attempts underneath.
	 *
	 * Shaped after what `GET /checkout/sessions/:id` returns, which expands
	 * `paymentIntent.charges[]`.
	 *
	 * @param string[] $statuses One wire charge status per attempt, oldest first.
	 */
	private function session_with_charges( array $statuses ): array {
		$charges = array();
		foreach ( $statuses as $index => $status ) {
			$charges[] = array(
				'id'     => 'ch_test_' . $index,
				'status' => $status,
			);
		}

		return array(
			'status'        => 'open',
			'paymentStatus' => 'unpaid',
			'isExpired'     => false,
			'paymentIntent' => array(
				'id'      => 'pi_test_verdict',
				'charges' => $charges,
			),
		);
	}

	/* ── The three answers ───────────────────────────────────────────── */

	public function test_a_paid_session_is_paid(): void {
		$this->assertSame( 'paid', $this->verdict( array( 'status' => 'complete', 'paymentStatus' => 'paid' ) ) );
	}

	/**
	 * The only verdict that hands the button back, and it needs all of it:
	 * a session that can still be paid, and no attempt still settling under
	 * it. This is the ordinary declined card, which is the case the retry
	 * exists for. A decline is written FAILED, never left PENDING, so the shopper
	 * gets their button back.
	 */
	public function test_an_unpaid_open_session_with_only_a_declined_attempt_may_be_retried(): void {
		$this->assertSame(
			'unpaid',
			$this->verdict( $this->session_with_charges( array( 'FAILED' ) ) )
		);
	}

	/**
	 * And a session nobody has tried to pay yet. The response always carries
	 * the key and sets it null when there is no intent, so a null here is a
	 * gap: no intent, no charge, nothing in flight.
	 */
	public function test_an_unpaid_open_session_with_no_intent_at_all_may_be_retried(): void {
		$session                   = $this->session_with_charges( array() );
		$session['paymentIntent'] = null;

		$this->assertSame( 'unpaid', $this->verdict( $session ) );
	}

	/* ── Everything else fails safe ──────────────────────────────────── */

	/**
	 * Unpaid on a session nothing more can be done with. Offering a retry
	 * here walks the shopper into a wall.
	 */
	public function test_an_unpaid_but_spent_session_is_not_a_retry(): void {
		$this->assertSame( 'unknown', $this->verdict( array( 'status' => 'complete', 'paymentStatus' => 'unpaid' ) ) );
	}

	public function test_an_expired_session_is_not_a_retry(): void {
		$this->assertSame(
			'unknown',
			$this->verdict( array( 'status' => 'open', 'paymentStatus' => 'unpaid', 'isExpired' => true ) )
		);
	}

	/**
	 * The case the whole change exists for. XPay cannot be reached, so
	 * nothing is known — and "nothing known" must never read as "safe to
	 * charge again".
	 */
	public function test_an_unreachable_xpay_never_offers_a_retry(): void {
		$this->assertSame( 'unknown', $this->verdict( null ) );
	}

	/**
	 * THE ONE THIS FILE EXISTS FOR.
	 *
	 * A charge that is still settling leaves the session exactly as an
	 * untouched one: OPEN, unpaid, unexpired. COMPLETE and PAID are written
	 * together at the very end,
	 * so nothing above tells them apart, and the shopper here must NOT be
	 * offered the button back over money that may already be moving.
	 *
	 */
	public function test_a_session_still_settling_is_not_a_retry(): void {
		$this->assertSame(
			'unknown',
			$this->verdict( $this->session_with_charges( array( 'PENDING' ) ) )
		);
	}

	/** One settled attempt does not clear a second one still in flight. */
	public function test_a_retry_settling_behind_a_decline_is_not_a_retry(): void {
		$this->assertSame(
			'unknown',
			$this->verdict( $this->session_with_charges( array( 'FAILED', 'PENDING' ) ) )
		);
	}

	/**
	 * Absent is not empty, the same rule XPay_Refundable reads charges by.
	 * An intent whose charges were not expanded says nothing about them, and
	 * "nothing" is not "nothing is moving".
	 */
	public function test_an_intent_without_its_charges_is_not_a_retry(): void {
		$session                  = $this->session_with_charges( array() );
		$session['paymentIntent'] = array( 'id' => 'pi_test_unexpanded' );

		$this->assertSame( 'unknown', $this->verdict( $session ) );
	}

	/** And an attempt whose status cannot be read may be the moving one. */
	public function test_a_charge_without_a_status_is_not_a_retry(): void {
		$session                  = $this->session_with_charges( array() );
		$session['paymentIntent'] = array( 'charges' => array( array( 'id' => 'ch_test_mute' ) ) );

		$this->assertSame( 'unknown', $this->verdict( $session ) );
	}

	/**
	 * A payload with no paymentIntent key at all is not a session this
	 * plugin recognises, and an unrecognised payload is never a retry.
	 */
	public function test_a_session_missing_the_intent_key_is_not_a_retry(): void {
		$this->assertSame(
			'unknown',
			$this->verdict( array( 'status' => 'open', 'paymentStatus' => 'unpaid', 'isExpired' => false ) )
		);
	}

	/** A payload with nothing in it says nothing, and says it safely. */
	public function test_a_session_that_says_nothing_is_not_a_retry(): void {
		$this->assertSame( 'unknown', $this->verdict( array() ) );
	}

	/* ── Who is allowed to ask ───────────────────────────────────────── */

	/**
	 * The session an order owns, resolved the way the endpoint resolves it.
	 *
	 * @param int    $order_id Order the browser named.
	 * @param string $key      The order key it presented.
	 */
	private function owned( int $order_id, string $key ): string {
		$method = new ReflectionMethod( 'XPay_Checkout_Elements', 'owned_session_id' );
		$method->setAccessible( true );
		return (string) $method->invoke( null, $order_id, $key );
	}

	/** An order paired with its own key is the shopper who placed it. */
	public function test_an_order_with_its_key_yields_its_session() {
		$order = wc_create_order();
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_owned' );
		$order->save();

		$this->assertSame( 'cs_test_owned', $this->owned( $order->get_id(), $order->get_order_key() ) );
	}

	/**
	 * And anyone else is not.
	 *
	 * The nonce this endpoint checks proves only that the request came from
	 * a page carrying one, and every visitor to the checkout has that. With
	 * the id alone accepted, a stranger could walk the order ids and read
	 * back whether each was paid, spending a live session read against the
	 * API on every probe.
	 */
	public function test_an_order_without_its_key_yields_nothing() {
		$order = wc_create_order();
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_owned' );
		$order->save();

		$this->assertSame( '', $this->owned( $order->get_id(), '' ), 'No key at all was accepted.' );
		$this->assertSame( '', $this->owned( $order->get_id(), 'wc_order_guessed' ), 'A wrong key was accepted.' );
	}

	/** An id nothing answers to is not an error, just nothing. */
	public function test_an_unknown_order_yields_nothing() {
		$this->assertSame( '', $this->owned( 999999, 'wc_order_anything' ) );
	}
}
