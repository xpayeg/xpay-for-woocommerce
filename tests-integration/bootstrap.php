<?php
/**
 * PHPUnit bootstrap — real WordPress integration suite.
 *
 * The third suite, and the only one that is not talking to itself. tests/
 * pins WordPress-free logic and tests-contracts/ pins state machines against
 * an in-memory shim we wrote; both can be green while the plugin is broken,
 * and both were, for the whole time the classic checkout did not work.
 *
 * This suite boots the real WordPress test library, installs real
 * WooCommerce, and activates the real plugin, so a test that says "core
 * fires this hook" is asserting against core rather than against our
 * recollection of it.
 *
 * Run: wp-env run tests-cli --env-cwd=wp-content/plugins/xpay-for-woocommerce \
 *          ./vendor/bin/phpunit -c phpunit-integration.xml
 *
 * NOTE: the WordPress test bootstrap installs a fresh site into the
 * tests-mysql database on every run. That is the throwaway site on :8889,
 * never the development store on :8888.
 *
 * @package XPay_For_WooCommerce
 */

$xpay_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $xpay_tests_dir ) {
	$xpay_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $xpay_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test library at {$xpay_tests_dir}.\n" );
	fwrite( STDERR, "This suite only runs inside wp-env's tests-cli container.\n" );
	exit( 1 );
}

// The WordPress test library refuses to boot without the polyfills; it looks
// for them at this constant rather than in our autoloader.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $xpay_tests_dir . '/includes/functions.php';

$xpay_plugins_dir = dirname( dirname( __DIR__ ) );

/*
 * Load WooCommerce and the plugin the same way WordPress would: WooCommerce
 * first (the plugin's own loader returns early without WC_Payment_Gateway),
 * both on muplugins_loaded so they are present before the test library
 * finishes booting.
 */
tests_add_filter(
	'muplugins_loaded',
	function () use ( $xpay_plugins_dir ) {
		require_once $xpay_plugins_dir . '/woocommerce/woocommerce.php';
		require_once dirname( __DIR__ ) . '/xpay-for-woocommerce.php';

		/*
		 * Spy on the log through WC_Logger's own handler list, registered
		 * before the first wc_get_logger() call constructs the logger (the
		 * handler list is read once, at construction). Tests then assert on
		 * what actually reached the log, after XPay_Logger's per-level
		 * keep/drop decision and the redactor.
		 */
		require_once __DIR__ . '/class-xpay-spy-log-handler.php';
		add_filter(
			'woocommerce_register_log_handlers',
			function ( $handlers ) {
				$handlers   = is_array( $handlers ) ? $handlers : array();
				$handlers[] = new XPay_Spy_Log_Handler();
				return $handlers;
			}
		);
	}
);

/*
 * WooCommerce's tables do not exist in a freshly installed test site, and
 * almost nothing in the plugin works without them. Install on setup_theme,
 * which is late enough for WC to be constructed and early enough to be
 * before any test runs.
 */
tests_add_filter(
	'setup_theme',
	function () use ( $xpay_plugins_dir ) {
		define( 'WP_UNINSTALL_PLUGIN', true );
		define( 'WC_REMOVE_ALL_DATA', true );
		include $xpay_plugins_dir . '/woocommerce/uninstall.php';

		WC_Install::install();

		// Reload roles from the freshly written capabilities.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
	}
);

/*
 * NOTHING IN THIS SUITE MAY REACH THE INTERNET.
 *
 * It could, and it did. The settings-save path builds its own API client
 * inside the gateway, so a test that exercises a save phoned api.xpay.app
 * for real, carrying the made-up key the test had just configured. Every
 * run produced a live 401, and a day of runs produced 85 of them in XPay's
 * monitoring, read there as a merchant polling with a rotated key.
 *
 * The block is a WP_Error rather than a canned 200 on purpose: a test that
 * genuinely needs an answer should say so by scripting one, and a test that
 * did not mean to call out should fail in a way that names the URL rather
 * than quietly succeeding against production.
 *
 * To script a response, push it onto $GLOBALS['xpay_test_http'] keyed by a
 * substring of the URL. Anything unmatched is refused.
 *
 * Every attempt is recorded on $GLOBALS['xpay_test_http_requests'] first,
 * blocked ones included, so a test can assert what the plugin SENT and not
 * only what it did with the answer. Some of what this plugin gets right is
 * in the request body — a session's expiresAfterMinutes is never visible in
 * any response the plugin reads.
 */
tests_add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		unset( $preempt );

		if ( ! isset( $GLOBALS['xpay_test_http_requests'] ) || ! is_array( $GLOBALS['xpay_test_http_requests'] ) ) {
			$GLOBALS['xpay_test_http_requests'] = array();
		}
		$GLOBALS['xpay_test_http_requests'][] = array(
			'url'     => (string) $url,
			'method'  => isset( $args['method'] ) ? (string) $args['method'] : 'GET',
			'body'    => isset( $args['body'] ) ? $args['body'] : null,
			'headers' => isset( $args['headers'] ) ? (array) $args['headers'] : array(),
		);

		$scripted = isset( $GLOBALS['xpay_test_http'] ) && is_array( $GLOBALS['xpay_test_http'] )
			? $GLOBALS['xpay_test_http']
			: array();

		foreach ( $scripted as $needle => $response ) {
			if ( false !== strpos( (string) $url, (string) $needle ) ) {
				return $response;
			}
		}

		return new WP_Error(
			'xpay_test_http_blocked',
			'The test suite tried to reach ' . $url . '. Tests must not touch a live system: script a response in $GLOBALS[\'xpay_test_http\'] instead.'
		);
	},
	1,
	3
);

require $xpay_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-xpay-integration-test-case.php';
