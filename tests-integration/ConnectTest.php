<?php
/**
 * Connect with XPay, against real WordPress.
 *
 * The subjects are the flow's security line and its one promise: begin()
 * registers this store and answers an authorize URL whose every parameter
 * is right; the callback refuses everything that is not its exact happy
 * path (wrong user, wrong state, spent flow, wrong issuer, wrong-mode
 * response) WITHOUT writing a byte of settings; and a verified return
 * delivers the keys through the SAME validate-and-provision path a manual
 * save runs, so proof, caches and webhook land identically.
 *
 * The endpoint registration itself is asserted too — an endpoint once
 * shipped unregistered here with green tests, because they called the
 * handler directly.
 *
 * @package XPay_For_WooCommerce
 */

class ConnectTest extends XPay_Integration_Test_Case {

	/** @var int */
	private $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		update_option(
			'woocommerce_xpay_settings',
			array(
				'enabled' => 'no',
				'mode'    => 'test',
			)
		);
		delete_option( XPay_Constants::OPTION_CONNECT_CLIENT );
		delete_option( XPay_Constants::OPTION_CONNECT_FLOW );
		delete_option( XPay_Constants::OPTION_KEY_VALIDATED );
		$GLOBALS['xpay_test_http']          = array();
		$GLOBALS['xpay_test_http_requests'] = array();
	}

	public function tear_down(): void {
		$GLOBALS['xpay_test_http']          = array();
		$GLOBALS['xpay_test_http_requests'] = array();
		$_GET                               = array();
		XPay_Connect::take_notices();
		parent::tear_down();
	}

	/* ── Harness ─────────────────────────────────────────────────────── */

	private function script_registration(): void {
		$GLOBALS['xpay_test_http']['oauth2/register'] = array(
			'response' => array( 'code' => 201 ),
			'body'     => wp_json_encode( array( 'client_id' => 'cid_test_1' ) ),
		);
	}

	/**
	 * Script the whole happy return leg: token exchange, account read,
	 * webhook creation.
	 */
	private function script_success_exchange(): void {
		$GLOBALS['xpay_test_http']['oauth2/token'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'access_token'         => 'xpo_short_lived',
					'token_type'           => 'Bearer',
					'expires_in'           => 60,
					'scope'                => 'merchant.connect.test',
					'xpay_merchant_id'     => 'acct_1',
					'xpay_mode'            => 'test',
					'xpay_restricted_key'  => 'rk_test_connected',
					'xpay_publishable_key' => 'pk_test_connected',
				)
			),
		);
		$GLOBALS['xpay_test_http']['/webhook-endpoints'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'     => 'we_conn_1',
					'url'    => XPay_Webhook_Configurator::webhook_url(),
					'secret' => 'whsec_conn_1',
				)
			),
		);
		$GLOBALS['xpay_test_http']['/account'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'id'                  => 'acct_1',
					'displayName'         => 'Connected Biz',
					'livemode'            => false,
					'supportedCurrencies' => array(
						array(
							'code'               => 'EGP',
							'decimals'           => 2,
							'paymentMethodTypes' => array( 'card', 'valu' ),
						),
					),
					'apiKey'              => array(
						'type'        => 'RESTRICTED',
						'mode'        => 'test',
						'permissions' => array( 'CHECKOUT_SESSIONS_WRITE', 'REFUNDS_WRITE', 'WEBHOOK_ENDPOINTS_WRITE' ),
					),
				)
			),
		);
	}

	/**
	 * Requests whose URL contains a needle.
	 *
	 * @param string $needle URL substring.
	 */
	private function requests_to( string $needle ): array {
		$hits = array();
		foreach ( $GLOBALS['xpay_test_http_requests'] as $request ) {
			if ( false !== strpos( $request['url'], $needle ) ) {
				$hits[] = $request;
			}
		}
		return $hits;
	}

	/** Begin a test-mode flow and hand back its stored record. */
	private function begin_flow(): array {
		$this->script_registration();
		$url  = XPay_Connect::begin( false );
		$flow = get_option( XPay_Constants::OPTION_CONNECT_FLOW );
		$this->assertIsArray( $flow );
		return array(
			'url'  => $url,
			'flow' => $flow,
		);
	}

	/** Arrive back from XPay with the given query. */
	private function arrive( array $query ): string {
		$_GET = $query;
		return XPay_Connect::handle_callback();
	}

	/* ── Wiring ──────────────────────────────────────────────────────── */

	public function test_the_callback_endpoint_is_registered_with_wc_api(): void {
		$this->assertNotFalse(
			has_action( 'woocommerce_api_' . XPay_Connect::CALLBACK_ENDPOINT ),
			'The OAuth callback is not registered; every connect would 404 at the return leg.'
		);
		// The wp_ajax hooks only exist under is_admin(), which this
		// bootstrap is not; the verb's wiring IS its row in AJAX_VERBS
		// (register() loops it), so that row plus a real handler is what
		// can break.
		$this->assertContains( 'connect', XPay_Admin_Screen::AJAX_VERBS, 'The connect verb is not registered; the button has nothing to call.' );
		$this->assertTrue( is_callable( array( 'XPay_Admin_Screen', 'handle_connect' ) ) );
	}

	public function test_a_plain_http_store_is_told_before_it_starts(): void {
		// The test environment's home_url is http and not loopback: the
		// exact state the server would refuse at registration.
		$this->assertFalse( XPay_Connect::https_ready() );
	}

	/* ── begin() ─────────────────────────────────────────────────────── */

	public function test_begin_registers_the_store_and_builds_the_authorize_url(): void {
		$started = $this->begin_flow();

		$registrations = $this->requests_to( 'oauth2/register' );
		$this->assertCount( 1, $registrations );
		$sent = json_decode( $registrations[0]['body'], true );
		$this->assertSame( array( XPay_Connect::callback_url() ), $sent['redirect_uris'] );
		$this->assertSame( 'none', $sent['token_endpoint_auth_method'] );
		$this->assertStringContainsString( 'example.org', $sent['client_name'], 'The consent screen and the minted key are named after this; a nameless client identifies nothing.' );

		$client = get_option( XPay_Constants::OPTION_CONNECT_CLIENT );
		$this->assertSame( 'cid_test_1', $client['client_id'] );
		$this->assertSame( XPay_Connect::callback_url(), $client['redirect_uri'] );

		$url = $started['url'];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		$this->assertStringStartsWith( XPay_Constants::oauth_base() . '/oauth2/authorize', $url );
		$this->assertSame( 'code', $params['response_type'] );
		$this->assertSame( 'cid_test_1', $params['client_id'] );
		$this->assertSame( XPay_Connect::callback_url(), $params['redirect_uri'] );
		$this->assertSame( 'merchant.connect.test', $params['scope'] );
		$this->assertSame( $started['flow']['state'], $params['state'] );
		$this->assertSame( 'S256', $params['code_challenge_method'] );
		$this->assertSame( XPay_Connect::challenge( $started['flow']['verifier'] ), $params['code_challenge'] );
	}

	public function test_begin_reuses_the_registration_and_live_asks_the_live_scope(): void {
		$this->begin_flow();
		$url = XPay_Connect::begin( true );

		$this->assertCount( 1, $this->requests_to( 'oauth2/register' ), 'Registration is once per install, not per click.' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		$this->assertSame( 'merchant.connect.live', $params['scope'] );
	}

	/* ── The callback's refusals ─────────────────────────────────────── */

	public function test_a_logged_out_return_goes_through_login_and_keeps_the_query(): void {
		wp_set_current_user( 0 );

		$destination = $this->arrive(
			array(
				'code'  => 'ac_1',
				'state' => 'whatever',
			)
		);

		$this->assertStringContainsString( 'wp-login.php', $destination );
		$this->assertStringContainsString( 'xpay_connect', $destination );
	}

	public function test_a_user_who_cannot_manage_the_store_is_refused(): void {
		$this->begin_flow();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->arrive(
			array(
				'code'  => 'ac_1',
				'state' => 'irrelevant',
			)
		);

		$this->assertSame( array(), $this->requests_to( 'oauth2/token' ), 'No exchange may run for a user who cannot manage the store.' );
	}

	public function test_a_wrong_state_is_refused_and_the_flow_is_spent(): void {
		$this->begin_flow();

		$this->arrive(
			array(
				'code'  => 'ac_1',
				'state' => 'FORGED',
			)
		);

		$this->assertSame( array(), $this->requests_to( 'oauth2/token' ) );
		$this->assertFalse( get_option( XPay_Constants::OPTION_CONNECT_FLOW ), 'A refused flow must be consumed, not left redeemable.' );
		$settings = get_option( 'woocommerce_xpay_settings' );
		$this->assertArrayNotHasKey( 'test_api_key', $settings, 'A refused callback wrote settings.' );

		$notices = XPay_Connect::take_notices();
		$this->assertSame( 'error', $notices[0]['type'] );
	}

	public function test_a_wrong_issuer_is_refused_before_the_exchange(): void {
		$started = $this->begin_flow();

		$this->arrive(
			array(
				'code'  => 'ac_1',
				'state' => $started['flow']['state'],
				'iss'   => 'https://evil.example/api/auth',
			)
		);

		$this->assertSame( array(), $this->requests_to( 'oauth2/token' ), 'A foreign issuer must never receive our exchange.' );
	}

	public function test_a_refusal_from_xpay_shows_its_own_explanation(): void {
		$this->begin_flow();

		$this->arrive(
			array(
				'error'             => 'access_denied',
				'error_description' => 'Your business is not approved for live payments yet.',
			)
		);

		$this->assertSame( array(), $this->requests_to( 'oauth2/token' ) );
		$this->assertFalse( get_option( XPay_Constants::OPTION_CONNECT_FLOW ) );
		$notices = XPay_Connect::take_notices();
		$this->assertStringContainsString( 'not approved for live', $notices[0]['text'], "XPay's own explanation is more precise than anything composed here." );
	}

	public function test_a_wrong_mode_response_writes_nothing(): void {
		$started = $this->begin_flow(); // A TEST flow.
		$this->script_success_exchange();
		$GLOBALS['xpay_test_http']['oauth2/token'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'xpay_mode'            => 'live',
					'xpay_restricted_key'  => 'rk_live_wrong',
					'xpay_publishable_key' => 'pk_live_wrong',
				)
			),
		);

		$this->arrive(
			array(
				'code'  => 'ac_1',
				'state' => $started['flow']['state'],
			)
		);

		$settings = get_option( 'woocommerce_xpay_settings' );
		$this->assertArrayNotHasKey( 'test_api_key', $settings );
		$this->assertArrayNotHasKey( 'live_api_key', $settings, 'A live answer to a test flow must not land in EITHER plane.' );
	}

	/* ── Duplicate delivery ──────────────────────────────────────────── */

	/**
	 * A callback that loses the claim is a duplicate, not a forgery: it
	 * must exchange nothing and say nothing.
	 */
	public function test_a_duplicate_callback_does_not_bury_the_success_it_arrives_after(): void {
		$started = $this->begin_flow();
		$this->script_success_exchange();
		$query = array(
			'code'  => 'ac_delivered_twice',
			'state' => $started['flow']['state'],
		);

		$first  = $this->arrive( $query );
		$second = $this->arrive( $query );

		$this->assertCount( 1, $this->requests_to( 'oauth2/token' ), 'The duplicate redeemed the code a second time.' );
		$this->assertSame( $first, $second, 'Both deliveries land the merchant on the settings screen.' );

		$texts = implode( ' ', wp_list_pluck( XPay_Connect::take_notices(), 'text' ) );
		$this->assertStringContainsString( 'XPay connected (test mode).', $texts );
		$this->assertStringNotContainsString( 'stale', $texts, 'The duplicate overwrote the success notice with a failure.' );

		// And the connection itself is untouched by the second arrival.
		$settings = get_option( 'woocommerce_xpay_settings' );
		$this->assertSame( 'rk_test_connected', $settings['test_api_key'] );
	}

	/* ── The happy path ──────────────────────────────────────────────── */

	public function test_a_verified_return_provisions_exactly_like_a_key_save(): void {
		$started = $this->begin_flow();
		$this->script_success_exchange();

		$destination = $this->arrive(
			array(
				'code'  => 'ac_verified',
				'state' => $started['flow']['state'],
			)
		);

		// The exchange carried the proof material, to the registered URI.
		$exchanges = $this->requests_to( 'oauth2/token' );
		$this->assertCount( 1, $exchanges );
		parse_str( (string) $exchanges[0]['body'], $sent );
		$this->assertSame( 'authorization_code', $sent['grant_type'] );
		$this->assertSame( 'ac_verified', $sent['code'] );
		$this->assertSame( 'cid_test_1', $sent['client_id'] );
		$this->assertSame( XPay_Connect::callback_url(), $sent['redirect_uri'] );
		$this->assertSame( $started['flow']['verifier'], $sent['code_verifier'] );

		// The keys landed as a MERGE, mode and enabled follow the click.
		$settings = get_option( 'woocommerce_xpay_settings' );
		$this->assertSame( 'rk_test_connected', $settings['test_api_key'] );
		$this->assertSame( 'pk_test_connected', $settings['test_publishable_key'] );
		$this->assertSame( 'test', $settings['mode'] );
		$this->assertSame( 'yes', $settings['enabled'] );

		// Provisioning ran the shared path: proof, caches, webhook.
		$proof = get_option( XPay_Constants::OPTION_KEY_VALIDATED );
		$this->assertSame( 'test', $proof['mode'] );
		$salt                 = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '';
		$expected_fingerprint = substr( hash( 'sha256', $salt . '|rk_test_connected|pk_test_connected' ), 0, 32 );
		$this->assertSame( $expected_fingerprint, $proof['fingerprint'] );
		$this->assertNotSame(
			$proof['fingerprint'],
			XPay_Constants::key_fingerprint( 'rk_test_other', 'pk_test_other' )
		);
		$this->assertSame( 'whsec_conn_1', $settings['test_webhook_secret'] );
		$this->assertSame( 'acct_1', get_option( XPay_Constants::merchant_id_option( false ) ) );

		// The outcome reaches the merchant, and the flow cannot replay.
		$this->assertStringContainsString( 'section=xpay', $destination );
		$notices = XPay_Connect::take_notices();
		$texts   = wp_list_pluck( $notices, 'text' );
		$this->assertStringContainsString( 'XPay connected (test mode).', implode( ' ', $texts ) );

		$client = get_option( XPay_Constants::OPTION_CONNECT_CLIENT );
		$this->assertGreaterThan( 0, $client['completed_at'], 'A completed connect must stop the staleness rule from re-registering this client.' );

		$this->arrive(
			array(
				'code'  => 'ac_verified',
				'state' => $started['flow']['state'],
			)
		);
		$this->assertCount( 1, $this->requests_to( 'oauth2/token' ), 'A spent flow must never reach the exchange again.' );
	}
}
