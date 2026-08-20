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
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Checkout_Service {

	/** @var XPay_Api_Client */
	private $client;

	public function __construct( XPay_Api_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Return an OPEN session for this order, creating one if needed.
	 *
	 * @param WC_Order   $order        Order awaiting payment.
	 * @param array|null $pinned_types Restrict the session to these method
	 *                                 types (per-method rows); null = the
	 *                                 merchant's full method list.
	 * @return array Session object (id, url, clientSecret, …).
	 * @throws XPay_Api_Exception
	 */
	public function get_or_create_session( WC_Order $order, ?array $pinned_types = null ): array {
		$pin         = null === $pinned_types ? '' : XPay_Payment_Methods::normalize_pin( $pinned_types );
		$existing_id = (string) $order->get_meta( XPay_Constants::META_SESSION_ID );
		$stored_pin  = (string) $order->get_meta( XPay_Constants::META_METHOD_PIN );

		// A session is only reusable for the SAME restriction it was minted
		// with: the shopper who went back and picked the valU row must not
		// land in a session pinned to card. Compared locally (the pin is
		// stored at creation) — the API response cannot distinguish "pinned
		// to the full list" from "not pinned at all".
		if ( '' !== $existing_id && $stored_pin === $pin ) {
			try {
				$session = $this->client->get_checkout_session( $existing_id );
				if ( $this->is_reusable( $session, $order ) ) {
					$this->remember_brand_primary( $session );
					$this->stamp_checked( $order );
					return $session;
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

		$session = $this->create_session( $order, $pinned_types, $pin );
		$this->remember_brand_primary( $session );

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
			// Remembered UNCONDITIONALLY, before the expire attempt: even a
			// successful expire can lose the race with a payment finishing
			// right now, and the resulting paid event must be recognizable
			// as this order's money (webhook parks it on-hold) rather than
			// dropped as anonymous. Bounded: an order cycling sessions
			// cannot grow the list without limit.
			$superseded   = $order->get_meta( XPay_Constants::META_SUPERSEDED_SESSIONS );
			$superseded   = is_array( $superseded ) ? $superseded : array();
			$superseded[] = $existing_id;
			$order->update_meta_data( XPay_Constants::META_SUPERSEDED_SESSIONS, array_slice( array_unique( $superseded ), -10 ) );
			$order->save();

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
	 * Stamp the moment the stored session was last confirmed against the
	 * API. The pay page's short trust window reads this — see
	 * XPay_Constants::META_SESSION_CHECKED_AT.
	 *
	 * @param WC_Order $order Order whose session was just validated.
	 */
	private function stamp_checked( WC_Order $order ): void {
		$order->update_meta_data( XPay_Constants::META_SESSION_CHECKED_AT, time() );
		$order->save();
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
		$total   = isset( $session['amountTotal'] ) ? (int) $session['amountTotal'] : -1;

		// Currency must match, not just the minor-unit total: a
		// multi-currency plugin can recalculate an order into a different
		// currency whose minor units happen to equal the old total, and a
		// matching number in the wrong currency is the worst kind of match.
		$currency_matches = isset( $session['currency'] )
			&& strtoupper( (string) $session['currency'] ) === strtoupper( $order->get_currency() );

		return XPay_Session_Status::OPEN === $status
			&& ! $expired
			&& $currency_matches
			&& XPay_Money::to_minor( $order->get_total(), $order->get_currency() ) === $total;
	}

	/**
	 * @param WC_Order   $order        Order awaiting payment.
	 * @param array|null $pinned_types Method restriction, null for the full list.
	 * @param string     $pin          Normalized form of $pinned_types (stored on the order).
	 * @return array Newly created session.
	 * @throws XPay_Api_Exception
	 */
	private function create_session( WC_Order $order, ?array $pinned_types = null, string $pin = '' ): array {
		$attempt  = (int) $order->get_meta( XPay_Constants::META_ATTEMPT ) + 1;
		$currency = strtoupper( $order->get_currency() );

		// xpay_session_id is deliberately never read by plugin code: it puts
		// the session id into the shopper's URL bar and browser history, so
		// support can identify the exact session from a screenshot or a
		// pasted link when a shopper reports "paid but still pending".
		$return_url = add_query_arg(
			array( 'xpay_session_id' => '{CHECKOUT_SESSION_ID}' ),
			$order->get_checkout_order_received_url()
		);

		$body = array(
			'uiMode'          => 'hosted',
			'currency'        => $currency,
			'lineItems'       => array(
				array(
					'quantity'  => 1,
					'priceData' => array(
						'currency'    => $currency,
						'unitAmount'  => XPay_Money::to_minor( $order->get_total(), $currency ),
						'productData' => array(
							/* translators: %s is the order number shown on the XPay checkout page. */
							'name' => sprintf( __( 'Order %s', 'xpay-for-woocommerce' ), $order->get_order_number() ),
						),
					),
				),
			),
			'afterCompletion' => array(
				'type'     => 'redirect',
				'redirect' => array( 'url' => $return_url ),
			),
			'cancelUrl'       => $order->get_checkout_payment_url(),
			// The hosted checkout renders server-side in the session's
			// locale. Without this, only the modal (which receives the
			// locale directly) followed the storefront language — the
			// hosted fallback and emailed pay links, used exactly when
			// things go wrong, fell back to the account default.
			'locale'          => 0 === strpos( get_locale(), 'ar' ) ? 'ar' : 'en',
			'metadata'        => array(
				// Which integration created the session. Support reads it in
				// the Workbench today; it is also the forward-compatible data
				// source for a dashboard "integration" badge the moment the
				// platform starts reading a reserved metadata key or field.
				'integration'  => 'woocommerce',
				'wc_order_id'  => (string) $order->get_id(),
				'wc_order_key' => $order->get_order_key(),
				'site_url'     => home_url(),
			),
		);

		if ( null !== $pinned_types && array() !== $pinned_types ) {
			$body['paymentMethodTypes'] = array_values( $pinned_types );

			// valU pays with the shopper's registered wallet phone, so the
			// valU row asks the payment window to SHOW the phone field: it
			// arrives prefilled with the order's billing phone (sent in
			// customerDetails below) and stays editable, instead of the
			// platform default of charging with the carried phone invisibly
			// — where a mistyped WooCommerce phone would be uncorrectable.
			// The flag is session-wide, not per-method, which is why the
			// combined session deliberately doesn't set it: it would make
			// phone a required field for card shoppers too.
			if ( array( XPay_Payment_Methods::VALU ) === $body['paymentMethodTypes'] ) {
				$body['phoneNumberCollection'] = true;
			}
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
			// A pinned method the merchant's XPay account does not have is
			// the MERCHANT'S configuration slip, never the shopper's dead
			// end: fall back once to an unpinned session (the full XPay
			// window) and leave the merchant a notice + log trail. Suffixed
			// idempotency key — same key + different body would be rejected
			// as a fingerprint mismatch.
			if ( isset( $body['paymentMethodTypes'] ) && XPay_Error_Codes::API_PARAMETER_INVALID === $e->get_error_code() && 'paymentMethodTypes' === $e->get_param() ) {
				$this->record_pin_rejection( $order, $body['paymentMethodTypes'] );
				// The phone-collection flag rode on the valU pin; the
				// unpinned fallback session must not keep it.
				unset( $body['paymentMethodTypes'], $body['phoneNumberCollection'] );
				$pin     = '';
				$session = $this->client->create_checkout_session( $body, sprintf( 'wc_%d_a%dp', $order->get_id(), $attempt ) );
			} elseif ( isset( $body['customerId'] ) && XPay_Error_Codes::API_RESOURCE_MISSING === $e->get_error_code() ) {
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
			} else {
				// A currency the platform supports can still be rejected
				// for THIS merchant (no exchange rate configured on the
				// account). Every shopper fails identically until the
				// merchant acts, so the failure earns a standing admin
				// notice — the shopper-facing handling stays the generic
				// safe failure either way.
				if ( XPay_Error_Codes::API_EXCHANGE_RATE_NOT_FOUND === $e->get_error_code() ) {
					$this->record_fx_rejection( $order );
				}
				throw $e;
			}
		}

		if ( empty( $session['id'] ) || empty( $session['clientSecret'] ) ) {
			throw XPay_Api_Exception::from_api_response( array( 'message' => 'Session response missing id or clientSecret' ), 502 );
		}

		// The hosted URL is browser-bound — allowlist-check it before we
		// ever hand it to a redirect (esc_url alone doesn't pin the host).
		if ( ! empty( $session['url'] ) && ! XPay_Constants::is_allowed_xpay_url( (string) $session['url'] ) ) {
			throw XPay_Api_Exception::untrusted_url();
		}

		$order->update_meta_data( XPay_Constants::META_SESSION_ID, (string) $session['id'] );
		$order->update_meta_data( XPay_Constants::META_METHOD_PIN, $pin );
		$order->update_meta_data( XPay_Constants::META_CLIENT_SECRET, (string) $session['clientSecret'] );
		if ( ! empty( $session['url'] ) ) {
			// Persisted (already allowlist-checked above) so the hosted
			// fallback always points at the deployment that minted the
			// session — a rebuilt production URL is wrong under a staging
			// override.
			$order->update_meta_data( XPay_Constants::META_SESSION_URL, (string) $session['url'] );
		}
		$order->update_meta_data( XPay_Constants::META_ATTEMPT, $attempt );
		$order->update_meta_data( XPay_Constants::META_SESSION_CHECKED_AT, time() );
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

		// A session in this currency just succeeded — any standing
		// currency-rejection notice is stale. One cached option read
		// when nothing is flagged.
		if ( false !== get_option( XPay_Constants::OPTION_FX_REJECTED ) ) {
			delete_option( XPay_Constants::OPTION_FX_REJECTED );
		}

		return $session;
	}

	/**
	 * Remember that the API refused the store currency for this merchant's
	 * account, so admin can say so until it is fixed. Overwritten, never
	 * stacked: one store has one currency at a time.
	 *
	 * @param WC_Order $order Order whose session creation hit the rejection.
	 */
	private function record_fx_rejection( WC_Order $order ): void {
		$currency = strtoupper( $order->get_currency() );
		XPay_Logger::event(
			'session.currency_rejected',
			array(
				'order_id' => $order->get_id(),
				'currency' => $currency,
			)
		);
		update_option(
			XPay_Constants::OPTION_FX_REJECTED,
			array(
				'currency' => $currency,
				'at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);
	}

	/**
	 * Standing admin notice for a per-merchant currency rejection.
	 * Rendered on admin_notices; cleared by a settings save or the next
	 * successful session.
	 */
	public static function render_currency_rejected_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$rejected = get_option( XPay_Constants::OPTION_FX_REJECTED );
		if ( ! is_array( $rejected ) || empty( $rejected['currency'] ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s is the rejected currency code (for example "USD"). */
				__( 'XPay: a payment in %s was rejected because your XPay account has no exchange rate configured for it. Every checkout will fail the same way until this is fixed. Contact XPay support to enable the currency, or change your store currency.', 'xpay-for-woocommerce' ),
				(string) $rejected['currency']
			)
		);
		echo '</p></div>';
	}

	/**
	 * Snapshot the merchant's primary brand color from the session response
	 * so the pay page's stage matches the XPay window that opens over it.
	 * The API resolves the merchant's XPay dashboard branding into every
	 * session, which makes this a free sync: change the color in the
	 * dashboard, and the next session repaints the pay page too. An
	 * unbranded response clears the snapshot so the page returns to the
	 * XPay-indigo fallback on its own.
	 *
	 * @param array $session Session object from the API.
	 */
	private function remember_brand_primary( array $session ): void {
		$primary = XPay_Branding::primary_from_session( $session );
		$stored  = (string) get_option( XPay_Constants::OPTION_BRAND_PRIMARY, '' );
		if ( $primary === $stored ) {
			return;
		}
		if ( '' === $primary ) {
			delete_option( XPay_Constants::OPTION_BRAND_PRIMARY );
			return;
		}
		// autoload false: the value is read only on the order-pay page.
		update_option( XPay_Constants::OPTION_BRAND_PRIMARY, $primary, false );
	}

	/**
	 * Remember that the API refused a method pin, so admin can tell the
	 * merchant. Keyed by type: repeated rejections do not stack notices.
	 *
	 * @param WC_Order $order Order whose session creation hit the rejection.
	 * @param array    $types The pinned types the API refused.
	 */
	private function record_pin_rejection( WC_Order $order, array $types ): void {
		XPay_Logger::event(
			'session.method_pin_rejected',
			array(
				'order_id' => $order->get_id(),
				'types'    => $types,
			)
		);
		$rejected = get_option( XPay_Constants::OPTION_PIN_REJECTED, array() );
		$rejected = is_array( $rejected ) ? $rejected : array();
		foreach ( $types as $type ) {
			$rejected[ (string) $type ] = gmdate( 'Y-m-d H:i:s' );
		}
		update_option( XPay_Constants::OPTION_PIN_REJECTED, $rejected, false );
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

		// A valU shopper may have confirmed a different number for their
		// wallet than the one they gave for delivery. That confirmation is
		// the number the payment spends, so it outranks the billing field
		// here, and only here: the order keeps the phone the shopper
		// entered. See XPay_Constants::META_WALLET_PHONE.
		$wallet = (string) $order->get_meta( XPay_Constants::META_WALLET_PHONE );
		$phone  = '' !== $wallet ? $wallet : (string) $order->get_billing_phone();

		$body['customerDetails'] = array_filter(
			array(
				'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email' => $order->get_billing_email(),
				'phone' => $phone,
			)
		);
		if ( $user_id > 0 ) {
			$body['customerCreation'] = 'always';
		}
		return $body;
	}
}
