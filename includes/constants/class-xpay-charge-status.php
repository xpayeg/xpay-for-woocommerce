<?php
/**
 * XPay charge status registry.
 *
 * Charge status values used to decide which charges represent captured money.
 *
 * @see https://docs.xpay.app/en/api-reference/objects/charge
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Charge_Status {
	const SUCCEEDED          = 'SUCCEEDED';
	const PENDING            = 'PENDING';
	const FAILED             = 'FAILED';
	const CANCELED           = 'CANCELED';
	const REFUNDED           = 'REFUNDED';
	const PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';

	/**
	 * Charges whose money was captured, and which can therefore still hold
	 * a refundable balance.
	 *
	 * Status narrows which charges to count; amountCaptured and
	 * amountRefunded determine what remains.
	 */
	const CAPTURED = array( self::SUCCEEDED, self::REFUNDED, self::PARTIALLY_REFUNDED );
}
