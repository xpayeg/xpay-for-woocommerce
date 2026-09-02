<?php
/**
 * The standalone money-safety fixes, against real WooCommerce.
 *
 * Every assertion here is against core behaviour rather than against a
 * shim, because in every one of these cases the bug WAS our reading of
 * core: what a nonce is scoped to, when a payment box is drawn, when an
 * unpaid order is swept away.
 *
 * @package XPay_For_WooCommerce
 */

class GroupAMoneySafetyTest extends XPay_Integration_Test_Case {

	/* ── A2: nonce scoping and method ────────────────────────────────── */

	/**
	 * WooCommerce binds a logged-out shopper's nonce to their own session
	 * only when the action starts with "woocommerce"
	 * (`class-wc-session-handler.php:627`). Anything else is one nonce for
	 * every guest in the shop.
	 */
	public function test_the_nonce_action_is_scoped_to_the_individual_shopper(): void {
		$this->assertStringStartsWith(
			'woocommerce',
			XPay_Checkout_Elements::NONCE_ACTION,
			'The nonce action must start with "woocommerce" or every guest shares one nonce.'
		);
	}

	/**
	 * The scoping is not folklore — prove core actually applies it to this
	 * exact string, so a future rename cannot silently undo it.
	 */
	public function test_core_scopes_this_action_to_the_session_customer(): void {
		$handler = new WC_Session_Handler();

		$this->assertSame(
			0,
			$handler->maybe_update_nonce_user_logged_out( 0, 'xpay_checkout_elements' ),
			'Sanity check: an unprefixed action is NOT scoped, which was the bug.'
		);

		// With a session, the prefixed action is rewritten to the customer id.
		WC()->initialize_session();
		WC()->session->set_customer_session_cookie( true );
		$customer_id = WC()->session->get_customer_id();
		$this->assertNotEmpty( $customer_id );

		$this->assertSame(
			$customer_id,
			WC()->session->maybe_update_nonce_user_logged_out( 0, XPay_Checkout_Elements::NONCE_ACTION ),
			'Core did not scope our nonce action to this shopper.'
		);
	}

