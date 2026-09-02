<?php
/**
 * One checkout row per payment method, against real WooCommerce.
 *
 * The account map cached at key save decides which rows exist (one per
 * method the account can charge, the main gateway being the Card row)
 * and which show for the store's currency. Before any map is cached the
 * plugin registers only the single "XPay" row, unfiltered — the state
 * every store is in when keys were written around the save path.
 *
 * The method rows are NOT processors: everything that moves money
 * forwards to the main gateway, so there is exactly one implementation.
 *
 * @package XPay_For_WooCommerce
 */

class MethodRowsTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		$this->configure_gateway(
			array(
				'enabled'              => 'yes',
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_rows',
				'test_publishable_key' => 'pk_test_rows',
			)
		);
		update_option( 'woocommerce_currency', 'EGP' );
	}

	public function tear_down(): void {
		delete_option( XPay_Constants::account_methods_option( false ) );
		update_option( 'woocommerce_currency', 'EGP' );
		parent::tear_down();
	}

	private function cache_map(): void {
		update_option(
			XPay_Constants::account_methods_option( false ),
			array(
				'EGP' => array( 'card', 'valu', 'fawry' ),
				'USD' => array( 'card' ),
			)
		);
	}

	/** @return array<string, WC_Payment_Gateway> Registered xpay rows by id. */
	private function registered_rows(): array {
		$rows = array();
		foreach ( XPay_Plugin::instance()->register_gateway( array() ) as $gateway ) {
			if ( $gateway instanceof WC_Payment_Gateway && XPay_Constants::is_xpay_gateway( (string) $gateway->id ) ) {
				$rows[ (string) $gateway->id ] = $gateway;
			}
		}
		return $rows;
	}

	public function test_every_account_method_gets_its_own_row(): void {
		$this->cache_map();

		$rows = $this->registered_rows();

		$this->assertSame(
			array( 'xpay', 'xpay_valu', 'xpay_fawry' ),
			array_keys( $rows ),
			'The account can charge three methods, so the checkout offers three rows, card first.'
		);
		$this->assertInstanceOf( 'XPay_Gateway', $rows['xpay'] );
		$this->assertInstanceOf( 'XPay_Method_Gateway', $rows['xpay_valu'] );
	}

	public function test_without_a_cached_map_only_the_single_row_registers(): void {
		$rows = $this->registered_rows();

		$this->assertSame(
			array( 'xpay' ),
			array_keys( $rows ),
			'Keys written around the save path must fall back to the one unfiltered row, never to nothing.'
		);
	}

	public function test_rows_follow_the_currency(): void {
		$this->cache_map();
		update_option( 'woocommerce_currency', 'USD' );

		$rows = $this->registered_rows();

		$this->assertTrue( $rows['xpay']->is_available(), 'USD carries card.' );
		$this->assertFalse( $rows['xpay_valu']->is_available(), 'valU cannot charge USD on this account.' );
		$this->assertFalse( $rows['xpay_fawry']->is_available() );
	}

	public function test_the_card_row_hides_when_the_account_cannot_card_this_currency(): void {
		update_option(
			XPay_Constants::account_methods_option( false ),
			array( 'EGP' => array( 'valu' ) )
		);

		$rows = $this->registered_rows();

		$this->assertFalse( $rows['xpay']->is_available(), 'A card row for an account with no card processor dead-ends every shopper.' );
		$this->assertTrue( $rows['xpay_valu']->is_available() );
	}

	public function test_the_card_row_presents_itself_as_card(): void {
		$this->cache_map();

		$this->assertSame( 'Card', ( new XPay_Gateway() )->get_title() );
	}

	public function test_the_fallback_row_keeps_the_merchant_title(): void {
		$this->configure_gateway( array( 'title' => 'XPay' ) );

		$this->assertSame( 'XPay', ( new XPay_Gateway() )->get_title() );
	}

	public function test_method_rows_never_appear_on_the_legacy_gateway_table(): void {
		$this->cache_map();

		WC()->payment_gateways->payment_gateways = array_values( $this->registered_rows() );
		XPay_Plugin::hide_method_rows_in_admin();

		$survivors = array();
		foreach ( WC()->payment_gateways->payment_gateways as $gateway ) {
			$survivors[] = $gateway->id;
		}
		// Restore the real gateway list for later tests: init_payment_gateways
		// is not re-entrant, so reload from the filter chain.
		WC()->payment_gateways->init();

		$this->assertSame(
			array( 'xpay' ),
			$survivors,
			'Merchants manage ONE XPay entry; the method rows are checkout rows, not integrations.'
		);
	}

	/**
	 * The reactified Payments page loads its providers over REST, where
	 * is_admin() is false and no admin hook can help, and hides a gateway
	 * only when it is a "shell": empty method title AND description, from
	 * a plugin that also registers a non-shell gateway
	 * (PaymentsProviders.php:637). Each method row shipping a method
	 * title made every method its own provider row on that page, as if
	 * each were a separate plugin. This pins the shell contract: the rows
	 * empty, the main gateway not.
	 */
	public function test_method_rows_are_shells_for_the_reactified_payments_page(): void {
		$this->cache_map();

		$row = new XPay_Method_Gateway( 'valu' );
		$this->assertSame( '', (string) $row->get_method_title(), 'A method title makes the row its own provider on the reactified Payments page.' );
		$this->assertSame( '', (string) $row->get_method_description() );

		$main = new XPay_Gateway();
		$this->assertNotSame( '', (string) $main->get_method_title(), 'The main gateway must stay the non-shell, or the shell rule stops hiding anything.' );
		$this->assertNotSame( '', (string) $main->get_method_description() );
	}

	public function test_a_method_row_forwards_its_refund_to_the_one_implementation(): void {
		$this->cache_map();
		$order = $this->make_xpay_order();
		$order->set_payment_method( 'xpay_valu' );
		$order->save();

		$row = new XPay_Method_Gateway( 'valu' );

		// No payment intent on the order: the shared can_refund_order
		// refuses, proving the call reached the main gateway's rule
		// rather than WC_Payment_Gateway's default (which answers true).
		$this->assertFalse( $row->can_refund_order( $order ) );
	}

	public function test_a_method_rows_fields_are_stamped_with_its_method(): void {
		$this->cache_map();

		ob_start();
		( new XPay_Method_Gateway( 'fawry' ) )->payment_fields();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-xpay-method="fawry"', $html );
	}

	public function test_the_fallback_rows_fields_carry_no_method(): void {
		ob_start();
		( new XPay_Gateway() )->payment_fields();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-xpay-method=""', $html, 'No map cached: the fields render every method, unfiltered.' );
	}

	/**
	 * The Blocks registrations, captured through the real registration
	 * action with a spy registry. WP's test framework restores the global
	 * hook table between tests, so clearing the action here cannot leak.
	 *
	 * @return array<string, XPay_Blocks_Support> Registered rows by name.
	 */
	private function registered_blocks_rows(): array {
		remove_all_actions( 'woocommerce_blocks_payment_method_type_registration' );
		XPay_Blocks_Support::register();

		$registry = new class() {
			/** @var array<string, XPay_Blocks_Support> */
			public $rows = array();

			/** @param XPay_Blocks_Support $type Row being registered. */
			public function register( $type ): void {
				$this->rows[ $type->get_name() ] = $type;
			}
		};
		do_action( 'woocommerce_blocks_payment_method_type_registration', $registry );

		foreach ( $registry->rows as $row ) {
			$row->initialize();
		}
		return $registry->rows;
	}

	/**
	 * The Blocks fallback row must answer availability the way the classic
	 * fallback row does. It was hardcoded active: a store whose keys were
	 * removed — one Disconnect click away — kept a dead XPay row on the
	 * Blocks checkout while the classic checkout hid it.
	 */
	public function test_the_blocks_fallback_row_follows_gateway_availability(): void {
		$rows = $this->registered_blocks_rows();
		$this->assertTrue( $rows['xpay']->is_active(), 'Keys saved and currency chargeable: the fallback row shows.' );

		$this->configure_gateway(
			array(
				'test_api_key'         => '',
				'test_publishable_key' => '',
			)
		);

		$rows = $this->registered_blocks_rows();
		$this->assertFalse( $rows['xpay']->is_active(), 'A row with no keys can only dead-end the shopper; the classic checkout hides it, Blocks must agree.' );
	}
}
