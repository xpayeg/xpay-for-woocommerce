<?php
/**
 * Plugin Name: XPay for WooCommerce
 * Plugin URI: https://xpay.app/wooCommerce-xpay
 * Description: Accept Card, Fawry, valU, Apple Pay, Wallets, and NBE Installments on your WooCommerce store via XPay (Egypt).
 * Author: XPAY
 * Author URI: https://xpay.app/
 * Version: 2.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: xpay-for-woocommerce
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) or exit;

// Plugin version constant. Bumped in lockstep with the Version header above
// so every enqueued asset carries the same cache-busting version string and
// no cache-bust comment lines are ever needed in this file again.
defined( 'WC_XPAY_VERSION' ) || define( 'WC_XPAY_VERSION', '2.0.0' );

// Translations. WP.org-hosted plugins get auto-loading from the slug since
// WP 4.6, but call load_plugin_textdomain explicitly so non-WP.org installs
// (manual / GitHub direct download) also pick up the .mo files bundled in
// /languages/. PCP discourages this for WP.org-hosted plugins; we keep it
// because the plugin ships via both channels (WP.org slug + manual download).
add_action( 'init', function () {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	load_plugin_textdomain(
		'xpay-for-woocommerce',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);
} );

// Declare compatibility with WooCommerce features that gate plugins on
// explicit opt-in: High-Performance Order Storage (HPOS) and the new
// Cart/Checkout Blocks. Without these declarations WC marks the plugin
// as incompatible and may disable it on stores that have these enabled.
// before_woocommerce_init fires regardless of whether WC ends up loading,
// so this is safe to register before the WC-active check below.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables', __FILE__, true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks', __FILE__, true
		);
	}
} );

// XPay flow logger — bundled with the plugin, off by default. Loaded
// unconditionally so activation/deactivation/cron hooks register, but its
// hot-path listeners only attach when explicitly enabled in settings. The
// admin Tools page is always reachable so merchants can enable/test it
// without leaving the dashboard.
require_once plugin_dir_path( __FILE__ ) . 'includes/logger/class-wc-xpay-logger.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/logger/class-wc-xpay-logger-admin.php';
register_activation_hook(   __FILE__, array( 'WC_XPay_Logger', 'on_plugin_activation' ) );
register_deactivation_hook( __FILE__, array( 'WC_XPay_Logger', 'on_plugin_deactivation' ) );
add_action( 'plugins_loaded', array( 'WC_XPay_Logger', 'init' ), 99 );
add_action( 'plugins_loaded', array( 'WC_XPay_Logger_Admin', 'register' ), 99 );

// Third-party plugin compatibility shims and the admin notices that nudge
// merchants toward enabling them. Loaded unconditionally — each shim
// internally checks both the third-party plugin's presence and our own
// per-shim toggle before doing anything.
require_once plugin_dir_path( __FILE__ ) . 'includes/compat/class-wc-xpay-wpfunnels-compat.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-xpay-admin-notices.php';
add_action( 'plugins_loaded', array( 'WC_XPay_WPFunnels_Compat', 'init' ), 99 );
add_action( 'plugins_loaded', array( 'WC_XPay_Admin_Notices', 'init' ), 99 );

// Make sure WooCommerce is active. is_plugin_active() lives in
// wp-admin/includes/plugin.php which is not loaded on front-end requests
// by default, so include it on demand. This avoids the older
// apply_filters('active_plugins', ...) pattern that PCP flags as a
// non-prefixed filter invocation.
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'utils.php';
require_once plugin_dir_path( __FILE__ ) . 'actions.php';

/**
 * Cart-fee label used to surface the XPay promo discount on the WC cart and
 * order. Used by both the cart-fee hook (to add the fee) and process_payment
 * (to recover the pre-discount total when sending to XPay's API). Keep
 * unique so other plugins' fees never collide with this name.
 */
const XPAY_PROMO_FEE_NAME = 'XPay promo discount';

/**
 * How long (in seconds) to block concurrent payment retries after an
 * ambiguous upstream failure. Pre-flight failures (breaker open, DNS,
 * connect refused) clear the fingerprint immediately; only genuinely
 * ambiguous failures hold the customer here.
 */
const XPAY_CONCURRENT_RETRY_BLOCK_SECONDS = 2 * MINUTE_IN_SECONDS;

/**
 * Apply the XPay promo discount as a negative WC cart fee, so the discount
 * appears on the cart preview, in the checkout summary, in the resulting
 * order's totals, and on customer-facing emails. Without this, WC reports
 * the un-discounted total while XPay charges the discounted amount and
 * reconciliation diverges (H14).
 *
 * The discount value comes from the WC session, where it was written by
 * xpay_handle_validate_promo_code from XPay's server-side validate
 * response. The session value cannot be tampered with by the client (C5).
 */
add_action( 'woocommerce_cart_calculate_fees', 'xpay_apply_promo_fee_to_cart', 99 );
function xpay_apply_promo_fee_to_cart( $cart ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}
	$promo_id = (string) WC()->session->get( 'promocode_id' );
	$value    = (float)  WC()->session->get( 'discount_amount' ); // final amount AFTER discount, per XPay's response
	if ( '' === $promo_id || $value <= 0 ) {
		return;
	}

	// Pre-fee total: subtotal + shipping + taxes. We deliberately ignore
	// other plugins' fees here — the discount applies to the merchandise
	// subtotal, not to other plugins' surcharges.
	$pre_discount = (float) $cart->cart_contents_total
	              + (float) $cart->shipping_total
	              + (float) $cart->get_taxes_total();
	$fee_amount   = $value - $pre_discount; // negative when value < pre-discount
	if ( $fee_amount >= 0 ) {
		return; // promo doesn't actually reduce the total — nothing to apply
	}

	$cart->add_fee( XPAY_PROMO_FEE_NAME, $fee_amount, false /* taxable */ );
}

// Register a Cart/Checkout Blocks integration so the gateway shows up on
// stores using the new block-based checkout (default for WC 8.3+).
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once plugin_dir_path( __FILE__ ) . 'class-wc-xpay-blocks-integration.php';
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $registry ) {
			$registry->register( new WC_Xpay_Blocks_Integration() );
		}
	);
} );

/**
 * Add the gateway to WC Available Gateways
 * 
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + xpay gateway
 */
function xpay_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_Xpay';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'xpay_add_to_gateways' );


/**
 * Adds plugin page links
 * 
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function xpay_gateway_plugin_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=xpay_gateway' ) . '">' . esc_html__( 'Configure', 'xpay-for-woocommerce' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}

// Register the filter after plugins are loaded to ensure translations are available
add_action( 'plugins_loaded', function() {
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'xpay_gateway_plugin_links' );
}, 0 ); // Priority 0 to run early but after translations are loaded


/**
 * Xpay Payment Gateway
 *
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Gateway_Xpay
 * @extends		WC_Payment_Gateway
 * @package		WooCommerce/Classes/Payment
 * @author 		Xpay
 */
