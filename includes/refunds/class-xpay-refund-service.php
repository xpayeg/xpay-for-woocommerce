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

	/* ── Webhook mirroring (dashboard-issued refunds) ────────────────── */

	/**
	 * Reflect a charge.refunded event into WooCommerce. The charge's
	 * refunds[] carries every refund on the charge, newest first; anything
	 * not yet in the order's ledger (META_REFUND_IDS records both
	 * plugin-issued and previously mirrored refunds) is new — typically
	 * issued from the XPay dashboard, or the recovery for a plugin refund
	 * whose HTTP response was lost after it committed.
	 *
	 * The WooCommerce record moves no money (the platform already did);
	 * where the refund cannot be stated in the order's own currency, an
	 * explanatory note stands in for a number that would be a guess.
	 * Either way the refund id enters the ledger, so redeliveries and
	 * later charge.refunded events never double-record.
	 *
	 * @param WC_Order $order  Order the charge's payment intent belongs to.
	 * @param array    $charge Charge payload from the event.
	 */
	public static function mirror_charge_refunds( WC_Order $order, array $charge ): void {
		$refunds = isset( $charge['refunds'] ) && is_array( $charge['refunds'] ) ? $charge['refunds'] : array();
		if ( array() === $refunds ) {
			return;
		}

		$ledger = $order->get_meta( XPay_Constants::META_REFUND_IDS );
		$ledger = is_array( $ledger ) ? $ledger : array();

		$mirrored = 0;
		foreach ( array_reverse( $refunds ) as $refund ) { // Oldest first, so records land in issue order.
			if ( ! is_array( $refund ) || ! isset( $refund['status'] ) || XPay_Refund_Status::SUCCEEDED !== $refund['status'] ) {
				continue; // Only settled money mirrors; pending/failed refunds have their own signals.
			}
			$refund_id = isset( $refund['id'] ) && is_string( $refund['id'] ) ? $refund['id'] : '';
			if ( '' === $refund_id || in_array( $refund_id, $ledger, true ) ) {
				continue;
			}

			$amount = self::amount_in_order_currency( $refund, $order );
			if ( null !== $amount ) {
				$result = wc_create_refund(
					array(
						'order_id'       => $order->get_id(),
						'amount'         => $amount,
						/* translators: %s is the XPay refund id. */
						'reason'         => sprintf( __( 'XPay dashboard refund %s', 'xpay-for-woocommerce' ), $refund_id ),
						'refund_payment' => false,
						'restock_items'  => false,
					)
				);
				if ( is_wp_error( $result ) ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: XPay refund id, 2: the error WooCommerce reported. */
							__( 'XPay refunded this order from the dashboard (%1$s), but the refund could not be recorded here: %2$s. Record it manually.', 'xpay-for-woocommerce' ),
							$refund_id,
							$result->get_error_message()
						)
					);
				} else {
					$order->add_order_note(
						sprintf(
							/* translators: 1: refund amount with currency, 2: XPay refund id. */
							__( 'XPay refund of %1$s issued from your XPay dashboard was recorded here (%2$s).', 'xpay-for-woocommerce' ),
							wc_price( $amount, array( 'currency' => $order->get_currency() ) ),
							$refund_id
						)
					);
				}
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: %s is the XPay refund id. */
						__( 'XPay refunded this order from the dashboard (%s), in a currency this order is not in. Check the amount in your XPay dashboard and record the refund here manually.', 'xpay-for-woocommerce' ),
						$refund_id
					)
				);
			}

			$ledger[] = $refund_id;
			++$mirrored;
		}

		if ( $mirrored > 0 ) {
			$order->update_meta_data( XPay_Constants::META_REFUND_IDS, $ledger );
			$order->save();
			XPay_Logger::event(
				'refund.mirrored',
				array(
					'order_id' => $order->get_id(),
					'count'    => $mirrored,
				)
			);
		}
	}

	/**
	 * The refund amount as a decimal string in the ORDER's currency, or
	 * null when it cannot be stated without guessing. Refund amounts ride
	 * in the charge's processing currency (EGP); when the order was
	 * presented in another currency the per-refund presentmentDetails
	 * mirror (prorated at the charge's locked rate) is the honest source.
	 *
	 * @param array    $refund Refund payload from the charge's refunds[].
	 * @param WC_Order $order  Order being mirrored into.
	 */
	private static function amount_in_order_currency( array $refund, WC_Order $order ): ?string {
		$order_currency = strtoupper( $order->get_currency() );

		if ( isset( $refund['currency'], $refund['amount'] ) && is_numeric( $refund['amount'] ) && strtoupper( (string) $refund['currency'] ) === $order_currency ) {
			return XPay_Money::from_minor( (int) $refund['amount'], $order_currency );
		}
		if ( isset( $refund['presentmentDetails']['currency'], $refund['presentmentDetails']['amount'] )
			&& is_numeric( $refund['presentmentDetails']['amount'] )
			&& strtoupper( (string) $refund['presentmentDetails']['currency'] ) === $order_currency ) {
			return XPay_Money::from_minor( (int) $refund['presentmentDetails']['amount'], $order_currency );
		}
		return null;
	}

	/**
	 * Record a refund.failed event: the refund was accepted but no money
	 * reached the customer. A note and a log row — the plugin never
	 * recorded in-flight refunds as completed, so there is nothing to
	 * roll back in WooCommerce.
	 *
	 * @param WC_Order $order  Order the refund's payment intent belongs to.
	 * @param array    $refund Refund payload from the event.
	 */
	public static function note_refund_failed( WC_Order $order, array $refund ): void {
		$refund_id = isset( $refund['id'] ) && is_string( $refund['id'] ) ? $refund['id'] : '';
		$reason    = isset( $refund['failureReason'] ) && is_string( $refund['failureReason'] ) ? $refund['failureReason'] : '';

		$order->add_order_note(
			sprintf(
				/* translators: 1: XPay refund id, 2: XPay failure reason (for example "expired_or_canceled_card"). */
				__( 'XPay refund %1$s failed (%2$s). No money was returned to the customer. Check the refund in your XPay dashboard.', 'xpay-for-woocommerce' ),
				'' !== $refund_id ? $refund_id : '—',
				'' !== $reason ? $reason : 'unknown'
			)
		);
		$order->save();

		XPay_Logger::event(
			'refund.failed_event',
			array(
				'order_id'  => $order->get_id(),
				'refund_id' => $refund_id,
				'reason'    => $reason,
			)
		);
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
