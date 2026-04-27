<?php
/**
 * Admin UI for the XPay flow logger. Lives under Tools → XPay Logger and
 * is only visible/usable to users with manage_woocommerce.
 *
 * The page is read-only with respect to the payment flow. Its only side
 * effects are:
 *   - clearing the log (action button, nonce-protected)
 *   - downloading the log (action button, nonce-protected)
 *   - writing a single diagnostics.snapshot entry (button, nonce-protected)
 */

defined( 'ABSPATH' ) or exit;

// tail_file() reads chunks from the END of the log via fopen + fseek +
// fread, so we don't load multi-MB log files into memory. WP_Filesystem
// has no equivalent partial-read API. handle_download() streams the log
// to the browser via readfile() to avoid buffering the whole file. Both
// patterns are intentional and PCP's AlternativeFunctions warnings on
// these particular calls are accepted.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_readfile

final class WC_XPay_Logger_Admin {

	const PAGE_SLUG = 'xpay-logger';
	const CAP       = 'manage_woocommerce';

	public static function register() {
		add_action( 'admin_menu',                                array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_post_xpay_logger_clear',              array( __CLASS__, 'handle_clear' ) );
		add_action( 'admin_post_xpay_logger_download',           array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_xpay_logger_diagnostics',        array( __CLASS__, 'handle_diagnostics' ) );
		add_action( 'wp_ajax_xpay_logger_tail',                  array( __CLASS__, 'ajax_tail' ) );
	}

	public static function add_menu_page() {
		add_management_page(
			__( 'XPay Logger', 'xpay-for-woocommerce' ),
			__( 'XPay Logger', 'xpay-for-woocommerce' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'xpay-for-woocommerce' ) );
		}

		$enabled       = WC_XPay_Logger::is_enabled();
		$log_path      = WC_XPay_Logger::current_log_path();
		$file_size     = ( $log_path && file_exists( $log_path ) ) ? size_format( (int) filesize( $log_path ), 1 ) : '0 B';
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=xpay_gateway' );
		$clear_url     = wp_nonce_url(
			admin_url( 'admin-post.php?action=xpay_logger_clear' ),
			'xpay_logger_clear'
		);
		$download_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=xpay_logger_download' ),
			'xpay_logger_download'
		);
		$diagnose_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=xpay_logger_diagnostics' ),
			'xpay_logger_diagnostics'
		);
		$tail_nonce    = wp_create_nonce( 'xpay_logger_tail' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'XPay Logger', 'xpay-for-woocommerce' ); ?></h1>

			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							wp_kses(
								/* translators: %s = link to gateway settings */
								__( 'The logger is currently <strong>disabled</strong>. Enable it from the <a href="%s">XPay gateway settings</a> to start recording. While disabled the logger has zero runtime cost — no listeners are attached.', 'xpay-for-woocommerce' ),
								array( 'strong' => array(), 'a' => array( 'href' => array() ) )
							),
							esc_url( $settings_url )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php
			// Flash message after one of our own admin-post handlers below
			// (clear / download / diagnostics), each of which already runs
			// check_admin_referer on the action that set the redirect target.
			// The page itself requires manage_woocommerce (registered via
			// add_management_page with self::CAP), the value is sanitized
			// and esc_html'd on output, and an attacker who crafts a URL
			// can inject only plain text inside the merchant's own admin
			// notice — no HTML, no script. Adding a separate nonce on the
			// flash message would be a no-op against the actual threat
			// model, so we phpcs:ignore the recommended-nonce check rather
			// than carry a redundant token through the redirect.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['xpay_logger_msg'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$msg = sanitize_text_field( wp_unslash( $_GET['xpay_logger_msg'] ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
			?>

			<div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap; margin-top:16px;">
				<div style="flex:2; min-width:520px;">
					<div style="background:#fff; border:1px solid #ccd0d4; padding:12px 16px; margin-bottom:12px;">
						<strong><?php esc_html_e( 'Status', 'xpay-for-woocommerce' ); ?>:</strong>
						<?php
						if ( $enabled ) {
							echo '<span style="color:#1e8449;">● ' . esc_html__( 'Live', 'xpay-for-woocommerce' ) . '</span>';
						} else {
							echo '<span style="color:#888;">○ ' . esc_html__( 'Paused', 'xpay-for-woocommerce' ) . '</span>';
						}
						?>
						&nbsp;|&nbsp;
						<strong><?php esc_html_e( 'Today\'s log', 'xpay-for-woocommerce' ); ?>:</strong>
						<?php echo esc_html( $log_path ? str_replace( ABSPATH, '', $log_path ) : __( '(not yet created)', 'xpay-for-woocommerce' ) ); ?>
						(<?php echo esc_html( $file_size ); ?>)
					</div>

