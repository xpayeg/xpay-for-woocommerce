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
		if ( ! $order instanceof WC_Order || XPay_Constants::GATEWAY_ID !== $order->get_payment_method() || $order->is_paid() ) {
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

		if ( $paid ) {
			self::mark_paid( $order, $session, 'thankyou' );
		}
	}
}
