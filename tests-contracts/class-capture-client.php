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
			'amountSubtotal' => 29000,
			'amountTotal'    => 29000,
			'currency'       => 'EGP',
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

		// The real API echoes the created lines back with their ids, and the
		// id is the only thing that makes the amount movable afterwards. A
		// fake that omitted it would let a session that can never be updated
		// pass as one that can.
		$lines = array();
		foreach ( isset( $body['lineItems'] ) && is_array( $body['lineItems'] ) ? $body['lineItems'] : array() as $index => $line ) {
			$lines[] = array(
				'id'       => 'li_test_contract_' . count( $this->created ) . '_' . $index,
				'quantity' => isset( $line['quantity'] ) ? $line['quantity'] : 1,
			);
		}

		return $this->serve(
			array(
				'id'        => 'cs_test_contract_' . count( $this->created ),
				'lineItems' => $lines,
			)
		);
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

		/*
		 * A repriced session reads back at its NEW total, the way the real
		 * one does: PATCH lineItems replaces the rows and recomputes the
		 * amounts. A fake that echoed the old figure would let a reprice
		 * that changed nothing pass as one that worked — and the reuse
		 * check on the next attempt reads exactly this field.
		 */
		$echo = array( 'id' => $session_id );
		if ( isset( $body['lineItems'][0]['priceData']['unitAmount'] ) ) {
			$amount                   = (int) $body['lineItems'][0]['priceData']['unitAmount'];
			$echo['amountSubtotal']   = $amount;
			$echo['amountTotal']      = $amount;
			$echo['lineItems']        = array( array( 'id' => 'li_test_contract_repriced', 'quantity' => 1 ) );
			$this->session['amountSubtotal'] = $amount;
			$this->session['amountTotal']    = $amount;
		}
		return $this->serve( $echo );
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

	/** What the platform settles when a refund states no amount of its own. */
	const BARE_REFUND_SETTLED = 1406500;

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
				'id'     => 're_test_contract_' . count( $this->refunds ),
				'status' => XPay_Refund_Status::SUCCEEDED,
				// A request that states no amount is answered with the full
				// remaining balance the platform worked out for itself, so
				// there is nothing here to echo. Verified against the live
				// test API: a bare request on a charge with 10900 left came
				// back with exactly 10900. Tests that care about the figure
				// set it through $this->refund.
				'amount' => isset( $body['amount'] ) ? $body['amount'] : self::BARE_REFUND_SETTLED,
				'currency' => 'EGP',
			),
			$this->refund
		);
	}
}
