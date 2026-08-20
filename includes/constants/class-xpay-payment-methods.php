<?php
/**
 * XPay_Payment_Methods
 *
 * Registry for the payment method types the plugin can offer as dedicated
 * checkout rows. Values are the exact lowercase wire strings from the v3
 * API (payment-method-type.enum.ts) — the same strings sent in
 * `paymentMethodTypes` on session create. One authoritative registry:
 * never inline a method type, label, or icon path at a call site.
 *
 * Adding a splittable method:
 *   1. Add the wire-string constant and append it to SPLITTABLE.
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

	/** Method types a merchant may split into dedicated checkout rows. */
	const SPLITTABLE = array( self::CARD, self::VALU, self::FAWRY );

	/**
	 * Card networks XPay accepts, shown on the Card row. Amex is
	 * deliberately absent: XPay does not accept it, and the platform's own
	 * payment sheet shows exactly this set.
	 */
	const CARD_NETWORKS = array( 'visa', 'mastercard', 'meeza' );

	/** WooCommerce gateway id for a method's dedicated row (xpay_card, …). */
	public static function gateway_id( string $type ): string {
		return XPay_Constants::GATEWAY_ID . '_' . $type;
	}

	/**
	 * The method a checkout row id names, or null when the id is not one of
	 * ours.
	 *
	 * The inverse of gateway_id(). Matched against the registry rather than
	 * by trimming the prefix off the string: the combined row is plain
	 * `xpay` and names no single method, and a foreign gateway that happens
	 * to start with the same letters must not be read as one of ours.
	 *
	 * @param string $gateway_id Row id as WooCommerce knows it.
	 */
	public static function type_for_gateway_id( string $gateway_id ): ?string {
		foreach ( self::SPLITTABLE as $type ) {
			if ( self::gateway_id( $type ) === $gateway_id ) {
				return $type;
			}
		}
		return null;
	}

	/** Settings key of the "offer this method as its own row" checkbox. */
	public static function setting_key( string $type ): string {
		return 'split_' . $type;
	}

	/** Shopper-facing row title. */
	public static function label( string $type ): string {
		switch ( $type ) {
			case self::CARD:
				return __( 'Card', 'xpay-for-woocommerce' );
			case self::VALU:
				return __( 'valU', 'xpay-for-woocommerce' );
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
				return __( 'Split your payment into installments with valU.', 'xpay-for-woocommerce' );
			case self::FAWRY:
				return __( 'Get a reference code and pay at any Fawry point or in the app.', 'xpay-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Icon URL for the row, '' when no licensed artwork ships yet.
	 * The SVGs are copied verbatim from XPay's own checkout components;
	 * Fawry stays text-only until XPay design provides the official mark —
	 * a brand logo is never redrawn by hand.
	 */
	public static function icon_url( string $type ): string {
		switch ( $type ) {
			case self::CARD:
				return XPAY_WC_PLUGIN_URL . 'assets/images/card-networks.svg';
			case self::VALU:
				return XPAY_WC_PLUGIN_URL . 'assets/images/valu.svg';
		}
		return '';
	}

	/**
	 * Canonical form of a method pin for storage and comparison: sorted,
	 * comma-joined, unknown-type-free. Two pins are the same restriction
	 * exactly when their normalized strings are equal — order carries no
	 * meaning in `paymentMethodTypes`.
	 *
	 * @param array $types Method type wire strings.
	 */
	public static function normalize_pin( array $types ): string {
		$known = array_values( array_intersect( $types, self::SPLITTABLE ) );
		sort( $known );
		return implode( ',', $known );
	}
}
