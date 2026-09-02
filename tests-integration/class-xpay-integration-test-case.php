<?php
/**
 * Base case for the real-WordPress suite.
 *
 * Holds the two things every test here needs and nothing else: a way to put
 * the shop on a chosen order storage, and a way to configure the gateway
 * without going through the settings screen.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

abstract class XPay_Integration_Test_Case extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The spy accumulates for the whole process; each test asserts only
		// on what it wrote itself.
		XPay_Spy_Log_Handler::reset();
		// Webhook health is plain options; a stamp left by one test would
		// change another's verdict.
		XPay_Webhook_State::clear_state( false );
		XPay_Webhook_State::clear_state( true );
	}

	/**
	 * Put the shop on a specific order storage for the rest of the test.
	 *
	 * A1 is only reachable on the legacy post store, so a suite that runs
	 * exclusively on the default storage would have stayed green through a
	 * live refund bug. Every lookup test runs on both.
	 *
	 * @param bool $enabled True for HPOS (custom order tables), false for the
	 *                      legacy post store.
	 */
	protected function use_hpos( bool $enabled ): void {
		update_option( 'woocommerce_custom_orders_table_enabled', $enabled ? 'yes' : 'no' );
		update_option( 'woocommerce_feature_custom_order_tables_enabled', $enabled ? 'yes' : 'no' );

		// WC_Data_Store caches nothing between loads, but WC_Order holds the
		// store it was constructed with, so orders must be made after this.
		$this->assertSame(
			$enabled,
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
			$enabled ? 'Expected HPOS to be active.' : 'Expected the legacy post store to be active.'
		);
	}

	/**
	 * True when the storage under test is the legacy post store.
	 */
	protected function on_legacy_storage(): bool {
		return ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * A saved, paid-looking XPay order.
	 *
	 * @param array $meta Extra meta to stamp on the order.
	 * @return WC_Order
	 */
	protected function make_xpay_order( array $meta = array() ): WC_Order {
		$order = new WC_Order();
		$order->set_payment_method( 'xpay' );
		$order->set_currency( 'EGP' );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		return $order;
	}

	/**
	 * A saved, purchasable simple product.
	 *
	 * Hand-rolled rather than borrowed from WooCommerce's own test helpers:
	 * wordpress.org strips `tests/` out of the distributed ZIP, so those
	 * helpers do not exist on any store this plugin will ever run on, and a
	 * suite that depended on them would only run against a git checkout.
	 *
	 * @param string $price Regular price.
	 * @return WC_Product_Simple
	 */
	protected function make_product( string $price = '50' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Integration fixture' );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_stock_status( 'instock' );
		$product->save();
		return $product;
	}

	/**
	 * Write gateway settings straight to the option the gateway reads.
	 *
	 * @param array $settings Settings to merge over the defaults.
	 */
	protected function configure_gateway( array $settings ): void {
		$existing = get_option( 'woocommerce_xpay_settings', array() );
		$existing = is_array( $existing ) ? $existing : array();
		update_option( 'woocommerce_xpay_settings', array_merge( $existing, $settings ) );
	}

	/**
	 * The plugin's gateway instance, freshly constructed so it re-reads
	 * settings written during the test.
	 */
	protected function gateway(): WC_Payment_Gateway {
		return new XPay_Gateway();
	}

}
