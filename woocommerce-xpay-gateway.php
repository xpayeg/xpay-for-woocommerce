<?php
// Cache bust: 2025-12-08-07-45


/**
 * Plugin Name: WooCommerce XPAY Gateway
 * Plugin URI: https://xpay.app/wooCommerce-xpay
 * Description: this is WooCommerce based plugin to use XPAY online payment gateway 
 * Author: XPAY
 * Author URI: https://xpay.app/
 * Version: 1.1
 */
 
defined( 'ABSPATH' ) or exit;

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

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'utils.php';
require_once plugin_dir_path( __FILE__ ) . 'actions.php';

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
function wc_xpay_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_Xpay';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_xpay_add_to_gateways' );


/**
 * Adds plugin page links
 * 
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_xpay_gateway_plugin_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=xpay_gateway' ) . '">' . esc_html__( 'Configure', 'wc-gateway-xpay' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}

// Register the filter after plugins are loaded to ensure translations are available
add_action( 'plugins_loaded', function() {
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_xpay_gateway_plugin_links' );
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
			if (isset($_POST['billing_phone'])) {
				// Simplified but effective international phone validation
				// This will accept almost any reasonable phone number format
				$phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
				
				// Remove all non-numeric characters except the leading +
				$digits_only = preg_replace('/[^\d+]/', '', $phone);
				
				// Check if we have at least 7 digits (minimum reasonable phone number length)
				// and not more than 15 digits (maximum length per E.164 standard)
				$digits_count = strlen(ltrim($digits_only, '+'));
				$is_correct = ($digits_count >= 7 && $digits_count <= 15);
				
				if (!$is_correct) {
					wc_add_notice(__('Please enter a valid phone number. International format is accepted (e.g., +1 123 456 7890).', 'wc-gateway-xpay'), 'error');
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
            $this->icon = apply_filters('woocommerce_offline_icon', '');
            $this->has_fields = true;
            $this->method_title = __('Xpay', 'wc-gateway-xpay');
            $this->method_description = __('Xpay gateway allow online payment', 'wc-gateway-xpay');

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
            // (WC keeps a singleton, and enqueue_checkout_scripts creates
            // another instance) — otherwise receipt_page would fire twice
            // and the modal HTML would be duplicated.
            static $receipt_action_registered = false;
            if (!$receipt_action_registered) {
                add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
                $receipt_action_registered = true;
            }

            // Hook into the payment fields display
            add_action('woocommerce_credit_card_form_start', array($this, 'payment_fields'));
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields() {
            $this->form_fields = apply_filters('wc_xpay_form_fields', array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc-gateway-xpay'),
                    'type' => 'checkbox',
                    'label' => __('Enable Xpay Payment', 'wc-gateway-xpay'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'wc-gateway-xpay'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-xpay'),
                    'default' => __('Xpay Payment', 'wc-gateway-xpay'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'wc-gateway-xpay'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'wc­gateway-xpay'),
                    'default' => __('Please remit payment to Store Name upon pickup or delivery.', 'wc-gateway-xpay'),
                    'desc_tip' => true,
                ),
                'instructions' => array(
                    'title' => __('Instructions', 'wc-gateway-xpay'),
                    'type' => 'textarea',
                    'description' => __('Instructions that will be added to the thank you page and emails.', 'wc-gateway-xpay'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'community_id' => array(
                    'title' => __('Community ID', 'wc-gateway-xpay'),
                    'type' => 'text',
                    'description' => __('This is the ID of your community you get form Xpay', 'wc-gateway-xpay'),
                    'desc_tip' => true,
                    'required' => true,
                ),
                'variable_amount_id' => array(
                    'title' => __('Variable Amount Template ID', 'wc-gateway-xpay'),
                    'type' => 'text',
                    'description' => __('This is the ID of your variable amount object you created on Xpay', 'wc-gateway-xpay'),
                    'default' => __('', 'wc-gateway-xpay'),
                    'desc_tip' => true,
                ),
                'payment_api_key' => array(
                    'title' => __('XPAY payment API key', 'wc-gateway-xpay'),
                    'type' => 'text',
                    'description' => __('This is the API key you get from Xpay', 'wc-gateway-xpay'),
                    'default' => __('', 'wc-gateway-xpay'),
                    'desc_tip' => true,
                ),
                'iframe_base_url' => array(
                    'title' => __('Environment', 'wc-gateway-xpay'),
                    'type' => 'select',
                    'required' => true,
                    'options' => array(
                        'http://127.0.0.1:8000' => __('Local'),
                        'https://new-dev.xpay.app' => __('Development'),
                        'https://staging.xpay.app' => __('Staging'),
                        'https://community.xpay.app' => __('Production'),
                    ),
                    'default' => 'https://staging.xpay.app'
                ),
                'callback_url' => array(
                    'title' => __('Callback URL :<h4 style="width: max-content;color:blue">' . $this->xpay_plugin_url . 'update_order.php <h4>', 'wc-gateway-xpay'),
                    'type' => 'text',
                    'description' => __('This is callback url that you will add in your api payment on xpay dashboard', 'wc-gateway-xpay'),
                    'default' => __($this->xpay_plugin_url . 'update_order.php', 'wc-gateway-xpay'),
                    'custom_attributes' => array('hidden' => true)
                ),
                'webhook_secret' => array(
                    'title'       => __('Webhook secret (optional)', 'wc-gateway-xpay'),
                    'type'        => 'password',
                    'description' => __('Paste the same secret here that you saved in XPay\'s "secret" field next to the callback URL. When this AND a signature header are both present on an incoming webhook, the plugin verifies a hex HMAC-SHA256 of the raw body and rejects mismatches with HTTP 401. Leave empty to accept unsigned webhooks during setup.', 'wc-gateway-xpay'),
                    'default'     => '',
                    'desc_tip'    => false,
                ),
                'debug' => array(
                    'title' => __('Debug', 'wc-gateway-xpay'),
                    'type' => 'checkbox',
                    'label' => __('Enable debug alert messages', 'wc-gateway-xpay'),
                    'default' => 'no'
                ),
            ));
        }
        public function payment_fields() {
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

            $method_labels = [
                'CARD' => __('Card', 'wc-gateway-xpay'),
                'FAWRY' => __('Fawry', 'wc-gateway-xpay'),
                'APPLE' => __('Apple Pay', 'wc-gateway-xpay'),
                'VALU' => __('valU', 'wc-gateway-xpay'),
                'MEEZA/DIGITAL' => __('Wallets', 'wc-gateway-xpay'),
                'Installment' => __('NBE Installments', 'wc-gateway-xpay'),
            ];

            // Add the installment pseudo-method only if XPay supports it AND
            // it's not already in the methods list (the upstream API has no
            // contract preventing 'Installment' from appearing there).
            if (!empty($prefs['supports_installments']) && !in_array('Installment', (array) $payment_methods, true)) {
                $payment_methods['installment'] = 'Installment';
            }


            echo '<div class="form-row form-row-first">
                    <label for="xpay_payment_method">' . __('Payment Method', 'wc-gateway-xpay') . ' <span class="required">*</span></label>
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
                        <input type="radio" class="xpay-payment-radio" name="xpay_payment_method" value="' . esc_attr($internal_key) . '" style="margin-right: 5px;" ' . $checked . '>
                        ' . esc_html($method_labels[$method]) . '
                    </label>';
            }


            echo '<div id="installment_options" style="display: grid; width: 100%; margin-top: 10px;">
                    <label>' . __('Installment Plans', 'wc-gateway-xpay') . ' <span class="required">*</span></label>
                    <div id="installment_card_container" style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;"></div>
                </div>';

            echo '<input type="hidden" name="xpay_selected_installment_plan" id="xpay_selected_installment_plan" value="">';

            ?>
            <script>
                jQuery(document).ready(function($) {
                    $('#installment_options').hide();

                    $('input[name="xpay_payment_method"]').change(function() {
                        if ($(this).val() === 'installment') { 
                            $.ajax({
                                url: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                                method: 'POST',
                                data: {
                                    action: 'fetch_installment_plans',
                                    amount: <?php echo (float) WC()->cart->total; ?>,
                                    nonce: <?php echo wp_json_encode( wp_create_nonce( 'xpay-installments' ) ); ?>
                                },
                                success: function(response) {
                                    $('#installment_options').show();
                                    const data = JSON.parse(JSON.parse(response));
                                    if (data && data.data && data.data.installment_fees) {
                                        const cartAmount = <?php echo (float) WC()->cart->total; ?>;
                                        const installmentPlans = data.data.installment_fees;
                                        const labels = <?php echo wp_json_encode(array(
                                            'months'         => __('Months', 'wc-gateway-xpay'),
                                            'totalInterest'  => __('Total Interest:', 'wc-gateway-xpay'),
                                            'monthlyPayment' => __('Monthly Payment:', 'wc-gateway-xpay'),
                                            'currency'       => __('EGP', 'wc-gateway-xpay'),
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
                                        alert(<?php echo wp_json_encode( __('Failed to fetch installment plans. Please try again.', 'wc-gateway-xpay') ); ?>);
                                    }
                                },
                                error: function(error) {
                                    alert(<?php echo wp_json_encode( __('Failed to load installment plans. Please try again.', 'wc-gateway-xpay') ); ?>);
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
            do_action('woocommerce_xpay_form_end', $this->id);
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page() {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
        }

        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false) {
            // Use the CRUD getter — direct property access on WC_Order is
            // deprecated and emits PHP 8 dynamic-property warnings, and is
            // not HPOS-safe.
            if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status('on-hold')) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            }
        }

        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id) {
            // Two sequential XPay HTTP calls (prepare-amount then pay) plus
            // the retry budget can take up to ~46s in pathological cases.
            // Request 60s of execution time on hosts that allow it.
            @set_time_limit(60);

            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice(__('Order not found.', 'wc-gateway-xpay'), 'error');
                return array('result' => 'failure');
            }

            // Idempotency: if a transaction was already created for this order
            // (e.g. customer hit Place Order twice), reuse it instead of
            // creating another one upstream.
            $existing_txn    = $order->get_meta('xpay_transaction_id');
            $existing_iframe = $order->get_meta('xpay_iframe_url');
            if ($existing_txn && $existing_iframe && !$order->is_paid()) {
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
                if ($started_ts && (time() - $started_ts) < 10 * MINUTE_IN_SECONDS && empty($existing_txn)) {
                    wc_add_notice(__('A previous payment attempt for this order is still being processed. If you were not redirected to the payment page, please contact support to confirm the payment status before trying again.', 'wc-gateway-xpay'), 'error');
                    return array('result' => 'failure');
                }
            }

            // Use $_POST not $_REQUEST so cookies cannot override form fields.
            // sanitize_text_field (not sanitize_key) preserves the slash in
            // 'meeza/digital' — but we then validate against an explicit
            // whitelist so only known keys reach the API.
            $payment_method     = isset($_POST['xpay_payment_method']) ? sanitize_text_field(wp_unslash($_POST['xpay_payment_method'])) : '';
            $installment_period = isset($_POST['xpay_selected_installment_plan']) ? sanitize_text_field(wp_unslash($_POST['xpay_selected_installment_plan'])) : '';

            if (!in_array($payment_method, xpay_allowed_method_keys(), true)) {
                wc_add_notice(__('Please select a valid payment method.', 'wc-gateway-xpay'), 'error');
                return array('result' => 'failure');
            }

            $api_key      = $this->get_option('payment_api_key');
            $debug        = $this->get_option('debug');
            $community_id = $this->get_option('community_id');
            $variable_id  = $this->get_option('variable_amount_id');
            $base_url     = rtrim($this->get_option('iframe_base_url'), '/');

            $original_amount = $order->get_total();

            // Step 1: prepare-amount.
            $prepare_body = httpPost(
                $base_url . '/api/v1/payments/prepare-amount/',
                wp_json_encode(array(
                    'community_id'            => $community_id,
                    'amount'                  => $original_amount,
                    'selected_payment_method' => $payment_method,
                    'variable_amount_id'      => $variable_id,
                )),
                $api_key,
                $debug
            );
            $prepare = is_string($prepare_body) ? json_decode($prepare_body, true) : null;
            if (!is_array($prepare) || !isset($prepare['data']['total_amount'])) {
                wc_add_notice(__('Payment processing failed. Please try again.', 'wc-gateway-xpay'), 'error');
                $order->update_status('failed', __('XPay prepare-amount call failed', 'wc-gateway-xpay'));
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
                wc_add_notice(__('Unknown payment method.', 'wc-gateway-xpay'), 'error');
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
            $pay_body = httpPost(
                $base_url . '/api/v1/payments/pay/variable-amount',
                wp_json_encode($payload),
                $api_key,
                $debug,
                0
            );
            // PHP 8 deprecates json_decode(null). httpPost returns null on
            // timeout / WAF block / network error — guard before decoding.
            $resp = is_string($pay_body) ? json_decode($pay_body, true) : null;
            if (!is_array($resp) || (isset($resp['status']['code']) ? $resp['status']['code'] : 0) !== 200) {
                $msg = isset($resp['status']['message']) ? $resp['status']['message'] : __('Payment processing failed.', 'wc-gateway-xpay');
                // If XPay returned a structured error (status.code set), the
                // call cleanly failed with no charge ambiguity. Clear the
                // in-flight fingerprint so the customer can retry immediately.
                // If status.code is missing (timeout / null response), keep
                // the fingerprint to block retries inside the 10-min window.
                if (is_array($resp) && isset($resp['status']['code'])) {
                    $order->delete_meta_data('xpay_pay_started_at');
                    $order->save();
                }
                wc_add_notice($msg, 'error');
                $order->update_status('failed', sprintf(__('XPay pay call failed: %s', 'wc-gateway-xpay'), $msg));
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
            $order->update_status('pending', __('Awaiting XPay payment', 'wc-gateway-xpay'));
            $order->save();

            // The cart can be cleared now: the order is the source of truth.
            WC()->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
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
                if (function_exists('generate_payment_modal')) {
                    generate_payment_modal($iframe_url, $trn_uuid, $order->get_id(), $community_id);
                }
                echo "<p id='xpay_message'>"
                    . esc_html__('Please complete your payment in the popup window. If it did not open, ', 'wc-gateway-xpay')
                    . "<a data-toggle='modal' data-target='#xpay_modal'>" . esc_html__('click here', 'wc-gateway-xpay') . "</a>."
                    . "</p>";
                return;
            }

            if ($message) {
                echo "<p id='xpay_message'>" . esc_html($message) . "</p>";
                return;
            }

            echo "<p id='xpay_message'>"
                . esc_html__('Your payment is being processed. You will receive a confirmation shortly.', 'wc-gateway-xpay')
                . "</p>";
        }
    }
}

if (!function_exists("generate_payment_modal")) {
    function generate_payment_modal($iframe_url, $trn_uuid, $order_id, $community_id) {
        // Bootstrap CSS/JS for the modal. We rely on WordPress's bundled
        // jQuery (already enqueued by WC on checkout/order-pay pages); we do
        // NOT load our own jQuery and we do NOT call noConflict — that
        // previously wiped window.jQuery for the rest of the page.
        $thankyou_url = '';
        $order_obj    = wc_get_order($order_id);
        if ($order_obj) {
            $thankyou_url = $order_obj->get_checkout_order_received_url();
        }
        ?>
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>

        <script>
            (function ($) {
                if (typeof $ === 'undefined') { return; }
                var xpayPluginUrl = <?php echo wp_json_encode(plugin_dir_url(__FILE__)); ?>;
                var xpayCommunityId = <?php echo wp_json_encode((string) $community_id); ?>;
                var xpayOrderId = <?php echo wp_json_encode((string) $order_id); ?>;
                var xpayThankyouUrl = <?php echo wp_json_encode($thankyou_url); ?>;

                // Poll interval (ms) for auto-detecting payment completion.
                // The XPay iframe does not postMessage to its parent on
                // success, so we poll our own check_transaction.php endpoint
                // (same-origin from the browser) until either the order is
                // paid or the customer closes the modal manually.
                //
                // check_transaction.php only echoes 'SUCCESSFUL' for orders
                // already in processing/completed state; FAILED, PENDING,
                // and INVALID statuses do NOT trigger auto-close, so a
                // failed payment leaves the modal open for the customer to
                // see XPay's failure message.
                var POLL_INTERVAL_MS    = 10000;
                var COUNTDOWN_SECONDS   = 5;

                $(function () {
                    var $modal = $('#xpay_modal');
                    if (!$modal.length) { return; }

                    var pollTimer      = null;
                    var countdownTimer = null;
                    var redirected     = false;

                    function stopPolling() {
                        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                    }
                    function stopCountdown() {
                        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
                    }

                    function doRedirect() {
                        if (xpayThankyouUrl) {
                            window.location.href = xpayThankyouUrl;
                        }
                    }

                    function showSuccessAndCountdown() {
                        if (redirected) { return; }
                        redirected = true;
                        stopPolling();

                        // If the modal is still open, show an in-modal
                        // banner with a visible countdown so the customer
                        // sees the success state before being redirected.
                        // If the modal was already closed (customer pressed
                        // X after paying), redirect straight away.
                        if ($modal.is(':visible')) {
                            var $banner = $('#xpay_success_banner');
                            var $count  = $('#xpay_redirect_countdown');
                            var remaining = COUNTDOWN_SECONDS;
                            $count.text(remaining);
                            $banner.show();
                            countdownTimer = setInterval(function () {
                                remaining--;
                                $count.text(remaining > 0 ? remaining : 0);
                                if (remaining <= 0) {
                                    stopCountdown();
                                    doRedirect();
                                }
                            }, 1000);
                        } else {
                            $('#xpay_message').text('Thank you - your order payment was completed successfully.');
                            doRedirect();
                        }
                    }

                    function checkAndMaybeRedirect() {
                        $.get(xpayPluginUrl + 'check_transaction.php', {
                            trn_uuid: $('#xpay_trn_uuid').val(),
                            community_id: xpayCommunityId,
                            order_id: xpayOrderId
                        }, function (data) {
                            if (data === 'SUCCESSFUL') {
                                showSuccessAndCountdown();
                            }
                            // Any other status (FAILED, PENDING, INVALID,
                            // empty) is ignored — the modal stays open and
                            // polling continues until the customer closes it.
                        });
                    }

                    $modal.modal({
                        backdrop: 'static',
                        keyboard: false
                    });

                    $modal.on('shown.bs.modal', function () {
                        $modal.css('z-index', 900);
                        $('.modal-backdrop:not(#xpay_modal)').hide();
                        pollTimer = setInterval(checkAndMaybeRedirect, POLL_INTERVAL_MS);
                    });

                    $modal.on('hidden.bs.modal', function () {
                        stopPolling();
                        // Customer-initiated close: do one final status check
                        // in case payment completed between polls. If it did,
                        // the redirect happens via doRedirect() (no banner —
                        // modal is no longer visible).
                        checkAndMaybeRedirect();
                    });
                });
            })(window.jQuery);
        </script>
        <!-- Modal -->
        <div class="modal fade" id="xpay_modal" role="dialog" style="visibility:visible;">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                        <h4 class="modal-title"><?php esc_html_e('Xpay Payment', 'wc-gateway-xpay'); ?></h4>
                        <p style="color:red"><?php esc_html_e("Don't close the popup until you finish payment", 'wc-gateway-xpay'); ?></p>
                    </div>
                    <div class="modal-body">
                        <div id="xpay_success_banner" style="display:none; padding:12px; background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:4px; margin-bottom:10px; text-align:center; font-weight:500;">
                            &#10003; <?php esc_html_e('Payment confirmed.', 'wc-gateway-xpay'); ?>
                            <?php esc_html_e('Redirecting in', 'wc-gateway-xpay'); ?>
                            <span id="xpay_redirect_countdown">5</span>
                            <?php esc_html_e('seconds…', 'wc-gateway-xpay'); ?>
                        </div>
                        <iframe src="<?php echo esc_url($iframe_url); ?>" class="no-lazy skip-lazy" style="border:none; width:100% !important; height:450px !important;"></iframe>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="trn_uuid" id="xpay_trn_uuid" value="<?php echo esc_attr($trn_uuid); ?>">
                        <button type="button" class="btn" data-dismiss="modal"><?php esc_html_e('Close', 'wc-gateway-xpay'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

// Enqueue styles
add_action('wp_enqueue_scripts', 'enqueue_xpay_styles');
function enqueue_xpay_styles() {
    if (is_admin()) {
        return;
    }
    wp_enqueue_style('xpay-styles', plugin_dir_url(__FILE__) . 'assets/css/style.css');
}

// Enqueue scripts
add_action('wp_enqueue_scripts', 'enqueue_checkout_scripts');
function enqueue_checkout_scripts() {
    // Prevent running in admin area
    if (is_admin()) {
        return;
    }
    // Get WooCommerce settings
    $xpay_gateway = new WC_Gateway_Xpay();
    
    // Retrieve prepareAmount Data from WooCommerce session
    $total_amount = WC()->session->get('xpay_total_amount', 0);
    $xpay_fees_amount = WC()->session->get('xpay_fees_amount', 0);
    $community_fees_amount = WC()->session->get('community_fees_amount', 0);

    // Enqueue the script
    wp_enqueue_script(
        'xpay-scripts',
        plugins_url('assets/js/checkout.js', __FILE__),
        array('jquery'),
        null,
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
        'iframe_base_url'   => $xpay_gateway->get_option('iframe_base_url'),
        'community_id'      => $xpay_gateway->get_option('community_id'),
        'amount'            => $total_amount,
        'currency'          => get_option('woocommerce_currency'), // WooCommerce setting
        'variable_amount_id'=> $xpay_gateway->get_option('variable_amount_id')
    );


    // Localize script with data
    wp_localize_script('xpay-scripts', 'xpayJSData', array(
        'ajax' => $sharedData,
        'promoCodeRequestData' => $promoCodeRequestData,
        'initialData' => $initialData,
    ));
}

?>
