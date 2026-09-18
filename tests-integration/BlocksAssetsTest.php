<?php
/**
 * The Blocks bundle's dependencies must EXIST when it declares them.
 *
 * WooCommerce Blocks resolves a payment method's script dependencies while
 * it builds its own asset registry, and that runs on every page — not only
 * the checkout. The library handle was registered inside an enqueue behind
 * an is_checkout() guard, so on the shop, the cart and the front page
 * Blocks found nothing and answered by switching the gateway off:
 *
 *   Payment gateway with handle 'xpayeg-blocks' has been deactivated in Cart
 *   and Checkout blocks because its dependency 'xpayeg-elements' is not
 *   registered.
 *
 * Found in the wild, in a debug log full of it.
 *
 * @package XPayEG_For_WooCommerce
 */

class BlocksAssetsTest extends XPayEG_Integration_Test_Case {

	private function blocks_support(): XPayEG_Blocks_Support {
		return new XPayEG_Blocks_Support(
			'xpayeg',
			array(
				'title'       => 'XPay',
				'description' => '',
				'icon'        => '',
				'active'      => true,
			)
		);
	}

	public function test_declaring_the_bundle_registers_what_it_depends_on(): void {
		$handles = $this->blocks_support()->get_payment_method_script_handles();

		$this->assertContains( 'xpayeg-blocks', $handles );
		$this->assertTrue(
			wp_script_is( XPayEG_Checkout_Elements::HANDLE, 'registered' ),
			'Blocks switches the gateway off entirely when a declared dependency does not exist.'
		);
	}

	/**
	 * Registering is not enqueueing. Nothing may be emitted onto a page that
	 * did not ask for it — this runs on the front page too.
	 */
	public function test_registering_does_not_put_anything_on_the_page(): void {
		XPayEG_Checkout_Elements::register_scripts();

		foreach ( array( XPayEG_Checkout_Elements::HANDLE, XPayEG_Checkout_Elements::DRIVER_HANDLE ) as $handle ) {
			$this->assertFalse( wp_script_is( $handle, 'enqueued' ), "$handle was enqueued by mere registration." );
		}
	}

	/**
	 * Idempotent: Blocks calls this once per payment row, and WordPress
	 * warns about re-registering a handle it already knows.
	 */
	public function test_declaring_it_twice_is_harmless(): void {
		$this->blocks_support()->get_payment_method_script_handles();
		$this->blocks_support()->get_payment_method_script_handles();

		$this->assertTrue( wp_script_is( XPayEG_Checkout_Elements::HANDLE, 'registered' ) );
	}
}
