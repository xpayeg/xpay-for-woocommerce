<?php
/**
 * Contracts for XPay_Cart_Session.
 *
 * The checkout page keeps one session alive while the cart total moves
 * under it, which is a deliberate exception to this plugin's
 * create-and-expire rule. The exception is only safe because of the guard
 * pinned here: the platform will happily accept an amount change on a
 * session that has a payment running, because such a session is still
 * "open". Nothing outside this class stops that.
 *
 * So the tests that matter are the refusals. A refused update leaves a
 * correct session with a stale amount, which confirm-time catches. An
 * allowed update at the wrong moment charges a shopper a number they never
 * agreed to.
 *
 * @package XPay_For_WooCommerce
 */

final class CartSessionContractTest extends ContractTestCase {

	/** @var XPay_Capture_Client */
	private $client;

	/** @var XPay_Cart_Session */
	private $cart;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['xpay_test_wc_session'] = array();
		$this->client                    = new XPay_Capture_Client();
		$this->cart                      = new XPay_Cart_Session( $this->client );
		$this->cart->remember( 'cs_cart_1', 'secret_1', 29000, 'EGP' );
	}

	/* ── The guard ───────────────────────────────────────────────────── */

	public function test_amount_change_is_refused_while_a_payment_is_running(): void {
		$this->cart->payment_started();

		$this->assertSame( 'locked', $this->cart->sync_amount( 45000, 'EGP' ) );
		$this->assertSame( array(), $this->client->updated, 'no PATCH may be sent while paying' );
		$this->assertSame( 29000, $this->cart->known_amount(), 'the agreed amount must survive' );
	}

	public function test_amount_change_resumes_once_the_payment_ends(): void {
		$this->cart->payment_started();
		$this->cart->sync_amount( 45000, 'EGP' );
		$this->cart->payment_finished();

		$this->assertSame( 'updated', $this->cart->sync_amount( 45000, 'EGP' ) );
		$this->assertCount( 1, $this->client->updated );
		$this->assertSame( 45000, $this->cart->known_amount() );
	}

	/**
	 * A browser that dies mid-payment must not freeze the cart forever, but
	 * the lock has to outlive a slow 3-D Secure challenge.
	 */
	public function test_a_stale_lock_stops_holding_after_the_ttl(): void {
		$this->cart->payment_started();
		$this->assertTrue( $this->cart->payment_in_flight() );

		// Just inside the window: still locked.
		$GLOBALS['xpay_test_wc_session'][ XPay_Cart_Session::PAYMENT_LOCK_KEY ] =
			time() - ( XPay_Cart_Session::PAYMENT_LOCK_TTL - 30 );
		$this->assertTrue( $this->cart->payment_in_flight() );
		$this->assertSame( 'locked', $this->cart->sync_amount( 45000, 'EGP' ) );

		// Past it: the shopper is gone, the cart is theirs again.
		$GLOBALS['xpay_test_wc_session'][ XPay_Cart_Session::PAYMENT_LOCK_KEY ] =
			time() - ( XPay_Cart_Session::PAYMENT_LOCK_TTL + 1 );
		$this->assertFalse( $this->cart->payment_in_flight() );
		$this->assertSame( 'updated', $this->cart->sync_amount( 45000, 'EGP' ) );
	}

	/**
	 * Unreadable lock state counts as locked. Fail closed: a false lock
	 * costs a refused update, a false unlock costs a wrong charge.
	 */
	public function test_a_corrupt_lock_is_treated_as_locked(): void {
		$GLOBALS['xpay_test_wc_session'][ XPay_Cart_Session::PAYMENT_LOCK_KEY ] = 'not-a-timestamp';

		$this->assertTrue( $this->cart->payment_in_flight() );
		$this->assertSame( 'locked', $this->cart->sync_amount( 45000, 'EGP' ) );
		$this->assertSame( array(), $this->client->updated );
	}

	/* ── Ordinary syncing ────────────────────────────────────────────── */

	public function test_an_unchanged_total_sends_nothing(): void {
		$this->assertSame( 'unchanged', $this->cart->sync_amount( 29000, 'EGP' ) );
		$this->assertSame( array(), $this->client->updated );
	}

	public function test_a_changed_total_is_sent_as_a_single_line(): void {
		$this->assertSame( 'updated', $this->cart->sync_amount( 45000, 'EGP' ) );

		$this->assertCount( 1, $this->client->updated );
		$sent = $this->client->updated[0];
		$this->assertSame( 'cs_cart_1', $sent['session_id'] );
		$this->assertCount( 1, $sent['body']['lineItems'] );
		$line = $sent['body']['lineItems'][0];
		$this->assertSame( 45000, $line['priceData']['unitAmount'] );
		$this->assertSame( 'EGP', $line['priceData']['currency'] );
		$this->assertSame( 1, $line['quantity'] );

		// The shape is not advisory: the platform rejects unknown keys rather
		// than dropping them, so a stray flat pair is a 400, not a warning.
		$this->assertArrayNotHasKey( 'unitAmount', $line );
		$this->assertArrayNotHasKey( 'name', $line );
	}

	/**
	 * The platform rejects any attempt to change a session's currency, so a
	 * currency change is a rebuild, not an update. Sending the PATCH anyway
	 * would burn a round trip to be told what we already know.
	 */
	public function test_a_currency_change_is_reported_rather_than_patched(): void {
		$this->assertSame( 'currency-changed', $this->cart->sync_amount( 45000, 'USD' ) );
		$this->assertSame( array(), $this->client->updated );
	}

	public function test_nothing_happens_without_a_session(): void {
		$this->cart->forget();
		$this->assertSame( 'none', $this->cart->sync_amount( 45000, 'EGP' ) );
		$this->assertSame( array(), $this->client->updated );
	}

	/* ── Idempotency ─────────────────────────────────────────────────── */

	/**
	 * Every update is its own request, including one that sets a total the
	 * cart has held before.
	 *
	 * The platform replays a key it has already seen carrying a matching
	 * body: the first call's answer comes back and the handler never runs.
	 * A key derived from the amount alone therefore turns "apply a coupon,
	 * remove it, apply it again" into a session left on the total the
	 * shopper moved away from, while this plugin records the one they moved
	 * to. Nothing then disagrees — the confirm-time guard compares the cart
	 * against what we believe we sent, both say 40000, and the session
	 * charges 45000.
	 */
	public function test_returning_to_a_previous_total_does_not_reuse_its_key(): void {
		$this->cart->sync_amount( 45000, 'EGP' );
		$this->cart->sync_amount( 29000, 'EGP' );
		$this->cart->sync_amount( 45000, 'EGP' );

		$keys = array_column( $this->client->updated, 'key' );
		$this->assertCount( 3, $keys );
		$this->assertSame( $keys, array_values( array_unique( $keys ) ), 'no two updates may collapse into one' );
	}

	/**
	 * The amount stays in the key even though it no longer distinguishes
	 * one update from another: a counter is only as durable as the
	 * WooCommerce session it lives in, and a reused number carrying a
	 * different body is refused outright rather than replayed.
	 */
	public function test_the_key_still_names_the_amount_it_carries(): void {
		$this->cart->sync_amount( 45000, 'EGP' );
		$this->cart->sync_amount( 52000, 'EGP' );

		$keys = array_column( $this->client->updated, 'key' );
		$this->assertStringContainsString( '45000', $keys[0] );
		$this->assertStringContainsString( '52000', $keys[1] );
	}

	/**
	 * Creation is deduplicated by customer, amount and currency so two asks
	 * racing each other leave one session behind rather than two. A
	 * completed answer stays replayable for a day, so that trio has to stop
	 * matching the moment the session it produced is thrown away —
	 * otherwise a shopper who buys the same basket twice, or comes back to a
	 * cart whose session expired under it, is handed the dead one again.
	 */
	public function test_a_dropped_session_is_not_recreated_from_its_own_cached_answer(): void {
		$this->cart->forget();
		$this->cart->ensure( 29000, 'EGP', 'https://shop.test/checkout' );

		$this->cart->forget();
		$this->cart->ensure( 29000, 'EGP', 'https://shop.test/checkout' );

		$this->assertCount( 2, $this->client->create_keys );
		$this->assertNotSame( $this->client->create_keys[0], $this->client->create_keys[1] );
	}

	public function test_two_asks_for_the_same_untouched_cart_share_one_creation_key(): void {
		$this->cart->forget();
		$this->cart->ensure( 29000, 'EGP', 'https://shop.test/checkout' );
		$first = $this->client->create_keys[0];

		// The same cart asking again before the first answer was written
		// back, which is what two page hooks racing each other looks like.
		unset(
			$GLOBALS['xpay_test_wc_session'][ XPay_Cart_Session::SESSION_ID_KEY ],
			$GLOBALS['xpay_test_wc_session'][ XPay_Cart_Session::CLIENT_SECRET_KEY ]
		);
		$this->cart->ensure( 29000, 'EGP', 'https://shop.test/checkout' );

		$this->assertSame( $first, $this->client->create_keys[1] );
	}

	/* ── Sessions that can never be paid ─────────────────────────────── */

	/**
	 * An expired session is replaced rather than handed back.
	 *
	 * Sessions live a day and WooCommerce sessions live two, so a shopper
	 * who leaves a checkout tab open overnight comes back to a secret that
	 * can never be paid. Nothing else in this class notices: ensure() checks
	 * only that a session exists and that its currency still agrees.
	 */
	public function test_an_expired_session_is_replaced(): void {
		$this->client->session = array(
			'status'    => XPay_Session_Status::EXPIRED,
			'isExpired' => true,
		);

		$this->assertSame( 'restarted', $this->cart->restart( 29000, 'EGP', 'https://shop.test/checkout' ) );
		$this->assertNotSame( 'cs_cart_1', $this->cart->session_id() );
		$this->assertCount( 1, $this->client->created );
	}

	/**
	 * A session that has already taken money is never replaced. It means the
	 * order that should have followed the payment did not, and minting a
	 * payable session there charges the shopper a second time for a basket
	 * they have already bought.
	 */
	public function test_a_paid_session_is_never_replaced(): void {
		$this->client->session = array(
			'status'        => XPay_Session_Status::COMPLETE,
			'paymentStatus' => XPay_Payment_Status::PAID,
		);

		$this->assertSame( 'paid', $this->cart->restart( 29000, 'EGP', 'https://shop.test/checkout' ) );
		$this->assertSame( 'cs_cart_1', $this->cart->session_id() );
		$this->assertSame( array(), $this->client->created );
	}

	/**
	 * A completed session that took no money is spent but harmless, and a
	 * shopper who was never charged must not be stranded by it.
	 */
	public function test_a_completed_but_unpaid_session_is_replaced(): void {
		$this->client->session = array(
			'status'        => XPay_Session_Status::COMPLETE,
			'paymentStatus' => XPay_Payment_Status::UNPAID,
		);

		$this->assertSame( 'restarted', $this->cart->restart( 29000, 'EGP', 'https://shop.test/checkout' ) );
		$this->assertCount( 1, $this->client->created );
	}

	/**
	 * A session the platform has never heard of is spent in the only sense
	 * that matters: it cannot be paid, and leaving it in place points this
	 * cart at nothing for as long as the shopper keeps it.
	 */
	public function test_a_missing_session_is_replaced(): void {
		$this->client->get_failure = XPay_Api_Exception::from_api_response(
			array(
				'code'    => XPay_Error_Codes::API_RESOURCE_MISSING,
				'message' => 'No such checkout_session',
			),
			404
		);

		$this->assertSame( 'restarted', $this->cart->restart( 29000, 'EGP', 'https://shop.test/checkout' ) );
		$this->assertCount( 1, $this->client->created );
	}

	/**
	 * A failure that says nothing about the session leaves it alone. It may
	 * still be open and payable, and replacing one of those is how a shopper
	 * ends up with two live sessions and pays the wrong one.
	 */
	public function test_a_transport_failure_does_not_replace_a_session(): void {
		$this->client->get_failure = XPay_Api_Exception::transport( 'connection reset' );

		$this->expectException( XPay_Api_Exception::class );
		try {
			$this->cart->restart( 29000, 'EGP', 'https://shop.test/checkout' );
		} finally {
			$this->assertSame( 'cs_cart_1', $this->cart->session_id() );
			$this->assertSame( array(), $this->client->created );
		}
	}

	/**
	 * The browser reports; the platform decides. A page able to retire a
	 * session on its own say-so could churn one per keystroke.
	 */
	public function test_a_session_the_platform_still_calls_open_is_left_alone(): void {
		$this->assertSame( 'open', $this->cart->restart( 29000, 'EGP', 'https://shop.test/checkout' ) );
		$this->assertSame( 'cs_cart_1', $this->cart->session_id() );
		$this->assertSame( array(), $this->client->created );
	}

	/* ── Lifecycle ───────────────────────────────────────────────────── */

	public function test_remember_clears_any_lock_from_a_previous_session(): void {
		$this->cart->payment_started();
		$this->cart->remember( 'cs_cart_2', 'secret_2', 10000, 'EGP' );

		$this->assertFalse( $this->cart->payment_in_flight() );
		$this->assertSame( 'cs_cart_2', $this->cart->session_id() );
		$this->assertSame( 'secret_2', $this->cart->client_secret() );
		$this->assertSame( 10000, $this->cart->known_amount() );
	}

	public function test_forget_leaves_nothing_behind(): void {
		$this->cart->payment_started();
		$this->cart->forget();

		$this->assertSame( '', $this->cart->session_id() );
		$this->assertSame( '', $this->cart->client_secret() );
		$this->assertNull( $this->cart->known_amount() );
		$this->assertFalse( $this->cart->payment_in_flight() );
	}

	/**
	 * A request with no WooCommerce session at all (cron, REST, a cached
	 * page) must not fatal. It simply has no cart session to speak of.
	 */
	public function test_a_request_without_a_woocommerce_session_is_survivable(): void {
		$GLOBALS['xpay_test_wc']->session = null;

		$this->assertSame( '', $this->cart->session_id() );
		$this->assertNull( $this->cart->known_amount() );
		$this->assertFalse( $this->cart->payment_in_flight() );
		$this->assertSame( 'none', $this->cart->sync_amount( 45000, 'EGP' ) );

		$GLOBALS['xpay_test_wc']->session = new XPay_Test_WC_Session();
	}
}
