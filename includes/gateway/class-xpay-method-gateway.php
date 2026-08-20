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
	 * Script behind the wallet-number prompt on the classic checkout.
	 *
	 * Enqueued for the checkout page whatever the shopper has selected,
	 * because the prompt can appear mid-checkout when they pick the valU
	 * row or edit their phone, and a script fetched at that moment would
	 * arrive too late to carry the field across the refresh that revealed
	 * it. Loading it is cheap; loading it conditionally is not correct.
	 *
	 * Hooked on wp_enqueue_scripts by the plugin loader.
	 */
	public static function enqueue_wallet_phone(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		wp_enqueue_script(
			'xpay-checkout-wallet-phone',
			XPAY_WC_PLUGIN_URL . 'assets/js/checkout-wallet-phone.js',
			array( 'jquery' ),
			XPay_Constants::asset_version( 'assets/js/checkout-wallet-phone.js' ),
			true
		);
	}

	/* ── The valU wallet number ──────────────────────────────────────── */

	/**
	 * Checkout body for this row.
	 *
	 * For every method except valU this is WooCommerce's own description
	 * and nothing else. valU spends the wallet registered to a phone
	 * number, so when nothing we hold can be sent as that number the
	 * shopper is asked for one here, next to the row they just picked.
	 *
	 * The prompt is conditional on purpose. Rendering it always would ask
	 * card shoppers for a number their payment never spends, which is the
	 * same trade the combined session already refuses by leaving
	 * phoneNumberCollection off: that flag is session wide.
	 *
	 * Under the hosted payment window XPay asked for this itself. Elements
	 * puts the payment fields on this page instead, and the platform
	 * rejects any request to collect a phone in that mode, so the asking
	 * becomes ours. See HANDOFF-CUSTOMER-DETAILS.md.
	 */
	public function payment_fields(): void {
		parent::payment_fields();

		list( $phone, $country ) = $this->live_billing();
		if ( ! XPay_Wallet_Phone::must_ask( $this->method_type, '', $phone, $country ) ) {
			return;
		}

		$has_phone = '' !== trim( $phone );

		// WooCommerce's own field wrapper, so the theme styles this like
		// every other input on the checkout instead of leaving it as bare
		// text in the middle of the payment box.
		echo '<p class="form-row form-row-wide xpay-wallet-phone">';

		echo '<label for="xpay_wallet_phone">';
		echo esc_html__( 'Mobile number for your valU wallet', 'xpay-for-woocommerce' );
		echo '</label>';

		echo '<span class="xpay-wallet-phone__why">';
		echo esc_html(
			$has_phone
				// The number is present and unusable, so say which one we
				// looked at rather than implying they left it blank.
				? __( 'valU pays from the wallet registered to your mobile number, and the number on this order is not an Egyptian or Jordanian mobile. Enter the one your valU account uses.', 'xpay-for-woocommerce' )
				: __( 'valU pays from the wallet registered to your mobile number. Enter the Egyptian or Jordanian mobile your valU account uses.', 'xpay-for-woocommerce' )
		);
		echo '</span>';

		// The placeholder is an example number, not prose: it stays in Latin
		// digits in every locale because that is what a tel input accepts.
		echo '<span class="woocommerce-input-wrapper">';
		echo '<input type="tel" id="xpay_wallet_phone" name="xpay_wallet_phone" class="input-text" autocomplete="tel" inputmode="tel" placeholder="01012345678" value="" />';
		echo '</span>';

		echo '</p>';
	}

	/**
	 * Server-side gate at submit.
	 *
	 * This is the real check, not the rendered prompt: the prompt is a
	 * courtesy and a shopper can reach this point without ever seeing it.
	 * Nothing behind the plugin re-checks the number either, because the
	 * platform refuses to collect a phone in Elements mode and its own
	 * field for one carries no format rule.
	 */
	public function validate_fields() {
		// The Blocks checkout reaches this method too, and must not be
		// judged here. Its data arrives as Store API payment_data rather
		// than in $_POST, so this method would read an empty correction
		// field and a phone it cannot see, and refuse an order the shopper
		// had already answered correctly. XPay_Blocks_Wallet_Phone owns
		// that path and holds the right request. Found by placing a real
		// Blocks order that the prompt had been answered for.
		if ( is_callable( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request() ) {
			return true;
		}

		list( $phone, $country ) = $this->posted_billing();
		$submitted               = $this->posted_wallet_phone();

		if ( ! XPay_Wallet_Phone::must_ask( $this->method_type, $submitted, $phone, $country ) ) {
			return true;
		}

		wc_add_notice(
			'' === trim( $submitted )
				? __( 'Enter the mobile number registered to your valU wallet.', 'xpay-for-woocommerce' )
				: __( 'That is not an Egyptian or Jordanian mobile number. Check the number registered to your valU wallet and try again.', 'xpay-for-woocommerce' ),
			'error'
		);
		return false;
	}

	/**
	 * Store the confirmed wallet number on the order, then hand off to the
	 * shared payment flow.
	 *
	 * Written as its own meta rather than over the billing phone: see
	 * XPay_Constants::META_WALLET_PHONE for why. A note records the
	 * divergence so the merchant is not left wondering why the order shows
	 * one number and the payment names another.
	 *
	 * @param int $order_id Order being paid.
	 * @return array result/redirect pair per the gateway contract.
	 */
	public function process_payment( $order_id ) {
		$store_api = is_callable( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request();
		if ( ! $store_api && XPay_Wallet_Phone::spends_a_wallet( $this->method_type ) ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$wallet = XPay_Wallet_Phone::resolve(
					$this->posted_wallet_phone(),
					(string) $order->get_billing_phone(),
					(string) $order->get_billing_country()
				);
				if ( null !== $wallet ) {
					$order->update_meta_data( XPay_Constants::META_WALLET_PHONE, $wallet );
					$billing_e164 = XPay_Phone::to_e164( (string) $order->get_billing_phone(), (string) $order->get_billing_country() );
					if ( $billing_e164 !== $wallet ) {
						$order->add_order_note(
							sprintf(
								/* translators: %s is a mobile number in international format. */
								__( 'Shopper gave %s as their valU wallet number. The billing phone on this order was left as they entered it.', 'xpay-for-woocommerce' ),
								$wallet
							)
						);
					}
					$order->save();
				}
			}
		}

		return parent::process_payment( $order_id );
	}

	/**
	 * The correction field as posted, sanitized.
	 *
	 * WooCommerce verifies its own checkout nonce before either caller
	 * runs; verified again here so reading $_POST is guarded on its own
	 * terms rather than on a caller's promise.
	 */
	private function posted_wallet_phone(): string {
		if ( ! isset( $_POST['xpay_wallet_phone'] ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( isset( $_POST['woocommerce-process-checkout-nonce'] ) ? sanitize_key( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ) : '', 'woocommerce-process_checkout' ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( $_POST['xpay_wallet_phone'] ) );
	}

	/**
	 * Billing phone and country as posted with the checkout submission.
	 *
	 * Read from the submission rather than the customer object because the
	 * shopper may have edited either field in the same submission that is
	 * being validated.
	 *
	 * @return array{0:string,1:string}
	 */
	private function posted_billing(): array {
		if ( ! wp_verify_nonce( isset( $_POST['woocommerce-process-checkout-nonce'] ) ? sanitize_key( wp_unslash( $_POST['woocommerce-process-checkout-nonce'] ) ) : '', 'woocommerce-process_checkout' ) ) {
			return $this->customer_billing();
		}
		$phone   = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
		$country = isset( $_POST['billing_country'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : '';
		if ( '' === $phone && '' === $country ) {
			return $this->customer_billing();
		}
		return array( $phone, $country );
	}

	/**
	 * Billing phone and country as they stand while the checkout is being
	 * rendered, including mid-typing.
	 *
	 * WooCommerce re-renders the payment box over AJAX and posts the whole
	 * form as one encoded string, so the live values are in there rather
	 * than on the customer object. checkout-wallet-phone.js is what makes
	 * that refresh fire when the phone field changes.
	 *
	 * @return array{0:string,1:string}
	 */
	private function live_billing(): array {
		if ( ! isset( $_POST['post_data'] ) || ! check_ajax_referer( 'update-order-review', 'security', false ) ) {
			return $this->customer_billing();
		}
		$fields = array();
		wp_parse_str( sanitize_text_field( wp_unslash( $_POST['post_data'] ) ), $fields );

		$phone   = isset( $fields['billing_phone'] ) ? sanitize_text_field( (string) $fields['billing_phone'] ) : '';
		$country = isset( $fields['billing_country'] ) ? sanitize_text_field( (string) $fields['billing_country'] ) : '';
		if ( '' === $phone && '' === $country ) {
			return $this->customer_billing();
		}
		return array( $phone, $country );
	}

	/**
	 * Billing phone and country from the customer session.
	 *
	 * @return array{0:string,1:string}
	 */
	private function customer_billing(): array {
		$customer = is_callable( 'WC' ) && WC()->customer instanceof WC_Customer ? WC()->customer : null;
		if ( null === $customer ) {
			return array( '', '' );
		}
		return array( (string) $customer->get_billing_phone(), (string) $customer->get_billing_country() );
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
