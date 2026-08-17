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
	 * States in which the platform has accepted the refund: money settled
	 * (SUCCEEDED) or in flight (PENDING / REQUIRES_ACTION). A 2xx create
	 * response can still carry FAILED or CANCELED — the processor's
	 * synchronous decline is copied into the refund object, not turned into
	 * an HTTP error — and recording a WooCommerce refund for one of those
	 * would misstate money truth.
	 */
	const ACCEPTED = array( self::SUCCEEDED, self::PENDING, self::REQUIRES_ACTION );
}
