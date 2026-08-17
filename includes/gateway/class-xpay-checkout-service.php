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
	 * @param WC_Order $order Order awaiting payment.
	 * @return array Session object (id, url, clientSecret, …).
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

		return $this->create_session( $order );
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

		return XPay_Session_Status::OPEN === $status
			&& ! $expired
			&& XPay_Money::to_minor( $order->get_total(), $order->get_currency() ) === $total;
	}

	/**
	 * @param WC_Order $order Order awaiting payment.
	 * @return array Newly created session.
	 * @throws XPay_Api_Exception
	 */
	private function create_session( WC_Order $order ): array {
		$attempt  = (int) $order->get_meta( XPay_Constants::META_ATTEMPT ) + 1;
		$currency = strtoupper( $order->get_currency() );

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
			'metadata'        => array(
				'wc_order_id'  => (string) $order->get_id(),
				'wc_order_key' => $order->get_order_key(),
				'site_url'     => home_url(),
			),
		);

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
			// A stored customer id can go stale (deleted in the XPay
			// dashboard). Recover instead of blocking checkout: drop the
			// dead link and retry once as a fresh customer. The retry uses a
			// suffixed idempotency key — same key + different body would be
			// rejected as a fingerprint mismatch.
			if ( isset( $body['customerId'] ) && XPay_Error_Codes::API_RESOURCE_MISSING === $e->get_error_code() ) {
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
		$order->update_meta_data( XPay_Constants::META_CLIENT_SECRET, (string) $session['clientSecret'] );
		if ( ! empty( $session['url'] ) ) {
			// Persisted (already allowlist-checked above) so the hosted
			// fallback always points at the deployment that minted the
			// session — a rebuilt production URL is wrong under a staging
			// override.
			$order->update_meta_data( XPay_Constants::META_SESSION_URL, (string) $session['url'] );
		}
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

		$body['customerDetails'] = array_filter(
			array(
				'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
			)
		);
		if ( $user_id > 0 ) {
			$body['customerCreation'] = 'always';
		}
		return $body;
	}
}
