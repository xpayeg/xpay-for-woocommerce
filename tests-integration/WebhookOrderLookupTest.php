<?php
/**
 * Webhook order lookup, against real WooCommerce, on both order storages.
 *
 * The refund path is the reason this file exists. A refund event carries no
 * metadata — the payment intent id is the only correlation channel — so if
 * the lookup returns the wrong order, a stranger's order is refunded. The
 * contract suite could not see this because it never ran against the legacy
 * post store, where the lookup silently matched everything.
 *
 * @package XPay_For_WooCommerce
 */

class WebhookOrderLookupTest extends XPay_Integration_Test_Case {

	/**
	 * Call the private lookup on the real controller. Reflection rather than
	 * widening the class's surface: the production code under test must be
	 * the code that ships, not a test-only twin of it.
	 *
	 * @param string $type    Event type.
	 * @param array  $payload data.object payload.
	 */
	private function locate( string $type, array $payload ): ?WC_Order {
		$method = new ReflectionMethod( 'XPay_Webhook_Controller', 'locate_order' );
		$method->setAccessible( true );
		return $method->invoke( null, $type, $payload );
	}

	public function storages(): array {
		return array(
			'HPOS'           => array( true ),
			'legacy posts'   => array( false ),
		);
	}

	/**
	 * The bug, stated as a test: a refund for an intent this shop has never
	 * seen must find nothing. On the legacy store the meta condition was
	 * dropped by core, so the query returned the newest order in the shop
	 * and the refund landed on it.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_a_refund_for_an_unknown_intent_matches_no_order( bool $hpos ): void {
		$this->use_hpos( $hpos );

		// A shop with history. The newest of these is what a dropped
		// meta condition would hand back.
		$this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_first' ) );
		$this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_second' ) );
		$newest = $this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_newest' ) );

		$found = $this->locate(
			XPay_Event_Names::CHARGE_REFUNDED,
			array( 'paymentIntentId' => 'pi_never_seen_here' )
		);

		$this->assertNull(
			$found,
			$found instanceof WC_Order && $found->get_id() === $newest->get_id()
				? 'The lookup returned the newest order in the shop — the meta condition was dropped.'
				: 'The lookup invented a match for an unknown payment intent.'
		);
	}

	/**
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_a_refund_finds_exactly_the_order_that_holds_the_intent( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$wanted = $this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_wanted' ) );
		$this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_decoy_newer' ) );

		$found = $this->locate(
			XPay_Event_Names::CHARGE_REFUNDED,
			array( 'paymentIntentId' => 'pi_wanted' )
		);

		$this->assertInstanceOf( 'WC_Order', $found );
		$this->assertSame( $wanted->get_id(), $found->get_id() );
	}

	/**
	 * An intent id that is a prefix of a real one must not match it. A LIKE
	 * or a truncating comparison would pass the test above and fail this.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_a_partial_intent_id_does_not_match( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_abcdef123456' ) );

		$this->assertNull( $this->locate( XPay_Event_Names::CHARGE_REFUNDED, array( 'paymentIntentId' => 'pi_abcdef' ) ) );
	}

	/**
	 * An order paid through another gateway must never be returned, even if
	 * something wrote a matching meta key onto it.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_a_non_xpay_order_is_never_returned( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$order = new WC_Order();
		$order->set_payment_method( 'cod' );
		$order->update_meta_data( '_xpay_payment_intent_id', 'pi_on_a_cod_order' );
		$order->save();

		$this->assertNull( $this->locate( XPay_Event_Names::CHARGE_REFUNDED, array( 'paymentIntentId' => 'pi_on_a_cod_order' ) ) );
	}

	/**
	 * Two orders claiming one payment is not a state this plugin creates,
	 * and if it ever arises the safe answer is to sync nothing. Picking one
	 * would refund a stranger half the time.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_two_orders_claiming_one_intent_are_refused( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$first  = $this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_contested' ) );
		$second = $this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_contested' ) );

		$found = $this->locate( XPay_Event_Names::CHARGE_REFUNDED, array( 'paymentIntentId' => 'pi_contested' ) );

		$this->assertNull(
			$found,
			$found instanceof WC_Order
				? 'The lookup picked order ' . $found->get_id() . ' out of two that both claim the payment.'
				: 'The lookup guessed.'
		);
		$this->assertNotEmpty(
			XPay_Spy_Log_Handler::query( array( 'stage' => 'webhook.ambiguous_order_lookup' ) ),
			'Nothing was synced and nothing said so, which leaves a refund missing with no trace.'
		);
		unset( $first, $second );
	}

	/**
	 * One order carrying the meta key twice must not fill the limit.
	 *
	 * The lookup reads two rows and refuses when they name two orders. Two
	 * rows for the SAME order are not two orders, and an order can carry
	 * the key twice: two writers that both thought the order was unpaid did
	 * exactly that. Without DISTINCT those two rows fill the bounded read
	 * and hide the genuine second order behind them, so the refusal below
	 * never happens and a stranger's order takes the refund.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_a_duplicated_meta_row_does_not_hide_a_second_claimant( bool $hpos ): void {
		$this->use_hpos( $hpos );

		// Older, so a read ordered newest-first reaches it only after the
		// two rows below, and only if those two did not fill the limit
		// between them.
		$this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_contested_twice' ) );

		$doubled = $this->make_xpay_order();
		$doubled->add_meta_data( '_xpay_payment_intent_id', 'pi_contested_twice', false );
		$doubled->add_meta_data( '_xpay_payment_intent_id', 'pi_contested_twice', false );
		$doubled->save();

		$this->assertNull(
			$this->locate( XPay_Event_Names::CHARGE_REFUNDED, array( 'paymentIntentId' => 'pi_contested_twice' ) ),
			'Two rows for one order hid a second order that claims the same payment.'
		);
	}

	/**
	 * The lookup is never acted on alone.
	 *
	 * This is the control that makes a dropped or mis-built condition
	 * harmless instead of catastrophic, and the drop is not hypothetical:
	 * on the legacy post store core puts meta_query on an explicit
	 * unsupported list and silently removes it
	 * (class-wc-data-store-wp.php:284), leaving "newest order in the shop".
	 * That is simulated exactly here, by rewriting the lookup's own SQL
	 * into the query core would have degraded it to.
	 */
	public function test_an_order_that_does_not_carry_the_intent_is_refused_whatever_the_query_said(): void {
		global $wpdb;
		$this->use_hpos( false );

		$this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_belongs_to_someone_else' ) );
		$newest = $this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_newest' ) );

		$degrade = static function ( $sql ) use ( $wpdb ) {
			if ( false === strpos( (string) $sql, '_xpay_payment_intent_id' ) ) {
				return $sql;
			}
			// One row, so the ambiguity refusal above is not what answers
			// this: the re-check is the only thing left standing between the
			// event and the wrong order.
			return "SELECT DISTINCT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'shop_order' ORDER BY p.ID DESC LIMIT 1";
		};
		add_filter( 'query', $degrade );
		$found = $this->locate(
			XPay_Event_Names::CHARGE_REFUNDED,
			array( 'paymentIntentId' => 'pi_belongs_to_someone_else' )
		);
		remove_filter( 'query', $degrade );

		$this->assertNull(
			$found,
			$found instanceof WC_Order && $found->get_id() === $newest->get_id()
				? 'The newest order in the shop was handed back for a payment it does not carry, and a refund would land on it.'
				: 'An order that does not carry this intent was handed back.'
		);
		$this->assertNotEmpty(
			XPay_Spy_Log_Handler::query( array( 'stage' => 'webhook.intent_lookup_mismatch' ) ),
			'The lookup and the order disagreed and nothing recorded it.'
		);
	}

