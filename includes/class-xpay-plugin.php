<?php
/**
 * XPay_Plugin
 *
 * Composition root for the plugin. Its only responsibilities:
 *   1. Load every class file from one authoritative include list.
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
		require_once $dir . 'constants/class-xpay-charge-status.php';
		require_once $dir . 'constants/class-xpay-payment-methods.php';
		require_once $dir . 'constants/class-xpay-event-names.php';

		// Logging (loaded before anything that logs).
		require_once $dir . 'logger/class-xpay-redactor.php';
		require_once $dir . 'logger/class-xpay-logger.php';

		// Admin surfaces (order panel, settings screen).
		require_once $dir . 'admin/class-xpay-order-panel.php';
		require_once $dir . 'admin/class-xpay-admin-screen.php';

		// API layer.
		require_once $dir . 'api/class-xpay-api-exception.php';
		require_once $dir . 'api/class-xpay-signature.php';
		require_once $dir . 'api/class-xpay-money.php';
		require_once $dir . 'api/class-xpay-fx.php';
		require_once $dir . 'api/class-xpay-api-client.php';

		// Domain services.
		require_once $dir . 'gateway/class-xpay-order-lock.php';
		require_once $dir . 'gateway/class-xpay-checkout-service.php';
		require_once $dir . 'gateway/class-xpay-checkout-elements.php';
		require_once $dir . 'gateway/class-xpay-gateway-order.php';
		require_once $dir . 'gateway/class-xpay-order-sync.php';
		require_once $dir . 'refunds/class-xpay-refundable.php';
		require_once $dir . 'refunds/class-xpay-refund-service.php';
		require_once $dir . 'webhooks/class-xpay-webhook-state.php';
		require_once $dir . 'webhooks/class-xpay-webhook-configurator.php';
		require_once $dir . 'webhooks/class-xpay-webhook-controller.php';
		require_once $dir . 'connect/class-xpay-connect-client.php';
		require_once $dir . 'connect/class-xpay-connect.php';

		// Compatibility integrations.
		require_once $dir . 'compat/class-xpay-wpfunnels-compat.php';

		// WooCommerce surfaces.
		require_once $dir . 'gateway/class-xpay-thankyou-notice.php';
		require_once $dir . 'gateway/class-xpay-gateway.php';
		require_once $dir . 'gateway/class-xpay-method-gateway.php';
		// Extends nothing, so it loads whatever Blocks is doing; every
		// Store API class it touches is guarded at the point of use.
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

		// Connect with XPay OAuth callback: https://<site>/?wc-api=xpay_connect
		// The merchant's browser returns here from XPay's approve page.
		XPay_Connect::register();

		// Thank-you page truth: re-check the session server-side rather than
		// trusting the redirect. Webhook remains the authoritative writer;
		// this closes the gap when the shopper outruns the webhook.
		add_action( 'woocommerce_before_thankyou', array( 'XPay_Order_Sync', 'verify_on_thankyou' ) );

		// Core cancels unpaid orders on the stock-hold timer (60 minutes by
		// default); a Fawry kiosk code is payable for 24 hours. Without this
		// the shopper pays at the kiosk and the webhook arrives to find the
		// order already cancelled.
		add_filter( 'woocommerce_cancel_unpaid_order', array( 'XPay_Order_Sync', 'should_cancel_unpaid' ), 10, 2 );

		// Activation alone cannot do it: our row is not in WooCommerce's
		// gateway map at that moment. See XPay_Gateway_Order.
		XPay_Gateway_Order::register();

		// The Payments table's drag-save rebuilds woocommerce_gateway_order
		// from the rows it shows, and the hidden method rows are not among
		// them — re-insert the block after every write, the way Stripe's
		// plugin does (class-wc-stripe.php:324). sync_gateway_order() itself
		// unhooks around its own write, so this cannot recurse.
		add_action( 'update_option_woocommerce_gateway_order', array( __CLASS__, 'sync_gateway_order' ) );

		// Let an XPay payment or session id typed into the orders-list search
		// box find its order. Support is handed one of these ids and nothing
		// else; without this the only way back to the order is a database
		// query.
		//
		// TWO filters, because the two order stores do not share one. The
		// legacy post store reads `woocommerce_shop_order_search_fields`
		// (class-wc-order-data-store-cpt.php:590); HPOS reads
		// `woocommerce_order_table_search_query_meta_keys`
		// (OrdersTableSearchQuery.php:446), and core's own docblock there
		// says so. Registering only the first is how this works on a
		// developer's HPOS store and silently does nothing on a merchant's.
		$xpay_search_keys = static function ( $keys ) {
			$keys   = is_array( $keys ) ? $keys : array();
			$keys[] = XPay_Constants::META_PAYMENT_INTENT;
			$keys[] = XPay_Constants::META_SESSION_ID;
			return array_values( array_unique( $keys ) );
		};
		add_filter( 'woocommerce_shop_order_search_fields', $xpay_search_keys );
		add_filter( 'woocommerce_order_table_search_query_meta_keys', $xpay_search_keys );
		// Priority 20: the payment-state notice must read post-verification
		// truth. WooCommerce's own page renders untouched around it.
		add_action( 'woocommerce_before_thankyou', array( 'XPay_Thankyou_Notice', 'render' ), 20 );

		// Compatibility shims: WPFunnels' order-received rewrite, and
		// optimizer opt-outs on the payment-critical script tags.
		XPay_WPFunnels_Compat::register();
		XPay_Checkout_Elements::register();

		/*
		 * A plugin update can change the subscribed event list; the stores'
		 * endpoints at XPay must follow without anyone re-saving keys. One
		 * cached option read per admin load; the reconfigure itself runs
		 * only on a version change, and only touches endpoints whose event
		 * list actually differs (Stripe reconfigures on update the same
		 * way).
		 */
		add_action(
			'admin_init',
			static function () {
				if ( XPAY_WC_VERSION === get_option( 'xpay_wc_version_seen' ) ) {
					return;
				}
				update_option( 'xpay_wc_version_seen', XPAY_WC_VERSION, false );
				XPay_Webhook_Configurator::maybe_reconfigure_on_update();
				// Keep newly available method rows together at the XPay
				// position without waiting for another settings save.
				self::sync_gateway_order();
			}
		);

		if ( is_admin() ) {
			// The method rows are not separate integrations to configure —
			// merchants manage ONE XPay entry — so they never appear on the
			// WooCommerce Payments settings table. Priority 5: before the
			// table renders. Stripe hides its per-method gateways the same
			// way, on the same hook.
			add_action( 'woocommerce_admin_field_payment_gateways', array( __CLASS__, 'hide_method_rows_in_admin' ), 5 );
			XPay_Admin_Screen::register();
			add_action( 'add_meta_boxes', array( 'XPay_Order_Panel', 'register' ) );
			add_action( 'woocommerce_admin_order_totals_after_total', array( 'XPay_Order_Panel', 'render_refund_currency_note' ) );
			XPay_WPFunnels_Compat::register_admin();
		}

		XPay_Logger::init();
	}

	/**
	 * One checkout row per payment method the account can charge.
	 *
	 * The main gateway is the Card row; every other method in the cached
	 * account map gets an XPay_Method_Gateway row, so WooCommerce's own
	 * radio list is the method selector — the same structure as Stripe's
	 * plugin, and for the same reason: a selector nested inside a selected
	 * row is two selectors. With no account map cached (keys written
	 * around the save path), only the main row registers and renders the
	 * full method list inside the fields, exactly as before.
	 *
	 * @param array $gateways Registered gateway class names/instances.
	 * @return array
	 */
	public function register_gateway( array $gateways ): array {
		$main       = $this->gateway();
		$gateways[] = $main;
		foreach ( $main->method_row_types() as $type ) {
			$gateways[] = new XPay_Method_Gateway( $type );
		}
		return $gateways;
	}

	/**
	 * Write the XPay rows into WooCommerce's own gateway ordering, in the
	 * merchant's Payment Methods order.
	 *
	 * `woocommerce_gateway_order` is the ONE list every checkout sorts by
	 * (WC_Payment_Gateways::init, class-wc-payment-gateways.php:106-131),
	 * and it holds two problems for the method rows. First, a gateway
	 * absent from it sorts at position 999+, so the rows would sit below
	 * every other plugin's options instead of beside the XPay entry.
	 * Second, the Payments table's own drag-save rebuilds the option from
	 * the rows it displays (class-wc-payment-gateways.php:395-406), and
	 * the method rows are hidden from that table — every drag would erase
	 * them again. Stripe's plugin answers both the same way
	 * (WC_Stripe_Helper::add_stripe_methods_in_woocommerce_gateway_order,
	 * re-run from update_option_woocommerce_gateway_order): rebuild the
	 * option with every XPay row id inserted at the XPay entry's own
	 * position, in the merchant's order, leaving every other gateway
	 * exactly where the merchant put it. This never moves XPay relative
	 * to other plugins — that stays the Payments table's decision.
	 */
	public static function sync_gateway_order(): void {
		$ordered = self::instance()->gateway()->ordered_method_types();
		if ( array() === $ordered ) {
			return; // No account map: only the single row exists, nothing to arrange.
		}

		$row_ids = array();
		foreach ( $ordered as $type ) {
			$row_ids[] = XPay_Payment_Methods::CARD === $type ? XPay_Constants::GATEWAY_ID : XPay_Constants::GATEWAY_ID . '_' . $type;
		}
		// The main gateway always registers (it is the settings anchor and
		// the fallback row), so it always needs a position — even for an
		// account with no card processor.
		if ( ! in_array( XPay_Constants::GATEWAY_ID, $row_ids, true ) ) {
			array_unshift( $row_ids, XPay_Constants::GATEWAY_ID );
		}

		$current = get_option( 'woocommerce_gateway_order', array() );
		$current = is_array( $current ) ? $current : array();
		asort( $current );

		$updated  = array();
		$position = 0;
		$inserted = false;
		foreach ( array_keys( $current ) as $id ) {
			$id = (string) $id;
			if ( XPay_Constants::is_xpay_gateway( $id ) ) {
				if ( ! $inserted ) {
					// The first XPay id marks where the merchant put XPay;
					// the whole block lands there. Later XPay ids are the
					// block's old members and are simply skipped.
					foreach ( $row_ids as $row_id ) {
						$updated[ $row_id ] = (string) $position++;
					}
					$inserted = true;
				}
				continue;
			}
			$updated[ $id ] = (string) $position++;
		}
		if ( ! $inserted ) {
			foreach ( $row_ids as $row_id ) {
				$updated[ $row_id ] = (string) $position++;
			}
		}

		if ( $updated === $current ) {
			return;
		}

		// This method also runs FROM the option's own update hook; writing
		// without unhooking would recurse.
		remove_action( 'update_option_woocommerce_gateway_order', array( __CLASS__, 'sync_gateway_order' ) );
		update_option( 'woocommerce_gateway_order', $updated );
		add_action( 'update_option_woocommerce_gateway_order', array( __CLASS__, 'sync_gateway_order' ) );
	}

	/**
	 * Keep the method rows off the LEGACY Payments settings table. They
	 * are checkout rows, not separate integrations: everything about them
	 * is managed on the one XPay settings screen.
	 *
	 * Legacy only. The reactified Payments page replays this same action
	 * before it reads the gateways list (PaymentsProviders.php:263), and
	 * unsetting there would remove the rows from the page's whole model
	 * rather than its display; that page hides them by its own shell rule
	 * instead (empty method title and description, see
	 * XPay_Method_Gateway's constructor). Stripe's hide carries the same
	 * guard for the same reason
	 * (class-wc-stripe-settings-controller.php:318).
	 */
	public static function hide_method_rows_in_admin(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return;
		}
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class )
			&& \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'reactify-classic-payments-settings' ) ) {
			return;
		}
		foreach ( WC()->payment_gateways->payment_gateways as $index => $gateway ) {
			if ( $gateway instanceof XPay_Method_Gateway ) {
				unset( WC()->payment_gateways->payment_gateways[ $index ] );
			}
		}
	}

	/**
	 * Shared gateway instance for settings access and processing.
	 *
	 * Settings are re-read on every access: this instance now registers
	 * with WooCommerce directly (it is the Card row), so it can be
	 * constructed before settings exist or consulted after they changed,
	 * and a snapshot from construction time would answer for the wrong
	 * configuration. init_settings() is one cached option read.
	 */
	public function gateway(): XPay_Gateway {
		if ( null === $this->gateway ) {
			$this->gateway = new XPay_Gateway();
		} else {
			$this->gateway->init_settings();
		}
		return $this->gateway;
	}
}