					<div style="margin-bottom:12px;">
						<a class="button" href="<?php echo esc_url( $diagnose_url ); ?>"><?php esc_html_e( 'Run diagnostics snapshot', 'xpay-for-woocommerce' ); ?></a>
						<a class="button" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download today\'s log', 'xpay-for-woocommerce' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( $clear_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete today\'s log file? This cannot be undone.', 'xpay-for-woocommerce' ) ); ?>');"><?php esc_html_e( 'Clear today\'s log', 'xpay-for-woocommerce' ); ?></a>
					</div>

					<div style="margin-bottom:8px;">
						<label>
							<input type="checkbox" id="xpay-logger-live" checked>
							<?php esc_html_e( 'Live tail (auto-refresh every 5s)', 'xpay-for-woocommerce' ); ?>
						</label>
						&nbsp;&nbsp;
						<input type="text" id="xpay-logger-grep" placeholder="<?php esc_attr_e( 'Filter by text (e.g. order=42, webhook, error)', 'xpay-for-woocommerce' ); ?>" style="width:320px;">
						<select id="xpay-logger-stage">
							<option value=""><?php esc_html_e( 'All stages', 'xpay-for-woocommerce' ); ?></option>
							<option value="boot">boot</option>
							<option value="prefs.fetch">prefs.fetch</option>
							<option value="payment_fields.render">payment_fields.render</option>
							<option value="process_payment">process_payment.*</option>
							<option value="prepare_amount">prepare_amount.*</option>
							<option value="pay">pay.*</option>
							<option value="webhook">webhook.*</option>
							<option value="check_transaction">check_transaction.*</option>
							<option value="modal">modal.*</option>
							<option value="diagnostics">diagnostics.*</option>
						</select>
					</div>

					<pre id="xpay-logger-tail" style="background:#1d1f21; color:#c5c8c6; padding:12px; border-radius:4px; height:520px; overflow:auto; font-family: Menlo, Consolas, monospace; font-size:12px; line-height:1.45; white-space:pre-wrap; word-break:break-word;">
