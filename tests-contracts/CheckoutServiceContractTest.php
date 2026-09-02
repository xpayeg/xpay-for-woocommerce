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

		$this->assertSame( 'custom', $body['uiMode'], 'The fields mount on the store\'s own page; hosted would be a different integration.' );
		$this->assertArrayNotHasKey( 'cancelUrl', $body, 'The platform refuses cancelUrl on a custom session.' );
		$this->assertArrayNotHasKey( 'expiresAfterMinutes', $body );
		$this->assertSame( 'EGP', $body['currency'] );
		$this->assertSame( 29000, (int) $body['lineItems'][0]['priceData']['unitAmount'] );
		$this->assertSame( 'en', $body['locale'] );
		$this->assertStringContainsString( 'xpay_session_id={CHECKOUT_SESSION_ID}', $body['afterCompletion']['redirect']['url'], 'The support breadcrumb placeholder must survive URL building.' );
		$this->assertSame( '14', $body['metadata']['wc_order_id'] );
		$this->assertSame( $order->get_order_key(), $body['metadata']['wc_order_key'] );
		$this->assertSame( 'woocommerce', $body['metadata']['integration'], 'The integration marker is a support/dashboard contract, not decoration.' );
		$this->assertArrayNotHasKey( 'phoneNumberCollection', $body );

		$this->assertNotInstanceOf( 'stdClass', $body['lineItems'][0]['priceData']['unitAmount'] );
		$this->assertFalse( is_float( $body['lineItems'][0]['priceData']['unitAmount'] ), 'Minor units travel as strings or ints, never floats.' );

		$this->assertArrayNotHasKey( 'paymentMethodTypes', $body, 'With no accepted list (the fallback state), the session stays on the account default.' );
	}

	/* ── The Payment Methods tab's enforcement half ──────────────────── */

	/** A service built the way the gateway builds it when a list applies. */
	private function pinned_service( array $types, ?callable $refresh = null ): XPay_Checkout_Service {
		return new XPay_Checkout_Service( $this->client, $types, $refresh );
	}

	public function test_the_accepted_method_list_rides_the_create_body() {
		$this->pinned_service( array( 'card', 'valu' ) )->get_or_create_session( $this->order() );

		$this->assertSame(
			array( 'card', 'valu' ),
			$this->client->created[0]['paymentMethodTypes'],
			'The checked list is the session\'s accepted set: an unchecked method must be unchargeable even from a tampered page.'
		);
	}

	public function test_a_method_list_change_supersedes_the_session() {
		$service = $this->pinned_service( array( 'card' ) );
		$order   = $this->order();
		$service->get_or_create_session( $order );
		$old_id = $order->get_meta( XPay_Constants::META_SESSION_ID );

		// The stored session reads back accepting card AND valu: the
		// merchant's checked list has since narrowed to card alone.
		$this->client->session = array(
			'paymentMethodTypes' => array(
				array( 'type' => 'card', 'displayName' => 'Card' ),
				array( 'type' => 'valu', 'displayName' => 'ValU' ),
			),
		);
		$service->get_or_create_session( $order );

		$this->assertCount( 2, $this->client->created, 'A session accepting an unchecked method stayed reusable.' );
		$this->assertSame( array( $old_id ), $this->client->expired, 'The superseded session stays payable, with the unchecked method, unless expired.' );
		$this->assertStageFired( 'session.method_list_changed' );
	}

	public function test_a_matching_method_list_reuses_whatever_its_order() {
		$service = $this->pinned_service( array( 'card', 'valu' ) );
		$order   = $this->order();
		$service->get_or_create_session( $order );

		// Same set, platform's own order: order is presentation, not identity.
		$this->client->session = array(
			'paymentMethodTypes' => array(
				array( 'type' => 'valu', 'displayName' => 'ValU' ),
				array( 'type' => 'card', 'displayName' => 'Card' ),
			),
		);
		$service->get_or_create_session( $order );

		$this->assertCount( 1, $this->client->created, 'A reordered but identical set churned a good session.' );
	}

	public function test_a_session_that_does_not_state_its_methods_is_still_reused() {
		// Fail open on shape: the default fake states no paymentMethodTypes
		// at all, and absence proves nothing about the session.
		$service = $this->pinned_service( array( 'card' ) );
		$order   = $this->order();
		$service->get_or_create_session( $order );

		$service->get_or_create_session( $order );

		$this->assertCount( 1, $this->client->created );
	}

	public function test_a_rejected_method_list_refreshes_and_retries_with_the_current_intersection() {
		$this->client->create_failures = array(
			XPay_Api_Exception::from_api_response(
				array(
					'code'    => XPay_Error_Codes::API_PARAMETER_INVALID,
					'message' => 'Payment method types [valu] are not enabled for this merchant.',
					'param'   => 'paymentMethodTypes',
				),
				400
			),
		);

		$session = $this->pinned_service(
			array( 'card', 'valu' ),
			static function ( string $currency ): array {
				return 'EGP' === $currency ? array( 'card' ) : array();
			}
		)->get_or_create_session( $this->order() );

		$this->assertNotEmpty( $session['id'] );
		$this->assertCount( 1, $this->client->created, 'The retry body is the one that succeeded.' );
		$this->assertSame( array( 'card' ), $this->client->created[0]['paymentMethodTypes'] );
		$this->assertStageFired( 'session.method_list_rejected' );
	}

	public function test_a_rejected_method_list_never_retries_unpinned_when_none_remain() {
		$this->client->create_failures = array(
			XPay_Api_Exception::from_api_response(
				array( 'code' => XPay_Error_Codes::API_PARAMETER_INVALID, 'message' => 'Rejected', 'param' => 'paymentMethodTypes' ),
				400
			),
		);

		try {
			$this->pinned_service( array( 'valu' ), static function (): array { return array(); } )->get_or_create_session( $this->order() );
			$this->fail( 'An empty current intersection must stop checkout.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::PAYMENT_METHODS_UNAVAILABLE, $e->get_error_code() );
			$this->assertCount( 0, $this->client->created );
		}
	}

	public function test_an_empty_accepted_list_fails_before_session_create() {
		try {
			$this->pinned_service( array() )->get_or_create_session( $this->order() );
			$this->fail( 'An empty accepted list must stop checkout.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::PAYMENT_METHODS_UNAVAILABLE, $e->get_error_code() );
			$this->assertCount( 0, $this->client->created );
		}
	}

	public function test_arabic_storefront_sends_arabic_locale() {
		$GLOBALS['xpay_test_locale'] = 'ar';
		$this->service->get_or_create_session( $this->order() );
		$this->assertSame( 'ar', $this->client->created[0]['locale'] );
	}

	public function test_open_matching_session_is_reused() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );

		$session = $this->service->get_or_create_session( $order );

		$this->assertCount( 1, $this->client->created, 'Same order, same terms: reuse, never re-mint.' );
		$this->assertSame( $order->get_meta( XPay_Constants::META_SESSION_ID ), $session['id'] );
	}

	/**
	 * An emptied session keeps its OPEN status, its amount and its
	 * currency, so every other reuse test above passes on one the platform
	 * will refuse to charge ("Add at least one item to your order before
	 * paying"). Reusing it puts the shopper on a pay page reading
	 * "Your order is empty".
	 */
	public function test_a_session_that_lost_its_line_items_is_not_reused() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );
		$old_id = $order->get_meta( XPay_Constants::META_SESSION_ID );

		$this->client->session = array( 'lineItems' => array() );
		$this->service->get_or_create_session( $order );

		$this->assertCount( 2, $this->client->created, 'The shopper was handed back a session that cannot be paid.' );
		$this->assertSame( array( $old_id ), $this->client->expired );
	}

	/**
	 * Absent is not empty: a response that does not expand its line items
	 * says nothing about whether the session has any, and must not cost the
	 * shopper a fresh session on every attempt.
	 */
	public function test_a_session_that_does_not_expand_its_line_items_is_still_reused() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );

		$this->service->get_or_create_session( $order );

		$this->assertCount( 1, $this->client->created );
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

	/**
	 * ONE SESSION PER CHECKOUT. A cart edited between attempts moves the
	 * total, and the answer is to reprice the session the shopper already
	 * has — not to mint a second one. A session per attempt means a
	 * Payment Intent per attempt: one purchase split across many objects,
	 * with the decline history scattered between them.
	 */
	public function test_amount_change_reprices_in_place_and_keeps_the_secret() {
		$order = $this->order();
		$first = $this->service->get_or_create_session( $order );

		$order->total = '999.00';
		$second       = $this->service->get_or_create_session( $order );

		$this->assertCount( 1, $this->client->created, 'A changed total minted a second session.' );
		$this->assertSame( array(), $this->client->expired, 'Nothing was superseded, so nothing may be expired.' );
		$this->assertSame( $first['id'], $second['id'], 'The shopper must keep the same session.' );
		$this->assertSame( $first['clientSecret'], $second['clientSecret'], 'A new secret is a new Payment Intent.' );

		$this->assertCount( 1, $this->client->updated );
		$patch = $this->client->updated[0];
		$this->assertSame( $first['id'], $patch['session_id'] );
		$this->assertSame( 99900, (int) $patch['body']['lineItems'][0]['priceData']['unitAmount'] );
		$this->assertCount( 1, $patch['body']['lineItems'], 'One synthetic line, never a re-itemized basket.' );
	}

	/**
	 * A -> B -> A -> B: every reprice must be a NEW operation. A key
	 * derived from the amount alone hands the second "B" the first one's
	 * key and body; the platform replays the stored response without
	 * re-applying, and the session is left at "A" while the shopper sees
	 * "B" — an amount_reconfirmation_required loop nothing can escape.
	 */
	public function test_repeated_amounts_never_reuse_a_reprice_key() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );

		$order->total = '999.00';
		$this->service->get_or_create_session( $order );
		$order->total = '290.00';
		$this->service->get_or_create_session( $order );
		$order->total = '999.00';
		$this->service->get_or_create_session( $order );

		$keys = array_column( $this->client->updated, 'key' );
		$this->assertCount( 3, $keys );
		$this->assertSame( $keys, array_unique( $keys ), 'A repeated total reused an old idempotency key; the platform would replay instead of re-applying.' );
	}

	public function test_a_repriced_session_is_reused_unchanged_on_the_next_attempt() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );
		$order->total = '999.00';
		$this->service->get_or_create_session( $order );

		$this->service->get_or_create_session( $order );

		$this->assertCount( 1, $this->client->updated, 'A total that has not moved must not be re-sent.' );
		$this->assertCount( 1, $this->client->created );
	}

	/**
	 * Repricing is never allowed to leave the caller holding a session
	 * whose total is a guess. Superseding is always safe.
	 */
	public function test_a_refused_reprice_falls_back_to_superseding() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );
		$old_id = $order->get_meta( XPay_Constants::META_SESSION_ID );

		$this->client->update_failure = XPay_Api_Exception::from_api_response( array( 'code' => 'resource_invalid_state' ), 400 );
		$order->total                 = '999.00';
		$this->service->get_or_create_session( $order );

		$this->assertCount( 2, $this->client->created );
		$this->assertSame( array( $old_id ), $this->client->expired );
		$this->assertStageFired( 'session.reprice_failed' );
	}

	/**
	 * Salvaged from the cart-session machinery: the platform's int4 column
	 * caps a line at 2147483647 minor units, and an amount above it must
	 * fail HERE, as a readable refusal, not deep in the platform.
	 */
	public function test_a_total_above_the_platform_ceiling_is_refused_before_any_call() {
		$order = $this->order( array( 'total' => '21474836.48' ) ); // 2147483648 piasters.

		try {
			$this->service->get_or_create_session( $order );
			$this->fail( 'A total above int4 reached the platform.' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::AMOUNT_ABOVE_LINE_CEILING, $e->get_error_code() );
		}
		$this->assertCount( 0, $this->client->created, 'The refusal must happen before any request.' );
	}

	public function test_supersede_records_the_old_session_id() {
		$order = $this->order();
		$this->service->get_or_create_session( $order );
		$old_id = $order->get_meta( XPay_Constants::META_SESSION_ID );

		// Currency is immutable on a session, so this is a genuine
		// supersede rather than a reprice.
		$this->client->session = array( 'currency' => 'USD' );
		$this->service->get_or_create_session( $order );

		$this->assertContains( $old_id, (array) $order->get_meta( XPay_Constants::META_SUPERSEDED_SESSIONS ), 'A paid event on the old id must stay recognizable as this order\'s money.' );
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

	public function test_the_billing_address_reaches_the_session(): void {
		$this->service->get_or_create_session(
			$this->order(
				array(
					'billing_address_1' => '2 test street',
					'billing_address_2' => 'apt 5',
					'billing_city'      => 'Cairo',
					'billing_state'     => 'C',
					'billing_postcode'  => '12345',
					'billing_country'   => 'EG',
				)
			)
		);

		$address = $this->client->created[0]['customerDetails']['billingDetails']['address'];
		$this->assertSame(
			array(
				'line1'      => '2 test street',
				'line2'      => 'apt 5',
				'city'       => 'Cairo',
				'state'      => 'C',
				'postalCode' => '12345',
				'country'    => 'EG',
			),
			$address
		);
	}

	public function test_an_empty_address_sends_no_billing_details(): void {
		$this->service->get_or_create_session( $this->order() );

		$this->assertArrayNotHasKey(
			'billingDetails',
			$this->client->created[0]['customerDetails'],
			'Blank fields are dropped, never sent as empty strings.'
		);
	}

	public function test_a_shipping_address_rides_along_when_the_order_has_one(): void {
		$this->service->get_or_create_session(
			$this->order(
				array(
					'shipping_first_name' => 'Mo',
					'shipping_last_name'  => 'Elmo',
					'shipping_address_1'  => '9 delivery road',
					'shipping_city'       => 'Giza',
					'shipping_country'    => 'EG',
				)
			)
		);

		$shipping = $this->client->created[0]['customerDetails']['shipping'];
		$this->assertSame( 'Mo Elmo', $shipping['name'] );
		$this->assertSame( '9 delivery road', $shipping['address']['line1'] );
		$this->assertSame( 'Giza', $shipping['address']['city'] );
		$this->assertSame( 'EG', $shipping['address']['country'] );
		$this->assertArrayNotHasKey( 'phone', $shipping, 'An empty shipping phone is dropped.' );
	}

	public function test_no_shipping_address_sends_no_shipping_block(): void {
		$this->service->get_or_create_session( $this->order() );

		$this->assertArrayNotHasKey( 'shipping', $this->client->created[0]['customerDetails'] );
	}

	public function test_shipping_address_line_two_counts_but_city_alone_does_not(): void {
		$this->service->get_or_create_session( $this->order( array( 'shipping_address_2' => 'Unit 5' ) ) );
		$this->assertSame( 'Unit 5', $this->client->created[0]['customerDetails']['shipping']['address']['line2'] );

		$this->client  = new XPay_Capture_Client();
		$this->service = new XPay_Checkout_Service( $this->client );
		$this->service->get_or_create_session( $this->order( array( 'shipping_city' => 'Giza' ) ) );
		$this->assertArrayNotHasKey( 'shipping', $this->client->created[0]['customerDetails'] );
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

}
