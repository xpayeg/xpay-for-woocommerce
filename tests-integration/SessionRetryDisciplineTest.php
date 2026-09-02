<?php
/**
 * ONE SESSION PER CHECKOUT, against real WooCommerce orders.
 *
 * The rule the whole deferred flow rests on: an order keeps ONE checkout
 * session across every payment attempt, so the whole transaction — each
 * decline, the retry history, the final charge — stays on one Payment
 * Intent. A session per Pay click would mint a Payment Intent per retry,
 * splitting one purchase across many objects and leaving every abandoned
 * attempt as a live payable session.
 *
 * Three paths, and only the third may create a second session:
 *   - unchanged total  -> reuse, same clientSecret
 *   - changed total    -> PATCH the line items, same clientSecret
 *   - expired or gone  -> supersede, expire the old one, create
 *
 * The contract suite pins the same rules against an in-memory shim; this
 * asserts them with real orders, real meta storage and real totals.
 *
 * @package XPay_For_WooCommerce
 */

class SessionRetryDisciplineTest extends XPay_Integration_Test_Case {

	/** @var int How many POST /checkout/sessions the store has made. */
	private int $creates = 0;

	/** @var array<int, array> Every PATCH body sent, in order. */
	private array $patches = array();

	/** @var array<int, string> Session ids the store asked to expire. */
	private array $expired = array();

	/** @var string What a GET of the stored session reads back as. */
	private string $stored_status = 'open';

	/** @var array<int, array> Every POST /checkout/sessions body, in order. */
	private array $created_bodies = array();

	/**
	 * @var string[]|null Wire types the stored session states it accepts,
	 *      or null to state none (the response shape most tests need).
	 */
	private ?array $stored_methods = null;

	/** @var int What a GET of the stored session reads back as, in minor units. */
	private int $stored_amount = 29000;

	/**
	 * The session the platform currently holds.
	 *
	 * Tracked rather than hardcoded because the secret's STABILITY across
	 * attempts is what these tests are about: a fake that answered a
	 * different secret on every read would make a reused session
	 * indistinguishable from a fresh one, which is the exact thing being
	 * asserted.
	 */
	private string $current_id = 'cs_created_1';

	/** @var string The secret that belongs to current_id. */
	private string $current_secret = 'cs_created_1_secret';

