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
		require_once $dir . 'constants/class-xpay-event-names.php';

		// Logging (loaded before anything that logs).
		require_once $dir . 'logger/class-xpay-redactor.php';
		require_once $dir . 'logger/class-xpay-logger.php';

		// API layer.
		require_once $dir . 'api/class-xpay-api-exception.php';
		require_once $dir . 'api/class-xpay-signature.php';
		require_once $dir . 'api/class-xpay-money.php';
		require_once $dir . 'api/class-xpay-api-client.php';

		// Domain services.
		require_once $dir . 'gateway/class-xpay-checkout-service.php';
		require_once $dir . 'gateway/class-xpay-order-sync.php';
		require_once $dir . 'refunds/class-xpay-refund-service.php';
		require_once $dir . 'webhooks/class-xpay-webhook-controller.php';

		// WooCommerce surfaces.
		require_once $dir . 'gateway/class-xpay-gateway.php';
		require_once $dir . 'blocks/class-xpay-blocks-support.php';
	}

	public function init(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'woocommerce_blocks_loaded', array( 'XPay_Blocks_Support', 'register' ) );

		// Public webhook receiver: https://<site>/?wc-api=xpay_webhook
		// Trust boundary: unauthenticated internet traffic — the HMAC
		// signature check inside the controller is the only gate.
		add_action( 'woocommerce_api_xpay_webhook', array( 'XPay_Webhook_Controller', 'handle' ) );

		// Thank-you page truth: re-check the session server-side rather than
		// trusting the redirect. Webhook remains the authoritative writer;
		// this closes the gap when the shopper outruns the webhook.
		add_action( 'woocommerce_before_thankyou', array( 'XPay_Order_Sync', 'verify_on_thankyou' ) );

		XPay_Logger::init();
	}

	/**
	 * @param array $gateways Registered gateway class names.
	 * @return array
	 */
	public function register_gateway( array $gateways ): array {
		$gateways[] = 'XPay_Gateway';
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
