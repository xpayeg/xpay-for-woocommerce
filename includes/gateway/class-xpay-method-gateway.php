<?php
/**
 * XPay_Method_Gateway
 *
 * One WooCommerce checkout row for one XPay payment method (ValU, Fawry,
 * …). The main XPay_Gateway is the card row; every other method the
 * merchant's account can charge gets an instance of this class, so the
 * checkout page's own radio list is the method selector — no second
 * selector nested inside the payment fields. Stripe's WooCommerce plugin
 * structures its methods the same way (WC_Stripe_UPE_Payment_Method), and
 * for the same reason.
 *
 * NOT a payment processor. Everything that moves money or state —
 * process_payment, refunds, settings — forwards to the main gateway, so
 * there is exactly one implementation of each. What lives here is only
 * what makes this row a row: its id (`xpay_<type>`), its label, icon and
 * description from the method registry, and its availability (the method
 * must be able to charge the store's currency, per the account map cached
 * at key save).
 *
 * Deliberately ONE class for every method rather than a subclass per
 * method: our methods differ only in registry data, never in behaviour.
 * The behavioural differences (a Fawry reference completing the session
 * unpaid) live on the platform and in the webhook handlers, keyed off the
 * event payloads — not off which row was clicked.
 *
 * Hidden from the WooCommerce Payments settings table (see
 * XPay_Plugin::hide_method_rows_in_admin): merchants manage one XPay
 * entry, and which methods appear is account + settings driven.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Method_Gateway extends WC_Payment_Gateway {

	/** @var string Wire method type ('valu', 'fawry', …). */
	private $method_type;

	/**
	 * @param string $type Wire method type this row offers.
	 */
	public function __construct( string $type ) {
		$this->method_type = $type;
		$this->id          = XPay_Constants::GATEWAY_ID . '_' . $type;
		$this->has_fields  = true;
		$this->supports    = array( 'products', 'refunds' );

		/*
		 * method_title and method_description stay EMPTY on purpose. The
		 * reactified Payments settings page hides a gateway only when it
		 * is a "shell": empty method title AND description, from a plugin
		 * that also registers a non-shell gateway
		 * (PaymentsProviders.php:637, is_shell_payment_gateway). The main
		 * XPay gateway is the non-shell; giving these rows a method title
		 * made each one its own provider row on that page, as if every
		 * method were a separate plugin. Stripe's per-method gateways set
		 * no method title for the same reason. The shopper-facing $title
		 * below is a different field and is untouched by this.
		 */
		$this->title       = XPay_Payment_Methods::label( $type );
		$this->description = XPay_Payment_Methods::description( $type );
		$this->icon        = XPay_Payment_Methods::icon_url( $type );

		// The same label the main gateway's button carries; the two must
		// never drift, because the shopper cannot tell which row set it.
		$this->order_button_text = __( 'Pay now', 'xpay-for-woocommerce' );

		// Read from the shared settings: a method row has no settings of
		// its own, and "enabled" means the ONE XPay integration is enabled.
		$this->enabled = $this->main_gateway()->get_option( 'enabled', 'no' );
	}

	/** @return string Wire method type this row offers. */
	public function get_method_type(): string {
		return $this->method_type;
	}

	/** The one gateway that actually processes payments and holds settings. */
	private function main_gateway(): XPay_Gateway {
		return XPay_Plugin::instance()->gateway();
	}

	/**
	 * Shown only when the store OFFERS this method for its currency: the
	 * account can charge it AND the merchant keeps it checked on the
	 * Payment Methods tab. The shared method_active_for_currency() is the
	 * one answer the card row and the Blocks 'active' flag read too, so
	 * the surfaces can never disagree.
	 */
	public function is_available() {
		return $this->main_gateway()->method_active_for_currency( $this->method_type );
	}

	/** The row's body: XPay's fields, restricted to this row's method. */
	public function payment_fields(): void {
		XPay_Checkout_Elements::render_mount( $this->method_type );
	}

	/**
	 * The row's brand mark on the classic checkout, at the row's far end.
	 *
	 * Emitted with our own class rather than relying on core's bare <img>,
	 * because the float and the size cap ride on it (the inline style
	 * XPay_Checkout_Elements registers) — the same pattern as Stripe's
	 * classed payment icons. '' when no licensed artwork ships.
	 */
	public function get_icon() {
		$url = XPay_Payment_Methods::icon_url( $this->method_type );
		if ( '' === $url ) {
			return '';
		}
		$icon = '<img src="' . esc_url( $url ) . '" class="xpay-method-icon xpay-method-icon--' . esc_attr( $this->method_type ) . '" alt="' . esc_attr( XPay_Payment_Methods::label( $this->method_type ) ) . '" />';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * @param int $order_id Order being paid.
	 * @return array result/redirect pair, from the main gateway.
	 */
	public function process_payment( $order_id ) {
		return $this->main_gateway()->process_payment( $order_id );
	}

	/**
	 * @param int    $order_id Order id.
	 * @param float  $amount   Amount to refund.
	 * @param string $reason   Admin-entered reason.
	 * @return bool|WP_Error From the main gateway.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return $this->main_gateway()->process_refund( $order_id, $amount, $reason );
	}

	/**
	 * @param WC_Order|false $order Order being viewed.
	 * @return bool From the main gateway.
	 */
	public function can_refund_order( $order ) {
		return $this->main_gateway()->can_refund_order( $order );
	}

	/**
	 * @param WC_Order $order Order being viewed.
	 * @return string From the main gateway (dashboard deep link).
	 */
	public function get_transaction_url( $order ) {
		return $this->main_gateway()->get_transaction_url( $order );
	}
}
