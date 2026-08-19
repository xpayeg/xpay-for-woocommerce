<?php
/**
 * XPay_Doc_Viewer
 *
 * A hidden admin page (admin.php?page=xpay-doc&doc=…) that shows the
 * plugin's bundled guides. PHP reads the file and prints it escaped, so
 * the page works everywhere WordPress does: linking the .md files by URL
 * broke on any host that refuses to serve unknown extensions from
 * plugin directories, which hardened setups do by policy.
 *
 * The guides render as the plain text they are — Markdown is designed to
 * be readable unrendered, and an escaped <pre> can never smuggle markup
 * into wp-admin. No parser, no parser bugs.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Doc_Viewer {

	/**
	 * Slug → [bundled file, translated title]. A whitelist by design: the
	 * request names a slug, never a path, so this page can only ever open
	 * the six merchant guides that ship in the distributable.
	 */
	private static function docs(): array {
		return array(
			'getting-started' => array( 'docs/GETTING_STARTED.md', __( 'Getting started guide', 'xpay-for-woocommerce' ) ),
			'configuration'   => array( 'docs/CONFIGURATION.md', __( 'Configuration reference', 'xpay-for-woocommerce' ) ),
			'troubleshooting' => array( 'docs/TROUBLESHOOTING.md', __( 'Troubleshooting', 'xpay-for-woocommerce' ) ),
			'webhooks'        => array( 'docs/WEBHOOKS.md', __( 'Webhooks reference', 'xpay-for-woocommerce' ) ),
			'going-live'      => array( 'docs/GOING_LIVE.md', __( 'Going live checklist', 'xpay-for-woocommerce' ) ),
			'compatibility'   => array( 'docs/COMPATIBILITY.md', __( 'Compatibility notes', 'xpay-for-woocommerce' ) ),
		);
	}

	/**
	 * @param string $slug Doc slug from the whitelist above.
	 */
	public static function url( string $slug ): string {
		return admin_url( 'admin.php?page=xpay-doc&doc=' . rawurlencode( $slug ) );
	}

	/**
	 * Registered with an empty parent slug: the documented way to get a
	 * routable admin page with no menu entry (registering under a parent
	 * and then remove_submenu_page-ing it fails the access check with a
	 * 403). The page is reached from the settings screen's doc links,
	 * not browsed to.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'',
			__( 'XPay guide', 'xpay-for-woocommerce' ),
			__( 'XPay guide', 'xpay-for-woocommerce' ),
			'manage_woocommerce',
			'xpay-doc',
			array( __CLASS__, 'render' )
		);
	}

	/** The settings screen's stylesheet carries the shell this page reuses. */
	public static function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen gating; no state is touched.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'xpay-doc' !== $page ) {
			return;
		}
		wp_enqueue_style(
			'xpay-admin-settings',
			XPAY_WC_PLUGIN_URL . 'assets/css/admin-settings.css',
			array(),
			XPay_Constants::asset_version( 'assets/css/admin-settings.css' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$docs = self::docs();
		// Whitelist lookup of a read-only view choice: nothing is echoed
		// from the request and no state changes, so there is no nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug = isset( $_GET['doc'] ) ? sanitize_key( wp_unslash( $_GET['doc'] ) ) : '';
		if ( ! isset( $docs[ $slug ] ) ) {
			$slug = 'getting-started';
		}
		list( $rel_path, $title ) = $docs[ $slug ];

		$path = XPAY_WC_PLUGIN_DIR . $rel_path;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin file from disk; wp_remote_get is for remote URLs and would turn a local read into an HTTP loop.
		$text = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

		echo '<div class="xpay-adm xpay-adm--doc">';
		echo '<div class="xpay-adm__band">';
		echo '<span class="xpay-adm__wordmark-pill"><img src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/xpay-wordmark.svg' ) . '" alt="XPay"></span>';
		echo '<span class="xpay-adm__band-title">' . esc_html( $title ) . '</span>';
		echo '<a class="xpay-adm__band-btn" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . XPay_Constants::GATEWAY_ID ) ) . '">' . esc_html__( 'XPay settings', 'xpay-for-woocommerce' ) . '</a>';
		echo '</div>';

		echo '<div class="xpay-adm__card xpay-adm__card--doc">';
		if ( '' === $text ) {
			echo '<p class="xpay-adm__doc-missing">' . esc_html__( 'This guide is missing from the installed plugin files. Reinstall the plugin to restore it.', 'xpay-for-woocommerce' ) . '</p>';
		} else {
			echo '<pre class="xpay-adm__doc-text">' . esc_html( $text ) . '</pre>';
		}
		echo '</div>';
		echo '</div>';
	}
}
