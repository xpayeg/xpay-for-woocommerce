<?php
/**
 * The Payment Methods tab's model, against real WordPress options.
 *
 * Three rules under test. The CHECKED LIST decides what the store offers:
 * an unchecked method loses its checkout row on every surface and is not
 * on the accepted list a session would carry. The SAVED ORDER decides how
 * the rows stand, self-healing when the account gains or loses methods.
 * And the order reaches the real checkout through WooCommerce's own
 * gateway ordering, where the Payments table's drag-save keeps erasing
 * hidden rows and the sync keeps putting them back.
 *
 * @package XPay_For_WooCommerce
 */

class PaymentMethodsTabTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		$this->configure_gateway(
			array(
				'enabled'              => 'yes',
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_tab',
				'test_publishable_key' => 'pk_test_tab',
			)
		);
		update_option( 'woocommerce_currency', 'EGP' );
		update_option(
			XPay_Constants::account_methods_option( false ),
			array(
				'EGP' => array( 'card', 'valu', 'fawry' ),
				'USD' => array( 'card' ),
			)
		);
	}

	public function tear_down(): void {
		delete_option( XPay_Constants::account_methods_option( false ) );
		delete_option( XPay_Constants::OPTION_ENABLED_METHODS );
		delete_option( XPay_Constants::OPTION_METHOD_ORDER );
		delete_option( 'woocommerce_gateway_order' );
		update_option( 'woocommerce_currency', 'EGP' );
		$_POST = array();
		parent::tear_down();
	}

	/** @return string[] Registered xpay row ids, in registration order. */
	private function registered_row_ids(): array {
		$ids = array();
		foreach ( XPay_Plugin::instance()->register_gateway( array() ) as $gateway ) {
			if ( $gateway instanceof WC_Payment_Gateway && XPay_Constants::is_xpay_gateway( (string) $gateway->id ) ) {
				$ids[] = (string) $gateway->id;
			}
		}
		return $ids;
	}

	/* ── The checked list gates every surface ────────────────────────── */

	public function test_an_unchecked_method_loses_its_row_everywhere(): void {
		update_option( XPay_Constants::OPTION_ENABLED_METHODS, array( 'card', 'fawry' ) );

		$this->assertSame( array( 'xpay', 'xpay_fawry' ), $this->registered_row_ids(), 'An unchecked method must not even register.' );
		$this->assertFalse( $this->gateway()->method_active_for_currency( 'valu' ) );
		$this->assertFalse( ( new XPay_Method_Gateway( 'valu' ) )->is_available() );
		$this->assertTrue( ( new XPay_Method_Gateway( 'fawry' ) )->is_available() );
	}

	public function test_unchecking_card_hides_the_card_row_but_not_the_others(): void {
		update_option( XPay_Constants::OPTION_ENABLED_METHODS, array( 'valu', 'fawry' ) );

		$gateway = $this->gateway();
		$this->assertFalse( $gateway->is_available(), 'The main gateway is the card row; unchecked card hides it.' );
		$this->assertTrue( $gateway->offers_any_method(), 'The other rows still serve the currency, so the scripts must still load.' );
		$this->assertSame( array( 'valu', 'fawry' ), $gateway->accepted_types_for_currency( 'EGP' ) );
	}

	public function test_the_accepted_list_is_the_checked_list_cut_to_the_currency(): void {
		update_option( XPay_Constants::OPTION_ENABLED_METHODS, array( 'card', 'valu' ) );

		$gateway = $this->gateway();
		$this->assertSame( array( 'card', 'valu' ), $gateway->accepted_types_for_session( 'EGP' ) );
		$this->assertSame( array( 'card' ), $gateway->accepted_types_for_session( 'USD' ), 'USD carries card only on this account.' );
	}

	public function test_a_stored_list_that_lost_every_account_method_offers_nothing(): void {
		// A vanished checked method must not re-enable unchecked methods.
		update_option( XPay_Constants::OPTION_ENABLED_METHODS, array( 'meeza' ) );

		$gateway = $this->gateway();
		$this->assertSame( array(), $gateway->enabled_method_types() );
		$this->assertFalse( $gateway->is_available(), 'The card row must hide: card is not offered.' );
		$this->assertFalse( $gateway->offers_any_method(), 'No offered method means no XPay scripts and no rows on any surface.' );
		$this->assertSame( array(), $gateway->accepted_types_for_session( 'EGP' ), 'A forged checkout must not fall back to the account default.' );
		$this->assertSame( array( 'xpay' ), $this->registered_row_ids(), 'Only the settings-anchor gateway registers, and it is hidden.' );
		$this->assertSame( array( 'card', 'valu', 'fawry' ), $gateway->ordered_method_types(), 'The tab still shows the account\'s methods, so the merchant can check one and recover.' );
	}

	public function test_no_stored_list_means_everything_is_offered(): void {
		$this->assertSame( array( 'card', 'valu', 'fawry' ), $this->gateway()->enabled_method_types() );
	}

	/* ── The saved order self-heals ──────────────────────────────────── */

	public function test_the_saved_order_arranges_rows_and_registration(): void {
		update_option( XPay_Constants::OPTION_METHOD_ORDER, array( 'valu', 'fawry', 'card' ) );

		$this->assertSame( array( 'valu', 'fawry', 'card' ), $this->gateway()->ordered_method_types() );
		$this->assertSame( array( 'xpay', 'xpay_valu', 'xpay_fawry' ), $this->registered_row_ids(), 'The main gateway registers first regardless; positions come from the gateway-order option.' );
		$this->assertSame( array( 'valu', 'fawry' ), $this->gateway()->method_row_types(), 'The method rows follow the saved order.' );
	}

	public function test_the_order_heals_against_the_account_map(): void {
		// 'meeza' left the account, 'fawry' joined it after the order was
		// saved: the vanished one drops, the new one appends at the end.
		update_option( XPay_Constants::OPTION_METHOD_ORDER, array( 'valu', 'meeza', 'card' ) );

		$this->assertSame( array( 'valu', 'card', 'fawry' ), $this->gateway()->ordered_method_types() );
		$this->assertSame(
			array( 'valu', 'meeza', 'card' ),
			get_option( XPay_Constants::OPTION_METHOD_ORDER ),
			'Healing is in memory: a plane flip must never quietly rewrite the stored list.'
		);
	}

	/* ── The order reaches the real checkout ─────────────────────────── */

	public function test_sync_inserts_the_rows_at_the_xpay_position(): void {
		update_option( XPay_Constants::OPTION_METHOD_ORDER, array( 'valu', 'card', 'fawry' ) );
		update_option(
			'woocommerce_gateway_order',
			array(
				'bacs' => '0',
				'xpay' => '1',
				'cod'  => '2',
			)
		);

		XPay_Plugin::sync_gateway_order();

		$this->assertSame(
			array(
				'bacs'       => '0',
				'xpay_valu'  => '1',
				'xpay'       => '2',
				'xpay_fawry' => '3',
				'cod'        => '4',
			),
			get_option( 'woocommerce_gateway_order' ),
			'The XPay block lands where the merchant put XPay, in the saved order; every other gateway keeps its place.'
		);
	}

	public function test_sync_appends_when_xpay_was_never_ordered(): void {
		update_option( 'woocommerce_gateway_order', array( 'cod' => '0' ) );

		XPay_Plugin::sync_gateway_order();

		$order = get_option( 'woocommerce_gateway_order' );
		$this->assertSame( array( 'cod', 'xpay', 'xpay_valu', 'xpay_fawry' ), array_keys( $order ) );
	}

	public function test_rendering_the_settings_screen_heals_a_stale_gateway_order(): void {
		// The state a long-lived store is in: every other sync trigger
		// (key save, version bump, option update) fired in the past,
		// while the option still carries relic entries from an older
		// install: rows in the wrong order and an id that no longer
		// exists. The settings screen showing the merchant an order is
		// the moment it has to be true, so rendering it heals the option.
		remove_action( 'update_option_woocommerce_gateway_order', array( 'XPay_Plugin', 'sync_gateway_order' ) );
		update_option(
			'woocommerce_gateway_order',
			array(
				'xpay'       => '0',
				'xpay_card'  => '1',
				'xpay_valu'  => '2',
				'xpay_fawry' => '3',
				'cod'        => '4',
			)
		);
		add_action( 'update_option_woocommerce_gateway_order', array( 'XPay_Plugin', 'sync_gateway_order' ) );

		ob_start();
		( new XPay_Gateway() )->admin_options();
		ob_end_clean();

		$this->assertSame(
			array( 'xpay', 'xpay_valu', 'xpay_fawry', 'cod' ),
			array_keys( get_option( 'woocommerce_gateway_order' ) ),
			'The rows must follow the account order and the relic xpay_card entry must be gone.'
		);
	}

	public function test_a_payments_table_save_cannot_erase_the_hidden_rows(): void {
		update_option( 'woocommerce_gateway_order', array( 'xpay' => '0', 'cod' => '1', 'xpay_valu' => '2', 'xpay_fawry' => '3' ) );

		// The Payments table shows only the visible gateways and rebuilds
		// the option from them; the hidden method rows fall out. The
		// update_option hook must put them straight back.
		update_option( 'woocommerce_gateway_order', array( 'cod' => '0', 'xpay' => '1' ) );

		$this->assertSame(
			array( 'cod', 'xpay', 'xpay_valu', 'xpay_fawry' ),
			array_keys( get_option( 'woocommerce_gateway_order' ) ),
			'The drag-save dropped the hidden rows and nothing re-inserted them.'
		);
	}

	/* ── The settings form saves the checked list ────────────────────── */

	/** Run process_admin_options with a scripted POST, network-stubbed. */
	private function save_settings( array $post ): void {
		$GLOBALS['xpay_test_http'] = array(
			'api.xpay.app' => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'id' => 'acct_1' ) ),
			),
		);
		$_POST = $post;
		( new XPay_Gateway() )->process_admin_options();
		$_POST                     = array();
		$GLOBALS['xpay_test_http'] = array();
	}

	public function test_the_form_save_persists_the_checked_list(): void {
		$this->save_settings(
			array(
				'xpay_methods_present' => '1',
				'xpay_method_enabled'  => array( 'card', 'fawry' ),
			)
		);

		$this->assertSame( array( 'card', 'fawry' ), get_option( XPay_Constants::OPTION_ENABLED_METHODS ) );
	}

	public function test_unchecking_everything_is_refused_and_keeps_the_previous_list(): void {
		update_option( XPay_Constants::OPTION_ENABLED_METHODS, array( 'card', 'valu' ) );

		$this->save_settings( array( 'xpay_methods_present' => '1' ) );

		$this->assertSame( array( 'card', 'valu' ), get_option( XPay_Constants::OPTION_ENABLED_METHODS ), 'All-unchecked must be refused, never saved.' );
	}

	public function test_a_save_without_the_tab_leaves_the_list_alone(): void {
		update_option( XPay_Constants::OPTION_ENABLED_METHODS, array( 'card' ) );

		// No marker field: the page that posted never rendered the tab
		// (the no-map state), so the checkboxes' absence means nothing.
		$this->save_settings( array() );

		$this->assertSame( array( 'card' ), get_option( XPay_Constants::OPTION_ENABLED_METHODS ) );
	}

	public function test_unknown_types_in_the_post_never_reach_storage(): void {
		$this->save_settings(
			array(
				'xpay_methods_present' => '1',
				'xpay_method_enabled'  => array( 'card', 'stolen_type' ),
			)
		);

		$this->assertSame( array( 'card' ), get_option( XPay_Constants::OPTION_ENABLED_METHODS ) );
	}
}