	/**
	 * Drive one of the endpoints' guards and return the JSON body it sent.
	 *
	 * wp_send_json_* ends the request, so the AJAX die handler is swapped
	 * for one that throws instead — the guard under test stays the shipped
	 * guard.
	 *
	 * The HTTP status is deliberately NOT asserted here, and that is a
	 * limitation worth stating rather than papering over: the WordPress test
	 * bootstrap prints before any test runs, so headers_sent() is true for
	 * the whole process and wp_send_json() skips its status_header() call
	 * entirely. A status assertion in this harness would read 0 and pass
	 * against nothing. The `reason` below is the contract both drivers
	 * branch on, and it is real.
	 *
	 * @param callable $run What to call.
	 * @return array|null Decoded JSON body, or null when nothing was sent.
	 */
	private function capture_json( callable $run ): ?array {
		$thrower = static function () {
			return static function () {
				throw new WPDieException( 'sent' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $thrower );

		ob_start();
		try {
			$run();
			return json_decode( (string) ob_get_clean(), true );
		} catch ( WPDieException $e ) {
			return json_decode( (string) ob_get_clean(), true );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $thrower );
		}
	}

	public function test_the_endpoints_refuse_anything_that_is_not_a_post(): void {
		$verify = new ReflectionMethod( 'XPay_Checkout_Elements', 'verify' );
		$verify->setAccessible( true );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$body                      = $this->capture_json(
			static function () use ( $verify ) {
				$verify->invoke( null );
			}
		);
		unset( $_SERVER['REQUEST_METHOD'] );

		$this->assertIsArray( $body, 'A GET was allowed straight through the guard.' );
		$this->assertFalse( $body['success'] );
		$this->assertSame( 'bad-method', $body['data']['reason'] );
	}

	public function test_a_post_without_a_valid_nonce_is_refused(): void {
		$verify = new ReflectionMethod( 'XPay_Checkout_Elements', 'verify' );
		$verify->setAccessible( true );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['nonce']         = 'not-a-real-nonce';
		$body                      = $this->capture_json(
			static function () use ( $verify ) {
				$verify->invoke( null );
			}
		);
		unset( $_SERVER['REQUEST_METHOD'], $_REQUEST['nonce'] );

		$this->assertIsArray( $body );
		$this->assertSame( 'bad-nonce', $body['data']['reason'] );
	}

	public function test_a_post_with_a_valid_nonce_passes(): void {
		$verify = new ReflectionMethod( 'XPay_Checkout_Elements', 'verify' );
		$verify->setAccessible( true );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['nonce']         = wp_create_nonce( XPay_Checkout_Elements::NONCE_ACTION );
		$body                      = $this->capture_json(
			static function () use ( $verify ) {
				$verify->invoke( null );
			}
		);
		unset( $_SERVER['REQUEST_METHOD'], $_REQUEST['nonce'] );

		$this->assertNull( $body, 'A legitimate request was refused — the guard is too tight to use.' );
	}

	/* ── A5: the payment box is drawn at all ─────────────────────────── */

	/**
	 * Core draws the payment box, and so calls payment_fields(), only when
	 * `has_fields() || get_description()`
	 * (`templates/checkout/payment-method.php:28`). Relying on the
	 * description means a merchant who clears that box deletes their own
	 * card form.
	 */
	public function test_the_card_form_survives_an_empty_description(): void {
		$this->configure_gateway( array( 'description' => '' ) );

		$gateway = $this->gateway();

		$this->assertSame( '', $gateway->get_description() );
		$this->assertTrue(
			(bool) $gateway->has_fields(),
			'With no description and no fields flag, core never renders the payment box.'
		);
	}

	/* ── A4: the unpaid-order sweep ──────────────────────────────────── */

	public function test_an_xpay_order_mid_payment_is_not_cancelled(): void {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_live_one' ) );

		$this->assertFalse(
			XPay_Order_Sync::should_cancel_unpaid( true, $order ),
			'A payment still open at XPay was swept away by the stock-hold timer.'
		);
	}

	public function test_the_shopper_is_told_why_in_an_order_note(): void {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_live_two' ) );

		XPay_Order_Sync::should_cancel_unpaid( true, $order );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty( $notes, 'Holding back a cancellation left no trace on the order.' );
		$this->assertStringContainsString( 'XPay', $notes[0]->content );
	}

	public function test_the_note_is_written_once_not_once_per_sweep(): void {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_live_three' ) );

		XPay_Order_Sync::should_cancel_unpaid( true, $order );
		XPay_Order_Sync::should_cancel_unpaid( true, wc_get_order( $order->get_id() ) );
		XPay_Order_Sync::should_cancel_unpaid( true, wc_get_order( $order->get_id() ) );

		$this->assertCount( 1, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * The protection is bounded. Refusing forever would hold stock forever.
	 */
	public function test_the_protection_expires(): void {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_old' ) );

		add_filter( 'xpay_unpaid_order_grace_seconds', '__return_zero' );
		$decision = XPay_Order_Sync::should_cancel_unpaid( true, $order );
		remove_filter( 'xpay_unpaid_order_grace_seconds', '__return_zero' );

		$this->assertTrue( $decision, 'The grace period never ends, so stock is held forever.' );
	}

	/**
	 * The hold does not need to outlast a Fawry reference, because a
	 * reference order is not `pending`. A deferred session completes
	 * (unpaid) at reference issuance and the order parks ON-HOLD awaiting
	 * payment; WooCommerce's sweep cancels `pending` only, so the on-hold
	 * order is already outside its reach for the reference's whole life.
	 * The old assertion here demanded a 30-hour hold to cover the voucher,
	 * which protected nothing and held abandoned-checkout stock for 30
	 * hours. The hold now covers only the order-created-to-webhook gap.
	 */
	public function test_an_awaiting_payment_order_is_outside_the_sweeps_reach(): void {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_fawry' ) );

		XPay_Order_Sync::mark_awaiting_payment(
			$order,
			array(
				'id'            => 'cs_fawry',
				'status'        => 'complete',
				'paymentStatus' => 'unpaid',
			)
		);

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $fresh->get_status(), 'An awaiting order must leave `pending`, the only status the sweep cancels.' );
	}

	public function test_an_order_that_never_reached_xpay_is_left_to_core(): void {
		$order = $this->make_xpay_order();

		$this->assertTrue( XPay_Order_Sync::should_cancel_unpaid( true, $order ) );
	}

	public function test_another_gateways_order_is_never_touched(): void {
		$order = new WC_Order();
		$order->set_payment_method( 'cod' );
		$order->save();

		$this->assertTrue( XPay_Order_Sync::should_cancel_unpaid( true, $order ) );
	}

	public function test_a_decision_core_already_made_against_cancelling_is_respected(): void {
		$order = $this->make_xpay_order( array( XPay_Constants::META_SESSION_ID => 'cs_x' ) );

		$this->assertFalse(
			XPay_Order_Sync::should_cancel_unpaid( false, $order ),
			'We must never turn a "do not cancel" into a "cancel".'
		);
	}

	/* ── A6: the validation badge ────────────────────────────────────── */

	private function keys_validated( bool $live ): bool {
		return XPay_Admin_Screen::keys_validated( $this->gateway(), $live );
	}

	public function test_the_badge_is_green_for_the_pair_that_was_validated(): void {
		$this->configure_gateway(
			array(
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_abc',
				'test_publishable_key' => 'pk_test_abc',
			)
		);
		update_option(
			XPay_Constants::OPTION_KEY_VALIDATED,
			array(
				'mode'        => 'test',
				'fingerprint' => XPay_Constants::key_fingerprint( 'rk_test_abc', 'pk_test_abc' ),
			),
			false
		);

		$this->assertTrue( $this->keys_validated( false ) );
	}

	/**
	 * The REST settings route writes gateway settings without ever calling
	 * process_admin_options(), so a key can be swapped with the badge left
	 * standing. The fingerprint is the only thing that notices.
	 */
	public function test_swapping_a_key_behind_the_settings_screen_drops_the_badge(): void {
		$this->configure_gateway(
			array(
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_abc',
				'test_publishable_key' => 'pk_test_abc',
			)
		);
		update_option(
			XPay_Constants::OPTION_KEY_VALIDATED,
			array(
				'mode'        => 'test',
				'fingerprint' => XPay_Constants::key_fingerprint( 'rk_test_abc', 'pk_test_abc' ),
			),
			false
		);

		// Exactly what the REST route does: write the option, nothing else.
		$this->configure_gateway( array( 'test_api_key' => 'rk_test_someone_elses' ) );

		$this->assertFalse(
			$this->keys_validated( false ),
			'A key was replaced without validation and the screen still says "Keys validated".'
		);
	}

	public function test_a_proof_from_before_the_fingerprint_existed_still_counts(): void {
		$this->configure_gateway(
			array(
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_abc',
				'test_publishable_key' => 'pk_test_abc',
			)
		);
		update_option( XPay_Constants::OPTION_KEY_VALIDATED, array( 'mode' => 'test' ), false );

		$this->assertTrue(
			$this->keys_validated( false ),
			'Upgrading merchants must not be forced to re-save to keep a badge they earned.'
		);
	}

	public function test_the_fingerprint_never_contains_the_key(): void {
		$print = XPay_Constants::key_fingerprint( 'rk_test_SECRETVALUE', 'pk_test_PUBLICVALUE' );

		$this->assertStringNotContainsString( 'SECRETVALUE', $print );
		$this->assertStringNotContainsString( 'PUBLICVALUE', $print );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $print );
	}

	public function test_an_empty_publishable_key_is_not_read_as_a_test_key(): void {
		// is_live_key( '' ) is false, so a bare plane comparison would tell a
		// merchant with a LIVE secret that their key is a TEST key.
		$this->assertFalse( XPay_Api_Client::is_live_key( '' ) );

		$this->configure_gateway(
			array(
				'mode'                 => 'live',
				'live_api_key'         => 'rk_live_abc',
				'live_publishable_key' => '',
			)
		);

		$this->assertFalse(
			$this->keys_validated( true ),
			'A missing publishable key must not read as a validated pair.'
		);
	}
}
