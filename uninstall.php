<?php
/**
 * Uninstall cleanup. Runs when the merchant DELETES the plugin (not on
 * deactivation), so it removes everything the plugin owns: its options
 * (including saved keys: they are re-obtainable from the XPay
 * dashboard, and a deleted plugin must not squat credentials in
 * wp_options) and per-user meta.
 *
 * On multisite the per-site cleanup runs for EVERY site: uninstall is
 * network-level by WordPress design, and cleaning only the main site
 * would leave every subsite's saved API credentials squatting in its
 * options table. User meta is cleaned once — users are network-global.
 *
 * Order meta (_xpayeg_session_id, _xpayeg_payment_intent_id, …) is
 * deliberately KEPT: it is the audit trail tying paid orders to XPay
 * resources, and it belongs to the order's history, not the plugin.
 *
 * Names are hardcoded rather than loaded from the plugin's constants
 * registry: uninstall runs standalone by WordPress convention, and
 * requiring plugin classes here would execute plugin code during
 * deletion. Keep this list in step with XPayEG_Constants and the two
 * compatibility notice's DISMISS_META key.
 *
 * @package XPayEG_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Everything one site owns: the options. Runs against whichever site
 * switch_to_blog() has made current.
 */
function xpayeg_uninstall_site() {
	$options = array(
		'woocommerce_xpayeg_settings',
		'xpayeg_account_methods_test',
		'xpayeg_account_methods_live',
		'xpayeg_account_checked_at_test',
		'xpayeg_account_checked_at_live',
		'xpayeg_enabled_methods',
		'xpayeg_method_order',
		'xpayeg_merchant_id_test',
		'xpayeg_merchant_id_live',
		// xpayeg_gateway_order_applied is deliberately NOT deleted:
		// "reinstalling is not permission to rearrange a checkout the
		// merchant has since arranged themselves" (XPayEG_Gateway_Order).
		'xpayeg_brand_primary',
		// Connect with XPay: the local client registration and flow record.
		'xpayeg_connect_client',
		'xpayeg_connect_flow',
		'xpayeg_key_validated',
		'xpayeg_version_seen',
		'xpayeg_live_payments_disabled',
		'xpayeg_merchant_name_test',
		'xpayeg_merchant_name_live',
		'xpayeg_wh_test_monitor_began_at',
		'xpayeg_wh_test_last_success_at',
		'xpayeg_wh_test_last_failure_at',
		'xpayeg_wh_test_last_error',
		'xpayeg_wh_live_monitor_began_at',
		'xpayeg_wh_live_last_success_at',
		'xpayeg_wh_live_last_failure_at',
		'xpayeg_wh_live_last_error',
		'xpayeg_first_paid_at_test',
		'xpayeg_first_paid_at_live',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $xpayeg_site_id ) {
		switch_to_blog( (int) $xpayeg_site_id );
		xpayeg_uninstall_site();
		restore_current_blog();
	}
} else {
	xpayeg_uninstall_site();
}

// Per-user leftovers: linked XPay customer ids (test and live planes)
// and the dismissible-notice flag. delete_metadata with $delete_all
// covers every user in one call, and the usermeta table is shared
// network-wide, so this runs once — never per site.
$xpayeg_meta_keys = array(
	'_xpayeg_customer_id_test',
	'_xpayeg_customer_id_live',
	'xpayeg_wpfunnels_notice_dismissed',
);
foreach ( $xpayeg_meta_keys as $xpayeg_meta_key ) {
	delete_metadata( 'user', 0, $xpayeg_meta_key, '', true );
}
