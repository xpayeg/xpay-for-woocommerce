<?php
/**
 * A WC_Logger handler that records every "xpay"-sourced entry in memory,
 * so tests can assert what actually reached the log.
 *
 * Registered in bootstrap.php through the same filter WooCommerce builds
 * its real handler list from, so the entries observed here went through
 * the REAL path: XPay_Logger's per-level keep/drop decision and the
 * redactor both ran before anything lands in this array. Capturing the
 * xpay_logger_event action instead would observe every call site
 * unconditionally and prove nothing about what the log keeps.
 *
 * @package XPay_For_WooCommerce
 */

class XPay_Spy_Log_Handler extends WC_Log_Handler {

	/** @var array[] Every xpay entry written this process: { level, stage, message, context }. */
	public static $entries = array();

	/**
	 * Decoded xpay log entries, newest first, optionally filtered.
	 *
	 * @param array $args { stage?: string (prefix match), level?: string }.
	 * @return array[]
	 */
	public static function query( array $args = array() ): array {
		$rows = array();
		foreach ( array_reverse( self::$entries ) as $entry ) {
			if ( isset( $args['stage'] ) && 0 !== strpos( $entry['stage'], $args['stage'] ) ) {
				continue;
			}
			if ( isset( $args['level'] ) && $args['level'] !== $entry['level'] ) {
				continue;
			}
			$rows[] = $entry;
		}
		return $rows;
	}

	public static function reset(): void {
		self::$entries = array();
	}

	/**
	 * @param int    $timestamp Log timestamp.
	 * @param string $level     emergency|alert|critical|error|warning|notice|info|debug.
	 * @param string $message   Log message (XPay_Logger writes a JSON entry).
	 * @param array  $context   Additional information (source).
	 */
	public function handle( $timestamp, $level, $message, $context ) {
		unset( $timestamp, $message );
		if ( ! isset( $context['source'] ) || 'xpay' !== $context['source'] ) {
			return false;
		}

		$rest = $context;
		unset( $rest['source'], $rest['stage'], $rest['request_id'], $rest['message'] );

		self::$entries[] = array(
			'level'      => (string) $level,
			'stage'      => isset( $context['stage'] ) ? (string) $context['stage'] : '',
			'message'    => isset( $context['message'] ) ? (string) $context['message'] : '',
			'context'    => wp_json_encode( $rest ),
			'request_id' => isset( $context['request_id'] ) ? (string) $context['request_id'] : '',
		);
		return false;
	}
}