	public function set_up(): void {
		parent::set_up();
		$this->configure_gateway(
			array(
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_retry',
				'test_publishable_key' => 'pk_test_retry',
			)
		);

		$this->creates        = 0;
		$this->patches        = array();
		$this->expired        = array();
		$this->created_bodies = array();
		$this->stored_methods = null;
		$this->stored_status  = 'open';
		$this->stored_amount  = 29000;
		$this->current_id     = 'cs_created_1';
		$this->current_secret = 'cs_created_1_secret';

		add_filter( 'pre_http_request', array( $this, 'serve' ), 1, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'serve' ), 1 );
		// Options a test writes must go even when the service under test
		// throws before the test's own cleanup line would have run.
		delete_option( XPay_Constants::OPTION_ENABLED_METHODS );
		delete_option( XPay_Constants::account_methods_option( false ) );
		parent::tear_down();
	}

	/**
	 * A platform stand-in that behaves the way the real one does on the
	 * two facts this test turns on: a PATCH of lineItems REPLACES them and
	 * the session reads back at its new total afterwards, and a created
	 * session gets an id and secret of its own.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    Request args.
	 * @param string $url     Request URL.
	 * @return array|mixed
	 */
	public function serve( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, 'api.xpay.app' ) ) {
			return $preempt;
		}

		$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( 'POST' === $method && '/checkout/sessions' === $path ) {
			++$this->creates;
			$this->stored_status    = 'open';
			$body                   = json_decode( (string) $args['body'], true );
			$this->created_bodies[] = $body;
			$this->stored_amount    = (int) $body['lineItems'][0]['priceData']['unitAmount'];
			$this->current_id     = 'cs_created_' . $this->creates;
			$this->current_secret = 'cs_created_' . $this->creates . '_secret';
			return $this->ok( array() );
		}

		if ( false !== strpos( $path, '/expire' ) ) {
			$this->expired[] = basename( dirname( $path ) );
			return $this->ok( array( 'id' => 'expired' ) );
		}

		if ( 'PATCH' === $method ) {
			$body            = json_decode( (string) $args['body'], true );
			$this->patches[] = $body;
			// The real PATCH replaces the rows and the session reads back
			// at the new total, keeping its id AND its secret. A fake that
			// echoed the old figure would let a reprice that changed
			// nothing pass as one that worked; one that minted a new
			// secret would hide a new Payment Intent.
			$this->stored_amount = (int) $body['lineItems'][0]['priceData']['unitAmount'];
			return $this->ok( array() );
		}

		return $this->ok( array() );
	}

	/** @param array $extra Fields to merge over the base session. */
	private function ok( array $extra ): array {
		$base = array(
			'id'             => $this->current_id,
			'clientSecret'   => $this->current_secret,
			'status'         => $this->stored_status,
			'isExpired'      => 'expired' === $this->stored_status,
			'amountSubtotal' => $this->stored_amount,
			'amountTotal'    => $this->stored_amount,
			'currency'       => 'EGP',
			'lineItems'      => array( array( 'id' => 'li_1', 'quantity' => 1 ) ),
		);
		if ( null !== $this->stored_methods ) {
			// The response's resolved shape: objects with a type.
			$base['paymentMethodTypes'] = array_map(
				static function ( string $type ): array {
					return array(
						'type'        => $type,
						'displayName' => ucfirst( $type ),
					);
				},
				$this->stored_methods
			);
		}
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => wp_json_encode( array_merge( $base, $extra ) ),
		);
	}

	private function service(): XPay_Checkout_Service {
		return new XPay_Checkout_Service( $this->gateway()->api_client() );
	}

	private function order( string $total = '290.00' ): WC_Order {
		$order = $this->make_xpay_order();
		$order->set_total( $total );
		$order->save();
		return $order;
	}

	public function test_a_retry_on_an_unchanged_cart_reuses_the_same_session(): void {
		$order = $this->order();

		$first  = $this->service()->get_or_create_session( $order );
		$second = $this->service()->get_or_create_session( $order );

		$this->assertSame( 1, $this->creates, 'A retry minted a second session, and so a second Payment Intent.' );
		$this->assertSame( array(), $this->patches, 'Nothing moved, so nothing may be sent.' );
		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( $first['clientSecret'], $second['clientSecret'] );
	}

	public function test_a_changed_total_reprices_the_same_session(): void {
		$order = $this->order();
		$first = $this->service()->get_or_create_session( $order );

		$order->set_total( '999.00' );
		$order->save();
		$second = $this->service()->get_or_create_session( $order );

		$this->assertSame( 1, $this->creates, 'An edited cart minted a second session instead of repricing.' );
		$this->assertCount( 1, $this->patches, 'Exactly one PATCH, carrying the whole new list.' );
		$this->assertSame( 99900, (int) $this->patches[0]['lineItems'][0]['priceData']['unitAmount'] );
		$this->assertCount( 1, $this->patches[0]['lineItems'], 'One synthetic line, never a re-itemized basket.' );
		$this->assertSame( $first['clientSecret'], $second['clientSecret'], 'A new secret is a new Payment Intent.' );
		$this->assertSame( array(), $this->expired, 'Nothing was superseded, so nothing may be expired.' );
	}

	public function test_an_expired_session_is_the_only_thing_that_mints_a_second(): void {
		$order = $this->order();
		$this->service()->get_or_create_session( $order );
		$old_id = (string) $order->get_meta( XPay_Constants::META_SESSION_ID );

		$this->stored_status = 'expired';
		$this->service()->get_or_create_session( $order );

		$this->assertSame( 2, $this->creates );
		$this->assertSame( array( $old_id ), $this->expired, 'A superseded session stays payable unless it is expired.' );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertContains(
			$old_id,
			(array) $fresh->get_meta( XPay_Constants::META_SUPERSEDED_SESSIONS ),
			'A paid event on the old id must stay recognizable as this order\'s money.'
		);
	}

	public function test_a_method_list_change_supersedes_like_a_currency_change(): void {
		update_option(
			XPay_Constants::account_methods_option( false ),
			array( 'EGP' => array( 'card', 'valu' ) )
		);
		$order   = $this->order();
		$service = new XPay_Checkout_Service(
			$this->gateway()->api_client(),
			$this->gateway()->accepted_types_for_session( 'EGP' )
		);
		$service->get_or_create_session( $order );
		$old_id = (string) $order->get_meta( XPay_Constants::META_SESSION_ID );

		// The merchant unchecks ValU while this shopper holds a live
		// session that still accepts it: the next attempt supersedes.
		update_option( XPay_Constants::OPTION_ENABLED_METHODS, array( 'card' ) );
		$this->stored_methods = array( 'card', 'valu' );
		$narrowed             = new XPay_Checkout_Service(
			$this->gateway()->api_client(),
			$this->gateway()->accepted_types_for_session( 'EGP' )
		);
		$narrowed->get_or_create_session( $order );

		$this->assertSame( 2, $this->creates, 'A session accepting an unchecked method stayed reusable.' );
		$this->assertSame( array( $old_id ), $this->expired );
		$this->assertSame( array( 'card' ), $this->created_bodies[1]['paymentMethodTypes'], 'The replacement accepts exactly the checked list.' );
	}

	public function test_the_session_carries_the_order_from_birth(): void {
		// No adoption: the session is created after the order exists, so
		// every webhook can find its order from the first event onward.
		$order = $this->order();

		$this->service()->get_or_create_session( $order );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'cs_created_1', (string) $fresh->get_meta( XPay_Constants::META_SESSION_ID ) );
	}
}
