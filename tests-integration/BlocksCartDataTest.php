<?php
/**
 * The Blocks rows mount with the cart total in XPay minor units, read off
 * the Store API cart.
 *
 * Blocks' own cart total is in the store's decimals setting, not the
 * currency's: a store set to 0 decimals reports EGP 349 as 349, which the
 * rows once read as 349 piasters and so held every method back by its
 * minimum. The server converts the total with XPayEG_Money instead.
 *
 * @package XPayEG_For_WooCommerce
 */

use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;

class BlocksCartDataTest extends XPayEG_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EGP' );
		wc_load_cart();
		WC()->cart->empty_cart();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		delete_option( 'woocommerce_price_num_decimals' );
		parent::tear_down();
	}

	private function cart_with( string $price, int $store_decimals ): void {
		update_option( 'woocommerce_price_num_decimals', (string) $store_decimals );
		WC()->cart->add_to_cart( $this->make_product( $price )->get_id() );
		WC()->cart->calculate_totals();
	}

	public function test_a_store_set_to_zero_decimals_still_gets_piasters(): void {
		$this->cart_with( '349', 0 );

		$this->assertSame(
			array(
				'amount'   => 34900,
				'currency' => 'EGP',
			),
			XPayEG_Blocks_Support::cart_data()
		);
	}

	public function test_a_store_set_to_two_decimals_is_unchanged(): void {
		$this->cart_with( '349.50', 2 );

		$this->assertSame( 34950, XPayEG_Blocks_Support::cart_data()['amount'] );
	}

	public function test_an_empty_cart_has_nothing_to_pay(): void {
		$this->assertNull( XPayEG_Blocks_Support::cart_data()['amount'] );
	}

	public function test_the_store_api_cart_carries_it(): void {
		$this->cart_with( '349', 0 );

		$data = (array) StoreApi::container()->get( ExtendSchema::class )->get_endpoint_data( CartSchema::IDENTIFIER );

		$this->assertArrayHasKey( XPayEG_Blocks_Support::CART_DATA_NAMESPACE, $data );
		$this->assertSame( 34900, $data[ XPayEG_Blocks_Support::CART_DATA_NAMESPACE ]['amount'] );
	}
}
