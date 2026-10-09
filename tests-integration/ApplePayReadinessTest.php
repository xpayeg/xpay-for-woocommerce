<?php
/**
 * Apple Pay only reaches the checkout once its website setup is complete.
 *
 * Two facts gate it: the merchant's XPay dashboard reports the website
 * setup as complete (cached from GET /account), and Apple's domain
 * verification file is reachable at this store's own address. Until both
 * hold, Apple Pay is never offered, even when the account is eligible and
 * the merchant checked it.
 *
 * @package XPayEG_For_WooCommerce
 */

class ApplePayReadinessTest extends XPayEG_Integration_Test_Case {

	/** Shape of Apple's file: wrapped base64, with a trailing newline. */
	const FILE = "MIAGCSqGSIb3DQEHAqCAMIACAQExDzANBglghkgBZQMEAgEFADCABgkqhkiG9w0BBwGggCSABIIB\nQnsidGVhbUlkIjoiQUJDREVGIiwiZG9tYWluIjoic2hvcC5leGFtcGxlIn0AAAAAAAAAAAAAAAA=\n";

	/** A valid file issued for another Merchant ID. */
	const OTHER_FILE = "MIAGCSqGSIb3DQEHAqCAMIACAQExDzANBglghkgBZQMEAgEFADCABgkqhkiG9w0BBwGggCSABIIB\nQnsidGVhbUlkIjoiWllYV1ZVIiwiZG9tYWluIjoib3RoZXIuZXhhbXBsZSJ9AAAAAAAAAAAAAAA=\n";

	/**
	 * Configure an EGP test account eligible for card and Apple Pay payments.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->configure_gateway(
			array(
				'enabled'              => 'yes',
				'mode'                 => 'test',
				'test_api_key'         => 'rk_test_apple',
				'test_publishable_key' => 'pk_test_apple',
			)
		);
		update_option( 'woocommerce_currency', 'EGP' );
		update_option( XPayEG_Constants::account_methods_option( false ), array( 'EGP' => array( 'card', 'apple_pay' ) ) );
	}

	/**
	 * Clear wallet readiness, uploaded files and request fixtures between tests.
	 */
	public function tear_down(): void {
		foreach ( array(
			XPayEG_Constants::account_methods_option( false ),
			XPayEG_Constants::account_wallets_option( false ),
			XPayEG_Constants::OPTION_ENABLED_METHODS,
			XPayEG_Apple_Pay_Domain::OPTION_FILE,
			XPayEG_Apple_Pay_Domain::OPTION_CHECK,
		) as $option ) {
			delete_option( $option );
		}
		$GLOBALS['xpayeg_test_http'] = array();
		$_POST                       = array();
		$_FILES                      = array();
		parent::tear_down();
	}

	/**
	 * Set the cached dashboard readiness for Apple Pay in test mode.
	 *
	 * @param bool $ready Whether the dashboard reports website setup as complete.
	 */
	private function dashboard_ready( bool $ready ): void {
		update_option( XPayEG_Constants::account_wallets_option( false ), array( 'apple_pay' => $ready ) );
	}

	/**
	 * Seed a domain check without making an HTTP request.
	 *
	 * @param bool   $found Whether the domain file was reachable.
	 * @param string $host  Checked host; an empty string uses the current store.
	 */
	private function domain_found( bool $found, string $host = '' ): void {
		update_option(
			XPayEG_Apple_Pay_Domain::OPTION_CHECK,
			array(
				'host'  => '' !== $host ? $host : XPayEG_Apple_Pay_Domain::host(),
				'found' => $found,
				'at'    => time(),
			)
		);
	}

	/**
	 * Read the methods checkout can accept after applying both readiness gates.
	 *
	 * @return string[] Method types offered for EGP.
	 */
	private function offered(): array {
		return $this->gateway()->accepted_types_for_currency( 'EGP' );
	}

	/* ── The gate ─────────────────────────────────────────────────────── */

	/**
	 * Require merchant opt-in even when account and website setup are complete.
	 */
	public function test_an_eligible_account_never_switches_apple_pay_on_by_default(): void {
		$this->dashboard_ready( true );
		$this->domain_found( true );

		$this->assertSame( array( 'card' ), $this->offered(), 'With no stored list, every method but the wallet is offered.' );
	}

