<?php
/**
 * XPay_Log_Viewer
 *
 * Admin screen (WooCommerce → XPay Log): a filterable tail of the log
 * store plus the one-click "Copy debug report" — the paste-into-a-ticket
 * bundle that turns a three-email support exchange into one.
 *
 * Trust boundary: wp-admin, manage_woocommerce capability, with a distinct
 * nonce per action (filter / clear), per the v2 per-endpoint-nonce rule.
 * Every value printed here was redacted at write time; the settings
 * snapshot in the debug report passes through XPay_Redactor again anyway —
 * belt and braces cost nothing.
 *
 * Nothing on this screen transmits anything anywhere: the merchant copies
 * the report manually. That keeps the plugin clean of consent obligations.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Log_Viewer {

	/** Rows shown per page load / included in the debug report. */
	const TAIL_ROWS   = 100;
	const REPORT_ROWS = 50;

	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'XPay Log', 'xpay-for-woocommerce' ),
			__( 'XPay Log', 'xpay-for-woocommerce' ),
			'manage_woocommerce',
			'xpay-log',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::handle_clear();

		wp_enqueue_script(
			'xpay-admin-log',
			XPAY_WC_PLUGIN_URL . 'assets/js/admin-log-viewer.js',
			array(),
			XPAY_WC_VERSION,
			true
		);

		$filters = self::read_filters();
		$rows    = XPay_Log_Store::query( array_merge( $filters, array( 'limit' => self::TAIL_ROWS ) ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'XPay Log', 'xpay-for-woocommerce' ) . '</h1>';

		if ( ! XPay_Logger::is_enabled() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Diagnostic logging is off. Turn it on in WooCommerce → Settings → Payments → XPay to record new entries.', 'xpay-for-woocommerce' ) . '</p></div>';
		}

		self::render_filter_form( $filters );
		self::render_debug_report();
		self::render_rows( $rows );
		self::render_clear_form();

		echo '</div>';
	}

	/* ── Sections ────────────────────────────────────────────────────── */

	private static function render_filter_form( array $filters ): void {
		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="xpay-log" />';
		wp_nonce_field( 'xpay-log-filter', '_xpaynonce', false );
		echo '<label>' . esc_html__( 'Order #', 'xpay-for-woocommerce' ) . ' <input type="number" name="order_id" value="' . esc_attr( $filters['order_id'] ? (string) $filters['order_id'] : '' ) . '" style="width:90px" /></label> ';
		echo '<label>' . esc_html__( 'Request id', 'xpay-for-woocommerce' ) . ' <input type="text" name="request_id" value="' . esc_attr( $filters['request_id'] ) . '" style="width:130px" /></label> ';
		echo '<label>' . esc_html__( 'Stage starts with', 'xpay-for-woocommerce' ) . ' <input type="text" name="stage" value="' . esc_attr( $filters['stage'] ) . '" placeholder="webhook." style="width:130px" /></label> ';
		submit_button( __( 'Filter', 'xpay-for-woocommerce' ), 'secondary', '', false );
		echo '</form>';
	}

	private static function render_debug_report(): void {
		echo '<p><button type="button" class="button button-primary" id="xpay-copy-report" data-copied="' . esc_attr__( 'Copied — paste it into your support ticket', 'xpay-for-woocommerce' ) . '">' . esc_html__( 'Copy debug report', 'xpay-for-woocommerce' ) . '</button></p>';
		echo '<textarea id="xpay-debug-report" readonly rows="4" style="width:100%;font-family:monospace;font-size:11px">' . esc_textarea( self::build_debug_report() ) . '</textarea>';
	}

	private static function render_rows( array $rows ): void {
		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr>';
		echo '<th style="width:150px">' . esc_html__( 'Time (UTC)', 'xpay-for-woocommerce' ) . '</th>';
		echo '<th style="width:110px">' . esc_html__( 'Request', 'xpay-for-woocommerce' ) . '</th>';
		echo '<th style="width:170px">' . esc_html__( 'Stage', 'xpay-for-woocommerce' ) . '</th>';
		echo '<th style="width:80px">' . esc_html__( 'Order', 'xpay-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'xpay-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No log entries match. New entries appear here as payments run.', 'xpay-for-woocommerce' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$order_cell = '—';
			if ( ! empty( $row['order_id'] ) ) {
				// get_edit_order_url() is storage-aware: HPOS orders live at
				// admin.php?page=wc-orders, not post.php — a hardcoded post
				// URL 404s there. Deleted orders degrade to a plain number.
				$log_order  = wc_get_order( (int) $row['order_id'] );
				$order_cell = $log_order instanceof WC_Order
					? '<a href="' . esc_url( $log_order->get_edit_order_url() ) . '">#' . (int) $row['order_id'] . '</a>'
					: '#' . (int) $row['order_id'];
			}
			$details = (string) $row['context'];
			if ( ! empty( $row['message'] ) ) {
				$details = $row['message'] . ' ' . $details;
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['request_id'] ) . '</code></td>';
			echo '<td><code>' . esc_html( (string) $row['stage'] ) . '</code></td>';
			echo '<td>' . wp_kses_post( $order_cell ) . '</td>';
			echo '<td style="font-family:monospace;font-size:11px;word-break:break-all">' . esc_html( $details ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_clear_form(): void {
		echo '<form method="post" style="margin-top:12px" onsubmit="return window.confirm(this.dataset.msg)" data-msg="' . esc_attr__( 'Delete all XPay log entries? This cannot be undone.', 'xpay-for-woocommerce' ) . '">';
		wp_nonce_field( 'xpay-log-clear' );
		echo '<input type="hidden" name="xpay_log_action" value="clear" />';
		submit_button( __( 'Clear log', 'xpay-for-woocommerce' ), 'delete', '', false );
		echo '</form>';
	}

	/* ── Actions & data ──────────────────────────────────────────────── */

	private static function handle_clear(): void {
		if ( ! isset( $_POST['xpay_log_action'] ) || 'clear' !== $_POST['xpay_log_action'] ) {
			return;
		}
		check_admin_referer( 'xpay-log-clear' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		XPay_Log_Store::clear();
		echo '<div class="notice notice-success"><p>' . esc_html__( 'XPay log cleared.', 'xpay-for-woocommerce' ) . '</p></div>';
	}

	/** @return array{order_id:int, request_id:string, stage:string} */
	private static function read_filters(): array {
		$empty = array(
			'order_id'   => 0,
			'request_id' => '',
			'stage'      => '',
		);
		// Read-only filters still carry a nonce (set by the filter form) so
		// the whole screen has zero unverified request reads.
		if ( ! isset( $_GET['_xpaynonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_xpaynonce'] ) ), 'xpay-log-filter' ) ) {
			return $empty;
		}
		return array(
			'order_id'   => isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0,
			'request_id' => isset( $_GET['request_id'] ) ? sanitize_text_field( wp_unslash( $_GET['request_id'] ) ) : '',
			'stage'      => isset( $_GET['stage'] ) ? sanitize_text_field( wp_unslash( $_GET['stage'] ) ) : '',
		);
	}

	/**
	 * The paste-into-a-ticket bundle: environment, redacted gateway config,
	 * and the recent tail. Plain text on purpose — it must survive email,
	 * Slack, and ticket systems without mangling.
	 */
	public static function build_debug_report(): string {
		global $wp_version;

		$gateway  = XPay_Plugin::instance()->gateway();
		$settings = XPay_Redactor::redact( get_option( 'woocommerce_' . XPay_Constants::GATEWAY_ID . '_settings', array() ) );

		$lines   = array();
		$lines[] = '=== XPay for WooCommerce debug report ===';
		$lines[] = 'generated_utc: ' . gmdate( 'Y-m-d H:i:s' );
		$lines[] = 'plugin: ' . XPAY_WC_VERSION . ' | wp: ' . $wp_version . ' | wc: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | php: ' . PHP_VERSION;
		$lines[] = 'site: ' . home_url();
		$lines[] = 'mode: ' . ( $gateway->is_test_mode() ? 'test' : 'live' ) . ' | gateway_enabled: ' . $gateway->get_option( 'enabled' ) . ' | needs_setup: ' . ( $gateway->needs_setup() ? 'yes' : 'no' );
		$lines[] = 'webhook_url: ' . home_url( '/?wc-api=' . XPay_Constants::WEBHOOK_ENDPOINT );
		$lines[] = 'settings: ' . wp_json_encode( $settings );
		$lines[] = '--- last ' . self::REPORT_ROWS . ' log entries (newest first, redacted at write time) ---';

		foreach ( XPay_Log_Store::query( array( 'limit' => self::REPORT_ROWS ) ) as $row ) {
			$lines[] = sprintf(
				'[%s] [%s] %s%s%s %s',
				$row['created_at'],
				$row['request_id'],
				$row['stage'],
				! empty( $row['order_id'] ) ? ' order=' . (int) $row['order_id'] : '',
				// The human-readable reason must survive into support
				// tickets, not just the on-screen table.
				! empty( $row['message'] ) ? ' ' . $row['message'] : '',
				(string) $row['context']
			);
		}

		return implode( "\n", $lines );
	}
}
