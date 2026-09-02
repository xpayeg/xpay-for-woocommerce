<?php
/**
 * XPay_Checkout_Service
 *
 * Creates and reuses XPay Checkout Sessions for WooCommerce orders.
 *
 * Amount mapping: ONE synthetic line item carrying the order's grand total
 * (items + shipping + tax − discounts) in minor units. Itemized lines are
 * deliberately not sent — WooCommerce's rounding is authoritative for what
 * the customer owes, and re-itemizing risks a piaster of drift between the
 * two systems. The order number is the product name the shopper sees.
 *
 * Session reuse: an order keeps its session across payment attempts while
 * the session is still OPEN and the total is unchanged; otherwise a new
 * session is created under a bumped attempt counter so the Idempotency-Key
 * changes with intent, not by accident.
 *
 * @see https://docs.xpay.app/en/api-reference/objects/checkout-session
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Checkout_Service {

	/** @var XPay_Api_Client */
	private $client;

	/**
	 * @var string[]|null The methods a session for this order may accept
	 *      (the Payment Methods tab's checked list ∩ the account's methods
	 *      for the order's currency), or null to leave the session on the
	 *      account default — the fallback state with no cached map.
	 */
	private $accepted_types;

	/** @var callable|null Refreshes the account and returns the current accepted methods. */
	private $refresh_accepted_types;

	/**
	 * @param XPay_Api_Client $client                 API client for the plane.
	 * @param string[]|null   $accepted_types         See $accepted_types above.
	 * @param callable|null   $refresh_accepted_types Returns string[] for a currency.
	 */
	public function __construct( XPay_Api_Client $client, ?array $accepted_types = null, ?callable $refresh_accepted_types = null ) {
		$this->client                 = $client;
		$this->accepted_types         = null === $accepted_types ? null : array_values( $accepted_types );
		$this->refresh_accepted_types = $refresh_accepted_types;
	}

	/**
	 * Return a payable session for this order, creating one only when there
	 * is no usable one already.
	 *
	 * ONE SESSION PER CHECKOUT. This is get-or-create per ORDER, never per
	 * Pay click, and the distinction is the whole of the retry discipline:
	 *
	 *   - Session still OPEN and totalling what the order does -> reuse it,
	 *     same clientSecret. One Payment Intent carries the whole
	 *     transaction: every decline, the retry history, the final charge.
	 *   - Total moved (the shopper edited the cart between attempts) ->
	 *     PATCH the existing session's line items to the new total and
	 *     reuse the SAME secret. The platform replaces line items
	 *     atomically and reuses the session's locked exchange rate.
	 *   - Session expired, gone, or in a currency this order no longer uses
	 *     -> only then mint a new one, record the old id in the superseded
	 *     ledger, and expire it.
	 *
	 * A session per Pay click would mint a Payment Intent per retry: one
	 * purchase split across many objects, each abandoned attempt leaving a
	 * live payable session behind. WooCommerce resumes the same order when
	 * a shopper retries an unchanged cart, so per-order reuse gives
	 * per-checkout reuse for free; when the cart changes enough that
	 * WooCommerce mints a new order, a new session is then genuinely
	 * correct.
	 *
	 * @param WC_Order $order Order awaiting payment.
	 * @return array Session object (id, clientSecret, …).
	 * @throws XPay_Api_Exception
	 */
	public function get_or_create_session( WC_Order $order ): array {
		$existing_id = (string) $order->get_meta( XPay_Constants::META_SESSION_ID );

		if ( '' !== $existing_id ) {
			try {
				$session = $this->client->get_checkout_session( $existing_id );
				if ( $this->is_reusable( $session, $order ) ) {
					return $session;
				}
				// Still payable, only the total moved: reprice in place and
				// keep the secret. Creating a replacement here is what
				// splits one purchase across many Payment Intents.
				$repriced = $this->reprice_session( $session, $order );
				if ( null !== $repriced ) {
					return $repriced;
				}
				// A COMPLETE/PAID session is not "merely not reusable" — it
				// means THIS order was already paid (stale emailed pay link,
				// webhook lost or still in flight). Minting a fresh payable
				// session here is how a shopper gets charged twice; apply
				// the payment instead and hand the caller the COMPLETE
				// session so it can route to the order-received page.
				if ( $this->is_paid_complete( $session ) ) {
					$this->apply_paid_session( $order, $session );
					return $session;
				}
			} catch ( XPay_Api_Exception $e ) {
				// ONLY a genuinely missing previous session falls through to
				// minting a fresh one. Transport failures (status 0) and auth
				// errors must surface instead: the old session may still be
				// OPEN, and a shopper paying it through its hosted link would
				// then hit the ownership check with a superseded session id —
				// money taken, order never marked paid.
				if ( XPay_Error_Codes::API_RESOURCE_MISSING !== $e->get_error_code() && 404 !== $e->get_http_status() ) {
					throw $e;
				}
			}
		}

		/*
		 * WRITTEN BEFORE THE ORDER MOVES OFF THE OLD SESSION, not after.
		 *
		 * The ledger is what makes a payment on a session this order has
		 * left behind recognizable as this order's money: the webhook reads
		 * META_SESSION_ID first and falls back to this list
		 * (class-xpay-webhook-controller.php:727-738), and an id in neither
		 * is 'foreign'. Foreign is terminal rather than retried: the
		 * controller answers it 200 received/applied:false so XPay's
		 * delivery engine is not alarmed
		 * (class-xpay-webhook-controller.php:100-113), which means an event
		 * judged foreign is dropped once and never comes back.
		 *
		 * Writing it first is safe in the direction that matters. If the
		 * create then fails, the order still points at the old session and
		 * the ledger merely names the session that is still current, which
		 * the exact-match branch answers first and calls 'current'.
		 *
		 * Bounded: an order cycling sessions cannot grow the list without
		 * limit.
		 */
		if ( '' !== $existing_id ) {
			$superseded   = $order->get_meta( XPay_Constants::META_SUPERSEDED_SESSIONS );
			$superseded   = is_array( $superseded ) ? $superseded : array();
			$superseded[] = $existing_id;
			$order->update_meta_data( XPay_Constants::META_SUPERSEDED_SESSIONS, array_slice( array_unique( $superseded ), -10 ) );
			$order->save();
		}

		$session = $this->create_session( $order );

		// The order now points at the NEW session, but the old one stays
		// OPEN (and payable!) on the platform for up to 24h. A shopper
		// completing it from a second tab or an old hosted link would be
		// charged while the webhook fails the ownership check and gets
		// dropped — money taken, order never marked paid. Expire it.
		// AFTER the new id is saved, so the old session's resulting
		// checkout.session.expired event also fails ownership and cannot
		// cancel this order. Best-effort: the new session is already live,
		// and the old one dies on its own clock if this call fails.
		if ( '' !== $existing_id && $existing_id !== (string) $session['id'] ) {
			try {
				$this->client->expire_checkout_session( $existing_id );
				XPay_Logger::event(
					'session.superseded_expired',
					array(
						'order_id'   => $order->get_id(),
						'session_id' => $existing_id,
					)
				);
			} catch ( XPay_Api_Exception $e ) {
				XPay_Logger::event(
					'session.expire_failed',
					array(
						'order_id'   => $order->get_id(),
						'session_id' => $existing_id,
						'code'       => $e->get_error_code(),
					)
				);
			}
		}

		return $session;
	}

	/**
	 * True when a fetched session says this order's payment already
	 * succeeded: the checkout completed AND money moved. COMPLETE alone is
	 * deliberately not enough — a completed-but-unpaid session (reserved
	 * for future pay-later shapes) falls through to the normal new-session
	 * path.
	 *
	 * @param array $session Session object from the API.
	 */
	private function is_paid_complete( array $session ): bool {
		return isset( $session['status'] ) && XPay_Session_Status::COMPLETE === $session['status']
			&& isset( $session['paymentStatus'] ) && XPay_Payment_Status::PAID === $session['paymentStatus'];
	}

	/**
	 * Apply a paid session found during a session check, under the same
	 * per-order lock discipline as the webhook and thank-you paths. The
	 * non-blocking acquire is deliberate: a busy lock means another writer
	 * (usually the webhook) is applying this same truth right now.
	 *
	 * @param WC_Order $order   Order the session belongs to (ownership: the
	 *                          session id came from this order's own meta).
	 * @param array    $session COMPLETE/PAID session object from the API.
	 */
	private function apply_paid_session( WC_Order $order, array $session ): void {
		XPay_Logger::event(
			'session.already_complete',
			array(
				'order_id'   => $order->get_id(),
				'session_id' => isset( $session['id'] ) ? (string) $session['id'] : '',
			)
		);

		$order_id = $order->get_id();
		if ( ! XPay_Order_Lock::acquire( $order_id, 0 ) ) {
			return;
		}
		try {
			$fresh = XPay_Order_Sync::reload( $order_id );
			if ( null !== $fresh && ! $fresh->is_paid() ) {
				XPay_Order_Sync::mark_paid( $fresh, $session, 'session-check' );
			}
		} finally {
			XPay_Order_Lock::release( $order_id );
		}
	}

	/**
	 * Has this session lost its line items?
	 *
	 * True only when the platform has SAID the list is empty. An absent key
	 * is not an answer — a response that simply does not expand lineItems
	 * must not be read as "no items" and churn a perfectly good session.
	 *
	 * The state is reachable: PATCHing `lineItems` onto a session leaves it
	 * with zero of them while `amountSubtotal` keeps the new total, so the
	 * session looks payable to every other check and is not.
	 *
	 * @param array $session Session as the API returned it.
	 */
	private static function is_emptied( array $session ): bool {
		return isset( $session['lineItems'] )
			&& is_array( $session['lineItems'] )
			&& array() === $session['lineItems'];
	}

	/**
	 * Move an OPEN session's total to the order's, in place.
	 *
	 * The retry path that keeps one purchase on one Payment Intent: the
	 * shopper declined, edited their cart, and pressed Pay again. Their
	 * session is still perfectly good; only the number on it is stale.
	 *
	 * A full line-item replacement, which is the platform's own shape for
	 * this: PATCH /checkout/sessions/:id deletes the existing rows and
	 * recreates them inside one transaction, reusing the session's LOCKED
	 * exchange rate so a repriced non-EGP order still settles at the rate
	 * the shopper first saw. An empty or null list is rejected outright,
	 * which is why this always sends exactly one line.
	 *
	 * Answers null when repricing is not the right answer, and the caller
	 * then supersedes:
	 *   - not OPEN, expired, or emptied: nothing to reprice.
	 *   - currency changed: immutable on a session, so it needs a new one.
	 *   - the API refuses: never leave the caller holding a session whose
	 *     total is a guess. Superseding is always safe.
	 *
	 * @param array    $session Session as the API returned it.
	 * @param WC_Order $order   Order it should pay for.
	 * @return array|null The repriced session, or null to supersede.
	 */
	private function reprice_session( array $session, WC_Order $order ): ?array {
		if ( XPay_Session_Status::OPEN !== ( $session['status'] ?? '' ) || ! empty( $session['isExpired'] ) || self::is_emptied( $session ) ) {
			return null;
		}

		// The merchant edited the Payment Methods tab while this shopper
		// held a live session: what the session ACCEPTS no longer matches
		// what the store offers. Supersede rather than PATCH the list onto
		// it — the same answer as a currency change, and always safe. An
		// unchecked method must become unchargeable on the very next
		// attempt, not whenever this session happens to die.
		if ( ! $this->methods_match( $session ) ) {
			XPay_Logger::event(
				'session.method_list_changed',
				array(
					'order_id'   => $order->get_id(),
					'session_id' => isset( $session['id'] ) ? (string) $session['id'] : '',
				)
			);
			return null;
		}

		$charge = XPay_Money::session_charge( $session );
		if ( null === $charge ) {
			return null;
		}

		$currency = strtoupper( $order->get_currency() );
		if ( $charge['currency'] !== $currency ) {
			// Currency is one of the fields the platform refuses on an
			// update, so this order needs a session of its own.
			return null;
		}

		$session_id = isset( $session['id'] ) ? (string) $session['id'] : '';
		if ( '' === $session_id ) {
			return null;
		}

		$total = XPay_Money::to_minor( $order->get_total(), $currency );
		$seq   = (int) $order->get_meta( XPay_Constants::META_REPRICE_SEQ ) + 1;

		try {
			$updated = $this->client->update_checkout_session(
				$session_id,
				array( 'lineItems' => self::line_items( $order, $total, $currency ) ),
				// Sequence + amount, never amount alone: an A -> B -> A -> B
				// cart would hand the second "B" the first one's key and
				// body, and the platform would replay the stored response
				// without re-applying — leaving the session at "A" while
				// the shopper sees "B". See META_REPRICE_SEQ.
				sprintf( 'wcprice_%d_n%d_%d', $order->get_id(), $seq, $total )
			);
		} catch ( XPay_Api_Exception $e ) {
			XPay_Logger::event(
				'session.reprice_failed',
				array(
					'order_id'   => $order->get_id(),
					'session_id' => $session_id,
					'code'       => $e->get_error_code(),
				)
			);
			return null;
		}

		XPay_Logger::event(
			'session.repriced',
			array(
				'order_id'   => $order->get_id(),
				'session_id' => $session_id,
				'amount'     => $total,
				'currency'   => $currency,
			)
		);

		$order->update_meta_data( XPay_Constants::META_REPRICE_SEQ, (string) $seq );
		$order->add_order_note(
			sprintf(
				/* translators: %s is the new order total with currency. */
				__( 'XPay payment session updated to %s after the order total changed. The shopper keeps the same payment session.', 'xpay-for-woocommerce' ),
				wc_price( $order->get_total(), array( 'currency' => $currency ) )
			)
		);
		$order->save();

		return $updated;
	}

	/**
	 * The ONE synthetic line this plugin sends, at the order's grand total.
	 *
	 * Never itemized: WooCommerce's rounding is authoritative for what the
	 * customer owes, and re-deriving the basket line by line risks a
	 * piaster of drift between the two systems on exactly the orders where
	 * it would be hardest to explain. Shared by create and reprice so the
	 * two can never disagree about the shape.
	 *
	 * @param WC_Order $order    Order being paid.
	 * @param int      $total    Grand total in minor units.
	 * @param string   $currency Uppercase currency code.
	 * @return array lineItems payload.
	 */
	private static function line_items( WC_Order $order, int $total, string $currency ): array {
		/*
		 * The platform stores unitAmount in an int4 column, so 2147483647
		 * minor units is the real ceiling (about EGP 21.4 million). Sending
		 * more does not fail cleanly — it is refused deep in the platform
		 * with an error no shopper could read. Refusing here turns it into
		 * the ordinary "could not be started" failure with the reason in
		 * the log and the order note. Salvaged from the cart-session
		 * machinery, where the amount rode as a line QUANTITY against the
		 * same column and met the same ceiling.
		 */
		if ( $total > 2147483647 ) {
			throw XPay_Api_Exception::from_api_response( array( 'code' => XPay_Error_Codes::AMOUNT_ABOVE_LINE_CEILING, 'message' => 'Order total exceeds the largest amount a checkout session line can carry' ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, Universal.Arrays.MixedKeyedUnkeyedArray.Found, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- constant registry-code message; render sites escape on output.
		}

		return array(
			array(
				'quantity'  => 1,
				'priceData' => array(
					'currency'    => $currency,
					'unitAmount'  => $total,
					'productData' => array(
						/* translators: %s is the order number shown on the XPay payment form. */
						'name' => sprintf( __( 'Order %s', 'xpay-for-woocommerce' ), $order->get_order_number() ),
					),
				),
			),
		);
	}

	/**
	 * @param array    $session Session object from the API.
	 * @param WC_Order $order   Order it should pay for.
	 */
	private function is_reusable( array $session, WC_Order $order ): bool {
		$status = isset( $session['status'] ) ? $session['status'] : '';
		// isExpired is authoritative per the API contract — never re-derive
		// expiry from expiresAt against local clock.
		$expired = ! empty( $session['isExpired'] );

		// Currency must match, not just the minor-unit total: a
		// multi-currency plugin can recalculate an order into a different
		// currency whose minor units happen to equal the old total, and a
		// matching number in the wrong currency is the worst kind of match.
		//
		// Both figures come from the shared helper: presentment first,
		// settlement second, per XPay_Money::session_charge().
		$charge = XPay_Money::session_charge( $session );
		if ( null === $charge ) {
			return false;
		}

		// An emptied session keeps its amount and its OPEN status, so every
		// test below passes on one the platform will refuse to charge. Mint
		// a fresh session instead of sending the shopper to a pay page that
		// reads "Your order is empty".
		if ( self::is_emptied( $session ) ) {
			XPay_Logger::error(
				'checkout.session_emptied',
				array(
					'order_id'   => $order->get_id(),
					'session_id' => isset( $session['id'] ) ? $session['id'] : '',
				)
			);
			return false;
		}

		return XPay_Session_Status::OPEN === $status
			&& ! $expired
			&& strtoupper( $order->get_currency() ) === $charge['currency']
			&& XPay_Money::to_minor( $order->get_total(), $order->get_currency() ) === $charge['amount']
			&& $this->methods_match( $session );
	}

	/**
	 * Does the session accept what the store currently offers?
	 *
	 * Compared as SETS: the platform resolves the accepted list in its own
	 * order and the array order is not meaningful. Fail open on shape,
	 * closed on value, the same discipline as the amount check — a
	 * response that does not state its paymentMethodTypes (or states them
	 * in a shape this plugin does not recognize) proves nothing and must
	 * not churn a good session; a stated list that differs blocks reuse.
	 *
	 * @param array $session Session as the API returned it.
	 */
	private function methods_match( array $session ): bool {
		if ( null === $this->accepted_types || ! isset( $session['paymentMethodTypes'] ) || ! is_array( $session['paymentMethodTypes'] ) ) {
			return true;
		}

		$stated = array();
		foreach ( $session['paymentMethodTypes'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['type'] ) || ! is_string( $row['type'] ) ) {
				return true; // A shape we do not recognize answers nothing.
			}
			$stated[] = $row['type'];
		}

		$offered = $this->accepted_types;
		sort( $stated );
		sort( $offered );
		return array_values( array_unique( $stated ) ) === array_values( array_unique( $offered ) );
	}

	/**
	 * @param WC_Order $order Order awaiting payment.
	 * @return array Newly created session.
	 * @throws XPay_Api_Exception
	 */
	private function create_session( WC_Order $order ): array {
		$attempt  = (int) $order->get_meta( XPay_Constants::META_ATTEMPT ) + 1;
		$currency = strtoupper( $order->get_currency() );
		if ( array() === $this->accepted_types ) {
			throw XPay_Api_Exception::payment_methods_unavailable();
		}

		// xpay_session_id is deliberately never read by plugin code: it puts
		// the session id into the shopper's URL bar and browser history, so
		// support can identify the exact session from a screenshot or a
		// pasted link when a shopper reports "paid but still pending".
		$return_url = add_query_arg(
			array( 'xpay_session_id' => '{CHECKOUT_SESSION_ID}' ),
			$order->get_checkout_order_received_url()
		);

		$body = array(
			/*
			 * custom, not hosted: the payment fields mount on the store's
			 * own checkout page, and this session exists only to be
			 * confirmed against. The platform enforces two rules for it —
			 * afterCompletion is required and must be a redirect (there is
			 * no XPay page to keep the customer on), and the custom-session
			 * contract does not accept cancelUrl.
			 */
			'uiMode'          => 'custom',
			'currency'        => $currency,
			'lineItems'       => self::line_items( $order, XPay_Money::to_minor( $order->get_total(), $currency ), $currency ),
			// Use the documented 24-hour session lifetime.
			'afterCompletion' => array(
				'type'     => 'redirect',
				'redirect' => array( 'url' => $return_url ),
			),
			// cancelUrl is deliberately absent: the platform refuses it on
			// a custom session, and there is nothing to cancel back to —
			// the shopper never leaves the store's checkout page.
			'locale'          => 0 === strpos( get_locale(), 'ar' ) ? 'ar' : 'en',
			'metadata'        => array(
				// Identifies the integration in session metadata.
				'integration'  => 'woocommerce',
				'wc_order_id'  => (string) $order->get_id(),
				'wc_order_key' => $order->get_order_key(),
				'site_url'     => home_url(),
			),
		);

		if ( null !== $this->accepted_types ) {
			// What this session may ACCEPT: the Payment Methods tab's
			// checked list for this currency. This is the enforcement half
			// of the tab — the rows only decide what the checkout SHOWS,
			// and without this a tampered page could still confirm an
			// unchecked method against the session.
			$body['paymentMethodTypes'] = $this->accepted_types;
		}

		$body = $this->apply_customer_fields( $body, $order );

		try {
			$session = $this->client->create_checkout_session(
				$body,
				// Order id + attempt: a transport retry of THIS attempt replays;
				// a deliberate new attempt (total changed, session expired) gets
				// a fresh key.
				sprintf( 'wc_%d_a%d', $order->get_id(), $attempt )
			);
		} catch ( XPay_Api_Exception $e ) {
			/*
			 * Two merchant-side configuration slips are recoverable here,
			 * and neither may be the shopper's dead end. Each falls back
			 * ONCE, under a suffixed idempotency key — the same key with a
			 * different body would be rejected as a fingerprint mismatch.
			 */
			if ( isset( $body['customerId'] ) && XPay_Error_Codes::API_RESOURCE_MISSING === $e->get_error_code() ) {
				// A stored customer link the platform no longer knows:
				// clear it so the next checkout re-creates, retry without.
				delete_user_meta( $order->get_user_id(), XPay_Constants::customer_user_meta_key( $this->client->is_live_mode() ) );
				XPay_Logger::event(
					'customer.stale_link_cleared',
					array(
						'order_id' => $order->get_id(),
						'user_id'  => $order->get_user_id(),
					)
				);
				unset( $body['customerId'] );
				$body    = $this->apply_customer_fields( $body, $order );
				$session = $this->client->create_checkout_session( $body, sprintf( 'wc_%d_a%dr', $order->get_id(), $attempt ) );
			} elseif ( isset( $body['paymentMethodTypes'] ) && 'paymentMethodTypes' === $e->get_param() ) {
				XPay_Logger::error(
					'session.method_list_rejected',
					array(
						'order_id' => $order->get_id(),
						'types'    => implode( ',', $body['paymentMethodTypes'] ),
						'code'     => $e->get_error_code(),
					)
				);
				if ( null === $this->refresh_accepted_types ) {
					throw $e;
				}
				$refreshed = call_user_func( $this->refresh_accepted_types, $currency );
				$refreshed = is_array( $refreshed ) ? array_values( array_unique( array_filter( $refreshed, 'is_string' ) ) ) : array();
				if ( array() === $refreshed ) {
					throw XPay_Api_Exception::payment_methods_unavailable();
				}
				$this->accepted_types       = $refreshed;
				$body['paymentMethodTypes'] = $refreshed;
				$session                    = $this->client->create_checkout_session( $body, sprintf( 'wc_%d_a%dm', $order->get_id(), $attempt ) );
			} else {
				throw $e;
			}
		}

		if ( empty( $session['id'] ) || empty( $session['clientSecret'] ) ) {
			throw XPay_Api_Exception::from_api_response( array( 'message' => 'Session response missing id or clientSecret' ), 502 );
		}

		$order->update_meta_data( XPay_Constants::META_SESSION_ID, (string) $session['id'] );
		$order->update_meta_data( XPay_Constants::META_CLIENT_SECRET, (string) $session['clientSecret'] );
		$order->update_meta_data( XPay_Constants::META_ATTEMPT, $attempt );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: XPay checkout session id, 2: attempt number. */
				__( 'XPay checkout session %1$s created (attempt %2$d).', 'xpay-for-woocommerce' ),
				(string) $session['id'],
				$attempt
			)
		);

		XPay_Logger::event(
			'session.created',
			array(
				'order_id'   => $order->get_id(),
				'session_id' => $session['id'],
				'attempt'    => $attempt,
				'live_mode'  => $this->client->is_live_mode(),
			)
		);

		return $session;
	}

	/**
	 * Customer linking. Three cases, mirroring the API's own contract
	 * (customerId is exclusive with customerDetails and with
	 * customerCreation=always — validated server-side):
	 *
	 *   1. Logged-in shopper with a stored XPay customer id for this mode
	 *      → send customerId only. Payments group under one customer in
	 *      the merchant's XPay dashboard, and fraud enrichment accumulates
	 *      on a stable identity.
	 *   2. Logged-in shopper without a stored id → customerDetails +
	 *      customerCreation=always; the id comes back with the paid
	 *      session and XPay_Order_Sync stores it for next time.
	 *   3. Guest → customerDetails only. The platform's default
	 *      (if_required + guest dedupe by email/phone fingerprint) already
	 *      handles guests correctly; forcing records for them would be
	 *      noise in the merchant's customer list.
	 *
	 * @param array    $body  Session payload under construction.
	 * @param WC_Order $order Order being paid.
	 * @return array Payload with customer fields applied.
	 */
	private function apply_customer_fields( array $body, WC_Order $order ): array {
		$user_id = $order->get_user_id();

		if ( $user_id > 0 && ! isset( $body['customerId'] ) ) {
			$stored = (string) get_user_meta( $user_id, XPay_Constants::customer_user_meta_key( $this->client->is_live_mode() ), true );
			if ( '' !== $stored && 0 === strpos( $stored, 'cus_' ) ) {
				$body['customerId'] = $stored;
				unset( $body['customerDetails'], $body['customerCreation'] );
				XPay_Logger::event(
					'customer.linked',
					array(
						'order_id'    => $order->get_id(),
						'user_id'     => $user_id,
						'customer_id' => $stored,
					)
				);
				return $body;
			}
		}

		$phone = (string) $order->get_billing_phone();

		$body['customerDetails'] = array_filter(
			array(
				'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email' => $order->get_billing_email(),
				'phone' => $phone,
			)
		);

		/*
		 * Send the billing address WooCommerce collected. Empty fields are
		 * dropped instead of being sent as blank values.
		 */
		$billing_address = self::address_fields(
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
			$order->get_billing_city(),
			$order->get_billing_state(),
			$order->get_billing_postcode(),
			$order->get_billing_country()
		);
		if ( array() !== $billing_address ) {
			$body['customerDetails']['billingDetails'] = array( 'address' => $billing_address );
		}

		// Send shipping details only when WooCommerce reports an address.
		if ( $order->has_shipping_address() ) {
			$shipping_address = self::address_fields(
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
				$order->get_shipping_city(),
				$order->get_shipping_state(),
				$order->get_shipping_postcode(),
				$order->get_shipping_country()
			);
			$shipping         = array_filter(
				array(
					'name'  => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
					'phone' => (string) $order->get_shipping_phone(),
				)
			);
			if ( array() !== $shipping_address ) {
				$shipping['address'] = $shipping_address;
			}
			if ( array() !== $shipping ) {
				$body['customerDetails']['shipping'] = $shipping;
			}
		}

		if ( $user_id > 0 ) {
			$body['customerCreation'] = 'always';
		}
		return $body;
	}

	/**
	 * An address in the Checkout Session API shape, with empty fields dropped.
	 *
	 * @param string $line1    Street address.
	 * @param string $line2    Apartment/suite.
	 * @param string $city     City.
	 * @param string $state    State/province, as WooCommerce stores it.
	 * @param string $postcode Postal code.
	 * @param string $country  Two-letter country code.
	 * @return array<string, string>
	 */
	private static function address_fields( $line1, $line2, $city, $state, $postcode, $country ): array {
		return array_filter(
			array(
				'line1'      => (string) $line1,
				'line2'      => (string) $line2,
				'city'       => (string) $city,
				'state'      => (string) $state,
				'postalCode' => (string) $postcode,
				'country'    => (string) $country,
			)
		);
	}
}
