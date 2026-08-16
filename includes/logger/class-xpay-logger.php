<?php
/**
 * XPay_Logger
 *
 * Structured diagnostic logging. Call sites fire one central action
 * (`xpay_logger_event`) unconditionally; this class is the only subscriber
 * and only attaches when the merchant enabled logging — a disabled logger
 * costs zero listener overhead (the v2 architecture, kept).
 *
 * Writes go through WC_Logger (source: "xpay") instead of v2's hand-rolled
 * flat files: WooCommerce's log directory is already access-hardened, the
 * merchant gets the native WooCommerce → Status → Logs viewer, and
 * retention is handled by core. Every entry passes through XPay_Redactor
 * at write time.
 *
 * One request id is minted per request and stamped on every line, so one
 * incident can be reconstructed from scattered entries and joined against
 * XPay's own request ids from order notes.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Logger {

	/** @var string|null Per-request correlation id (minted once, threaded everywhere). */
	private static $request_id = null;

	/** @var WC_Logger_Interface|null */
	private static $wc_logger = null;

	public static function init(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_action( 'xpay_logger_event', array( __CLASS__, 'write' ), 10, 3 );
	}

	public static function is_enabled(): bool {
		$settings = get_option( 'woocommerce_' . XPay_Constants::GATEWAY_ID . '_settings', array() );
		return isset( $settings['debug'] ) && 'yes' === $settings['debug'];
	}

	/**
	 * Convenience emitter — identical to firing the action directly.
	 *
	 * @param string $stage   Dot-separated stage, e.g. "webhook.verified".
	 * @param array  $context Structured context (redacted before write).
	 * @param string $message Optional short human message.
	 */
	public static function event( string $stage, array $context = array(), string $message = '' ): void {
		do_action( 'xpay_logger_event', $stage, $context, $message );
	}

	/**
	 * Action subscriber. Only ever attached when logging is enabled.
	 *
	 * @param string $stage   Stage identifier.
	 * @param array  $context Context array.
	 * @param string $message Optional message.
	 */
	public static function write( $stage, $context = array(), $message = '' ): void {
		if ( null === self::$wc_logger ) {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			self::$wc_logger = wc_get_logger();
		}

		$entry = array(
			'request_id' => self::request_id(),
			'stage'      => (string) $stage,
			'context'    => XPay_Redactor::redact( is_array( $context ) ? $context : array() ),
		);
		if ( '' !== $message ) {
			$entry['message'] = XPay_Redactor::scrub_string( (string) $message );
		}

		self::$wc_logger->info( wp_json_encode( $entry ), array( 'source' => 'xpay' ) );
	}

	public static function request_id(): string {
		if ( null === self::$request_id ) {
			self::$request_id = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		}
		return self::$request_id;
	}
}
