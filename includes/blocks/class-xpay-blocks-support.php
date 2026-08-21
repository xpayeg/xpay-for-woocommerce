<?php
/**
 * XPay_Blocks_Support
 *
 * Cart & Checkout Blocks integration. Without this class the gateway
 * simply does not appear on block-based checkouts — Blocks does not fall
 * back to classic gateways.
 *
 * One instance per checkout row: the combined XPay row in combined mode,
 * or one per ticked method in split mode — mirroring exactly what
 * is_available() decides for classic checkout, so the two checkouts can
 * never disagree about which rows exist.
 *
 * The payment step itself happens on the order-pay page (see
 * XPay_Gateway), so the Blocks surface stays minimal: render the
 * title/description/icon, and let Blocks' standard redirect flow carry
 * the shopper to the pay page. One flow for both checkouts, by design.
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

				$registry->register(
					new self(
						XPay_Constants::GATEWAY_ID,
						array(
							'title'       => $gateway->get_option( 'title', 'XPay' ),
							'description' => $gateway->get_option( 'description', '' ),
							'icon'        => '',
							'active'      => true,
						)
					)
				);
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
		// One shared script serves every row; registering the same handle
		// repeatedly is a no-op in WordPress, so per-row calls stay safe.
		wp_register_script(
			'xpay-blocks',
			XPAY_WC_PLUGIN_URL . 'assets/js/blocks-integration.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-data' ),
			XPay_Constants::asset_version( 'assets/js/blocks-integration.js' ),
			true
		);
		// The row-id list the JS registers from — derived from the same
		// registry as everything else, so a new SPLITTABLE method reaches
		// Blocks without touching the JS (localizing twice is harmless:
		// the second call overwrites with identical data).
		wp_localize_script(
			'xpay-blocks',
			'xpayBlocksRowIds',
			array_merge(
				array( XPay_Constants::GATEWAY_ID ),
				array_map( array( 'XPay_Payment_Methods', 'gateway_id' ), XPay_Payment_Methods::SPLITTABLE )
			)
		);
		return array( 'xpay-blocks' );
	}

	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->row['title'],
			'description' => $this->row['description'],
			'icon'        => $this->row['icon'],
			'name'        => $this->name,
			'supports'    => array( 'products', 'refunds' ),
			// Blocks' Place Order button label while an XPay row is
			// selected — the same string classic checkout gets from the
			// gateway's order_button_text.
			'buttonLabel' => __( 'Pay now', 'xpay-for-woocommerce' ),
			// Copy for the valU number prompt, taken from the one place that
			// owns it. Blocks and the classic checkout show the same shopper
			// the same words: two copies of this wording drifted apart once
			// already, and the shopper cannot tell which checkout they are on.
			'bnplPhone'   => XPay_Checkout_Elements::bnpl_copy(),
		);
	}
}
