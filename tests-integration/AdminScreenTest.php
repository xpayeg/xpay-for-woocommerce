<?php
/**
 * The settings screen, against real WordPress.
 *
 * The subjects are the screen's TRUTH RULES, not its markup: which card a
 * store sees is decided by a real validation proof; every badge answers
 * from a fact source (the fingerprinted key proof, the recorded webhook
 * endpoint, the cached live-activation flag); a stored secret is never
 * echoed into the page; and the AJAX verbs hold the same capability and
 * nonce line the payment endpoints do.
 *
 * @package XPay_For_WooCommerce
 */

class AdminScreenTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option(
			'woocommerce_xpay_settings',
			array(
				'enabled' => 'yes',
				'mode'    => 'test',
			)
		);
		delete_option( XPay_Constants::OPTION_KEY_VALIDATED );
		delete_option( 'xpay_wc_live_payments_disabled' );
	}

	/** Render the screen and return its HTML. */
	private function html(): string {
		ob_start();
		XPay_Admin_Screen::render( new XPay_Gateway() );
		return (string) ob_get_clean();
	}

	/** Store a validated pair for one plane. */
	private function prove_keys( bool $live, string $secret = 'rk_test_screen', string $publishable = 'pk_test_screen' ): void {
		$mode = $live ? 'live' : 'test';
		XPay_Webhook_Configurator::merge_settings(
			array(
				$mode . '_api_key'         => $secret,
				$mode . '_publishable_key' => $publishable,
			)
		);
		update_option(
			XPay_Constants::OPTION_KEY_VALIDATED,
			array(
				'mode'         => $mode,
				'validated_at' => time(),
				'fingerprint'  => XPay_Constants::key_fingerprint( $secret, $publishable ),
			),
			false
		);
	}

	/* ── Which card a store sees ─────────────────────────────────────── */

	public function test_a_fresh_install_sees_the_get_started_card(): void {
		$html = $this->html();

		$this->assertStringContainsString( 'Start accepting payments', $html );
		$this->assertStringNotContainsString( 'Account status', $html, 'A store with nothing connected has no status to report.' );
	}

	public function test_a_connected_store_sees_the_status_card_instead(): void {
		$this->prove_keys( false );

		$html = $this->html();

		$this->assertStringContainsString( 'Account status', $html );
		$this->assertStringNotContainsString( 'Start accepting payments', $html );
	}

	/* ── The badges answer from fact sources ─────────────────────────── */

	public function test_an_unactivated_live_account_is_awaiting_activation_not_enabled(): void {
		$this->prove_keys( true, 'rk_live_screen', 'pk_live_screen' );
		XPay_Webhook_Configurator::merge_settings( array( 'mode' => 'live' ) );
		update_option( 'xpay_wc_live_payments_disabled', '1', false );

		$this->assertStringContainsString( 'Awaiting activation', $this->html() );
	}

	public function test_the_webhook_badge_reads_the_recorded_endpoint(): void {
		$this->prove_keys( false );

		$unconfigured = $this->html();
		$this->assertStringContainsString( 'Not configured', $unconfigured );
		$this->assertStringNotContainsString( 'data-xpay-health', $unconfigured );

		XPay_Webhook_Configurator::merge_settings(
			array(
				'test_webhook_secret' => 'whsec_x',
				'test_webhook_data'   => array(
					'id'     => 'we_x',
					'url'    => XPay_Webhook_Configurator::webhook_url(),
					'secret' => 'rk_test_screen',
				),
			)
		);

		// 'Not configured' contains 'Configured', so the flip is proved
		// by the green badge class — and scoped to the TEST pane, because
		// the live plane legitimately stays unconfigured.
		$configured = $this->html();
		$this->assertMatchesRegularExpression(
			'/data-xpay-pane="test".*?Webhook.*?badge-value--ok">Configured</s',
			$configured
		);
		$this->assertStringContainsString( 'data-xpay-health', $configured );
	}

	/* ── Secrets never reach the page ────────────────────────────────── */

	public function test_a_stored_secret_key_is_never_echoed_into_the_html(): void {
		$this->prove_keys( false, 'rk_test_never_in_dom_123456', 'pk_test_fine_to_show' );
		XPay_Webhook_Configurator::merge_settings( array( 'test_webhook_secret' => 'whsec_never_in_dom_9' ) );

		$html = $this->html();

		$this->assertStringNotContainsString( 'rk_test_never_in_dom_123456', $html, 'A settings page is copied into support tickets whole; the secret must not ride along.' );
		$this->assertStringNotContainsString( 'whsec_never_in_dom_9', $html );
	}

	public function test_the_screen_renders_no_key_input_at_all(): void {
		// Keys arrive only through Connect: a key input on the page would
		// be a second, unvalidated way in, and the exact field a phishing
		// overlay would imitate. The declared key fields exist in storage
		// only (the unrendered-field restore keeps them across saves).
		$this->prove_keys( false );

		$html = $this->html();

		$this->assertStringNotContainsString( 'woocommerce_xpay_test_api_key', $html );
		$this->assertStringNotContainsString( 'woocommerce_xpay_live_api_key', $html );
		$this->assertStringNotContainsString( 'woocommerce_xpay_test_webhook_secret', $html );
	}

	/* ── The mode carrier ────────────────────────────────────────────── */

	public function test_the_mode_carrier_posts_the_saved_mode(): void {
		$this->assertMatchesRegularExpression(
			'/name="woocommerce_xpay_mode" value="test"/',
			$this->html()
		);

		XPay_Webhook_Configurator::merge_settings( array( 'mode' => 'live' ) );
		$this->assertMatchesRegularExpression(
			'/name="woocommerce_xpay_mode" value="live"/',
			$this->html()
		);
	}

	/* ── The validation proof (the badge's truth source) ─────────────── */

	public function test_keys_validated_is_true_only_for_the_proved_pair(): void {
		$this->prove_keys( false );
		$gateway = new XPay_Gateway();

		$this->assertTrue( XPay_Admin_Screen::keys_validated( $gateway, false ) );
		$this->assertFalse( XPay_Admin_Screen::keys_validated( $gateway, true ), 'A test-mode proof says nothing about live.' );

		// Keys swapped around the save path (the REST settings route never
		// validates): the fingerprint is what notices.
		XPay_Webhook_Configurator::merge_settings( array( 'test_api_key' => 'rk_test_swapped_in' ) );
		$this->assertFalse( XPay_Admin_Screen::keys_validated( new XPay_Gateway(), false ) );
	}

	/* ── AJAX verbs ──────────────────────────────────────────────────── */

	/**
	 * @param string $verb Verb suffix.
	 * @param array  $body POST fields.
	 * @return array Decoded JSON answer.
	 */
	private function call_verb( string $verb, array $body = array() ): array {
		$_POST          = $body;
		$_POST['nonce'] = wp_create_nonce( XPay_Admin_Screen::NONCE_ACTION );
		$_REQUEST       = $_POST;

		/*
		 * wp_send_json_* echoes the body, then wp_die()s under AJAX and
		 * plain die()s otherwise — which would kill the whole suite. Both
		 * filters are the fix: doing_ajax routes to the ajax die handler,
		 * and the handler throws instead of exiting. The JSON is then in
		 * the buffer.
		 */
		add_filter( 'wp_doing_ajax', '__return_true' );
		$thrower = static function () {
			return static function () {
				throw new WPDieException( '' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $thrower );

		ob_start();
		try {
			XPay_Admin_Screen::{'handle_' . $verb}();
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		$json = json_decode( (string) ob_get_clean(), true );

		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $thrower );

		$_POST    = array();
		$_REQUEST = array();
		return is_array( $json ) ? $json : array();
	}

	public function test_the_health_verb_answers_the_states_message(): void {
		XPay_Webhook_State::record_failure( false, XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID );

		$answer = $this->call_verb( 'health', array( 'plane' => 'test' ) );

		$this->assertTrue( $answer['success'] );
		$this->assertSame( 4, $answer['data']['code'] );
		$this->assertStringContainsString( 'could not be processed', $answer['data']['message'] );
	}

	public function test_disconnect_clears_exactly_one_planes_connection(): void {
		$this->prove_keys( false );
		XPay_Webhook_Configurator::merge_settings(
			array(
				'live_api_key'        => 'rk_live_other',
				'test_webhook_secret' => 'whsec_x',
				'test_webhook_data'   => array(
					'id'     => 'we_x',
					'url'    => XPay_Webhook_Configurator::webhook_url(),
					'secret' => 'rk_test_screen',
				),
			)
		);

		$answer = $this->call_verb( 'disconnect', array( 'plane' => 'test' ) );
		$this->assertTrue( $answer['success'] );

		$settings = get_option( 'woocommerce_xpay_settings' );
		$this->assertSame( '', $settings['test_api_key'] );
		$this->assertSame( '', $settings['test_webhook_secret'] );
		$this->assertSame( array(), $settings['test_webhook_data'] );
		$this->assertSame( 'rk_live_other', $settings['live_api_key'], 'The other mode is untouched.' );
		$this->assertFalse( get_option( XPay_Constants::OPTION_KEY_VALIDATED ), 'A disconnected mode cannot keep its Connected badge.' );
	}

	public function test_reconfigure_refuses_without_keys_and_rate_limits(): void {
		$first = $this->call_verb( 'reconfigure_webhooks', array( 'plane' => 'test' ) );
		$this->assertFalse( $first['success'] );
		$this->assertStringContainsString( 'keys first', $first['data']['message'] );

		// The second attempt inside the window is refused BEFORE any work,
		// whatever the first attempt's outcome was.
		$second = $this->call_verb( 'reconfigure_webhooks', array( 'plane' => 'test' ) );
		$this->assertFalse( $second['success'] );
		$this->assertStringContainsString( 'minute', $second['data']['message'] );
	}

	public function test_every_verb_requires_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$answer = $this->call_verb( 'health', array( 'plane' => 'test' ) );

		$this->assertFalse( $answer['success'] );
		$this->assertSame( 'forbidden', $answer['data']['reason'] );
	}

	/* ── The mode lock (Stripe's test-mode checkbox rule) ────────────── */

	public function test_test_mode_cannot_be_left_without_live_keys(): void {
		$this->prove_keys( false );

		$html = $this->html();

		$this->assertMatchesRegularExpression( '/data-xpay-testmode[^>]*disabled/', $html, 'Leaving test mode with no live keys would run the checkout on a mode that cannot charge.' );
		$this->assertStringContainsString( 'Live mode cannot be enabled before you have connected a live XPay account.', $html );
		$this->assertStringContainsString( 'data-xpay-modal-tab="live"', $html, 'The notice must hand the merchant the connection dialog opened on the plane missing its keys.' );
	}

	public function test_the_mode_checkbox_unlocks_once_both_planes_hold_keys(): void {
		$this->prove_keys( false );
		XPay_Webhook_Configurator::merge_settings(
			array(
				'live_api_key'         => 'rk_live_screen',
				'live_publishable_key' => 'pk_live_screen',
			)
		);

		$html = $this->html();

		$this->assertDoesNotMatchRegularExpression( '/data-xpay-testmode[^>]*disabled/', $html );
		$this->assertStringNotContainsString( 'cannot be enabled before', $html );
	}

	public function test_live_mode_cannot_be_left_without_test_keys(): void {
		XPay_Webhook_Configurator::merge_settings(
			array(
				'mode'                 => 'live',
				'live_api_key'         => 'rk_live_screen',
				'live_publishable_key' => 'pk_live_screen',
			)
		);

		$html = $this->html();

		$this->assertMatchesRegularExpression( '/data-xpay-testmode[^>]*disabled/', $html );
		$this->assertStringContainsString( 'Test mode cannot be enabled before you have connected a test XPay account.', $html );
		$this->assertStringContainsString( 'data-xpay-modal-tab="test"', $html );
	}

	/* ── The Payment Methods tab ─────────────────────────────────────── */

	/** Cache an account map so the tab has methods to show. */
	private function cache_methods(): void {
		update_option(
			XPay_Constants::account_methods_option( false ),
			array( 'EGP' => array( 'card', 'valu', 'fawry' ) )
		);
	}

	public function test_the_methods_tab_renders_one_row_per_account_method(): void {
		$this->prove_keys( false );
		$this->cache_methods();

		$html = $this->html();

		delete_option( XPay_Constants::account_methods_option( false ) );

		$this->assertStringContainsString( 'data-xpay-page-tab="methods"', $html );
		$this->assertStringContainsString( 'data-xpay-page-tab="settings"', $html );
		$this->assertStringContainsString( 'data-xpay-type="card"', $html );
		$this->assertStringContainsString( 'data-xpay-type="valu"', $html );
		$this->assertStringContainsString( 'data-xpay-type="fawry"', $html );
		$this->assertStringContainsString( 'name="xpay_method_enabled[]"', $html );
		$this->assertStringContainsString( 'name="xpay_methods_present"', $html, 'Without the marker, a save from this page would wipe the checked list.' );
		$this->assertStringContainsString( 'Manage available methods in your XPay dashboard', $html, 'The banner states where the list comes from.' );
		$this->assertStringContainsString( 'Refresh payment methods', $html, 'The card head carries the account-refresh menu item, like Stripe\'s.' );
	}

	public function test_without_an_account_map_there_are_no_tabs(): void {
		$this->prove_keys( false );

		$html = $this->html();

		$this->assertStringNotContainsString( 'data-xpay-page-tab', $html );
		$this->assertStringNotContainsString( 'xpay_methods_present', $html );
	}

	public function test_the_reorder_verb_saves_a_permutation_and_orders_the_checkout(): void {
		$this->prove_keys( false );
		$this->cache_methods();
		update_option( 'woocommerce_gateway_order', array( 'xpay' => '0', 'cod' => '1' ) );

		$answer = $this->call_verb( 'save_method_order', array( 'order' => array( 'fawry', 'card', 'valu' ) ) );

		$saved   = get_option( XPay_Constants::OPTION_METHOD_ORDER );
		$gateway = array_keys( (array) get_option( 'woocommerce_gateway_order' ) );
		delete_option( XPay_Constants::account_methods_option( false ) );
		delete_option( XPay_Constants::OPTION_METHOD_ORDER );
		delete_option( 'woocommerce_gateway_order' );

		$this->assertTrue( $answer['success'] );
		$this->assertSame( array( 'fawry', 'card', 'valu' ), $saved );
		$this->assertSame(
			array( 'xpay_fawry', 'xpay', 'xpay_valu', 'cod' ),
			$gateway,
			'The saved order must become the REAL checkout order through the gateway-ordering option.'
		);
	}

	public function test_the_reorder_verb_refuses_anything_but_a_permutation(): void {
		$this->prove_keys( false );
		$this->cache_methods();

		$missing = $this->call_verb( 'save_method_order', array( 'order' => array( 'card', 'valu' ) ) );
		$forged  = $this->call_verb( 'save_method_order', array( 'order' => array( 'card', 'valu', 'fawry', 'stolen' ) ) );
		$empty   = $this->call_verb( 'save_method_order', array() );

		delete_option( XPay_Constants::account_methods_option( false ) );

		$this->assertFalse( $missing['success'], 'A list missing a method is a stale page, not an order.' );
		$this->assertFalse( $forged['success'] );
		$this->assertFalse( $empty['success'] );
		$this->assertFalse( get_option( XPay_Constants::OPTION_METHOD_ORDER ), 'Nothing may be stored from a refused save.' );
	}
}
