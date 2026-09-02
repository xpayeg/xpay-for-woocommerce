<?php
/**
 * Proves the harness itself is real before anything is asserted through it.
 *
 * @package XPay_For_WooCommerce
 */

class SmokeTest extends XPay_Integration_Test_Case {

	public function test_woocommerce_and_the_plugin_are_both_loaded(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce did not load.' );
		$this->assertTrue( class_exists( 'XPay_Plugin' ), 'The plugin did not load.' );
		$this->assertTrue( class_exists( 'XPay_Gateway' ), 'The gateway did not load.' );
	}

	public function test_the_gateway_is_registered_with_woocommerce(): void {
		$ids = array_map(
			static function ( $gateway ) {
				return $gateway->id;
			},
			WC()->payment_gateways()->payment_gateways()
		);
		$this->assertContains( 'xpay', $ids, 'The gateway is not registered with WooCommerce.' );
	}

	public function test_orders_can_be_written_and_read_on_hpos(): void {
		$this->use_hpos( true );
		$order = $this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_hpos' ) );
		$this->assertSame( 'pi_hpos', wc_get_order( $order->get_id() )->get_meta( '_xpay_payment_intent_id' ) );
	}

	public function test_orders_can_be_written_and_read_on_legacy_storage(): void {
		$this->use_hpos( false );
		$order = $this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_legacy' ) );
		$this->assertSame( 'pi_legacy', wc_get_order( $order->get_id() )->get_meta( '_xpay_payment_intent_id' ) );
	}
}
