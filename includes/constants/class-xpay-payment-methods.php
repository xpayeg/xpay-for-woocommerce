<?php
/**
 * XPay_Payment_Methods
 *
 * Registry for the payment method types this plugin names. Values are the
 * exact lowercase wire strings from the XPay API. One authoritative registry: never inline
 * a method type, label, or icon path at a call site.
 *
 * The plugin does NOT choose which methods a shopper sees — the merchant's
 * XPay account does, and the payment fields render whatever it allows. What
 * lives here is only the naming and artwork the plugin needs when it talks
 * ABOUT a method: the ValU phone prompt, the pay page's trust strip, the
 * welcome screen's chips.
 *
 * Adding a method:
 *   1. Add the wire-string constant.
 *   2. Add its label/description/icon cases below.
 *   3. Ship the icon under assets/images/ (never a CDN — wp.org rule).
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class XPay_Payment_Methods {

	const CARD  = 'card';
	const VALU  = 'valu';
	const FAWRY = 'fawry';

	/**
	 * Card networks XPay accepts, shown on the Card row. Amex is
	 * deliberately absent: XPay does not accept it, and the platform's own
	 * payment sheet shows exactly this set.
	 */
	const CARD_NETWORKS = array( 'visa', 'mastercard', 'meeza' );




	/** Shopper-facing row title. */
	public static function label( string $type ): string {
		switch ( $type ) {
			case self::CARD:
				return __( 'Card', 'xpay-for-woocommerce' );
			case self::VALU:
				return __( 'ValU', 'xpay-for-woocommerce' );
			case self::FAWRY:
				return __( 'Fawry', 'xpay-for-woocommerce' );
		}
		return $type;
	}

	/** One sentence under the row title. */
	public static function description( string $type ): string {
		switch ( $type ) {
			case self::CARD:
				return __( 'Pay with your Visa, Mastercard or Meeza card.', 'xpay-for-woocommerce' );
			case self::VALU:
				return __( 'Split your payment into installments with ValU.', 'xpay-for-woocommerce' );
			case self::FAWRY:
				return __( 'Get a reference code and pay at any Fawry point or in the app.', 'xpay-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Icon URL for the row, '' when no licensed artwork ships.
	 * The SVGs are copied verbatim from XPay's own checkout components —
	 * a brand logo is never redrawn by hand. Fawry uses the roundel alone
	 * because the payment-method row already writes the brand name.
	 */
	public static function icon_url( string $type ): string {
		switch ( $type ) {
			case self::CARD:
				return XPAY_WC_PLUGIN_URL . 'assets/images/card-networks.svg';
			case self::VALU:
				return XPAY_WC_PLUGIN_URL . 'assets/images/valu.svg';
			case self::FAWRY:
				return XPAY_WC_PLUGIN_URL . 'assets/images/fawry.svg';
		}
		return '';
	}
}
