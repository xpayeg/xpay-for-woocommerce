<?php
/**
 * XPay_Order_Lock
 *
 * Per-order advisory lock (MySQL GET_LOCK) serializing every XPay-driven
 * payment transition. Without it, concurrent webhook deliveries — or a
 * delivery racing the thank-you page check — interleave read-modify-write
 * on the same order: both pass the event dedupe, both fire
 * payment_complete side effects, and concurrent processed-event saves
 * overwrite each other.
 *
 * Same primitive as XPay_Refund_Service's per-intent lock (the monorepo's
 * advisory-lock rule), different key space: this one is per ORDER. The two
 * lock scopes never nest in any code path, so pre-5.7.5 MySQL's
 * one-named-lock-per-connection limit is never hit.
 *
 * GET_LOCK is connection-scoped: if PHP dies mid-section, MySQL releases
 * the lock when the connection closes — no stale-lock janitor needed.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Order_Lock {

	/** Webhook-side wait: XPay's delivery timeout comfortably exceeds this, and a 500-triggered retry is cheap. */
	const WAIT_SECONDS = 5;

	/**
	 * @param int $order_id        Order whose transitions to serialize.
	 * @param int $timeout_seconds Seconds to wait for the holder; 0 = try once, never wait.
	 */
	public static function acquire( int $order_id, int $timeout_seconds ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named MySQL advisory lock: connection-scoped and uncacheable by definition; no core API exists.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::name( $order_id ), $timeout_seconds ) );
	}

	public static function release( int $order_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the advisory lock acquired above; uncacheable by definition.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::name( $order_id ) ) );
	}

	/** Namespaced: never collides with other plugins' locks or our per-intent refund lock. */
	private static function name( int $order_id ): string {
		return 'xpay_order_' . $order_id;
	}
}
