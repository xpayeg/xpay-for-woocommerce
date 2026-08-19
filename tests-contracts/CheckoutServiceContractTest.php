<?php
/**
 * Pins XPay_Checkout_Service's session lifecycle: what the create body
 * must carry, when an existing session may be reused, when it must be
 * superseded AND expired, and the customer-linking exclusivity rules.
 *
 * @package XPay_For_WooCommerce
 */

class CheckoutServiceContractTest extends ContractTestCase {

	/** @var XPay_Capture_Client */
	private $client;

	/** @var XPay_Checkout_Service */
	private $service;

	protected function setUp(): void {
		parent::setUp();
		$this->client  = new XPay_Capture_Client();
		$this->service = new XPay_Checkout_Service( $this->client );
	}

	private function order( array $props = array() ): WC_Order {
		return $this->makeOrder( 14, array_merge( array( 'total' => '290.00', 'billing_email' => 'shopper@example.com', 'billing_phone' => '+201001234567' ), $props ) );
	}

	public function test_create_body_contract() {
		$order = $this->order();

		$this->service->get_or_create_session( $order );

		$this->assertCount( 1, $this->client->created );
		$body = $this->client->created[0];

		$this->assertSame( 'hosted', $body['uiMode'] );
		$this->assertSame( 'EGP', $body['currency'] );
		$this->assertSame( 29000, (int) $body['lineItems'][0]['priceData']['unitAmount'] );
		$this->assertSame( 'en', $body['locale'] );
		$this->assertStringContainsString( 'xpay_session_id={CHECKOUT_SESSION_ID}', $body['afterCompletion']['redirect']['url'], 'The support breadcrumb placeholder must survive URL building.' );
		$this->assertSame( '14', $body['metadata']['wc_order_id'] );
		$this->assertSame( $order->get_order_key(), $body['metadata']['wc_order_key'] );
		$this->assertArrayNotHasKey( 'paymentMethodTypes', $body, 'The combined row never pins methods.' );
		$this->assertArrayNotHasKey( 'phoneNumberCollection', $body );

		$this->assertNotInstanceOf( 'stdClass', $body['lineItems'][0]['priceData']['unitAmount'] );
		$this->assertFalse( is_float( $body['lineItems'][0]['priceData']['unitAmount'] ), 'Minor units travel as strings or ints, never floats.' );
	}

	public function test_arabic_storefront_sends_arabic_locale() {
		$GLOBALS['xpay_test_locale'] = 'ar';
		$this->service->get_or_create_session( $this->order() );
		$this->assertSame( 'ar', $this->client->created[0]['locale'] );
	}

	public function test_valu_pin_collects_phone_but_card_pin_does_not() {
		$order = $this->order();

		$this->service->get_or_create_session( $order, array( XPay_Payment_Methods::VALU ) );
		$this->assertSame( array( 'valu' ), $this->client->created[0]['paymentMethodTypes'] );
		$this->assertTrue( $this->client->created[0]['phoneNumberCollection'] );

		$order->delete_meta_data( XPay_Constants::META_SESSION_ID );
		$this->service->get_or_create_session( $order, array( XPay_Payment_Methods::CARD ) );
		$this->assertArrayNotHasKey( 'phoneNumberCollection', $this->client->created[1] );
	}

