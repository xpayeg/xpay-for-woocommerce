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
	 * @param string   $via        'webhook'|'thankyou' — recorded for audit.
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

		// payment_complete() sets processing/completed, records the
		// transaction id, and reduces stock — WooCommerce's canonical
		// "money arrived" transition.
		$order->payment_complete( '' !== $intent_id ? $intent_id : (string) $order->get_meta( XPay_Constants::META_SESSION_ID ) );

		$order->add_order_note(
			sprintf(
				/* translators: 1: payment source ("webhook" or "thank-you page check"), 2: XPay payment intent id. */
				__( 'XPay payment confirmed via %1$s. Payment intent: %2$s', 'xpay-for-woocommerce' ),
				'webhook' === $via ? __( 'webhook', 'xpay-for-woocommerce' ) : __( 'thank-you page check', 'xpay-for-woocommerce' ),
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
			$session = $client->get_checkout_session( $session_id );
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
