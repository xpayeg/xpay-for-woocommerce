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

			<style>
			/* ── XPay Logger structured view ─────────────────────────────── */
			#xpay-log-view { font-family: Menlo, Consolas, monospace; font-size: 12px; line-height: 1.5; }

			/* Stage badge */
			.xpay-badge { display:inline-block; padding:1px 6px; border-radius:3px; font-size:11px; font-weight:600; white-space:nowrap; }
			.xpay-badge-boot        { background:#f1f5f9; color:#475569; }
			.xpay-badge-prefs       { background:#dbeafe; color:#1e40af; }
			.xpay-badge-process_payment { background:#e0e7ff; color:#3730a3; }
			.xpay-badge-webhook     { background:#ede9fe; color:#5b21b6; }
			.xpay-badge-modal       { background:#ccfbf1; color:#115e59; }
			.xpay-badge-diagnostics { background:#fef3c7; color:#92400e; }
			.xpay-badge-errors      { background:#fee2e2; color:#991b1b; }
			.xpay-badge-other       { background:#e2e8f0; color:#334155; }

			/* Request group */
			.xpay-group { margin-bottom:8px; border:1px solid #ddd; border-radius:4px; overflow:hidden; }
			.xpay-group summary {
				display:flex; align-items:center; gap:8px; flex-wrap:wrap;
				padding:6px 10px; background:#f8f9fa; cursor:pointer;
				list-style:none; user-select:none;
			}
			.xpay-group summary::-webkit-details-marker { display:none; }
			.xpay-group summary::before { content:'▸'; font-size:10px; color:#666; transition:transform .15s; flex-shrink:0; }
			.xpay-group[open] summary::before { transform:rotate(90deg); }
			.xpay-group summary:hover { background:#eef0f2; }
			.xpay-group-req  { font-weight:700; color:#1d2327; letter-spacing:.03em; }
			.xpay-group-meta { color:#666; font-size:11px; }
			.xpay-group-count { background:#e2e8f0; color:#334155; font-size:10px; padding:1px 5px; border-radius:10px; }

			/* Event rows */
			.xpay-events { padding:0; margin:0; list-style:none; }
			.xpay-event  {
				display:flex; align-items:baseline; gap:6px; flex-wrap:wrap;
				padding:4px 10px; border-bottom:1px solid #f0f0f0;
				border-left:3px solid transparent;
			}
			.xpay-event:last-child { border-bottom:none; }
			.xpay-event.is-error   { border-left-color:#ef4444; background:#fff8f8; }
			.xpay-event-time  { color:#888; flex-shrink:0; min-width:68px; }
			.xpay-event-name  { color:#1d2327; font-weight:600; }
			.xpay-event-order { color:#666; font-size:11px; }
			.xpay-event-headline { color:#444; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
			.xpay-event-msg   { color:#777; font-style:italic; font-size:11px; }

			/* JSON expand toggle */
			.xpay-json-toggle { font-size:10px; color:#888; cursor:pointer; text-decoration:underline; flex-shrink:0; }
			.xpay-json-block  { width:100%; margin:2px 0 4px 0; }
			.xpay-json-block pre {
				margin:0; padding:8px; background:#1d1f21; color:#c5c8c6;
				border-radius:3px; overflow:auto; max-height:300px;
				font-size:11px; white-space:pre; tab-size:2;
			}

			/* hidden-by-filter */
			.xpay-hidden { display:none !important; }
			</style>

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

					<div style="margin-bottom:8px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
						<label style="white-space:nowrap;">
							<input type="checkbox" id="xpay-logger-live" checked>
							<?php esc_html_e( 'Live tail (auto-refresh every 5s)', 'xpay-for-woocommerce' ); ?>
						</label>
						<label style="white-space:nowrap;">
							<input type="checkbox" id="xpay-show-diag">
							<?php esc_html_e( 'Show diagnostic events (boot.hooks_inventory etc.)', 'xpay-for-woocommerce' ); ?>
						</label>
						<input type="text" id="xpay-logger-grep"
							placeholder="<?php esc_attr_e( 'Filter by text (e.g. order=42, webhook, error)', 'xpay-for-woocommerce' ); ?>"
							style="flex:1; min-width:180px; max-width:280px;">
						<select id="xpay-logger-stage" style="max-width:160px;">
							<option value=""><?php esc_html_e( 'All stages', 'xpay-for-woocommerce' ); ?></option>
							<option value="boot">boot</option>
							<option value="prefs">prefs.*</option>
							<option value="payment_fields">payment_fields.*</option>
							<option value="process_payment">process_payment.*</option>
							<option value="prepare_amount">prepare_amount.*</option>
							<option value="webhook">webhook.*</option>
							<option value="check_transaction">check_transaction.*</option>
							<option value="modal">modal.*</option>
							<option value="diagnostics">diagnostics.*</option>
						</select>
					</div>

					<div id="xpay-log-view" style="height:560px; overflow:auto; border:1px solid #ddd; border-radius:4px; background:#fff; padding:4px 0;">
						<p style="color:#888; padding:12px;"><?php esc_html_e( 'Loading…', 'xpay-for-woocommerce' ); ?></p>
					</div>
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
				/* ── i18n strings injected from PHP ─────────────────────── */
				var I18N = {
					noEntries: <?php echo wp_json_encode( __( 'No matching entries.', 'xpay-for-woocommerce' ) ); ?>,
					showJson:  <?php echo wp_json_encode( __( 'show JSON', 'xpay-for-woocommerce' ) ); ?>,
					hideJson:  <?php echo wp_json_encode( __( 'hide JSON', 'xpay-for-woocommerce' ) ); ?>,
				};

				/* ── DOM refs ────────────────────────────────────────────── */
				var endpoint  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var nonce     = <?php echo wp_json_encode( $tail_nonce ); ?>;
				var $view     = document.getElementById('xpay-log-view');
				var $live     = document.getElementById('xpay-logger-live');
				var $grep     = document.getElementById('xpay-logger-grep');
				var $stage    = document.getElementById('xpay-logger-stage');
				var $diag     = document.getElementById('xpay-show-diag');
				var pollMs    = 5000;
				var inFlight  = false;

				/* ── localStorage persistence ────────────────────────────── */
				var LS_DIAG = 'xpay_logger_show_diag';
				$diag.checked = localStorage.getItem(LS_DIAG) === '1';
				$diag.addEventListener('change', function () {
					localStorage.setItem(LS_DIAG, $diag.checked ? '1' : '0');
					applyVisibility();
				});

				/* ── Line parser ─────────────────────────────────────────── */
				// Format: [iso-ts] [req-id] [stage] [order=ID|-] message {json}
				var LINE_RE = /^\[([^\]]+)\] \[([^\]]+)\] \[([^\]]+)\] \[order=([^\]]+)\] ?(.*)/;

				function parseLine(raw) {
					var m = raw.match(LINE_RE);
					if (!m) { return null; }
					var message = m[5] || '';
					var jsonStr = '';
					var jsonObj = null;
					// JSON payload starts at first '{' that follows the message text
					var jIdx = message.indexOf('{');
					if (jIdx !== -1) {
						jsonStr = message.slice(jIdx);
						message = message.slice(0, jIdx).trim();
						try { jsonObj = JSON.parse(jsonStr); } catch(e) {}
					}
					return {
						ts:        m[1],           // full ISO timestamp
						time:      m[1].slice(11, 19), // HH:MM:SS
						reqId:     m[2],
						stage:     m[3],
						orderId:   m[4] === '-' ? null : m[4],
						message:   message,
						jsonStr:   jsonStr,
						jsonObj:   jsonObj,
					};
				}

				/* ── Headline builder ────────────────────────────────────── */
				function makeHeadline(ev) {
					var o = ev.jsonObj;
					if (!o) { return ev.message || ''; }
					var parts = [];

					// Error/failure: surface upstream_message first
					if (o.upstream_message) { return String(o.upstream_message).slice(0, 80); }

					// Per-stage interesting keys
					var keys = [];
					var s = ev.stage;
					if (s === 'webhook.applied' || s === 'webhook.received' || s === 'webhook.lookup') {
						keys = ['branch','order_status_out','order_status_in','transaction_id'];
					} else if (s.indexOf('process_payment') === 0) {
						keys = ['http','fingerprint_kept','branch','amount','currency'];
					} else if (s.indexOf('check_transaction') === 0) {
						keys = ['status','branch','transaction_id'];
					} else if (s.indexOf('modal') === 0) {
						keys = ['event','branch','error'];
					} else if (s.indexOf('prefs') === 0) {
						keys = ['http','methods_count','promo'];
					} else if (s === 'boot') {
						keys = ['plugin_version','wc_version','php_version','theme'];
					} else if (s.indexOf('diagnostics') === 0) {
						keys = ['wp_version','wc_version','php_version'];
					} else {
						// Generic: first 4 scalar keys
						var count = 0;
						for (var k in o) {
							if (Object.prototype.hasOwnProperty.call(o, k) && typeof o[k] !== 'object' && count < 4) {
								keys.push(k);
								count++;
							}
						}
					}

					for (var i = 0; i < keys.length; i++) {
						var k = keys[i];
						if (o[k] !== undefined && o[k] !== null) {
							parts.push(k + '=' + o[k]);
						}
					}

					var headline = parts.join(' ');
					if (!headline && ev.message) { headline = ev.message; }
					return headline.slice(0, 80);
				}

				/* ── Stage → CSS class mapping ───────────────────────────── */
				function stageBadgeClass(stage) {
					if (stage === 'boot' || stage === 'boot.hooks_inventory') return 'xpay-badge-boot';
					if (stage.indexOf('prefs') === 0) return 'xpay-badge-prefs';
					if (stage.indexOf('process_payment') === 0) return 'xpay-badge-process_payment';
					if (stage.indexOf('webhook') === 0) return 'xpay-badge-webhook';
					if (stage.indexOf('modal') === 0) return 'xpay-badge-modal';
					if (stage.indexOf('diagnostics') === 0) return 'xpay-badge-diagnostics';
					if (stage.indexOf('error') === 0) return 'xpay-badge-errors';
					return 'xpay-badge-other';
				}

				/* ── Error detection ─────────────────────────────────────── */
				var ERROR_RE = /failed|error|mismatch|missing|rejected|non-2xx|wp_error/i;
				function isErrorEvent(ev) {
					if (ev.stage.indexOf('error') === 0) { return true; }
					var o = ev.jsonObj;
					if (o && typeof o.branch === 'string' && ERROR_RE.test(o.branch)) { return true; }
					if (ERROR_RE.test(ev.message)) { return true; }
					return false;
				}

				/* ── HTML escape ─────────────────────────────────────────── */
				function h(s) {
					return String(s).replace(/[&<>"']/g, function(c) {
						return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
					});
				}

				/* ── Group events by request_id ──────────────────────────── */
				function groupEvents(events) {
					var groups = [];
					var map    = {};
					for (var i = 0; i < events.length; i++) {
						var ev = events[i];
						if (!map[ev.reqId]) {
							var grp = { reqId: ev.reqId, events: [], firstTs: ev.ts, lastTs: ev.ts };
							map[ev.reqId] = grp;
							groups.push(grp);
						}
						var g = map[ev.reqId];
						g.events.push(ev);
						if (ev.ts > g.lastTs) { g.lastTs = ev.ts; }
					}
					return groups;
				}

				/* ── Dominant stage for a group ──────────────────────────── */
				var STAGE_PRIORITY = ['webhook','process_payment','modal','check_transaction','prefs','diagnostics','boot'];
				function dominantStage(group) {
					var seen = {};
					for (var i = 0; i < group.events.length; i++) {
						var s = group.events[i].stage;
						for (var p = 0; p < STAGE_PRIORITY.length; p++) {
							if (s.indexOf(STAGE_PRIORITY[p]) === 0) {
								seen[STAGE_PRIORITY[p]] = true;
								break;
							}
						}
					}
					for (var p = 0; p < STAGE_PRIORITY.length; p++) {
						if (seen[STAGE_PRIORITY[p]]) { return STAGE_PRIORITY[p]; }
					}
					return group.events[0] ? group.events[0].stage : 'other';
				}

				/* ── Render a single event row ───────────────────────────── */
				function renderEvent(ev) {
					var isErr    = isErrorEvent(ev);
					var headline = makeHeadline(ev);
					var badgeCls = stageBadgeClass(ev.stage);
					var isDiag   = ev.stage === 'boot.hooks_inventory';

					var errCls  = isErr ? ' is-error' : '';
					var diagAttr = isDiag ? ' data-diag="1"' : '';

					var orderBit = ev.orderId
						? '<span class="xpay-event-order">order=' + h(ev.orderId) + '</span>'
						: '';
					var msgBit = ev.message && ev.message !== headline
						? '<span class="xpay-event-msg">' + h(ev.message) + '</span>'
						: '';

					var jsonToggle = '';
					var jsonBlock  = '';
					if (ev.jsonStr) {
						var pretty = '';
						try { pretty = JSON.stringify(ev.jsonObj, null, 2); } catch(e) { pretty = ev.jsonStr; }
						var uid = 'xj-' + Math.random().toString(36).slice(2);
						jsonToggle = '<span class="xpay-json-toggle" data-target="' + uid + '">' + h(I18N.showJson) + '</span>';
						jsonBlock  = '<details class="xpay-json-block" id="' + uid + '"><summary style="display:none;"></summary><pre>' + h(pretty) + '</pre></details>';
					}

					return '<li class="xpay-event' + errCls + '"' + diagAttr + '>'
						+ '<span class="xpay-event-time">' + h(ev.time) + '</span>'
						+ '<span class="xpay-badge ' + badgeCls + '">' + h(ev.stage) + '</span>'
						+ orderBit
						+ '<span class="xpay-event-headline">' + h(headline) + '</span>'
						+ msgBit
						+ jsonToggle
						+ jsonBlock
						+ '</li>';
				}

				/* ── Render a request group ──────────────────────────────── */
				function renderGroup(group, open) {
					var dom  = dominantStage(group);
					var bcls = stageBadgeClass(dom);
					var shortId = group.reqId.slice(-6);
					var timeRange = group.firstTs.slice(11,19);
					if (group.firstTs !== group.lastTs) {
						timeRange += ' – ' + group.lastTs.slice(11,19);
					}
					var hasError = group.events.some(function(ev) { return isErrorEvent(ev); });
					var errMark  = hasError ? ' <span style="color:#ef4444;">✖</span>' : '';

					var evHtml = group.events.map(renderEvent).join('');

					return '<details class="xpay-group" data-reqid="' + h(group.reqId) + '"'
						+ (open ? ' open' : '') + '>'
						+ '<summary>'
						+ '<span class="xpay-group-req">' + h(shortId) + '</span>'
						+ '<span class="xpay-badge ' + bcls + '">' + h(dom) + '</span>'
						+ '<span class="xpay-group-meta">' + h(timeRange) + errMark + '</span>'
						+ '<span class="xpay-group-count">' + group.events.length + '</span>'
						+ '</summary>'
						+ '<ul class="xpay-events">' + evHtml + '</ul>'
						+ '</details>';
				}

				/* ── Save / restore open-state across refreshes ──────────── */
				function saveOpenState() {
					var open = {};
					$view.querySelectorAll('.xpay-group[open]').forEach(function(el) {
						open[el.dataset.reqid] = true;
					});
					return open;
				}

				function restoreOpenState(openMap) {
					$view.querySelectorAll('.xpay-group').forEach(function(el) {
						if (openMap[el.dataset.reqid]) {
							el.setAttribute('open', '');
						}
					});
				}

				/* ── Apply visibility (filter + diag toggle) ─────────────── */
				function matchesFilter(ev, grep, stagePrefix) {
					if (stagePrefix && ev.stage.indexOf(stagePrefix) !== 0) { return false; }
					if (!grep) { return true; }
					var haystack = [ev.ts, ev.reqId, ev.stage, ev.orderId || '', ev.message, makeHeadline(ev)]
						.join(' ').toLowerCase();
					return haystack.indexOf(grep) !== -1;
				}

				function applyVisibility() {
					var grep        = ($grep.value || '').trim().toLowerCase();
					var stagePrefix = ($stage.value || '').trim();
					var showDiag    = $diag.checked;

					$view.querySelectorAll('.xpay-group').forEach(function(groupEl) {
						var anyVisible = false;
						groupEl.querySelectorAll('.xpay-event').forEach(function(evEl) {
							var isDiag = evEl.dataset.diag === '1';
							// Reconstruct minimal event for filter matching from DOM data attrs
							// (stored on the element when rendered)
							var stage   = evEl.dataset.stage   || '';
							var reqId   = evEl.dataset.reqid   || '';
							var orderId = evEl.dataset.order   || '';
							var tsText  = evEl.dataset.ts      || '';
							var hl      = evEl.querySelector('.xpay-event-headline');
							var hlText  = hl ? hl.textContent : '';
							var evName  = evEl.querySelector('.xpay-badge');
							var evNameText = evName ? evName.textContent : stage;

							// Hide diagnostic events unless toggled
							if (isDiag && !showDiag) {
								evEl.classList.add('xpay-hidden');
								return;
							}

							// Stage filter
							if (stagePrefix && stage.indexOf(stagePrefix) !== 0) {
								evEl.classList.add('xpay-hidden');
								return;
							}

							// Text filter
							if (grep) {
								var haystack = [tsText, reqId, stage, orderId, hlText, evNameText]
									.join(' ').toLowerCase();
								if (haystack.indexOf(grep) === -1) {
									evEl.classList.add('xpay-hidden');
									return;
								}
							}

							evEl.classList.remove('xpay-hidden');
							anyVisible = true;
						});

						groupEl.classList.toggle('xpay-hidden', !anyVisible);
					});

					// Show empty-state message if nothing visible
					var anyGroup = false;
					$view.querySelectorAll('.xpay-group').forEach(function(g) {
						if (!g.classList.contains('xpay-hidden')) { anyGroup = true; }
					});
					var emptyEl = $view.querySelector('.xpay-empty');
					if (emptyEl) { emptyEl.style.display = anyGroup ? 'none' : ''; }
				}

				/* ── Full render pass ────────────────────────────────────── */
				function render(lines) {
					var events = [];
					for (var i = 0; i < lines.length; i++) {
						var ev = parseLine(lines[i]);
						if (ev) {
							// Attach data we need for applyVisibility (avoids re-parsing from DOM)
							ev._lineIndex = i;
							events.push(ev);
						}
					}
					if (!events.length) {
						$view.innerHTML = '<p class="xpay-empty" style="color:#888;padding:12px;">' + h(I18N.noEntries) + '</p>';
						return;
					}

					var groups     = groupEvents(events);
					var savedOpen  = saveOpenState();

					// Auto-open the last 2 groups; restore previously-opened ones
					var defaultOpen = {};
					for (var gi = Math.max(0, groups.length - 2); gi < groups.length; gi++) {
						defaultOpen[groups[gi].reqId] = true;
					}

					var wasEmpty = !$view.querySelector('.xpay-group');
					var scrollTop = $view.scrollTop;
					var atBottom  = ($view.scrollHeight - scrollTop - $view.clientHeight) < 30;

					// Build HTML. Each event <li> gets data-* attrs for filter re-use.
					var html = '';
					for (var gi = 0; gi < groups.length; gi++) {
						var grp  = groups[gi];
						var open = defaultOpen[grp.reqId] || savedOpen[grp.reqId] || false;
						// Inject data-* onto event <li> elements via a post-processing step
						html += renderGroup(grp, open);
					}
					html += '<p class="xpay-empty" style="color:#888;padding:12px;display:none;">' + h(I18N.noEntries) + '</p>';

					$view.innerHTML = html;

					// Stamp data-* attributes on event rows for fast filter access
					var groupEls = $view.querySelectorAll('.xpay-group');
					var eIdx = 0;
					for (var gi = 0; gi < groups.length; gi++) {
						var grp = groups[gi];
						var evEls = groupEls[gi] ? groupEls[gi].querySelectorAll('.xpay-event') : [];
						for (var ei = 0; ei < evEls.length; ei++) {
							var ev  = grp.events[ei];
							if (!ev) { continue; }
							var el  = evEls[ei];
							el.dataset.stage  = ev.stage;
							el.dataset.reqid  = ev.reqId;
							el.dataset.order  = ev.orderId || '';
							el.dataset.ts     = ev.ts;
						}
					}

					// Wire JSON toggle buttons
					$view.querySelectorAll('.xpay-json-toggle').forEach(function(btn) {
						btn.addEventListener('click', function() {
							var targetId = btn.dataset.target;
							var det = document.getElementById(targetId);
							if (!det) { return; }
							if (det.open) {
								det.removeAttribute('open');
								btn.textContent = I18N.showJson;
							} else {
								det.setAttribute('open', '');
								btn.textContent = I18N.hideJson;
							}
						});
					});

					applyVisibility();

					// Restore scroll position unless we were at bottom
					if (!wasEmpty && !atBottom) {
						$view.scrollTop = scrollTop;
					} else if (atBottom) {
						$view.scrollTop = $view.scrollHeight;
					}
				}

				/* ── AJAX fetch ──────────────────────────────────────────── */
				function fetchTail() {
					if (inFlight) { return; }
					inFlight = true;

					var fd = new FormData();
					fd.append('action', 'xpay_logger_tail');
					fd.append('nonce', nonce);
					fd.append('lines', '300');

					fetch(endpoint, { method:'POST', body:fd, credentials:'same-origin' })
						.then(function(r) { return r.json(); })
						.then(function(data) {
							inFlight = false;
							if (!data || !data.success) { return; }
							var lines = (data.data && data.data.lines) || [];
							render(lines);
						})
						.catch(function() { inFlight = false; });
				}

				/* ── Wire controls ───────────────────────────────────────── */
				$live.addEventListener('change', function() {
					if ($live.checked) { fetchTail(); }
				});
				$grep.addEventListener('input', function() {
					// Re-apply filters on existing DOM without a full re-fetch
					applyVisibility();
				});
				$stage.addEventListener('change', function() {
					applyVisibility();
				});

				fetchTail();
				setInterval(function() {
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