	/**
	 * Keep an opted-in wallet unavailable until dashboard setup is complete.
	 */
	public function test_checked_apple_pay_waits_for_the_dashboard_setup(): void {
		update_option( XPayEG_Constants::OPTION_ENABLED_METHODS, array( 'card', 'apple_pay' ) );
		$this->dashboard_ready( false );
		$this->domain_found( true );

		$this->assertSame( array( 'card' ), $this->offered() );
		$this->assertFalse( ( new XPayEG_Method_Gateway( 'apple_pay' ) )->is_available() );
	}

	/**
	 * Keep an opted-in wallet hidden when only dashboard setup is complete.
	 */
	public function test_checked_apple_pay_waits_for_the_domain_file(): void {
		update_option( XPayEG_Constants::OPTION_ENABLED_METHODS, array( 'card', 'apple_pay' ) );
		$this->dashboard_ready( true );
		$this->domain_found( false );

		$this->assertSame( array( 'card' ), $this->offered() );
	}

	/**
	 * Require a new domain check after the store moves to another host.
	 */
	public function test_a_check_made_for_another_host_does_not_count(): void {
		update_option( XPayEG_Constants::OPTION_ENABLED_METHODS, array( 'card', 'apple_pay' ) );
		$this->dashboard_ready( true );
		$this->domain_found( true, 'old-store.example' );

		$this->assertSame( array( 'card' ), $this->offered(), 'A moved store must verify its new domain.' );
	}

	/**
	 * Offer an opted-in wallet in EGP once both readiness gates pass.
	 */
	public function test_apple_pay_is_offered_once_both_steps_are_complete(): void {
		update_option( XPayEG_Constants::OPTION_ENABLED_METHODS, array( 'card', 'apple_pay' ) );
		$this->dashboard_ready( true );
		$this->domain_found( true );

		$this->assertSame( array( 'card', 'apple_pay' ), $this->offered() );
		$this->assertTrue( ( new XPayEG_Method_Gateway( 'apple_pay' ) )->is_available() );
	}

	/* ── The domain file ──────────────────────────────────────────────── */

	/**
	 * Check the HTTPS .txt address and cache a matching uploaded file as found.
	 */
	public function test_the_file_is_found_when_this_store_serves_it(): void {
		update_option( XPayEG_Apple_Pay_Domain::OPTION_FILE, self::FILE );
		$GLOBALS['xpayeg_test_http'] = array(
			'/.well-known/apple-developer-merchantid-domain-association' => array(
				'response' => array( 'code' => 200 ),
				'body'     => self::FILE . "\n",
			),
		);

		$before = count( $GLOBALS['xpayeg_test_http_requests'] ?? array() );
		$this->assertTrue( XPayEG_Apple_Pay_Domain::check() );
		$this->assertTrue( XPayEG_Apple_Pay_Domain::found() );

		$requests = array_slice( $GLOBALS['xpayeg_test_http_requests'], $before );
		$this->assertSame(
			array( 'https://' . XPayEG_Apple_Pay_Domain::host() . '/.well-known/apple-developer-merchantid-domain-association.txt' ),
			array_column( $requests, 'url' ),
			'Readiness is judged at the .txt address Apple verifies for portal registrations, and only there.'
		);
	}

	/**
	 * Preserve the uploaded bytes, including the newline Apple may compare.
	 */
	public function test_the_uploaded_file_is_kept_byte_for_byte(): void {
		update_option( XPayEG_Apple_Pay_Domain::OPTION_FILE, self::FILE );

		$this->assertSame( self::FILE, XPayEG_Apple_Pay_Domain::file(), 'Apple compares the served file with its own copy, trailing newline included.' );
	}

	/**
	 * Reject an HTML error page even when the server returns HTTP 200.
	 */
	public function test_a_themed_404_page_is_not_the_file(): void {
		$GLOBALS['xpayeg_test_http'] = array(
			'/.well-known/' => array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html><body>Page not found</body></html>',
			),
		);

