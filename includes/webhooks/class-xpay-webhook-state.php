<?php
/**
 * XPay_Webhook_State
 *
 * The webhook health record the settings screen reads: when monitoring
 * began, the last delivery that applied, the last one that was refused and
 * why — per plane, because test and live are separate XPay endpoints with
 * separate secrets and separate truths.
 *
 * The shape is Stripe's webhook-state model (WC_Stripe_Webhook_State),
 * adopted deliberately: two timestamps and a reason code answer every
 * question the screen asks, and their ORDER is the verdict. Most recent
 * event succeeded = healthy; nothing yet = waiting since monitoring began;
 * failure after a success = something broke; failure with no success ever
 * = it never worked. One improvement over Stripe's: every method takes the
 * plane explicitly instead of reading a global mode, so the receiver can
 * record against the plane the EVENT belongs to while the screen reads the
 * plane the merchant is looking at.
 *
 * Writers: the webhook receiver (success on every verified, well-formed
 * delivery; failure on every refusal or apply error) and the configurator
 * (clear_state when an endpoint is created or decommissioned, so the
 * record always describes the CURRENT endpoint).
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Webhook_State {

	/**
	 * When monitoring began, lazily stamped on first read so "no events
	 * since <date>" always has a date — Stripe's exact behavior.
	 *
	 * @param bool $live_mode Which plane.
	 * @return int UTC seconds.
	 */
	public static function monitoring_began_at( bool $live_mode ): int {
		$option = self::option( $live_mode, 'monitor_began_at' );
		$began  = (int) get_option( $option, 0 );
		if ( 0 === $began ) {
			$began = time();
			update_option( $option, $began, false );
		}
		return $began;
	}

	/**
	 * A verified, well-formed delivery was applied (or deliberately
	 * ignored). Keyed by the EVENT's own livemode, which always matches the
	 * secret that verified it.
	 *
	 * @param bool $live_mode Which plane the event belongs to.
	 */
	public static function record_success( bool $live_mode ): void {
		self::monitoring_began_at( $live_mode );
		update_option( self::option( $live_mode, 'last_success_at' ), time(), false );
	}

	/**
	 * A delivery was refused or could not be applied.
	 *
	 * @param bool   $live_mode Which plane (the CONFIGURED plane for
	 *                          rejections — an unverified body's own claim
	 *                          is not to be trusted).
	 * @param string $code      Error code from the rejection path.
	 */
	public static function record_failure( bool $live_mode, string $code ): void {
		self::monitoring_began_at( $live_mode );
		update_option( self::option( $live_mode, 'last_failure_at' ), time(), false );
		update_option( self::option( $live_mode, 'last_error' ), $code, false );
	}

	/** @param bool $live_mode Which plane. */
	public static function last_success_at( bool $live_mode ): int {
		return (int) get_option( self::option( $live_mode, 'last_success_at' ), 0 );
	}

	/** @param bool $live_mode Which plane. */
	public static function last_failure_at( bool $live_mode ): int {
		return (int) get_option( self::option( $live_mode, 'last_failure_at' ), 0 );
	}

	/** @param bool $live_mode Which plane. */
	public static function last_error_code( bool $live_mode ): string {
		return (string) get_option( self::option( $live_mode, 'last_error' ), '' );
	}

	/**
	 * Forget a plane's record. Called when its endpoint is created or
	 * decommissioned: the record must describe the CURRENT endpoint, and a
	 * failure that belonged to the old secret would paint the new one red.
	 *
	 * @param bool $live_mode Which plane.
	 */
	public static function clear_state( bool $live_mode ): void {
		foreach ( array( 'monitor_began_at', 'last_success_at', 'last_failure_at', 'last_error' ) as $suffix ) {
			delete_option( self::option( $live_mode, $suffix ) );
		}
	}

	/**
	 * The four verdicts, by timestamp order (Stripe's status codes):
	 *   1 = most recent event succeeded (healthy),
	 *   2 = nothing received since monitoring began (waiting),
	 *   3 = failure after an earlier success (something broke),
	 *   4 = failure and never a success (it never worked).
	 *
	 * @param bool $live_mode Which plane.
	 */
	public static function status_code( bool $live_mode ): int {
		$success = self::last_success_at( $live_mode );
		$failure = self::last_failure_at( $live_mode );

		if ( $success > $failure ) {
			return 1;
		}
		if ( 0 === $success && 0 === $failure ) {
			return 2;
		}
		return $success > 0 ? 3 : 4;
	}

	/**
	 * One sentence a merchant can act on, per rejection code. Lives with
	 * the state because the state stores the code and every reader needs
	 * the same words.
	 *
	 * @param string $code Error code.
	 */
	public static function reason_sentence( string $code ): string {
		switch ( $code ) {
			case XPay_Error_Codes::WEBHOOK_NOT_CONFIGURED:
				return __( 'No signing secret is saved for this mode, so deliveries cannot be verified.', 'xpay-for-woocommerce' );
			case XPay_Error_Codes::WEBHOOK_SIGNATURE_INVALID:
				return __( 'The signature did not match. The signing secret saved here is probably not the one XPay is signing with.', 'xpay-for-woocommerce' );
			case XPay_Error_Codes::WEBHOOK_SIGNATURE_MISSING:
				return __( 'Deliveries are arriving without a signature header. Check that the endpoint URL in your XPay dashboard points at this store.', 'xpay-for-woocommerce' );
			case XPay_Error_Codes::WEBHOOK_TIMESTAMP_TOLERANCE:
				return __( 'The delivery was too old to accept. Check that this server\'s clock is correct.', 'xpay-for-woocommerce' );
			case XPay_Error_Codes::WEBHOOK_PAYLOAD_MALFORMED:
				return __( 'The delivery was not in the shape this plugin expects.', 'xpay-for-woocommerce' );
			default:
				return __( 'The delivery was verified but could not be applied to an order. The log has the detail.', 'xpay-for-woocommerce' );
		}
	}

	/**
	 * The whole verdict in one merchant-readable sentence, for the health
	 * row. Timestamps rendered in UTC, as Stripe renders theirs, so the
	 * merchant and XPay's own dashboard delivery log agree on the clock.
	 *
	 * @param bool $live_mode Which plane.
	 */
	public static function status_message( bool $live_mode ): string {
		$format = 'Y-m-d H:i:s e';

		switch ( self::status_code( $live_mode ) ) {
			case 1:
				return sprintf(
					/* translators: %s is a date and time, for example "2026-08-28 10:30:50 UTC". */
					__( 'The most recent event, received %s, was processed successfully.', 'xpay-for-woocommerce' ),
					gmdate( $format, self::last_success_at( $live_mode ) )
				);
			case 2:
				self::monitoring_began_at( $live_mode );
				return __( 'No webhook events received yet.', 'xpay-for-woocommerce' );
			case 3:
				return sprintf(
					/* translators: 1: date and time of the failed delivery, 2: the reason it failed, 3: date and time of the last successful delivery. */
					__( 'The most recent event, received %1$s, could not be processed. %2$s The last event to process successfully arrived %3$s.', 'xpay-for-woocommerce' ),
					gmdate( $format, self::last_failure_at( $live_mode ) ),
					self::reason_sentence( self::last_error_code( $live_mode ) ),
					gmdate( $format, self::last_success_at( $live_mode ) )
				);
			default:
				return sprintf(
					/* translators: 1: date and time of the failed delivery, 2: the reason it failed, 3: date and time monitoring began. */
					__( 'The most recent event, received %1$s, could not be processed. %2$s No event has processed successfully since monitoring began at %3$s.', 'xpay-for-woocommerce' ),
					gmdate( $format, self::last_failure_at( $live_mode ) ),
					self::reason_sentence( self::last_error_code( $live_mode ) ),
					gmdate( $format, self::monitoring_began_at( $live_mode ) )
				);
		}
	}

	/**
	 * @param bool   $live_mode Which plane.
	 * @param string $suffix    Record field.
	 */
	private static function option( bool $live_mode, string $suffix ): string {
		return 'xpay_wc_wh_' . ( $live_mode ? 'live' : 'test' ) . '_' . $suffix;
	}
}
