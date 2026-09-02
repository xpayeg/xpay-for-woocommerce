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
 * Order meta (_xpay_session_id, _xpay_payment_intent_id, …) is
 * deliberately KEPT: it is the audit trail tying paid orders to XPay
 * resources, and it belongs to the order's history, not the plugin.
 *
 * Names are hardcoded rather than loaded from the plugin's constants
 * registry: uninstall runs standalone by WordPress convention, and
 * requiring plugin classes here would execute plugin code during
 * deletion. Keep this list in step with XPay_Constants and the two
 * compatibility notice's DISMISS_META key.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Everything one site owns: the options. Runs against whichever site
 * switch_to_blog() has made current.
 */
function xpay_wc_uninstall_site() {
	$options = array(
		'woocommerce_xpay_settings',
		'xpay_wc_account_methods_test',
		'xpay_wc_account_methods_live',
		'xpay_wc_account_checked_at_test',
		'xpay_wc_account_checked_at_live',
		'xpay_wc_enabled_methods',
		'xpay_wc_method_order',
		'xpay_wc_merchant_id_test',
		'xpay_wc_merchant_id_live',
		// xpay_wc_gateway_order_applied is deliberately NOT deleted:
		// "reinstalling is not permission to rearrange a checkout the
		// merchant has since arranged themselves" (XPay_Gateway_Order).
		'xpay_wc_brand_primary',
		// Connect with XPay: the local client registration and flow record.
		'xpay_wc_connect_client',
		'xpay_wc_connect_flow',
		'xpay_wc_key_validated',
		'xpay_wc_version_seen',
		'xpay_wc_live_payments_disabled',
		'xpay_wc_merchant_name_test',
		'xpay_wc_merchant_name_live',
		'xpay_wc_wh_test_monitor_began_at',
		'xpay_wc_wh_test_last_success_at',
		'xpay_wc_wh_test_last_failure_at',
		'xpay_wc_wh_test_last_error',
		'xpay_wc_wh_live_monitor_began_at',
		'xpay_wc_wh_live_last_success_at',
		'xpay_wc_wh_live_last_failure_at',
		'xpay_wc_wh_live_last_error',
		'xpay_wc_first_paid_at_test',
		'xpay_wc_first_paid_at_live',
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
	) as $xpay_wc_site_id ) {
		switch_to_blog( (int) $xpay_wc_site_id );
		xpay_wc_uninstall_site();
		restore_current_blog();
	}
} else {
	xpay_wc_uninstall_site();
}

// Per-user leftovers: linked XPay customer ids (test and live planes)
// and the dismissible-notice flag. delete_metadata with $delete_all
// covers every user in one call, and the usermeta table is shared
// network-wide, so this runs once — never per site.
$xpay_wc_meta_keys = array(
	'_xpay_customer_id_test',
	'_xpay_customer_id_live',
	'xpay_wpfunnels_notice_dismissed',
);
foreach ( $xpay_wc_meta_keys as $xpay_wc_meta_key ) {
	delete_metadata( 'user', 0, $xpay_wc_meta_key, '', true );
}
