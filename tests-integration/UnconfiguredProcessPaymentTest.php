<?php
/**
 * Place Order on a store whose keys have gone missing.
 *
 * is_available() keeps an unconfigured gateway off the checkout, so this
 * needs the keys to be cleared while a shopper is mid-checkout. Rare, and
 * the outcome still has to be a failed payment rather than a white screen.
 *
 * @package XPay_For_WooCommerce
 */

class UnconfiguredProcessPaymentTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		// Enabled, and no keys of any kind.
		update_option(
			'woocommerce_xpay_settings',
			array(
				'enabled' => 'yes',
				'mode'    => 'test',
				'title'   => 'XPay',
			)
		);
	}

	/** A failed payment, not a fatal. */
	public function test_place_order_without_keys_fails_the_payment() {
		$order = wc_create_order();
		$order->set_total( '100.00' );
		$order->save();

		$result = ( new XPay_Gateway() )->process_payment( $order->get_id() );

		$this->assertIsArray( $result, 'process_payment did not return at all.' );
		$this->assertSame( 'failure', $result['result'] );
	}

	/** And the merchant can find out why, from the order itself. */
	public function test_the_order_records_why_nothing_was_charged() {
		$order = wc_create_order();
		$order->set_total( '100.00' );
		$order->save();

		( new XPay_Gateway() )->process_payment( $order->get_id() );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$text  = '';
		foreach ( $notes as $note ) {
			$text .= $note->content;
		}
		$this->assertStringContainsString( 'XPay', $text, 'Nothing on the order says why the payment did not start.' );
	}
}
