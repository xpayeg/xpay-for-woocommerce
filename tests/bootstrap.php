<?php
/**
 * PHPUnit bootstrap — pure unit suite.
 *
 * Loads ONLY the WordPress-free classes (money, signature, exception,
 * error codes, redactor). Anything touching WooCommerce/WordPress state
 * belongs in the contract suite (tests-contracts/, phpunit-contracts.xml),
 * which runs the state machines against an in-memory WordPress shim.
 *
 * ABSPATH is defined so the plugin files' direct-access guards pass.
 *
 * @package XPayEG_For_WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );

// Minimal WP shims so the pure suite can exercise WordPress-free logic that
// happens to call these two thin wrappers. wp_parse_url() is parse_url()
// plus pre-PHP-5.4.7 workarounds irrelevant on our 7.4 floor.
if ( ! function_exists( 'wp_parse_url' ) ) {
	/** @param int $component -1 for the full array, or a PHP_URL_* constant. */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

require_once dirname( __DIR__ ) . '/includes/constants/class-xpayeg-constants.php';
require_once dirname( __DIR__ ) . '/includes/constants/class-xpayeg-error-codes.php';
require_once dirname( __DIR__ ) . '/includes/constants/class-xpayeg-payment-methods.php';
require_once dirname( __DIR__ ) . '/includes/constants/class-xpayeg-refund-status.php';
require_once dirname( __DIR__ ) . '/includes/constants/class-xpayeg-charge-status.php';
require_once dirname( __DIR__ ) . '/includes/api/class-xpayeg-api-exception.php';
require_once dirname( __DIR__ ) . '/includes/api/class-xpayeg-signature.php';
require_once dirname( __DIR__ ) . '/includes/api/class-xpayeg-money.php';
require_once dirname( __DIR__ ) . '/includes/api/class-xpayeg-fx.php';
require_once dirname( __DIR__ ) . '/includes/logger/class-xpayeg-redactor.php';
require_once dirname( __DIR__ ) . '/includes/refunds/class-xpayeg-refundable.php';
// Only its pure decision tables run here; everything WordPress-touching
// in it belongs to the integration suite.
require_once dirname( __DIR__ ) . '/includes/connect/class-xpayeg-connect.php';
