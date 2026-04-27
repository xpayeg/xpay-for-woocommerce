<?php
/**
 * Admin notices that nudge merchants toward known compatibility settings
 * before they hit the support queue.
 *
 * Each notice:
 *   - is dismissible per-user (stored in user meta, not a transient — so a
 *     dismiss survives cache flushes and persists across sessions)
 *   - only renders when the underlying condition still applies (re-checks
 *     active plugins on every load — re-shows if the merchant uninstalls
 *     and later reinstalls)
 *   - is shown only to users with `manage_woocommerce`
 */

defined( 'ABSPATH' ) or exit;

final class WC_XPay_Admin_Notices {

	const DISMISS_META_PREFIX = 'xpay_dismissed_notice_';
	const NOTICE_WPFUNNELS    = 'wpfunnels_redirect';

	public static function init() {
		add_action( 'admin_notices',                array( __CLASS__, 'render_notices' ) );
		add_action( 'admin_post_xpay_dismiss_notice', array( __CLASS__, 'handle_dismiss' ) );
	}

	public static function render_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		self::render_wpfunnels_notice();
	}

	private static function render_wpfunnels_notice() {
		if ( self::is_dismissed( self::NOTICE_WPFUNNELS ) ) {
			return;
		}
		// Check WPFunnels active. The compat class is the source of truth so
		// detection logic stays in one place.
		if ( ! class_exists( 'WC_XPay_WPFunnels_Compat' ) || ! WC_XPay_WPFunnels_Compat::is_wpfunnels_active() ) {
			return;
		}
		// Already enabled — no need to nag.
		$settings = get_option( 'woocommerce_xpay_gateway_settings', array() );
		if ( ! empty( $settings[ WC_XPay_WPFunnels_Compat::SETTING_KEY ] ) && 'yes' === $settings[ WC_XPay_WPFunnels_Compat::SETTING_KEY ] ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=xpay_gateway' );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( array(
				'action' => 'xpay_dismiss_notice',
				'notice' => self::NOTICE_WPFUNNELS,
			), admin_url( 'admin-post.php' ) ),
			'xpay_dismiss_notice_' . self::NOTICE_WPFUNNELS
		);

		?>
		<div class="notice notice-warning is-dismissible" style="position:relative;">
			<p>
				<strong><?php esc_html_e( 'XPay + WPFunnels:', 'wc-gateway-xpay' ); ?></strong>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %1$s = link to gateway settings */
						__( 'WPFunnels is active and rewrites the post-payment redirect into its own funnel flow. Without a working upsell step (WPFunnels Pro), customers paying via XPay land on <code>/cart/</code> instead of a confirmation page even though the order is recorded correctly. <a href="%1$s">Enable the WPFunnels compatibility setting</a> to force the standard order-received page for XPay orders.', 'wc-gateway-xpay' ),
						esc_url( $settings_url )
					),
					array( 'a' => array( 'href' => array() ), 'code' => array() )
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open XPay settings', 'wc-gateway-xpay' ); ?></a>
				&nbsp;
				<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'wc-gateway-xpay' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'forbidden', 403 );
		}
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		if ( '' === $notice ) {
			wp_die( 'missing notice id', 400 );
		}
		check_admin_referer( 'xpay_dismiss_notice_' . $notice );

		update_user_meta( get_current_user_id(), self::DISMISS_META_PREFIX . $notice, time() );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	private static function is_dismissed( $notice ) {
		return (bool) get_user_meta( get_current_user_id(), self::DISMISS_META_PREFIX . $notice, true );
	}
}
