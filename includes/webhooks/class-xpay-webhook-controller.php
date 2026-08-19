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
			// Log BEFORE responding: respond() exits, and the not-configured
			// rejection is exactly the one docs/WEBHOOKS.md tells merchants
			// to look for in the log.
			XPay_Logger::event( 'webhook.rejected', array( 'code' => $e->get_error_code() ) );
			if ( XPay_Error_Codes::WEBHOOK_NOT_CONFIGURED === $e->get_error_code() ) {
				// Our fault (5xx): merchant hasn't stored the secret yet.
				// Answering 4xx here would make XPay's alerting treat a
				// misconfigured plugin as the sender's problem.
				self::respond( 500, array( 'error' => $e->get_error_code() ) );
			}
			self::respond( 401, array( 'error' => $e->get_error_code() ) );
		}

		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) || ! is_string( $event['type'] ) || ! isset( $event['data']['object'] ) || ! is_array( $event['data']['object'] ) ) {
			self::respond( 400, array( 'error' => XPay_Error_Codes::WEBHOOK_PAYLOAD_MALFORMED ) );
		}

		// Health heartbeat for the settings screen: only signature-verified,
		// well-formed events reach this line, so the timestamp can't be
		// painted by unauthenticated probes. Independent of the logging
		// setting on purpose — health must stay truthful with logging off.
		// Stamped PER PLANE, keyed by the event's own livemode (which always
		// matches the secret that just verified it): a test event must never
		// paint the live health row green.
		update_option( XPay_Constants::last_webhook_option( ! empty( $event['livemode'] ) ), time(), false );

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
	 * @throws XPay_Api_Exception On ownership mismatch, or when the per-order lock stays busy.
	 */
	/** Event types whose data.object is session-scoped (a session, or a payment intent carrying its session id). */
	const SESSION_SCOPED = array(
		XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
		XPay_Event_Names::CHECKOUT_SESSION_EXPIRED,
		XPay_Event_Names::PAYMENT_INTENT_FAILED,
	);

	private static function apply_event( string $type, string $event_id, array $payload ): void {
		if ( ! in_array( $type, XPay_Event_Names::SUBSCRIBED, true ) ) {
			return; // Unknown/unsubscribed types are acknowledged, never errors — forward compatibility.
		}

		$order = self::locate_order( $type, $payload );
		if ( null === $order ) {
			// Order deleted or foreign event: acknowledged and ignored.
			// 404-ing would burn XPay's 3-day retry schedule on a permanent state.
			XPay_Logger::event( 'webhook.order_not_found', array( 'event_id' => $event_id ) );
			return;
		}

		// Cheap rejection BEFORE the lock: a genuinely foreign session id
		// (the IDOR case) never earns a lock acquisition. The refund events
		// carry no metadata to forge — their order WAS located by exact
		// payment-intent match, which is the same control.
		if ( in_array( $type, self::SESSION_SCOPED, true ) && 'foreign' === self::session_relation( $order, self::event_session_id( $type, $payload ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constant message; the webhook response body carries only the stable code, never this text.
			throw XPay_Api_Exception::webhook( XPay_Error_Codes::ORDER_MISMATCH, 'Session does not belong to this order' );
		}

		// One writer per order: dedupe check → transition → processed-list
		// save is a read-modify-write. Two concurrent deliveries (or a
		// delivery racing the thank-you check) would otherwise both pass the
		// dedupe and both apply side effects.
		$order_id = $order->get_id();
		if ( ! XPay_Order_Lock::acquire( $order_id, XPay_Order_Lock::WAIT_SECONDS ) ) {
			// Surfaces as a 500 → XPay's retry engine redelivers after the
			// current holder finishes; nothing is lost.
			throw XPay_Api_Exception::order_lock_busy();
		}

		try {
			// Re-read inside the lock: the pre-lock snapshot may predate the
			// previous holder's save.
			$order = XPay_Order_Sync::reload( $order_id );
			if ( null === $order ) {
				return;
			}

			if ( '' !== $event_id && self::already_processed( $order, $event_id ) ) {
				return;
			}

			// Re-evaluated on the reloaded order: a concurrent supersede may
			// have moved the stored id between the pre-lock check and here.
			$relation = 'current';
			if ( in_array( $type, self::SESSION_SCOPED, true ) ) {
				$relation = self::session_relation( $order, self::event_session_id( $type, $payload ) );
				if ( 'foreign' === $relation ) {
					XPay_Logger::event( 'webhook.relation_changed_in_lock', array( 'event_id' => $event_id ) );
					return;
				}
			}

			switch ( $type ) {
				case XPay_Event_Names::CHECKOUT_SESSION_COMPLETED:
					if ( 'current' === $relation ) {
						XPay_Order_Sync::mark_paid( $order, $payload, 'webhook' );
					} else {
						// Money on a session this order left behind: park it
						// for a human instead of dropping it silently.
						XPay_Order_Sync::apply_superseded_paid( $order, $payload );
					}
					break;
				case XPay_Event_Names::CHECKOUT_SESSION_EXPIRED:
					if ( 'current' === $relation ) {
						XPay_Order_Sync::mark_expired( $order, $payload );
					} else {
						// The expected end of a superseded session — never a
						// reason to touch the order's current state.
						XPay_Logger::event(
							'webhook.superseded_expired_ignored',
							array(
								'event_id' => $event_id,
								'order_id' => $order_id,
							)
						);
					}
					break;
				case XPay_Event_Names::PAYMENT_INTENT_FAILED:
					// Both 'current' and 'superseded' are this order's own
					// attempts — a decline is order history either way.
					XPay_Order_Sync::note_payment_failed( $order, $payload );
					break;
				case XPay_Event_Names::CHARGE_REFUNDED:
					XPay_Refund_Service::mirror_charge_refunds( $order, $payload );
					break;
				case XPay_Event_Names::REFUND_FAILED:
					XPay_Refund_Service::note_refund_failed( $order, $payload );
					break;
			}

			if ( '' !== $event_id ) {
				self::remember_processed( $order, $event_id );
			}
		} finally {
			XPay_Order_Lock::release( $order_id );
		}
	}

	/**
	 * Locate the order for an event, by object family. Ownership is NOT
	 * decided here for session-scoped events — session_relation() answers
	 * that separately, because a superseded id and a foreign id deserve
	 * different fates.
	 *
	 * @param string $type   Event type (decides the object family).
	 * @param array  $payload data.object payload.
	 * @return WC_Order|null Null when no such order exists.
	 */
	private static function locate_order( string $type, array $payload ): ?WC_Order {
		if ( XPay_Event_Names::CHARGE_REFUNDED === $type || XPay_Event_Names::REFUND_FAILED === $type ) {
			return self::order_by_payment_intent( $payload );
		}

		// Sessions carry the plugin's own metadata; the failed-payment
		// event's payment intent carries a copy of it (snapshotted at the
		// shopper's first submit), with the nested session as fallback.
		$order_id = isset( $payload['metadata']['wc_order_id'] ) ? absint( $payload['metadata']['wc_order_id'] ) : 0;
		if ( 0 === $order_id && isset( $payload['checkoutSession']['metadata']['wc_order_id'] ) ) {
			$order_id = absint( $payload['checkoutSession']['metadata']['wc_order_id'] );
		}
		if ( 0 === $order_id ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * Refund-family events carry no metadata at all — the charge/refund
	 * object's paymentIntentId is the only correlation channel. The exact
	 * match against the intent id the plugin recorded at payment time IS
	 * the ownership check: events are signature-verified as this
	 * merchant's, and an intent that matches no order applies nowhere.
	 *
	 * @param array $payload Charge or refund payload.
	 */
	private static function order_by_payment_intent( array $payload ): ?WC_Order {
		$intent_id = isset( $payload['paymentIntentId'] ) && is_string( $payload['paymentIntentId'] ) ? trim( $payload['paymentIntentId'] ) : '';
		if ( '' === $intent_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one bounded lookup (limit 1) on the plugin's own indexed order meta; there is no other key a refund event can be correlated by.
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_query' => array(
					array(
						'key'   => XPay_Constants::META_PAYMENT_INTENT,
						'value' => $intent_id,
					),
				),
			)
		);
		$order  = is_array( $orders ) && isset( $orders[0] ) ? $orders[0] : null;
		if ( ! $order instanceof WC_Order || ! XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * The session id a session-scoped event speaks for: the session's own
	 * id, or the payment intent's checkoutSessionId.
	 *
	 * @param string $type   Event type.
	 * @param array  $payload data.object payload.
	 */
	private static function event_session_id( string $type, array $payload ): string {
		if ( XPay_Event_Names::PAYMENT_INTENT_FAILED === $type ) {
			if ( isset( $payload['checkoutSessionId'] ) && is_string( $payload['checkoutSessionId'] ) ) {
				return trim( $payload['checkoutSessionId'] );
			}
			if ( isset( $payload['checkoutSession']['id'] ) && is_string( $payload['checkoutSession']['id'] ) ) {
				return trim( $payload['checkoutSession']['id'] );
			}
			return '';
		}
		return isset( $payload['id'] ) ? trim( (string) $payload['id'] ) : '';
	}

	/**
	 * How the event's session id relates to this order:
	 *
	 *   'current'    — exactly the id the plugin stored (the IDOR guard's
	 *                  match, v2's check preserved). Full trust.
	 *   'superseded' — an id from this order's own superseded ledger:
	 *                  provably ours, but for an outdated session.
	 *   'foreign'    — neither. Without the exact-match control, anyone
	 *                  who completed ANY session could craft metadata
	 *                  pointing at ANY order.
	 *
	 * @param WC_Order $order    Candidate order from locate_order().
	 * @param string   $incoming Session id the event speaks for.
	 */
	private static function session_relation( WC_Order $order, string $incoming ): string {
		$stored = trim( (string) $order->get_meta( XPay_Constants::META_SESSION_ID ) );
		if ( '' === $stored || '' === $incoming ) {
			return 'foreign';
		}
		if ( hash_equals( $stored, $incoming ) ) {
			return 'current';
		}
		$superseded = $order->get_meta( XPay_Constants::META_SUPERSEDED_SESSIONS );
		if ( is_array( $superseded ) && in_array( $incoming, $superseded, true ) ) {
			return 'superseded';
		}
		return 'foreign';
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
