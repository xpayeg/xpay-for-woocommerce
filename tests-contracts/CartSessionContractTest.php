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
		$this->assertSame( 45000, $sent['body']['lineItems'][0]['unitAmount'] );
		$this->assertSame( 1, $sent['body']['lineItems'][0]['quantity'] );
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
	 * Two updates to the SAME amount must collapse server-side, and two
	 * updates to different amounts must not. Shipping recalculation fires
	 * this path repeatedly for one shopper.
	 */
	public function test_the_key_follows_the_amount_not_the_call(): void {
		$this->cart->sync_amount( 45000, 'EGP' );
		$this->cart->sync_amount( 52000, 'EGP' );

		$keys = array_column( $this->client->updated, 'key' );
		$this->assertCount( 2, $keys );
		$this->assertNotSame( $keys[0], $keys[1], 'a different amount needs a different key' );
		$this->assertStringContainsString( '45000', $keys[0] );
		$this->assertStringContainsString( '52000', $keys[1] );
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
