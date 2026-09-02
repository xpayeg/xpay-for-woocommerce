<?php
/**
 * What actually goes out in the Idempotency-Key header.
 *
 * The plugin names its keys after a logical operation — "adopt this
 * session for this order", "refund this much of this order". The platform
 * does not read them that way: it binds a key to the first request it saw
 * with it, fingerprinting method, path, query, and body. A mismatch returns
 * 400 `idempotency_key_in_use`.
 *
 * @package XPay_For_WooCommerce
 */

class IdempotencyKeyContractTest extends ContractTestCase {

	/** @var XPay_Api_Client */
	private $client;

	protected function setUp(): void {
		parent::setUp();
		$this->client = new XPay_Api_Client( 'rk_test_contract' );
	}

	/** Every Idempotency-Key sent so far, in order. */
	private function keys(): array {
		$keys = array();
		foreach ( $GLOBALS['xpay_test_http'] as $call ) {
			if ( isset( $call['args']['headers']['Idempotency-Key'] ) ) {
				$keys[] = $call['args']['headers']['Idempotency-Key'];
			}
		}
		return $keys;
	}

	/** The last request body that went over the wire. */
	private function lastBody(): ?string {
		$last = end( $GLOBALS['xpay_test_http'] );
		return isset( $last['args']['body'] ) ? $last['args']['body'] : null;
	}

	private function patch( array $body ): void {
		$this->client->update_checkout_session( 'cs_test_1', $body, 'adopt_cs_test_1_84' );
	}

	/* ── The bug that broke a real payment ───────────────────────────── */

	public function test_the_same_operation_with_a_changed_body_gets_a_new_key(): void {
		$this->patch( array( 'customerDetails' => array( 'email' => 'first@example.test' ) ) );
		$this->patch( array( 'customerDetails' => array( 'email' => 'second@example.test' ) ) );

		$keys = $this->keys();
		$this->assertCount( 2, $keys );
		$this->assertNotSame(
			$keys[0],
			$keys[1],
			'A shopper who edits their email and retries sends a different body under the same key, '
				. 'which the platform refuses with idempotency_key_in_use and no payment starts.'
		);
	}

	/* ── …without giving up what the key is for ──────────────────────── */

	public function test_an_identical_retry_keeps_its_key_and_can_still_replay(): void {
		$body = array( 'metadata' => array( 'wc_order_id' => '84' ) );
		$this->patch( $body );
		$this->patch( $body );

		$keys = $this->keys();
		$this->assertSame(
			$keys[0],
			$keys[1],
			'A transport retry of the identical request must replay, not create a second effect.'
		);
	}

	public function test_the_key_still_names_its_operation(): void {
		$this->patch( array( 'metadata' => array( 'wc_order_id' => '84' ) ) );

		$this->assertStringStartsWith(
			'adopt_cs_test_1_84',
			$this->keys()[0],
			'Support reads these keys in the platform log; the operation must stay legible.'
		);
	}

	public function test_two_orders_adopting_one_session_never_share_a_key(): void {
		$this->client->update_checkout_session( 'cs_test_1', array( 'metadata' => array( 'wc_order_id' => '84' ) ), 'adopt_cs_test_1_84' );
		$this->client->update_checkout_session( 'cs_test_1', array( 'metadata' => array( 'wc_order_id' => '85' ) ), 'adopt_cs_test_1_85' );

		$keys = $this->keys();
		$this->assertNotSame( $keys[0], $keys[1] );
	}

	/**
	 * 255 is the API's documented ceiling, and a key
	 * over it is rejected outright rather than ignored.
	 */
	public function test_a_long_operation_name_stays_within_the_platforms_limit(): void {
		$this->client->update_checkout_session( 'cs_test_1', array( 'a' => 'b' ), str_repeat( 'x', 400 ) );

		$this->assertLessThanOrEqual( 255, strlen( $this->keys()[0] ) );
	}

	/* ── The body is unchanged by any of this ────────────────────────── */

	public function test_binding_the_key_does_not_disturb_the_body(): void {
		$this->patch( array( 'metadata' => array( 'wc_order_id' => '84' ) ) );

		$this->assertSame( '{"metadata":{"wc_order_id":"84"}}', $this->lastBody() );
	}

	public function test_a_bodyless_write_still_carries_a_stable_key(): void {
		$this->client->expire_checkout_session( 'cs_test_1' );
		$this->client->expire_checkout_session( 'cs_test_1' );

		$keys = $this->keys();
		$this->assertSame( $keys[0], $keys[1], 'Expiring the same session twice is one operation.' );
	}
}
