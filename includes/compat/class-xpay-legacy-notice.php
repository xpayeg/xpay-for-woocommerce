<?php
/**
 * XPay_Legacy_Notice
 *
 * Upgrade-path guard: the retired "WooCommerce XPAY Gateway" (v2) plugin
 * coexists with this one without fatals — different class names, different
 * gateway id (xpay_gateway vs xpay) — which is exactly the problem: nothing
 * crashes, so a merchant who installs v3 without deactivating v2 quietly
 * ships TWO XPay options at checkout, two settings screens, and v2's
 * legacy webhook endpoint. This notice is how they find out before their
 * shoppers do.
 *
 * Settings are deliberately NOT migrated: v2 keys are v2-API credentials
 * and the v3 plugin uses the v3 API's rk_/pk_ keys — carrying them over
 * would produce a configured-looking gateway that cannot charge.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Legacy_Notice {

	/** Per-user persistent dismissal flag (mid-migration admins know). */
	const DISMISS_META = 'xpay_legacy_notice_dismissed';

	/** Query arg + nonce action of the dismiss link. */
	const DISMISS_ARG = 'xpay-dismiss-legacy';

	public static function register_admin(): void {
		add_action( 'admin_init', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Detect the retired plugin by its runtime fingerprints, not the
	 * active-plugins list — survives folder renames, and both signals are
	 * defined unconditionally when v2 boots.
	 */
	public static function is_legacy_active(): bool {
		return class_exists( 'WC_Gateway_Xpay' ) || defined( 'WC_XPAY_VERSION' );
	}

	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::is_legacy_active() ) {
			return;
		}
		if ( '1' === (string) get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}

		$plugins_url = admin_url( 'plugins.php' );
		$dismiss_url = wp_nonce_url( add_query_arg( self::DISMISS_ARG, '1' ), self::DISMISS_ARG );

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'XPay: the legacy XPay plugin is still active alongside this one. Shoppers currently see two separate XPay options at checkout. Deactivate the legacy plugin. Its settings are separate, and this plugin keeps its own keys and orders.', 'xpay-for-woocommerce' );
		echo '</p><p>';
		echo '<a class="button button-primary" href="' . esc_url( $plugins_url ) . '">' . esc_html__( 'Open the Plugins page', 'xpay-for-woocommerce' ) . '</a> ';
		echo '<a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss: I am mid-migration and know', 'xpay-for-woocommerce' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Persist the dismissal for this user. User meta, not a transient:
	 * a dismissal must survive cache flushes and upgrades.
	 */
	public static function handle_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_ARG ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, '1' );
		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) ) );
		exit;
	}
}