	public function test_open_matching_session_is_reused() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );

		$session = $this->service->get_or_create_session( $order );

		$this->assertCount( 1, $this->client->created, 'Same order, same terms: reuse, never re-mint.' );
		$this->assertSame( $order->get_meta( XPay_Constants::META_SESSION_ID ), $session['id'] );
	}

	public function test_currency_change_supersedes_and_expires_old_session() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );
		$old_id = $order->get_meta( XPay_Constants::META_SESSION_ID );

		$this->client->session = array( 'currency' => 'USD' ); // The stored session now reads back in another currency.
		$this->service->get_or_create_session( $order );

		$this->assertCount( 2, $this->client->created );
		$this->assertSame( array( $old_id ), $this->client->expired, 'The superseded session must be expired, or it stays payable for 24h.' );
		$this->assertStageFired( 'session.superseded_expired' );
	}

	public function test_amount_change_supersedes_and_expires_old_session() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );
		$old_id = $order->get_meta( XPay_Constants::META_SESSION_ID );

		$order->total = '999.00';
		$this->service->get_or_create_session( $order );

		$this->assertCount( 2, $this->client->created );
		$this->assertSame( array( $old_id ), $this->client->expired );
	}

	public function test_supersede_records_the_old_session_id() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );
		$old_id = $order->get_meta( XPay_Constants::META_SESSION_ID );

		$order->total = '999.00';
		$this->service->get_or_create_session( $order );

		$this->assertContains( $old_id, $order->get_meta( XPay_Constants::META_SUPERSEDED_SESSIONS ), 'A paid event on the old id must stay recognizable as this order\'s money.' );
	}

	public function test_pin_change_mints_fresh_session() {
		$order = $this->order();
		$this->service->get_or_create_session( $order, array( XPay_Payment_Methods::CARD ) );

		$this->service->get_or_create_session( $order, array( XPay_Payment_Methods::VALU ) );

		$this->assertCount( 2, $this->client->created, 'A session pinned to card must never serve the valU row.' );
	}

	public function test_complete_paid_session_marks_order_paid_instead_of_reminting() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );

		// The stored session now reads back COMPLETE and PAID: a stale
		// emailed pay link whose webhook was lost or is still in flight.
		$this->client->session = array(
			'status'        => XPay_Session_Status::COMPLETE,
			'paymentStatus' => XPay_Payment_Status::PAID,
		);
		$session = $this->service->get_or_create_session( $order );

		$this->assertCount( 1, $this->client->created, 'Minting a fresh payable session over a paid one is how a shopper gets charged twice.' );
		$this->assertSame( XPay_Session_Status::COMPLETE, $session['status'] );
		$this->assertTrue( $order->is_paid(), 'The already-paid truth is applied, not just observed.' );
		$this->assertStageFired( 'session.already_complete' );
		$this->assertStageFired( 'order.paid' );
	}

	public function test_complete_paid_session_defers_to_a_busy_lock() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );

		$this->client->session          = array(
			'status'        => XPay_Session_Status::COMPLETE,
			'paymentStatus' => XPay_Payment_Status::PAID,
		);
		$GLOBALS['wpdb']->lock_results = array( '0' ); // The webhook holds the order lock right now.

		$session = $this->service->get_or_create_session( $order );

		$this->assertCount( 1, $this->client->created, 'Still no re-mint: the busy holder is applying this same truth.' );
		$this->assertSame( XPay_Session_Status::COMPLETE, $session['status'] );
		$this->assertFalse( $order->is_paid(), 'Deferring to the lock holder means writing nothing ourselves.' );
	}

	public function test_complete_unpaid_session_still_mints_fresh() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );

		$this->client->session = array(
			'status'        => XPay_Session_Status::COMPLETE,
			'paymentStatus' => XPay_Payment_Status::UNPAID,
		);
		$this->service->get_or_create_session( $order );

		$this->assertCount( 2, $this->client->created, 'Only COMPLETE and PAID means already-paid; completed-but-unpaid follows the normal new-session path.' );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_session_validation_stamps_freshness() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );
		$this->assertGreaterThan( 0, (int) $order->get_meta( XPay_Constants::META_SESSION_CHECKED_AT ), 'Creation counts as a confirmation.' );

		$order->update_meta_data( XPay_Constants::META_SESSION_CHECKED_AT, 0 );
		$this->service->get_or_create_session( $order );

		$this->assertGreaterThan( 0, (int) $order->get_meta( XPay_Constants::META_SESSION_CHECKED_AT ), 'A successful reuse validation refreshes the stamp the pay page trusts.' );
	}

	public function test_transport_failure_on_reuse_check_surfaces_instead_of_reminting() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );

		$this->client->get_failure = XPay_Api_Exception::transport( 'timeout' );

		try {
			$this->service->get_or_create_session( $order );
			$this->fail( 'A transport blip must surface: re-minting over a possibly-OPEN session risks money-taken-order-never-paid.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertCount( 1, $this->client->created );
		}
	}

	public function test_guest_sends_customer_details_only() {
		$this->service->get_or_create_session( $this->order() );

		$body = $this->client->created[0];
		$this->assertSame( '+201001234567', $body['customerDetails']['phone'] );
		$this->assertArrayNotHasKey( 'customerId', $body );
		$this->assertArrayNotHasKey( 'customerCreation', $body, 'Guests ride the platform default (if_required + dedupe).' );
	}

	public function test_logged_in_first_time_forces_customer_creation() {
		$this->service->get_or_create_session( $this->order( array( 'user_id' => 7 ) ) );

		$body = $this->client->created[0];
		$this->assertSame( 'always', $body['customerCreation'] );
		$this->assertArrayNotHasKey( 'customerId', $body );
	}

	public function test_linked_customer_sends_id_exclusively() {
		update_user_meta( 7, XPay_Constants::customer_user_meta_key( false ), 'cus_LINKED' );

		$this->service->get_or_create_session( $this->order( array( 'user_id' => 7 ) ) );

		$body = $this->client->created[0];
		$this->assertSame( 'cus_LINKED', $body['customerId'] );
		$this->assertArrayNotHasKey( 'customerDetails', $body, 'customerId and customerDetails are mutually exclusive on the platform.' );
		$this->assertArrayNotHasKey( 'customerCreation', $body );
	}

	public function test_stale_customer_link_is_cleared_and_retried_once() {
		update_user_meta( 7, XPay_Constants::customer_user_meta_key( false ), 'cus_DELETED' );
		$this->client->create_failures = array(
			XPay_Api_Exception::from_api_response(
				array(
					'code'    => XPay_Error_Codes::API_RESOURCE_MISSING,
					'message' => 'No such customer',
					'param'   => 'customerId',
				),
				404
			),
		);

		$session = $this->service->get_or_create_session( $this->order( array( 'user_id' => 7 ) ) );

		$this->assertNotEmpty( $session['id'] );
		$this->assertCount( 1, $this->client->created, 'The retry body is the one that succeeded.' );
		$this->assertArrayNotHasKey( 'customerId', $this->client->created[0] );
		$this->assertSame( '', get_user_meta( 7, XPay_Constants::customer_user_meta_key( false ), true ), 'The dead link must be cleared so the next checkout re-creates.' );
		$this->assertStageFired( 'customer.stale_link_cleared' );
	}

	public function test_rejected_method_pin_falls_back_to_unpinned_session() {
		$this->client->create_failures = array(
			XPay_Api_Exception::from_api_response(
				array(
					'code'    => XPay_Error_Codes::API_PARAMETER_INVALID,
					'message' => 'method not enabled',
					'param'   => 'paymentMethodTypes',
				),
				400
			),
		);

		$session = $this->service->get_or_create_session( $this->order(), array( XPay_Payment_Methods::VALU ) );

		$this->assertNotEmpty( $session['id'] );
		$body = $this->client->created[0];
		$this->assertArrayNotHasKey( 'paymentMethodTypes', $body, 'The shopper falls open to the full window.' );
		$this->assertArrayNotHasKey( 'phoneNumberCollection', $body, 'The phone flag rode the valU pin and must drop with it.' );
		$this->assertArrayHasKey( 'valu', get_option( XPay_Constants::OPTION_PIN_REJECTED ), 'The merchant gets a truthful notice flag.' );
	}

	public function test_branding_primary_snapshot_follows_session() {
		$this->client->session = array( 'brandingSettings' => array( 'colors' => array( 'primary' => '#123abc' ) ) );

		$this->service->get_or_create_session( $this->order() );

		$this->assertSame( '#123abc', get_option( XPay_Constants::OPTION_BRAND_PRIMARY ) );
	}
}
