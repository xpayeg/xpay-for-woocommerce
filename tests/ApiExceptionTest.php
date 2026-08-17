<?php
/**
 * XPay_Api_Exception envelope tests.
 *
 * Guards the from_api_response() fallback contract: a code or message the
 * API sent as empty/whitespace must fall back exactly like an absent field,
 * because a blank code silently defeats every error-code comparison in the
 * plugin (checkout-session recovery, refund messages) — the failure would
 * look like "generic error", not like the bug it is.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class ApiExceptionTest extends TestCase {

	public function test_valid_fields_are_preserved(): void {
		$e = XPay_Api_Exception::from_api_response(
			array(
				'code'    => 'resource_missing',
				'message' => 'No such checkout session',
				'doc_url' => 'https://docs.xpay.app/errors/resource_missing',
				'param'   => 'sessionId',
			),
			404
		);
		$this->assertSame( XPay_Error_Codes::API_RESOURCE_MISSING, $e->get_error_code() );
		$this->assertSame( 'No such checkout session', $e->getMessage() );
		$this->assertSame( 404, $e->get_http_status() );
		$this->assertSame( 'https://docs.xpay.app/errors/resource_missing', $e->get_doc_url() );
		$this->assertSame( 'sessionId', $e->get_param() );
	}

	/** @return array<string, array{array}> */
	public function degenerate_bodies(): array {
		return array(
			'absent fields'          => array( array() ),
			'empty strings'          => array(
				array(
					'code'    => '',
					'message' => '',
				),
			),
			'whitespace-only'        => array(
				array(
					'code'    => "  \t",
					'message' => "\n ",
				),
			),
			'non-string field types' => array(
				array(
					'code'    => 123,
					'message' => array( 'nope' ),
				),
			),
		);
	}

	/** @dataProvider degenerate_bodies */
	public function test_degenerate_code_and_message_fall_back( array $body ): void {
		$e = XPay_Api_Exception::from_api_response( $body, 500 );
		$this->assertSame( XPay_Error_Codes::API_ERROR, $e->get_error_code() );
		$this->assertSame( 'XPay API request failed', $e->getMessage() );
	}

	public function test_surrounding_whitespace_is_trimmed_from_kept_values(): void {
		$e = XPay_Api_Exception::from_api_response(
			array(
				'code'    => ' rate_limit ',
				'message' => " Too many requests \n",
			),
			429
		);
		$this->assertSame( XPay_Error_Codes::API_RATE_LIMIT, $e->get_error_code() );
		$this->assertSame( 'Too many requests', $e->getMessage() );
	}
}
