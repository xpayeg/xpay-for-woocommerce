<?php
/**
 * XPay_Blocks_Support
 *
 * Cart & Checkout Blocks integration. Without this class the gateway
 * simply does not appear on block-based checkouts — Blocks does not fall
 * back to classic gateways.
 *
 * The payment step itself happens on the order-pay page (see
 * XPay_Gateway), so the Blocks surface stays minimal: render the
 * title/description, and let Blocks' standard redirect flow carry the
 * shopper to the pay page. One flow for both checkouts, by design.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class XPay_Blocks_Support extends AbstractPaymentMethodType {

	/** @var string Required by AbstractPaymentMethodType. */
	protected $name = XPay_Constants::GATEWAY_ID;

	public static function register(): void {
		if ( ! class_exists( AbstractPaymentMethodType::class ) ) {
			return;
		}
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				$registry->register( new self() );
			}
		);
	}

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . XPay_Constants::GATEWAY_ID . '_settings', array() );
	}

	public function is_active(): bool {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'xpay-blocks',
			XPAY_WC_PLUGIN_URL . 'assets/js/blocks-integration.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			XPAY_WC_VERSION,
			true
		);
		return array( 'xpay-blocks' );
	}

	public function get_payment_method_data(): array {
		return array(
			'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : 'XPay',
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'supports'    => array( 'products', 'refunds' ),
		);
	}
}
