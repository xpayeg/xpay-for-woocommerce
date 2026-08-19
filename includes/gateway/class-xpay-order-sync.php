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
	 * Cancel an order whose session expired unpaid. Idempotent; refuses to
	 * touch paid or already-terminal orders.
	 *
	 * @param WC_Order $order Target order.
	 */
	public static function mark_expired( WC_Order $order ): void {
		if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return;
		}
		// A recorded payment intent means money moved for this order —
		// possibly parked on-hold by the amount guard while a later retry
		// session expired unpaid. Cancelling would bury a real payment;
		// orders with money behind them are resolved by humans only.
		if ( '' !== (string) $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ) ) {
			return;
		}
		$order->update_status(
			'cancelled',
			__( 'XPay checkout session expired without payment.', 'xpay-for-woocommerce' )
		);
		XPay_Logger::event( 'order.session_expired', array( 'order_id' => $order->get_id() ) );
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