add_action( 'plugins_loaded', 'wc_xpay_gateway_init', 11);

function wc_xpay_gateway_init() {

	/**
	 * Validates the billing phone number field on checkout
	 * 
	 * UPDATE:
	 * This validation now accepts phone numbers from any country with various formats:
	 * - With or without country code (e.g., +1, +44)
	 * - With common separators (space, dash, period)
	 * - Different length standards around the world (typically 8-15 digits)
	 * - With or without parentheses for area codes
	 * 
	 * Examples of valid formats:
	 * - +1 (555) 123-4567
	 * - +44 7911 123456
	 * - 0123456789
	 * - +86 123 4567 8901
	 */
	if (!function_exists('xpay_custom_validate_billing_phone')) {
		function xpay_custom_validate_billing_phone() {
			// WC's checkout flow verifies woocommerce-process-checkout-nonce before
			// dispatching this validation hook (woocommerce_after_checkout_validation
			// in WC core). PCP can't model that cross-flow nonce; the read here is
			// safe because it only runs as a callback from WC's own validation pass.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if (isset($_POST['billing_phone'])) {
				// Simplified but effective international phone validation
				// This will accept almost any reasonable phone number format
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified by WC checkout flow above this callback.
				$phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
				
				// Remove all non-numeric characters except the leading +
				$digits_only = preg_replace('/[^\d+]/', '', $phone);
				
				// Check if we have at least 7 digits (minimum reasonable phone number length)
				// and not more than 15 digits (maximum length per E.164 standard)
				$digits_count = strlen(ltrim($digits_only, '+'));
				$is_correct = ($digits_count >= 7 && $digits_count <= 15);
				
				if (!$is_correct) {
					wc_add_notice(__('Please enter a valid phone number. International format is accepted (e.g., +1 123 456 7890).', 'xpay-for-woocommerce'), 'error');
				}
			}
		}
		add_action('woocommerce_checkout_process', 'xpay_custom_validate_billing_phone');
	}


	class WC_Gateway_Xpay extends WC_Payment_Gateway {

        /**
         * Plugin URL
         * @var string
         */
        public $xpay_plugin_url;

        /**
         * Payment instructions
         * @var string
         */
        public $instructions;

        /**
         * Constructor for the gateway.
         */
        public function __construct() {
            $this->id = 'xpay_gateway';
            $this->xpay_plugin_url = plugin_dir_url(__FILE__);
            $this->icon = '';
            $this->has_fields = true;
            $this->method_title = __('Xpay', 'xpay-for-woocommerce');
            $this->method_description = __('Xpay gateway allow online payment', 'xpay-for-woocommerce');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->instructions = $this->get_option('instructions', $this->description);

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Render the XPay iframe on the standard WC pay-for-order page,
            // not the thank-you page. Customer pays here, then is redirected
            // to the thank-you page after payment confirmation. We register
            // only once even if the gateway is instantiated multiple times
            // (WC keeps a singleton, and xpay_enqueue_checkout_scripts creates
            // another instance) — otherwise receipt_page would fire twice
            // and the modal HTML would be duplicated.
            static $receipt_action_registered = false;
            if (!$receipt_action_registered) {
                add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
                $receipt_action_registered = true;
            }

        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields() {
            $this->form_fields = apply_filters('wc_xpay_form_fields', array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'xpay-for-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Xpay Payment', 'xpay-for-woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'xpay-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'xpay-for-woocommerce'),
                    'default' => __('Xpay Payment', 'xpay-for-woocommerce'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'xpay-for-woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'xpay-for-woocommerce'),
                    'default' => __('Please remit payment to Store Name upon pickup or delivery.', 'xpay-for-woocommerce'),
                    'desc_tip' => true,
                ),
                'instructions' => array(
                    'title' => __('Instructions', 'xpay-for-woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Instructions that will be added to the thank you page and emails.', 'xpay-for-woocommerce'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'community_id' => array(
                    'title' => __('Community ID', 'xpay-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('This is the ID of your community you get form Xpay', 'xpay-for-woocommerce'),
                    'desc_tip' => true,
                    'required' => true,
                ),
                'variable_amount_id' => array(
                    'title' => __('Variable Amount Template ID', 'xpay-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('This is the ID of your variable amount object you created on Xpay', 'xpay-for-woocommerce'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'payment_api_key' => array(
                    'title' => __('XPAY payment API key', 'xpay-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('This is the API key you get from Xpay', 'xpay-for-woocommerce'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'iframe_base_url' => array(
                    'title' => __('Environment', 'xpay-for-woocommerce'),
                    'type' => 'select',
                    'required' => true,
                    'options' => array(
                        'http://127.0.0.1:8000'       => __('Local',       'xpay-for-woocommerce'),
                        'https://new-dev.xpay.app'    => __('Development', 'xpay-for-woocommerce'),
                        'https://staging.xpay.app'    => __('Staging',     'xpay-for-woocommerce'),
                        'https://community.xpay.app'  => __('Production',  'xpay-for-woocommerce'),
                    ),
                    'default' => 'https://staging.xpay.app'
                ),
                'callback_url' => array(
                    'title'             => __('Callback URL', 'xpay-for-woocommerce') . ' :<h4 style="width: max-content;color:blue">' . esc_html($this->xpay_plugin_url) . 'update_order.php</h4>',
                    'type'              => 'text',
                    'description'       => __('This is callback url that you will add in your api payment on xpay dashboard', 'xpay-for-woocommerce'),
                    'default'           => $this->xpay_plugin_url . 'update_order.php',
                    'custom_attributes' => array('hidden' => true)
                ),
                'webhook_secret' => array(
                    'title'       => __('Webhook secret (optional)', 'xpay-for-woocommerce'),
                    'type'        => 'password',
                    'description' => __('Paste the same secret here that you saved in your XPay community settings. When set, every incoming webhook must carry the same value as <code>secret_key</code> in the JSON body; missing or mismatched values are rejected with HTTP 401. Leave empty to accept webhooks without verification (suitable for initial setup, before you configure a secret in your XPay community).', 'xpay-for-woocommerce'),
                    'default'     => '',
                    'desc_tip'    => false,
                ),
                'debug' => array(
                    'title' => __('Debug', 'xpay-for-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable debug alert messages', 'xpay-for-woocommerce'),
                    'default' => 'no'
                ),
                'logger_enabled' => array(
                    'title'       => __('Diagnostic logger', 'xpay-for-woocommerce'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable XPay flow logger', 'xpay-for-woocommerce'),
                    'description' => __('When enabled, every step of the XPay flow is recorded to <code>wp-content/uploads/xpay-logs/</code> with secrets redacted. View the live tail under <strong>Tools → XPay Logger</strong>. Off by default — turn on while reproducing an issue, then disable. Logs are pruned after 30 days.', 'xpay-for-woocommerce'),
                    'default'     => 'no',
                ),
                'wpfunnels_force_standard_redirect' => array(
                    'title'       => __('WPFunnels compatibility', 'xpay-for-woocommerce'),
                    'type'        => 'checkbox',
                    'label'       => __('Force standard order-received page after payment', 'xpay-for-woocommerce'),
                    'description' => __('Only relevant when WPFunnels is active. After a successful XPay payment, WPFunnels rewrites the post-payment URL to route through its own funnel flow. On WPFunnels Free (no upsell wired up), the customer ends up on <code>/cart/</code> instead of a thank-you page even though the payment succeeded. Enable this to force the standard WooCommerce order-received page for XPay orders. Leave OFF if you have a configured WPFunnels Pro upsell flow you want XPay orders to enter.', 'xpay-for-woocommerce'),
                    'default'     => 'no',
                ),
            ));
        }
        public function payment_fields() {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Follows WC convention `woocommerce_<gateway_id>_form_start` so themes can hook the same way they would for any built-in WC gateway.
            do_action('woocommerce_xpay_form_start', $this->id);

            // Fetch available payment methods from XPay (cached + WAF-resilient).
            $community_id = $this->settings['community_id'];
            $prefs = xpay_get_community_preferences(
                $this->settings['iframe_base_url'],
                $community_id,
                $this->get_option('payment_api_key'),
                $this->get_option('debug')
            );
            $payment_methods  = $prefs['payment_methods'];
            $allow_promo_code = $prefs['allow_promo_code'];

            do_action('xpay_logger_event', 'payment_fields.render', array(
                'methods'              => array_values((array) $payment_methods),
                'method_count'         => count((array) $payment_methods),
                'allow_promo_code'     => (bool) $allow_promo_code,
                'supports_installments' => !empty($prefs['supports_installments']),
                'render_context'       => 'classic',
                'is_block_theme'       => function_exists('wp_is_block_theme') ? wp_is_block_theme() : null,
            ), 'payment_fields rendered');

            $method_labels = [
                'CARD' => __('Card', 'xpay-for-woocommerce'),
                'FAWRY' => __('Fawry', 'xpay-for-woocommerce'),
                'APPLE' => __('Apple Pay', 'xpay-for-woocommerce'),
                'VALU' => __('valU', 'xpay-for-woocommerce'),
                'MEEZA/DIGITAL' => __('Wallets', 'xpay-for-woocommerce'),
                'Installment' => __('NBE Installments', 'xpay-for-woocommerce'),
            ];

            // Add the installment pseudo-method only if XPay supports it AND
            // it's not already in the methods list (the upstream API has no
            // contract preventing 'Installment' from appearing there).
            if (!empty($prefs['supports_installments']) && !in_array('Installment', (array) $payment_methods, true)) {
                $payment_methods['installment'] = 'Installment';
            }


            echo '<div class="form-row form-row-first">
                    <label for="xpay_payment_method">' . esc_html__('Payment Method', 'xpay-for-woocommerce') . ' <span class="required">*</span></label>
                    <div class="xpay-payment-methods" style="text-align: left; direction: ltr;">';
            // ===== Conditionally Display Promo Code Section =====
            if ($allow_promo_code) {
                include(plugin_dir_path(__FILE__) . 'promo_code_section.php');
            }
            // ===== End Conditional Promo Code Section =====  

            // Default-check CARD when present, otherwise the first rendered
            // method. Without this, communities that don't support cards
            // would render with no radio checked at all.
            $has_card        = in_array('CARD', (array) $payment_methods, true);
            $checked_assigned = false;
            foreach ($payment_methods as $method) {
                if (!isset($method_labels[$method])) {
                    continue;
                }
                // Normalize via the shared map — strtolower would turn
                // 'MEEZA/DIGITAL' into 'meeza/digital', which sanitize_text_field
                // would preserve but the payment_config lookup uses 'wallets'.
                $internal_key = xpay_normalize_method_code($method);
                $checked      = '';
                if ($has_card) {
                    if ('CARD' === $method) {
                        $checked = 'checked';
                    }
                } elseif (!$checked_assigned) {
                    $checked          = 'checked';
                    $checked_assigned = true;
                }
                echo '<label class="xpay-method" style="display: flex; align-items: center;">
                        <input type="radio" class="xpay-payment-radio" name="xpay_payment_method" value="' . esc_attr($internal_key) . '" style="margin-right: 5px;" ' . esc_attr($checked) . '>
                        ' . esc_html($method_labels[$method]) . '
                    </label>';
            }


            echo '<div id="installment_options" style="display: grid; width: 100%; margin-top: 10px;">
                    <label>' . esc_html__('Installment Plans', 'xpay-for-woocommerce') . ' <span class="required">*</span></label>
                    <div id="installment_card_container" style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;"></div>
                </div>';

            echo '<input type="hidden" name="xpay_selected_installment_plan" id="xpay_selected_installment_plan" value="">';

            // Capture cart total once with a null-guard. WC()->cart is always
            // populated during standard WC checkout, but third-party builders
            // (e.g. WPFunnels) and AJAX previews can render this template
            // outside that context — letting (float) WC()->cart->total fatal.
            $cart_total = ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->total : 0.0;

            ?>
            <script>
                jQuery(document).ready(function($) {
                    $('#installment_options').hide();

                    $('input[name="xpay_payment_method"]').change(function() {
                        if ($(this).val() === 'installment') {
                            $.ajax({
                                url: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                                method: 'POST',
                                dataType: 'json',
                                data: {
                                    action: 'xpay_fetch_installment_plans',
                                    amount: <?php echo wp_json_encode( $cart_total ); ?>,
                                    nonce: <?php echo wp_json_encode( wp_create_nonce( 'xpay-installments' ) ); ?>
                                },
                                success: function(response) {
                                    $('#installment_options').show();
                                    if (response && response.success && response.data && response.data.data && response.data.data.installment_fees) {
                                        const cartAmount = <?php echo wp_json_encode( $cart_total ); ?>;
                                        const installmentPlans = response.data.data.installment_fees;
                                        const labels = <?php echo wp_json_encode(array(
                                            'months'         => __('Months', 'xpay-for-woocommerce'),
                                            'totalInterest'  => __('Total Interest:', 'xpay-for-woocommerce'),
                                            'monthlyPayment' => __('Monthly Payment:', 'xpay-for-woocommerce'),
                                            'currency'       => __('EGP', 'xpay-for-woocommerce'),
                                        )); ?>;
                                        $('#installment_card_container').empty();

                                        installmentPlans.forEach(function(plan) {
                                            const totalAmount = cartAmount + parseFloat(plan.installment_fees + plan.const_fees);
                                            const monthlyPayment = parseFloat((totalAmount / plan.period_duration).toFixed(2));
                                            const $card = $('<div class="installment-card"></div>')
                                                .attr('data-duration', plan.period_duration)
                                                .attr('data-total-amount', totalAmount.toFixed(2))
                                                .css({
                                                    border: '2px solid #ddd',
                                                    padding: '15px',
                                                    borderRadius: '8px',
                                                    width: '200px',
                                                    textAlign: 'center',
                                                    cursor: 'pointer',
                                                    background: '#f9f9f9',
                                                    flex: 1
                                                });
                                            $('<strong></strong>').text(plan.period_duration + ' ' + labels.months).appendTo($card);
                                            $('<p></p>').text(labels.totalInterest + ' ' + plan.interest_percentage).appendTo($card);
                                            $('<p></p>').text(labels.monthlyPayment + ' ' + monthlyPayment + ' ' + labels.currency).appendTo($card);
                                            $('#installment_card_container').append($card);
                                        });
                                    } else {
                                        alert(<?php echo wp_json_encode( __('Failed to fetch installment plans. Please try again.', 'xpay-for-woocommerce') ); ?>);
                                    }
                                },
                                error: function(error) {
                                    alert(<?php echo wp_json_encode( __('Failed to load installment plans. Please try again.', 'xpay-for-woocommerce') ); ?>);
                                }
                            });
                        } else {
                            $('#installment_options').hide();
                        }
                    });

                    $('#installment_card_container').on('click', '.installment-card', function() {
                        $('.installment-card').css({
                            "border": "2px solid #ddd",
                            "background-color": "#fff",
                            "box-shadow": "none",
                            "transform": "scale(1)",
                            "opacity": "1"
                        }); 

                        $(this).css({
                            "border": "2px solid #007cba",
                            "background-color": "#e3f2fd",
                            "box-shadow": "0px 4px 10px rgba(0, 124, 186, 0.3)",
                            "transform": "scale(1.05)",
                            "opacity": "1"
                        }); 
                        const selectedDuration = $(this).data('duration');
                        $('#xpay_selected_installment_plan').val(selectedDuration);
                    });
                });
            </script>
            <?php
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mirrors `woocommerce_<gateway_id>_form_end` from the WC gateway convention; renaming would break theme hooks.
            do_action('woocommerce_xpay_form_end', $this->id);
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page() {
            if ($this->instructions) {
                // wpautop + wptexturize produce intentional HTML wrapping that
                // would be double-encoded if we ran it through esc_html. The
                // input ($this->instructions) is merchant-set in WC admin
                // (capability-gated), not user-supplied.
                echo wpautop(wptexturize($this->instructions)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }

        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id) {
            $process_started = microtime(true);
            // Two sequential XPay HTTP calls (prepare-amount then pay) plus
            // the retry budget can take up to ~46s in pathological cases.
            // Request 60s of execution time on hosts that allow it. PCP flags
            // set_time_limit as discouraged; we accept that because the pay
            // call's worst-case latency exceeds the default 30s PHP limit and
            // there is no PHP-side equivalent that can extend a single request.
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
            @set_time_limit(60);

            $order = wc_get_order($order_id);
            if (!$order) {
                do_action('xpay_logger_event', 'process_payment.start', array(
                    'order_id' => (int) $order_id,
                    'error'    => 'order_not_found',
                ), 'order lookup failed');
                wc_add_notice(__('Order not found.', 'xpay-for-woocommerce'), 'error');
                return array('result' => 'failure');
            }

            // process_payment is invoked by WC's woocommerce_process_checkout
            // handler, which verifies the woocommerce-process-checkout-nonce
            // BEFORE calling any gateway. PCP can't see that cross-flow check,
            // so the $_POST reads inside this method get flagged. They're safe.
            do_action('xpay_logger_event', 'process_payment.start', array(
                'order_id'           => $order->get_id(),
                'cart_total'         => function_exists('WC') && WC()->cart ? (float) WC()->cart->total : null,
                'order_total'        => (float) $order->get_total(),
                'currency'           => $order->get_currency(),
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies woocommerce-process-checkout-nonce before invoking process_payment.
                'posted_method'      => isset($_POST['xpay_payment_method']) ? sanitize_text_field(wp_unslash($_POST['xpay_payment_method'])) : '',
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same as above.
                'posted_installment' => isset($_POST['xpay_selected_installment_plan']) ? sanitize_text_field(wp_unslash($_POST['xpay_selected_installment_plan'])) : '',
                'order_status_in'    => $order->get_status(),
            ), 'process_payment entered');

            // Idempotency: if a transaction was already created for this order
            // (e.g. customer hit Place Order twice), reuse it instead of
            // creating another one upstream.
            $existing_txn    = $order->get_meta('xpay_transaction_id');
            $existing_iframe = $order->get_meta('xpay_iframe_url');
            if ($existing_txn && $existing_iframe && !$order->is_paid()) {
                do_action('xpay_logger_event', 'process_payment.end', array(
                    'order_id'   => $order->get_id(),
                    'branch'     => 'idempotent_reuse',
                    'duration_ms' => (int) ((microtime(true) - $process_started) * 1000),
                ), 'reusing existing transaction; no new pay call');
                return array(
                    'result'   => 'success',
                    'redirect' => $order->get_checkout_payment_url(true),
                );
            }

            // Concurrent-attempt guard. If a previous process_payment started
            // the pay call but never finished writing meta (e.g. PHP killed
            // mid-flow), a recent xpay_pay_started_at is the only evidence we
            // have that the customer may already be charged at XPay. Block
            // retries within a 10-minute window so we don't double-charge.
            // After the window we assume the prior attempt has resolved and
            // allow a fresh try.
            $started_at = $order->get_meta('xpay_pay_started_at');
            if ($started_at) {
                $started_ts = strtotime($started_at . ' UTC');
                if ($started_ts && (time() - $started_ts) < XPAY_CONCURRENT_RETRY_BLOCK_SECONDS && empty($existing_txn)) {
                    do_action('xpay_logger_event', 'process_payment.end', array(
                        'order_id'        => $order->get_id(),
                        'branch'          => 'concurrent_attempt_blocked',
                        'previous_started_at' => $started_at,
                        'duration_ms'     => (int) ((microtime(true) - $process_started) * 1000),
                    ), 'blocked: previous attempt still in flight');
                    wc_add_notice(sprintf(
                        /* translators: %d is the order number */
                        __('Your previous payment attempt is still in progress. To prevent a double charge we are pausing new attempts for up to 2 minutes. If you do not see a confirmation email by then, contact support with order #%d — your card will not be charged twice.', 'xpay-for-woocommerce'),
                        (int) $order->get_id()
                    ), 'error');
                    return array('result' => 'failure');
                }
            }

            // Use $_POST not $_REQUEST so cookies cannot override form fields.
            // sanitize_text_field (not sanitize_key) preserves the slash in
            // 'meeza/digital' — but we then validate against an explicit
            // whitelist so only known keys reach the API.
            // Nonce verification is performed by WC's woocommerce_process_checkout
            // before this gateway method runs (see comment at the top of
            // process_payment).
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $payment_method     = isset($_POST['xpay_payment_method']) ? sanitize_text_field(wp_unslash($_POST['xpay_payment_method'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $installment_period = isset($_POST['xpay_selected_installment_plan']) ? sanitize_text_field(wp_unslash($_POST['xpay_selected_installment_plan'])) : '';

            if (!in_array($payment_method, xpay_allowed_method_keys(), true)) {
                wc_add_notice(__('Please select a valid payment method.', 'xpay-for-woocommerce'), 'error');
                return array('result' => 'failure');
            }

            $api_key      = $this->get_option('payment_api_key');
            $debug        = $this->get_option('debug');
            $community_id = $this->get_option('community_id');
            $variable_id  = $this->get_option('variable_amount_id');
            $base_url     = rtrim($this->get_option('iframe_base_url'), '/');

            // $order->get_total() includes our XPay promo fee (which is
            // negative when a promo is active). For the XPay API call we
            // want the un-discounted total — XPay's pay/variable-amount
            // expects original_amount to be the pre-promo amount and the
            // separate `amount` field to be the discounted amount. Other
            // plugins' fees are deliberately preserved in the total.
            $original_amount = (float) $order->get_total();
            foreach ($order->get_fees() as $fee) {
                if (XPAY_PROMO_FEE_NAME === $fee->get_name()) {
                    // fee total is negative (e.g. -20), so subtracting a
                    // negative restores the un-discounted total.
                    $original_amount -= (float) $fee->get_total();
                }
            }

            // Pre-validate required billing fields before making any XPay
            // HTTP call. XPay returns HTTP 400 with structured errors when
            // these are blank; catching them here gives the customer an
            // actionable notice and avoids a doomed upstream request.
            $required_billing = array(
                'first_name' => __('First name', 'xpay-for-woocommerce'),
                'last_name'  => __('Last name', 'xpay-for-woocommerce'),
                'email'      => __('Email address', 'xpay-for-woocommerce'),
                'phone'      => __('Phone number', 'xpay-for-woocommerce'),
            );
            $missing = array();
            foreach ($required_billing as $key => $label) {
                $getter = 'get_billing_' . $key;
                $value  = method_exists($order, $getter) ? trim((string) $order->$getter()) : '';
                if ('' === $value) {
                    $missing[] = $label;
                }
            }
            if (!empty($missing)) {
                do_action('xpay_logger_event', 'process_payment.invalid_billing', array(
                    'order_id'       => $order->get_id(),
                    'missing_fields' => $missing,
                ), 'pre-validation: required billing fields are blank');
                wc_add_notice(
                    sprintf(
                        /* translators: %s = comma-separated list of field labels */
                        __('Please complete the following before paying with XPay: %s.', 'xpay-for-woocommerce'),
                        implode(', ', $missing)
                    ),
                    'error'
                );
                return array('result' => 'failure');
            }

            // Step 1: prepare-amount. Tight timeout + no retry — prepare is
            // a lightweight fee/community lookup, and this runs on the
            // customer's interactive checkout submission. Letting it run
            // the full 25s default with a retry would push the total
            // checkout request past 30s under XPay slowness and saturate
            // PHP-FPM workers (taking the whole site down, not just
            // payments).
            $prepare_started = microtime(true);
            $prepare_body = xpay_http_post(
                $base_url . '/api/v1/payments/prepare-amount/',
                wp_json_encode(array(
                    'community_id'            => $community_id,
                    'amount'                  => $original_amount,
                    'selected_payment_method' => $payment_method,
                    'variable_amount_id'      => $variable_id,
                )),
                $api_key,
                $debug,
                0,                       // max_retries
                XPAY_PREPARE_TIMEOUT
            );
            $prepare = is_string($prepare_body) ? json_decode($prepare_body, true) : null;
            do_action('xpay_logger_event', 'process_payment.prepare', array(
                'order_id'    => $order->get_id(),
                'duration_ms' => (int) ((microtime(true) - $prepare_started) * 1000),
                'parsed_ok'   => is_array($prepare),
                'has_total'   => is_array($prepare) && isset($prepare['data']['total_amount']),
                'amount_in'   => (float) $original_amount,
                'amount_out'  => is_array($prepare) && isset($prepare['data']['total_amount']) ? (float) $prepare['data']['total_amount'] : null,
                'method'      => $payment_method,
            ), 'prepare-amount call completed');
            if (!is_array($prepare) || !isset($prepare['data']['total_amount'])) {
                $prepare_unreachable = (null === $prepare_body);
                do_action('xpay_logger_event', 'process_payment.end', array(
                    'order_id'    => $order->get_id(),
                    'branch'      => $prepare_unreachable ? 'prepare_unreachable' : 'prepare_failed',
                    'duration_ms' => (int) ((microtime(true) - $process_started) * 1000),
                ), $prepare_unreachable ? 'prepare-amount: no response (transport failure)' : 'prepare-amount returned no total_amount');
                if ($prepare_unreachable) {
                    wc_add_notice(__("We couldn't reach the payment provider right now. This is usually temporary — please wait a moment and try again. If this keeps happening, please contact us.", 'xpay-for-woocommerce'), 'error');
                } else {
                    $prepare_user_errors = xpay_extract_user_errors($prepare);
                    if (!empty($prepare_user_errors)) {
                        foreach ($prepare_user_errors as $prepare_user_error) {
                            wc_add_notice($prepare_user_error, 'error');
                        }
                    } else {
                        $prepare_msg = is_array($prepare) && isset($prepare['status']['message'])
                            ? $prepare['status']['message']
                            : __('Payment processing failed.', 'xpay-for-woocommerce');
                        wc_add_notice($prepare_msg, 'error');
                    }
                }
                $order->update_status('failed', __('XPay prepare-amount call failed', 'xpay-for-woocommerce'));
                return array('result' => 'failure');
            }
            $total_amount     = $prepare['data']['total_amount'];
            $installment_fees = isset($prepare['data']['installment_fees']) ? $prepare['data']['installment_fees'] : array();

            // Step 2: build the pay/variable-amount payload.
            $promocode_id    = WC()->session ? WC()->session->get('promocode_id')    : null;
            $discount_amount = WC()->session ? WC()->session->get('discount_amount') : null;

            $base_payload = array(
                'billing_data' => array(
                    'name'         => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'email'        => $order->get_billing_email(),
                    'phone_number' => $order->get_billing_phone(),
                ),
                'community_id'       => $community_id,
                'variable_amount_id' => $variable_id,
                'currency'           => $order->get_currency(),
                'original_amount'    => $original_amount,
                'amount'             => $total_amount,
            );
            if (!empty($promocode_id)) {
                $base_payload['promocode_id'] = $promocode_id;
                $base_payload['amount']       = $discount_amount;
            }

            $current_installment_fees = 0;
            if ($payment_method === 'installment') {
                foreach ($installment_fees as $fee) {
                    if ((int) $fee['period_duration'] === (int) $installment_period) {
                        $current_installment_fees = $fee['installment_fees'] + $fee['const_fees'];
                        break;
                    }
                }
            }

            $payment_config = array(
                'card'        => array('pay_using' => 'card'),
                'kiosk'       => array('pay_using' => 'kiosk'),
                'fawry'       => array('pay_using' => 'fawry'),
                'apple'       => array('pay_using' => 'apple'),
                'valu'        => array('pay_using' => 'valu'),
                'wallets'     => array('pay_using' => 'meeza/digital'),
                'installment' => array(
                    'pay_using'          => 'card',
                    'amount'             => $original_amount + $current_installment_fees,
                    'installment_period' => $installment_period,
                ),
            );
            if (!isset($payment_config[$payment_method])) {
                wc_add_notice(__('Unknown payment method.', 'xpay-for-woocommerce'), 'error');
                return array('result' => 'failure');
            }
            if (!empty($promocode_id) && $payment_method === 'installment') {
                wc_add_notice(__('Promo codes cannot be combined with installment payments. Please remove the promo code or choose a different payment method.', 'xpay-for-woocommerce'), 'error');
                return array('result' => 'failure');
            }
            $payload = array_merge($base_payload, $payment_config[$payment_method]);

            // Pre-save the payment-attempt fingerprint BEFORE the pay call so
            // that if PHP times out between the API call and our success-path
            // save below (which would charge the customer but not record the
            // transaction), the next attempt's concurrent-attempt guard will
            // catch it instead of silently double-charging.
            $order->update_meta_data('xpay_payment_method', $payment_method);
            $order->update_meta_data('xpay_pay_started_at', gmdate('Y-m-d H:i:s'));
            $order->save();

            // Step 3: pay/variable-amount. max_retries = 0 because retrying a
            // pay call after a timeout/non-JSON response risks double-charge —
            // the first attempt may have succeeded server-side at XPay even if
            // we never received the response.
            $pay_started = microtime(true);
            $pay_body = xpay_http_post(
                $base_url . '/api/v1/payments/pay/variable-amount',
                wp_json_encode($payload),
                $api_key,
                $debug,
                0
            );
            // PHP 8 deprecates json_decode(null). xpay_http_post returns null on
            // timeout / WAF block / network error — guard before decoding.
            $resp = is_string($pay_body) ? json_decode($pay_body, true) : null;
            do_action('xpay_logger_event', 'process_payment.pay', array(
                'order_id'      => $order->get_id(),
                'duration_ms'   => (int) ((microtime(true) - $pay_started) * 1000),
                'parsed_ok'     => is_array($resp),
                'status_code'   => is_array($resp) && isset($resp['status']['code']) ? (int) $resp['status']['code'] : null,
                'has_iframe_url' => is_array($resp) && !empty($resp['data']['iframe_url']),
                'has_txn_uuid'  => is_array($resp) && !empty($resp['data']['transaction_uuid']),
                'method'        => $payment_method,
            ), 'pay/variable-amount call completed');
            if (!is_array($resp) || (isset($resp['status']['code']) ? $resp['status']['code'] : 0) !== 200) {
                $pay_unreachable = (null === $pay_body);
                $pay_failure_type = function_exists('xpay_http_last_failure_type') ? xpay_http_last_failure_type() : 'ambiguous';

                // Clear the in-flight fingerprint when we're CONFIDENT no charge
                // could have happened. Three cases:
                //   1. XPay returned a structured response (status.code set): clean
                //      4xx/5xx, no charge ambiguity.
                //   2. xpay_http_post returned null AND the failure type is
                //      'pre_flight' (breaker open, DNS fail, connect refused, TLS
                //      handshake failure): no bytes reached XPay.
                //   3. Otherwise (ambiguous): keep the fingerprint so the
                //      concurrent-attempt guard prevents double-charge on retry.
                $can_clear_fingerprint = (is_array($resp) && isset($resp['status']['code']))
                                         || ($pay_unreachable && $pay_failure_type === 'pre_flight');
                if ($can_clear_fingerprint) {
                    $order->delete_meta_data('xpay_pay_started_at');
                    $order->save();
                }

                do_action('xpay_logger_event', 'process_payment.end', array(
                    'order_id'             => $order->get_id(),
                    'branch'               => $pay_unreachable ? 'pay_unreachable' : 'pay_failed',
                    'pay_failure_type'     => $pay_failure_type,
                    'fingerprint_cleared'  => $can_clear_fingerprint,
                    'upstream_status_code' => is_array($resp) && isset($resp['status']['code']) ? (int) $resp['status']['code'] : null,
                    'upstream_message'     => is_array($resp) && isset($resp['status']['message']) ? (string) $resp['status']['message'] : null,
                    'duration_ms'          => (int) ((microtime(true) - $process_started) * 1000),
                ), $pay_unreachable ? 'pay/variable-amount: no response (transport failure)' : 'pay call did not return success');
                if ($pay_unreachable) {
                    wc_add_notice(__("We couldn't reach the payment provider right now. This is usually temporary — please wait a moment and try again. If this keeps happening, please contact us.", 'xpay-for-woocommerce'), 'error');
                } else {
                    $msg = isset($resp['status']['message']) ? $resp['status']['message'] : __('Payment processing failed.', 'xpay-for-woocommerce');
                    $user_errors = xpay_extract_user_errors($resp);
                    if (!empty($user_errors)) {
                        foreach ($user_errors as $user_error) {
                            wc_add_notice($user_error, 'error');
                        }
                    } else {
                        wc_add_notice($msg, 'error');
                    }
                }
                $order->update_status('failed', sprintf(
                    /* translators: %s = upstream error message returned by XPay */
                    __('XPay pay call failed: %s', 'xpay-for-woocommerce'),
                    is_array($resp) && isset($resp['status']['message']) ? $resp['status']['message'] : __('transport failure', 'xpay-for-woocommerce')
                ));
                return array('result' => 'failure');
            }

            if (empty($resp['data']['iframe_url']) && empty($resp['data']['transaction_uuid'])) {
                do_action('xpay_logger_event', 'process_payment.empty_artifacts', array(
                    'order_id'             => $order->get_id(),
                    'upstream_status_code' => 200,
                ), 'pay/variable-amount returned 200 but no iframe_url or transaction_uuid');
                $order->delete_meta_data('xpay_pay_started_at');
                $order->save();
                wc_add_notice(__('Payment provider returned an empty response. Please try again.', 'xpay-for-woocommerce'), 'error');
                $order->update_status('failed', __('XPay returned 200 but no payment artifacts.', 'xpay-for-woocommerce'));
                return array('result' => 'failure');
            }

            // Persist transaction details on the order. Stock is NOT reduced
            // here — that happens via $order->payment_complete() once the
            // webhook confirms payment. trim() on the txn id so the strict
            // === comparisons in update_order.php / check_transaction.php
            // can't fail on whitespace-padded upstream values.
            if (!empty($resp['data']['transaction_uuid'])) {
                $order->update_meta_data('xpay_transaction_id', trim((string) $resp['data']['transaction_uuid']));
            }
            if (!empty($resp['data']['iframe_url'])) {
                $order->update_meta_data('xpay_iframe_url', $resp['data']['iframe_url']);
            }
            if (!empty($resp['data']['message'])) {
                $order->update_meta_data('xpay_response_message', $resp['data']['message']);
            }
            $order->update_meta_data('xpay_payment_method', $payment_method);
            $order->update_status('pending', __('Awaiting XPay payment', 'xpay-for-woocommerce'));
            $order->save();

            // The cart can be cleared now: the order is the source of truth.
            WC()->cart->empty_cart();

            $redirect_url = $order->get_checkout_payment_url(true);
            do_action('xpay_logger_event', 'process_payment.end', array(
                'order_id'        => $order->get_id(),
                'branch'          => 'success',
                'order_status_out' => $order->get_status(),
                'has_iframe_url'  => !empty($resp['data']['iframe_url']),
                'redirect_path'   => wp_parse_url($redirect_url, PHP_URL_PATH),
                'duration_ms'     => (int) ((microtime(true) - $process_started) * 1000),
            ), 'process_payment success; awaiting webhook');

            return array(
                'result'   => 'success',
                'redirect' => $redirect_url,
            );
        }

        /**
         * Renders the XPay iframe on the WC pay-for-order page
         * (/checkout/order-pay/{id}/). Reads the transaction details that
         * process_payment() persisted on the order — no API call here, so
         * refreshes are free.
         */
        public function receipt_page($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            $iframe_url   = $order->get_meta('xpay_iframe_url');
            $trn_uuid     = $order->get_meta('xpay_transaction_id');
            $message      = $order->get_meta('xpay_response_message');
            $community_id = $this->get_option('community_id');

            if ($iframe_url) {
                if (function_exists('xpay_generate_payment_modal')) {
                    xpay_generate_payment_modal($iframe_url, $trn_uuid, $order->get_id(), $community_id);
                }
                // The "click here" link is a fallback for when the modal
                // didn't auto-open (possible on aggressive lazy-load themes
                // or when our JS failed to bind for some reason). Plain
                // anchor with an id — our vanilla modal JS attaches a click
                // handler. No Bootstrap data-* attributes.
                echo "<p id='xpay_message'>"
                    . esc_html__('Please complete your payment in the popup window. If it did not open, ', 'xpay-for-woocommerce')
                    . "<a href='#' id='xpay_modal_open_link'>" . esc_html__('click here', 'xpay-for-woocommerce') . "</a>."
                    . "</p>";
                return;
            }

            if ($message) {
                echo "<p id='xpay_message'>" . esc_html($message) . "</p>";
                return;
            }

            echo "<p id='xpay_message'>"
                . esc_html__('Your payment is being processed. You will receive a confirmation shortly.', 'xpay-for-woocommerce')
                . "</p>";
        }
    }
}

/**
 * Allowlist check for the host of the iframe URL XPay returned in its
 * pay/variable-amount response. esc_url protects against unsafe schemes
 * (javascript:, data:, etc.) but does NOT bind the iframe to XPay-controlled
 * domains. If XPay's API response is ever compromised or misconfigured to
 * return an attacker-controlled URL, esc_url alone would still let it
 * render as an iframe on the merchant's payment page.
 *
 * Allowed hosts:
 *   - any subdomain of xpay.app (covers staging.xpay.app,
 *     staging-payment.xpay.app, community.xpay.app, community-payment.xpay.app,
 *     new-dev.xpay.app, etc.)
 *   - the bare xpay.app
 *   - 127.0.0.1 / localhost only when the gateway's Environment is set to
 *     the developer-only Local option
 */
if (!function_exists('xpay_iframe_host_is_allowed')) {
    function xpay_iframe_host_is_allowed($iframe_url) {
        $scheme = strtolower((string) wp_parse_url($iframe_url, PHP_URL_SCHEME));
        $host   = wp_parse_url($iframe_url, PHP_URL_HOST);
        if (!$host || !is_string($host)) {
            return false;
        }
        $host = strtolower($host);
        // Production hosts (xpay.app and any subdomain) MUST use HTTPS.
        // A response that hands us a non-HTTPS xpay.app URL is either a
        // misconfiguration or a downgrade attempt and would also trigger
        // browser mixed-content blocks anyway.
        if ('xpay.app' === $host || substr($host, -strlen('.xpay.app')) === '.xpay.app') {
            return 'https' === $scheme;
        }
        // Local-dev escape hatch — only when the gateway is actually
        // configured for the Local environment, AND the iframe scheme
        // matches the configured base URL's scheme. This stops production
        // deploys from accepting localhost iframes, and stops a misconfigured
        // base URL from accepting an iframe with an unrelated scheme.
        if ('127.0.0.1' === $host || 'localhost' === $host) {
            $settings    = get_option('woocommerce_xpay_gateway_settings', array());
            $base        = isset($settings['iframe_base_url']) ? (string) $settings['iframe_base_url'] : '';
            $base_host   = wp_parse_url($base, PHP_URL_HOST);
            $base_scheme = strtolower((string) wp_parse_url($base, PHP_URL_SCHEME));
            if (
                ('127.0.0.1' === $base_host || 'localhost' === $base_host) &&
                in_array($scheme, array('http', 'https'), true) &&
                $scheme === $base_scheme
            ) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists("xpay_generate_payment_modal")) {
    function xpay_generate_payment_modal($iframe_url, $trn_uuid, $order_id, $community_id) {
        // Bind the iframe to XPay-controlled hosts only. If the URL XPay
        // returned points anywhere else (an upstream compromise, a
        // misconfiguration, or an attacker-injected response), refuse to
        // render the iframe and surface a customer-facing error instead.
        if (!xpay_iframe_host_is_allowed($iframe_url)) {
            do_action('xpay_logger_event', 'iframe.blocked', array(
                'order_id'      => (int) $order_id,
                'rejected_host' => wp_parse_url($iframe_url, PHP_URL_HOST),
            ), 'iframe URL rejected: host not in xpay-controlled allowlist');
            echo '<p id="xpay_message" style="color:#a00;">'
                . esc_html(sprintf(
                    /* translators: %d is the order number */
                    __('We could not securely load the payment form. Your card has not been charged. Please contact support and quote order #%d so we can complete this payment for you.', 'xpay-for-woocommerce'),
                    (int) $order_id
                ))
                . '</p>';
            return;
        }

        // Vanilla CSS/JS modal. Replaces the legacy Bootstrap 3 + jQuery
        // implementation. Behavior is identical (poll → countdown →
        // redirect; manual-close fires one final check) but with no
        // external CDN dependency, no jQuery requirement, and no risk of
        // colliding with a theme that ships Bootstrap 4 or 5.
        $thankyou_url = '';
        $order_obj    = wc_get_order($order_id);
        if ($order_obj) {
            $thankyou_url = $order_obj->get_checkout_order_received_url();
        }

        $plugin_url = plugin_dir_url(__FILE__);
        wp_enqueue_style('xpay-modal', $plugin_url . 'assets/css/xpay-modal.css', array(), WC_XPAY_VERSION);
        wp_register_script('xpay-modal', $plugin_url . 'assets/js/xpay-modal.js', array(), WC_XPAY_VERSION, true);
        wp_localize_script('xpay-modal', 'xpayModal', array(
            'logEndpoint' => admin_url('admin-ajax.php'),
            'logNonce'    => wp_create_nonce('xpay_log_modal_event'),
            'checkUrl'    => $plugin_url . 'check_transaction.php',
            'communityId' => (string) $community_id,
            'orderId'     => (string) $order_id,
            'thankyouUrl' => $thankyou_url,
        ));
        wp_enqueue_script('xpay-modal');
        ?>
        <div id="xpay_modal_backdrop" class="xpay-modal-backdrop">
            <div id="xpay_modal" class="xpay-modal" role="dialog" aria-modal="true" aria-labelledby="xpay_modal_title" aria-hidden="true">
                <div class="xpay-modal-header">
                    <button type="button" class="xpay-modal-close" data-xpay-close
                            aria-label="<?php esc_attr_e('Close', 'xpay-for-woocommerce'); ?>">&times;</button>
                    <h4 id="xpay_modal_title" class="xpay-modal-title"><?php esc_html_e('Xpay Payment', 'xpay-for-woocommerce'); ?></h4>
                    <p class="xpay-modal-warning"><?php esc_html_e("Don't close the popup until you finish payment", 'xpay-for-woocommerce'); ?></p>
                </div>
                <div class="xpay-modal-body">
                    <div id="xpay_success_banner" class="xpay-success-banner" role="status">
                        &#10003; <?php esc_html_e('Payment confirmed.', 'xpay-for-woocommerce'); ?>
                        <?php esc_html_e('Redirecting in', 'xpay-for-woocommerce'); ?>
                        <span id="xpay_redirect_countdown">5</span>
                        <?php esc_html_e('seconds…', 'xpay-for-woocommerce'); ?>
                    </div>
                    <iframe src="<?php echo esc_url($iframe_url); ?>"
                            class="xpay-iframe no-lazy skip-lazy"
                            title="<?php esc_attr_e('XPay secure payment', 'xpay-for-woocommerce'); ?>"
                            referrerpolicy="strict-origin-when-cross-origin"></iframe>
                </div>
                <div class="xpay-modal-footer">
                    <input type="hidden" name="trn_uuid" id="xpay_trn_uuid" value="<?php echo esc_attr($trn_uuid); ?>">
                    <button type="button" class="xpay-btn" data-xpay-close><?php esc_html_e('Close', 'xpay-for-woocommerce'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * Is the current request rendering a page where XPay checkout JS/CSS is
 * actually used? checkout.js targets the classic-checkout payment-method
 * radios and promo-code input; both only exist on the checkout form itself.
 * Receipt page (order-pay) and order-received endpoint don't host them.
 *
 * Falls back to true if WC's conditional helpers aren't loaded — better to
 * over-enqueue than break checkout on an unusual context where the helpers
 * are missing.
 */
function xpay_is_checkout_context() {
    if ( ! function_exists( 'is_checkout' ) ) {
        return true;
    }
    if ( ! is_checkout() ) {
        return false;
    }
    if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
        return false;
    }
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
        return false;
    }
    return true;
}

// Enqueue styles. The promo code container, apply button, and input are all
// rendered inside payment_fields() — i.e. only on the checkout form. Loading
// the stylesheet on every front-end page (homepage, shop, product, blog,
// account) added ~2 KB of uncached CSS to every render with no targets.
add_action('wp_enqueue_scripts', 'xpay_enqueue_styles');
function xpay_enqueue_styles() {
    if (is_admin() || ! xpay_is_checkout_context()) {
        return;
    }
    wp_enqueue_style('xpay-styles', plugin_dir_url(__FILE__) . 'assets/css/style.css', array(), WC_XPAY_VERSION);
}

// Enqueue scripts. checkout.js binds to .xpay-payment-radio, #apply_promo_code
// and the order-total markup — all of which only exist on the classic
// checkout form. Previously this ran on every front-end page (only gated by
// is_admin), which:
//   - shipped 14 KB of JS + 600 B of inline xpayJSData on every page render
//   - constructed `new WC_Gateway_Xpay()` and generated 2 nonces per page
//   - leaked iframe_base_url / community_id / variable_amount_id into HTML
//     of pages that have nothing to do with checkout.
add_action('wp_enqueue_scripts', 'xpay_enqueue_checkout_scripts');
function xpay_enqueue_checkout_scripts() {
    if (is_admin() || ! xpay_is_checkout_context()) {
        return;
    }
    if ( ! function_exists('WC') || ! WC()->cart || ! WC()->session ) {
        return;
    }
    // Get WooCommerce settings
    $settings = get_option('woocommerce_xpay_gateway_settings', array());

    // Retrieve prepareAmount Data from WooCommerce session
    $total_amount = WC()->session->get('xpay_total_amount', 0);
    $xpay_fees_amount = WC()->session->get('xpay_fees_amount', 0);
    $community_fees_amount = WC()->session->get('community_fees_amount', 0);

    // Enqueue the script
    wp_enqueue_script(
        'xpay-scripts',
        plugins_url('assets/js/checkout.js', __FILE__),
        array('jquery'),
        WC_XPAY_VERSION,
        true
    );

    // Shared data for AJAX. Each AJAX endpoint gets its own nonce so
    // a token leaked from one form can't be replayed against another.
    $sharedData = array(
        'ajax_url'   => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('validate-promo-code'),
        'fees_nonce' => wp_create_nonce('xpay-fees'),
    );
    // fetch prepare amount data stored in wc Session for checkout page in order summary 
    $initialData = array(
        'subtotal_amount' => WC()->cart->total,
        'currency' => get_option('woocommerce_currency'),
    );

    // Fetch settings from the payment gateway
    $promoCodeRequestData = array(
        'iframe_base_url'   => $settings['iframe_base_url'] ?? '',
        'community_id'      => $settings['community_id'] ?? '',
        'amount'            => $total_amount,
        'currency'          => get_option('woocommerce_currency'), // WooCommerce setting
        'variable_amount_id'=> $settings['variable_amount_id'] ?? '',
    );


    // Localize script with data
    wp_localize_script('xpay-scripts', 'xpayJSData', array(
        'ajax' => $sharedData,
        'promoCodeRequestData' => $promoCodeRequestData,
        'initialData' => $initialData,
        'promo_strings' => xpay_promo_strings(),
    ));
}

?>
