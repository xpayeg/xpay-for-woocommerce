<?php
/**
 * XPay_Connect_Client
 *
 * The HTTP half of Connect with XPay: Dynamic Client Registration
 * (RFC 7591) and the authorization-code exchange (RFC 6749), against
 * XPay's OAuth issuer. Kept apart from XPay_Api_Client on purpose —
 * that client authenticates with a merchant key and speaks the API's
 * JSON error envelope; these two endpoints authenticate with nothing
 * (public client, PKCE) and speak OAuth's flat
 * { error, error_description } envelope. One client per protocol, so
 * neither grows special cases for the other.
 *
 * The token response's access_token is DELIBERATELY never returned to
 * callers: it is short-lived and the xpay_* fields are the
 * whole deliverable. Not handing it out is how we guarantee nothing
 * ever stores it.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Connect_Client {

	/**
	 * Both calls sit between an admin click and a browser navigation, so
	 * they get the API client's write-path budget, not the shopper one.
	 */
	const TIMEOUT_SECONDS = 30;

	/**
	 * Register this store as an OAuth client.
	 *
	 * @param array $body RFC 7591 registration document (client_name,
	 *                    client_uri, redirect_uris, token_endpoint_auth_method).
	 * @return array Decoded registration response, client_id guaranteed.
	 * @throws XPay_Api_Exception When registration fails or the response
	 *                            carries no client_id.
	 */
	public static function register_client( array $body ): array {
		$response = self::request(
			'/oauth2/register',
			wp_json_encode( $body ),
			'application/json',
			// The registration document is names and URLs, no secrets —
			// loggable as-is, and support needs it verbatim when a
			// registration is refused.
			array( 'body' => $body )
		);

		if ( ! isset( $response['client_id'] ) || ! is_string( $response['client_id'] ) || '' === $response['client_id'] ) {
			throw XPay_Api_Exception::from_api_response( array( 'code' => XPay_Error_Codes::CONNECT_REGISTRATION_FAILED, 'message' => 'Client registration response missing client_id' ), 502 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- constant message, render sites escape on output; single-line form because the sniff flags every line of a multiline throw.
		}

		return $response;
	}

	/**
	 * Exchange the authorization code for the key payload.
	 *
	 * @param string $client_id    The registered client.
	 * @param string $redirect_uri The EXACT registered redirect URI — the
	 *                             server re-checks it here (RFC 6749 §4.1.3).
	 * @param string $code         The single-use authorization code.
	 * @param string $verifier     The PKCE verifier minted at flow start.
	 * @return array Decoded token response (the xpay_* fields are the
	 *               deliverable; the access_token inside is discarded by
	 *               every caller).
	 * @throws XPay_Api_Exception When the exchange is refused or fails.
	 */
	public static function exchange( string $client_id, string $redirect_uri, string $code, string $verifier ): array {
		return self::request(
			'/oauth2/token',
			http_build_query(
				array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => $redirect_uri,
					'client_id'     => $client_id,
					'code_verifier' => $verifier,
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			),
			'application/x-www-form-urlencoded',
			// Never the body: it carries the code and the verifier, and
			// holding both redeems the code. The masked code is enough to
			// join this line to the authorize redirect in support work.
			array( 'code' => self::masked( $code ) )
		);
	}

	/**
	 * One POST to the OAuth issuer, logged as a request/response pair the
	 * way XPay_Api_Client logs its calls.
	 *
	 * @param string $path         Path under the issuer, starting with '/'.
	 * @param string $body         Encoded request body.
	 * @param string $content_type Body encoding.
	 * @param array  $log_context  What of the request is safe to log.
	 * @return array Decoded JSON response.
	 * @throws XPay_Api_Exception On transport failure or any non-2xx.
	 */
	private static function request( string $path, string $body, string $content_type, array $log_context ): array {
		$url = XPay_Constants::oauth_base() . $path;

		XPay_Logger::event(
			'connect.request',
			array_merge( array( 'path' => $path ), $log_context ),
			'POST ' . $path
		);

		$started  = microtime( true );
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => $content_type,
					'User-Agent'   => 'XPay-WooCommerce/' . XPAY_WC_VERSION . '; ' . home_url(),
				),
				'body'    => $body,
				'timeout' => self::TIMEOUT_SECONDS,
			)
		);

		if ( is_wp_error( $response ) ) {
			XPay_Logger::event(
				'connect.transport_error',
				array(
					'path'        => $path,
					'duration_ms' => (int) ( ( microtime( true ) - $started ) * 1000 ),
					'wp_error'    => $response->get_error_message(),
				)
			);
			throw XPay_Api_Exception::transport( 'Could not reach the XPay OAuth service' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		XPay_Logger::event(
			'connect.response',
			array(
				'path'        => $path,
				'status'      => $status,
				'duration_ms' => (int) ( ( microtime( true ) - $started ) * 1000 ),
				// The decoded body, whole, through the logger's redactor:
				// xpay_restricted_key and access_token are on its secret
				// list, so what lands on disk is safe to share.
				'response'    => is_array( $json ) ? $json : null,
			),
			'POST ' . $path . ' (' . $status . ')'
		);

		if ( $status >= 200 && $status < 300 && is_array( $json ) ) {
			return $json;
		}

		/*
		 * OAuth's error envelope is flat ({ error, error_description },
		 * RFC 6749 §5.2), unlike the API's nested one — mapped here onto
		 * the same exception type so callers branch on codes the one
		 * existing way.
		 */
		$code    = isset( $json['error'] ) && is_string( $json['error'] ) && '' !== trim( $json['error'] )
			? trim( $json['error'] )
			: XPay_Error_Codes::CONNECT_EXCHANGE_FAILED;
		$message = isset( $json['error_description'] ) && is_string( $json['error_description'] ) && '' !== trim( $json['error_description'] )
			? trim( $json['error_description'] )
			: 'XPay OAuth request failed';
		throw XPay_Api_Exception::from_api_response( array( 'code' => $code, 'message' => $message ), $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- OAuth error envelope values, every render site escapes on output; single-line form because the sniff flags every line of a multiline throw.
	}

	/**
	 * A code safe to log: first six characters, nothing more. Enough to
	 * join a log line to its authorize redirect, useless to redeem.
	 *
	 * @param string $value The authorization code.
	 */
	private static function masked( string $value ): string {
		return substr( $value, 0, 6 ) . '...';
	}
}
