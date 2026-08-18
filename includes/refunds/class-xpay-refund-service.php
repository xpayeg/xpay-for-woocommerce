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

		$body = array(
			'paymentIntentId' => $intent_id,
			'amount'          => XPay_Money::to_minor( (string) $amount, $order->get_currency() ),
			'reason'          => XPay_Refund_Reason::REQUESTED_BY_CUSTOMER,
		);

		// UUID per admin action: transport-level retries inside this
		// request replay safely; a second deliberate refund is a new key.
		$refund = $this->client->create_refund( $body, 'wcref_' . str_replace( '-', '', wp_generate_uuid4() ) );

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
				return __( 'XPay accepted this refund and is still processing it. Do not submit it again — check the payment in your XPay dashboard and record the refund here once it completes.', 'xpay-for-woocommerce' );
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
