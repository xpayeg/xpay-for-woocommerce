<?php
/**
 * XPay_Blocks_Wallet_Phone
 *
 * The valU wallet-number prompt on the Cart & Checkout Blocks checkout.
 *
 * Blocks renders its payment rows in the browser, which is a problem the
 * classic checkout does not have: the decision about whether to ask for a
 * number is made by XPay_Phone in PHP, and a second copy of that rule in
 * JavaScript would drift from the one that actually gates the payment. A
 * shopper would then be told their number is fine by one rule and refused
 * by another.
 *
 * So the rule never crosses. What crosses is its verdict, published on the
 * Store API cart response under the `xpay` extension namespace and
 * recomputed by the server every time the cart is fetched. Blocks fetches
 * the cart whenever the customer's address changes, so the prompt appears
 * and disappears as the shopper edits their phone without this file
 * knowing anything about Egyptian numbering.
 *
 * Submission is gated here too, on the Store API's own order hook rather
 * than the gateway's validate_fields(): Blocks does not call that method,
 * so a gate that lived only there would leave this checkout unguarded.
 *
 * @package XPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;

final class XPay_Blocks_Wallet_Phone {

	/** Extension namespace on the Store API cart response. */
	const NAMESPACE_KEY = 'xpay';

	/** The key the browser sends the confirmed number back under. */
	const FIELD = 'xpay_wallet_phone';

	public static function register(): void {
		// woocommerce_blocks_loaded fires from plugins_loaded priority 10,
		// one tick before this plugin boots at 11, so subscribing to it
		// would usually subscribe to an event already gone and the verdict
		// would never reach the browser. Same guard the row registration
		// uses, for the same reason.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::publish_verdict();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'publish_verdict' ) );
		}

		// The gate is registered whatever happened above: it belongs to the
		// Store API request, not to Blocks' own boot sequence.
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			array( __CLASS__, 'capture_and_gate' ),
			10,
			2
		);
	}

	/**
	 * Publish the server's verdict on the cart response.
	 *
	 * Deliberately only a verdict and the number to prefill: no rule, no
	 * pattern, nothing the browser could evaluate for itself and get a
	 * different answer from.
	 */
	public static function publish_verdict(): void {
		if ( ! class_exists( StoreApi::class ) || ! class_exists( ExtendSchema::class ) ) {
			return;
		}

		$extend = StoreApi::container()->get( ExtendSchema::class );
		if ( ! $extend instanceof ExtendSchema ) {
			return;
		}

		$extend->register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE_KEY,
				'data_callback'   => array( __CLASS__, 'verdict' ),
				'schema_callback' => array( __CLASS__, 'verdict_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * @return array{walletPhoneNeeded:bool, hasBillingPhone:bool}
	 */
	public static function verdict(): array {
		list( $phone, $country ) = self::customer_billing();

		return array(
			'walletPhoneNeeded' => XPay_Wallet_Phone::must_ask( XPay_Payment_Methods::VALU, '', $phone, $country ),
			// Lets the prompt say which situation the shopper is in without
			// the browser inspecting the number itself.
			'hasBillingPhone'   => '' !== trim( $phone ),
		);
	}

	/** @return array<string, array<string, mixed>> */
	public static function verdict_schema(): array {
		return array(
			'walletPhoneNeeded' => array(
				'description' => __( 'Whether the shopper must be asked for a valU wallet number.', 'xpay-for-woocommerce' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'hasBillingPhone'   => array(
				'description' => __( 'Whether the order already carries a phone number of any kind.', 'xpay-for-woocommerce' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
		);
	}

	/**
	 * Gate the submission and keep the confirmed number.
	 *
	 * Runs for every Store API checkout, and returns immediately unless the
	 * shopper actually chose a row that spends a wallet.
	 *
	 * @param WC_Order        $order   Order built from the request.
	 * @param WP_REST_Request $request The checkout request.
	 *
	 * @throws RouteException When the shopper must be asked for a number.
	 */
	public static function capture_and_gate( $order, $request ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! self::is_placing_the_order( $request ) ) {
			return;
		}

		$method = (string) $order->get_payment_method();
		$type   = XPay_Payment_Methods::type_for_gateway_id( $method );
		if ( null === $type || ! XPay_Wallet_Phone::spends_a_wallet( $type ) ) {
			return;
		}

		$submitted = self::submitted_number( $request );
		$phone     = (string) $order->get_billing_phone();
		$country   = (string) $order->get_billing_country();

		if ( XPay_Wallet_Phone::must_ask( $type, $submitted, $phone, $country ) ) {
			throw new RouteException(
				'xpay_wallet_phone_required',
				'' === trim( $submitted )
					? esc_html__( 'Enter the mobile number registered to your valU wallet.', 'xpay-for-woocommerce' )
					: esc_html__( 'That is not an Egyptian or Jordanian mobile number. Check the number registered to your valU wallet and try again.', 'xpay-for-woocommerce' ),
				400
			);
		}

		$wallet = XPay_Wallet_Phone::resolve( $submitted, $phone, $country );
		if ( null === $wallet ) {
			return;
		}

		$order->update_meta_data( XPay_Constants::META_WALLET_PHONE, $wallet );

		$billing_e164 = XPay_Phone::to_e164( $phone, $country );
		if ( $billing_e164 !== $wallet ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s is a mobile number in international format. */
					__( 'Shopper gave %s as their valU wallet number. The billing phone on this order was left as they entered it.', 'xpay-for-woocommerce' ),
					$wallet
				)
			);
		}
	}

	/**
	 * Whether this request is the shopper placing the order, rather than
	 * Blocks keeping its draft in step.
	 *
	 * This hook fires for both, which is a trap worth naming: selecting the
	 * valU row makes Blocks POST the chosen method to the same endpoint, and
	 * a gate that did not tell them apart would refuse the order the instant
	 * the shopper picked valU, before the field asking them for a number had
	 * even been answered. That is exactly what it did until a browser run
	 * showed the 400 arriving on selection.
	 *
	 * The draft sync sends the payment method and nothing else. Placing the
	 * order sends the customer's details with it, so the presence of a
	 * billing address is what separates them. Deliberately not keyed on the
	 * `__experimental_calc_totals` flag those requests also carry: a flag
	 * named experimental is not a contract to build a payment gate on.
	 *
	 * @param WP_REST_Request $request The checkout request.
	 */
	private static function is_placing_the_order( $request ): bool {
		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}
		$billing = $request['billing_address'];
		return is_array( $billing ) && array() !== array_filter( $billing );
	}

	/**
	 * The number the browser sent with the payment method data.
	 *
	 * The Store API delivers payment data as a list of key/value pairs
	 * rather than a map, so it is walked rather than indexed.
	 *
	 * @param WP_REST_Request $request The checkout request.
	 */
	private static function submitted_number( $request ): string {
		if ( ! $request instanceof WP_REST_Request ) {
			return '';
		}
		$payment_data = $request['payment_data'];
		if ( ! is_array( $payment_data ) ) {
			return '';
		}
		foreach ( $payment_data as $pair ) {
			if ( is_array( $pair ) && isset( $pair['key'], $pair['value'] ) && self::FIELD === $pair['key'] ) {
				return sanitize_text_field( (string) $pair['value'] );
			}
		}
		return '';
	}

	/**
	 * Billing phone and country for the shopper as the cart currently
	 * knows them.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function customer_billing(): array {
		$customer = is_callable( 'WC' ) && WC()->customer instanceof WC_Customer ? WC()->customer : null;
		if ( null === $customer ) {
			return array( '', '' );
		}
		return array( (string) $customer->get_billing_phone(), (string) $customer->get_billing_country() );
	}
}
