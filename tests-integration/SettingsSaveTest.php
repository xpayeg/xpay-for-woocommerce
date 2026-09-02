<?php
/**
 * Saving a settings screen that only shows part of the settings.
 *
 * WooCommerce writes every declared field from the POST, so fields the
 * screen does not render must be preserved without exposing secrets in HTML.
 *
 * @package XPay_For_WooCommerce
 */

class SettingsSaveTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		update_option(
			'woocommerce_xpay_settings',
			array(
				'enabled'                => 'yes',
				'mode'                   => 'test',
				'title'                  => 'XPay',
				'description'            => 'Pay securely.',
				'test_api_key'           => 'rk_test_existing',
				'test_publishable_key'   => 'pk_test_existing',
				'test_webhook_secret'    => 'whsec_test_existing',
				'live_api_key'           => 'rk_live_existing',
				'live_publishable_key'   => 'pk_live_existing',
				'live_webhook_secret'    => 'whsec_live_existing',
				'debug'                  => 'no',
			)
		);
	}

	/**
	 * @param array $post What the screen submitted.
	 */
	private function save( array $post ): array {
		$_POST   = $post;
		$gateway = new XPay_Gateway();
		// Only the carry-forward is under test; the key validation that
		// follows it needs the network and is covered elsewhere.
		$gateway->process_admin_options();
		$_POST = array();

		$saved = get_option( 'woocommerce_xpay_settings' );
		return is_array( $saved ) ? $saved : array();
	}

	public function test_saving_the_test_plane_does_not_wipe_the_live_keys(): void {
		$saved = $this->save(
			array(
				'woocommerce_xpay_test_api_key'         => 'rk_test_new',
				'woocommerce_xpay_test_publishable_key' => 'pk_test_new',
			)
		);

		$this->assertSame( 'rk_test_new', $saved['test_api_key'] );
		$this->assertSame( 'rk_live_existing', $saved['live_api_key'], 'Saving the test screen erased the live secret key.' );
		$this->assertSame( 'whsec_live_existing', $saved['live_webhook_secret'] );
		$this->assertSame( 'whsec_test_existing', $saved['test_webhook_secret'] );
	}

	public function test_a_field_the_screen_did_show_is_still_saved(): void {
		$saved = $this->save( array( 'woocommerce_xpay_title' => 'Pay with XPay' ) );

		$this->assertSame( 'Pay with XPay', $saved['title'] );
	}

	/**
	 * A field that IS on the page and deliberately emptied must be emptied.
	 * Carrying values forward must not become "keys can never be removed".
	 */
	public function test_a_field_the_merchant_cleared_is_cleared(): void {
		$saved = $this->save(
			array(
				'woocommerce_xpay_test_api_key' => '',
				'woocommerce_xpay_title'        => 'XPay',
			)
		);

		$this->assertSame( '', $saved['test_api_key'], 'A merchant could not remove a key they wanted gone.' );
	}

	/**
	 * Checkboxes are excluded from the carry-forward on purpose: for them an
	 * absent field genuinely means "off".
	 */
	public function test_an_unchecked_box_still_turns_off(): void {
		update_option(
			'woocommerce_xpay_settings',
			array_merge( get_option( 'woocommerce_xpay_settings' ), array( 'debug' => 'yes' ) )
		);

		$saved = $this->save( array( 'woocommerce_xpay_title' => 'XPay' ) );

		$this->assertSame( 'no', $saved['debug'] );
	}

	public function test_a_checked_box_turns_on(): void {
		$saved = $this->save(
			array(
				'woocommerce_xpay_title' => 'XPay',
				'woocommerce_xpay_debug' => '1',
			)
		);

		$this->assertSame( 'yes', $saved['debug'] );
	}

	/**
	 * The instance has to agree with what was written, or the screen that
	 * renders straight after the save shows the blanks it computed from the
	 * POST rather than the values it kept.
	 */
	public function test_the_gateway_reports_the_kept_values_after_saving(): void {
		$_POST   = array( 'woocommerce_xpay_test_api_key' => 'rk_test_new' );
		$gateway = new XPay_Gateway();
		$gateway->process_admin_options();
		$_POST = array();

		$this->assertSame( 'rk_live_existing', $gateway->get_option( 'live_api_key' ) );
	}

	/* ── Keys are proved once, not on every save ─────────────────────── */

	/**
	 * Count the calls that actually leave for XPay.
	 *
	 * @return int
	 */
	private function outbound_calls(): int {
		return isset( $GLOBALS['xpay_test_calls'] ) ? (int) $GLOBALS['xpay_test_calls'] : 0;
	}

	private function count_outbound(): void {
		$GLOBALS['xpay_test_calls'] = 0;
		$GLOBALS['xpay_test_http']  = array(
			'api.xpay.app' => array(
				'response' => array( 'code' => 200 ),
				// Account-shaped: the save now proves keys via GET /account
				// and refuses a key whose permission set is missing.
				'body'     => wp_json_encode(
					array(
						'id'                  => 'acct_settings_save',
						'livePaymentsEnabled' => true,
						'supportedCurrencies' => array( array( 'code' => 'EGP' ) ),
						'apiKey'              => array( 'permissions' => array( 'CHECKOUT_SESSIONS_WRITE', 'REFUNDS_WRITE' ) ),
					)
				),
			),
		);
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( (string) $url, 'api.xpay.app' ) ) {
					++$GLOBALS['xpay_test_calls'];
				}
				return $preempt;
			},
			0,
			3
		);
	}

	/**
	 * @param string $title Something harmless to change.
	 */
	private function save_with( string $title ): void {
		$_POST = array(
			'woocommerce_xpay_enabled'              => '1',
			'woocommerce_xpay_title'                => $title,
			'woocommerce_xpay_mode'                 => 'test',
			'woocommerce_xpay_test_api_key'         => 'rk_test_proved',
			'woocommerce_xpay_test_publishable_key' => 'pk_test_proved',
		);
		$this->gateway()->process_admin_options();
		$_POST = array();
	}

	/**
	 * A save on proved keys must
	 * stay SILENT about them: the badge keeps its original proof, and no
	 * notice second-guesses keys nobody touched. It still reads the
	 * account once — currencies and the merchant id can change at XPay
	 * without the keys moving, and a save is the one deliberate moment to
	 * refresh them ("re-save to pick up a new currency" has to be true).
	 */
	public function test_saving_again_refreshes_facts_without_reproving_keys(): void {
		$this->count_outbound();

		$this->save_with( 'Pay with XPay' );
		$after_first = $this->outbound_calls();
		$proof       = get_option( XPay_Constants::OPTION_KEY_VALIDATED );

		$this->save_with( 'Pay by card' );

		$this->assertSame( 1, $after_first, 'The first save proves the keys.' );
		$this->assertSame( 2, $this->outbound_calls(), 'The second save reads the account once, and only once.' );
		$this->assertSame(
			$proof,
			get_option( XPay_Constants::OPTION_KEY_VALIDATED ),
			'The badge must keep the ORIGINAL proof; a facts refresh is not a re-validation.'
		);
	}

	public function test_changing_a_key_is_proved_again(): void {
		$this->count_outbound();
		$this->save_with( 'Pay with XPay' );

		$_POST = array(
			'woocommerce_xpay_enabled'              => '1',
			'woocommerce_xpay_title'                => 'Pay with XPay',
			'woocommerce_xpay_mode'                 => 'test',
			'woocommerce_xpay_test_api_key'         => 'rk_test_different',
			'woocommerce_xpay_test_publishable_key' => 'pk_test_proved',
		);
		$this->gateway()->process_admin_options();
		$_POST = array();

		$this->assertSame( 2, $this->outbound_calls(), 'A new key must be proved before the badge claims it works.' );
	}

	public function tear_down(): void {
		$GLOBALS['xpay_test_http'] = array();
		parent::tear_down();
	}
}
