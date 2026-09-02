<?php
/**
 * The account cache's shelf life.
 *
 * The checkout asset pass re-reads a stale account cache once per window.
 * A failed read
 * keeps the last good values without retrying per page view.
 *
 * Driven through the real enqueue entry point, not the helper alone, so a
 * dropped wiring line fails here.
 *
 * @package XPay_For_WooCommerce
 */

class AccountCacheRefreshTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		$this->configure_gateway(
			array(
				'enabled'              => 'yes',
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_ttl',
				'test_publishable_key' => 'pk_test_ttl',
			)
		);
		update_option( 'woocommerce_currency', 'EGP' );
		update_option(
			XPay_Constants::account_methods_option( false ),
			array( 'EGP' => array( 'card' ) )
		);
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$GLOBALS['xpay_test_http']          = array();
		$GLOBALS['xpay_test_http_requests'] = array();
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_is_checkout', '__return_true' );
		// The enqueue pass this suite drives really enqueues, and enqueued
		// state outlives the test: without this, a later test asserting
		// "registration alone enqueues nothing" fails on this suite's
		// leftovers.
		wp_dequeue_script( XPay_Checkout_Elements::HANDLE );
		wp_dequeue_script( XPay_Checkout_Elements::DRIVER_HANDLE );
		wp_dequeue_style( XPay_Checkout_Elements::STYLE_HANDLE );
		delete_option( XPay_Constants::account_methods_option( false ) );
		delete_option( XPay_Constants::account_checked_option( false ) );
		$GLOBALS['xpay_test_http']          = array();
		$GLOBALS['xpay_test_http_requests'] = array();
		parent::tear_down();
	}

	/** GET /account answering a map that gained ValU since the cache was written. */
	private function script_account(): void {
		$GLOBALS['xpay_test_http'] = array(
			'/account' => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'id'                  => 'mer_ttl',
						'displayName'         => 'TTL Store',
						'supportedCurrencies' => array(
							array(
								'code'               => 'EGP',
								'paymentMethodTypes' => array( 'card', 'valu' ),
							),
						),
					)
				),
			),
		);
	}

	/** @return array The requests the render made to /account. */
	private function account_requests(): array {
		$hits = array();
		foreach ( (array) $GLOBALS['xpay_test_http_requests'] as $request ) {
			if ( false !== strpos( (string) $request['url'], '/account' ) ) {
				$hits[] = $request;
			}
		}
		return $hits;
	}

	public function test_a_stale_cache_is_reread_once_by_the_checkout_render(): void {
		$this->script_account();

		// No stamp at all: the state of every store from before the shelf
		// life existed. The first checkout render heals it.
		XPay_Checkout_Elements::enqueue();

		$this->assertCount( 1, $this->account_requests(), 'A stale cache earns exactly one re-read.' );
		$this->assertSame(
			array( 'EGP' => array( 'card', 'valu' ) ),
			get_option( XPay_Constants::account_methods_option( false ) ),
			'The method the account gained must reach the cache without a key re-save.'
		);

		$GLOBALS['xpay_test_http_requests'] = array();
		XPay_Checkout_Elements::enqueue();
		$this->assertCount( 0, $this->account_requests(), 'The successful read restarted the shelf life; a fresh cache costs the checkout nothing.' );
	}

	public function test_a_fresh_cache_is_not_reread(): void {
		$this->script_account();
		update_option( XPay_Constants::account_checked_option( false ), time() - HOUR_IN_SECONDS );

		XPay_Checkout_Elements::enqueue();

		$this->assertCount( 0, $this->account_requests() );
	}

	public function test_a_failed_reread_keeps_the_cache_and_does_not_retry_per_view(): void {
		update_option( XPay_Constants::account_checked_option( false ), time() - 3 * HOUR_IN_SECONDS );
		$GLOBALS['xpay_test_http'] = array(
			'/account' => array(
				'response' => array( 'code' => 503 ),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'code'    => 'api_error',
							'message' => 'down',
						),
					)
				),
			),
		);

		XPay_Checkout_Elements::enqueue();
		XPay_Checkout_Elements::enqueue();

		$this->assertCount( 1, $this->account_requests(), 'A down API costs one bounded request per window, never one per page view.' );
		$this->assertSame(
			array( 'EGP' => array( 'card' ) ),
			get_option( XPay_Constants::account_methods_option( false ) ),
			'The last good facts stay in place when the re-read fails.'
		);
	}

	public function test_a_store_with_no_keys_never_reaches_the_api(): void {
		$this->configure_gateway(
			array(
				'test_api_key'         => '',
				'test_publishable_key' => '',
			)
		);

		XPay_Checkout_Elements::enqueue();

		$this->assertCount( 0, $this->account_requests(), 'No keys means nothing to authenticate a read with.' );
	}
}
