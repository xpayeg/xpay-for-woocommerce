<?php
/**
 * Pins the webhook controller's routing contract: ownership (IDOR),
 * dedupe, lock behavior, and forward compatibility. The claimable set
 * IS the contract — a new code path must not loosen any of these.
 *
 * @package XPay_For_WooCommerce
 */

class WebhookApplyContractTest extends ContractTestCase {

	/** An order wired the way process_payment leaves it. */
	private function wiredOrder( int $id = 14 ): WC_Order {
		$order = $this->makeOrder( $id, array( 'total' => '290.00' ) );
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_contract' );
		return $order;
	}

	public function test_webhook_health_stamp_is_per_plane() {
		// Name-pinning on purpose: uninstall.php hardcodes these strings
		// (it runs standalone by WordPress convention), so a rename here
		// must fail a test until the uninstall list moves in step.
		XPay_Webhook_State::record_success( false );
		$this->assertGreaterThan( 0, (int) get_option( 'xpay_wc_wh_test_last_success_at', 0 ) );
		$this->assertSame(
			0,
			(int) get_option( 'xpay_wc_wh_live_last_success_at', 0 ),
			'One shared stamp is how a test event paints the live health row green.'
		);

		XPay_Webhook_State::record_failure( true, XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID );
		$this->assertSame( XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID, get_option( 'xpay_wc_wh_live_last_error' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'xpay_wc_wh_live_monitor_began_at', 0 ) );

		// The verdict is the ORDER of the two stamps: test succeeded last
		// (healthy), live failed with no success ever (never worked).
		$this->assertSame( 1, XPay_Webhook_State::status_code( false ) );
		$this->assertSame( 4, XPay_Webhook_State::status_code( true ) );
	}

	public function test_completed_event_marks_paid_and_records_event_id() {
		$order = $this->wiredOrder();

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );

