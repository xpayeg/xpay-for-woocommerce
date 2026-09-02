<?php
/**
 * The webhook sets itself up at key save, against real WordPress.
 *
 * The lifecycle under test is Stripe's, per plane and independently:
 * create at key save and store the secret with the endpoint's record
 * (including the key that created it), delete leftovers aimed at this
 * store's URL, retire the endpoint with the OLD key when the key changes,
 * and silently re-create when a plugin update changes the event list.
 * Every path that cannot configure leaves the save intact and the manual
 * secret field as the working fallback.
 *
 * @package XPay_For_WooCommerce
 */

class WebhookConfiguratorTest extends XPay_Integration_Test_Case {

	/** @var array[] Every API request the flow made: {method, url, headers, body}. */
	private $requests = array();

	/** @var array Scripted answers keyed by "METHOD path-substring". */
	private $answers = array();

	/** @var callable */
	private $dispatcher;

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

		$this->requests   = array();
		$this->answers    = array();
		$this->dispatcher = function ( $preempt, $args, $url ) {
			unset( $preempt );
			$method           = isset( $args['method'] ) ? (string) $args['method'] : 'GET';
			$this->requests[] = array(
				'method'  => $method,
				'url'     => (string) $url,
				'headers' => isset( $args['headers'] ) ? (array) $args['headers'] : array(),
				'body'    => isset( $args['body'] ) ? (string) $args['body'] : '',
			);
			foreach ( $this->answers as $key => $answer ) {
				list( $want_method, $needle ) = explode( ' ', $key, 2 );
				if ( $method === $want_method && false !== strpos( (string) $url, $needle ) ) {
					return array(
						'response' => array( 'code' => $answer['status'] ),
						'body'     => wp_json_encode( $answer['body'] ),
						'headers'  => array(),
					);
				}
			}
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => wp_json_encode( array( 'error' => array( 'code' => 'resource_missing' ) ) ),
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->dispatcher, 5, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->dispatcher, 5 );
		foreach ( array( 'errors', 'messages' ) as $store ) {
			$property = new ReflectionProperty( 'WC_Admin_Settings', $store );
			$property->setAccessible( true );
			$property->setValue( null, array() );
		}
		parent::tear_down();
	}

	private function answer( string $method, string $needle, int $status, array $body ): void {
		$this->answers[ $method . ' ' . $needle ] = array(
			'status' => $status,
			'body'   => $body,
		);
	}

	/** The account body for a key that CAN manage webhooks. */
	private function account( array $permissions = array( 'CHECKOUT_SESSIONS_WRITE', 'REFUNDS_WRITE', 'WEBHOOK_ENDPOINTS_WRITE' ) ): array {
		return array(
			'id'                  => 'acct_wh',
			'livemode'            => false,
			'livePaymentsEnabled' => true,
			'supportedCurrencies' => array(
				array(
					'code'               => 'EGP',
					'decimals'           => 2,
					'paymentMethodTypes' => array( 'card' ),
				),
			),
			'apiKey'              => array(
				'type'        => 'restricted',
				'mode'        => 'test',
				'permissions' => $permissions,
			),
		);
	}

	private function endpoint_body( string $id, string $url, array $extra = array() ): array {
		return array_merge(
			array(
				'id'            => $id,
				'object'        => 'webhook_endpoint',
				'url'           => $url,
				'status'        => 'enabled',
				'enabledEvents' => XPay_Event_Names::SUBSCRIBED,
				'livemode'      => false,
			),
			$extra
		);
	}

	private function save_keys( string $secret = 'rk_test_wh1' ): void {
		$_POST   = array(
			'woocommerce_xpay_test_api_key'         => $secret,
			'woocommerce_xpay_test_publishable_key' => 'pk_test_wh',
		);
		$gateway = new XPay_Gateway();
		$gateway->process_admin_options();
		$_POST = array();
	}

	/** Requests to a path, in order. */
	private function sent( string $method, string $needle ): array {
		$matches = array();
		foreach ( $this->requests as $request ) {
			if ( $request['method'] === $method && false !== strpos( $request['url'], $needle ) ) {
				$matches[] = $request;
			}
		}
		return $matches;
	}

	public function test_a_key_save_creates_the_endpoint_and_stores_the_secret(): void {
		$this->answer( 'GET', '/account', 200, $this->account() );
		$this->answer( 'POST', '/webhook-endpoints', 201, $this->endpoint_body( 'we_1', XPay_Webhook_Configurator::webhook_url(), array( 'secret' => 'whsec_auto_1' ) ) );
		$this->answer( 'GET', '/webhook-endpoints', 200, array( 'object' => 'list', 'data' => array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->save_keys();

		$this->assertCount( 1, $this->sent( 'POST', '/webhook-endpoints' ), 'One save, one endpoint.' );

		$settings = get_option( 'woocommerce_xpay_settings' );
		$this->assertSame( 'whsec_auto_1', $settings['test_webhook_secret'], 'The signing secret must be stored where the receiver reads it, with nothing to paste.' );
		$this->assertSame( 'we_1', $settings['test_webhook_data']['id'] );
		$this->assertSame( 'rk_test_wh1', $settings['test_webhook_data']['secret'], 'The CREATING key is the record: decommissioning later must authenticate as the account that owns the endpoint.' );

		$body = json_decode( $this->sent( 'POST', '/webhook-endpoints' )[0]['body'], true );
		$this->assertSame( XPay_Webhook_Configurator::webhook_url(), $body['url'] );
		$this->assertSame( XPay_Event_Names::SUBSCRIBED, $body['enabledEvents'], 'Every subscribed event, exactly.' );
	}

	public function test_a_reinstall_deletes_the_endpoint_the_old_install_left_behind(): void {
		$ours    = XPay_Webhook_Configurator::webhook_url();
		$foreign = 'https://someone-elses-store.test/?wc-api=xpay_webhook';
		$this->answer( 'GET', '/account', 200, $this->account() );
		$this->answer( 'POST', '/webhook-endpoints', 201, $this->endpoint_body( 'we_new', $ours, array( 'secret' => 'whsec_new' ) ) );
		$this->answer(
			'GET',
			'/webhook-endpoints',
			200,
			array(
				'object' => 'list',
				'data'   => array(
					$this->endpoint_body( 'we_new', $ours ),
					$this->endpoint_body( 'we_stale', $ours ),
					$this->endpoint_body( 'we_foreign', $foreign ),
				),
			)
		);
		$this->answer( 'DELETE', '/webhook-endpoints/we_stale', 204, array() );

		$this->save_keys();

		$deletes = $this->sent( 'DELETE', '/webhook-endpoints' );
		$this->assertCount( 1, $deletes, 'Exactly the stale twin dies: never the endpoint just created, never another store\'s.' );
		$this->assertStringContainsString( 'we_stale', $deletes[0]['url'] );
	}

	public function test_a_key_change_retires_the_old_endpoint_with_the_old_key(): void {
		// A store that already configured under the OLD key.
		XPay_Webhook_Configurator::merge_settings(
			array(
				'test_webhook_secret' => 'whsec_old',
				'test_webhook_data'   => array(
					'id'     => 'we_old',
					'url'    => XPay_Webhook_Configurator::webhook_url(),
					'secret' => 'rk_test_OLD',
				),
			)
		);
		$this->answer( 'GET', '/account', 200, $this->account() );
		$this->answer( 'DELETE', '/webhook-endpoints/we_old', 204, array() );
		$this->answer( 'POST', '/webhook-endpoints', 201, $this->endpoint_body( 'we_new', XPay_Webhook_Configurator::webhook_url(), array( 'secret' => 'whsec_new' ) ) );
		$this->answer( 'GET', '/webhook-endpoints', 200, array( 'object' => 'list', 'data' => array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->save_keys( 'rk_test_NEW' );

		$deletes = $this->sent( 'DELETE', '/webhook-endpoints/we_old' );
		$this->assertCount( 1, $deletes );
		$this->assertSame(
			'Bearer rk_test_OLD',
			$deletes[0]['headers']['Authorization'],
			'The new key may belong to a different account entirely; only the creating key can retire the endpoint.'
		);

		$settings = get_option( 'woocommerce_xpay_settings' );
		$this->assertSame( 'whsec_new', $settings['test_webhook_secret'] );
		$this->assertSame( 'we_new', $settings['test_webhook_data']['id'] );
	}

	public function test_saving_the_same_key_again_leaves_the_endpoint_alone(): void {
		update_option(
			XPay_Constants::OPTION_KEY_VALIDATED,
			array(
				'mode'         => 'test',
				'validated_at' => time(),
				'fingerprint'  => XPay_Constants::key_fingerprint( 'rk_test_wh1', 'pk_test_wh' ),
			),
			false
		);
		XPay_Webhook_Configurator::merge_settings(
			array(
				'test_webhook_secret' => 'whsec_standing',
				'test_webhook_data'   => array(
					'id'     => 'we_standing',
					'url'    => XPay_Webhook_Configurator::webhook_url(),
					'secret' => 'rk_test_wh1',
				),
			)
		);
		$this->answer( 'GET', '/account', 200, $this->account() );

		$this->save_keys( 'rk_test_wh1' );

		$this->assertCount( 0, $this->sent( 'POST', '/webhook-endpoints' ), 'This key\'s endpoint already stands; a save that changes nothing creates nothing.' );
		$this->assertCount( 0, $this->sent( 'DELETE', '/webhook-endpoints' ) );
		$settings = get_option( 'woocommerce_xpay_settings' );
		$this->assertSame( 'whsec_standing', $settings['test_webhook_secret'] );
	}

	public function test_a_key_without_the_permission_falls_back_to_manual_setup(): void {
		$this->answer( 'GET', '/account', 200, $this->account( array( 'CHECKOUT_SESSIONS_WRITE', 'REFUNDS_WRITE' ) ) );

		$this->save_keys();

		$this->assertCount( 0, $this->sent( 'POST', '/webhook-endpoints' ) );
		$this->assertIsArray( get_option( XPay_Constants::OPTION_KEY_VALIDATED ), 'The keys still validate; only the webhook automation is unavailable.' );

		$property = new ReflectionProperty( 'WC_Admin_Settings', 'messages' );
		$property->setAccessible( true );
		$this->assertTrue(
			(bool) array_filter( (array) $property->getValue(), fn( $m ) => false !== stripos( (string) $m, 'Webhook Endpoints (write)' ) ),
			'The missing permission is NAMED, so the merchant edits the right checkbox instead of guessing.'
		);
	}

	public function test_a_failed_create_never_fails_the_save(): void {
		$this->answer( 'GET', '/account', 200, $this->account() );
		$this->answer( 'POST', '/webhook-endpoints', 500, array( 'error' => array( 'code' => 'internal' ) ) );

		$this->save_keys();

		$this->assertIsArray( get_option( XPay_Constants::OPTION_KEY_VALIDATED ), 'A webhook hiccup must never cost the merchant their validated keys.' );
		$settings = get_option( 'woocommerce_xpay_settings' );
		$this->assertArrayNotHasKey( 'id', (array) ( $settings['test_webhook_data'] ?? array() ) );
	}

	public function test_a_plugin_update_reconfigures_only_when_the_event_list_changed(): void {
		XPay_Webhook_Configurator::merge_settings(
			array(
				'test_api_key'        => 'rk_test_wh1',
				'test_webhook_secret' => 'whsec_v1',
				'test_webhook_data'   => array(
					'id'     => 'we_v1',
					'url'    => XPay_Webhook_Configurator::webhook_url(),
					'secret' => 'rk_test_wh1',
				),
			)
		);

		// Platform copy matches the plugin's list: nothing to do.
		$this->answer(
			'GET',
			'/webhook-endpoints',
			200,
			array(
				'object' => 'list',
				'data'   => array( $this->endpoint_body( 'we_v1', XPay_Webhook_Configurator::webhook_url() ) ),
			)
		);
		XPay_Webhook_Configurator::maybe_reconfigure_on_update();
		$this->assertCount( 0, $this->sent( 'POST', '/webhook-endpoints' ) );

		// Platform copy is missing an event (an older plugin created it):
		// silently re-create.
		$stale = $this->endpoint_body( 'we_v1', XPay_Webhook_Configurator::webhook_url() );
		$stale['enabledEvents'] = array( XPay_Event_Names::CHECKOUT_SESSION_COMPLETED );
		$this->answer(
			'GET',
			'/webhook-endpoints',
			200,
			array(
				'object' => 'list',
				'data'   => array( $stale ),
			)
		);
		$this->answer( 'POST', '/webhook-endpoints', 201, $this->endpoint_body( 'we_replacement', XPay_Webhook_Configurator::webhook_url(), array( 'secret' => 'whsec_replacement' ) ) );
		$this->answer( 'DELETE', '/webhook-endpoints/we_v1', 204, array() );

		XPay_Webhook_Configurator::maybe_reconfigure_on_update();

		$this->assertCount( 1, $this->sent( 'POST', '/webhook-endpoints' ) );
		$this->assertSame( 'whsec_replacement', get_option( 'woocommerce_xpay_settings' )['test_webhook_secret'] );
	}
}
