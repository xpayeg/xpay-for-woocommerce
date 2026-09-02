<?php
/**
 * XPay_Refund_Service
 *
 * Processes admin-initiated refunds against the XPay Refunds API.
 *
 * Idempotency binds one WooCommerce refund to one XPay refund across
 * transport retries. XPay enforces the remaining refundable amount.
 *
 * ValU orders: the XPay platform cannot refund ValU today. Rather than
 * guessing the method client-side, the API's typed rejection is mapped to
 * a plain-English message — honest, and automatically correct the day the
 * platform adds support.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Refund_Service {

	/**
	 * How far a refund's customer-facing figure may sit from what was asked
	 * before it counts as a disagreement rather than rounding.
	 *
	 * ONE minor unit, and the number is derived rather than picked. A
	 * non-EGP amount round-trips through EGP and both conversions truncate;
	 * truncation loses less than one unit of the currency it lands in, so
	 * the two truncations cannot move the answer by more than one unit of
	 * the customer's own currency. The plugin's own FxTest pins an instance
	 * of it: 2501 becomes 127576 becomes 2500.
	 *
	 * Anything beyond this is a real difference — most often a refund taken
	 * in the XPay dashboard first, leaving less remaining than WooCommerce
	 * thinks — and must still fail with the money moved.
	 */
	const ROUNDING_TOLERANCE_MINOR = 1;

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

		$currency       = strtoupper( $order->get_currency() );
		$requested      = XPay_Money::to_minor( (string) $amount, $currency );
		$settles_direct = XPay_Constants::SETTLEMENT_CURRENCY === $currency;

		/*
		 * A refund amount has no currency on the wire.
		 *
		 * `CreateRefundDto` carries no currency field, and the platform
		 * reads the integer it is given as minor units of the charge's
		 * PROCESSING currency, which is always EGP. So the same `2500`
		 * means "$25.00" leaving WooCommerce and "EGP 25.00" arriving at
		 * XPay: about two per cent of the intended refund, answered
		 * SUCCEEDED. That is the whole of the problem.
		 *
		 * There is exactly one way to refund a non-EGP order safely, and it
		 * is to state no amount at all. `amount` is optional, and omitting
		 * it refunds the full remaining balance in EGP, converted by the
		 * platform at the rate it locked when the shopper paid. Verified
		 * against the live test API: a charge of 11000 with 100 already
		 * refunded answered a bare request with exactly 10900.
		 *
		 * A full refund therefore needs no arithmetic. A partial one needs
		 * the amount converted, and the rate to convert it at is the one
		 * XPay locked when the shopper paid — carried on the charge and
		 * never recomputed, which is what makes an old order refundable at
		 * the price it was actually sold at.
		 */
		/*
		 * WHOLE ORDER MEANS NOTHING IS LEFT, NOT "THE REST EQUALS THE ASK".
		 *
		 * WooCommerce saves the refund record before it calls the gateway
		 * (wc-order-functions.php:667 saves, :669 hands off to
		 * wc_refund_payment), so get_total_refunded() already counts the
		 * refund being processed and $outstanding is what remains AFTER it.
		 *
		 * Comparing that against $requested therefore answered true for a
		 * refund of exactly half: on a 100.00 order with nothing refunded
		 * before, a 50.00 request leaves 50.00 outstanding, and 50 == 50.
		 * A non-EGP order then took the bare branch below, which states no
		 * amount and lets the platform return the WHOLE remaining balance.
		 * The merchant asked to refund half and gave back all of it.
		 *
		 * Nothing outstanding is the honest test, and it is true in exactly
		 * the cases it should be: the last refund on an order, whatever its
		 * size, and a single refund for the full amount.
		 */
		$outstanding = self::outstanding_minor( $order, $currency );
		$whole_order = 0 === $outstanding;

		$body = array(
			'paymentIntentId' => $intent_id,
			'reason'          => XPay_Refund_Reason::REQUESTED_BY_CUSTOMER,
		);

		// Whether the amount on the wire is the merchant's own number or one
		// converted from it. It decides what the answer is checked against.
		$converted = false;

		if ( $settles_direct ) {
			// EGP orders keep stating their amount. It is the currency the
			// platform reads, so the number is unambiguous, and saying it
			// lets the response be checked against it.
			$body['amount'] = $requested;
		} elseif ( ! $whole_order ) {
			$balance = $this->presentment_balance( $order );
			if ( null === $balance || null === $balance['rate'] ) {
				// No locked rate means no honest conversion. Refuse rather
				// than reach for today's rate, which would price an old
				// order at a number nobody agreed to.
				XPay_Logger::event(
					'refund.no_locked_rate',
					array(
						'order_id' => $order->get_id(),
						'currency' => $currency,
					)
				);
				throw XPay_Api_Exception::refund_currency_unsupported();
			}

			// Asking for everything that is left is not a partial. Stating
			// no amount lets the platform work out the remainder itself,
			// exactly, and takes rounding out of the commonest case.
			if ( null !== $balance['refundable'] && $requested === $balance['refundable'] ) {
				$whole_order = true;
			} else {
				$settlement = XPay_Fx::to_settlement( $requested, $balance['rate'], $currency, XPay_Constants::SETTLEMENT_CURRENCY );
				if ( null === $settlement || $settlement <= 0 ) {
					XPay_Logger::event(
						'refund.conversion_failed',
						array(
							'order_id'  => $order->get_id(),
							'currency'  => $currency,
							'requested' => $requested,
							'rate'      => $balance['rate'],
						)
					);
					throw XPay_Api_Exception::refund_currency_unsupported();
				}
				$body['amount'] = $settlement;
				$converted      = true;
			}
		}

		// Deterministic key, not a per-request UUID: when a refund commits
		// on the platform but its HTTP response is lost, WooCommerce rolls
		// its refund record back and the admin retries — and a fresh key on
		// that retry would pay the same money out twice. Composing from the
		// order, the count of refunds this plugin has RECORDED (nothing was
		// recorded for the lost one), and the amount makes the retry replay
		// the original refund. A genuinely new refund follows a recorded
		// success (count moved) or changes the amount, so it gets its own key.
		$refund = $this->client->create_refund( $body, $this->idempotency_key( $order, $requested ) );

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
		/*
		 * What to check the answer against depends on what was asked.
		 *
		 * When an amount was stated, the returned amount and currency must
		 * equal it. When none was stated — a full refund on an order priced
		 * in something other than EGP — the settlement figure is the
		 * platform's to decide and there is nothing to compare it to. The
		 * refund's own `presentmentDetails.amount` is what can be checked,
		 * per XPay_Money::session_charge(), and it answers "XPay refunded
		 * exactly the amount this order is for".
		 *
		 * That check is strictly stronger than the EGP one, which only
		 * confirms the platform echoed our own number back. It is what
		 * makes a bare request safe: a dashboard refund that landed first
		 * leaves less remaining, the mirror comes back smaller, and this
		 * fails closed with the money already moved and a note on the order.
		 */
		$returned_currency = isset( $refund['currency'] ) && is_string( $refund['currency'] ) ? strtoupper( $refund['currency'] ) : '';
		if ( $converted ) {
			/*
			 * A converted amount is checked against the MIRROR, not the EGP
			 * we sent — echoing our own number back proves nothing about
			 * what the customer received.
			 *
			 * A gap of a minor unit is expected and is not a failure. Both
			 * conversions truncate, so asking for $25.01 can move EGP
			 * 1,275.76 and be reported back as $25.00: the money is right,
			 * the cent is an artefact of two truncations. Refusing there
			 * would leave a completed refund unrecorded, which is worse
			 * than a cent. So it is written on the order and allowed
			 * through.
			 *
			 * BOUNDED, not waived, exactly as the bare branch below is.
			 * Truncation loses less than one unit of the currency it lands
			 * in, and two of them cannot move the answer further, so
			 * anything larger is a real difference: a dashboard refund that
			 * landed first leaves less remaining and the mirror comes back
			 * smaller by an amount nobody agreed to. Recording that as
			 * rounding would tell WooCommerce the customer was made whole
			 * when they were not. It must fail with the money moved.
			 */
			$mirror          = self::presentment_amount( $refund );
			$expected        = null;
			$returned_amount = null;
			if ( null !== $mirror && abs( $mirror - $requested ) > self::ROUNDING_TOLERANCE_MINOR ) {
				// A real difference. Fall through to the check below, which
				// reports it and refuses.
				$expected        = $requested;
				$returned_amount = $mirror;
			} elseif ( null !== $mirror && $mirror !== $requested ) {
				XPay_Logger::event(
					'refund.presentment_rounding',
					array(
						'order_id'   => $order->get_id(),
						'refund_id'  => $refund_id,
						'requested'  => $requested,
						'refunded'   => $mirror,
						'settlement' => $body['amount'],
					)
				);
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount requested, 2: amount refunded, 3: settlement amount. */
						__( 'XPay refunded %3$s, which it reports to the customer as %2$s. You asked for %1$s. The difference is rounding between the two currencies; the settled amount is the one that moved.', 'xpay-for-woocommerce' ),
						wc_price( XPay_Money::from_minor( $requested, $currency ), array( 'currency' => $currency ) ),
						wc_price( XPay_Money::from_minor( $mirror, $currency ), array( 'currency' => $currency ) ),
						wc_price( XPay_Money::from_minor( (int) $body['amount'], XPay_Constants::SETTLEMENT_CURRENCY ), array( 'currency' => XPay_Constants::SETTLEMENT_CURRENCY ) )
					)
				);
			}
		} elseif ( isset( $body['amount'] ) ) {
			$expected        = (int) $body['amount'];
			$returned_amount = isset( $refund['amount'] ) && is_numeric( $refund['amount'] ) ? (int) $refund['amount'] : null;
		} else {
			$expected        = null;
			$returned_amount = null;
			/*
			 * A BARE request: no amount was sent, so the platform returned
			 * the whole remaining balance. There is nothing to verify the
			 * amount against — we did not name one.
			 *
			 * A non-EGP balance can differ by a minor unit after conversion.
			 * The completed refund is recorded and the difference is noted.
			 *
			 * BOUNDED, not waived. The artefact is at most one minor unit:
			 * each conversion truncates, and truncation loses less than one
			 * unit of the currency it lands in. Anything larger is not
			 * rounding — a dashboard refund that landed first leaves less
			 * remaining, the mirror comes back smaller by a real amount,
			 * and that must still fail with the money moved. The check
			 * below is what keeps it failing.
			 *
			 * The CURRENCY check stays too. A refund reported in a currency
			 * this order is not in is a disagreement, not an artefact.
			 */
			$mirror = self::presentment_amount( $refund );
			if ( null !== $mirror && abs( $mirror - $requested ) > self::ROUNDING_TOLERANCE_MINOR ) {
				// A real difference. Fall through to the check below, which
				// reports it and refuses.
				$expected        = $requested;
				$returned_amount = $mirror;
			} elseif ( null !== $mirror && $mirror !== $requested ) {
				XPay_Logger::event(
					'refund.presentment_rounding',
					array(
						'order_id'  => $order->get_id(),
						'refund_id' => $refund_id,
						'requested' => $requested,
						'refunded'  => $mirror,
						'bare'      => true,
					)
				);
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount requested, 2: amount XPay reports to the customer. */
						__( 'XPay refunded the remaining balance, which it reports to the customer as %2$s. You asked for %1$s. The difference is rounding between the two currencies.', 'xpay-for-woocommerce' ),
						wc_price( XPay_Money::from_minor( $requested, $currency ), array( 'currency' => $currency ) ),
						wc_price( XPay_Money::from_minor( $mirror, $currency ), array( 'currency' => $currency ) )
					)
				);
			}
			// The settlement currency is the platform's business here; the
			// mirror is what has to be in the order's currency.
			$returned_currency = self::presentment_currency( $refund );
		}

		if ( $converted ) {
			// Already reconciled above, against the mirror rather than the
			// echo. The currency check below belongs to the other two paths.
			$returned_currency = '';
		}

		if ( ( null !== $returned_amount && $expected !== $returned_amount ) || ( '' !== $returned_currency && $currency !== $returned_currency ) ) {
			XPay_Logger::event(
				'refund.result_mismatch',
				array(
					'order_id'          => $order->get_id(),
					'refund_id'         => $refund_id,
					'requested_amount'  => $expected,
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
				// The order's own currency, always. On a bare request the
				// settlement figure is the platform's and is recorded below
				// as `settled`, so the log can answer both "what did the
				// merchant ask for" and "what actually moved".
				'amount'    => $requested,
				'currency'  => $currency,
				'settled'   => isset( $refund['amount'] ) && is_numeric( $refund['amount'] ) ? (int) $refund['amount'] : null,
			)
		);

		return $refund;
	}

	/**
	 * What XPay still holds for this order, in the customer's own currency,
	 * and the rate it was locked at.
	 *
	 * One extra call, made only for a partial refund on an order priced in
	 * something other than EGP — the one case that cannot be answered from
	 * WooCommerce's own records. Everything it returns is a figure the
	 * platform authored: the charge's presentment amount, the sum of the
	 * refunds' own presentment amounts, and the locked rate as the string it
	 * was stored as.
	 *
	 * @param WC_Order $order Order being refunded.
	 * @return array{rate:?string,refundable:?int,currency:string}|null
	 */
	private function presentment_balance( WC_Order $order ): ?array {
		$session_id = (string) $order->get_meta( XPay_Constants::META_SESSION_ID );
		if ( '' === $session_id ) {
			return null;
		}

		try {
			$session = $this->client->get_checkout_session( $session_id );
		} catch ( XPay_Api_Exception $e ) {
			XPay_Logger::event(
				'refund.balance_unreadable',
				array(
					'order_id' => $order->get_id(),
					'code'     => $e->get_error_code(),
				)
			);
			return null;
		}

		$answer = XPay_Refundable::from_session( $session );
		if ( null === $answer || null === $answer['presentment'] ) {
			return null;
		}

		return array(
			'rate'       => $answer['presentment']['rate'],
			// Absent when the refunds on the payload do not account for what
			// the charge says went back. A missing figure only costs the
			// "asking for everything left" shortcut; it does not block.
			'refundable' => isset( $answer['presentment']['refundable'] ) ? (int) $answer['presentment']['refundable'] : null,
			'currency'   => $answer['presentment']['currency'],
		);
	}

	/**
	 * What this order still owes back, in minor units of its own currency.
	 *
	 * WooCommerce's own ledger, deliberately: the refund box has already
	 * validated the admin's figure against exactly this number
	 * (class-wc-ajax.php:2414), so testing against anything else here would
	 * disagree with the screen the admin is looking at. The platform's view
	 * is the backstop, not the input.
	 *
	 * @param WC_Order $order    Order being refunded.
	 * @param string   $currency Order currency, already uppercased.
	 */
	private static function outstanding_minor( WC_Order $order, string $currency ): int {
		return XPay_Money::to_minor( (string) $order->get_total(), $currency )
			- XPay_Money::to_minor( (string) $order->get_total_refunded(), $currency );
	}


	/**
	 * The refund's amount in the CUSTOMER's currency, when it carries one.
	 *
	 * Present only when the charge was converted, i.e. exactly the case
	 * where the settlement figure is unverifiable from here. Null otherwise,
	 * which the caller
	 * treats as "nothing to check" rather than "zero".
	 *
	 * @param array $refund Refund object from the API.
	 */
	private static function presentment_amount( array $refund ): ?int {
		if ( ! isset( $refund['presentmentDetails'] ) || ! is_array( $refund['presentmentDetails'] ) ) {
			return null;
		}
		$amount = $refund['presentmentDetails']['amount'] ?? null;
		return is_numeric( $amount ) ? (int) $amount : null;
	}

	/**
	 * @param array $refund Refund object from the API.
	 * @return string Uppercase currency, or '' when the refund carries no mirror.
	 */
	private static function presentment_currency( array $refund ): string {
		if ( ! isset( $refund['presentmentDetails'] ) || ! is_array( $refund['presentmentDetails'] ) ) {
			return '';
		}
		$currency = $refund['presentmentDetails']['currency'] ?? null;
		return is_string( $currency ) ? strtoupper( $currency ) : '';
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
	 * null when it cannot be stated without guessing. Presentment in the
	 * mirror, settlement at the top level, per XPay_Money::session_charge();
	 * here the mirror is the refund's OWN, prorated at the charge's locked rate.
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
				return __( 'This order is not in EGP, and XPay reads refund amounts as EGP, so a part-refund from here would move the wrong amount. Refund the full order instead, which works, or issue the part-refund from your XPay dashboard. A dashboard refund is recorded here automatically.', 'xpay-for-woocommerce' );
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
