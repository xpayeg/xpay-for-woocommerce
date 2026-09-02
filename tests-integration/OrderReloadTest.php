<?php
/**
 * Does the re-read inside the order lock actually re-read?
 *
 * Both confirmation paths are built the same way: take the per-order lock,
 * re-read the order, act only if it is still unpaid. The lock works. The
 * re-read did not — it named HPOS's cache class in the wrong namespace
 * (`Caching` instead of `Caches`), and the `class_exists()` guard turned
 * that typo into a silent skip, so `wc_get_order()` handed back the copy
 * the request already had.
 *
 * What that cost, on a real order: a webhook and a thank-you page landing
 * in the same second both saw an unpaid order, both ran payment_complete(),
 * and the shopper got two "order processing" emails against duplicated
 * meta rows.
 *
 * Storage is changed here by direct SQL on purpose. That is the only way to
 * move the row without going through the caches, and it is exactly what a
 * concurrent second process looks like from inside this one.
 *
 * @package XPay_For_WooCommerce
 */

class OrderReloadTest extends XPay_Integration_Test_Case {

	public function storages(): array {
		return array(
			'HPOS'         => array( true ),
			'legacy posts' => array( false ),
		);
	}

	/**
	 * Move an order's status behind every cache's back, the way another
	 * request would.
	 *
	 * @param int    $order_id Order to move.
	 * @param string $status   Status with the wc- prefix.
	 */
	private function write_status_directly( int $order_id, string $status ): void {
		global $wpdb;

		if ( $this->on_legacy_storage() ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_status = %s WHERE ID = %d", $status, $order_id ) );
			return;
		}

		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wc_orders SET status = %s WHERE id = %d", $status, $order_id ) );
	}

	/**
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_reload_sees_a_write_this_request_did_not_make( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$order = $this->make_xpay_order();
		$order->set_status( 'pending' );
		$order->save();

		// Warm every cache this request has, the way a real request does
		// before it ever reaches the lock.
		wc_get_order( $order->get_id() );

		$this->write_status_directly( $order->get_id(), 'wc-processing' );

		$fresh = XPay_Order_Sync::reload( $order->get_id() );

		$this->assertInstanceOf( 'WC_Order', $fresh );
		$this->assertSame(
			'processing',
			$fresh->get_status(),
			'reload() returned this request\'s cached copy, so the second writer will re-apply a transition the first already made.'
		);
	}

	/**
	 * The guard both confirmation paths actually ask, stated directly.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_an_order_paid_elsewhere_reads_back_as_paid( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$order = $this->make_xpay_order();
		$order->set_status( 'pending' );
		$order->save();
		wc_get_order( $order->get_id() );

		$this->write_status_directly( $order->get_id(), 'wc-processing' );

		$this->assertTrue(
			XPay_Order_Sync::reload( $order->get_id() )->is_paid(),
			'This is the exact test guarding mark_paid(); false here means the payment is applied twice.'
		);
	}

	/**
	 * Eviction must leave the SHARED cache correct too, not just hand this
	 * one caller a fresh object. Anything later in the request that calls
	 * wc_get_order() has to see the same order.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_the_shared_cache_agrees_after_a_reload( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$order = $this->make_xpay_order();
		$order->set_status( 'pending' );
		$order->save();
		wc_get_order( $order->get_id() );

		$this->write_status_directly( $order->get_id(), 'wc-processing' );
		XPay_Order_Sync::reload( $order->get_id() );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * The cache class is named by a string literal, so a WooCommerce release
	 * that moves it would put us straight back into the double-write. This
	 * fails loudly on upgrade instead.
	 */
	public function test_the_hpos_order_cache_is_where_we_think_it_is(): void {
		$this->assertTrue(
			class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ),
			'WooCommerce moved OrderCache. reload() evicts by class name and is now a no-op.'
		);
		$this->assertInstanceOf(
			\Automattic\WooCommerce\Caches\OrderCache::class,
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )
		);
	}

	/**
	 * A missing order is a null, not a fatal — the webhook path returns
	 * early on it.
	 */
	public function test_reloading_an_order_that_does_not_exist_answers_null(): void {
		$this->assertNull( XPay_Order_Sync::reload( 999999 ) );
	}
}
