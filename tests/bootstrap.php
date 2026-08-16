<?php
/**
 * PHPUnit bootstrap — pure unit suite.
 *
 * Loads ONLY the WordPress-free classes (money, signature, exception,
 * error codes, redactor). Anything touching WooCommerce/WordPress belongs
 * in a separate wp-env integration suite, per the two-suite split.
 *
 * ABSPATH is defined so the plugin files' direct-access guards pass.
 *
 * @package XPay_For_WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/constants/class-xpay-error-codes.php';
require_once dirname( __DIR__ ) . '/includes/api/class-xpay-api-exception.php';
require_once dirname( __DIR__ ) . '/includes/api/class-xpay-signature.php';
require_once dirname( __DIR__ ) . '/includes/api/class-xpay-money.php';
require_once dirname( __DIR__ ) . '/includes/logger/class-xpay-redactor.php';