	/**
	 * An empty or missing intent id must not be treated as a wildcard.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_an_empty_intent_id_matches_nothing( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$this->make_xpay_order( array( '_xpay_payment_intent_id' => 'pi_real' ) );

		$this->assertNull( $this->locate( XPay_Event_Names::CHARGE_REFUNDED, array( 'paymentIntentId' => '' ) ) );
		$this->assertNull( $this->locate( XPay_Event_Names::CHARGE_REFUNDED, array() ) );
	}

	/**
	 * B5: metadata is the fast path, not the only path.
	 *
	 * A session created before the order existed carries the order id only
	 * from the moment process_payment patches it on, and a delivery can be
	 * in flight across that moment. The plugin writes the session id onto
	 * the order as soon as it has one, so the order stays findable from the
	 * session even while the session cannot yet name the order.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_a_session_event_with_no_metadata_still_finds_its_order( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_decoy' ) );
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_wanted' ) );
		$this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_newer_decoy' ) );

		$found = $this->locate(
			XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
			array( 'id' => 'cs_wanted' )
		);

		$this->assertInstanceOf( 'WC_Order', $found, 'A paid session could not find the order it belongs to.' );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_a_failed_intent_resolves_through_its_nested_session_id( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_from_intent' ) );

		$found = $this->locate(
			XPay_Event_Names::PAYMENT_INTENT_FAILED,
			array( 'checkoutSessionId' => 'cs_from_intent' )
		);

		$this->assertInstanceOf( 'WC_Order', $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * The fallback must not become a wildcard: a session this shop has never
	 * seen still resolves to nothing.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_an_unknown_session_id_still_finds_nothing( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_ours' ) );

		$this->assertNull(
			$this->locate( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED, array( 'id' => 'cs_someone_elses' ) )
		);
	}

	/**
	 * Metadata still wins when it is there, so the cheap path stays the
	 * common path.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_metadata_outranks_the_session_id_fallback( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$by_metadata = $this->make_xpay_order();
		$by_session  = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_shared' ) );

		$found = $this->locate(
			XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
			array(
				'id'       => 'cs_shared',
				'metadata' => array( 'wc_order_id' => (string) $by_metadata->get_id() ),
			)
		);

		$this->assertSame( $by_metadata->get_id(), $found->get_id() );
		$this->assertNotSame( $by_session->get_id(), $found->get_id() );
	}

	/**
	 * Session-scoped events resolve by the order id in metadata, on both
	 * storages. This path was never the bug, and this test is here so that
	 * the A1 fix cannot quietly break it.
	 *
	 * @dataProvider storages
	 * @param bool $hpos Storage under test.
	 */
	public function test_session_events_resolve_by_order_id_metadata( bool $hpos ): void {
		$this->use_hpos( $hpos );

		$order = $this->make_xpay_order();

		$found = $this->locate(
			XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
			array(
				'id'       => 'cs_1',
				'metadata' => array( 'wc_order_id' => (string) $order->get_id() ),
			)
		);

		$this->assertInstanceOf( 'WC_Order', $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}
}
