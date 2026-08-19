<?php
/**
 * Uninstall cleanup. Runs when the merchant DELETES the plugin (not on
 * deactivation), so it removes everything the plugin owns: the log
 * table, its options (including saved keys: they are re-obtainable
 * from the XPay dashboard, and a deleted plugin must not squat
 * credentials in wp_options), per-user meta, and the prune cron.
 *
 * Order meta (_xpay_session_id, _xpay_payment_intent_id, …) is
 * deliberately KEPT: it is the audit trail tying paid orders to XPay
 * resources, and it belongs to the order's history, not the plugin.
 *
 * Names are hardcoded rather than loaded from the plugin's constants
 * registry: uninstall runs standalone by WordPress convention, and
 * requiring plugin classes here would execute plugin code during
 * deletion. Keep this list in step with XPay_Constants and the two
 * compat notices' DISMISS_META keys.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin-owned log table at uninstall; %i binds the identifier.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'xpay_log' ) );

$xpay_wc_options = array(
	'woocommerce_xpay_settings',
	'xpay_wc_db_version',
	'xpay_wc_method_pin_rejected',
	'xpay_wc_brand_primary',
	'xpay_wc_key_validated',
	'xpay_wc_last_webhook_at',
);
foreach ( $xpay_wc_options as $xpay_wc_option ) {
	delete_option( $xpay_wc_option );
}

// Per-user leftovers: linked XPay customer ids (test and live planes)
// and the two dismissible-notice flags. delete_metadata with $delete_all
// covers every user in one call.
$xpay_wc_meta_keys = array(
	'_xpay_customer_id_test',
	'_xpay_customer_id_live',
	'xpay_legacy_notice_dismissed',
	'xpay_wpfunnels_notice_dismissed',
);
foreach ( $xpay_wc_meta_keys as $xpay_wc_meta_key ) {
	delete_metadata( 'user', 0, $xpay_wc_meta_key, '', true );
}

wp_clear_scheduled_hook( 'xpay_wc_prune_log' );
