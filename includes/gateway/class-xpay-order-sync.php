<?php
/**
 * XPay_Order_Sync
 *
 * The ONLY writer of XPay-driven order-state transitions. Both async paths
 * (webhook events) and sync paths (thank-you page re-check) funnel through
 * the same idempotent methods here, so "payment_complete exactly once" is
 * enforced in one place instead of N call sites.
 *
 * Ownership rule (IDOR guard): a session id arriving from
 * outside is only trusted for an order when it exactly matches the session
 * id THIS plugin stored on THAT order. Existence is never enough.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Order_Sync {

	/**
	 * How long an XPay order that reached the platform is protected from
	 * WooCommerce's unpaid-order sweep.
	 *
	 * WooCommerce cancels unpaid orders on the stock-hold timer, which
	 * defaults to 60 minutes (`wc-order-functions.php:1080`). The sweep
	 * selects a single status, `pending`
	 * (`OrdersTableDataStore::get_unpaid_orders_gmt`), and every XPay
	 * outcome leaves `pending` on its own: a paid session completes the
	 * order, a deferred (Fawry) session parks it on-hold awaiting payment,
	 * an expired session fails it. On-hold, processing and failed are all
	 * invisible to the sweep, no grace needed.
	 *
	 * So this protects exactly one window: the order is `pending` and the
	 * webhook that would move it has not arrived yet. That window is
	 * minutes on the happy path and bounded by XPay's own retry schedule on
	 * the unhappy one. Two hours covers it with room; the session's
	 * `checkout.session.expired` (platform default lifetime, 24 hours)
	 * arrives on its own clock and fails the order properly through
	 * mark_expired().
	 *
	 * A deferred payment completes its session as unpaid when the reference
	 * is issued, so the order leaves `pending` before the reference expires.
	 */
	const UNPAID_GRACE_SECONDS = 2 * HOUR_IN_SECONDS;

	/** Meta flag so the explanatory note is written once, not once per cron run. */
	const META_CANCEL_DEFERRED = '_xpay_cancel_deferred';

	/**
	 * Keep WooCommerce from cancelling an order the shopper is still paying.
	 *
	 * Filters core's per-order decision rather than the global interval:
	 * stretching `woocommerce_hold_stock_minutes` would change the timer for
	 * every gateway on the store, which is not ours to do.
	 *
	 * @param bool     $should_cancel Core's decision.
	 * @param WC_Order $order         The unpaid order.
	 * @return bool
	 */
	public static function should_cancel_unpaid( $should_cancel, $order ): bool {
		if ( ! $should_cancel || ! $order instanceof WC_Order ) {
			return (bool) $should_cancel;
		}
		if ( ! XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) ) {
			return true;
		}
		if ( $order->is_paid() ) {
			return false;
		}

		// No session means the shopper never reached XPay, so there is no
		// payment in flight to protect and core's timer is right.
		if ( '' === (string) $order->get_meta( XPay_Constants::META_SESSION_ID ) ) {
			return true;
		}

		$created = $order->get_date_created();
		$age     = $created ? ( time() - $created->getTimestamp() ) : PHP_INT_MAX;

		/**
		 * How long to protect an in-flight XPay payment from the unpaid-order
		 * sweep, in seconds.
		 *
		 * @param int      $seconds Grace period.
		 * @param WC_Order $order   The unpaid order.
		 */
		$grace = (int) apply_filters( 'xpay_unpaid_order_grace_seconds', self::UNPAID_GRACE_SECONDS, $order );

		if ( $age >= $grace ) {
			return true;
		}

		if ( '' === (string) $order->get_meta( self::META_CANCEL_DEFERRED ) ) {
			$order->update_meta_data( self::META_CANCEL_DEFERRED, (string) time() );
			$order->add_order_note(
				sprintf(
					/* translators: %s is a human-readable duration, for example "2 hours". */
					__( 'XPay: automatic cancellation held back. A payment session for this order is still open at XPay and its result has not arrived yet. WooCommerce will cancel the order after %s if it is still unpaid.', 'xpay-for-woocommerce' ),
					human_time_diff( 0, $grace )
				)
			);
			$order->save();
		}

		XPay_Logger::event( 'order.cancel_deferred', array( 'order_id' => $order->get_id() ) );

		return false;
	}

	/**
	 * Route a COMPLETE session to the transition its paymentStatus earns.
	 *
	 * `completed` is not a paid event. A deferred processor (Fawry)
	 * completes the session with paymentStatus `unpaid` the moment the
	 * payment reference is issued,
	 * and the money verdict arrives later on async_payment_succeeded or
	 * async_payment_failed. Completing the order here would ship goods for
	 * an unpaid reference.
	 *
	 * An absent paymentStatus routes to mark_paid: fail open on shape,
	 * closed on value, the same discipline as the amount check — and
	 * mark_paid re-reads the field itself, so a present-but-unpaid value
	 * can never complete through either door.
	 *
	 * @param WC_Order $order   Target order (ownership already verified).
	 * @param array    $session COMPLETE session payload.
	 * @param string   $via     'webhook'|'thankyou'|'session-check' — recorded for audit.
	 */
	public static function apply_completed( WC_Order $order, array $session, string $via ): void {
		$status = isset( $session['paymentStatus'] ) && is_string( $session['paymentStatus'] ) ? $session['paymentStatus'] : '';
		if ( XPay_Payment_Status::UNPAID === $status ) {
			self::mark_awaiting_payment( $order, $session );
			return;
		}
		self::mark_paid( $order, $session, $via );
	}

	/**
	 * Mark an order paid from a COMPLETE/PAID session. Idempotent: safe for
	 * duplicate webhook deliveries and the webhook/thank-you race.
	 *
	 * @param WC_Order $order      Target order (ownership already verified).
	 * @param array    $session    Session object (webhook data.object or API fetch).
	 * @param string   $via        'webhook'|'thankyou'|'session-check' — recorded for audit.
	 */
	public static function mark_paid( WC_Order $order, array $session, string $via ): void {
		if ( $order->is_paid() ) {
			return;
		}

		/*
		 * Money truth, before anything else: a session that states its
		 * paymentStatus and does not state `paid` has moved no money, and
		 * nothing downstream of this line may run on it. This gate exists
		 * so the deferred-payments bug cannot be reintroduced from ANY call
		 * site: apply_completed() routes unpaid sessions away before this,
		 * and this refuses whatever slips past a future caller.
		 * no_payment_required passes: a session that owes nothing is
		 * complete the moment it completes, and the amount check below
		 * still guards its numbers.
		 */
		$payment_status = isset( $session['paymentStatus'] ) && is_string( $session['paymentStatus'] ) ? $session['paymentStatus'] : '';
		if ( '' !== $payment_status && XPay_Payment_Status::PAID !== $payment_status && XPay_Payment_Status::NO_PAYMENT_REQUIRED !== $payment_status ) {
			XPay_Logger::error(
				'order.unpaid_session_refused',
				array(
					'order_id'       => $order->get_id(),
					'session_id'     => isset( $session['id'] ) ? (string) $session['id'] : '',
					'payment_status' => $payment_status,
					'via'            => $via,
				)
			);
			return;
		}

		$intent_id = '';
		if ( isset( $session['paymentIntent']['id'] ) && is_string( $session['paymentIntent']['id'] ) ) {
			$intent_id = $session['paymentIntent']['id'];
		} elseif ( isset( $session['paymentIntentId'] ) && is_string( $session['paymentIntentId'] ) ) {
			$intent_id = $session['paymentIntentId'];
		}

		if ( '' !== $intent_id ) {
			$order->update_meta_data( XPay_Constants::META_PAYMENT_INTENT, $intent_id );
		}

		self::remember_customer( $order, $session );

		/*
		 * A cancelled order is not a pending one that happens to be late.
		 * Someone closed it: the merchant deliberately, or WooCommerce's
		 * unpaid-order sweep once should_cancel_unpaid() stopped holding it
		 * back.
		 *
		 * payment_complete() would take it anyway. Core lists `cancelled`
		 * in OrderStatus::PAYMENT_COMPLETE_STATUSES alongside pending and
		 * failed, so the order would flip to processing, email the shopper
		 * that it is on its way, and be picked and shipped, with nobody
		 * having decided that.
		 *
		 * Refusing outright is worse: the money is real and would be
		 * recorded nowhere. So the payment is written down and the order is
		 * parked for a human, the same answer this method already gives a
		 * charge whose amount does not match.
		 *
		 * Stripe answers the same question differently, for a different
		 * case: they REFUND it (class-wc-stripe-webhook-handler.php:1921,
		 * shipped 10.9.0). That fits theirs, which is a shopper who
		 * cancelled their own order while the payment settled and wants the
		 * money back. Ours is a shopper who paid slowly and is owed goods,
		 * so returning their money would be the hostile answer.
		 */
		/*
		 * First money on this plane, for the setup screen. The plane comes
		 * from the session's OWN livemode stamp, never the gateway's
		 * current toggle — the same rule remember_customer() follows: the
		 * record decides which plane it belongs to.
		 *
		 * add_option, not update_option: this is the FIRST payment, and a
		 * later one must not move the timestamp.
		 */
		if ( isset( $session['livemode'] ) ) {
			add_option( XPay_Constants::first_paid_option( (bool) $session['livemode'] ), time(), '', false );
		}

		$parked = '' !== (string) $order->get_meta( XPay_Constants::META_PAID_AFTER_CANCEL )
			|| '' !== (string) $order->get_meta( XPay_Constants::META_SUPERSEDED_PARKED );
		if ( $order->has_status( 'cancelled' ) || $parked ) {
			if ( '' !== $intent_id && '' === (string) $order->get_transaction_id() ) {
				$order->set_transaction_id( $intent_id );
			}
			if ( $parked ) {
				// Already parked and already explained. A redelivery must
				// not re-note or re-log, but it must still not fall
				// through: on-hold is in PAYMENT_COMPLETE_STATUSES, so
				// falling through completes the order.
				$order->save();
				return;
			}
			$order->update_meta_data( XPay_Constants::META_PAID_AFTER_CANCEL, (string) time() );
			XPay_Logger::error(
				'order.paid_after_cancel',
				array(
					'order_id'   => $order->get_id(),
					'session_id' => isset( $session['id'] ) ? $session['id'] : '',
					'intent_id'  => $intent_id,
					'via'        => $via,
				)
			);
			// on-hold reduces stock again (core hooks
			// wc_maybe_reduce_stock_levels to it), which is right: the goods
			// are being held while a human decides. What it does not do is
			// tell the shopper their order is on its way.
			$order->update_status( 'on-hold', self::cancelled_note( $session ) );
			return;
		}

		// Money truth: the session says what was CHARGED, the order says
		// what is OWED, and they can drift — an admin editing the total
		// while the shopper holds a live pay page, or a session inflated by
		// something this store never priced. Completing on drifted numbers
		// would stamp the order fully paid for the wrong amount, silently.
		// Absent fields skip the check (the event is already
		// signature-verified; fail open on shape, closed on value);
		// present-but-different values park the order for a human. The
		// money is at XPay either way — the order just waits.
		if ( self::charged_amount_disagrees( $session, $order ) ) {
			/*
			 * A held order is the one a merchant will actually open, so it
			 * is the one that most needs the payment to be findable.
			 * payment_complete() is what normally records the transaction
			 * id, and this branch never reaches it — leaving the merchant
			 * to resolve an amount dispute with no link to the payment.
			 */
			if ( '' !== $intent_id && '' === (string) $order->get_transaction_id() ) {
				$order->set_transaction_id( $intent_id );
			}
			if ( ! $order->has_status( 'on-hold' ) ) {
				XPay_Logger::event(
					'order.amount_mismatch',
					array(
						'order_id'   => $order->get_id(),
						'session_id' => isset( $session['id'] ) ? $session['id'] : '',
						'via'        => $via,
					)
				);
				$order->update_status( 'on-hold', self::mismatch_note( $session, $order ) );
			} else {
				// Already held (earlier delivery or another reason): keep
				// the identifiers written above without re-noting.
				$order->save();
			}
			return;
		}

		// payment_complete() sets processing/completed, records the
		// transaction id, and reduces stock — WooCommerce's canonical
		// "money arrived" transition.
		$order->payment_complete( '' !== $intent_id ? $intent_id : (string) $order->get_meta( XPay_Constants::META_SESSION_ID ) );

		$source_label = __( 'thank-you page check', 'xpay-for-woocommerce' );
		if ( 'webhook' === $via ) {
			$source_label = __( 'webhook', 'xpay-for-woocommerce' );
		} elseif ( 'session-check' === $via ) {
			$source_label = __( 'payment session check', 'xpay-for-woocommerce' );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: payment source ("webhook", "thank-you page check" or "payment session check"), 2: XPay payment intent id. */
				__( 'XPay payment confirmed via %1$s. Payment intent: %2$s', 'xpay-for-woocommerce' ),
				$source_label,
				'' !== $intent_id ? $intent_id : '—'
			)
		);

		XPay_Logger::event(
			'order.paid',
			array(
				'order_id'   => $order->get_id(),
				'session_id' => isset( $session['id'] ) ? $session['id'] : '',
				'intent_id'  => $intent_id,
				'via'        => $via,
			)
		);
	}

	/**
	 * True when the session states a charged amount that does not equal
	 * the order's total. Presentment first, settlement second, per
	 * XPay_Money::session_charge(). Missing/malformed fields answer false:
	 * only a present-but-different value may block a payment.
	 *
	 * @param array    $session Session payload (webhook or API fetch).
	 * @param WC_Order $order   Order about to be marked paid.
	 */
	private static function charged_amount_disagrees( array $session, WC_Order $order ): bool {
		$charge = XPay_Money::session_charge( $session );
		if ( null === $charge ) {
			return false;
		}
		$expected = XPay_Money::to_minor( $order->get_total(), $order->get_currency() );

		return strtoupper( $order->get_currency() ) !== $charge['currency']
			|| $expected !== $charge['amount'];
	}

	/**
	 * Order note explaining an amount mismatch with both numbers, so the
	 * merchant can resolve it without opening a log.
	 *
	 * @param array    $session Session payload carrying the charged amount.
	 * @param WC_Order $order   Held order.
	 */
	private static function mismatch_note( array $session, WC_Order $order ): string {
		$charge   = XPay_Money::session_charge( $session );
		$currency = null === $charge ? $order->get_currency() : $charge['currency'];
		$amount   = null === $charge ? 0 : $charge['amount'];
		return sprintf(
			/* translators: 1: the amount XPay charged, 2: this order's total. */
			__( 'XPay charged %1$s but this order totals %2$s. Review the payment in your XPay dashboard, adjust the order if needed, then complete or refund it manually.', 'xpay-for-woocommerce' ),
			wc_price( XPay_Money::from_minor( (string) $amount, $currency ), array( 'currency' => $currency ) ),
			wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) )
		);
	}

	/**
	 * What the merchant reads on an order that was paid after it closed.
	 *
	 * Says what happened and what the two choices are. It deliberately does
	 * not recommend one: whether to ship or refund depends on why the order
	 * was cancelled, which only the merchant knows.
	 *
	 * @param array $session Session payload (webhook or API fetch).
	 */
	private static function cancelled_note( array $session ): string {
		$charge   = XPay_Money::session_charge( $session );
		$amount   = null === $charge
			? ''
			: wc_price( XPay_Money::from_minor( (string) $charge['amount'], $charge['currency'] ), array( 'currency' => $charge['currency'] ) );
		$sentence = '' === $amount
			? __( 'XPay took a payment for this order after it had already been cancelled.', 'xpay-for-woocommerce' )
			: sprintf(
				/* translators: %s is the amount XPay charged. */
				__( 'XPay took %s for this order after it had already been cancelled.', 'xpay-for-woocommerce' ),
				$amount
			);
		return $sentence . ' ' . __( 'The order is on hold so nothing ships automatically. Either complete it, or refund the payment from this order.', 'xpay-for-woocommerce' );
	}

	/**
	 * Persist the XPay Customer id from a paid session: on the order (for
	 * the support panel) and, for logged-in shoppers, on the user per mode
	 * so the next checkout sends customerId instead of re-creating.
	 *
	 * The mode comes from the session's OWN livemode stamp, never from the
	 * gateway's current settings toggle — the record decides its plane.
	 *
	 * @param WC_Order $order   Target order.
	 * @param array    $session Session payload (webhook or API fetch).
	 */
	private static function remember_customer( WC_Order $order, array $session ): void {
		$customer_id = '';
		if ( isset( $session['customer'] ) && is_string( $session['customer'] ) ) {
			$customer_id = $session['customer'];
		} elseif ( isset( $session['customer']['id'] ) && is_string( $session['customer']['id'] ) ) {
			$customer_id = $session['customer']['id'];
		}
		if ( '' === $customer_id || 0 !== strpos( $customer_id, 'cus_' ) ) {
			return;
		}

		$order->update_meta_data( XPay_Constants::META_CUSTOMER_ID, $customer_id );

		$user_id = $order->get_user_id();
		if ( $user_id > 0 && isset( $session['livemode'] ) ) {
			update_user_meta( $user_id, XPay_Constants::customer_user_meta_key( (bool) $session['livemode'] ), $customer_id );
		}
	}

	/**
	 * Re-read an order from storage, discarding this request's cached copy.
	 * Call ONLY while holding the order's XPay_Order_Lock: the point is to
	 * see the previous lock holder's save, which the per-request caches
	 * (HPOS's OrderCache and the legacy post/meta cache alike) would hide.
	 *
	 * @param int $order_id Order to reload.
	 */
	public static function reload( int $order_id ): ?WC_Order {
		self::forget_cached_order( $order_id );
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Drop every cached copy of an order so the next read hits storage.
	 *
	 * `wc_get_order()` reads THROUGH HPOS's object cache and returns the
	 * cached instance when there is one (class-wc-order-factory.php:34-41),
	 * so evicting is the whole of the work — without it "reload" hands back
	 * the copy this request already had.
	 *
	 * The namespace is `Caches`, not `Caching`; `Caching` holds the abstract
	 * ObjectCache this one extends. It was `Caching` here, and because the
	 * guard is a class_exists() the mistake did not fail — it made the whole
	 * block quietly not run. Both confirmation paths then re-read inside
	 * their lock and got a stale order, so a payment that arrived by webhook
	 * and by thank-you page at once was applied TWICE: two payment_complete()
	 * transitions, two sets of customer emails, duplicate meta rows.
	 * Reproduced on order 86 and pinned by OrderReloadTest.
	 *
	 * @param int $order_id Order whose cached copies to drop.
	 */
	private static function forget_cached_order( int $order_id ): void {
		// Legacy post store.
		clean_post_cache( $order_id );

		if ( ! class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			return;
		}

		try {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		} catch ( Throwable $e ) {
			// A failed eviction means the caller is about to act on a stale
			// order, which is the exact condition this method exists to
			// prevent. Never silent.
			XPay_Logger::error(
				'order.cache_evict_failed',
				array(
					'order_id' => $order_id,
					'error'    => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * A paid event arrived on a session this order has since superseded:
	 * provably this order's money (the id came from the order's own
	 * superseded ledger), but possibly for an outdated total, so it never
	 * auto-completes — the order parks on-hold for a human, the same
	 * pattern as the amount-mismatch guard. Two shapes:
	 *
	 *   - Order still unpaid: the outdated session's money is the only
	 *     money. Park on-hold, record the payment intent so refunds and
	 *     the expiry guard see real money behind the order.
	 *   - Order already paid: the CURRENT session also collected — the
	 *     shopper paid twice. Note it loudly; the recorded intent stays
	 *     the current session's (refunding the duplicate is a dashboard
	 *     action on the OLD intent, named in the note).
	 *
	 * @param WC_Order $order   Target order (superseded ownership verified).
	 * @param array    $session COMPLETE session payload from the event.
	 */
	public static function apply_superseded_paid( WC_Order $order, array $session ): void {
		$paid = isset( $session['paymentStatus'] ) && XPay_Payment_Status::PAID === $session['paymentStatus'];
		if ( ! $paid ) {
			return; // A completed-but-unpaid superseded session moves no money.
		}

		$session_id = isset( $session['id'] ) ? (string) $session['id'] : '';
		$intent_id  = '';
		if ( isset( $session['paymentIntent']['id'] ) && is_string( $session['paymentIntent']['id'] ) ) {
			$intent_id = $session['paymentIntent']['id'];
		} elseif ( isset( $session['paymentIntentId'] ) && is_string( $session['paymentIntentId'] ) ) {
			$intent_id = $session['paymentIntentId'];
		}

		if ( $order->is_paid() ) {
			XPay_Logger::event(
				'order.superseded_double_paid',
				array(
					'order_id'   => $order->get_id(),
					'session_id' => $session_id,
					'intent_id'  => $intent_id,
				)
			);
			$order->add_order_note(
				sprintf(
					/* translators: 1: XPay checkout session id, 2: XPay payment intent id. */
					__( 'XPay received a SECOND payment for this order, on an outdated payment session (%1$s, payment intent %2$s). Refund the duplicate payment from your XPay dashboard.', 'xpay-for-woocommerce' ),
					'' !== $session_id ? $session_id : '—',
					'' !== $intent_id ? $intent_id : '—'
				)
			);
			$order->save();
			return;
		}

		if ( '' !== $intent_id ) {
			$order->update_meta_data( XPay_Constants::META_PAYMENT_INTENT, $intent_id );
		}
		self::remember_customer( $order, $session );

		XPay_Logger::event(
			'order.superseded_paid',
			array(
				'order_id'   => $order->get_id(),
				'session_id' => $session_id,
				'intent_id'  => $intent_id,
			)
		);

		$note = sprintf(
			/* translators: %s is the XPay checkout session id the payment arrived on. */
			__( 'XPay received a payment for this order on an outdated payment session (%s), so the amount may not match the current total. Review the payment in your XPay dashboard, then complete or refund the order manually.', 'xpay-for-woocommerce' ),
			'' !== $session_id ? $session_id : '—'
		);
		/*
		 * Written BEFORE the status change, and never cleared. on-hold is in
		 * PAYMENT_COMPLETE_STATUSES and is not is_paid(), so without a
		 * durable fact here the next mark_paid() completes the order and
		 * undoes this park. The route that reaches it is the shopper paying
		 * the CURRENT session afterwards: verify_on_thankyou() finds that
		 * session genuinely paid and calls mark_paid(), which would ship an
		 * order carrying two payments and bury the note asking someone to
		 * look at the first.
		 */
		$order->update_meta_data( XPay_Constants::META_SUPERSEDED_PARKED, (string) time() );

		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
			$order->save();
		}
	}

	/**
	 * Park an order whose session completed UNPAID: the shopper finished
	 * checkout with a deferred method and holds a payment reference (a
	 * Fawry code) they will pay out of band. No money has moved. On-hold,
	 * not processing: on-hold ships nothing, tells the shopper nothing is
	 * on its way, and holds the stock while the reference is live.
	 *
	 * The money verdict arrives later as async_payment_succeeded (routed
	 * to mark_paid, which completes from on-hold) or async_payment_failed
	 * (routed to mark_async_failed). Nothing here records
	 * META_PAYMENT_INTENT: that key means money moved for this order, and
	 * the refund path and mark_expired() both read it that way.
	 *
	 * Idempotent by marker, not by event id: a redelivery of `completed`
	 * under a fresh event id passes the id-keyed dedupe, and the status
	 * check alone cannot carry this (another park also leaves on-hold).
	 *
	 * @param WC_Order $order   Target order (ownership already verified).
	 * @param array    $session COMPLETE/UNPAID session payload.
	 */
	public static function mark_awaiting_payment( WC_Order $order, array $session ): void {
		if ( $order->is_paid() ) {
			return;
		}
		/*
		 * Same status gate as mark_async_failed() and mark_expired(), and
		 * this was the one transition writer without it.
		 *
		 * Two events, two independent deliveries, each with its own retry
		 * schedule: nothing guarantees `completed` lands before
		 * `async_payment_failed`. A `completed` that 404s because it outran
		 * Place Order is retried for up to three days, and a reference can
		 * die inside that window — so the failure arrives first, fails the
		 * order, and the redelivered `completed` then found no marker and no
		 * status guard, and reopened a dead reference as on-hold "awaiting
		 * payment, do not ship". The order then waits for a confirmation
		 * that already came, and the marker it wrote blocks mark_expired()
		 * from ever closing it.
		 *
		 * A cancelled order met the same gap and was moved to on-hold, which
		 * re-reduces stock — the case mark_paid() handles deliberately and
		 * loudly, and this one did silently.
		 */
		if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			XPay_Logger::event(
				'order.awaiting_payment_refused',
				array(
					'order_id' => $order->get_id(),
					'status'   => $order->get_status(),
				)
			);
			return;
		}
		if ( '' !== (string) $order->get_meta( XPay_Constants::META_AWAITING_PAYMENT ) ) {
			return;
		}

		$order->update_meta_data( XPay_Constants::META_AWAITING_PAYMENT, (string) time() );

		XPay_Logger::event(
			'order.awaiting_payment',
			array(
				'order_id'   => $order->get_id(),
				'session_id' => isset( $session['id'] ) ? (string) $session['id'] : '',
			)
		);

		$note = __( 'XPay: the shopper completed checkout with a payment reference to pay later, for example a Fawry code. No money has been received yet. XPay confirms automatically when the reference is paid or fails, and this order will update on its own. Do not ship before that confirmation.', 'xpay-for-woocommerce' );

		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
			$order->save();
		}
	}

	/**
	 * Fail an order whose deferred payment reference died unpaid, from an
	 * async_payment_failed event. FAILED for the same reason mark_expired()
	 * chooses it: a failed order stays payable, so the emailed pay link
	 * keeps working and the shopper can pay again with a fresh session.
	 *
	 * Refuses paid orders (a straggler failure after an async success is
	 * noise) and orders in any state other than pending or the on-hold this
	 * plugin parked them in.
	 *
	 * @param WC_Order $order   Target order (ownership already verified).
	 * @param array    $session Session payload; its payment intent carries
	 *                          the processor's reason when there is one.
	 */
	public static function mark_async_failed( WC_Order $order, array $session ): void {
		if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return;
		}

		$intent = isset( $session['paymentIntent'] ) && is_array( $session['paymentIntent'] ) ? $session['paymentIntent'] : array();
		$error  = isset( $intent['lastPaymentError'] ) && is_array( $intent['lastPaymentError'] ) ? $intent['lastPaymentError'] : array();

		$message = self::error_field( $error, 'merchantMessage' );
		$message = '' !== $message ? $message : self::error_field( $error, 'message' );

		$detail = '';
		if ( '' !== $message ) {
			$detail = ' ' . sprintf(
				/* translators: %s is XPay's reason, for example "The customer did not pay the reference before it expired". */
				__( 'Reason: %s', 'xpay-for-woocommerce' ),
				rtrim( $message, '.' ) . '.'
			);
		}

		$order->update_status(
			'failed',
			__( 'XPay: the payment reference for this order was not paid. No money was received. The order can still be paid through its payment link.', 'xpay-for-woocommerce' ) . $detail
		);

		XPay_Logger::event(
			'order.async_payment_failed',
			array(
				'order_id'   => $order->get_id(),
				'session_id' => isset( $session['id'] ) ? (string) $session['id'] : '',
				'intent_id'  => isset( $intent['id'] ) && is_string( $intent['id'] ) ? $intent['id'] : '',
			)
		);
	}

	/**
	 * Fail an order whose session expired unpaid. FAILED, not CANCELLED,
	 * by design: a failed order stays payable, so the emailed pay link
	 * keeps working (the pay page mints a fresh session on the revisit)
	 * and WooCommerce's own failed-order machinery can nudge the shopper.
	 * Cancelling killed the link a day after the pay page promised
	 * "pay when you are ready". Idempotent; refuses to touch paid or
	 * already-terminal orders.
	 *
	 * @param WC_Order $order   Target order.
	 * @param array    $session Expired-session payload when available — its
	 *                          embedded payment intent carries the decline
	 *                          history that explains WHY nothing was paid.
	 */
	public static function mark_expired( WC_Order $order, array $session = array() ): void {
		if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return;
		}
		// A recorded payment intent means money moved for this order —
		// possibly parked on-hold by the amount guard while a later retry
		// session expired unpaid. Failing would bury a real payment;
		// orders with money behind them are resolved by humans only.
		if ( '' !== (string) $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ) ) {
			return;
		}
		// An order awaiting a deferred payment belongs to the
		// async_payment events. Its session is COMPLETE, so the platform
		// never fires `expired` for it (the expiration cron only touches
		// OPEN sessions) — this guards a stale or misrouted event from
		// failing an order whose reference may still be paid.
		if ( '' !== (string) $order->get_meta( XPay_Constants::META_AWAITING_PAYMENT ) ) {
			return;
		}
		$order->update_status(
			'failed',
			trim( __( 'XPay checkout session expired without payment. The order can still be paid through its payment link.', 'xpay-for-woocommerce' ) . ' ' . self::decline_summary( $session ) )
		);
		XPay_Logger::event( 'order.session_expired', array( 'order_id' => $order->get_id() ) );
	}

	/**
	 * One sentence summarizing the declines embedded in an expired-session
	 * payload, or '' when there were none (or the shopper never submitted
	 * — the payload's paymentIntent is null then). This is the post-mortem
	 * on an abandoned order: "walked away" and "card kept declining" need
	 * different merchant responses.
	 *
	 * @param array $session Expired-session payload.
	 */
	private static function decline_summary( array $session ): string {
		if ( ! isset( $session['paymentIntent'] ) || ! is_array( $session['paymentIntent'] ) ) {
			return '';
		}
		$intent = $session['paymentIntent'];

		$failed = 0;
		if ( isset( $intent['charges'] ) && is_array( $intent['charges'] ) ) {
			foreach ( $intent['charges'] as $charge ) {
				if ( is_array( $charge ) && isset( $charge['status'] ) && 'FAILED' === $charge['status'] ) {
					++$failed;
				}
			}
		}
		if ( 0 === $failed ) {
			return '';
		}

		/* translators: %d is the number of declined payment attempts. */
		$summary = sprintf( _n( 'The shopper made %d payment attempt that was declined.', 'The shopper made %d payment attempts that were declined.', $failed, 'xpay-for-woocommerce' ), $failed );

		$error = isset( $intent['lastPaymentError'] ) && is_array( $intent['lastPaymentError'] ) ? $intent['lastPaymentError'] : array();
		$code  = self::error_field( $error, 'declineCode' );
		$code  = '' !== $code ? $code : self::error_field( $error, 'code' );
		if ( '' !== $code ) {
			$message  = self::error_field( $error, 'merchantMessage' );
			$message  = '' !== $message ? $message : self::error_field( $error, 'message' );
			$summary .= ' ' . sprintf(
				/* translators: 1: XPay failure code, 2: failure message. */
				__( 'Last decline: %1$s (%2$s)', 'xpay-for-woocommerce' ),
				$code,
				'' !== $message ? $message : '—'
			);
		}
		return $summary;
	}

	/**
	 * @param array  $error lastPaymentError payload.
	 * @param string $key   Field name.
	 */
	private static function error_field( array $error, string $key ): string {
		return isset( $error[ $key ] ) && is_string( $error[ $key ] ) ? trim( $error[ $key ] ) : '';
	}

	/**
	 * Record a declined attempt on the order, from a
	 * payment_intent.payment_failed event. A note and a log row, never a
	 * status change: the shopper may still succeed on the next attempt,
	 * and expiry/payment keep their own writers. Skipped entirely on paid
	 * orders — a straggler decline event after success is noise.
	 *
	 * @param WC_Order $order  Target order (session ownership verified).
	 * @param array    $intent Payment-intent payload from the event.
	 */
	public static function note_payment_failed( WC_Order $order, array $intent ): void {
		if ( $order->is_paid() ) {
			return;
		}

		$error   = isset( $intent['lastPaymentError'] ) && is_array( $intent['lastPaymentError'] ) ? $intent['lastPaymentError'] : array();
		$code    = self::error_field( $error, 'declineCode' );
		$code    = '' !== $code ? $code : self::error_field( $error, 'code' );
		$message = self::error_field( $error, 'merchantMessage' );
		$message = '' !== $message ? $message : self::error_field( $error, 'message' );

		$order->add_order_note(
			sprintf(
				/* translators: 1: XPay failure code (for example "insufficient_funds"), 2: failure message. */
				__( 'XPay payment attempt declined [%1$s]: %2$s The shopper can retry; the order is unchanged.', 'xpay-for-woocommerce' ),
				'' !== $code ? $code : 'unknown',
				'' !== $message ? rtrim( $message, '.' ) . '.' : '—'
			)
		);
		$order->save();

		XPay_Logger::event(
			'order.payment_failed',
			array(
				'order_id'  => $order->get_id(),
				'intent_id' => isset( $intent['id'] ) ? (string) $intent['id'] : '',
				'code'      => $code,
			)
		);
	}

	/**
	 * Thank-you page truth check. The redirect back from XPay is NEVER
	 * trusted as proof of payment — this re-fetches the session server-side
	 * and applies the authoritative state. The webhook usually wins this
	 * race; when it does, this is a no-op.
	 *
	 * Hooked on woocommerce_before_thankyou.
	 *
	 * @param int $order_id Order being viewed.
	 */
	public static function verify_on_thankyou( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) || $order->is_paid() ) {
			return;
		}

		$session_id = (string) $order->get_meta( XPay_Constants::META_SESSION_ID );
		if ( '' === $session_id ) {
			return;
		}

		try {
			$client  = XPay_Plugin::instance()->gateway()->api_client();
			$session = $client->get_checkout_session( $session_id, XPay_Api_Client::SHOPPER_READ_TIMEOUT_SECONDS );
		} catch ( XPay_Api_Exception $e ) {
			// Fail open to the pending UI: the webhook retry engine is the
			// safety net, and blocking the thank-you page on an API blip
			// would punish a customer who already paid.
			XPay_Logger::event(
				'thankyou.check_failed',
				array(
					'order_id' => $order_id,
					'code'     => $e->get_error_code(),
				)
			);
			return;
		}

		$paid = isset( $session['paymentStatus'] ) && XPay_Payment_Status::PAID === $session['paymentStatus']
			&& isset( $session['status'] ) && XPay_Session_Status::COMPLETE === $session['status'];

		if ( ! $paid ) {
			return;
		}

		// The webhook usually wins this race. Take the per-order lock
		// non-blocking and defer to whoever holds it — their write is the
		// same transition this one would apply.
		$order_id = (int) $order_id;
		if ( ! XPay_Order_Lock::acquire( $order_id, 0 ) ) {
			return;
		}
		try {
			$fresh = self::reload( $order_id );
			if ( null !== $fresh && ! $fresh->is_paid() ) {
				self::mark_paid( $fresh, $session, 'thankyou' );
			}
		} finally {
			XPay_Order_Lock::release( $order_id );
		}
	}
}
