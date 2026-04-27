<?php
/**
 * XPay flow logger — read-only diagnostic recorder.
 *
 * Captures every stage of the XPay payment flow with enough detail to
 * debug conflicts with themes / plugins / hosts. The logger is OFF by
 * default and a no-op until enabled in gateway settings. Listeners are
 * only attached when the logger is enabled, so a disabled logger has
 * literally zero hot-path cost.
 *
 * Storage: wp-content/uploads/xpay-logs/xpay-flow-YYYY-MM-DD.log, one
 * entry per line, daily rotation, 30-day retention via cron.
 */

defined( 'ABSPATH' ) or exit;

// The logger writes high-frequency payment-event lines using fopen + flock
// + fwrite + fclose for atomic appends. WP_Filesystem doesn't expose flock
// or any append-with-locking primitive, and re-initialising WP_Filesystem
// per write would multiply the per-event overhead. We accept PCP's
// AlternativeFunctions warnings on the file-IO calls inside this class as
// a deliberate performance/architecture trade-off.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

require_once __DIR__ . '/class-wc-xpay-logger-redactor.php';

final class WC_XPay_Logger {

	const SETTINGS_OPTION   = 'woocommerce_xpay_gateway_settings';
	const SETTING_KEY       = 'logger_enabled';
	const CRON_HOOK         = 'xpay_logger_daily_cleanup';
	const RETENTION_DAYS    = 30;
	const MAX_ENTRY_BYTES   = 4096;
	const LOG_DIR_NAME      = 'xpay-logs';

	private static $request_id  = null;
	private static $boot_logged = false;

	/**
	 * Wire up the logger. Called from the main plugin file at plugins_loaded
	 * priority 99 (the spec requires the boot stage at 99 so we have a stable
	 * picture of what other plugins have loaded).
	 *
	 * Even when disabled we register the activation/cleanup cron and the
	 * uninstall path — those need to exist regardless. The hot-path listeners
	 * are gated behind is_enabled().
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'rotate_old_logs' ) );

		// Auto-install fallback. register_activation_hook only fires when the
		// merchant explicitly activates the plugin — it does NOT fire when
		// they upgrade from a pre-logger version. Run the activation work
		// once at runtime if the cron isn't scheduled yet, so existing
		// installs pick up the cron + log dir on first request after upgrade.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::on_plugin_activation();
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		// Subscribe to the central event bus. Every emitter in the plugin
		// fires `do_action('xpay_logger_event', ...)` — when disabled, no
		// subscribers means it's a true no-op.
		add_action( 'xpay_logger_event', array( __CLASS__, 'on_event' ), 10, 3 );

		require_once __DIR__ . '/class-wc-xpay-logger-listeners.php';
		WC_XPay_Logger_Listeners::register();

		// Boot snapshot on every request so we capture the load context for
		// the request that will follow. Deduped per-request — see
		// log_boot_snapshot().
		add_action( 'wp_loaded', array( __CLASS__, 'log_boot_snapshot' ), 1 );
	}

	/**
	 * Activation hook — schedule the daily cleanup cron and pre-create the
	 * log directory with hardening files. Idempotent.
	 */
	public static function on_plugin_activation() {
		self::ensure_log_dir();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation hook — clear the cron. Logs are intentionally left in
	 * place; the merchant may want to inspect them after disabling.
	 */
	public static function on_plugin_deactivation() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function is_enabled() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		return ! empty( $settings[ self::SETTING_KEY ] ) && 'yes' === $settings[ self::SETTING_KEY ];
	}

