<?php
/**
 * XPay_Blocks_Support
 *
 * Cart & Checkout Blocks integration. Without this class the gateway
 * simply does not appear on block-based checkouts — Blocks does not fall
 * back to classic gateways.
 *
 * One registration per checkout row: the Card row plus one per other
 * method the account can charge, mirroring the classic per-method
 * gateways. Before the account map is cached, a single "XPay" fallback
 * row renders every method inside the fields.
 *
 * The payment happens on the checkout page, in fields these rows mount —
 * not on the order-pay page. What this class provides is the server half:
 * each row's title, description, icon and method type, and the script
 * data the browser-side registration (assets/js/blocks-integration.js)
 * needs to mount against. The sequencing that makes it safe lives there.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class XPay_Blocks_Support extends AbstractPaymentMethodType {

	/** @var array{title:string, description:string, icon:string, active:bool} */
	private $row;

	/**
	 * @param string $name Gateway id this row registers as (xpay, xpay_valu, …).
	 * @param array  $row  title/description/icon/active for the row.
	 */
	public function __construct( string $name, array $row ) {
		$this->name = $name;
		$this->row  = $row;
	}

	public static function register(): void {
		if ( ! class_exists( AbstractPaymentMethodType::class ) ) {
			return;
		}
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				$gateway = XPay_Plugin::instance()->gateway();

				/*
				 * One Blocks registration per checkout row, mirroring the
				 * classic registrations exactly: the main row (Card once
				 * the account map is cached, the full "XPay" fallback
				 * before it) plus one row per other method the account can
				 * charge. Per-currency availability rides in 'active' —
				 * Blocks has no later is_available moment for these.
				 */
				$rows_active = $gateway->method_rows_active();

				$registry->register(
					new self(
						XPay_Constants::GATEWAY_ID,
						$rows_active
							? array(
								'title'       => XPay_Payment_Methods::label( XPay_Payment_Methods::CARD ),
								'description' => XPay_Payment_Methods::description( XPay_Payment_Methods::CARD ),
								'icon'        => XPay_Payment_Methods::icon_url( XPay_Payment_Methods::CARD ),
								'method'      => XPay_Payment_Methods::CARD,
								// The classic card row's is_available answers
								// through the same helper, so the two
								// checkouts can never disagree about a row.
								'active'      => $gateway->method_active_for_currency( XPay_Payment_Methods::CARD ),
							)
							: array(
								'title'       => $gateway->get_option( 'title', 'XPay' ),
								'description' => $gateway->get_option( 'description', '' ),
								'icon'        => '',
								'method'      => '',
								// The same availability answer the classic
								// fallback row gives (base_available via
								// offers_any_method): a store with no keys,
								// or a currency the platform cannot charge,
								// hides on the classic checkout — a hardcoded
								// true here kept a dead XPay row visible on
								// Blocks, one Disconnect click away.
								'active'      => $gateway->offers_any_method(),
							)
					)
				);

				foreach ( $gateway->method_row_types() as $type ) {
					$registry->register(
						new self(
							XPay_Constants::GATEWAY_ID . '_' . $type,
							array(
								'title'       => XPay_Payment_Methods::label( $type ),
								'description' => XPay_Payment_Methods::description( $type ),
								'icon'        => XPay_Payment_Methods::icon_url( $type ),
								'method'      => $type,
								'active'      => $gateway->method_active_for_currency( $type ),
							)
						)
					);
				}
			}
		);
	}

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . XPay_Constants::GATEWAY_ID . '_settings', array() );
	}

	public function is_active(): bool {
		return $this->row['active'] && isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles(): array {
		// The library this bundle depends on has to EXIST before the
		// dependency is declared. Blocks resolves it while building its own
		// asset registry, which runs on every page, and the library's own
		// enqueue is behind an is_checkout() guard — so on any other page
		// Blocks found nothing and deactivated this payment method outright.

		XPay_Checkout_Elements::register_scripts();

		// Registering the same handle again is a no-op in WordPress, so a
		// repeat call stays safe.
		wp_register_script(
			'xpay-blocks',
			XPAY_WC_PLUGIN_URL . 'assets/js/blocks-integration.js',
			/*
			 * xpay-elements is the library this bundle mounts with, and the
			 * handle xpayElementsParams is localised onto. Both were used
			 * without being declared, so the Blocks row worked only while
			 * the enqueue order happened to favour it — and an optimizer
			 * that reorders footer scripts would take the payment form away
			 * with no error anywhere.
			 */
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-data', XPay_Checkout_Elements::HANDLE ),
			XPay_Constants::asset_version( 'assets/js/blocks-integration.js' ),
			true
		);
		return array( 'xpay-blocks' );
	}

	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->row['title'],
			'description' => $this->row['description'],
			'icon'        => $this->row['icon'],
			'name'        => $this->name,
			// The row's method type; the browser mounts fields restricted
			// to it. '' on the fallback row = unfiltered.
			'method'      => isset( $this->row['method'] ) ? (string) $this->row['method'] : '',
			'supports'    => array( 'products', 'refunds' ),
			// Blocks' Place Order button label while an XPay row is
			// selected — the same string classic checkout gets from the
			// gateway's order_button_text.
			'buttonLabel' => __( 'Pay now', 'xpay-for-woocommerce' ),
			// the same words: two copies of this wording drifted apart once
			// already, and the shopper cannot tell which checkout they are on.
		);
	}
}
