<?php
/**
 * XPay_Event_Names
 *
 * Webhook event types the plugin subscribes to and handles. Values are the
 * exact wire strings from the v3 event catalogue (event-names.constants.ts).
 *
 * To handle a new event type:
 *   1. Add the constant here.
 *   2. Add it to SUBSCRIBED (used when auto-provisioning the endpoint).
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

	/** Event types the plugin's webhook endpoint subscribes to. */
	const SUBSCRIBED = array(
		self::CHECKOUT_SESSION_COMPLETED,
		self::CHECKOUT_SESSION_EXPIRED,
	);

	/*
	 * Deferred — refund.* events would let the plugin reflect refunds issued
	 * from the XPay dashboard back into WooCommerce order notes. Reactivate
	 * by following the 3-step checklist above.
	 *
	 * const REFUND_SUCCEEDED = 'refund.succeeded';
	 * const REFUND_FAILED    = 'refund.failed';
	 */
}
