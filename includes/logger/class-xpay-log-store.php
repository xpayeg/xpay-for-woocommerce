<?php
/**
 * XPay_Log_Store
 *
 * Bounded, queryable storage behind the in-admin log viewer. One row per
 * logger event in a custom table — indexed by order, so "show me this
 * order's story" is a query, not log-file parsing. The WC_Logger stream
 * (XPay_Logger) stays untouched as the raw feed for developers; this table
 * exists for the merchant/support UI.
 *
 * Everything stored here is ALREADY redacted — rows arrive from
 * XPay_Logger::write() after XPay_Redactor ran. This class never handles
 * raw secrets.
 *
 * Writes are non-critical by contract: an insert failure is swallowed
 * (never breaks a payment), mirroring the monorepo's fire-and-forget
 * audit-row rule.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Log_Store {

	/** Bump when the schema changes; checked against the stored option on admin loads. */
	const DB_VERSION = 1;

	/** Retention window — matches v2's log rotation policy. */
	const RETENTION_DAYS = 14;

	/** Hard row cap so a chatty store can't grow the table unbounded within the window. */
	const MAX_ROWS = 10000;

	/** Context JSON cap per row (the audit-trail truncation rule). */
	const MAX_CONTEXT_BYTES = 16384;

	/** Daily prune cron hook. */
	const CRON_HOOK = 'xpay_wc_prune_log';

	/** Option storing the installed schema version. */
	const DB_VERSION_OPTION = 'xpay_wc_db_version';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'xpay_log';
	}

	/**
	 * Create/upgrade the table and schedule the prune cron. Runs on
	 * activation and (cheaply, via option check) after plugin updates.
	 */
	public static function install(): void {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION && wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				request_id VARCHAR(16) NOT NULL DEFAULT '',
				stage VARCHAR(64) NOT NULL DEFAULT '',
				order_id BIGINT UNSIGNED NULL,
				message TEXT NULL,
				context LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Store one redacted event row. Never throws — a logging failure must
	 * never fail the operation being logged.
	 *
	 * @param string $stage      Dot-separated stage.
	 * @param array  $context    Redacted context (order_id read from here when present).
	 * @param string $message    Optional scrubbed message.
	 * @param string $request_id Per-request correlation id.
	 */
	public static function insert( string $stage, array $context, string $message, string $request_id ): void {
		global $wpdb;

		$order_id = 0;
		if ( isset( $context['order_id'] ) && is_numeric( $context['order_id'] ) ) {
			$order_id = (int) $context['order_id'];
		}

		$json = wp_json_encode( $context );
		$json = is_string( $json ) ? substr( $json, 0, self::MAX_CONTEXT_BYTES ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table; no core API exists for it, and log rows are never cached.
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'request_id' => substr( $request_id, 0, 16 ),
				'stage'      => substr( $stage, 0, 64 ),
				'order_id'   => $order_id > 0 ? $order_id : null,
				'message'    => '' !== $message ? $message : null,
				'context'    => $json,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		// Insert failure is deliberately ignored: the WC_Logger stream still
		// has the entry, and the payment path must never depend on this table.
	}

	/**
	 * Newest-first rows for the viewer, the debug report, and the CSV export.
	 *
	 * Dates are UTC calendar days (the table stores gmdate timestamps and the
	 * viewer labels its time column UTC): date_from starts at 00:00:00,
	 * date_to ends at 23:59:59, both inclusive. The caller has already
	 * validated the Y-m-d shape — this method only binds.
	 *
	 * @param array $args { order_id?: int, request_id?: string, stage?: string,
	 *                      search?: string, date_from?: string, date_to?: string,
	 *                      limit?: int }.
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		// The viewer tails 100; only the explicit CSV export asks for the
		// full table, and the row cap bounds what that can ever cost.
		$limit = isset( $args['limit'] ) ? min( max( (int) $args['limit'], 1 ), self::MAX_ROWS ) : 100;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$values[] = (int) $args['order_id'];
		}
		if ( ! empty( $args['request_id'] ) ) {
			$where[]  = 'request_id = %s';
			$values[] = (string) $args['request_id'];
		}
		if ( ! empty( $args['stage'] ) ) {
			$where[]  = 'stage LIKE %s';
			$values[] = $wpdb->esc_like( (string) $args['stage'] ) . '%';
		}
		if ( ! empty( $args['search'] ) ) {
			// One free-text needle across the two human-readable columns.
			// Substring match, not prefix: the merchant searches for an order
			// key or an error word buried mid-JSON.
			$where[]  = '(message LIKE %s OR context LIKE %s)';
			$needle   = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$values[] = $needle;
			$values[] = $needle;
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = $args['date_to'] . ' 23:59:59';
		}

		$values[] = $limit;

		// $where holds only the fixed placeholder fragments assembled above —
		// every runtime value is placeholder-bound and the table name goes
		// through %i (WP 6.2+), so the interpolation the sniff sees is static.
		// The placeholder-count sniff cannot count through implode()+array_merge
		// either: placeholders and values are built in lockstep above (each
		// pushed fragment is followed by exactly as many value pushes as the
		// placeholders it declares — the search fragment declares two), so the
		// counts match by construction.
		// Plugin Check's own DirectDB sniff shares the implode() blindness, so
		// its code is silenced on the same justification.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table, no core API and no cache layer; see fragment notes above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, request_id, stage, order_id, message, context FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d',
				array_merge( array( self::table_name() ), $values )
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Time- and size-bound retention, run daily by cron. Two deletes: rows
	 * older than the window, then overflow beyond MAX_ROWS (oldest first).
	 */
	public static function prune(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table: no core API exists and log rows are never cached. Table name via %i, values placeholder-bound.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				self::table_name(),
				gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS )
			)
		);

		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table_name() ) );
		if ( $count > self::MAX_ROWS ) {
			$cutoff = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d',
					self::table_name(),
					self::MAX_ROWS - 1
				)
			);
			if ( $cutoff > 0 ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', self::table_name(), $cutoff ) );
			}
		}
		// phpcs:enable
	}

	/** Empty the table (admin "Clear log" action). */
	public static function clear(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin-owned table; TRUNCATE is the cheapest full clear and %i binds the identifier.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table_name() ) );
	}
}
