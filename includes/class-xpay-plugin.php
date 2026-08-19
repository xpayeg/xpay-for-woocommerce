<?php
/**
 * XPay_Plugin
 *
 * Composition root for the plugin. Its only responsibilities:
 *   1. Load every class file (the one authoritative include list — PHP's
 *      answer to the monorepo's barrel files).
 *   2. Wire WordPress/WooCommerce hooks to their owning classes.
 *   3. Expose the gateway singleton other components read settings through.
 *
 * Business logic never lives here.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Plugin {

	/** @var XPay_Plugin|null */
	private static $instance = null;

	/** @var XPay_Gateway|null Lazily created; WooCommerce also instantiates its own copy for checkout. */
	private $gateway = null;

	public static function instance(): XPay_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$dir = XPAY_WC_PLUGIN_DIR . 'includes/';

		// Constants (registries — one source of truth each).
		require_once $dir . 'constants/class-xpay-constants.php';
		require_once $dir . 'constants/class-xpay-error-codes.php';
		require_once $dir . 'constants/class-xpay-session-status.php';
		require_once $dir . 'constants/class-xpay-refund-status.php';
		require_once $dir . 'constants/class-xpay-payment-methods.php';
		require_once $dir . 'constants/class-xpay-branding.php';
		require_once $dir . 'constants/class-xpay-event-names.php';

		// Logging (loaded before anything that logs).
		require_once $dir . 'logger/class-xpay-redactor.php';
		require_once $dir . 'logger/class-xpay-log-store.php';
		require_once $dir . 'logger/class-xpay-logger.php';

		// Admin surfaces (log viewer, order panel, settings screen, docs).
		require_once $dir . 'admin/class-xpay-log-viewer.php';
		require_once $dir . 'admin/class-xpay-order-panel.php';
		require_once $dir . 'admin/class-xpay-settings-screen.php';
		require_once $dir . 'admin/class-xpay-doc-viewer.php';

		// API layer.
		require_once $dir . 'api/class-xpay-api-exception.php';
		require_once $dir . 'api/class-xpay-signature.php';
		require_once $dir . 'api/class-xpay-money.php';
		require_once $dir . 'api/class-xpay-api-client.php';

		// Domain services.
		require_once $dir . 'gateway/class-xpay-order-lock.php';
		require_once $dir . 'gateway/class-xpay-checkout-service.php';
		require_once $dir . 'gateway/class-xpay-order-sync.php';
		require_once $dir . 'refunds/class-xpay-refund-service.php';
		require_once $dir . 'webhooks/class-xpay-webhook-controller.php';

		// Compatibility shims (third-party conflicts the field taught us).
		require_once $dir . 'compat/class-xpay-wpfunnels-compat.php';
		require_once $dir . 'compat/class-xpay-script-guard.php';
		require_once $dir . 'compat/class-xpay-legacy-notice.php';

		// WooCommerce surfaces.
		require_once $dir . 'gateway/class-xpay-pay-page.php';
		require_once $dir . 'gateway/class-xpay-thankyou-notice.php';
		require_once $dir . 'gateway/class-xpay-gateway.php';
		require_once $dir . 'gateway/class-xpay-method-gateway.php';
		if ( class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			// The Blocks class extends AbstractPaymentMethodType at parse
			// time — requiring the file without the parent loaded is a fatal,
			// so the guard must live HERE, not inside the class.
			require_once $dir . 'blocks/class-xpay-blocks-support.php';
		}
	}

	public function init(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		if ( class_exists( 'XPay_Blocks_Support' ) ) {
			// WooCommerce fires woocommerce_blocks_loaded from plugins_loaded
			// priority 10 — one tick before this plugin boots at 11 — so by
			// now the event is normally long gone and add_action alone would
			// never run, leaving every XPay row invisible to the Cart &
			// Checkout Blocks. Registering directly is safe here: register()
			// only subscribes to the payment-method-type registration hook,
			// which Blocks fires later (init, priority 5).
			if ( did_action( 'woocommerce_blocks_loaded' ) ) {
				XPay_Blocks_Support::register();
			} else {
				add_action( 'woocommerce_blocks_loaded', array( 'XPay_Blocks_Support', 'register' ) );
			}
		}

		// Public webhook receiver: https://<site>/?wc-api=xpay_webhook
		// Trust boundary: unauthenticated internet traffic — the HMAC
		// signature check inside the controller is the only gate.
		add_action( 'woocommerce_api_xpay_webhook', array( 'XPay_Webhook_Controller', 'handle' ) );

		// Thank-you page truth: re-check the session server-side rather than
		// trusting the redirect. Webhook remains the authoritative writer;
		// this closes the gap when the shopper outruns the webhook.
		add_action( 'woocommerce_before_thankyou', array( 'XPay_Order_Sync', 'verify_on_thankyou' ) );
		// Priority 20: the stamped receipt must read post-verification truth.
		add_action( 'woocommerce_before_thankyou', array( 'XPay_Thankyou_Notice', 'render' ), 20 );
		add_action( 'wp_enqueue_scripts', array( 'XPay_Thankyou_Notice', 'enqueue' ) );
		add_filter( 'woocommerce_thankyou_order_received_text', array( 'XPay_Thankyou_Notice', 'filter_received_text' ), 10, 2 );

		// Compatibility shims: WPFunnels' order-received rewrite, and
		// optimizer opt-outs on the payment-critical script tags.
		XPay_WPFunnels_Compat::register();
		XPay_Script_Guard::register();

		// Prune runs from WP-Cron (any request context, not just admin).
		// The schema check rides the same cron first (priority 5): the lazy
		// admin-load check below never fires on a headless or WP-CLI-managed
		// store, so without this a schema bump shipped in an update would
		// never apply there. It early-returns on a matching version — one
		// cached option read per day. The post-update hook narrows the
		// window further when some OTHER update runs with this plugin's new
		// code already loaded (our own update still carries the old code
		// for the rest of its request, which is exactly why the cron pass
		// exists).
		add_action( XPay_Log_Store::CRON_HOOK, array( 'XPay_Log_Store', 'install' ), 5 );
		add_action( 'upgrader_process_complete', array( 'XPay_Log_Store', 'install' ) );
		add_action( XPay_Log_Store::CRON_HOOK, array( 'XPay_Log_Store', 'prune' ) );

		if ( is_admin() ) {
			// Covers plugin updates that skip the activation hook: install()
			// early-returns on a matching schema version, so this is one
			// cached option read on admin loads.
			add_action( 'admin_init', array( 'XPay_Log_Store', 'install' ) );
			add_action( 'admin_menu', array( 'XPay_Log_Viewer', 'register_menu' ) );
			add_action( 'admin_menu', array( 'XPay_Doc_Viewer', 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( 'XPay_Doc_Viewer', 'enqueue' ) );
			// admin-post.php is an is_admin() request, so this wiring lives
			// here; the handler re-checks capability and nonce itself.
			add_action( 'admin_post_xpay_log_export', array( 'XPay_Log_Viewer', 'handle_export' ) );
			add_action( 'admin_enqueue_scripts', array( 'XPay_Settings_Screen', 'enqueue' ) );
			add_action( 'add_meta_boxes', array( 'XPay_Order_Panel', 'register' ) );
			add_action( 'admin_notices', array( 'XPay_Method_Gateway', 'render_pin_rejected_notice' ) );
			add_action( 'admin_notices', array( 'XPay_Checkout_Service', 'render_currency_rejected_notice' ) );
			XPay_WPFunnels_Compat::register_admin();
			XPay_Legacy_Notice::register_admin();
		}

		XPay_Logger::init();
	}

	/**
	 * The combined gateway plus one row per splittable method. On shopper
	 * surfaces all are always registered — each row's is_available()
	 * decides what checkout actually shows, so mode switches never need
	 * cache-sensitive registration logic. WooCommerce accepts instances
	 * here, which the per-method rows need (their constructor takes the
	 * method type).
	 *
	 * Plain admin page requests get ONLY the main gateway: merchants see
	 * one XPay row in the Payments list and the order screens, exactly
	 * like PayPal's and Paymob's multi-method plugins. Modern WooCommerce
	 * hides the per-method rows there anyway via its shell-gateway rule
	 * (see XPay_Method_Gateway), but the legacy settings table on older
	 * WooCommerce has no such rule — skipping registration covers it.
	 * AJAX stays fully registered: refunds for per-method orders run
	 * through admin-ajax and need the row's gateway instance.
	 *
	 * @param array $gateways Registered gateway class names/instances.
	 * @return array
	 */
	public function register_gateway( array $gateways ): array {
		$gateways[] = 'XPay_Gateway';
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		foreach ( XPay_Payment_Methods::SPLITTABLE as $type ) {
			$gateways[] = new XPay_Method_Gateway( $type );
		}
		return $gateways;
	}

	/**
	 * Shared read-only gateway instance for settings access outside checkout.
	 */
	public function gateway(): XPay_Gateway {
		if ( null === $this->gateway ) {
			$this->gateway = new XPay_Gateway();
		}
		return $this->gateway;
	}
}
