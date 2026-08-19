<?php
/**
 * Shared base for the contract suite: a fresh in-memory world per test,
 * plus builders and assertion helpers shared by every contract.
 *
 * @package XPay_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

abstract class ContractTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		xpay_tests_reset_world();
	}

	/**
	 * Register an order in the wc_get_order() registry.
	 *
	 * @param int   $id    Order id.
	 * @param array $props Public property overrides for the stub.
	 */
	protected function makeOrder( int $id, array $props = array() ): WC_Order {
		$order = new WC_Order( $id );
		foreach ( $props as $key => $value ) {
			$order->{$key} = $value;
		}
		$GLOBALS['xpay_test_orders'][ $id ] = $order;
		return $order;
	}

	/** Stage names of every xpay_logger_event fired so far. */
	protected function firedStages(): array {
		$stages = array();
		foreach ( $GLOBALS['xpay_test_actions'] as $action ) {
			if ( 'xpay_logger_event' === $action[0] ) {
				$stages[] = $action[1];
			}
		}
		return $stages;
	}

	protected function assertStageFired( string $stage ): void {
		$this->assertContains( $stage, $this->firedStages(), "Expected logger stage '$stage' to fire." );
	}

	protected function assertStageNotFired( string $stage ): void {
		$this->assertNotContains( $stage, $this->firedStages(), "Logger stage '$stage' must not fire." );
	}

	/** A paid-session payload the webhook or thank-you check would carry. */
	protected function paidSession( array $extra = array() ): array {
		return array_merge(
			array(
				'id'            => 'cs_test_contract',
				'status'        => XPay_Session_Status::COMPLETE,
				'paymentStatus' => XPay_Payment_Status::PAID,
				'amountTotal'   => 29000,
				'currency'      => 'EGP',
				'paymentIntent' => array( 'id' => 'pi_contract_1' ),
				'livemode'      => false,
				'metadata'      => array( 'wc_order_id' => '14' ),
			),
			$extra
		);
	}

	/** Invoke the webhook controller's private apply_event for a scenario. */
	protected function applyEvent( string $type, string $event_id, array $session_object ): void {
		$method = new ReflectionMethod( XPay_Webhook_Controller::class, 'apply_event' );
		$method->setAccessible( true );
		$method->invoke( null, $type, $event_id, $session_object );
	}
}
