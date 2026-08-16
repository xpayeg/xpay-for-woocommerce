<?php
/**
 * XPay session status registries.
 *
 * Constant names are UPPER_SNAKE; values are the exact lower_snake wire
 * strings from the v3 API (checkout-session-status.enum.ts /
 * payment-status.enum.ts). Never re-derive a wire string from a constant
 * name at runtime, and never compare against a raw literal.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Session_Status {
	const OPEN     = 'open';
	const COMPLETE = 'complete';
	const EXPIRED  = 'expired';
}

final class XPay_Payment_Status {
	const PAID                = 'paid';
	const UNPAID              = 'unpaid';
	const NO_PAYMENT_REQUIRED = 'no_payment_required';
}