		$this->assertFalse( XPayEG_Apple_Pay_Domain::check() );
		$this->assertFalse( XPayEG_Apple_Pay_Domain::found() );
	}

	/**
	 * Reject short status messages that cannot be a domain association payload.
	 */
	public function test_plain_text_served_with_status_200_is_not_the_file(): void {
		foreach ( array( 'Not Found', 'OK', str_repeat( 'A', 63 ) ) as $body ) {
			$GLOBALS['xpayeg_test_http'] = array(
				'/.well-known/' => array(
					'response' => array( 'code' => 200 ),
					'body'     => $body,
				),
			);

			$this->assertFalse( XPayEG_Apple_Pay_Domain::check(), $body );
		}
	}

	/**
	 * Accept encoded association payloads and reject empty, short or HTML content.
	 */
	public function test_an_upload_must_have_the_files_shape(): void {
		$this->assertTrue( XPayEG_Apple_Pay_Domain::is_association_text( self::FILE ) );
		$this->assertTrue( XPayEG_Apple_Pay_Domain::is_association_text( str_repeat( '7B22', 32 ) ), 'Older files are hex.' );
		$this->assertTrue( XPayEG_Apple_Pay_Domain::is_association_text( "MIAGCSqGSIb3DQEHAqCAMIACAQEx-_zANBglghkgBZQMEAgEFADCABgkqhkiG9w0BBwGggCSA\nBIIBQnsidGVhbUlkIjoiQUJDREVGIn0_-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\n" ), 'Apple issues the file in URL-safe base64.' );
		$this->assertFalse( XPayEG_Apple_Pay_Domain::is_association_text( 'Not Found' ) );
		$this->assertFalse( XPayEG_Apple_Pay_Domain::is_association_text( '<html>' . self::FILE . '</html>' ) );
		$this->assertFalse( XPayEG_Apple_Pay_Domain::is_association_text( '' ) );
	}

	/**
	 * Reject a served payload that differs from the stored merchant file.
	 */
	public function test_a_different_file_than_the_uploaded_one_is_not_found(): void {
		update_option( XPayEG_Apple_Pay_Domain::OPTION_FILE, self::FILE );
		$GLOBALS['xpayeg_test_http'] = array(
			'/.well-known/' => array(
				'response' => array( 'code' => 200 ),
				'body'     => self::OTHER_FILE,
			),
		);

		$this->assertFalse( XPayEG_Apple_Pay_Domain::check() );
	}

	/**
	 * Allow an externally hosted association file when no uploaded copy exists.
	 */
	public function test_a_file_hosted_another_way_counts_without_an_upload(): void {
		$GLOBALS['xpayeg_test_http'] = array(
			'/.well-known/' => array(
				'response' => array( 'code' => 200 ),
				'body'     => self::FILE,
			),
		);

		$this->assertTrue( XPayEG_Apple_Pay_Domain::check() );
	}

	/**
	 * Recognise the extensionless and .txt paths without accepting other suffixes.
	 */
	public function test_both_of_apples_paths_are_recognised(): void {
		$this->assertTrue( XPayEG_Apple_Pay_Domain::is_path( '/.well-known/apple-developer-merchantid-domain-association' ) );
		$this->assertTrue( XPayEG_Apple_Pay_Domain::is_path( '/.well-known/apple-developer-merchantid-domain-association.txt' ) );
		$this->assertFalse( XPayEG_Apple_Pay_Domain::is_path( '/.well-known/apple-developer-merchantid-domain-association.php' ) );
	}

	/* ── Account facts and the settings save ──────────────────────────── */

	/**
	 * Cache dashboard readiness in test mode and refresh domain reachability.
	 */
	public function test_the_account_response_caches_wallet_setup_per_plane(): void {
		$GLOBALS['xpayeg_test_http'] = array(
			'/.well-known/' => array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
			),
		);
		$this->gateway()->refresh_account_facts(
			array(
				'id'                  => 'acct_1',
				'supportedCurrencies' => array(
					array(
						'code'               => 'EGP',
						'paymentMethodTypes' => array( 'card', 'apple_pay' ),
					),
				),
				'wallets'             => array(
					array(
						'type'         => 'apple_pay',
						'websiteReady' => true,
					),
				),
			)
		);

		$this->assertSame( array( 'apple_pay' => true ), get_option( XPayEG_Constants::account_wallets_option( false ) ) );
		$this->assertTrue( $this->gateway()->wallet_dashboard_ready( 'apple_pay' ) );
		$this->assertFalse( XPayEG_Apple_Pay_Domain::found(), 'The refresh re-checks the domain file.' );
	}

	/**
	 * Clear stale readiness when a later account response omits wallet facts.
	 */
	public function test_an_account_response_without_wallets_clears_their_setup(): void {
		$this->dashboard_ready( true );
		$GLOBALS['xpayeg_test_http'] = array(
			'/.well-known/' => array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
			),
		);
		$this->gateway()->refresh_account_facts(
			array(
				'id'                  => 'acct_1',
				'supportedCurrencies' => array(
					array(
						'code'               => 'EGP',
						'paymentMethodTypes' => array( 'card', 'apple_pay' ),
					),
				),
			)
		);

		$this->assertSame( array(), get_option( XPayEG_Constants::account_wallets_option( false ) ) );
		$this->assertFalse( $this->gateway()->wallet_dashboard_ready( 'apple_pay' ), 'A wallet is never offered on an earlier response\'s word.' );
	}

	/**
	 * Preserve checked state through a hidden input while setup disables the checkbox.
	 */
	public function test_a_checked_wallet_that_cannot_be_switched_yet_still_posts_its_state(): void {
		update_option( XPayEG_Constants::OPTION_ENABLED_METHODS, array( 'card', 'apple_pay' ) );
		update_option(
			XPayEG_Constants::OPTION_KEY_VALIDATED,
			array(
				'mode'         => 'test',
				'validated_at' => time(),
				'fingerprint'  => XPayEG_Constants::key_fingerprint( 'rk_test_apple', 'pk_test_apple' ),
			),
			false
		);
		$this->dashboard_ready( false );

		ob_start();
		XPayEG_Admin_Screen::render( new XPayEG_Gateway() );
		$html = (string) ob_get_clean();
		delete_option( XPayEG_Constants::OPTION_KEY_VALIDATED );

		$this->assertMatchesRegularExpression( '/<input type="checkbox"[^>]*id="xpayeg-method-apple_pay"[^>]*disabled/', $html );
		$this->assertStringContainsString( '<input type="hidden" name="xpayeg_method_enabled[]" value="apple_pay">', $html, 'The disabled checkbox cannot post, so its state rides on a hidden field.' );
		$this->assertStringNotContainsString( '<input type="hidden" name="xpayeg_method_enabled[]" value="card">', $html );
	}

	/**
	 * Retain merchant opt-in when a settings save discovers a newly reachable file.
	 */
	public function test_a_save_keeps_a_checked_wallet_that_becomes_ready_during_the_save(): void {
		update_option( XPayEG_Constants::OPTION_ENABLED_METHODS, array( 'card', 'apple_pay' ) );
		$this->dashboard_ready( true );
		$this->domain_found( false );

		// The domain file goes live during this save, so Apple Pay turns
		// ready before the list is stored. The form rendered it disabled
		// and checked, so it posted through the hidden field.
		$GLOBALS['xpayeg_test_http'] = array(
			'api.xpay.app'  => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'id'      => 'acct_1',
						'wallets' => array(
							array(
								'type'         => 'apple_pay',
								'websiteReady' => true,
							),
						),
					)
				),
			),
			'/.well-known/' => array(
				'response' => array( 'code' => 200 ),
				'body'     => self::FILE,
			),
		);
		$_POST = array(
			'xpayeg_methods_present' => '1',
			'xpayeg_method_enabled'  => array( 'card', 'apple_pay' ),
		);
		( new XPayEG_Gateway() )->process_admin_options();

		$this->assertSame( array( 'card', 'apple_pay' ), get_option( XPayEG_Constants::OPTION_ENABLED_METHODS ) );
	}

	/**
	 * Preserve the previous method list when the submitted list offers no usable method.
	 */
	public function test_a_save_refuses_a_list_whose_only_method_is_an_unready_wallet(): void {
		update_option( XPayEG_Constants::OPTION_ENABLED_METHODS, array( 'card' ) );
		$this->dashboard_ready( false );

		$GLOBALS['xpayeg_test_http'] = array(
			'api.xpay.app'  => array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'id' => 'acct_1' ) ),
			),
			'/.well-known/' => array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
			),
		);
		$_POST = array(
			'xpayeg_methods_present' => '1',
			'xpayeg_method_enabled'  => array( 'apple_pay' ),
		);
		( new XPayEG_Gateway() )->process_admin_options();

		$this->assertSame( array( 'card' ), get_option( XPayEG_Constants::OPTION_ENABLED_METHODS ), 'A list that offers nothing at checkout must be refused.' );
	}
}
