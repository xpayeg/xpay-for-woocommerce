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

	/** @var string[] Idempotency-Key of every create_checkout_session, in order. */
	public $create_keys = array();

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
		$this->created[]     = $body;
		$this->create_keys[] = $idempotency_key;
		return $this->serve( array( 'id' => 'cs_test_contract_' . count( $this->created ) ) );
	}

	public function get_checkout_session( string $session_id, ?int $timeout = null ): array {
		if ( null !== $this->get_failure ) {
			throw $this->get_failure;
		}
		return $this->serve( array( 'id' => $session_id ) );
	}

	/** @var array<int, array{session_id:string, body:array, key:string}> Every PATCH sent. */
	public $updated = array();

	/** @var XPay_Api_Exception|null Thrown by the next update, if set. */
	public $update_failure = null;

	public function update_checkout_session( string $session_id, array $body, string $idempotency_key ): array {
		if ( null !== $this->update_failure ) {
			throw $this->update_failure;
		}
		$this->updated[] = array(
			'session_id' => $session_id,
			'body'       => $body,
			'key'        => $idempotency_key,
		);
		return $this->serve( array( 'id' => $session_id ) );
	}

	public function expire_checkout_session( string $session_id ): void {
		$this->expired[] = $session_id;
	}

	/* ── Refunds ─────────────────────────────────────────────────────── */

	/** @var array[] Every create_refund body, in order. */
	public $refunds = array();

	/** @var string[] Idempotency-Key of every create_refund attempt, in order. */
	public $refund_keys = array();

	/** @var array Overrides merged into every refund object this client returns. */
	public $refund = array();

	/** @var XPay_Api_Exception|null Thrown on the next create_refund (once). */
	public $refund_failure = null;

	public function create_refund( array $body, string $idempotency_key ): array {
		// Body and key are recorded BEFORE a scripted failure: a transport
		// failure means the response was lost, not that the request (and
		// its idempotency key) never went out — which is exactly the
		// scenario the deterministic-key contract exists for.
		$this->refunds[]     = $body;
		$this->refund_keys[] = $idempotency_key;
		if ( null !== $this->refund_failure ) {
			$failure              = $this->refund_failure;
			$this->refund_failure = null;
			throw $failure;
		}
		return array_merge(
			array(
				'id'       => 're_test_contract_' . count( $this->refunds ),
				'status'   => XPay_Refund_Status::SUCCEEDED,
				'amount'   => $body['amount'],
				'currency' => 'EGP',
			),
			$this->refund
		);
	}
}
