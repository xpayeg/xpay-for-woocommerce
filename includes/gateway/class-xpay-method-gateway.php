<?php
/**
 * XPay_Method_Gateway
 *
 * A dedicated checkout row for ONE payment method type (Card, valU,
 * Fawry). Thin by design: it inherits the whole payment flow from
 * XPay_Gateway and changes exactly three things — its identity (id,
 * title, icon), the session pin (paymentMethodTypes), and its
 * availability rule. Credentials, webhooks, refunds, and order truth stay
 * with the shared machinery; per-method rows never grow their own copies.
 *
 * Settings storage is the ONE shared option (woocommerce_xpay_settings,
 * via get_option_key) so a merchant configures keys once, whatever the
 * checkout display mode.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class XPay_Method_Gateway extends XPay_Gateway {

	/** @var string Method type wire string (XPay_Payment_Methods::SPLITTABLE member). */
	private $method_type;

	public function __construct( string $method_type ) {
		// Set before the parent constructor runs: gateway_id() below is
		// called from it, and the receipt/settings hooks bake the id in.
		$this->method_type = $method_type;

		parent::__construct();

		// Identity the shopper sees. Fixed labels, not settings fields:
		// a method row must say what the method is called everywhere else.
		$this->title       = XPay_Payment_Methods::label( $method_type );
		$this->description = XPay_Payment_Methods::description( $method_type );
		$this->icon        = XPay_Payment_Methods::icon_url( $method_type );

		// Deliberately NO admin identity: an empty method title AND
		// description makes this row a "shell" gateway to WooCommerce's
		// Payments settings page (PaymentsProviders::is_shell_payment_gateway),
		// which hides it there as long as the main XPay gateway — a
		// non-shell from the same plugin — is registered. One row in the
		// merchant's gateway list, three rows at shopper checkout: the same
		// pattern WooPayments and PayPal use for their sub-methods.
		// (Older WooCommerce renders the legacy table with no shell rule;
		// XPay_Plugin::register_gateway covers that side.)
		$this->method_title       = '';
		$this->method_description = '';

		// The payments-list toggle column reads $this->enabled. For a
		// method row that must mean "is THIS row offered", never the
		// shared plugin switch — same computed state the get_option
		// override answers, so display and toggle behavior agree.
		$this->enabled = $this->get_option( 'enabled' );
	}

	protected function gateway_id(): string {
		return XPay_Payment_Methods::gateway_id( $this->method_type );
	}

	protected function pinned_method_types(): ?array {
		return array( $this->method_type );
	}

	/**
	 * All rows read the ONE shared settings option — the merchant pastes
	 * keys exactly once. Without this override the settings API would
	 * derive a per-id option (woocommerce_xpay_valu_settings) that is
	 * forever empty.
	 */
	public function get_option_key() {
		return $this->plugin_id . XPay_Constants::GATEWAY_ID . '_settings';
	}

	/** A method row shows only when the merchant ticked it in split mode. */
	public function is_available() {
		return $this->base_available() && in_array( $this->method_type, $this->split_types(), true );
	}

	/**
	 * The payments-list AJAX toggle decides enable-vs-disable from
	 * get_option('enabled') — not from the $enabled property the list
	 * displays. Without this read override the row would answer with the
	 * SHARED plugin switch and the toggle acts on the wrong state in both
	 * directions. Must call parent::get_option for the master switch:
	 * routing through $this would recurse.
	 *
	 * @param string $key         Settings key.
	 * @param mixed  $empty_value Default when the key is unset.
	 */
	public function get_option( $key, $empty_value = null ) {
		if ( 'enabled' === $key ) {
			return 'yes' === parent::get_option( 'enabled' ) && in_array( $this->method_type, $this->split_types(), true ) ? 'yes' : 'no';
		}
		return parent::get_option( $key, $empty_value );
	}

	/**
	 * The payments-list toggle writes 'enabled' through here. On a method
	 * row that write must govern THIS row's checkbox — routed to the
	 * shared option key it would otherwise flip the plugin-wide switch,
	 * and toggling one method off would silently kill every XPay row.
	 * Enabling a row from the list also flips the display mode to split,
	 * because that is unambiguously what the admin just asked for;
	 * disabling leaves the mode alone (with no rows ticked the combined
	 * row returns on its own, so checkout can never go dark).
	 *
	 * @param string $key   Settings key being written.
	 * @param mixed  $value New value.
	 */
	public function update_option( $key, $value = '' ) {
		if ( 'enabled' === $key ) {
			if ( 'yes' === $value ) {
				parent::update_option( 'display_mode', 'split' );
			}
			return parent::update_option( XPay_Payment_Methods::setting_key( $this->method_type ), $value );
		}
		return parent::update_option( $key, $value );
	}

	/**
	 * The per-method rows carry no settings of their own — everything
	 * lives on the main XPay screen. An empty form keeps this row's
	 * "Manage" page honest instead of duplicating the credential fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array();
	}

	/**
	 * Admin notice when the API rejected a pinned method as not enabled
	 * for this merchant. Set by XPay_Checkout_Service at the moment of
	 * rejection; cleared when the merchant saves the XPay settings.
	 * Hooked on admin_notices by the plugin loader.
	 */
	public static function render_pin_rejected_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$rejected = get_option( XPay_Constants::OPTION_PIN_REJECTED, array() );
		if ( ! is_array( $rejected ) || array() === $rejected ) {
			return;
		}
		$labels = array();
		foreach ( array_keys( $rejected ) as $type ) {
			$labels[] = XPay_Payment_Methods::label( (string) $type );
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s is a comma-separated list of payment method names (for example "valU, Fawry"). */
				__( 'XPay: your XPay account does not have %s enabled. Shoppers who picked it were shown the full XPay payment window instead. Enable the method in your XPay dashboard, or untick it under WooCommerce → Settings → Payments → XPay.', 'xpay-for-woocommerce' ),
				implode( ', ', $labels )
			)
		);
		echo '</p></div>';
	}
}
