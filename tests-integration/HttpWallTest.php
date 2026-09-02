<?php
/**
 * No test may reach the internet.
 *
 * This suite could, and it did. The settings-save path builds its own API
 * client inside the gateway, so a test exercising a save phoned
 * api.xpay.app for real with the made-up key the test had just configured.
 * Every run produced a live 401; a day of runs produced 85 of them in
 * XPay's monitoring, read there as a merchant polling with a rotated key.
 *
 * These pin the wall itself, because a wall nobody tests is a wall that
 * quietly comes down.
 *
 * @package XPay_For_WooCommerce
 */

class HttpWallTest extends XPay_Integration_Test_Case {

	public function tear_down(): void {
		$GLOBALS['xpay_test_http'] = array();
		parent::tear_down();
	}

	public function test_an_outbound_request_is_refused(): void {
		$response = wp_remote_get( 'https://api.xpay.app/refunds?limit=1' );

		$this->assertInstanceOf( 'WP_Error', $response, 'A test just reached a live system.' );
		$this->assertSame( 'xpay_test_http_blocked', $response->get_error_code() );
	}

	/**
	 * The refusal has to name the address, or the next person to trip it
	 * gets a failure with nothing to act on.
	 */
	public function test_the_refusal_says_where_the_test_was_going(): void {
		$response = wp_remote_get( 'https://example.test/somewhere' );

		$this->assertStringContainsString( 'https://example.test/somewhere', $response->get_error_message() );
	}

	/**
	 * The path that was actually calling out. It reaches the client through
	 * the gateway, which builds its own, so there is nothing to substitute
	 * except at the HTTP layer — which is the whole reason the wall lives
	 * there.
	 */
	public function test_the_account_check_never_leaves_the_machine(): void {
		$this->configure_gateway(
			array(
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_integration',
				'test_publishable_key' => 'pk_test_integration',
			)
		);

		try {
			$this->gateway()->api_client()->get_account();
			$this->fail( 'The account check reached the network.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::TRANSPORT_ERROR, $e->get_error_code() );
		}
	}

	/**
	 * A test that genuinely needs an answer says so, and gets exactly the
	 * one it asked for.
	 */
	public function test_a_scripted_response_is_served_instead(): void {
		$GLOBALS['xpay_test_http'] = array(
			'api.xpay.app' => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"data":[]}',
			),
		);

		$response = wp_remote_get( 'https://api.xpay.app/refunds?limit=1' );

		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( '{"data":[]}', wp_remote_retrieve_body( $response ) );
	}

	public function test_scripting_one_address_does_not_open_the_rest(): void {
		$GLOBALS['xpay_test_http'] = array( 'api.xpay.app' => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ) );

		$this->assertInstanceOf( 'WP_Error', wp_remote_get( 'https://checkout.xpay.app/v1/sdk.js' ) );
	}
}
