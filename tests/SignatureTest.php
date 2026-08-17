<?php
/**
 * Webhook-signature invariants for XPay_Signature.
 *
 * The signature check is the ONLY authentication on the public webhook
 * endpoint — every branch that is not a proven-valid signature must throw.
 * Pinned scenarios come from real failure classes:
 *   - unverified-accept downgrade (the v2 receiver explicitly rejects
 *     rather than downgrades when a secret is configured; this suite makes
 *     that fail-closed contract executable), and
 *   - replay: XPay signs but does NOT enforce timestamp tolerance
 *     server-side — its docs put the 300s window on the receiver, so a
 *     missing tolerance check here would make replayed captures valid
 *     forever.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase {

	private const SECRET = 'whsec_test_0123456789abcdef';
	private const BODY   = '{"id":"evt_1","type":"checkout.session.completed","data":{"object":{"id":"cs_test_1"}}}';
	private const NOW    = 1700000000;

	private function sign( string $body, int $timestamp, string $secret = self::SECRET ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	public function test_valid_signature_passes(): void {
		$header = 't=' . self::NOW . ',v1=' . $this->sign( self::BODY, self::NOW );
		XPay_Signature::verify( $header, self::BODY, self::SECRET, 300, self::NOW );
		$this->addToAssertionCount( 1 ); // No exception = verified.
	}

	public function test_second_v1_candidate_is_accepted(): void {
		// Stripe-style headers may carry multiple v1 entries (secret
		// rotation overlap). Any one valid signature must pass.
		$header = 't=' . self::NOW . ',v1=' . str_repeat( 'a', 64 ) . ',v1=' . $this->sign( self::BODY, self::NOW );
		XPay_Signature::verify( $header, self::BODY, self::SECRET, 300, self::NOW );
		$this->addToAssertionCount( 1 );
	}

	public function test_tampered_body_is_rejected(): void {
		$header = 't=' . self::NOW . ',v1=' . $this->sign( self::BODY, self::NOW );
		try {
			XPay_Signature::verify( $header, self::BODY . ' ', self::SECRET, 300, self::NOW );
			$this->fail( 'Tampered body must not verify' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID, $e->get_error_code() );
		}
	}

	public function test_wrong_secret_is_rejected(): void {
		$header = 't=' . self::NOW . ',v1=' . $this->sign( self::BODY, self::NOW, 'whsec_other' );
		try {
			XPay_Signature::verify( $header, self::BODY, self::SECRET, 300, self::NOW );
			$this->fail( 'Wrong secret must not verify' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID, $e->get_error_code() );
		}
	}

	/** @return array<string, array{int}> */
	public function stale_timestamps(): array {
		return array(
			'past beyond tolerance'   => array( self::NOW - 301 ),
			'future beyond tolerance' => array( self::NOW + 301 ),
		);
	}

	/** @dataProvider stale_timestamps */
	public function test_replayed_timestamp_is_rejected( int $timestamp ): void {
		$header = 't=' . $timestamp . ',v1=' . $this->sign( self::BODY, $timestamp );
		try {
			XPay_Signature::verify( $header, self::BODY, self::SECRET, 300, self::NOW );
			$this->fail( 'Stale timestamp must not verify even with a valid HMAC' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_TIMESTAMP_TOLERANCE, $e->get_error_code() );
		}
	}

	public function test_boundary_timestamp_still_passes(): void {
		$timestamp = self::NOW - 300; // Exactly at tolerance: valid.
		$header    = 't=' . $timestamp . ',v1=' . $this->sign( self::BODY, $timestamp );
		XPay_Signature::verify( $header, self::BODY, self::SECRET, 300, self::NOW );
		$this->addToAssertionCount( 1 );
	}

	/** @return array<string, array{string, string}> */
	public function rejected_headers(): array {
		return array(
			'missing header'   => array( '', XPay_Error_Codes::WEBHOOK_SIGNATURE_MISSING ),
			'whitespace only'  => array( '   ', XPay_Error_Codes::WEBHOOK_SIGNATURE_MISSING ),
			'no v1 entry'      => array( 't=1700000000', XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID ),
			'no timestamp'     => array( 'v1=deadbeef', XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID ),
			'non-numeric t'    => array( 't=abc,v1=deadbeef', XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID ),
			'garbage'          => array( 'not-a-header', XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID ),
		);
	}

	/** @dataProvider rejected_headers */
	public function test_malformed_headers_are_rejected( string $header, string $expected_code ): void {
		try {
			XPay_Signature::verify( $header, self::BODY, self::SECRET, 300, self::NOW );
			$this->fail( 'Malformed header must not verify' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( $expected_code, $e->get_error_code() );
		}
	}

	public function test_missing_secret_is_a_configuration_fault_not_a_signature_fault(): void {
		// Distinct code on purpose: the webhook controller answers 500 (our
		// config fault, keep retrying) for this one, 401 for the others.
		$header = 't=' . self::NOW . ',v1=' . $this->sign( self::BODY, self::NOW );
		try {
			XPay_Signature::verify( $header, self::BODY, '', 300, self::NOW );
			$this->fail( 'Empty secret must never verify anything' );
		} catch ( XPay_Api_Exception $e ) {
			$this->assertSame( XPay_Error_Codes::WEBHOOK_NOT_CONFIGURED, $e->get_error_code() );
		}
	}
}
