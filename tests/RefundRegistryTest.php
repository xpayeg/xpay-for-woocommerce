<?php
/**
 * Refund wire-string registry tests.
 *
 * Pins the UPPERCASE refund enum casing. Origin: the stress-test audit of
 * 2026-08-17 found the refund service sending the reason as lowercase
 * 'requested_by_customer' while the API validates the enum case-sensitively
 * as 'REQUESTED_BY_CUSTOMER' — every WooCommerce refund 400'd before
 * reaching the processor. Refund enums are UPPERCASE on the wire; session
 * enums are lowercase. These assertions make that split undriftable.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class RefundRegistryTest extends TestCase {

	public function test_refund_statuses_are_uppercase_wire_strings(): void {
		$this->assertSame( 'PENDING', XPay_Refund_Status::PENDING );
		$this->assertSame( 'REQUIRES_ACTION', XPay_Refund_Status::REQUIRES_ACTION );
		$this->assertSame( 'SUCCEEDED', XPay_Refund_Status::SUCCEEDED );
		$this->assertSame( 'FAILED', XPay_Refund_Status::FAILED );
		$this->assertSame( 'CANCELED', XPay_Refund_Status::CANCELED );
		$this->assertSame( array( 'PENDING', 'REQUIRES_ACTION' ), XPay_Refund_Status::IN_FLIGHT );
	}

	public function test_refund_reason_is_the_uppercase_wire_string(): void {
		$this->assertSame( 'REQUESTED_BY_CUSTOMER', XPay_Refund_Reason::REQUESTED_BY_CUSTOMER );
	}
}
