<?php
/**
 * XPay_Order_Sync
 *
 * The ONLY writer of XPay-driven order-state transitions. Both async paths
 * (webhook events) and sync paths (thank-you page re-check) funnel through
 * the same idempotent methods here, so "payment_complete exactly once" is
 * enforced in one place instead of N call sites — the guard-parity lesson
 * from the monorepo's failure catalogue.
 *
 * Ownership rule (IDOR guard, carried from v2): a session id arriving from
 * outside is only trusted for an order when it exactly matches the session
 * id THIS plugin stored on THAT order. Existence is never enough.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Order_Sync {

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

		// Money truth: the session says what was CHARGED, the order says
		// what is OWED, and they can drift — an admin editing the total
		// while the shopper holds a live pay page. Completing on drifted
		// numbers would stamp the order fully paid for the wrong amount,
		// silently. Absent fields skip the check (the event is already
		// signature-verified; fail open on shape, closed on value);
		// present-but-different values park the order for a human. The
		// money is at XPay either way — the order just waits.
		if ( self::charged_amount_disagrees( $session, $order ) ) {
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
	 * the order's total. The comparison source prefers presentmentDetails
	 * (the platform's mirror in the shopper's currency when it differs
	 * from processing) and falls back to the top-level amount — both in
	 * minor units, both compared against the same conversion the session
	 * was created with. Missing/malformed fields answer false: only a
	 * present-but-different value may block a payment.
	 *
	 * @param array    $session Session payload (webhook or API fetch).
	 * @param WC_Order $order   Order about to be marked paid.
	 */
	private static function charged_amount_disagrees( array $session, WC_Order $order ): bool {
		$amount   = null;
		$currency = '';
		if ( isset( $session['presentmentDetails']['amountTotal'], $session['presentmentDetails']['currency'] ) ) {
			$amount   = $session['presentmentDetails']['amountTotal'];
			$currency = (string) $session['presentmentDetails']['currency'];
		} elseif ( isset( $session['amountTotal'], $session['currency'] ) ) {
			$amount   = $session['amountTotal'];
			$currency = (string) $session['currency'];
		}
		if ( null === $amount || ! is_scalar( $amount ) || '' === $currency ) {
			return false;
		}
		return strtoupper( $order->get_currency() ) !== strtoupper( $currency )
			|| XPay_Money::to_minor( $order->get_total(), $order->get_currency() ) !== (int) $amount;
	}

	/**
	 * Order note explaining an amount mismatch with both numbers, so the
	 * merchant can resolve it without opening a log.
	 *
	 * @param array    $session Session payload carrying the charged amount.
	 * @param WC_Order $order   Held order.
	 */
	private static function mismatch_note( array $session, WC_Order $order ): string {
		$currency = isset( $session['presentmentDetails']['currency'] ) ? (string) $session['presentmentDetails']['currency'] : ( isset( $session['currency'] ) ? (string) $session['currency'] : $order->get_currency() );
		$amount   = isset( $session['presentmentDetails']['amountTotal'] ) ? $session['presentmentDetails']['amountTotal'] : ( isset( $session['amountTotal'] ) ? $session['amountTotal'] : 0 );
		return sprintf(
			/* translators: 1: the amount XPay charged, 2: this order's total. */
			__( 'XPay charged %1$s but this order totals %2$s. Review the payment in your XPay dashboard, adjust the order if needed, then complete or refund it manually.', 'xpay-for-woocommerce' ),
			wc_price( XPay_Money::from_minor( (string) $amount, $currency ), array( 'currency' => $currency ) ),
			wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) )
		);
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
		if ( class_exists( \Automattic\WooCommerce\Caching\OrderCache::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caching\OrderCache::class )->remove( $order_id );
		}
		clean_post_cache( $order_id );
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
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
		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
			$order->save();
		}
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
