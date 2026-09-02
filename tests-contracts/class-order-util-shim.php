<?php
/**
 * OrderUtil stand-in for the contract suite.
 *
 * The contract world is a modern store: order storage is HPOS, so the
 * webhook lookup takes the supported-API branch that the shimmed
 * wc_get_orders() models faithfully.
 *
 * This is deliberate, and it is a boundary rather than an oversight. The
 * legacy post-store branch issues real SQL against real tables; modelling
 * that here would mean writing a SQL parser into a fake and then asserting
 * against our own parser — the exact shape of test that stayed green while
 * refunds landed on the wrong order. That branch is covered where it can be
 * covered honestly: tests-integration/WebhookOrderLookupTest.php runs every
 * lookup case against real WooCommerce on BOTH order storages.
 *
 * @package XPay_For_WooCommerce
 */

namespace Automattic\WooCommerce\Utilities;

class OrderUtil {

	public static function custom_orders_table_usage_is_enabled(): bool {
		return true;
	}
}
