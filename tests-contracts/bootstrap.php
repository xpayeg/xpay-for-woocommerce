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
 * @package XPayEG_For_WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'XPAYEG_VERSION', 'contract-tests' );
define( 'XPAYEG_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'XPAYEG_PLUGIN_URL', 'https://store.test/wp-content/plugins/xpay-for-woocommerce/' );

require_once __DIR__ . '/class-fake-wpdb.php';
require_once __DIR__ . '/class-order-util-shim.php';
require_once __DIR__ . '/wp-shims.php';
require_once __DIR__ . '/class-wc-order-stub.php';

xpayeg_tests_reset_world();

$xpayeg_contract_files = array(
	'includes/constants/class-xpayeg-constants.php',
	'includes/constants/class-xpayeg-error-codes.php',
	'includes/constants/class-xpayeg-event-names.php',
	'includes/constants/class-xpayeg-payment-methods.php',
	'includes/constants/class-xpayeg-session-status.php',
	'includes/constants/class-xpayeg-refund-status.php',
	'includes/api/class-xpayeg-api-exception.php',
	'includes/api/class-xpayeg-money.php',
	'includes/api/class-xpayeg-fx.php',
	'includes/api/class-xpayeg-api-client.php',
	'includes/logger/class-xpayeg-logger.php',
	'includes/gateway/class-xpayeg-order-lock.php',
	'includes/gateway/class-xpayeg-order-sync.php',
	'includes/gateway/class-xpayeg-checkout-service.php',
	'includes/constants/class-xpayeg-charge-status.php',
	'includes/refunds/class-xpayeg-refundable.php',
	'includes/refunds/class-xpayeg-refund-service.php',
	'includes/webhooks/class-xpayeg-webhook-state.php',
	'includes/webhooks/class-xpayeg-webhook-controller.php',
);
foreach ( $xpayeg_contract_files as $xpayeg_contract_file ) {
	require_once dirname( __DIR__ ) . '/' . $xpayeg_contract_file;
}

require_once __DIR__ . '/class-capture-client.php';
require_once __DIR__ . '/ContractTestCase.php';
