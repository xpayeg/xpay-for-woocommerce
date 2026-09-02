<?php
/**
 * XPay_Gateway_Order
 *
 * Puts the XPay row at the top of the checkout ONCE, when the plugin is
 * first activated, and never touches the order again.
 *
 * WooCommerce sorts the checkout's payment rows by
 * `woocommerce_gateway_order`, a map of gateway id to position that the
 * merchant sets by dragging rows in WooCommerce > Settings > Payments.
 * Whichever row sorts first is also the one the Blocks checkout preselects,
 * so position and default selection are one decision, not two.
 *
 * A merchant installing a payment gateway means to be paid through it, so
 * arriving below Cash on delivery is a poor default. But the order is
 * THEIRS. Forcing it on every load would overrule a choice they made
 * deliberately, and reordering a merchant's checkout from a plugin is
 * exactly what wordpress.org review treats as misbehaviour.
 *
 * So: once, ever. The flag below is what makes it once — not the presence
 * of `xpay` in the map, which WooCommerce writes by itself the first time
 * anyone opens the Payments screen and which therefore says nothing about
 * what the merchant wanted. A merchant who moves the row afterwards keeps
 * their order forever, including across deactivation and reactivation.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Gateway_Order {

	/**
	 * Set once the default position has been applied, and never cleared.
	 *
	 * Deliberately outlives deactivation: reinstalling is not permission to
	 * rearrange a checkout the merchant has since arranged themselves.
	 */
	const OPTION_APPLIED = 'xpay_wc_gateway_order_applied';

	/**
	 * Put the XPay rows first, if this store has never been given a default.
	 *
	 * Every other gateway keeps its relative order; they are shifted down
	 * as a block rather than resorted, so a merchant who had already
	 * arranged the rest finds the rest still arranged.
	 */
	public static function apply_default(): void {
		if ( '' !== (string) get_option( self::OPTION_APPLIED, '' ) ) {
			return;
		}

		$order = get_option( 'woocommerce_gateway_order' );
		if ( ! is_array( $order ) || array() === $order ) {
			// Nothing has ever ordered the rows. WooCommerce writes that map
			// itself, later. Marking ourselves done here would spend the one
			// shot on a map that cannot contain our row yet.
			return;
		}

		$primary = array();
		$ours    = array();
		$others  = array();
		foreach ( $order as $id => $position ) {
			$id = (string) $id;
			if ( XPay_Constants::GATEWAY_ID === $id ) {
				// The combined row is the one a shopper should meet first.
				// A store carrying older xpay_* rows must not push it down
				// just because they happen to sort earlier today.
				$primary[] = $id;
			} elseif ( XPay_Constants::is_xpay_gateway( $id ) ) {
				$ours[] = $id;
			} else {
				$others[] = $id;
			}
		}
		$ours = array_merge( $primary, $ours );

		if ( array() === $ours ) {
			/*
			 * OUR ROW IS NOT IN THE MAP YET, so there is nothing to move and
			 * the flag must NOT be set.
			 *
			 * This is what made "once, ever" mean "never". The flag was
			 * written at the top of this method, and activation is the one
			 * moment our row cannot be in the map: WordPress fires
			 * activate_{plugin} long after plugins_loaded, which is why the
			 * hook has to require the class by hand. WooCommerce fills the
			 * map afterwards. So the single shot was always spent before it
			 * could do anything, and no store was ever reordered.
			 *
			 * Returning without the flag means the next admin page load
			 * tries again, and the run that finally finds the row is the one
			 * that spends it.
			 */
			return;
		}

		$position = 0;
		$updated  = array();
		foreach ( array_merge( $ours, $others ) as $id ) {
			$updated[ $id ] = $position;
			++$position;
		}

		update_option( 'woocommerce_gateway_order', $updated );
		update_option( self::OPTION_APPLIED, (string) time(), false );
	}

	/**
	 * Try again on each admin load until there is something to reorder.
	 *
	 * Activation alone could never work — see the bail-out above — and the
	 * merchant's own ordering must still win, which the flag guarantees the
	 * moment a real attempt succeeds. Until then this costs one option read
	 * per admin request, and only for a store that has never been given a
	 * default.
	 */
	public static function register(): void {
		if ( '' !== (string) get_option( self::OPTION_APPLIED, '' ) ) {
			return;
		}
		add_action( 'admin_init', array( __CLASS__, 'apply_default' ) );
	}
}
