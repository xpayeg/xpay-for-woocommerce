<?php
/**
 * The XPay rows are put first ONCE, on activation, and never moved again.
 *
 * A merchant installing a payment gateway means to be paid through it, so
 * arriving below Cash on delivery is a poor default. But the order is
 * theirs: reordering a merchant's checkout on every load is what
 * wordpress.org review treats as misbehaviour, and it would silently
 * overrule a choice they made by dragging the rows.
 *
 * @package XPay_For_WooCommerce
 */

class GatewayOrderTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		delete_option( XPay_Gateway_Order::OPTION_APPLIED );
		delete_option( 'woocommerce_gateway_order' );
	}

	public function tear_down(): void {
		delete_option( XPay_Gateway_Order::OPTION_APPLIED );
		delete_option( 'woocommerce_gateway_order' );
		parent::tear_down();
	}

	/** The real shape, taken from a live store. */
	private function a_store_where_xpay_is_last(): void {
		update_option(
			'woocommerce_gateway_order',
			array(
				'bacs'          => 4,
				'cheque'        => 5,
				'cod'           => 6,
				'xpay_gateway'  => 7,
				'xpay'          => 8,
			)
		);
	}

	private function order(): array {
		$saved = get_option( 'woocommerce_gateway_order' );
		asort( $saved );
		return array_keys( $saved );
	}

	/* ── What it does ────────────────────────────────────────────────── */

	public function test_xpay_is_first_after_activation(): void {
		$this->a_store_where_xpay_is_last();

		XPay_Gateway_Order::apply_default();

		$order = $this->order();
		$this->assertSame(
			'xpay',
			$order[0],
			'A merchant who just installed a payment gateway finds it below Cash on delivery.'
		);
	}

	/**
	 * Whichever row sorts first is the one Blocks preselects, so this is
	 * also what makes XPay the selected method. One decision, not two.
	 */
	public function test_every_other_gateway_keeps_its_relative_order(): void {
		$this->a_store_where_xpay_is_last();

		XPay_Gateway_Order::apply_default();

		$rest = array_values( array_filter( $this->order(), function ( $id ) {
			return ! XPay_Constants::is_xpay_gateway( $id );
		} ) );
		$this->assertSame(
			array( 'bacs', 'cheque', 'cod' ),
			$rest,
			'A merchant who had arranged the other rows finds them rearranged.'
		);
	}

	/**
	 * The bug that made "once, ever" mean "never".
	 *
	 * The flag was written at the top of apply_default(), and activation is
	 * the one moment our row cannot be in WooCommerce's map — WordPress
	 * fires activate_{plugin} long after plugins_loaded, and WooCommerce
	 * fills the map afterwards. So the single shot was always spent on a
	 * map that could not contain the row, and no store was ever reordered.
	 */
	public function test_a_run_with_nothing_to_move_does_not_spend_the_one_shot(): void {
		// The map as it looks at activation: real gateways, no xpay yet.
		update_option( 'woocommerce_gateway_order', array( 'bacs' => 0, 'cheque' => 1, 'cod' => 2 ) );

		XPay_Gateway_Order::apply_default();

		$this->assertSame(
			'',
			(string) get_option( XPay_Gateway_Order::OPTION_APPLIED, '' ),
			'Activation marked itself done before it could do anything, so the reorder never happens.'
		);

		// WooCommerce fills the map in later, and the next run does the work.
		update_option( 'woocommerce_gateway_order', array( 'bacs' => 0, 'cheque' => 1, 'cod' => 2, 'xpay' => 3 ) );
		XPay_Gateway_Order::apply_default();

		$this->assertSame( 'xpay', $this->order()[0] );
		$this->assertNotSame( '', (string) get_option( XPay_Gateway_Order::OPTION_APPLIED, '' ), 'The run that did the work must spend the shot.' );
	}

	/* ── What it must never do ───────────────────────────────────────── */

	/**
	 * The whole point. A merchant who moves the row back keeps it moved,
	 * including across a deactivate and reactivate.
	 */
	public function test_a_merchant_who_reorders_afterwards_is_not_overruled(): void {
		$this->a_store_where_xpay_is_last();
		XPay_Gateway_Order::apply_default();

		// The merchant drags Cash on delivery back to the top.
		update_option( 'woocommerce_gateway_order', array( 'cod' => 0, 'xpay' => 1, 'bacs' => 2 ) );

		// Reactivated later.
		XPay_Gateway_Order::apply_default();

		$this->assertSame( 'cod', $this->order()[0], 'Reinstalling rearranged a checkout the merchant had arranged.' );
	}

	public function test_it_runs_once_even_within_one_install(): void {
		$this->a_store_where_xpay_is_last();
		XPay_Gateway_Order::apply_default();
		update_option( 'woocommerce_gateway_order', array( 'cod' => 0, 'xpay' => 1 ) );

		XPay_Gateway_Order::apply_default();
		XPay_Gateway_Order::apply_default();

		$this->assertSame( 'cod', $this->order()[0] );
	}

	/**
	 * Nothing has ever ordered the rows, so there is nothing to reorder —
	 * and nothing to mark done either.
	 *
	 * A store WooCommerce has not written a map for yet has not been given a
	 * default, so the next run must still be allowed to try.
	 */
	public function test_an_untouched_store_is_left_for_woocommerce_to_fill(): void {
		XPay_Gateway_Order::apply_default();

		$this->assertFalse( get_option( 'woocommerce_gateway_order' ), 'A partial map would pin gateways this store may not have.' );
		$this->assertSame(
			'',
			(string) get_option( XPay_Gateway_Order::OPTION_APPLIED, '' ),
			'Marking a no-op run as done is what made the default never apply.'
		);
	}

	public function test_a_store_with_no_xpay_row_is_untouched(): void {
		update_option( 'woocommerce_gateway_order', array( 'cod' => 0, 'bacs' => 1 ) );

		XPay_Gateway_Order::apply_default();

		$this->assertSame( array( 'cod', 'bacs' ), $this->order() );
	}
}
