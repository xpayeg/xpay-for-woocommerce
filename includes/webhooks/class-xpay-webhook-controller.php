<?php
/**
 * XPay_Webhook_Controller
 *
 * Public webhook receiver at ?wc-api=xpay_webhook.
 *
 * Trust boundary: raw internet. The HMAC signature (XPay_Signature) is the
 * only authentication; verification is fail-closed — no configured secret
 * means 500, never unverified-accept.
 *
 * HTTP status discipline drives XPay's retry engine:
 *   200 — event verified and applied, or verified and deliberately ignored.
 *   400 — request malformed (bad JSON, wrong shape). Caller's fault.
 *   401 — signature missing/invalid/stale. Caller's fault.
 *   404 — verified, but no order carries the event yet (delivery outran
 *         Place Order, or a refund outran its payment). Retry-worthy:
 *         XPay's engine redelivers and the race resolves itself. There is
 *         no local retry queue; the platform's engine is the only one.
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
			// Record before responding because respond() exits.
			self::record_rejection( $gateway, $header, $e->get_error_code() );
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
			XPay_Logger::error( 'webhook.rejected', array( 'code' => XPay_Error_Codes::WEBHOOK_PAYLOAD_MALFORMED ) );
			self::record_failure( $gateway, XPay_Error_Codes::WEBHOOK_PAYLOAD_MALFORMED );
			self::respond( 400, array( 'error' => XPay_Error_Codes::WEBHOOK_PAYLOAD_MALFORMED ) );
		}

		// Health heartbeat for the settings screen: only signature-verified,
		// well-formed events reach this line, so the record can't be
		// painted by unauthenticated probes. Independent of the logging
		// setting on purpose — health must stay truthful with logging off.
		// Stamped PER PLANE, keyed by the event's own livemode (which always
		// matches the secret that just verified it): a test event must never
		// paint the live health row green. A success outranking the last
		// failure is what turns a fixed secret's row green again, with no
		// dismiss button to find.
		XPay_Webhook_State::record_success( ! empty( $event['livemode'] ) );

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
			// No order yet: a delivery outran Place Order, or a refund
			// outran its payment. Non-2xx so XPay's retry engine
			// redelivers; NOT record_failure — a race is not a
			// misconfiguration, and the health row must not go red over
			// one. Already logged at ERROR inside apply_event.
			if ( XPay_Error_Codes::WEBHOOK_ORDER_NOT_FOUND === $e->get_error_code() ) {
				self::respond( 404, array( 'error' => $e->get_error_code() ) );
			}
			XPay_Logger::error(
				'webhook.apply_failed',
				array(
					'event_id' => $event_id,
					'code'     => $e->get_error_code(),
				)
			);
			self::record_failure( $gateway, $e->get_error_code() );
			self::respond( 500, array( 'error' => 'internal' ) );
		} catch ( Throwable $t ) {
			XPay_Logger::error(
				'webhook.apply_failed',
				array(
					'event_id' => $event_id,
					'error'    => get_class( $t ),
				)
			);
			self::record_failure( $gateway, XPay_Error_Codes::WEBHOOK_APPLY_FAILED );
			self::respond( 500, array( 'error' => 'internal' ) );
		}

		self::respond( 200, array( 'received' => true ) );
	}

	/**
	 * Report a refused delivery, but only when a delivery is what it was.
	 *
	 * The endpoint is public and has to stay public, so every scanner on
	 * the internet can reach it. Reporting every refusal meant a bare
	 * `curl -X POST '<store>/?wc-api=xpay_webhook' -d '{}'` wrote the
	 * failure option the settings screen renders as "Webhook failing"
	 * (class-xpay-settings-screen.php:494) AND an ERROR row that survives
	 * diagnostics being off (class-xpay-logger.php:103). Anyone who knew
	 * the URL could paint a merchant's screen red and fill their log.
	 *
	 * The line is the signature HEADER, not the verdict on it. XPay signs
	 * every delivery, so a request carrying no header at all is not a
	 * delivery and proves nothing about this store's configuration. A
	 * request that does carry one is XPay, or someone who already knows
	 * this plugin is here; either way the verdict is worth reporting. That
	 * keeps every genuine misconfiguration reportable, because all of them
	 * arrive signed: a wrong secret, a skewed clock, and a real delivery
	 * landing before any secret was saved.
	 *
	 * Parsing the header further would buy nothing. Anyone who knows the
	 * header's name can spell `t=…,v1=…` just as easily, and a stricter
	 * shape test would go silent the day XPay's signer changes format.
	 *
	 * Downgraded, not silenced: the probe still writes `webhook.rejected`,
	 * at the level the fact deserves, so a merchant who turns diagnostics
	 * on to investigate "nothing is arriving" still sees what did arrive.
	 *
	 * @param XPay_Gateway $gateway The gateway, for the configured plane.
	 * @param string       $header  Raw XPay-Signature header as received.
	 * @param string       $code    Error code from the rejection path.
	 */
	private static function record_rejection( XPay_Gateway $gateway, string $header, string $code ): void {
		// Same emptiness test the verifier itself uses for a missing header
		// (class-xpay-signature.php:43), so the two cannot drift apart.
		if ( '' === trim( $header ) ) {
			XPay_Logger::event(
				'webhook.rejected',
				array(
					'code'   => $code,
					'signed' => false,
				)
			);
			return;
		}

		XPay_Logger::error(
			'webhook.rejected',
			array(
				'code'   => $code,
				'signed' => true,
			)
		);
		self::record_failure( $gateway, $code );
	}

	/**
	 * Remember why the last delivery was refused, so the settings screen can
	 * say so.
	 *
	 * Recording the reason in plain English here rather than in the admin is
	 * deliberate: this is the only place that knows which of the rejection
	 * paths was taken, and a merchant reading "Webhook waiting for its first
	 * event" while every delivery is being rejected has been told the
	 * opposite of the truth.
	 *
	 * @param XPay_Gateway $gateway The gateway, for the configured plane.
	 * @param string       $code    Error code from the rejection path.
	 */
	private static function record_failure( XPay_Gateway $gateway, string $code ): void {
		XPay_Webhook_State::record_failure( ! $gateway->is_test_mode(), $code );
	}

	/** Event types whose data.object is session-scoped (a session, or a payment intent carrying its session id). */
	const SESSION_SCOPED = array(
		XPay_Event_Names::CHECKOUT_SESSION_COMPLETED,
		XPay_Event_Names::CHECKOUT_SESSION_EXPIRED,
		XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED,
		XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED,
		XPay_Event_Names::PAYMENT_INTENT_FAILED,
	);

	/**
	 * A session that expired without ever being paid, and no order to apply
	 * it to. In other words: a shopper who reached the payment fields and
	 * did not buy.
	 *
	 * Both halves are required, and both are read from what the platform
	 * SAID rather than inferred. `paymentStatus` must be present and must
	 * say unpaid: an absent field is unknown, and treating unknown as unpaid
	 * is how a real payment would get downgraded to routine. The caller has
	 * already established there is no order.
	 *
	 * Only the expired event qualifies. A completed session with no order is
	 * money with nothing attached to it, which is the case the alarm exists
	 * for.
	 *
	 * @param string $type    Event type.
	 * @param array  $payload data.object payload (a checkout session).
	 */
	private static function is_abandoned_cart( string $type, array $payload ): bool {
		if ( XPay_Event_Names::CHECKOUT_SESSION_EXPIRED !== $type ) {
			return false;
		}
		return isset( $payload['paymentStatus'] )
			&& XPay_Payment_Status::UNPAID === (string) $payload['paymentStatus'];
	}

	/**
	 * Route a verified event to its order transition.
	 *
	 * @param string $type     Event type.
	 * @param string $event_id Event id (evt_…), used for dedupe.
	 * @param array  $payload  data.object payload (a checkout session).
	 * @throws XPay_Api_Exception On ownership mismatch, a busy per-order lock, or an order not found yet.
	 */
	private static function apply_event( string $type, string $event_id, array $payload ): void {
		if ( ! in_array( $type, XPay_Event_Names::SUBSCRIBED, true ) ) {
			return; // Unknown/unsubscribed types are acknowledged, never errors — forward compatibility.
		}

		$order = self::locate_order( $type, $payload );
		if ( null === $order ) {
			/*
			 * Not found is not the same as never going to be found. A
			 * delivery can genuinely outrun the order it belongs to (the
			 * shopper's browser and XPay's webhook fleet race the same few
			 * hundred milliseconds), and the refund family shares the race
			 * one step later: its order is located by payment intent, and
			 * the ORDER side of that join is written only by mark_paid() and
			 * apply_superseded_paid(), so a dashboard refund taken seconds
			 * after a payment can land before any order carries the intent.
			 *
			 * Answered non-2xx so XPay retries delivery.
			 * webhook.order_not_found is the diagnostic the
			 * go-live and troubleshooting guides tell merchants to search
			 * for; it now fires once per delivery attempt.
			 *
			 * The one quiet exception: a session that expired UNPAID with no
			 * order is a shopper who reached the payment fields and did not
			 * buy — the ordinary outcome of a checkout, acknowledged with
			 * 200 so redelivering it cannot burn the retry schedule.
			 */
			if ( self::is_abandoned_cart( $type, $payload ) ) {
				XPay_Logger::event(
					'webhook.abandoned_cart_expired',
					array(
						'event_id'   => $event_id,
						'session_id' => self::event_session_id( $type, $payload ),
					)
				);
				return;
			}

			XPay_Logger::error(
				'webhook.order_not_found',
				array(
					'event_id'   => $event_id,
					'event_type' => $type,
					'session_id' => self::event_session_id( $type, $payload ),
				)
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constant message; the webhook response body carries only the stable code, never this text.
			throw XPay_Api_Exception::webhook( XPay_Error_Codes::WEBHOOK_ORDER_NOT_FOUND, 'No order carries this event yet' );
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
						// Routed, not marked paid: `completed` fires for a
						// deferred (Fawry) session at reference issuance
						// with paymentStatus `unpaid`, and money moves
						// later or never. apply_completed reads the field
						// and parks unpaid sessions awaiting payment.
						XPay_Order_Sync::apply_completed( $order, $payload, 'webhook' );
					} else {
						// Money on a session this order left behind: park it
						// for a human instead of dropping it silently.
						// Gates on paymentStatus itself — a superseded
						// completed-unpaid session moves no money and is
						// ignored there.
						XPay_Order_Sync::apply_superseded_paid( $order, $payload );
					}
					break;
				case XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_SUCCEEDED:
					if ( 'current' === $relation ) {
						// The deferred reference was paid: the session is
						// now COMPLETE + paid, and this is the money event
						// the earlier `completed` was not. mark_paid
						// completes from the awaiting on-hold park.
						XPay_Order_Sync::mark_paid( $order, $payload, 'webhook' );
					} else {
						// Money on a superseded session: same park-for-a-
						// human answer as a superseded `completed`.
						XPay_Order_Sync::apply_superseded_paid( $order, $payload );
					}
					break;
				case XPay_Event_Names::CHECKOUT_SESSION_ASYNC_PAYMENT_FAILED:
					if ( 'current' === $relation ) {
						XPay_Order_Sync::mark_async_failed( $order, $payload );
					} else {
						// A dead reference on a session this order already
						// left behind changes nothing about the order.
						XPay_Logger::event(
							'webhook.superseded_async_failed_ignored',
							array(
								'event_id' => $event_id,
								'order_id' => $order_id,
							)
						);
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

		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( $order instanceof WC_Order && XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) ) {
			return $order;
		}

		/*
		 * Second strategy: the session id itself.
		 *
		 * The metadata is the fast path and it is usually there, but it is
		 * not always: a session created before the order existed carries the
		 * order id only from the moment process_payment patches it on, and
		 * an event can be in flight across that moment. The plugin writes
		 * the session id onto the order as soon as it has one, so the order
		 * can be found from the session even when the session cannot yet
		 * name the order.
		 *
		 * One lookup, not a scan: same exact-match discipline as the refund
		 * path, and the ownership check below still has the last word.
		 */
		$session_id = self::event_session_id( $type, $payload );
		if ( '' === $session_id ) {
			return null;
		}

		$found = self::order_id_by_meta( XPay_Constants::META_SESSION_ID, $session_id );
		if ( 0 === $found ) {
			return null;
		}

		$order = wc_get_order( $found );
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

		$order_id = self::order_id_by_meta( XPay_Constants::META_PAYMENT_INTENT, $intent_id );
		if ( 0 === $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! XPay_Constants::is_xpay_gateway( (string) $order->get_payment_method() ) ) {
			return null;
		}

		/*
		 * Never act on the query alone. This is the control that makes a
		 * dropped or mis-built condition harmless instead of catastrophic:
		 * whatever the lookup handed back, the order itself has to be
		 * carrying the intent this event is about.
		 */
		if ( (string) $order->get_meta( XPay_Constants::META_PAYMENT_INTENT ) !== $intent_id ) {
			XPay_Logger::error(
				'webhook.intent_lookup_mismatch',
				array(
					'order_id' => $order_id,
					'intent'   => $intent_id,
				)
			);
			return null;
		}

		return $order;
	}

	/**
	 * The single order carrying a given meta value, or 0.
	 *
	 * Two code paths because WooCommerce has two order stores and only one
	 * of them can answer this question through the public API. On the
	 * legacy post store core puts `meta_query` on an explicit unsupported
	 * list, raises a doing_it_wrong notice that is invisible on a
	 * production site, and drops the condition
	 * (`class-wc-data-store-wp.php:284` skips the key outright). The query
	 * then degrades to "newest order in the shop", which for a refund event
	 * means refunding a stranger.
	 *
	 * Ambiguity is refused rather than guessed. Two orders claiming one
	 * payment is not a state this plugin creates, and if it ever arises the
	 * safe answer is to sync nothing and say so: an unsynced refund is
	 * visible in the XPay dashboard and fixable, a refund applied to the
	 * wrong shopper is neither.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Exact meta value to match.
	 * @return int Order id, or 0 when there is no unambiguous match.
	 */
	private static function order_id_by_meta( string $key, string $value ): int {
		$ids = array();

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$found = wc_get_orders(
				array(
					'limit'      => 2,
					'return'     => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded lookup on the plugin's own order meta; there is no other key a refund event can be correlated by.
					'meta_query' => array(
						array(
							'key'   => $key,
							'value' => $value,
						),
					),
				)
			);
			$ids   = is_array( $found ) ? array_map( 'absint', $found ) : array();
		} else {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the supported API cannot express this on the legacy store (see docblock); bounded to two rows on an indexed meta key, and webhook delivery is not a cached read path.
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					// DISTINCT, not just LIMIT 2: an order can legitimately
					// carry the same meta key twice (two writers that both
					// thought the order was unpaid did exactly that), and two
					// rows for ONE order would then fill the limit and hide a
					// genuine second order behind it — turning the ambiguity
					// refusal below into a silent wrong match on a refund.
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
					 WHERE p.post_type = 'shop_order' AND m.meta_key = %s AND m.meta_value = %s
					 ORDER BY p.ID DESC LIMIT 2",
					$key,
					$value
				)
			);
			$ids = is_array( $rows ) ? array_map( 'absint', $rows ) : array();
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( count( $ids ) > 1 ) {
			XPay_Logger::error(
				'webhook.ambiguous_order_lookup',
				array(
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- log context key, not a query argument.
					'meta_key' => $key,
					'orders'   => $ids,
				)
			);
			return 0;
		}

		return isset( $ids[0] ) ? (int) $ids[0] : 0;
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
	 *   'current'    — exactly the id the plugin stored. Full trust.
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
