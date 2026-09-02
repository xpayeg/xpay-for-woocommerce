<?php
/**
 * XPay_Logger
 *
 * Structured diagnostic logging. Call sites fire one central action
 * (`xpay_logger_event`) unconditionally; this class is the only subscriber,
 * and what is worth keeping is decided per write (see init()).
 *
 * Writes go through WC_Logger (source: "xpay"): WooCommerce's log directory
 * is already access-hardened, the merchant gets the native
 * WooCommerce → Status → Logs viewer, and retention is handled by core.
 * Every entry passes through XPay_Redactor at write time.
 *
 * One request id is minted per request and stamped on every line so related
 * entries can be correlated.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Logger {

	/** @var string|null Per-request correlation id (minted once, threaded everywhere). */
	private static $request_id = null;

	/** @var WC_Logger_Interface|null */
	private static $wc_logger = null;

	/** Routine tracing. Written only when the merchant enabled logging. */
	const LEVEL_INFO = 'info';

	/** Something failed. Always written. */
	const LEVEL_ERROR = 'error';

	/** Something failed in a way that costs money or blocks payment. Always written. */
	const LEVEL_CRITICAL = 'critical';

	/**
	 * Attach unconditionally. The write path filters routine entries while
	 * preserving payment failures.
	 */
	public static function init(): void {
		add_action( 'xpay_logger_event', array( __CLASS__, 'write' ), 10, 4 );
	}

	public static function is_enabled(): bool {
		$settings = get_option( 'woocommerce_' . XPay_Constants::GATEWAY_ID . '_settings', array() );
		return isset( $settings['debug'] ) && 'yes' === $settings['debug'];
	}

	/**
	 * Routine tracing. Suppressed unless the merchant enabled logging.
	 *
	 * @param string $stage   Dot-separated stage, e.g. "webhook.verified".
	 * @param array  $context Structured context (redacted before write).
	 * @param string $message Optional short human message.
	 */
	public static function event( string $stage, array $context = array(), string $message = '' ): void {
		do_action( 'xpay_logger_event', $stage, $context, $message, self::LEVEL_INFO );
	}

	/**
	 * A failure. Always written, whatever the debug setting says.
	 *
	 * @param string $stage   Dot-separated stage.
	 * @param array  $context Structured context (redacted before write).
	 * @param string $message Optional short human message.
	 */
	public static function error( string $stage, array $context = array(), string $message = '' ): void {
		do_action( 'xpay_logger_event', $stage, $context, $message, self::LEVEL_ERROR );
	}

	/**
	 * A failure that costs money or blocks payment. Always written.
	 *
	 * @param string $stage   Dot-separated stage.
	 * @param array  $context Structured context (redacted before write).
	 * @param string $message Optional short human message.
	 */
	public static function critical( string $stage, array $context = array(), string $message = '' ): void {
		do_action( 'xpay_logger_event', $stage, $context, $message, self::LEVEL_CRITICAL );
	}

	/**
	 * Action subscriber. Always attached; decides here what to keep.
	 *
	 * @param string $stage   Stage identifier.
	 * @param array  $context Context array.
	 * @param string $message Optional message.
	 * @param string $level   One of the LEVEL_* constants.
	 */
	public static function write( $stage, $context = array(), $message = '', $level = self::LEVEL_INFO ): void {
		$level = in_array( $level, array( self::LEVEL_INFO, self::LEVEL_ERROR, self::LEVEL_CRITICAL ), true ) ? $level : self::LEVEL_INFO;

		if ( self::LEVEL_INFO === $level && ! self::is_enabled() ) {
			return;
		}

		if ( null === self::$wc_logger ) {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			self::$wc_logger = wc_get_logger();
		}

		$redacted_context = XPay_Redactor::redact( is_array( $context ) ? $context : array() );
		$scrubbed_message = '' !== $message ? XPay_Redactor::scrub_string( (string) $message ) : '';

		/*
		 * The message is the readable line; everything structured rides
		 * the CONTEXT param, which WooCommerce's log viewer renders as a
		 * collapsible "Additional context" block — the same shape Stripe's
		 * plugin logs in. Cramming the JSON into the message made every
		 * line a wall.
		 */
		$line = '' !== $scrubbed_message ? $stage . ': ' . $scrubbed_message : (string) $stage;

		self::$wc_logger->log(
			$level,
			$line,
			array_merge(
				array(
					'source'     => 'xpay',
					'stage'      => (string) $stage,
					'request_id' => self::request_id(),
				),
				$redacted_context,
				'' !== $scrubbed_message ? array( 'message' => $scrubbed_message ) : array()
			)
		);
	}

	public static function request_id(): string {
		if ( null === self::$request_id ) {
			self::$request_id = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		}
		return self::$request_id;
	}
}
