<?php
/**
 * XPay_Webhook_Controller
 *
 * Public webhook receiver at ?wc-api=xpay_webhook.
 *
 * Trust boundary: raw internet. The HMAC signature (XPay_Signature) is the
 * only authentication; verification is fail-closed — no configured secret
 * means 500 (our config fault, and XPay's ~3-day retry window keeps
 * redelivering until the merchant finishes setup), never unverified-accept.
 *
 * HTTP status discipline (drives XPay's retry engine, and mirrors the
 * severity rule in the monorepo's logging doc):
 *   200 — event verified and applied, or verified and deliberately ignored.
 *   400 — request malformed (bad JSON, wrong shape). Caller's fault.
 *   401 — signature missing/invalid/stale. Caller's fault.
 *   500 — plugin's own config/code fault. Retry-worthy.
 *
 * Event dedupe: processed event ids are stored on the order (bounded list),
 * so a redelivered event is acknowledged without reapplying side effects.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Webhook_Controller {

	/** Max processed-event ids remembered per order (dedupe window). */
	const PROCESSED_EVENTS_KEPT = 20;

	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-to-server endpoint; the HMAC signature below is the authentication, a WP nonce cannot apply here.
		$raw_body = file_get_contents( 'php://input' );
		$raw_body = is_string( $raw_body ) ? $raw_body : '';
		$header   = isset( $_SERVER['HTTP_XPAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_XPAY_SIGNATURE'] ) ) : '';

		$gateway = XPay_Plugin::instance()->gateway();
		$secret  = $gateway->webhook_secret();

		try {
			XPay_Signature::verify( $header, $raw_body, $secret );
		} catch ( XPay_Api_Exception $e ) {
			if ( XPay_Error_Codes::WEBHOOK_NOT_CONFIGURED === $e->get_error_code() ) {
				// Our fault (5xx): merchant hasn't stored the secret yet.
				// Answering 4xx here would make XPay's alerting treat a
				// misconfigured plugin as the sender's problem.
				self::respond( 500, array( 'error' => $e->get_error_code() ) );
			}
			XPay_Logger::event( 'webhook.rejected', array( 'code' => $e->get_error_code() ) );
			self::respond( 401, array( 'error' => $e->get_error_code() ) );
		}

		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) || ! isset( $event['data']['object'] ) || ! is_array( $event['data']['object'] ) ) {
			self::respond( 400, array( 'error' => XPay_Error_Codes::WEBHOOK_PAYLOAD_MALFORMED ) );
		}

		$event_id = isset( $event['id'] ) && is_string( $event['id'] ) ? $event['id'] : '';
		XPay_Logger::event(
			'webhook.received',
			array(
				'event_id'   => $event_id,
				'event_type' => $event['type'],
				'livemode'   => ! empty( $event['livemode'] ),
			)
		);

		try {
			self::apply_event( (string) $event['type'], $event_id, $event['data']['object'] );
		} catch ( XPay_Api_Exception $e ) {
			// Ownership mismatch is an attack or cross-site delivery — the
			// event is acknowledged (200) but nothing was applied; retrying
			// it will never succeed and must not alarm XPay's retry engine.
			if ( XPay_Error_Codes::ORDER_MISMATCH === $e->get_error_code() ) {
				XPay_Logger::event( 'webhook.ownership_mismatch', array( 'event_id' => $event_id ) );
				self::respond(
					200,
					array(
						'received' => true,
						'applied'  => false,
					)
				);
			}
			XPay_Logger::event(
				'webhook.apply_failed',
				array(
					'event_id' => $event_id,
					'code'     => $e->get_error_code(),
				)
			);
			self::respond( 500, array( 'error' => 'internal' ) );
		} catch ( Throwable $t ) {
			XPay_Logger::event(
				'webhook.apply_failed',
				array(
					'event_id' => $event_id,
					'error'    => get_class( $t ),
				)
			);
			self::respond( 500, array( 'error' => 'internal' ) );
		}

		self::respond( 200, array( 'received' => true ) );
	}

	/**
	 * Route a verified event to its order transition.
	 *
	 * @param string $type     Event type.
	 * @param string $event_id Event id (evt_…), used for dedupe.
	 * @param array  $session_object data.object payload (a checkout session).
	 * @throws XPay_Api_Exception On ownership mismatch.
	 */
	private static function apply_event( string $type, string $event_id, array $session_object ): void {
		if ( ! in_array( $type, XPay_Event_Names::SUBSCRIBED, true ) ) {
			return; // Unknown/unsubscribed types are acknowledged, never errors — forward compatibility.
		}

		$order = self::resolve_order( $session_object );
		if ( null === $order ) {
			// Order deleted or foreign event: acknowledged and ignored.
			// 404-ing would burn XPay's 3-day retry schedule on a permanent state.
			XPay_Logger::event( 'webhook.order_not_found', array( 'event_id' => $event_id ) );
			return;
		}

		if ( '' !== $event_id && self::already_processed( $order, $event_id ) ) {
			return;
		}

		switch ( $type ) {
			case XPay_Event_Names::CHECKOUT_SESSION_COMPLETED:
				XPay_Order_Sync::mark_paid( $order, $session_object, 'webhook' );
				break;
			case XPay_Event_Names::CHECKOUT_SESSION_EXPIRED:
				XPay_Order_Sync::mark_expired( $order );
				break;
		}

		if ( '' !== $event_id ) {
			self::remember_processed( $order, $event_id );
		}
	}

	/**
	 * Locate the order via metadata.wc_order_id, then enforce ownership:
	 * the event's session id must equal the session id stored on the order.
	 *
	 * @param array $session Session object from the event.
	 * @return WC_Order|null Null when no such order exists.
	 * @throws XPay_Api_Exception When the order exists but the session doesn't match it.
	 */
	private static function resolve_order( array $session ): ?WC_Order {
		$order_id = isset( $session['metadata']['wc_order_id'] ) ? absint( $session['metadata']['wc_order_id'] ) : 0;
		if ( 0 === $order_id ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || XPay_Constants::GATEWAY_ID !== $order->get_payment_method() ) {
			return null;
		}

		$stored   = trim( (string) $order->get_meta( XPay_Constants::META_SESSION_ID ) );
		$incoming = isset( $session['id'] ) ? trim( (string) $session['id'] ) : '';

		// IDOR guard: without this, anyone who completed ANY session could
		// craft metadata pointing at ANY order. Exact match against the id
		// we stored is the load-bearing control (v2's check, preserved).
		if ( '' === $stored || '' === $incoming || ! hash_equals( $stored, $incoming ) ) {
			throw XPay_Api_Exception::webhook( XPay_Error_Codes::ORDER_MISMATCH, 'Session does not belong to this order' );
		}

		return $order;
	}

	private static function already_processed( WC_Order $order, string $event_id ): bool {
		$processed = $order->get_meta( XPay_Constants::META_PROCESSED_EVENTS );
		return is_array( $processed ) && in_array( $event_id, $processed, true );
	}

	private static function remember_processed( WC_Order $order, string $event_id ): void {
		$processed   = $order->get_meta( XPay_Constants::META_PROCESSED_EVENTS );
		$processed   = is_array( $processed ) ? $processed : array();
		$processed[] = $event_id;
		$order->update_meta_data( XPay_Constants::META_PROCESSED_EVENTS, array_slice( $processed, -1 * self::PROCESSED_EVENTS_KEPT ) );
		$order->save();
	}

	/**
	 * Emit a JSON response and terminate. Never returns.
	 *
	 * @param int   $status HTTP status.
	 * @param array $body   JSON body.
	 */
	private static function respond( int $status, array $body ): void {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- machine-readable JSON for XPay's delivery engine; wp_json_encode is the correct encoder and esc_html would corrupt the payload. $body is plugin-built, never request data.
		echo wp_json_encode( $body );
		exit;
	}
}
