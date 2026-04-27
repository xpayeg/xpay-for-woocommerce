<?php
/**
 * Cart/Checkout Blocks integration for the XPay gateway.
 *
 * Registers the gateway on stores using the block-based checkout (default
 * for WC 8.3+). The block UI mirrors the classic payment_fields() output —
 * a radio-button list of available methods sourced from the same cached
 * preferences helper. The selected method is forwarded as paymentMethodData,
 * which WC blocks injects into $_POST so process_payment() reads it the
 * same way it does on the classic checkout.
 */

defined( 'ABSPATH' ) or exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Xpay_Blocks_Integration extends AbstractPaymentMethodType {

	/**
	 * Must match the gateway id in WC_Gateway_Xpay::__construct().
	 */
	protected $name = 'xpay_gateway';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_xpay_gateway_settings', array() );
	}

	public function is_active() {
		if ( empty( $this->settings['enabled'] ) || 'yes' !== $this->settings['enabled'] ) {
			return false;
		}
		// Don't surface the gateway in the block checkout if it would only
		// fail at process_payment time. Customers should not see a payment
		// option that is guaranteed to error.
		return ! empty( $this->settings['payment_api_key'] )
			&& ! empty( $this->settings['community_id'] )
			&& ! empty( $this->settings['iframe_base_url'] );
	}

	public function get_payment_method_script_handles() {
		$handle = 'xpay-blocks-integration';
		wp_register_script(
			$handle,
			plugin_dir_url( __FILE__ ) . 'assets/js/blocks-integration.js',
			array( 'wp-element', 'wp-i18n', 'wp-html-entities', 'wc-blocks-registry', 'wc-settings' ),
			// WC_XPAY_VERSION is defined unconditionally at the top of the
			// main plugin file, which always loads before this class is
			// instantiated by the blocks registry. The fallback exists only
			// to keep wp_register_script from being called with `false` if
			// some unforeseen execution order ever skips the constant.
			defined( 'WC_XPAY_VERSION' ) ? WC_XPAY_VERSION : '2.0.0',
			true
		);
		return array( $handle );
	}

	public function get_payment_method_data() {
		$prefs = function_exists( 'xpay_get_community_preferences' )
			? xpay_get_community_preferences(
				isset( $this->settings['iframe_base_url'] ) ? $this->settings['iframe_base_url'] : '',
				isset( $this->settings['community_id'] )    ? $this->settings['community_id']    : '',
				isset( $this->settings['payment_api_key'] ) ? $this->settings['payment_api_key'] : '',
				isset( $this->settings['debug'] )           ? $this->settings['debug']           : 'no'
			)
			: array(
				'payment_methods'       => array( 'CARD', 'FAWRY', 'VALU', 'MEEZA/DIGITAL' ),
				'allow_promo_code'      => false,
				'supports_installments' => true,
			);

		$methods = array_values( (array) $prefs['payment_methods'] );
		if ( ! empty( $prefs['supports_installments'] ) && ! in_array( 'Installment', $methods, true ) ) {
			$methods[] = 'Installment';
		}

		do_action( 'xpay_logger_event', 'payment_fields.render', array(
			'methods'              => $methods,
			'method_count'         => count( $methods ),
			'allow_promo_code'     => ! empty( $prefs['allow_promo_code'] ),
			'supports_installments' => ! empty( $prefs['supports_installments'] ),
			'render_context'       => 'blocks',
		), 'block-checkout payment-method data assembled' );

		return array(
			'title'       => isset( $this->settings['title'] )       ? $this->settings['title']       : 'XPay Payment',
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'methods'     => $methods,
			'supports'    => $this->get_supported_features(),
		);
	}

	public function get_supported_features() {
		// Mirror the classic gateway's supports list. WC blocks reads this
		// to decide whether the method is available for a given cart
		// (e.g. subscriptions need 'subscriptions').
		$gateway = $this->get_gateway_instance();
		if ( $gateway ) {
			return array_values( $gateway->supports );
		}
		return array( 'products' );
	}

	private function get_gateway_instance() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}
}
