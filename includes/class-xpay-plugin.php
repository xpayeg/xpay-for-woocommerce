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

		// Admin surfaces (log viewer, order panel).
		require_once $dir . 'admin/class-xpay-log-viewer.php';
		require_once $dir . 'admin/class-xpay-order-panel.php';

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
		// Priority 20: the status strip must read post-verification truth.
		add_action( 'woocommerce_before_thankyou', array( 'XPay_Thankyou_Notice', 'render' ), 20 );

		// Prune runs from WP-Cron (any request context, not just admin).
		add_action( XPay_Log_Store::CRON_HOOK, array( 'XPay_Log_Store', 'prune' ) );

		if ( is_admin() ) {
			// Covers plugin updates that skip the activation hook: install()
			// early-returns on a matching schema version, so this is one
			// cached option read on admin loads.
			add_action( 'admin_init', array( 'XPay_Log_Store', 'install' ) );
			add_action( 'admin_menu', array( 'XPay_Log_Viewer', 'register_menu' ) );
			add_action( 'add_meta_boxes', array( 'XPay_Order_Panel', 'register' ) );
			add_action( 'admin_notices', array( 'XPay_Method_Gateway', 'render_pin_rejected_notice' ) );
		}

		XPay_Logger::init();
	}

	/**
	 * The combined gateway plus one row per splittable method. All are
	 * always registered — each row's is_available() decides what checkout
	 * actually shows, so mode switches never need cache-sensitive
	 * registration logic. WooCommerce accepts instances here, which the
	 * per-method rows need (their constructor takes the method type).
	 *
	 * @param array $gateways Registered gateway class names/instances.
	 * @return array
	 */
	public function register_gateway( array $gateways ): array {
		$gateways[] = 'XPay_Gateway';
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
