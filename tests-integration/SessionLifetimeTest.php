<?php
/**
 * Sessions use the platform's default lifetime; the store overrides nothing.
 *
 * Deferred references complete the session as unpaid and report their later
 * outcome asynchronously.
 *
 * @package XPay_For_WooCommerce
 */

class SessionLifetimeTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		$this->configure_gateway(
			array(
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_ttl',
				'test_publishable_key' => 'pk_test_ttl',
			)
		);
		// The recorder is shared across a run and nothing resets it, so a
		// test reading "the last request" must start from a known point.
		$GLOBALS['xpay_test_http_requests'] = array();
		$GLOBALS['xpay_test_http']          = array(
			'api.xpay.app' => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'id'           => 'cs_test_ttl',
						'clientSecret' => 'cs_test_ttl_secret',
						'status'       => 'open',
						'lineItems'    => array( array( 'id' => 'li_test_ttl' ) ),
					)
				),
			),
		);
	}

	public function tear_down(): void {
		$GLOBALS['xpay_test_http']          = array();
		$GLOBALS['xpay_test_http_requests'] = array();
		parent::tear_down();
	}

	/** Every request this test made, newest last. */
	private function sent_bodies(): array {
		$bodies = array();
		foreach ( (array) ( $GLOBALS['xpay_test_http_requests'] ?? array() ) as $request ) {
			if ( isset( $request['body'] ) && is_string( $request['body'] ) ) {
				$decoded = json_decode( $request['body'], true );
				if ( is_array( $decoded ) ) {
					$bodies[] = $decoded;
				}
			}
		}
		return $bodies;
	}

	public function test_session_creation_sends_no_lifetime_override(): void {
		$order = $this->make_xpay_order();
		$order->set_total( '123.00' );
		$order->save();
		( new XPay_Checkout_Service( $this->gateway()->api_client() ) )->get_or_create_session( $order );

		$bodies = $this->sent_bodies();
		$this->assertNotEmpty( $bodies, 'No request was captured; the assertion below would pass vacuously.' );

		$last = end( $bodies );
		$this->assertArrayNotHasKey(
			'expiresAfterMinutes',
			$last,
			'The 30-hour override was built on a false premise about Fawry; the platform default stands.'
		);
	}

	/**
	 * The unpaid-order hold covers only the pending window.
	 *
	 * WooCommerce's sweep cancels `pending` orders only, and every XPay
	 * outcome leaves `pending` on its own: paid completes, deferred parks
	 * on-hold awaiting payment, expired fails. What remains to protect is
	 * the gap between order creation and the webhook that moves it, which
	 * is minutes on the happy path and bounded by the retry schedule
	 * otherwise. Two hours covers it; the old 30 hours protected a state
	 * the order is no longer in.
	 */
	public function test_the_unpaid_hold_covers_the_pending_window_only(): void {
		$this->assertSame( 2 * HOUR_IN_SECONDS, XPay_Order_Sync::UNPAID_GRACE_SECONDS );
	}
}
