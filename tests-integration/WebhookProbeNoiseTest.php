<?php
/**
 * An unauthenticated POST must not be able to paint a merchant's screen red.
 *
 * The receiver is public. Only authenticated deliveries may update the
 * merchant-facing webhook health state.
 *
 * @package XPay_For_WooCommerce
 */

class WebhookProbeNoiseTest extends XPay_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		// The state a real store is in: diagnostics off, so the settings
		// screen and the error rows are all the merchant has.
		$this->configure_gateway(
			array(
				'debug' => 'no',
				'mode'  => 'test',
			)
		);
		XPay_Logger::init();
	}

	/**
	 * Drive the recorder the way handle() drives it, with the header the
	 * request carried and the code the verifier threw.
	 *
	 * @param string $header Raw XPay-Signature header value ('' when absent).
	 * @param string $code   Error code from XPay_Signature::verify().
	 */
	private function reject( string $header, string $code ): void {
		$method = new ReflectionMethod( 'XPay_Webhook_Controller', 'record_rejection' );
		$method->setAccessible( true );
		$method->invoke( null, $this->gateway(), $header, $code );
	}

	private function rows( string $level ): array {
		return XPay_Spy_Log_Handler::query(
			array(
				'stage' => 'webhook.rejected',
				'level' => $level,
			)
		);
	}

	private function screen_is_red(): bool {
		return in_array( XPay_Webhook_State::status_code( false ), array( 3, 4 ), true );
	}

	/* ── The scanner ─────────────────────────────────────────────────── */

	public function test_an_unsigned_post_does_not_mark_the_webhook_failing(): void {
		$this->reject( '', XPay_Error_Codes::WEBHOOK_SIGNATURE_MISSING );

		$this->assertFalse(
			$this->screen_is_red(),
			'A request with no signature header at all told the merchant their webhook is broken.'
		);
		$this->assertSame(
			array(),
			$this->rows( XPay_Logger::LEVEL_ERROR ),
			'An unauthenticated POST wrote an error row on a store with logging off.'
		);
	}

	public function test_an_unsigned_post_is_a_probe_even_before_a_secret_is_saved(): void {
		// verify() checks the secret before the header, so an unconfigured
		// store answers a probe with a different code. It is still a probe.
		$this->reject( '', XPay_Error_Codes::WEBHOOK_NOT_CONFIGURED );

		$this->assertFalse( $this->screen_is_red() );
		$this->assertSame( array(), $this->rows( XPay_Logger::LEVEL_ERROR ) );
	}

	public function test_the_probe_is_still_visible_once_diagnostics_are_on(): void {
		$this->configure_gateway( array( 'debug' => 'yes' ) );

		$this->reject( '', XPay_Error_Codes::WEBHOOK_SIGNATURE_MISSING );

		$rows = $this->rows( XPay_Logger::LEVEL_INFO );
		$this->assertCount(
			1,
			$rows,
			'A merchant investigating "nothing is arriving" cannot see the traffic that did arrive.'
		);
		$this->assertStringContainsString( XPay_Error_Codes::WEBHOOK_SIGNATURE_MISSING, $rows[0]['context'] );
	}

	/* ── The misconfiguration the record exists for ──────────────────── */

	public function test_a_wrong_signing_secret_still_marks_the_webhook_failing(): void {
		$this->reject( 't=1700000000,v1=' . str_repeat( 'a', 64 ), XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID );

		$this->assertTrue( $this->screen_is_red(), 'A store whose secret is wrong was left believing it is fine.' );
		$this->assertSame( XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID, XPay_Webhook_State::last_error_code( false ) );
		$this->assertCount( 1, $this->rows( XPay_Logger::LEVEL_ERROR ) );
	}

	public function test_a_signed_delivery_to_a_store_with_no_secret_still_reports(): void {
		$this->reject( 't=1700000000,v1=' . str_repeat( 'b', 64 ), XPay_Error_Codes::WEBHOOK_NOT_CONFIGURED );

		$this->assertTrue( $this->screen_is_red() );
		$this->assertCount( 1, $this->rows( XPay_Logger::LEVEL_ERROR ) );
	}

	public function test_a_skewed_clock_still_reports(): void {
		$this->reject( 't=1,v1=' . str_repeat( 'c', 64 ), XPay_Error_Codes::WEBHOOK_TIMESTAMP_TOLERANCE );

		$this->assertTrue( $this->screen_is_red() );
		$this->assertCount( 1, $this->rows( XPay_Logger::LEVEL_ERROR ) );
	}
}
