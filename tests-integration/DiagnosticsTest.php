<?php
/**
 * Failures have to be visible on a store that never turned anything on.
 *
 * Both fixes pinned here are about the same thing: a merchant whose
 * payments have stopped must be able to find out why. Before this, the
 * logger only attached when debug logging was enabled — so the record of a
 * failed payment did not exist unless someone had predicted the failure —
 * and the webhook health row had no code path that could report a problem.
 *
 * @package XPayEG_For_WooCommerce
 */

class DiagnosticsTest extends XPayEG_Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		// The state under test: a normal store, diagnostics off.
		$this->configure_gateway(
			array(
				'debug' => 'no',
				'mode'  => 'test',
			)
		);
		XPayEG_Logger::init();
	}

	private function rows( array $args = array() ): array {
		return XPayEG_Spy_Log_Handler::query( $args );
	}

	public function test_routine_tracing_stays_off_when_logging_is_off(): void {
		XPayEG_Logger::event( 'test.routine', array( 'k' => 'v' ) );

		$this->assertSame( array(), $this->rows( array( 'stage' => 'test.routine' ) ) );
	}

	public function test_errors_are_recorded_even_when_logging_is_off(): void {
		XPayEG_Logger::error( 'test.failure', array( 'order_id' => 41 ), 'it broke' );

		$rows = $this->rows( array( 'stage' => 'test.failure' ) );
		$this->assertCount( 1, $rows, 'A failure was dropped on a store with logging off.' );
		$this->assertSame( 'error', $rows[0]['level'] );
		$this->assertSame( 'it broke', $rows[0]['message'] );
	}

	public function test_criticals_are_recorded_even_when_logging_is_off(): void {
		XPayEG_Logger::critical( 'test.money', array( 'order_id' => 42 ) );

		$rows = $this->rows( array( 'stage' => 'test.money' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'critical', $rows[0]['level'] );
	}

	public function test_routine_tracing_is_recorded_once_logging_is_on(): void {
		$this->configure_gateway( array( 'debug' => 'yes' ) );

		XPayEG_Logger::event( 'test.chatty' );

		$this->assertCount( 1, $this->rows( array( 'stage' => 'test.chatty' ) ) );
	}

	public function test_the_log_can_be_filtered_down_to_failures(): void {
		$this->configure_gateway( array( 'debug' => 'yes' ) );

		XPayEG_Logger::event( 'test.noise.one' );
		XPayEG_Logger::event( 'test.noise.two' );
		XPayEG_Logger::error( 'test.signal' );

		$errors = $this->rows( array( 'level' => 'error' ) );
		$stages = wp_list_pluck( $errors, 'stage' );
		$this->assertContains( 'test.signal', $stages );
		$this->assertNotContains( 'test.noise.one', $stages );
	}

	/**
	 * A7: a refused delivery has to leave a mark the settings screen can read.
	 */
	public function test_a_refused_delivery_is_recorded_against_the_configured_plane(): void {
		$gateway = $this->gateway();

		$method = new ReflectionMethod( 'XPayEG_Webhook_Controller', 'record_failure' );
		$method->setAccessible( true );
		$method->invoke( null, $gateway, XPayEG_Error_Codes::WEBHOOK_SIGNATURE_INVALID );

		$this->assertSame( XPayEG_Error_Codes::WEBHOOK_SIGNATURE_INVALID, XPayEG_Webhook_State::last_error_code( false ) );
		$this->assertNotSame( '', XPayEG_Webhook_State::reason_sentence( XPayEG_Webhook_State::last_error_code( false ) ), 'The rejection was recorded without a reason a merchant can read.' );
		$this->assertGreaterThan( 0, XPayEG_Webhook_State::last_failure_at( false ) );
		$this->assertSame( 4, XPayEG_Webhook_State::status_code( false ), 'A failure with no success ever is the never-worked verdict.' );

		// The other plane must stay clean: test and live are separate
		// endpoints with separate secrets.
		$this->assertSame( 0, XPayEG_Webhook_State::last_failure_at( true ) );
		$this->assertSame( 2, XPayEG_Webhook_State::status_code( true ) );
	}

	public function test_every_rejection_code_produces_a_distinct_sentence(): void {
		$codes = array(
			XPayEG_Error_Codes::WEBHOOK_NOT_CONFIGURED,
			XPayEG_Error_Codes::WEBHOOK_SIGNATURE_INVALID,
			XPayEG_Error_Codes::WEBHOOK_SIGNATURE_MISSING,
			XPayEG_Error_Codes::WEBHOOK_TIMESTAMP_TOLERANCE,
			XPayEG_Error_Codes::WEBHOOK_PAYLOAD_MALFORMED,
		);

		$sentences = array();
		foreach ( $codes as $code ) {
			$sentence = XPayEG_Webhook_State::reason_sentence( $code );
			$this->assertNotSame( '', $sentence );
			$sentences[] = $sentence;
		}

		$this->assertSame(
			count( $sentences ),
			count( array_unique( $sentences ) ),
			'Two rejection causes share one sentence, so the merchant cannot tell them apart.'
		);
	}

	public function test_an_unrecognised_code_still_gets_a_sentence(): void {
		$this->assertNotSame( '', XPayEG_Webhook_State::reason_sentence( 'something_new_from_a_later_version' ) );
	}

	/**
	 * A failure a later success has outranked is history, not a warning:
	 * the screen's failing verdict is the state's timestamp order.
	 */
	public function test_a_fixed_secret_turns_the_row_green_on_its_own(): void {
		XPayEG_Webhook_State::record_failure( false, XPayEG_Error_Codes::WEBHOOK_SIGNATURE_INVALID );
		$this->assertSame( 4, XPayEG_Webhook_State::status_code( false ) );

		sleep( 1 ); // The verdict is timestamp ORDER; equal seconds is a tie.
		XPayEG_Webhook_State::record_success( false );
		$this->assertSame( 1, XPayEG_Webhook_State::status_code( false ), 'A delivery got through, so whatever was wrong is no longer wrong.' );
	}
}
