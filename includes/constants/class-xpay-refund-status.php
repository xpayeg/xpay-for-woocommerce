<?php
/**
 * XPay refund status registry.
 *
 * Constant names are UPPER_SNAKE; values are the exact wire strings from
 * the v3 API (refund-status.enum.ts). NOTE the casing: refund statuses are
 * UPPERCASE on the wire, unlike the checkout-session enums, which are
 * lowercase — the API copies each enum's raw value into the response. Never
 * re-derive a wire string from a constant name at runtime, and never
 * compare against a raw literal.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Refund_Status {
	const PENDING         = 'PENDING';
	const REQUIRES_ACTION = 'REQUIRES_ACTION';
	const SUCCEEDED       = 'SUCCEEDED';
	const FAILED          = 'FAILED';
	const CANCELED        = 'CANCELED';

	/**
	 * Money accepted but not yet settled. Every current platform adapter
	 * answers a create synchronously with SUCCEEDED or FAILED — these two
	 * exist for future processors. WooCommerce's process_refund() contract
	 * treats `true` as a COMPLETED synchronous refund, so an in-flight
	 * state must not be recorded as one; it surfaces as an explicit
	 * "still processing, do not resubmit" error until the plugin
	 * subscribes to refund.* webhooks and can reconcile asynchronously.
	 */
	const IN_FLIGHT = array( self::PENDING, self::REQUIRES_ACTION );
}
