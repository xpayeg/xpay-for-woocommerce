<?php
/**
 * What a merchant is told when they save their keys.
 *
 * One GET /account call, asked once, when the keys change. Nothing asks
 * again afterwards: a key revoked at XPay later announces itself where it
 * matters, which is orders that stop completing.
 *
 * The account response states what the old empty-POST probe inferred from
 * status codes: the key's effective permission set (a mis-scoped
 * restricted key is named field by field), the currencies this account
 * can charge in (cached for the availability gate), the merchant id, and
 * live activation as a fact — an unactivated live key answers 200 with
 * livePaymentsEnabled false, never merchant_not_activated.
 *
 * @package XPay_For_WooCommerce
 */

class KeySaveOutcomeTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option(
			'woocommerce_xpay_settings',
			array(
				'enabled' => 'yes',
				'mode'    => 'test',
				'title'   => 'XPay',
			)
		);
		$this->reset_notices();
	}

	public function tear_down(): void {
		$GLOBALS['xpay_test_http'] = array();
		$this->reset_notices();
		parent::tear_down();
	}

	private function reset_notices(): void {
		foreach ( array( 'errors', 'messages' ) as $store ) {
			$property = new ReflectionProperty( 'WC_Admin_Settings', $store );
			$property->setAccessible( true );
			$property->setValue( null, array() );
		}
	}

	/** @return string[] */
	private function notices( string $store ): array {
		$property = new ReflectionProperty( 'WC_Admin_Settings', $store );
		$property->setAccessible( true );
		return (array) $property->getValue();
	}

	private function said( string $store, string $needle ): bool {
		foreach ( $this->notices( $store ) as $notice ) {
			if ( false !== stripos( (string) $notice, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int    $status What XPay answers the check with.
	 * @param string $code   Error code in the body, when there is one.
	 */
	private function xpay_answers( int $status, string $code = 'probe' ): void {
		add_filter(
			'pre_http_request',
			function () use ( $status, $code ) {
				return array(
					'response' => array( 'code' => $status ),
					'body'     => wp_json_encode( array( 'error' => array( 'code' => $code ) ) ),
					'headers'  => array(),
				);
			},
			2
		);
	}

	private function save_keys(): void {
		$_POST = array(
			'woocommerce_xpay_test_api_key'         => 'rk_test_outcome',
			'woocommerce_xpay_test_publishable_key' => 'pk_test_outcome',
		);
		$gateway = new XPay_Gateway();
		$gateway->process_admin_options();
		$_POST = array();
	}

	/** A full account answer, overridable per test. */
	private function account_body( array $overrides = array() ): array {
		// Lists must REPLACE, not merge element-wise: array_replace_recursive
		// would keep the tail of the default permissions under a shorter
		// override, silently granting what the test meant to revoke.
		$body = array_replace_recursive(
			array(
				'id'                  => 'acct_test_outcome',
				'defaultCurrency'     => 'EGP',
				'livemode'            => false,
				'livePaymentsEnabled' => true,
				'supportedCurrencies' => array(
					array(
						'code'               => 'EGP',
						'decimals'           => 2,
						'paymentMethodTypes' => array( 'card', 'fawry' ),
					),
					array(
						'code'               => 'USD',
						'decimals'           => 2,
						'paymentMethodTypes' => array( 'card' ),
					),
				),
				'apiKey'              => array(
					'type'        => 'restricted',
					'mode'        => 'test',
					'permissions' => array( 'CHECKOUT_SESSIONS_WRITE', 'CHECKOUT_SESSIONS_READ', 'REFUNDS_WRITE', 'REFUNDS_READ' ),
				),
			),
			$overrides
		);
		if ( isset( $overrides['apiKey']['permissions'] ) ) {
			$body['apiKey']['permissions'] = $overrides['apiKey']['permissions'];
		}
		if ( isset( $overrides['supportedCurrencies'] ) ) {
			$body['supportedCurrencies'] = $overrides['supportedCurrencies'];
		}
		return $body;
	}

	private function xpay_answers_account( array $overrides = array() ): void {
		$body = $this->account_body( $overrides );
		add_filter(
			'pre_http_request',
			function () use ( $body ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $body ),
					'headers'  => array(),
				);
			},
			2
		);
	}

	/* ── The key works ───────────────────────────────────────────────── */

	public function test_a_working_key_connects_and_caches_the_account(): void {
		$this->xpay_answers_account();

		$this->save_keys();

		$this->assertIsArray( get_option( XPay_Constants::OPTION_KEY_VALIDATED ) );
		$this->assertTrue( $this->said( 'messages', 'XPay connected' ) );
		$this->assertSame(
			'acct_test_outcome',
			get_option( XPay_Constants::merchant_id_option( false ) ),
			'The merchant id comes from the account now, not from session-response scraping.'
		);
		$this->assertSame(
			array(
				'EGP' => array( 'card', 'fawry' ),
				'USD' => array( 'card' ),
			),
			get_option( XPay_Constants::account_methods_option( false ) ),
			'Both availability gates read this cache: the currency gate its keys, the method rows its values.'
		);
	}

	/* ── The key is wrong ────────────────────────────────────────────── */

	public function test_a_refused_key_is_reported_as_a_bad_key(): void {
		$this->xpay_answers( 401, 'invalid_api_key' );

		$this->save_keys();

		$this->assertFalse( get_option( XPay_Constants::OPTION_KEY_VALIDATED ) );
		$this->assertTrue( $this->said( 'errors', 'refused' ) );
	}

	public function test_a_publishable_key_in_the_secret_field_is_named_for_what_it_is(): void {
		// /account requires no permission, so 403 permission_denied can
		// only mean the key TYPE is wrong (its guard refuses pk_ keys).
		$this->xpay_answers( 403, 'permission_denied' );

		$this->save_keys();

		$this->assertFalse( get_option( XPay_Constants::OPTION_KEY_VALIDATED ) );
		$this->assertTrue( $this->said( 'errors', 'publishable' ) );
	}

	/* ── The key is real but mis-scoped ──────────────────────────────── */

	public function test_a_missing_permission_is_named(): void {
		$this->xpay_answers_account(
			array( 'apiKey' => array( 'permissions' => array( 'CHECKOUT_SESSIONS_WRITE', 'CHECKOUT_SESSIONS_READ' ) ) )
		);

		$this->save_keys();

		$this->assertFalse(
			get_option( XPay_Constants::OPTION_KEY_VALIDATED ),
			'A key that cannot refund was given a green badge.'
		);
		$this->assertTrue( $this->said( 'errors', 'Refunds (write)' ), 'The missing permission must be named, not guessed at.' );
		$this->assertFalse( $this->said( 'errors', 'Checkout Sessions (write)' ), 'A permission the key HAS must not be reported missing.' );
	}

	public function test_both_missing_permissions_are_named_together(): void {
		$this->xpay_answers_account( array( 'apiKey' => array( 'permissions' => array( 'PRODUCTS_READ' ) ) ) );

		$this->save_keys();

		$this->assertTrue( $this->said( 'errors', 'Checkout Sessions (write)' ) );
		$this->assertTrue( $this->said( 'errors', 'Refunds (write)' ) );
	}

	/* ── The key is real and the account is simply not live yet ──────── */

	public function test_an_unactivated_live_account_is_connected_and_told_so(): void {
		$this->xpay_answers_account(
			array(
				'livemode'            => true,
				'livePaymentsEnabled' => false,
				'apiKey'              => array( 'mode' => 'live' ),
			)
		);

		$_POST = array(
			'woocommerce_xpay_mode'                 => 'live',
			'woocommerce_xpay_live_api_key'         => 'rk_live_outcome',
			'woocommerce_xpay_live_publishable_key' => 'pk_live_outcome',
		);
		$gateway = new XPay_Gateway();
		$gateway->process_admin_options();
		$_POST = array();

		$this->assertIsArray( get_option( XPay_Constants::OPTION_KEY_VALIDATED ), 'The key is real; the badge may say so.' );
		$this->assertFalse( $this->said( 'errors', 'refused' ), 'A pending activation was reported as a wrong key.' );
		$this->assertTrue( $this->said( 'messages', 'not activated' ), 'The merchant deserves the activation fact, not a fault.' );
	}

	/* ── XPay did not answer ─────────────────────────────────────────── */

	public function test_an_unreachable_xpay_is_not_reported_as_a_bad_key(): void {
		$GLOBALS['xpay_test_http'] = array(); // The wall refuses the call.

		$this->save_keys();

		$saved = get_option( 'woocommerce_xpay_settings' );
		$this->assertSame( 'rk_test_outcome', $saved['test_api_key'], 'The keys were not even saved.' );
		$this->assertFalse( $this->said( 'errors', 'did not validate' ) );
		$this->assertTrue( $this->said( 'messages', 'could not be reached' ) );
	}

	public function test_a_server_error_is_not_reported_as_a_bad_key(): void {
		// A 5xx can come from in front of the API without the key having
		// been read at all.
		$this->xpay_answers( 502, 'api_error' );

		$this->save_keys();

		$this->assertFalse( $this->said( 'errors', 'refused' ) );
		$this->assertTrue( $this->said( 'messages', 'could not be reached' ) );
	}
}
