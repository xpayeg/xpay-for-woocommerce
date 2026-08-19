<?php
/**
 * XPay_Refund_Service
 *
 * Processes admin-initiated refunds against the XPay Refunds API.
 *
 * Deliberately NO client-side lock: the platform serializes the whole
 * validate → processor call → commit critical section per charge with its
 * own advisory lock (charge-refund-lock.util.ts) and re-validates the
 * remaining refundable amount inside it, so concurrent refunds can never
 * over-refund regardless of what any client does. A plugin-side lock was
 * shipped and removed: it serialized but could not deduplicate (the second
 * click just waited its turn and fired), so its only effect was a softer
 * error message — machinery without function. Each side locks what it
 * owns: the platform locks the money, XPay_Order_Lock locks WooCommerce
 * order state.
 *
 * valU orders: the XPay platform cannot refund valU today. Rather than
 * guessing the method client-side, the API's typed rejection is mapped to
 * a plain-English message — honest, and automatically correct the day the
 * platform adds support.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Refund_Service {

	/** @var XPay_Api_Client */
	private $client;

	public function __construct( XPay_Api_Client $client ) {
		$this->client = $client;
	}

	/**
	 * @param WC_Order $order  Order being refunded.
	 * @param float    $amount Refund amount in the order's currency.
	 * @param string   $reason Free-text reason from the admin.
	 * @return array Refund object from the API.
	 * @throws XPay_Api_Exception
	 */
	public function refund_order( WC_Order $order, float $amount, string $reason ): array {
		$intent_id = (string) $order->get_meta( XPay_Constants::META_PAYMENT_INTENT );
		if ( '' === $intent_id ) {
			throw XPay_Api_Exception::not_configured( 'payment intent for this order' );
		}

		// Fail closed on non-EGP orders BEFORE any API call: the platform
		// interprets refund amounts in the charge's PROCESSING currency,
		// which is always EGP (CreateRefundDto has no currency field), so a
		// "100" from a USD order would move 100 EGP, come back SUCCEEDED,
		// and WooCommerce would record $100 refunded. Those merchants
		// refund from the XPay dashboard until the platform accepts
		// presentment-currency amounts.
		$currency = strtoupper( $order->get_currency() );
		if ( 'EGP' !== $currency ) {
			XPay_Logger::event(
				'refund.currency_unsupported',
				array(
					'order_id' => $order->get_id(),
					'currency' => $currency,
				)
			);
			throw XPay_Api_Exception::refund_currency_unsupported();
		}

		$body = array(
			'paymentIntentId' => $intent_id,
			'amount'          => XPay_Money::to_minor( (string) $amount, $currency ),
			'reason'          => XPay_Refund_Reason::REQUESTED_BY_CUSTOMER,
		);

		// Deterministic key, not a per-request UUID: when a refund commits
		// on the platform but its HTTP response is lost, WooCommerce rolls
		// its refund record back and the admin retries — and a fresh key on
		// that retry would pay the same money out twice. Composing from the
		// order, the count of refunds this plugin has RECORDED (nothing was
		// recorded for the lost one), and the amount makes the retry replay
		// the original refund. A genuinely new refund follows a recorded
		// success (count moved) or changes the amount, so it gets its own key.
		$refund = $this->client->create_refund( $body, $this->idempotency_key( $order, (int) $body['amount'] ) );

		// A 2xx create can still carry a non-completed status — the
		// processor's synchronous decline (FAILED) is copied into the
		// refund object, not turned into an HTTP error. Only SUCCEEDED
		// may reach WooCommerce: returning true from process_refund()
		// records a COMPLETED refund, so an in-flight state (unused by
		// current adapters, reserved for future processors) must fail
		// closed with a "do not resubmit" message instead.
		$status = isset( $refund['status'] ) && is_string( $refund['status'] ) ? $refund['status'] : '';
		if ( XPay_Refund_Status::SUCCEEDED !== $status ) {
			XPay_Logger::event(
				'refund.not_completed',
				array(
					'order_id'  => $order->get_id(),
					'intent_id' => $intent_id,
					'refund_id' => isset( $refund['id'] ) ? (string) $refund['id'] : '',
					'status'    => $status,
				)
			);
			if ( in_array( $status, XPay_Refund_Status::IN_FLIGHT, true ) ) {
				throw XPay_Api_Exception::refund_pending();
			}
			throw XPay_Api_Exception::refund_rejected();
		}

		$refund_id = isset( $refund['id'] ) ? (string) $refund['id'] : '';

		// SUCCEEDED alone is not enough: the returned refund object must
		// state the amount and currency that were REQUESTED before
		// WooCommerce records them as fact. Absent fields pass (fail open
		// on shape, closed on value — the same rule mark_paid applies);
		// a present-but-different value goes to a human with the money
		// already moved, so the trail must live on the order, not only in
		// the log.
		$returned_amount   = isset( $refund['amount'] ) && is_numeric( $refund['amount'] ) ? (int) $refund['amount'] : null;
		$returned_currency = isset( $refund['currency'] ) && is_string( $refund['currency'] ) ? strtoupper( $refund['currency'] ) : '';
		if ( ( null !== $returned_amount && (int) $body['amount'] !== $returned_amount ) || ( '' !== $returned_currency && $currency !== $returned_currency ) ) {
			XPay_Logger::event(
				'refund.result_mismatch',
				array(
					'order_id'          => $order->get_id(),
					'refund_id'         => $refund_id,
					'requested_amount'  => $body['amount'],
					'returned_amount'   => $returned_amount,
					'returned_currency' => $returned_currency,
				)
			);
			$order->add_order_note(
				sprintf(
					/* translators: %s is the XPay refund id. */
					__( 'XPay completed refund %s with a different amount or currency than requested. Nothing was recorded in WooCommerce. Verify the payment in your XPay dashboard.', 'xpay-for-woocommerce' ),
					'' !== $refund_id ? $refund_id : '—'
				)
			);
			throw XPay_Api_Exception::refund_result_mismatch();
		}

		$this->record_refund( $order, $refund_id );

		$order->add_order_note(
			sprintf(
				/* translators: 1: refund amount with currency, 2: XPay refund id, 3: admin-entered reason. */
				__( 'XPay refund of %1$s submitted (%2$s). Reason: %3$s', 'xpay-for-woocommerce' ),
				wc_price( $amount, array( 'currency' => $order->get_currency() ) ),
				'' !== $refund_id ? $refund_id : '—',
				'' !== $reason ? $reason : '—'
			)
		);

		XPay_Logger::event(
			'refund.submitted',
			array(
				'order_id'  => $order->get_id(),
				'intent_id' => $intent_id,
				'refund_id' => $refund_id,
				'amount'    => $body['amount'],
			)
		);

		return $refund;
	}

	/**
	 * The deterministic Idempotency-Key for the refund being attempted.
	 * Sequence = refunds already RECORDED on the order (record_refund),
	 * so a retry after a lost response replays and a follow-up refund
	 * after a recorded success gets a fresh key.
	 *
	 * @param WC_Order $order        Order being refunded.
	 * @param int      $amount_minor Refund amount in minor units.
	 */
	private function idempotency_key( WC_Order $order, int $amount_minor ): string {
		return sprintf( 'wcref_%d_n%d_%d', $order->get_id(), count( $this->recorded_refunds( $order ) ), $amount_minor );
	}

	/** @param WC_Order $order Order carrying the recorded-refund ledger. */
	private function recorded_refunds( WC_Order $order ): array {
		$recorded = $order->get_meta( XPay_Constants::META_REFUND_IDS );
		return is_array( $recorded ) ? $recorded : array();
	}

	/**
	 * Append a completed refund to the order's ledger. Saved immediately:
	 * the ledger is what moves the idempotency sequence forward, and it
	 * must be on disk before process_refund() returns true.
	 *
	 * @param WC_Order $order     Order that was refunded.
	 * @param string   $refund_id XPay refund id ('' still advances the sequence).
	 */
	private function record_refund( WC_Order $order, string $refund_id ): void {
		$recorded   = $this->recorded_refunds( $order );
		$recorded[] = $refund_id;
		$order->update_meta_data( XPay_Constants::META_REFUND_IDS, $recorded );
		$order->save();
	}

	/**
	 * Plain-English message for a refund failure, keyed on the API's stable
	 * error code. The doc_url from the response is appended verbatim when
	 * present — never reconstructed client-side.
	 *
	 * @param XPay_Api_Exception $e The failure.
	 */
	public static function admin_message( XPay_Api_Exception $e ): string {
		switch ( $e->get_error_code() ) {
			case XPay_Error_Codes::REFUND_REJECTED:
				return __( 'XPay accepted the request but did not complete the refund. Check the payment in your XPay dashboard before retrying.', 'xpay-for-woocommerce' );
			case XPay_Error_Codes::REFUND_PENDING:
				return __( 'XPay accepted this refund and is still processing it. Do not submit it again. Check the payment in your XPay dashboard and record the refund here once it completes.', 'xpay-for-woocommerce' );
			case XPay_Error_Codes::REFUND_CURRENCY_UNSUPPORTED:
				return __( 'This order is not in EGP. XPay processes refund amounts in EGP, so refunding from here would move the wrong amount. Issue the refund from your XPay dashboard instead, then record it in WooCommerce manually.', 'xpay-for-woocommerce' );
			case XPay_Error_Codes::REFUND_RESULT_MISMATCH:
				return __( 'XPay completed this refund with a different amount or currency than requested, so it was not recorded in WooCommerce. Check the payment in your XPay dashboard before doing anything else.', 'xpay-for-woocommerce' );
			case XPay_Error_Codes::TRANSPORT_ERROR:
				return __( 'Could not reach XPay to confirm this refund. It may still have gone through: check the payment in your XPay dashboard before retrying.', 'xpay-for-woocommerce' );
			case XPay_Error_Codes::API_RESOURCE_INVALID_STATE:
				return __( 'XPay cannot refund this payment in its current state. Check the payment in your XPay dashboard.', 'xpay-for-woocommerce' );
			default:
				$message = $e->getMessage();
				if ( '' !== $e->get_doc_url() ) {
					$message .= ' — ' . $e->get_doc_url();
				}
				return $message;
		}
	}
}
