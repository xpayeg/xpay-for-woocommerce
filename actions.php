<?php

// Register AJAX handlers for logged-in and guest users
add_action('wp_ajax_validate_xpay_promo_code', 'handle_validate_xpay_promo_code');
add_action('wp_ajax_nopriv_validate_xpay_promo_code', 'handle_validate_xpay_promo_code');
function handle_validate_xpay_promo_code() {
    // Verify the security nonce to ensure the request is legitimate
    check_ajax_referer('validate-promo-code', 'security');

    // Pull all credentials and the upstream URL from server-side settings.
    // The previous version accepted `url` from $_POST and forwarded the
    // api_key to it — an SSRF that turned this endpoint into an open relay
    // for credential exfiltration to attacker-controlled hosts.
    $gateway            = new WC_Gateway_Xpay();
    $api_key            = $gateway->get_option('payment_api_key');
    $debug              = $gateway->get_option('debug');
    $base_url           = rtrim($gateway->get_option('iframe_base_url'), '/');
    $server_community   = $gateway->get_option('community_id');
    $server_variable_id = $gateway->get_option('variable_amount_id');

    if (!$api_key || !$base_url || !$server_community) {
        wp_send_json_error(array('message' => 'XPay gateway is not configured'));
    }

    $name         = isset($_POST['name'])         ? sanitize_text_field(wp_unslash($_POST['name']))         : '';
    $amount       = isset($_POST['amount'])       ? sanitize_text_field(wp_unslash($_POST['amount']))       : '';
    $currency     = isset($_POST['currency'])     ? sanitize_text_field(wp_unslash($_POST['currency']))     : '';
    $payment_for  = isset($_POST['payment_for'])  ? sanitize_text_field(wp_unslash($_POST['payment_for']))  : '';
    $phone_number = isset($_POST['phone_number']) ? sanitize_text_field(wp_unslash($_POST['phone_number'])) : '';

    // Use server-side community_id and variable_amount_id, not anything
    // the caller supplied. (Caller-supplied versions could target an
    // unrelated XPay community whose promo codes happen to validate.)
    $community_id       = $server_community;
    $variable_amount_id = $server_variable_id;
    $api_url            = $base_url . '/api/promocodes/validate/';

    foreach (array('name', 'amount', 'currency', 'payment_for', 'phone_number') as $param) {
        if (empty($$param)) {
            wp_send_json_error(array('message' => 'Missing required parameters'));
        }
    }

    // Prepare the API request payload
    $request_body = json_encode(array(
        'name' => $name,
        'community_id' => $community_id,
        'amount' => $amount,
        'currency' => $currency,
        'payment_for' => $payment_for,
        'phone_number' => $phone_number,
        'variable_amount_id' => $variable_amount_id
    ));

    // Make the API request to validate the promo code
    $response = httpPost($api_url, $request_body, $api_key, $debug);
    $body = json_decode($response, true);
    
    // Handle error response
    if (!isset($body['status']['code']) || $body['status']['code'] !== 200) {
        $error_message = 'Invalid promo code';
        if (isset($body['status']['errors']) && is_array($body['status']['errors'])) {
            foreach ($body['status']['errors'] as $error) {
                if (isset($error['name'])) {
                    $error_message = $error['name'];
                    break;
                }
            }
        }
        wp_send_json_error(array('message' => $error_message));
        return;
    }

    // Check if response has data
    if (isset($body['data'])) {
        wp_send_json_success($body['data']);
    } else {
        wp_send_json_error(array('message' => 'Invalid response format'));
    }    
}

// Update the action registration to match the function name
add_action('wp_ajax_store_promocode_details', 'handle_store_promocode_details');
add_action('wp_ajax_nopriv_store_promocode_details', 'handle_store_promocode_details');
function handle_store_promocode_details() {
    check_ajax_referer('validate-promo-code', 'security');
    
    // Get the promocode_id and discount_amount from the AJAX request
    $promocode_id = sanitize_text_field($_POST['promocode_id']);
    $discount_amount = sanitize_text_field($_POST['discount_amount']);
    
    // Store both values in session
    WC()->session->set('promocode_id', $promocode_id);
    WC()->session->set('discount_amount', $discount_amount);
    
    // Send a success response with promocode details
    wp_send_json_success(array(
        'promocode_id' => $promocode_id,
        'discount_amount' => $discount_amount
    ));
}

add_action('wp_ajax_clear_promocode_details', 'handle_clear_promocode_details');
add_action('wp_ajax_nopriv_clear_promocode_details', 'handle_clear_promocode_details');
function handle_clear_promocode_details() {
    check_ajax_referer('validate-promo-code', 'security');
    
    // Clear promo code data from session
    WC()->session->__unset('promocode_id');
    WC()->session->__unset('discount_amount');
    
    wp_send_json_success(array(
        'message' => 'Promo code cleared successfully'
    ));
}

add_action('wp_ajax_xpay_get_payment_methods_fees', 'xpay_get_payment_methods_fees');
add_action('wp_ajax_nopriv_xpay_get_payment_methods_fees', 'xpay_get_payment_methods_fees');
function xpay_get_payment_methods_fees() {
    check_ajax_referer('xpay-fees', 'nonce');

    $selected_method = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';

    if (!function_exists('WC')) {
        wp_send_json_error(array('message' => 'WooCommerce not available.'));
    }

    $xpay_gateway = new WC_Gateway_Xpay();
    $api_key      = $xpay_gateway->get_option('payment_api_key');
    $community_id = $xpay_gateway->get_option('community_id');
    $variable_id  = $xpay_gateway->get_option('variable_amount_id');
    $currency     = get_option('woocommerce_currency');

    // WPFunnels and other custom checkouts may not have a populated WC
    // cart in the AJAX context. Accept the amount from the client as a
    // fallback when the cart total is 0.
    $posted_amount = isset($_POST['amount']) ? floatval(wp_unslash($_POST['amount'])) : 0;
    $cart_amount   = (function_exists('WC') && WC()->cart) ? (float) WC()->cart->total : 0;
    $order_amount  = $posted_amount > 0 ? $posted_amount : $cart_amount;

    if ($order_amount <= 0) {
        wp_send_json_error(array('message' => 'Amount unavailable.'));
    }

    $url     = rtrim($xpay_gateway->get_option('iframe_base_url'), '/') . '/api/v1/payments/prepare-amount/';
    $payload = array(
        'community_id'       => $community_id,
        'amount'             => $order_amount,
        'currency'           => $currency,
        'variable_amount_id' => $variable_id,
    );
    if (!empty($selected_method)) {
        $payload['selected_payment_method'] = $selected_method;
    }

    $response = httpPost($url, wp_json_encode($payload), $api_key, $xpay_gateway->get_option('debug'));
    $resp     = json_decode($response, true);

    if (is_array($resp) && isset($resp['data'])) {
        wp_send_json_success($resp['data']);
    } else {
        wp_send_json_error(array('message' => 'Failed to retrieve prepare amount data from Backend.'));
    }
}

?>