		$this->assertTrue( $order->paid );
		$this->assertContains( 'evt_1', $order->get_meta( XPay_Constants::META_PROCESSED_EVENTS ) );
	}

	public function test_duplicate_event_id_is_ignored() {
		$order = $this->wiredOrder();

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );
		$notes = count( $order->notes );
		$order->paid = false; // Even if state regressed, the dedupe alone must block a replay.

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );

		$this->assertFalse( $order->paid, 'A replayed event id must be a no-op.' );
		$this->assertCount( $notes, $order->notes );
	}

	public function test_ownership_mismatch_throws_and_applies_nothing() {
		$order = $this->wiredOrder();
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_DIFFERENT' );

		try {
			$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );
			$this->fail( 'Expected the IDOR guard to throw.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::ORDER_MISMATCH, $e->get_error_code() );
		}

		$this->assertFalse( $order->paid );
		$this->assertSame( '', $order->get_meta( XPay_Constants::META_PROCESSED_EVENTS ) );
	}

	public function test_order_without_stored_session_id_fails_ownership() {
		$this->makeOrder( 14, array( 'total' => '290.00' ) ); // No META_SESSION_ID at all.

		$this->expectException( XPay_Api_Exception::class );
		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );
	}

	public function test_unknown_event_types_are_acknowledged_untouched() {
		$order = $this->wiredOrder();

		$this->applyEvent( 'payment_intent.arrived_from_the_future', 'evt_1', $this->paidSession() );

		$this->assertFalse( $order->paid, 'Unsubscribed types are forward-compatibility no-ops.' );
	}

	/**
	 * A session event whose order does not exist yet must FAIL the
	 * delivery, never acknowledge it: acknowledged-and-dropped loses a
	 * payment permanently when delivery outruns Place Order.
	 */
	public function test_missing_order_fails_the_delivery_for_redelivery() {
		try {
			$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );
			$this->fail( 'Expected the order-not-found refusal to throw.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_ORDER_NOT_FOUND, $e->get_error_code() );
		}

		$this->assertStageFired( 'webhook.order_not_found' );
		$this->assertCount( 0, $GLOBALS['xpay_test_scheduled'], 'Nothing may queue locally; the platform retries.' );
	}

	/**
	 * The one quiet exception: a session that expired unpaid with no order
	 * is a shopper who reached the payment fields and did not buy. The
	 * ordinary outcome of a checkout, acknowledged so redelivery cannot
	 * burn XPay's retry schedule, and recorded at the level it deserves.
	 */
	public function test_abandoned_cart_expiry_is_acknowledged_quietly() {
		$this->applyEvent(
			XPay_Event_Names::CHECKOUT_SESSION_EXPIRED,
			'evt_1',
			$this->paidSession( array( 'paymentStatus' => XPay_Payment_Status::UNPAID ) )
		);

		$this->assertStageFired( 'webhook.abandoned_cart_expired' );
		$this->assertStageNotFired( 'webhook.order_not_found' );
	}

	/**
	 * An expired session that says PAID with no order is money that cannot
	 * be located: never the quiet path.
	 */
	public function test_paid_expiry_with_no_order_is_never_quiet() {
		try {
			$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_EXPIRED, 'evt_1', $this->paidSession() );
			$this->fail( 'Expected the order-not-found refusal to throw.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_ORDER_NOT_FOUND, $e->get_error_code() );
		}
		$this->assertStageNotFired( 'webhook.abandoned_cart_expired' );
	}

	public function test_foreign_gateway_order_is_never_touched() {
		$foreign = $this->makeOrder( 14, array( 'payment_method' => 'cod' ) );
		try {
			$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_2', $this->paidSession() );
			$this->fail( 'Expected the order-not-found refusal to throw.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_ORDER_NOT_FOUND, $e->get_error_code() );
		}
		$this->assertFalse( $foreign->paid, 'An order paid by another gateway is never ours to touch.' );
	}

	public function test_busy_lock_throws_so_xpay_retries() {
		$this->wiredOrder();
		$GLOBALS['wpdb']->lock_results = array( '0' );

		try {
			$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );
			$this->fail( 'A busy lock must surface as an error (500 to the retry engine).' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertNotSame( XPay_Error_Codes::ORDER_MISMATCH, $e->get_error_code() );
		}
	}

	public function test_errored_lock_proceeds_unserialized_and_logs() {
		$order = $this->wiredOrder();
		$GLOBALS['wpdb']->lock_results = array( null );

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );

		$this->assertTrue( $order->paid, 'A host without GET_LOCK must degrade, not dead-end payment confirmation.' );
		$this->assertStageFired( 'order_lock.unavailable' );
	}

	public function test_expired_event_fails_pending_order() {
		$order = $this->wiredOrder();

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_EXPIRED, 'evt_1', $this->paidSession() );

		$this->assertSame( 'failed', $order->status, 'FAILED keeps the pay link alive for the returning shopper.' );
	}

	/* ── Superseded-session events (design decision Q4) ─────────────── */

	public function test_superseded_paid_event_parks_on_hold_instead_of_dropping() {
		$order = $this->wiredOrder();
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_NEW' );
		$order->update_meta_data( XPay_Constants::META_SUPERSEDED_SESSIONS, array( 'cs_test_contract' ) );

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );

		$this->assertFalse( $order->paid, 'A superseded payment may be for an outdated total; it never auto-completes.' );
		$this->assertSame( 'on-hold', $order->status );
		$this->assertSame( 'pi_contract_1', $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ), 'The intent is recorded so refunds and the expiry guard see real money.' );
		$this->assertStageFired( 'order.superseded_paid' );
	}

	public function test_superseded_paid_on_a_paid_order_notes_the_double_payment() {
		$order = $this->wiredOrder();
		$order->paid   = true;
		$order->status = 'processing';
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_NEW' );
		$order->update_meta_data( XPay_Constants::META_PAYMENT_INTENT, 'pi_the_real_one' );
		$order->update_meta_data( XPay_Constants::META_SUPERSEDED_SESSIONS, array( 'cs_test_contract' ) );

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_1', $this->paidSession() );

		$this->assertSame( 'processing', $order->status, 'The real payment stands; the duplicate is a note, not a state change.' );
		$this->assertSame( 'pi_the_real_one', $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ), 'The duplicate must not overwrite the real payment intent.' );
		$this->assertStageFired( 'order.superseded_double_paid' );
		$this->assertStringContainsString( 'SECOND payment', end( $order->notes ) );
	}

	public function test_superseded_expired_event_is_ignored() {
		$order         = $this->wiredOrder();
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_NEW' );
		$order->update_meta_data( XPay_Constants::META_SUPERSEDED_SESSIONS, array( 'cs_test_contract' ) );

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_EXPIRED, 'evt_1', $this->paidSession() );

		$this->assertSame( 'pending', $order->status, 'The old session dying is expected; the order\'s current state is untouchable.' );
		$this->assertStageFired( 'webhook.superseded_expired_ignored' );
	}

	/* ── Declined attempts (design decision Q2) ─────────────────────── */

	/** A payment_intent.payment_failed payload the platform would send. */
	private function failedIntent( array $extra = array() ): array {
		return array_merge(
			array(
				'id'                => 'pi_contract_1',
				'object'            => 'payment_intent',
				'status'            => 'FAILED',
				'checkoutSessionId' => 'cs_test_contract',
				'metadata'          => array( 'wc_order_id' => '14' ),
				'lastPaymentError'  => array(
					'code'            => 'card_declined',
					'declineCode'     => 'insufficient_funds',
					'message'         => 'Your card was declined.',
					'merchantMessage' => 'The issuer declined the charge: insufficient funds.',
				),
			),
			$extra
		);
	}

	public function test_failed_attempt_leaves_a_note_and_no_status_change() {
		$order = $this->wiredOrder();

		$this->applyEvent( XPay_Event_Names::PAYMENT_INTENT_FAILED, 'evt_1', $this->failedIntent() );

		$this->assertSame( 'pending', $order->status, 'The shopper may still succeed; declines never move order state.' );
		$this->assertFalse( $order->paid );
		$this->assertStringContainsString( 'insufficient_funds', end( $order->notes ) );
		$this->assertStageFired( 'order.payment_failed' );
	}

	public function test_failed_attempt_on_a_paid_order_is_skipped() {
		$order       = $this->wiredOrder();
		$order->paid = true;

		$this->applyEvent( XPay_Event_Names::PAYMENT_INTENT_FAILED, 'evt_1', $this->failedIntent() );

		$this->assertCount( 0, $order->notes, 'A straggler decline after success is noise.' );
	}

	public function test_failed_attempt_for_a_foreign_session_fails_ownership() {
		$this->wiredOrder();

		$this->expectException( XPay_Api_Exception::class );
		$this->applyEvent( XPay_Event_Names::PAYMENT_INTENT_FAILED, 'evt_1', $this->failedIntent( array( 'checkoutSessionId' => 'cs_test_SOMEONE_ELSES' ) ) );
	}

	public function test_expiry_note_carries_the_decline_history() {
		$order = $this->wiredOrder();

		$this->applyEvent(
			XPay_Event_Names::CHECKOUT_SESSION_EXPIRED,
			'evt_1',
			$this->paidSession(
				array(
					'paymentIntent' => array(
						'id'               => 'pi_contract_1',
						'charges'          => array(
							array( 'status' => 'FAILED' ),
							array( 'status' => 'FAILED' ),
							array( 'status' => 'CANCELED' ),
						),
						'lastPaymentError' => array(
							'code'        => 'card_declined',
							'declineCode' => 'insufficient_funds',
							'message'     => 'Your card was declined.',
						),
					),
				)
			)
		);

		$this->assertSame( 'failed', $order->status );
		$note = end( $order->notes );
		$this->assertStringContainsString( '2 payment attempts', $note, 'Only FAILED charges count as declines.' );
		$this->assertStringContainsString( 'insufficient_funds', $note );
	}

	public function test_expiry_note_stays_plain_when_nothing_was_attempted() {
		$order = $this->wiredOrder();

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_EXPIRED, 'evt_1', $this->paidSession( array( 'paymentIntent' => null ) ) );

		$this->assertSame( 'failed', $order->status );
		$this->assertStringNotContainsString( 'declined', end( $order->notes ), 'No attempts means no decline story to tell.' );
	}

	/* ── Refund mirroring (design decision Q3) ──────────────────────── */

	/** An order that paid through intent pi_contract_1, like mark_paid leaves it. */
	private function paidOrderWithIntent(): WC_Order {
		$order         = $this->wiredOrder();
		$order->paid   = true;
		$order->status = 'processing';
		$order->update_meta_data( XPay_Constants::META_PAYMENT_INTENT, 'pi_contract_1' );
		return $order;
	}

	/** A charge.refunded payload the platform would send. */
	private function refundedCharge( array $refunds ): array {
		return array(
			'id'              => 'ch_contract_1',
			'object'          => 'charge',
			'paymentIntentId' => 'pi_contract_1',
			'currency'        => 'EGP',
			'amount'          => 29000,
			'refunds'         => $refunds,
		);
	}

	public function test_dashboard_refund_is_mirrored_once() {
		$order = $this->paidOrderWithIntent();

		$event = $this->refundedCharge(
			array(
				array(
					'id'       => 're_dash_1',
					'status'   => XPay_Refund_Status::SUCCEEDED,
					'amount'   => 5000,
					'currency' => 'EGP',
				),
			)
		);
		$this->applyEvent( XPay_Event_Names::CHARGE_REFUNDED, 'evt_1', $event );

		$this->assertCount( 1, $GLOBALS['xpay_test_wc_refunds'] );
		$this->assertSame( '50.00', $GLOBALS['xpay_test_wc_refunds'][0]['amount'] );
		$this->assertFalse( $GLOBALS['xpay_test_wc_refunds'][0]['refund_payment'], 'Mirroring records money the platform already moved; it must never move it again.' );
		$this->assertContains( 're_dash_1', $order->get_meta( XPay_Constants::META_REFUND_IDS ) );
		$this->assertStageFired( 'refund.mirrored' );

		// A later charge.refunded re-carries the whole refunds list; the
		// ledger keeps the already-mirrored one from double-recording.
		$this->applyEvent( XPay_Event_Names::CHARGE_REFUNDED, 'evt_2', $event );
		$this->assertCount( 1, $GLOBALS['xpay_test_wc_refunds'] );
	}

	public function test_plugin_issued_refunds_are_not_mirrored_back() {
		$order = $this->paidOrderWithIntent();
		$order->update_meta_data( XPay_Constants::META_REFUND_IDS, array( 're_plugin_1' ) );

		$this->applyEvent(
			XPay_Event_Names::CHARGE_REFUNDED,
			'evt_1',
			$this->refundedCharge(
				array(
					array(
						'id'       => 're_plugin_1',
						'status'   => XPay_Refund_Status::SUCCEEDED,
						'amount'   => 5000,
						'currency' => 'EGP',
					),
				)
			)
		);

		$this->assertCount( 0, $GLOBALS['xpay_test_wc_refunds'], 'The plugin\'s own refund echoing back is not a new refund.' );
	}

	public function test_non_egp_mirror_uses_the_presentment_amount() {
		$order           = $this->paidOrderWithIntent();
		$order->currency = 'USD';

		$this->applyEvent(
			XPay_Event_Names::CHARGE_REFUNDED,
			'evt_1',
			$this->refundedCharge(
				array(
					array(
						'id'                 => 're_dash_1',
						'status'             => XPay_Refund_Status::SUCCEEDED,
						'amount'             => 5000,
						'currency'           => 'EGP',
						'presentmentDetails' => array(
							'amount'   => 100,
							'currency' => 'USD',
						),
					),
				)
			)
		);

		$this->assertSame( '1.00', $GLOBALS['xpay_test_wc_refunds'][0]['amount'], 'The per-refund presentment mirror is the only honest USD number.' );
	}

	public function test_unmatchable_currency_mirrors_as_a_note_only() {
		$order           = $this->paidOrderWithIntent();
		$order->currency = 'USD';

		$this->applyEvent(
			XPay_Event_Names::CHARGE_REFUNDED,
			'evt_1',
			$this->refundedCharge(
				array(
					array(
						'id'       => 're_dash_1',
						'status'   => XPay_Refund_Status::SUCCEEDED,
						'amount'   => 5000,
						'currency' => 'EGP',
					),
				)
			)
		);

		$this->assertCount( 0, $GLOBALS['xpay_test_wc_refunds'], 'A guessed conversion is worse than no record.' );
		$this->assertStringContainsString( 're_dash_1', end( $order->notes ) );
		$this->assertContains( 're_dash_1', $order->get_meta( XPay_Constants::META_REFUND_IDS ), 'The note still claims the ledger slot, or every redelivery re-notes it.' );
	}

	public function test_pending_refunds_are_not_mirrored() {
		$this->paidOrderWithIntent();

		$this->applyEvent(
			XPay_Event_Names::CHARGE_REFUNDED,
			'evt_1',
			$this->refundedCharge(
				array(
					array(
						'id'       => 're_pending_1',
						'status'   => XPay_Refund_Status::PENDING,
						'amount'   => 5000,
						'currency' => 'EGP',
					),
				)
			)
		);

		$this->assertCount( 0, $GLOBALS['xpay_test_wc_refunds'], 'Only settled money mirrors.' );
	}

	public function test_refund_failed_event_leaves_a_note() {
		$order = $this->paidOrderWithIntent();

		$this->applyEvent(
			XPay_Event_Names::REFUND_FAILED,
			'evt_1',
			array(
				'id'              => 're_dash_1',
				'object'          => 'refund',
				'paymentIntentId' => 'pi_contract_1',
				'status'          => XPay_Refund_Status::FAILED,
				'failureReason'   => 'expired_or_canceled_card',
			)
		);

		$this->assertStringContainsString( 'expired_or_canceled_card', end( $order->notes ) );
		$this->assertStageFired( 'refund.failed_event' );
	}

	/**
	 * An intent no order carries applies to no order and fails the
	 * delivery, so XPay redelivers. "No order carries it" and "no order
	 * will ever carry it" are different states the refund path cannot tell
	 * apart at delivery time: the order side of the join is written by
	 * mark_paid() and apply_superseded_paid(), so a refund taken seconds
	 * after a payment arrives first, and the redelivery finds the order.
	 */
	public function test_refund_event_with_unknown_intent_fails_for_redelivery() {
		$this->paidOrderWithIntent();

		try {
			$this->applyEvent(
				XPay_Event_Names::CHARGE_REFUNDED,
				'evt_1',
				array(
					'id'              => 'ch_foreign',
					'paymentIntentId' => 'pi_NOBODY',
					'refunds'         => array(
						array(
							'id'       => 're_x',
							'status'   => XPay_Refund_Status::SUCCEEDED,
							'amount'   => 5000,
							'currency' => 'EGP',
						),
					),
				)
			);
			$this->fail( 'Expected the order-not-found refusal to throw.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_ORDER_NOT_FOUND, $e->get_error_code() );
		}

		$this->assertCount( 0, $GLOBALS['xpay_test_wc_refunds'], 'A refund landed on an order that does not carry the intent.' );
		$this->assertCount( 0, $GLOBALS['xpay_test_scheduled'], 'Nothing may queue locally; the platform retries.' );
	}

	public function test_processed_event_list_is_capped() {
		$order = $this->wiredOrder();

		$total = XPay_Webhook_Controller::PROCESSED_EVENTS_KEPT + 3;
		for ( $i = 1; $i <= $total; $i++ ) {
			$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_' . $i, $this->paidSession() );
		}

		$processed = $order->get_meta( XPay_Constants::META_PROCESSED_EVENTS );
		$this->assertCount( XPay_Webhook_Controller::PROCESSED_EVENTS_KEPT, $processed );
		$this->assertContains( 'evt_' . $total, $processed, 'Newest ids survive the cap.' );
		$this->assertNotContains( 'evt_1', $processed, 'Oldest ids age out.' );
	}

	/* ── Deferred payments (the Fawry story) ─────────────────────────── */

	/**
	 * A completed but unpaid deferred session must never mark the order paid.
	 */
	public function test_completed_unpaid_parks_awaiting_and_never_completes() {
		$order = $this->wiredOrder();

		$this->applyEvent(
			XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
			'evt_f1',
			$this->paidSession( array( 'paymentStatus' => XPay_Payment_Status::UNPAID ) )
		);

		$this->assertFalse( $order->paid, 'A completed-but-unpaid session must never complete the order.' );
		$this->assertSame( 'on-hold', $order->status );
		$this->assertNotSame( '', (string) $order->get_meta( XPay_Constants::META_AWAITING_PAYMENT ) );
		$this->assertSame( '', (string) $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ), 'No money moved, so nothing may claim it did.' );
		$this->assertStageFired( 'order.awaiting_payment' );
		$this->assertStageNotFired( 'order.paid' );
	}

	public function test_redelivered_completed_unpaid_does_not_renote() {
		$order = $this->wiredOrder();

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_f1', $this->paidSession( array( 'paymentStatus' => XPay_Payment_Status::UNPAID ) ) );
		$notes = count( $order->notes );

		// A fresh event id passes the id-keyed dedupe; the awaiting marker
		// is what must hold.
		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_f2', $this->paidSession( array( 'paymentStatus' => XPay_Payment_Status::UNPAID ) ) );

		$this->assertCount( $notes, $order->notes );
		$this->assertFalse( $order->paid );
	}

	public function test_async_success_completes_the_awaiting_order() {
		$order = $this->wiredOrder();
		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_f1', $this->paidSession( array( 'paymentStatus' => XPay_Payment_Status::UNPAID ) ) );

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED, 'evt_f2', $this->paidSession() );

		$this->assertTrue( $order->paid );
		$this->assertSame( 'processing', $order->status );
		$this->assertSame( 'pi_contract_1', $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ) );
		$this->assertStageFired( 'order.paid' );
	}

	public function test_async_failure_fails_the_awaiting_order_with_the_reason() {
		$order = $this->wiredOrder();
		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_f1', $this->paidSession( array( 'paymentStatus' => XPay_Payment_Status::UNPAID ) ) );

		$this->applyEvent(
			XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED,
			'evt_f2',
			$this->paidSession(
				array(
					'paymentStatus' => XPay_Payment_Status::UNPAID,
					'paymentIntent' => array(
						'id'               => 'pi_contract_1',
						'lastPaymentError' => array( 'merchantMessage' => 'The customer did not pay the reference before it expired' ),
					),
				)
			)
		);

		$this->assertFalse( $order->paid );
		$this->assertSame( 'failed', $order->status );
		$this->assertStringContainsString( 'did not pay the reference', end( $order->notes ) );
		$this->assertStageFired( 'order.async_payment_failed' );
	}

	public function test_expired_never_touches_an_awaiting_order() {
		$order = $this->wiredOrder();
		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, 'evt_f1', $this->paidSession( array( 'paymentStatus' => XPay_Payment_Status::UNPAID ) ) );

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_EXPIRED, 'evt_f2', $this->paidSession( array( 'paymentStatus' => XPay_Payment_Status::UNPAID ) ) );

		$this->assertSame( 'on-hold', $order->status, 'The async_payment events own an awaiting order; expiry must not fail it.' );
	}

	public function test_async_success_on_a_superseded_session_parks_for_review() {
		$order = $this->wiredOrder();
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_NEWER' );
		$order->update_meta_data( XPay_Constants::META_SUPERSEDED_SESSIONS, array( 'cs_test_contract' ) );

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED, 'evt_f2', $this->paidSession() );

		$this->assertFalse( $order->paid );
		$this->assertSame( 'on-hold', $order->status );
		$this->assertStageFired( 'order.superseded_paid' );
	}

	public function test_async_failure_on_a_superseded_session_is_ignored() {
		$order = $this->wiredOrder();
		$order->update_meta_data( XPay_Constants::META_SESSION_ID, 'cs_test_NEWER' );
		$order->update_meta_data( XPay_Constants::META_SUPERSEDED_SESSIONS, array( 'cs_test_contract' ) );

		$this->applyEvent( XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED, 'evt_f2', $this->paidSession( array( 'paymentStatus' => XPay_Payment_Status::UNPAID ) ) );

		$this->assertSame( 'pending', $order->status );
		$this->assertStageFired( 'webhook.superseded_async_failed_ignored' );
	}
}
