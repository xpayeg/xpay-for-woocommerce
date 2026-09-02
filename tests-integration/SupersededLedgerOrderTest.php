<?php
/**
 * The ledger names the old session BEFORE the order moves off it.
 *
 * XPay_Checkout_Service::get_or_create_session() supersedes a session that
 * can no longer be reused. The old one stays OPEN and payable on the
 * platform until the expire call lands, so a shopper finishing it in another
 * tab or from an emailed pay link still produces a checkout.session.completed
 * for it. The superseded ledger is the only thing that makes that money
 * recognizable: the webhook reads META_SESSION_ID first and falls back to the
 * ledger (class-xpay-webhook-controller.php:727-738), and an id in neither is
 * 'foreign'.
 *
 * 'foreign' is TERMINAL, not retried. The controller answers it 200
 * received/applied:false so XPay's retry engine is not alarmed
 * (class-xpay-webhook-controller.php:100-113). So an event judged foreign is
 * dropped once and never redelivered: money taken, order never marked paid,
 * never even parked on-hold for a human.
 *
 * Which makes the ORDER of the two writes the whole of the safety. The new
 * session id was committed by create_session()'s own save, and the ledger
 * entry by a second save afterwards, with an order note, a log row and up to
 * four option reads and writes in between. Every one of those is a database
 * round trip, and the webhook takes no order lock before it judges ownership
 * (the check sits above the acquire, at :425), so nothing serializes a
 * delivery against that gap.
 *
 * @package XPay_For_WooCommerce
 */

class SupersededLedgerOrderTest extends XPay_Integration_Test_Case {

	/** @var array|null What a fresh read of the order saw inside the window. */
	private $seen = null;

	/** @var int Which session the scripted platform hands out next. */
	private $minted = 0;

	public function set_up(): void {
		parent::set_up();
		$this->configure_gateway(
			array(
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_ledger',
				'test_publishable_key' => 'pk_test_ledger',
			)
		);
		$this->seen   = null;
		$this->minted = 0;
		add_filter( 'pre_http_request', array( $this, 'platform' ), 20, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'platform' ), 20 );
		parent::tear_down();
	}

	/**
	 * A platform that mints a new session id each time, so "the order moved
	 * off the old one" is a real change rather than a coincidence.
	 *
	 * @param mixed  $preempt Whatever an earlier filter returned; unused.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array A canned HTTP response.
	 */
	public function platform( $preempt, $args, $url ) {
		unset( $preempt, $url );

		if ( 'POST' === ( isset( $args['method'] ) ? $args['method'] : 'GET' ) ) {
			++$this->minted;
			$id = 'cs_ledger_' . $this->minted;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'id'           => $id,
						'clientSecret' => $id . '_secret',
						'status'       => 'open',
						'currency'     => 'EGP',
						'amountTotal'  => 12300,
					)
				),
			);
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'           => 'cs_ledger_1',
					'clientSecret' => 'cs_ledger_1_secret',
					'status'       => 'open',
					'currency'     => 'EGP',
					// Not the order's total any more, which is why the
					// session can no longer be reused and is superseded.
					'amountTotal'  => 999900,
				)
			),
		);
	}

	/**
	 * Read the order the way a webhook worker would, from the moment inside
	 * the window when the new session id is already committed.
	 *
	 * The probe hangs off the order note create_session() writes immediately
	 * after its own save, so it fires between the two commits without any
	 * timing assumption. XPay_Order_Sync::reload() is the accessor the
	 * webhook itself uses inside its lock, so what this sees is what that
	 * worker would see.
	 *
	 * @param int      $comment_id The note; unused.
	 * @param WC_Order $noted      The order the note was added to.
	 */
	public function probe( $comment_id, $noted ): void {
		unset( $comment_id );
		if ( null !== $this->seen ) {
			return;
		}
		$fresh      = XPay_Order_Sync::reload( $noted->get_id() );
		$superseded = $fresh->get_meta( XPay_Constants::META_SUPERSEDED_SESSIONS );
		$this->seen = array(
			'session_id' => (string) $fresh->get_meta( XPay_Constants::META_SESSION_ID ),
			'superseded' => is_array( $superseded ) ? $superseded : array(),
		);
	}

	/** An order already on a session, whose total has since moved. */
	private function order_being_superseded(): WC_Order {
		$order = $this->make_xpay_order();
		$order->set_total( '123.00' );
		$order->set_status( 'pending' );
		$order->save();

		$service = new XPay_Checkout_Service( $this->gateway()->api_client() );
		$service->get_or_create_session( $order );

		return $order;
	}

	public function test_the_old_session_is_in_the_ledger_before_the_new_one_is_committed(): void {
		$order = $this->order_being_superseded();
		$old   = (string) $order->get_meta( XPay_Constants::META_SESSION_ID );
		$this->assertSame( 'cs_ledger_1', $old );

		add_action( 'woocommerce_order_note_added', array( $this, 'probe' ), 10, 2 );
		$this->seen = null;
		( new XPay_Checkout_Service( $this->gateway()->api_client() ) )->get_or_create_session( $order );
		remove_action( 'woocommerce_order_note_added', array( $this, 'probe' ), 10 );

		$this->assertNotNull( $this->seen, 'The probe never ran, so the assertions below would pass vacuously.' );
		$this->assertSame(
			'cs_ledger_2',
			$this->seen['session_id'],
			'The probe fired before the order had moved off the old session, so it is not looking at the window at all.'
		);
		$this->assertContains(
			$old,
			$this->seen['superseded'],
			'A checkout.session.completed for the old session landing here is judged foreign, acknowledged 200 and dropped for good: money taken, order never marked paid and never parked.'
		);
	}

	/** The ledger still ends up correct once the call returns. */
	public function test_the_ledger_names_the_old_session_when_the_call_is_done(): void {
		$order = $this->order_being_superseded();
		$old   = (string) $order->get_meta( XPay_Constants::META_SESSION_ID );

		( new XPay_Checkout_Service( $this->gateway()->api_client() ) )->get_or_create_session( $order );

		$fresh = XPay_Order_Sync::reload( $order->get_id() );
		$this->assertSame( 'cs_ledger_2', (string) $fresh->get_meta( XPay_Constants::META_SESSION_ID ) );
		$this->assertContains( $old, (array) $fresh->get_meta( XPay_Constants::META_SUPERSEDED_SESSIONS ) );
	}
}
