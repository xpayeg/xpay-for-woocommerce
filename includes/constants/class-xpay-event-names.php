<?php
/**
 * XPay_Event_Names
 *
 * Webhook event types the plugin subscribes to and handles. Values are the
 * exact wire strings from the XPay webhook contract.
 *
 * To handle a new event type:
 *   1. Add the constant here.
 *   2. Add it to SUBSCRIBED — the list the configurator creates endpoints
 *      with and the update-time reconfigure converges live endpoints to.
 *   3. Add a case in XPay_Webhook_Controller::apply_event().
 *
 * Explicitly NOT handled (acknowledged with 200 and ignored): every other
 * event type. The receiver must stay forward-compatible — XPay adds event
 * types without notice, and an unknown type is never an error.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Event_Names {

	const CHECKOUT_SESSION_COMPLETED = 'checkout.session.completed';
	const CHECKOUT_SESSION_EXPIRED   = 'checkout.session.expired';

	/*
	 * Deferred-method outcomes. A deferred processor (Fawry) completes the
	 * session with paymentStatus `unpaid` the moment the payment reference
	 * is issued and fires `completed` right then,
	 * so `completed` alone NEVER means money moved. The verdict arrives
	 * later on one of these two: `async_payment_succeeded` when the shopper
	 * pays the reference (data.object is the session, now `paid`), or
	 * `async_payment_failed` when the reference dies unpaid (the session
	 * stays `complete` + `unpaid`, its final shape). This follows the common
	 * model for
	 * delayed-notification methods, adopted wholesale.
	 */
	const CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED = 'checkout.session.async_payment_succeeded';
	const CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED    = 'checkout.session.async_payment_failed';

	/**
	 * Declined attempt. data.object is a PAYMENT INTENT, not a session —
	 * it carries the session's metadata (copied at first collect) plus
	 * checkoutSessionId for ownership, and lastPaymentError for the note.
	 */
	const PAYMENT_INTENT_FAILED = 'payment_intent.payment_failed';

	/*
	 * Refund mirroring. 'charge.refunded' is the money-settled signal
	 * (fires only for SUCCEEDED refunds; data.object is a CHARGE whose
	 * refunds[] each carry id/amount/currency/status and a per-refund
	 * presentmentDetails mirror); 'refund.failed' is the decline
	 * (data.object is a REFUND with failureReason). Neither object
	 * carries metadata — correlation goes through paymentIntentId.
	 * 'refund.created' fires for pending creates too, so it is NOT a
	 * settlement signal, and 'refund.updated' does not exist — refunds
	 * are immutable post-create.
	 */
	const CHARGE_REFUNDED = 'charge.refunded';
	const REFUND_FAILED   = 'refund.failed';

	/** Event types the receiver applies; everything else is acknowledged and ignored. */
	const SUBSCRIBED = array(
		self::CHECKOUT_SESSION_COMPLETED,
		self::CHECKOUT_SESSION_EXPIRED,
		self::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED,
		self::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED,
		self::PAYMENT_INTENT_FAILED,
		self::CHARGE_REFUNDED,
		self::REFUND_FAILED,
	);
}
