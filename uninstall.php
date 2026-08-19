<?php
/**
 * Uninstall cleanup. Runs when the merchant DELETES the plugin (not on
 * deactivation), so it removes everything the plugin owns: the log
 * table, its options (including saved keys: they are re-obtainable
 * from the XPay dashboard, and a deleted plugin must not squat
 * credentials in wp_options), per-user meta, and the prune cron.
 *
 * On multisite the per-site cleanup runs for EVERY site: uninstall is
 * network-level by WordPress design, and cleaning only the main site
 * would leave every subsite's saved API credentials squatting in its
 * options table. User meta is cleaned once — users are network-global.
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

/**
 * Everything one site owns: the log table, the options, the prune cron.
 * Runs against whichever site switch_to_blog() has made current.
 */
function xpay_wc_uninstall_site() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin-owned log table at uninstall; %i binds the identifier.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'xpay_log' ) );

	$options = array(
		'woocommerce_xpay_settings',
		'xpay_wc_db_version',
		'xpay_wc_method_pin_rejected',
		'xpay_wc_brand_primary',
		'xpay_wc_key_validated',
		'xpay_wc_last_webhook_at_test',
		'xpay_wc_last_webhook_at_live',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	wp_clear_scheduled_hook( 'xpay_wc_prune_log' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $xpay_wc_site_id ) {
		switch_to_blog( (int) $xpay_wc_site_id );
		xpay_wc_uninstall_site();
		restore_current_blog();
	}
} else {
	xpay_wc_uninstall_site();
}

// Per-user leftovers: linked XPay customer ids (test and live planes)
// and the two dismissible-notice flags. delete_metadata with $delete_all
// covers every user in one call, and the usermeta table is shared
// network-wide, so this runs once — never per site.
$xpay_wc_meta_keys = array(
	'_xpay_customer_id_test',
	'_xpay_customer_id_live',
	'xpay_legacy_notice_dismissed',
	'xpay_wpfunnels_notice_dismissed',
);
foreach ( $xpay_wc_meta_keys as $xpay_wc_meta_key ) {
	delete_metadata( 'user', 0, $xpay_wc_meta_key, '', true );
}
