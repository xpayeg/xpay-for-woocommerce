<?php
/**
 * The order-pay page's session endpoint.
 *
 * What it guards: the pay-link surface is public, so the endpoint must
 * answer only someone holding the order's own key (WooCommerce's proof of
 * access for a shopper with no account), and a stale link whose order was
 * already paid must be offered the receipt, never a second charge. The
 * session discipline underneath (reuse / reprice / supersede) is pinned by
 * SessionRetryDisciplineTest; this pins the transport around it.
 *
 * @package XPay_For_WooCommerce
 */

class OrderSessionEndpointTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		$this->configure_gateway(
			array(
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_ops',
				'test_publishable_key' => 'pk_test_ops',
			)
		);
		$GLOBALS['xpay_test_http'] = array(
			'api.xpay.app' => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'id'             => 'cs_ops_1',
						'clientSecret'   => 'cs_ops_1_secret',
						'status'         => 'open',
						'isExpired'      => false,
						'amountSubtotal' => 29000,
						'amountTotal'    => 29000,
						'currency'       => 'EGP',
						'lineItems'      => array( array( 'id' => 'li_1' ) ),
					)
				),
			),
		);
	}

	public function tear_down(): void {
		$GLOBALS['xpay_test_http'] = array();
		$_POST                     = array();
		parent::tear_down();
	}

	/**
	 * Call the endpoint the way admin-ajax would, returning its JSON.
	 *
	 * @param array $post POST fields (nonce added).
	 */
	private function call( array $post ): ?array {
		$_POST                     = $post;
		$_POST['nonce']            = wp_create_nonce( 'woocommerce_xpay_checkout_elements' );
		// check_ajax_referer reads $_REQUEST, which PHP populated before
		// this test wrote $_POST.
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$thrower = static function () {
			return static function () {
				throw new WPDieException( 'sent' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $thrower );

		ob_start();
		try {
			XPay_Checkout_Elements::handle_order_session();
			return json_decode( (string) ob_get_clean(), true );
		} catch ( WPDieException $e ) {
			return json_decode( (string) ob_get_clean(), true );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $thrower );
			$_POST    = array();
			$_REQUEST = array();
		}
	}

	private function order(): WC_Order {
		$order = $this->make_xpay_order();
		$order->set_total( '290.00' );
		$order->save();
		return $order;
	}

	public function test_the_order_key_is_the_whole_of_the_authorization(): void {
		$order = $this->order();

		$wrong = $this->call(
			array(
				'order' => (string) $order->get_id(),
				'key'   => 'wc_order_WRONG',
			)
		);

		$this->assertFalse( $wrong['success'], 'A guessed key must never reach a session.' );
		$this->assertSame( 'not-found', $wrong['data']['reason'], 'The refusal must be the key check, not an accident upstream.' );
		$this->assertArrayNotHasKey( 'clientSecret', (array) ( $wrong['data'] ?? array() ) );
	}

	public function test_a_valid_key_gets_the_orders_session_secret(): void {
		$order = $this->order();

		$answer = $this->call(
			array(
				'order' => (string) $order->get_id(),
				'key'   => (string) $order->get_order_key(),
			)
		);

		$this->assertTrue( $answer['success'] );
		$this->assertFalse( $answer['data']['paid'] );
		$this->assertSame( 'cs_ops_1_secret', $answer['data']['clientSecret'] );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'cs_ops_1', (string) $fresh->get_meta( XPay_Constants::META_SESSION_ID ), 'The one-session-per-order ledger must record what was handed out.' );
	}

	public function test_the_created_session_accepts_exactly_the_offered_methods(): void {
		update_option(
			XPay_Constants::account_methods_option( false ),
			array( 'EGP' => array( 'card', 'valu', 'fawry' ) )
		);
		update_option( XPay_Constants::OPTION_ENABLED_METHODS, array( 'card', 'fawry' ) );
		$GLOBALS['xpay_test_http_requests'] = array();
		$order                              = $this->order();

		$this->call(
			array(
				'order' => (string) $order->get_id(),
				'key'   => (string) $order->get_order_key(),
			)
		);

		$create = null;
		foreach ( (array) $GLOBALS['xpay_test_http_requests'] as $request ) {
			if ( 'POST' === strtoupper( (string) ( $request['method'] ?? '' ) )
				&& false !== strpos( (string) $request['url'], '/checkout/sessions' ) ) {
				$create = json_decode( (string) $request['body'], true );
			}
		}

		delete_option( XPay_Constants::account_methods_option( false ) );
		delete_option( XPay_Constants::OPTION_ENABLED_METHODS );

		$this->assertIsArray( $create, 'The endpoint must have created a session.' );
		$this->assertSame(
			array( 'card', 'fawry' ),
			$create['paymentMethodTypes'],
			'Pay-link sessions carry the checked list too: the tab\'s enforcement covers every surface, not just the checkout.'
		);
	}

	public function test_a_paid_order_is_offered_the_receipt_never_a_charge(): void {
		$order = $this->order();
		$order->payment_complete( 'pi_ops_paid' );

		$answer = $this->call(
			array(
				'order' => (string) $order->get_id(),
				'key'   => (string) $order->get_order_key(),
			)
		);

		$this->assertTrue( $answer['success'] );
		$this->assertTrue( $answer['data']['paid'] );
		$this->assertArrayNotHasKey( 'clientSecret', $answer['data'], 'A paid order must never be handed something payable.' );
	}
}
