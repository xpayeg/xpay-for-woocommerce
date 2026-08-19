<?php
/**
 * PHPUnit bootstrap — contract suite.
 *
 * The second half of the two-suite split: the pure suite (tests/) pins
 * WordPress-free logic; THIS suite pins the stateful contracts — session
 * reuse/supersede, order-state transitions, webhook dedupe/ownership,
 * and the order lock — against a thin in-memory WordPress shim
 * (wp-shims.php). No database, no WordPress checkout: what these tests
 * pin is decision logic, the concurrency-shaped rules a regression
 * would loosen silently.
 *
 * @package XPay_For_WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'XPAY_WC_VERSION', 'contract-tests' );
define( 'XPAY_WC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'XPAY_WC_PLUGIN_URL', 'https://store.test/wp-content/plugins/xpay-for-woocommerce/' );

require_once __DIR__ . '/class-fake-wpdb.php';
require_once __DIR__ . '/wp-shims.php';
require_once __DIR__ . '/class-wc-order-stub.php';

xpay_tests_reset_world();

$xpay_contract_files = array(
	'includes/constants/class-xpay-constants.php',
	'includes/constants/class-xpay-error-codes.php',
	'includes/constants/class-xpay-event-names.php',
	'includes/constants/class-xpay-payment-methods.php',
	'includes/constants/class-xpay-session-status.php',
	'includes/constants/class-xpay-branding.php',
	'includes/api/class-xpay-api-exception.php',
	'includes/api/class-xpay-money.php',
	'includes/api/class-xpay-api-client.php',
	'includes/logger/class-xpay-logger.php',
	'includes/gateway/class-xpay-order-lock.php',
	'includes/gateway/class-xpay-order-sync.php',
	'includes/gateway/class-xpay-checkout-service.php',
	'includes/webhooks/class-xpay-webhook-controller.php',
);
foreach ( $xpay_contract_files as $xpay_contract_file ) {
	require_once dirname( __DIR__ ) . '/' . $xpay_contract_file;
}

require_once __DIR__ . '/class-capture-client.php';
require_once __DIR__ . '/ContractTestCase.php';
