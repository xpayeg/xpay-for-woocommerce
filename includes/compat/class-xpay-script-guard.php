<?php
/**
 * XPay_Script_Guard
 *
 * Optimizer opt-out armor for the payment-critical scripts. JS optimizers
 * (Cloudflare Rocket Loader, WP Rocket delay/defer, LiteSpeed, Autoptimize,
 * Perfmatters) rewrite script tags to defer or delay execution until first
 * interaction. On the pay page that turns "the XPay window opens by itself"
 * into "nothing happens until the shopper wiggles the mouse", and on block
 * checkout it can keep the XPay rows from registering at all. Each
 * optimizer honors a documented opt-out attribute; this stamps the full
 * set on exactly the handles that must run on time, and nothing else —
 * the rest of the page stays the optimizer's to improve.
 *
 * The v2 plugin never had this armor and the gap is documented in its
 * support history; the scripts here also boot readyState-safely, so the
 * attributes are the second layer, not the only one.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Script_Guard {

	/** Script handles that must execute untouched, on time, in order. */
	const PROTECTED_HANDLES = array( 'xpay-checkout-modal', 'xpay-blocks' );

	public static function register(): void {
		add_filter( 'script_loader_tag', array( __CLASS__, 'harden_tag' ), 10, 2 );
	}

	/**
	 * Stamp the opt-out attributes onto a protected handle's script tag:
	 * data-cfasync (Cloudflare Rocket Loader), nowprocket (WP Rocket),
	 * data-no-optimize (LiteSpeed), data-noptimize (Autoptimize),
	 * data-no-defer (Perfmatters and friends). Unknown attributes are
	 * ignored by browsers, so the union is harmless where an optimizer
	 * is absent.
	 *
	 * @param mixed  $tag    The complete script tag markup.
	 * @param string $handle Registered script handle.
	 * @return mixed
	 */
	public static function harden_tag( $tag, $handle ) {
		if ( ! is_string( $tag ) || ! in_array( $handle, self::PROTECTED_HANDLES, true ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'data-cfasync' ) ) {
			return $tag; // Already stamped — some setups run this filter twice.
		}
		return str_replace(
			'<script ',
			'<script data-cfasync="false" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" ',
			$tag
		);
	}
}
