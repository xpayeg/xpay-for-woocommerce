<?php
/**
 * Plugin Name: XPay for WooCommerce
 * Plugin URI: https://xpay.app/
 * Description: Accept payments on your WooCommerce store via XPay (Egypt): cards, valU and more, in a secure on-site checkout.
 * Author: XPay
 * Author URI: https://xpay.app/
 * Version: 3.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.3
 * WC tested up to: 10.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: xpay-for-woocommerce
 * Domain Path: /languages
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

defined( 'XPAY_WC_VERSION' ) || define( 'XPAY_WC_VERSION', '3.0.0' );
defined( 'XPAY_WC_PLUGIN_FILE' ) || define( 'XPAY_WC_PLUGIN_FILE', __FILE__ );
defined( 'XPAY_WC_PLUGIN_DIR' ) || define( 'XPAY_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'XPAY_WC_PLUGIN_URL' ) || define( 'XPAY_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * HPOS (custom order tables) and Cart/Checkout Blocks compatibility must be
 * declared on before_woocommerce_init — declaring later (or not at all) makes
 * WooCommerce mark the plugin incompatible and is an automatic Woo Marketplace
 * rejection for new submissions.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/*
 * WP.org auto-loads translations for hosted plugins, but this plugin also
 * ships via direct download from XPay — load_plugin_textdomain keeps the
 * manual-install channel translated too.
 */
add_action(
	'init',
	function () {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
		load_plugin_textdomain( 'xpay-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/*
 * Activation/deactivation must not depend on WooCommerce being loaded, so
 * the log store is required directly rather than via the plugin loader.
 *
 * Network-wide activation runs per site: each subsite owns its log table
 * and prune cron, so touching only the main site would leave every other
 * subsite to lazy-install while a network DEACTIVATION would strand their
 * crons forever. Subsites created after a network activation converge via
 * the same lazy install (admin_init) that covers plugin updates.
 */
register_activation_hook(
	__FILE__,
	function ( $network_wide ) {
		require_once XPAY_WC_PLUGIN_DIR . 'includes/logger/class-xpay-log-store.php';
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $xpay_wc_site_id ) {
				switch_to_blog( (int) $xpay_wc_site_id );
				XPay_Log_Store::install();
				restore_current_blog();
			}
			return;
		}
		XPay_Log_Store::install();
	}
);
register_deactivation_hook(
	__FILE__,
	function ( $network_wide ) {
		require_once XPAY_WC_PLUGIN_DIR . 'includes/logger/class-xpay-log-store.php';
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $xpay_wc_site_id ) {
				switch_to_blog( (int) $xpay_wc_site_id );
				XPay_Log_Store::unschedule();
				restore_current_blog();
			}
			return;
		}
		XPay_Log_Store::unschedule();
	}
);

/*
 * When a subsite is deleted, WordPress drops the tables the
 * wpmu_drop_tables filter lists (DROP IF EXISTS — harmless where the
 * plugin never ran). Without this, a deleted subsite leaves its xpay_log
 * table orphaned in the database forever.
 */
if ( is_multisite() ) {
	add_filter(
		'wpmu_drop_tables',
		function ( $tables, $site_id ) {
			global $wpdb;
			$tables[] = $wpdb->get_blog_prefix( $site_id ) . 'xpay_log';
			return $tables;
		},
		10,
		2
	);
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			// WooCommerce inactive: stay dormant. No admin nag — Plugin Check
			// flags noisy cross-plugin notices, and Woo core already explains
			// missing-dependency states to the merchant.
			return;
		}
		require_once XPAY_WC_PLUGIN_DIR . 'includes/class-xpay-plugin.php';
		XPay_Plugin::instance()->init();
	},
	11
);
