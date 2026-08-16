<?php
/**
 * XPay_Refund_Service
 *
 * Processes admin-initiated refunds against the XPay Refunds API.
 *
 * Critical-section rule (ported from the monorepo's charge-refund lock):
 * validate → external processor call → record must be serialized per
 * payment intent. Two concurrent admin refund clicks could otherwise both
 * pass validation and both reach the processor — real money out twice.
 * MySQL GET_LOCK (bounded, non-blocking wait) is the WordPress-stack
 * equivalent of the Postgres advisory lock v3 uses.
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

	/** Max seconds to wait for the per-intent lock before giving up (mirrors v3's 5s bound). */
	const LOCK_WAIT_SECONDS = 5;

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

		global $wpdb;
		$lock_name = 'xpay_refund_' . $intent_id; // Namespaced: never collides with other plugin locks.

		// Non-blocking poll with a wall-clock deadline instead of a blocking
		// GET_LOCK timeout: keeps the PHP-FPM worker's worst case predictable
		// and lets us answer "busy, retry" instead of hanging the admin.
		$deadline = microtime( true ) + self::LOCK_WAIT_SECONDS;
		$acquired = false;
		do {
			$acquired = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
			if ( $acquired ) {
				break;
			}
			usleep( 150000 );
		} while ( microtime( true ) < $deadline );

		if ( ! $acquired ) {
			throw XPay_Api_Exception::refund_lock_busy();
		}

		try {
			$body = array(
				'paymentIntentId' => $intent_id,
				'amount'          => XPay_Money::to_minor( (string) $amount, $order->get_currency() ),
				'reason'          => 'requested_by_customer',
			);

			// UUID per admin action: transport-level retries inside this
			// request replay safely; a second deliberate refund is a new key.
			$refund = $this->client->create_refund( $body, 'wcref_' . str_replace( '-', '', wp_generate_uuid4() ) );

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
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
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
			case XPay_Error_Codes::REFUND_LOCK_BUSY:
				return __( 'Another refund for this payment is still processing. Wait a few seconds and try again.', 'xpay-for-woocommerce' );
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
