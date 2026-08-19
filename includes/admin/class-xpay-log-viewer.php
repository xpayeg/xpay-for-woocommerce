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

		// The screen shares the settings screen's design-system stylesheet;
		// registering the same handle twice is a no-op.
		wp_enqueue_style(
			'xpay-admin-settings',
			XPAY_WC_PLUGIN_URL . 'assets/css/admin-settings.css',
			array(),
			XPay_Constants::asset_version( 'assets/css/admin-settings.css' )
		);
		wp_enqueue_script(
			'xpay-admin-log',
			XPAY_WC_PLUGIN_URL . 'assets/js/admin-log-viewer.js',
			array(),
			XPay_Constants::asset_version( 'assets/js/admin-log-viewer.js' ),
			true
		);

		$filters = self::read_filters();
		$rows    = XPay_Log_Store::query( array_merge( $filters, array( 'limit' => self::TAIL_ROWS ) ) );

		echo '<div class="wrap"><h1 class="screen-reader-text">' . esc_html__( 'XPay Log', 'xpay-for-woocommerce' ) . '</h1>';
		echo '<div class="xpay-adm">';

		// Band: title, live logging state, and the way back to settings.
		echo '<div class="xpay-adm__band">';
		echo '<span class="xpay-adm__wordmark-pill"><img src="' . esc_url( XPAY_WC_PLUGIN_URL . 'assets/images/xpay-wordmark.svg' ) . '" alt="XPay"></span>';
		echo '<span class="xpay-adm__band-title">' . esc_html__( 'XPay Log', 'xpay-for-woocommerce' ) . '</span>';
		if ( XPay_Logger::is_enabled() ) {
			echo '<span class="xpay-adm__badge xpay-adm__badge--green">' . esc_html__( 'Logging on', 'xpay-for-woocommerce' ) . '</span>';
		} else {
			echo '<span class="xpay-adm__badge xpay-adm__badge--amber">' . esc_html__( 'Logging off', 'xpay-for-woocommerce' ) . '</span>';
		}
		echo '<a class="xpay-adm__band-btn" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . XPay_Constants::GATEWAY_ID ) ) . '">' . esc_html__( 'Open XPay settings', 'xpay-for-woocommerce' ) . '</a>';
		echo '</div>';

		echo '<div class="xpay-adm__card xpay-adm__card--log">';

		if ( ! XPay_Logger::is_enabled() ) {
			echo '<div class="xpay-adm__row xpay-adm__row--first xpay-adm__row--last xpay-adm__row--warn">';
			echo '<span class="xpay-adm__row-icon xpay-adm__row-icon--amber">!</span>';
			echo '<span class="xpay-adm__row-main">' . esc_html__( 'Diagnostic logging is off. Turn it on in WooCommerce → Settings → Payments → XPay to record new entries.', 'xpay-for-woocommerce' ) . '</span>';
			echo '</div>';
		}

		self::render_toolbar( $filters );
		self::render_debug_report( $filters );
		self::render_rows( $rows );

		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/* ── Sections ────────────────────────────────────────────────────── */

	private static function render_toolbar( array $filters ): void {
		echo '<div class="xpay-adm__toolbar">';

		// Filter form (GET, nonce-carrying).
		echo '<form method="get" class="xpay-adm__filters">';
		echo '<input type="hidden" name="page" value="xpay-log">';
		wp_nonce_field( 'xpay-log-filter', '_xpaynonce', false );
		echo '<input class="xpay-adm__input xpay-adm__input--w110" type="number" name="order_id" value="' . esc_attr( $filters['order_id'] ? (string) $filters['order_id'] : '' ) . '" placeholder="' . esc_attr__( 'Order #', 'xpay-for-woocommerce' ) . '" aria-label="' . esc_attr__( 'Order #', 'xpay-for-woocommerce' ) . '">';
		echo '<input class="xpay-adm__input xpay-adm__input--w150 xpay-adm__mono" type="text" name="request_id" value="' . esc_attr( $filters['request_id'] ) . '" placeholder="' . esc_attr__( 'Request id', 'xpay-for-woocommerce' ) . '" aria-label="' . esc_attr__( 'Request id', 'xpay-for-woocommerce' ) . '">';
		self::render_stage_select( $filters['stage'] );
		echo '<input class="xpay-adm__input xpay-adm__input--grow" type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search', 'xpay-for-woocommerce' ) . '" aria-label="' . esc_attr__( 'Search messages and details', 'xpay-for-woocommerce' ) . '">';
		// The table's time column is UTC; the date bounds mean the same days.
		echo '<input class="xpay-adm__input xpay-adm__input--w150" type="date" name="date_from" value="' . esc_attr( $filters['date_from'] ) . '" title="' . esc_attr__( 'From date (UTC)', 'xpay-for-woocommerce' ) . '" aria-label="' . esc_attr__( 'From date (UTC)', 'xpay-for-woocommerce' ) . '">';
		echo '<input class="xpay-adm__input xpay-adm__input--w150" type="date" name="date_to" value="' . esc_attr( $filters['date_to'] ) . '" title="' . esc_attr__( 'To date (UTC)', 'xpay-for-woocommerce' ) . '" aria-label="' . esc_attr__( 'To date (UTC)', 'xpay-for-woocommerce' ) . '">';
		echo '<button type="submit" class="xpay-adm__btn xpay-adm__btn--secondary">' . esc_html__( 'Filter', 'xpay-for-woocommerce' ) . '</button>';
		echo '</form>';

		// Actions row: "Clear filters" anchors the start (only while filters
		// are active), the copy / export / clear controls anchor the end.
		echo '<div class="xpay-adm__toolbar-actions">';
		if ( self::has_filters( $filters ) ) {
			echo '<a class="xpay-adm__filters-clear" href="' . esc_url( admin_url( 'admin.php?page=xpay-log' ) ) . '">' . esc_html__( 'Clear filters', 'xpay-for-woocommerce' ) . '</a>';
		}
		echo '<button type="button" class="xpay-adm__btn" id="xpay-copy-report" data-copied="' . esc_attr__( 'Copied. Paste it into your support ticket', 'xpay-for-woocommerce' ) . '">' . esc_html__( 'Copy debug report', 'xpay-for-woocommerce' ) . '</button>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="xpay-adm__export">';
		echo '<input type="hidden" name="action" value="xpay_log_export">';
		wp_nonce_field( 'xpay-log-export' );
		foreach ( array( 'order_id', 'request_id', 'stage', 'search', 'date_from', 'date_to' ) as $field ) {
			$value = 'order_id' === $field ? ( $filters['order_id'] ? (string) $filters['order_id'] : '' ) : $filters[ $field ];
			if ( '' !== $value ) {
				echo '<input type="hidden" name="' . esc_attr( 'search' === $field ? 's' : $field ) . '" value="' . esc_attr( $value ) . '">';
			}
		}
		echo '<button type="submit" class="xpay-adm__btn xpay-adm__btn--secondary">' . esc_html__( 'Export CSV', 'xpay-for-woocommerce' ) . '</button>';
		echo '</form>';
		echo '<form method="post" class="xpay-adm__clear" onsubmit="return window.confirm(this.dataset.msg)" data-msg="' . esc_attr__( 'Delete all XPay log entries? This cannot be undone.', 'xpay-for-woocommerce' ) . '">';
		wp_nonce_field( 'xpay-log-clear' );
		echo '<input type="hidden" name="xpay_log_action" value="clear">';
		echo '<button type="submit" class="xpay-adm__btn xpay-adm__btn--danger">' . esc_html__( 'Clear log', 'xpay-for-woocommerce' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * The stage families the plugin writes, for the toolbar dropdown.
	 * Values are the LIKE prefixes the store filters by; labels are what
	 * a merchant reads. Update alongside any new XPay_Logger stage family
	 * — the same keep-in-step discipline as the translation dictionary.
	 *
	 * @return array<string, string> Prefix => translated label.
	 */
	private static function stage_families(): array {
		return array(
			'session.'         => __( 'Sessions', 'xpay-for-woocommerce' ),
			'webhook.'         => __( 'Webhooks', 'xpay-for-woocommerce' ),
			'order.'           => __( 'Orders', 'xpay-for-woocommerce' ),
			'refund.'          => __( 'Refunds', 'xpay-for-woocommerce' ),
			'api.'             => __( 'API calls', 'xpay-for-woocommerce' ),
			'customer.'        => __( 'Customers', 'xpay-for-woocommerce' ),
			'thankyou.'        => __( 'Confirmation page', 'xpay-for-woocommerce' ),
			'process_payment.' => __( 'Checkout errors', 'xpay-for-woocommerce' ),
			'order_lock.'      => __( 'Order locks', 'xpay-for-woocommerce' ),
			'compat.'          => __( 'Compatibility', 'xpay-for-woocommerce' ),
		);
	}

	/**
	 * Stage filter as a dropdown of the families above — a merchant picks
	 * "Webhooks", not a prefix syntax. The backend still filters by
	 * prefix, so a deep link carrying an exact stage
	 * (?stage=webhook.rejected) keeps working: it renders as an extra
	 * selected option instead of being silently dropped.
	 *
	 * @param string $current The active stage filter value.
	 */
	private static function render_stage_select( string $current ): void {
		$families = self::stage_families();
		echo '<select class="xpay-adm__input xpay-adm__select" name="stage" aria-label="' . esc_attr__( 'Stage', 'xpay-for-woocommerce' ) . '">';
		echo '<option value="">' . esc_html__( 'All stages', 'xpay-for-woocommerce' ) . '</option>';
		foreach ( $families as $prefix => $label ) {
			echo '<option value="' . esc_attr( $prefix ) . '"' . selected( $current, $prefix, false ) . '>' . esc_html( $label ) . '</option>';
		}
		if ( '' !== $current && ! isset( $families[ $current ] ) ) {
			echo '<option value="' . esc_attr( $current ) . '" selected>' . esc_html( $current ) . '</option>';
		}
		echo '</select>';
	}

	private static function render_debug_report( array $filters ): void {
		echo '<textarea id="xpay-debug-report" class="xpay-adm__report xpay-adm__mono" readonly rows="4">' . esc_textarea( self::build_debug_report( $filters ) ) . '</textarea>';
	}

	private static function render_rows( array $rows ): void {
		// The wrapper scrolls sideways on narrow screens; without it the
		// details column gets crushed to a one-character sliver.
		echo '<div class="xpay-adm__tablewrap">';
		echo '<table class="xpay-adm__table"><thead><tr>';
		echo '<th style="width:150px">' . esc_html__( 'Time (UTC)', 'xpay-for-woocommerce' ) . '</th>';
		echo '<th style="width:110px">' . esc_html__( 'Request', 'xpay-for-woocommerce' ) . '</th>';
		echo '<th style="width:180px">' . esc_html__( 'Stage', 'xpay-for-woocommerce' ) . '</th>';
		echo '<th style="width:80px">' . esc_html__( 'Order', 'xpay-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'xpay-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="5" class="xpay-adm__table-empty">' . esc_html__( 'No log entries match. New entries appear here as payments run.', 'xpay-for-woocommerce' ) . '</td></tr>';
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
			// The row carries its full values; cells show a truncated line
			// and the details button opens the dialog with everything.
			echo '<tr'
				. ' data-time="' . esc_attr( (string) $row['created_at'] ) . '"'
				. ' data-request="' . esc_attr( (string) $row['request_id'] ) . '"'
				. ' data-stage="' . esc_attr( (string) $row['stage'] ) . '"'
				. ' data-order="' . esc_attr( ! empty( $row['order_id'] ) ? (string) (int) $row['order_id'] : '' ) . '"'
				. ' data-message="' . esc_attr( (string) $row['message'] ) . '"'
				. ' data-context="' . esc_attr( (string) $row['context'] ) . '"'
				. '>';
			echo '<td class="xpay-adm__cell-muted">' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '<td class="xpay-adm__mono">' . esc_html( (string) $row['request_id'] ) . '</td>';
			echo '<td class="xpay-adm__mono">' . esc_html( (string) $row['stage'] ) . '</td>';
			echo '<td>' . wp_kses_post( $order_cell ) . '</td>';
			if ( '' === trim( $details ) ) {
				echo '<td class="xpay-adm__mono xpay-adm__cell-details">—</td>';
			} else {
				echo '<td class="xpay-adm__mono xpay-adm__cell-details"><button type="button" class="xpay-adm__cell-more" aria-haspopup="dialog" title="' . esc_attr__( 'View the full entry', 'xpay-for-woocommerce' ) . '">' . esc_html( $details ) . '</button></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		if ( count( $rows ) === self::TAIL_ROWS ) {
			/* translators: %d is the number of log entries shown. */
			echo '<p class="xpay-adm__table-note">' . esc_html( sprintf( __( 'Showing the latest %d entries. Narrow with the filters, or Export CSV for everything retained.', 'xpay-for-woocommerce' ), self::TAIL_ROWS ) ) . '</p>';
		}

		self::render_entry_dialog();
	}

	/**
	 * The full-entry dialog the details cells open. One empty skeleton;
	 * admin-log-viewer.js fills it from the clicked row's data attributes
	 * (values land via textContent, never markup) and pretty-prints the
	 * context JSON.
	 */
	private static function render_entry_dialog(): void {
		echo '<div class="xpay-adm__dialog-backdrop" id="xpay-log-dialog" hidden>';
		echo '<div class="xpay-adm__dialog" role="dialog" aria-modal="true" aria-labelledby="xpay-log-dialog-title">';
		echo '<div class="xpay-adm__dialog-head">';
		echo '<h2 class="xpay-adm__dialog-title" id="xpay-log-dialog-title">' . esc_html__( 'Log entry', 'xpay-for-woocommerce' ) . '</h2>';
		echo '<button type="button" class="xpay-adm__dialog-close" aria-label="' . esc_attr__( 'Close', 'xpay-for-woocommerce' ) . '">&times;</button>';
		echo '</div>';
		echo '<div class="xpay-adm__dialog-meta">';
		echo '<span class="xpay-adm__dialog-label">' . esc_html__( 'Time (UTC)', 'xpay-for-woocommerce' ) . '</span><span class="xpay-adm__mono" data-xpay-dlg="time"></span>';
		echo '<span class="xpay-adm__dialog-label">' . esc_html__( 'Request', 'xpay-for-woocommerce' ) . '</span><span class="xpay-adm__mono" data-xpay-dlg="request"></span>';
		echo '<span class="xpay-adm__dialog-label">' . esc_html__( 'Stage', 'xpay-for-woocommerce' ) . '</span><span class="xpay-adm__mono" data-xpay-dlg="stage"></span>';
		echo '<span class="xpay-adm__dialog-label">' . esc_html__( 'Order', 'xpay-for-woocommerce' ) . '</span><span class="xpay-adm__mono" data-xpay-dlg="order"></span>';
		echo '</div>';
		echo '<p class="xpay-adm__dialog-message" data-xpay-dlg="message" hidden></p>';
		echo '<pre class="xpay-adm__dialog-context xpay-adm__mono" data-xpay-dlg="context"></pre>';
		echo '<div class="xpay-adm__dialog-foot">';
		echo '<button type="button" id="xpay-log-copy-entry" class="xpay-adm__btn xpay-adm__btn--secondary xpay-adm__btn--sm" data-copied="' . esc_attr__( 'Copied', 'xpay-for-woocommerce' ) . '">' . esc_html__( 'Copy entry', 'xpay-for-woocommerce' ) . '</button>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
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

	/** @return array{order_id:int, request_id:string, stage:string, search:string, date_from:string, date_to:string} */
	private static function read_filters(): array {
		// Read-only filters still carry a nonce (set by the filter form) so
		// the whole screen has zero unverified request reads.
		if ( ! isset( $_GET['_xpaynonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_xpaynonce'] ) ), 'xpay-log-filter' ) ) {
			return array(
				'order_id'   => 0,
				'request_id' => '',
				'stage'      => '',
				'search'     => '',
				'date_from'  => '',
				'date_to'    => '',
			);
		}
		return self::sanitize_filters();
	}

	/**
	 * Pure sanitization of the filter query args — shared by the screen
	 * (after its filter nonce) and the CSV export (after its export nonce).
	 * Every caller has ALREADY verified a nonce; this reads values only.
	 */
	private static function sanitize_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- both callers verified their action's nonce before calling.
		return array(
			'order_id'   => isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0,
			'request_id' => isset( $_GET['request_id'] ) ? sanitize_text_field( wp_unslash( $_GET['request_id'] ) ) : '',
			'stage'      => isset( $_GET['stage'] ) ? sanitize_text_field( wp_unslash( $_GET['stage'] ) ) : '',
			'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'date_from'  => isset( $_GET['date_from'] ) ? self::sanitize_date( sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) ) : '',
			'date_to'    => isset( $_GET['date_to'] ) ? self::sanitize_date( sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) ) : '',
		);
		// phpcs:enable
	}

	/** A real calendar date in Y-m-d, or ''. Hand-edited URLs get '' — never a string MySQL has to guess about. */
	private static function sanitize_date( string $value ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $value;
	}

	private static function has_filters( array $filters ): bool {
		return $filters['order_id'] > 0
			|| '' !== $filters['request_id']
			|| '' !== $filters['stage']
			|| '' !== $filters['search']
			|| '' !== $filters['date_from']
			|| '' !== $filters['date_to'];
	}

	/**
	 * The paste-into-a-ticket bundle: environment, redacted gateway config,
	 * and the recent tail. Plain text on purpose — it must survive email,
	 * Slack, and ticket systems without mangling. Deliberately untranslated:
	 * it is read by XPay support, and a fixed format is what they grep.
	 *
	 * The tail honors the screen's active filters, so "filter to the broken
	 * order, copy, paste" sends support exactly the story in question —
	 * with a filter line saying so, because a report that silently omits
	 * rows reads as "nothing else happened".
	 *
	 * @param array $filters The screen's active filters (read_filters shape).
	 */
	public static function build_debug_report( array $filters = array() ): string {
		global $wp_version;

		$gateway  = XPay_Plugin::instance()->gateway();
		$settings = XPay_Redactor::redact( get_option( 'woocommerce_' . XPay_Constants::GATEWAY_ID . '_settings', array() ) );
		$filtered = array() !== $filters && self::has_filters( $filters );

		$lines   = array();
		$lines[] = '=== XPay for WooCommerce debug report ===';
		$lines[] = 'generated_utc: ' . gmdate( 'Y-m-d H:i:s' );
		$lines[] = 'plugin: ' . XPAY_WC_VERSION . ' | wp: ' . $wp_version . ' | wc: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' | php: ' . PHP_VERSION;
		$lines[] = 'site: ' . home_url();
		$lines[] = 'mode: ' . ( $gateway->is_test_mode() ? 'test' : 'live' ) . ' | gateway_enabled: ' . $gateway->get_option( 'enabled' ) . ' | needs_setup: ' . ( $gateway->needs_setup() ? 'yes' : 'no' );
		$lines[] = 'webhook_url: ' . home_url( '/?wc-api=' . XPay_Constants::WEBHOOK_ENDPOINT );
		$lines[] = 'settings: ' . wp_json_encode( $settings );
		$lines[] = 'log_filter: ' . ( $filtered ? self::describe_filters( $filters ) : 'none (latest entries)' );
		$lines[] = '--- last ' . self::REPORT_ROWS . ' log entries' . ( $filtered ? ' matching the filter' : '' ) . ' (newest first, redacted at write time) ---';

		foreach ( XPay_Log_Store::query( array_merge( $filters, array( 'limit' => self::REPORT_ROWS ) ) ) as $row ) {
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

	/** One-line untranslated summary of active filters for the debug report. */
	private static function describe_filters( array $filters ): string {
		$parts = array();
		if ( $filters['order_id'] > 0 ) {
			$parts[] = 'order=' . $filters['order_id'];
		}
		if ( '' !== $filters['request_id'] ) {
			$parts[] = 'request=' . $filters['request_id'];
		}
		if ( '' !== $filters['stage'] ) {
			$parts[] = 'stage=' . $filters['stage'] . '*';
		}
		if ( '' !== $filters['search'] ) {
			$parts[] = 'search="' . $filters['search'] . '"';
		}
		if ( '' !== $filters['date_from'] ) {
			$parts[] = 'from=' . $filters['date_from'];
		}
		if ( '' !== $filters['date_to'] ) {
			$parts[] = 'to=' . $filters['date_to'];
		}
		return implode( ' ', $parts );
	}

	/* ── CSV export ──────────────────────────────────────────────────── */

	/**
	 * Stream the filtered rows as CSV (admin-post.php?action=xpay_log_export).
	 * Runs before any admin output, so headers are still ours to send. The
	 * export honors the same filters as the screen — the hidden fields in the
	 * export form carry them — and spans the whole retained table, not just
	 * the 100-row tail. Rows were redacted at write time; the cell guard
	 * below only defuses spreadsheet formula injection.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to export the XPay log.', 'xpay-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'xpay-log-export' );

		$rows = XPay_Log_Store::query( array_merge( self::sanitize_filters(), array( 'limit' => XPay_Log_Store::MAX_ROWS ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="xpay-log-' . gmdate( 'Ymd-His' ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV to the response body; WP_Filesystem writes files, it cannot stream php://output.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'time_utc', 'request_id', 'stage', 'order_id', 'message', 'context' ), ',', '"', '' );
		foreach ( $rows as $row ) {
			$cells = array(
				(string) $row['created_at'],
				(string) $row['request_id'],
				(string) $row['stage'],
				! empty( $row['order_id'] ) ? (string) (int) $row['order_id'] : '',
				(string) $row['message'],
				(string) $row['context'],
			);
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $cells ), ',', '"', '' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the php://output stream opened above.
		fclose( $out );
		exit;
	}

	/**
	 * Defuse spreadsheet formula injection: log text can contain
	 * attacker-influenced strings (API error bodies, webhook payload
	 * fragments), and Excel/Sheets execute cells starting with = + - @ or a
	 * tab. A leading apostrophe makes the cell inert text; honest data
	 * starting with those characters survives, just displayed as text.
	 *
	 * @param string $cell Raw cell value.
	 */
	private static function csv_cell( string $cell ): string {
		if ( '' !== $cell && strpbrk( $cell[0], "=+-@\t\r" ) !== false ) {
			return "'" . $cell;
		}
		return $cell;
	}
}
