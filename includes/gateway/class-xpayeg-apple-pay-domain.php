<?php
/**
 * XPayEG_Apple_Pay_Domain
 *
 * Apple verifies a website for Apple Pay by fetching a domain association
 * file, issued for the merchant's Apple Merchant ID, from a fixed path at
 * the domain root. The merchant uploads that file here and the plugin
 * serves it, so no access to the web server's files is needed.
 *
 * Readiness is judged by fetching the live URL, not by whether a file was
 * uploaded: a store behind a proxy or installed in a subdirectory may never
 * route the path to WordPress, and a merchant may host the file another way.
 *
 * @package XPayEG_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPayEG_Apple_Pay_Domain {

	/** The uploaded file's contents. */
	const OPTION_FILE = 'xpayeg_apple_pay_domain_file';

	/** The last reachability check: host, result and time. */
	const OPTION_CHECK = 'xpayeg_apple_pay_domain_check';

	/** Upload field name on the settings form. */
	const UPLOAD_FIELD = 'xpayeg_apple_pay_domain_file';

	/**
	 * Apple's path. Domains registered in the Apple Developer portal are
	 * verified at PATH.txt; the extensionless form is served too, for
	 * registrations made through Apple's API.
	 */
	const PATH = '/.well-known/apple-developer-merchantid-domain-association';

	/** Upper bound on an accepted upload; Apple's file is a few kilobytes. */
	const MAX_BYTES = 65536;

	/** Lower bound on the encoded payload; Apple's file is far longer. */
	const MIN_PAYLOAD = 64;

	/**
	 * Serve domain verification requests before normal WordPress routing.
	 */
	public static function register(): void {
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ), 0 );
	}

	/** Serve the uploaded file at Apple's path. Everything else falls through. */
	public static function maybe_serve(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! self::is_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) ) ) {
			return;
		}
		$file = self::file();
		if ( '' === $file ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- served verbatim as text/plain; Apple compares it byte for byte, and the upload was validated as encoded text.
		echo $file;
		exit;
	}

	/**
	 * @param string $path Request path.
	 */
	public static function is_path( string $path ): bool {
		return self::PATH === $path || self::PATH . '.txt' === $path;
	}

	/** The uploaded file's contents, or '' when none was uploaded. */
	public static function file(): string {
		$file = get_option( self::OPTION_FILE, '' );
		return is_string( $file ) ? $file : '';
	}

	/** The store's host, the domain Apple verifies. */
	public static function host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/** The address Apple verifies: shown to the merchant and checked. */
	public static function url(): string {
		return 'https://' . self::host() . self::PATH . '.txt';
	}

	/**
	 * Whether a valid file was found at this store's address on the last
	 * check. A check made for a different host does not count.
	 */
	public static function found(): bool {
		$check = get_option( self::OPTION_CHECK );
		return is_array( $check ) && ! empty( $check['found'] ) && isset( $check['host'] ) && self::host() === $check['host'];
	}

	/**
	 * Whether text has the shape of a domain association file: an encoded
	 * payload (wrapped base64, or hex in older files) and nothing else, so
	 * a 404 page or an error message served with status 200 cannot pass.
	 *
	 * Both base64 alphabets are accepted. Apple issues the file in the
	 * URL-safe variant (`-` and `_` where standard base64 has `+` and `/`),
	 * so a class without those two characters rejects every genuine file.
	 *
	 * @param string $text File contents.
	 */
	public static function is_association_text( string $text ): bool {
		$text = trim( $text );
		return strlen( $text ) <= self::MAX_BYTES
			&& 1 === preg_match( '#^[A-Za-z0-9+/=_\-\r\n]+$#', $text )
			&& strlen( (string) preg_replace( '/[\r\n]/', '', $text ) ) >= self::MIN_PAYLOAD;
	}

	/**
	 * Whether a fetched body is the domain association file. When a file
	 * was uploaded, the body must be exactly that file.
	 *
	 * @param string $body Response body.
	 */
	public static function is_file_body( string $body ): bool {
		if ( ! self::is_association_text( $body ) ) {
			return false;
		}
		$uploaded = trim( self::file() );
		return '' === $uploaded || trim( $body ) === $uploaded;
	}

	/**
	 * Fetch the file from the address Apple verifies and record the result.
	 * Apple requires HTTPS without redirects, so neither is followed here.
	 */
	public static function check(): bool {
		$response = wp_remote_get(
			self::url(),
			array(
				'timeout'     => 10,
				'redirection' => 0,
			)
		);
		$found    = ! is_wp_error( $response )
			&& 200 === (int) wp_remote_retrieve_response_code( $response )
			&& self::is_file_body( (string) wp_remote_retrieve_body( $response ) );

		update_option(
			self::OPTION_CHECK,
			array(
				'host'  => self::host(),
				'found' => $found,
				'at'    => time(),
			),
			false
		);
		return $found;
	}

	/**
	 * Store an uploaded file from the settings form, if one was posted,
	 * byte for byte: Apple compares the served file with its own copy.
	 *
	 * @return string|null An error for the merchant, or null when nothing
	 *                     was posted or the file was stored.
	 */
	public static function save_upload(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the settings nonce before process_admin_options ran; this is only called from it.
		if ( ! isset( $_FILES[ self::UPLOAD_FIELD ] ) || ! is_array( $_FILES[ self::UPLOAD_FIELD ] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above; only error, size and tmp_name are read, and the contents are validated below.
		$upload = $_FILES[ self::UPLOAD_FIELD ];
		if ( ! isset( $upload['error'] ) || UPLOAD_ERR_NO_FILE === (int) $upload['error'] ) {
			return null;
		}

		$invalid = __( 'XPay: that file is not an Apple Pay domain verification file. Download it again from your Apple Developer account and upload it unchanged.', 'xpay-for-woocommerce' );
		if ( UPLOAD_ERR_OK !== (int) $upload['error'] || empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) || (int) $upload['size'] > self::MAX_BYTES ) {
			return $invalid;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the just-uploaded temporary file.
		$contents = file_get_contents( $upload['tmp_name'] );
		if ( ! is_string( $contents ) || ! self::is_association_text( $contents ) ) {
			return $invalid;
		}

		update_option( self::OPTION_FILE, $contents, false );
		return null;
	}
}