	/**
	 * Stable per-request id. Used to group all entries that came from the
	 * same HTTP request — webhook handler, modal poll, page render, etc.
	 */
	public static function request_id() {
		if ( null === self::$request_id ) {
			self::$request_id = function_exists( 'wp_generate_uuid4' )
				? substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 )
				: substr( md5( microtime( true ) . wp_rand() ), 0, 12 );
		}
		return self::$request_id;
	}

	/**
	 * Single subscriber for the xpay_logger_event action. Called as
	 *
	 *     do_action( 'xpay_logger_event', $stage, $context, $message );
	 *
	 * $context can be omitted (null), $message optional. The on-disk format
	 * is line-oriented:
	 *
	 *     [iso-ts] [req-id] [stage] [order=ID|-] message {json-context}
	 */
	public static function on_event( $stage, $context = null, $message = '' ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! is_string( $stage ) || '' === $stage ) {
			return;
		}

		$context  = is_array( $context ) ? $context : array();
		$order_id = isset( $context['order_id'] ) ? (int) $context['order_id'] : 0;
		unset( $context['order_id'] ); // moved to its own column

		$redacted = WC_XPay_Logger_Redactor::redact( $context );
		$json     = '';
		if ( ! empty( $redacted ) ) {
			$json = wp_json_encode( $redacted, JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				$json = '{"_encode_error":true}';
			}
		}

		$line = sprintf(
			"[%s] [%s] [%s] [order=%s] %s%s\n",
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			self::request_id(),
			$stage,
			$order_id > 0 ? (string) $order_id : '-',
			(string) $message,
			'' !== $json ? ' ' . $json : ''
		);

		// Bound entry size to keep the log scannable and avoid runaway
		// memory if someone passes a giant blob into context.
		if ( strlen( $line ) > self::MAX_ENTRY_BYTES ) {
			$truncated_at = self::MAX_ENTRY_BYTES - 80;
			$line         = substr( $line, 0, $truncated_at )
				. sprintf( ' …(truncated, original %d bytes)', strlen( $line ) - 1 )
				. "\n";
		}

		self::write( $line );
	}

	/**
	 * Append a pre-formatted line to today's log file. All file IO is best
	 * effort — we never let logger failures bubble up and break a payment.
	 */
	private static function write( $line ) {
		$path = self::current_log_path();
		if ( ! $path ) {
			return;
		}
		// Suppressed because we cannot let a closed FS or full disk crash
		// the gateway. The error_clear_last() pair lets us silently no-op.
		error_clear_last();
		$fh = @fopen( $path, 'a' );
		if ( ! $fh ) {
			return;
		}
		// LOCK_EX bounds risk of interleaved writes from concurrent php-fpm
		// workers handling parallel webhook + checkout requests.
		if ( @flock( $fh, LOCK_EX ) ) {
			@fwrite( $fh, $line );
			@flock( $fh, LOCK_UN );
		}
		@fclose( $fh );
	}

	/**
	 * Path to today's log file, ensuring the directory exists. Returns null
	 * on failure so write() can no-op cleanly.
	 */
	public static function current_log_path() {
		$dir = self::ensure_log_dir();
		if ( ! $dir ) {
			return null;
		}
		return trailingslashit( $dir ) . 'xpay-flow-' . gmdate( 'Y-m-d' ) . '.log';
	}

	public static function log_dir() {
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['basedir'] ) ) {
			return null;
		}
		return trailingslashit( $uploads['basedir'] ) . self::LOG_DIR_NAME;
	}

	/**
	 * Creates the log directory and drops in two hardening files:
	 *   - index.html so directory listing returns nothing
	 *   - .htaccess to block direct file access on Apache
	 *
	 * Nginx hosts will need a server-block rule to be equally protected;
	 * the admin page surfaces this in diagnostics.
	 */
	public static function ensure_log_dir() {
		$dir = self::log_dir();
		if ( ! $dir ) {
			return null;
		}
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$index_path = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index_path ) ) {
			@file_put_contents( $index_path, '' );
		}
		$ht_path = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $ht_path ) ) {
			@file_put_contents( $ht_path, "Order allow,deny\nDeny from all\n" );
		}

		return $dir;
	}

	/**
	 * Daily cleanup — delete log files older than RETENTION_DAYS days.
	 * Runs from a daily cron registered on activation.
	 */
	public static function rotate_old_logs() {
		$dir = self::log_dir();
		if ( ! $dir || ! is_dir( $dir ) ) {
			return;
		}
		$cutoff = time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS );
		$files  = glob( trailingslashit( $dir ) . 'xpay-flow-*.log' );
		if ( ! is_array( $files ) ) {
			return;
		}
		foreach ( $files as $file ) {
			$mtime = @filemtime( $file );
			if ( $mtime && $mtime < $cutoff ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Boot snapshot — runs once per request at wp_loaded. Captures the load
	 * context (versions, features, conflicting plugins) so a single log
	 * entry tells you what the request was running on top of.
	 *
	 * Hooks-inventory enumerates which non-XPay callbacks are attached to
	 * the WC checkout/payment hooks we depend on. Anything from another
	 * plugin on these hooks is a candidate for conflict investigation.
	 */
	public static function log_boot_snapshot() {
		if ( self::$boot_logged ) {
			return;
		}
		self::$boot_logged = true;

		$active = self::active_plugins_with_versions();
		$theme  = wp_get_theme();

		$hpos_enabled = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		$blocks_active = function_exists( 'wc_current_theme_is_fse_theme' )
			|| class_exists( '\Automattic\WooCommerce\Blocks\Package' );

		do_action(
			'xpay_logger_event',
			'boot',
			array(
				'plugin_version'      => self::plugin_version(),
				'wp_version'          => get_bloginfo( 'version' ),
				'wc_version'          => defined( 'WC_VERSION' ) ? WC_VERSION : 'n/a',
				'php_version'         => PHP_VERSION,
				'hpos_enabled'        => $hpos_enabled,
				'blocks_loaded'       => $blocks_active,
				'theme'               => $theme ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : 'unknown',
				'parent_theme'        => $theme && $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
				'wpfunnels_active'    => self::has_active_plugin( $active, array( 'wpfunnels', 'wpfnl' ) ),
				'cartflows_active'    => self::has_active_plugin( $active, array( 'cartflows' ) ),
				'funnelkit_active'    => self::has_active_plugin( $active, array( 'funnelkit', 'funnel-builder' ) ),
				'caching_plugin'      => self::detect_caching_plugin( $active ),
				'security_plugin'     => self::detect_security_plugin( $active ),
				'cookie_consent'      => self::detect_consent_plugin( $active ),
				'page_builder'        => self::detect_page_builder( $active ),
				'php_socket_timeout'  => (int) ini_get( 'default_socket_timeout' ),
				'php_max_execution'   => (int) ini_get( 'max_execution_time' ),
				'php_memory_limit'    => ini_get( 'memory_limit' ),
			),
			'request boot snapshot'
		);

		do_action(
			'xpay_logger_event',
			'boot.hooks_inventory',
			array(
				'hooks' => self::collect_hook_callbacks( array(
					'woocommerce_payment_gateways',
					'woocommerce_thankyou',
					'woocommerce_checkout_process',
					'woocommerce_blocks_payment_method_type_registration',
					'woocommerce_receipt_xpay_gateway',
					'the_title',
					'woocommerce_checkout_order_processed',
					'woocommerce_payment_complete',
				) ),
			),
			'callbacks attached to checkout/payment hooks'
		);
	}

	/**
	 * Return [plugin_basename => version] for active plugins. Includes
	 * network-active plugins on multisite installs.
	 */
	private static function active_plugins_with_versions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active  = array_merge( $active, array_keys( $network ) );
		}
		$out = array();
		foreach ( array_unique( $active ) as $slug ) {
			$out[ $slug ] = isset( $all[ $slug ]['Version'] ) ? $all[ $slug ]['Version'] : '?';
		}
		return $out;
	}

	private static function has_active_plugin( $active, $needles ) {
		foreach ( $active as $slug => $version ) {
			$lower = strtolower( $slug );
			foreach ( $needles as $needle ) {
				if ( strpos( $lower, $needle ) !== false ) {
					return $slug . ' ' . $version;
				}
			}
		}
		return null;
	}

	private static function detect_caching_plugin( $active ) {
		return self::has_active_plugin( $active, array(
			'wp-rocket', 'litespeed-cache', 'w3-total-cache', 'wp-super-cache',
			'cache-enabler', 'autoptimize', 'wp-fastest-cache', 'sg-cachepress',
		) );
	}

	private static function detect_security_plugin( $active ) {
		return self::has_active_plugin( $active, array(
			'wordfence', 'sucuri', 'ithemes-security', 'better-wp-security',
			'all-in-one-wp-security', 'wp-cerber',
		) );
	}

	private static function detect_consent_plugin( $active ) {
		return self::has_active_plugin( $active, array(
			'cookie-notice', 'cookieyes', 'complianz', 'gdpr-cookie',
			'cookie-law-info', 'borlabs-cookie',
		) );
	}

	private static function detect_page_builder( $active ) {
		return self::has_active_plugin( $active, array(
			'elementor', 'beaver-builder', 'divi-builder', 'siteorigin',
			'bricks', 'oxygen', 'wpbakery',
		) );
	}

	/**
	 * For each hook name, return the list of registered callbacks. Skips
	 * our own callbacks (anything whose source file lives under this
	 * plugin directory) so the inventory only highlights third-party
	 * involvement.
	 */
	private static function collect_hook_callbacks( $hook_names ) {
		global $wp_filter;
		$out          = array();
		$plugin_root  = plugin_dir_path( dirname( __DIR__ ) ); // ../../ from /includes/logger/

		foreach ( $hook_names as $hook ) {
			$entries = array();
			if ( empty( $wp_filter[ $hook ] ) ) {
				$out[ $hook ] = array();
				continue;
			}
			$wp_hook = $wp_filter[ $hook ];
			$callbacks = is_object( $wp_hook ) && isset( $wp_hook->callbacks )
				? $wp_hook->callbacks
				: (array) $wp_hook;
			foreach ( $callbacks as $priority => $by_id ) {
				if ( ! is_array( $by_id ) ) {
					continue;
				}
				foreach ( $by_id as $id => $info ) {
					$callable = isset( $info['function'] ) ? $info['function'] : null;
					$desc     = self::describe_callable( $callable );
					if ( $desc['source'] && 0 === strpos( $desc['source'], $plugin_root ) ) {
						continue; // ours
					}
					$entries[] = array(
						'priority' => (int) $priority,
						'callback' => $desc['name'],
						'source'   => $desc['source'] ? str_replace( ABSPATH, '', $desc['source'] ) : 'unknown',
					);
				}
			}
			$out[ $hook ] = $entries;
		}
		return $out;
	}

	/**
	 * Best-effort callable description: function name + source file path.
	 * Closures are described by their declaring file + line.
	 */
	private static function describe_callable( $callable ) {
		try {
			if ( is_string( $callable ) ) {
				if ( function_exists( $callable ) ) {
					$ref = new ReflectionFunction( $callable );
					return array( 'name' => $callable, 'source' => $ref->getFileName() );
				}
				return array( 'name' => $callable, 'source' => null );
			}
			if ( is_array( $callable ) && count( $callable ) === 2 ) {
				$class  = is_object( $callable[0] ) ? get_class( $callable[0] ) : (string) $callable[0];
				$method = (string) $callable[1];
				if ( method_exists( $class, $method ) ) {
					$ref = new ReflectionMethod( $class, $method );
					return array( 'name' => $class . '::' . $method, 'source' => $ref->getFileName() );
				}
				return array( 'name' => $class . '::' . $method, 'source' => null );
			}
			if ( $callable instanceof Closure ) {
				$ref  = new ReflectionFunction( $callable );
				$file = $ref->getFileName();
				$line = $ref->getStartLine();
				return array( 'name' => 'Closure@' . basename( (string) $file ) . ':' . $line, 'source' => $file );
			}
			if ( is_object( $callable ) && method_exists( $callable, '__invoke' ) ) {
				$ref = new ReflectionMethod( $callable, '__invoke' );
				return array( 'name' => get_class( $callable ) . '::__invoke', 'source' => $ref->getFileName() );
			}
		} catch ( Throwable $e ) {
			// Fall through to unknown
		}
		return array( 'name' => 'unknown', 'source' => null );
	}

	private static function plugin_version() {
		$main = dirname( dirname( __DIR__ ) ) . '/woocommerce-xpay-gateway.php';
		if ( ! is_readable( $main ) ) {
			return 'unknown';
		}
		$data = get_file_data( $main, array( 'Version' => 'Version' ) );
		return isset( $data['Version'] ) ? $data['Version'] : 'unknown';
	}
}
