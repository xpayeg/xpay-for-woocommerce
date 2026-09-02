<?php
/**
 * XPay refund status registry.
 *
 * Values match the Refund object schema.
 *
 * @see https://docs.xpay.app/en/api-reference/objects/refund
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
	 * WooCommerce treats a successful process_refund() return as completed,
	 * so these in-flight states must not be recorded as completed refunds.
	 */
	const IN_FLIGHT = array( self::PENDING, self::REQUIRES_ACTION );
}

/**
 * The one Refund reason this plugin sends.
 */
final class XPay_Refund_Reason {
	const REQUESTED_BY_CUSTOMER = 'REQUESTED_BY_CUSTOMER';
}