<?php esc_html_e( 'Loading…', 'xpay-for-woocommerce' ); ?>
					</pre>
				</div>

				<div style="flex:1; min-width:280px;">
					<div style="background:#fff; border:1px solid #ccd0d4; padding:12px 16px;">
						<h2 style="margin-top:0;"><?php esc_html_e( 'Stages reference', 'xpay-for-woocommerce' ); ?></h2>
						<dl style="font-size:12px; line-height:1.6;">
							<dt><strong>boot</strong></dt><dd><?php esc_html_e( 'Per-request snapshot of versions, theme, conflicting plugins.', 'xpay-for-woocommerce' ); ?></dd>
							<dt><strong>boot.hooks_inventory</strong></dt><dd><?php esc_html_e( 'Non-XPay callbacks attached to checkout hooks.', 'xpay-for-woocommerce' ); ?></dd>
							<dt><strong>prefs.fetch</strong></dt><dd><?php esc_html_e( 'Community preferences API call (methods, promo flag).', 'xpay-for-woocommerce' ); ?></dd>
							<dt><strong>payment_fields.render</strong></dt><dd><?php esc_html_e( 'Gateway radios rendered (classic checkout context).', 'xpay-for-woocommerce' ); ?></dd>
							<dt><strong>process_payment.start / .prepare / .pay / .end</strong></dt><dd><?php esc_html_e( 'Server-side payment lifecycle, with HTTP timings.', 'xpay-for-woocommerce' ); ?></dd>
							<dt><strong>webhook.received / .lookup / .applied</strong></dt><dd><?php esc_html_e( 'Inbound webhook from XPay; signature state, branch taken.', 'xpay-for-woocommerce' ); ?></dd>
							<dt><strong>check_transaction</strong></dt><dd><?php esc_html_e( 'Modal poll endpoint hits; returned status.', 'xpay-for-woocommerce' ); ?></dd>
							<dt><strong>modal.client_event</strong></dt><dd><?php esc_html_e( 'Browser-side events (modal shown/hidden, JS errors).', 'xpay-for-woocommerce' ); ?></dd>
							<dt><strong>diagnostics.snapshot</strong></dt><dd><?php esc_html_e( 'On-demand environment dump (run from button above).', 'xpay-for-woocommerce' ); ?></dd>
						</dl>
						<p style="font-size:12px; color:#666;">
							<?php esc_html_e( 'Secrets and PII are redacted at write time. Logs older than 30 days are pruned automatically.', 'xpay-for-woocommerce' ); ?>
						</p>
					</div>
				</div>
			</div>

			<script>
			(function () {
				var endpoint = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var nonce    = <?php echo wp_json_encode( $tail_nonce ); ?>;
				var $tail    = document.getElementById('xpay-logger-tail');
				var $live    = document.getElementById('xpay-logger-live');
				var $grep    = document.getElementById('xpay-logger-grep');
				var $stage   = document.getElementById('xpay-logger-stage');
				var pollMs   = 5000;
				var inFlight = false;
				var pausedByScroll = false;

				function escapeHtml (s) {
					return s.replace(/[&<>"']/g, function (c) {
						return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
					});
				}

				function applyFilters (lines) {
					var grep  = ($grep.value || '').trim().toLowerCase();
					var stage = ($stage.value || '').trim();
					var stageRe = /^\[[^\]]+\] \[[^\]]+\] \[([^\]]+)\]/;
					return lines.filter(function (line) {
						if (stage) {
							var m = line.match(stageRe);
							var stageBucket = m ? m[1] : '';
							if (stageBucket.indexOf(stage) !== 0) {
								return false;
							}
						}
						if (grep && line.toLowerCase().indexOf(grep) === -1) {
							return false;
						}
						return true;
					});
				}

				function colorize (lines) {
					return lines.map(function (line) {
						var html = escapeHtml(line);
						html = html.replace(/\[webhook\.[^\]]+\]/g, '<span style="color:#f0c674;">$&</span>');
						html = html.replace(/\[process_payment\.[^\]]+\]/g, '<span style="color:#81a2be;">$&</span>');
						html = html.replace(/\[modal\.[^\]]+\]/g, '<span style="color:#b294bb;">$&</span>');
						html = html.replace(/\[boot[^\]]*\]/g, '<span style="color:#8abeb7;">$&</span>');
						html = html.replace(/\[diagnostics[^\]]*\]/g, '<span style="color:#de935f;">$&</span>');
						html = html.replace(/non-2xx|wp_error|REJECTED|mismatch|failed|error/gi, '<span style="color:#cc6666;">$&</span>');
						return html;
					}).join('\n');
				}

				function fetchTail () {
					if (inFlight) { return; }
					inFlight = true;

					var fd = new FormData();
					fd.append('action', 'xpay_logger_tail');
					fd.append('nonce', nonce);
					fd.append('lines', '200');

					fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (data) {
							inFlight = false;
							if (!data || !data.success) { return; }
							var lines = (data.data && data.data.lines) || [];
							var filtered = applyFilters(lines);
							var atBottom = ($tail.scrollHeight - $tail.scrollTop - $tail.clientHeight) < 30;
							$tail.innerHTML = colorize(filtered) || '<em style="color:#888;">' + <?php echo wp_json_encode( esc_js( __( 'No matching entries.', 'xpay-for-woocommerce' ) ) ); ?> + '</em>';
							if (atBottom) { $tail.scrollTop = $tail.scrollHeight; }
						})
						.catch(function () { inFlight = false; });
				}

				$live.addEventListener('change', function () {
					if ($live.checked) { fetchTail(); }
				});
				$grep.addEventListener('input', fetchTail);
				$stage.addEventListener('change', fetchTail);

				fetchTail();
				setInterval(function () {
					if ($live.checked) { fetchTail(); }
				}, pollMs);
			})();
			</script>
		</div>
		<?php
	}

	/**
	 * AJAX: tail the current log file. Returns up to N most-recent lines.
	 */
	public static function ajax_tail() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'xpay_logger_tail', 'nonce' );

		$max_lines = isset( $_POST['lines'] ) ? min( 1000, max( 10, (int) $_POST['lines'] ) ) : 200;

		$path = WC_XPay_Logger::current_log_path();
		if ( ! $path || ! file_exists( $path ) ) {
			wp_send_json_success( array( 'lines' => array() ) );
		}

		$lines = self::tail_file( $path, $max_lines );
		wp_send_json_success( array( 'lines' => $lines ) );
	}

	/**
	 * Read the last N lines of a file efficiently. Reads chunks from the
	 * end so we don't load multi-MB log files into memory.
	 */
	private static function tail_file( $path, $n ) {
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) {
			return array();
		}
		fseek( $fh, 0, SEEK_END );
		$size       = ftell( $fh );
		$chunk      = 8192;
		$pos        = $size;
		$buf        = '';
		$line_count = 0;

		while ( $pos > 0 && $line_count <= $n ) {
			$read_size = min( $chunk, $pos );
			$pos      -= $read_size;
			fseek( $fh, $pos );
			$buf        = fread( $fh, $read_size ) . $buf;
			$line_count = substr_count( $buf, "\n" );
		}
		fclose( $fh );

		$lines = explode( "\n", $buf );
		// Strip empty trailing line from final \n
		if ( '' === end( $lines ) ) {
			array_pop( $lines );
		}
		if ( count( $lines ) > $n ) {
			$lines = array_slice( $lines, -$n );
		}
		return $lines;
	}

	public static function handle_clear() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'xpay_logger_clear' );

		$path = WC_XPay_Logger::current_log_path();
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		wp_safe_redirect( add_query_arg(
			'xpay_logger_msg',
			rawurlencode( __( 'Today\'s log cleared.', 'xpay-for-woocommerce' ) ),
			admin_url( 'tools.php?page=' . self::PAGE_SLUG )
		) );
		exit;
	}

	public static function handle_download() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'xpay_logger_download' );

		$path = WC_XPay_Logger::current_log_path();
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'No log file to download.', 'xpay-for-woocommerce' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Emits a single comprehensive diagnostics.snapshot entry that is
	 * intended to be the first thing a support engineer asks for.
	 */
	public static function handle_diagnostics() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( 'xpay_logger_diagnostics' );

		$settings = get_option( WC_XPay_Logger::SETTINGS_OPTION, array() );
		$gateway_settings_summary = array();
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, array( 'payment_api_key', 'webhook_secret' ), true ) ) {
				$gateway_settings_summary[ $key ] = empty( $value ) ? 'empty' : 'present (' . strlen( (string) $value ) . 'b)';
			} else {
				$gateway_settings_summary[ $key ] = $value;
			}
		}

		$wc_features = array();
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$wc_features['hpos_enabled'] = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		$path     = WC_XPay_Logger::current_log_path();
		$log_size = ( $path && file_exists( $path ) ) ? filesize( $path ) : 0;

		do_action(
			'xpay_logger_event',
			'diagnostics.snapshot',
			array(
				'wp_version'             => get_bloginfo( 'version' ),
				'wc_version'             => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'php_version'            => PHP_VERSION,
				'plugin_settings'        => $gateway_settings_summary,
				'wc_features'            => $wc_features,
				'php_extensions'         => array(
					'curl'     => extension_loaded( 'curl' ),
					'mbstring' => extension_loaded( 'mbstring' ),
					'openssl'  => extension_loaded( 'openssl' ),
					'json'     => extension_loaded( 'json' ),
				),
				'php_ini'                => array(
					'max_execution_time'      => (int) ini_get( 'max_execution_time' ),
					'memory_limit'            => ini_get( 'memory_limit' ),
					'default_socket_timeout'  => (int) ini_get( 'default_socket_timeout' ),
					'post_max_size'           => ini_get( 'post_max_size' ),
				),
				'log_path'               => $path ? str_replace( ABSPATH, '', $path ) : null,
				'log_size_bytes'         => $log_size,
				'cloudflare_detected'    => isset( $_SERVER['HTTP_CF_RAY'] ) || isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ),
				'tunnel_detected'        => isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ),
				'site_url'               => site_url(),
			),
			'on-demand diagnostics snapshot'
		);

		wp_safe_redirect( add_query_arg(
			'xpay_logger_msg',
			rawurlencode( __( 'Diagnostics snapshot written to log.', 'xpay-for-woocommerce' ) ),
			admin_url( 'tools.php?page=' . self::PAGE_SLUG )
		) );
		exit;
	}
}
