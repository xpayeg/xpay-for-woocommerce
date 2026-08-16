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
				// A missing/broken previous session is not fatal — we mint a
				// fresh one below. Anything else (auth, transport) must
				// surface: retrying with a new session won't fix a bad key.
				if ( XPay_Error_Codes::API_RESOURCE_MISSING !== $e->get_error_code() && 0 !== $e->get_http_status() && 404 !== $e->get_http_status() ) {
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
			'customerDetails' => array_filter(
				array(
					'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'email' => $order->get_billing_email(),
					'phone' => $order->get_billing_phone(),
				)
			),
			'metadata'        => array(
				'wc_order_id'  => (string) $order->get_id(),
				'wc_order_key' => $order->get_order_key(),
				'site_url'     => home_url(),
			),
		);

		$session = $this->client->create_checkout_session(
			$body,
			// Order id + attempt: a transport retry of THIS attempt replays;
			// a deliberate new attempt (total changed, session expired) gets
			// a fresh key.
			sprintf( 'wc_%d_a%d', $order->get_id(), $attempt )
		);

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
}
