<?php
/**
 * Pure listeners — these only attach when the logger is enabled. They never
 * mutate the values they observe; they emit xpay_logger_event and return the
 * original input untouched.
 */

defined( 'ABSPATH' ) or exit;

final class WC_XPay_Logger_Listeners {

	public static function register() {
		// http_api_debug fires after every WP HTTP request resolves. We only
		// look at requests whose URL targets an XPay host so we don't
		// instrument unrelated traffic. Filtered by URL substring rather
		// than the configured base_url because the configured value may
		// have changed between request issue and this hook.
		add_action( 'http_api_debug', array( __CLASS__, 'on_http_response' ), 10, 5 );

		// AJAX endpoint that the modal/checkout JS uses to report client-side
		// events (modal_shown, modal_hidden, redirect, JS errors, etc).
		// Nonce-checked, admin-AJAX so logged-in or guest browsers can post.
		add_action( 'wp_ajax_xpay_log_modal_event',        array( __CLASS__, 'ajax_log_modal_event' ) );
		add_action( 'wp_ajax_nopriv_xpay_log_modal_event', array( __CLASS__, 'ajax_log_modal_event' ) );
	}

	/**
	 * http_api_debug callback. Filters down to XPay traffic only.
	 *
	 * @param array|WP_Error $response  The response (or WP_Error on failure)
	 * @param string         $context   Which transport-level event ('response')
	 * @param string         $class     Transport class
	 * @param array          $r         Original request args
	 * @param string         $url       Request URL
	 */
	public static function on_http_response( $response, $context, $class, $r, $url ) {
		if ( 'response' !== $context ) {
			return;
		}
		if ( ! is_string( $url ) || false === stripos( $url, 'xpay' ) ) {
			return;
		}

		$stage = self::stage_for_url( $url );

		if ( is_wp_error( $response ) ) {
			do_action(
				'xpay_logger_event',
				$stage,
				array(
					'url'       => self::sanitize_url( $url ),
					'method'    => isset( $r['method'] ) ? $r['method'] : 'GET',
					'wp_error'  => $response->get_error_message(),
					'timeout_s' => isset( $r['timeout'] ) ? (float) $r['timeout'] : null,
				),
				'http call failed'
			);
			return;
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$body_excerpt = self::body_excerpt( $body );

		do_action(
			'xpay_logger_event',
			$stage,
			array(
				'url'           => self::sanitize_url( $url ),
				'method'        => isset( $r['method'] ) ? $r['method'] : 'GET',
				'http_code'     => (int) $code,
				'body_bytes'    => strlen( $body ),
				'body_excerpt'  => $body_excerpt,
				'looks_like_html' => $body_excerpt && in_array( $body_excerpt[0], array( '<', '!' ), true ),
				'timeout_s'     => isset( $r['timeout'] ) ? (float) $r['timeout'] : null,
			),
			'http call ' . ( $code >= 200 && $code < 300 ? 'ok' : 'non-2xx' )
		);
	}

	/**
	 * Map an outbound URL to a logical stage so the log is greppable by
	 * meaningful operation rather than full URL.
	 */
	private static function stage_for_url( $url ) {
		if ( false !== stripos( $url, '/api/communities/preferences' ) ) {
			return 'prefs.fetch';
		}
		if ( false !== stripos( $url, '/api/v1/payments/prepare-amount' ) ) {
			return 'prepare_amount.http';
		}
		if ( false !== stripos( $url, '/api/v1/payments/pay/variable-amount' ) ) {
			return 'pay.http';
		}
		if ( false !== stripos( $url, '/api/v1/communities/' ) && false !== stripos( $url, '/transactions/' ) ) {
			return 'check_transaction.http';
		}
		if ( false !== stripos( $url, '/api/promocodes/validate' ) ) {
			return 'promocode.http';
		}
		return 'http.other';
	}

	private static function sanitize_url( $url ) {
		// Strip query string entirely — we don't need it (community_id etc.
		// are already in payloads/settings) and it can carry trackable bits.
		$pos = strpos( $url, '?' );
		return false === $pos ? $url : substr( $url, 0, $pos ) . '?[stripped]';
	}

	private static function body_excerpt( $body ) {
		$body = trim( $body );
		if ( '' === $body ) {
			return '';
		}
		if ( strlen( $body ) <= 280 ) {
			return $body;
		}
		return substr( $body, 0, 280 ) . '…';
	}

	/**
	 * AJAX endpoint for client-side modal events. Validates nonce and
	 * caps payload size before emitting. Returns json success quickly so
	 * the modal lifecycle isn't blocked.
	 */
	public static function ajax_log_modal_event() {
		if ( ! WC_XPay_Logger::is_enabled() ) {
			wp_send_json_success( array( 'logged' => false ) );
		}

		// Nonce is created in the receipt-page modal output and shipped with
		// every event post. Mismatched/missing => silent no-op success so we
		// don't reveal whether the logger is active.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'xpay_log_modal_event' ) ) {
			wp_send_json_success( array( 'logged' => false ) );
		}

		$event_name = isset( $_POST['event'] )
			? sanitize_text_field( wp_unslash( $_POST['event'] ) )
			: 'unknown';
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		$context = array(
			'event'    => $event_name,
			'ua'       => isset( $_SERVER['HTTP_USER_AGENT'] )
				? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 240 )
				: '',
			'jq'       => isset( $_POST['jq'] ) ? sanitize_text_field( wp_unslash( $_POST['jq'] ) ) : '',
			'href'     => isset( $_POST['href'] )
				? esc_url_raw( wp_unslash( $_POST['href'] ) )
				: '',
			'order_id' => $order_id,
		);

		// Optional details payload (poll response code, error message, etc).
		// Capped to 512 chars and trimmed of any HTML that would pollute the
		// log.
		if ( isset( $_POST['details'] ) ) {
			$details = wp_strip_all_tags( wp_unslash( $_POST['details'] ) );
			if ( strlen( $details ) > 512 ) {
				$details = substr( $details, 0, 512 ) . '…';
			}
			$context['details'] = $details;
		}

		do_action( 'xpay_logger_event', 'modal.client_event', $context, 'client event ' . $event_name );

		wp_send_json_success( array( 'logged' => true ) );
	}
}
