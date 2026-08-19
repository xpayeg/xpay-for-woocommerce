<?php
/**
 * Scriptable XPay_Api_Client for the contract suite: records every
 * create body, serves a configurable session for reads, and can be told
 * to fail specific calls with specific API errors — which is how the
 * pin-rejected and stale-customer retry contracts get exercised.
 *
 * @package XPay_For_WooCommerce
 */

class XPay_Capture_Client extends XPay_Api_Client {

	/** @var array[] Every create_checkout_session body, in order. */
	public $created = array();

	/** @var string[] Session ids passed to expire_checkout_session. */
	public $expired = array();

	/** @var array Overrides merged into every session this client returns. */
	public $session = array();

	/** @var XPay_Api_Exception[] Exceptions to throw on the next create calls (null entry = succeed). */
	public $create_failures = array();

	/** @var XPay_Api_Exception|null Exception thrown on every get until cleared. */
	public $get_failure = null;

	public function __construct() {
		parent::__construct( 'rk_test_contract' );
	}

	private function serve( array $extra = array() ): array {
		$base = array(
			'id'           => 'cs_test_contract',
			'status'       => XPay_Session_Status::OPEN,
			'isExpired'    => false,
			'url'          => 'https://checkout.xpay.app/c/contract',
			'clientSecret' => 'cs_secret_contract',
			'amountTotal'  => 29000,
			'currency'     => 'EGP',
		);
		return array_merge( $base, $this->session, $extra );
	}

	public function create_checkout_session( array $body, string $idempotency_key ): array {
		if ( array() !== $this->create_failures ) {
			$failure = array_shift( $this->create_failures );
			if ( null !== $failure ) {
				throw $failure;
			}
		}
		$this->created[] = $body;
		return $this->serve( array( 'id' => 'cs_test_contract_' . count( $this->created ) ) );
	}

	public function get_checkout_session( string $session_id, ?int $timeout = null ): array {
		if ( null !== $this->get_failure ) {
			throw $this->get_failure;
		}
		return $this->serve( array( 'id' => $session_id ) );
	}

	public function expire_checkout_session( string $session_id ): void {
		$this->expired[] = $session_id;
	}
}
