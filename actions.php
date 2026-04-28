<?php

defined( 'ABSPATH' ) or exit;

// Register AJAX handlers for logged-in and guest users
add_action('wp_ajax_xpay_validate_promo_code', 'xpay_handle_validate_promo_code');
add_action('wp_ajax_nopriv_xpay_validate_promo_code', 'xpay_handle_validate_promo_code');
function xpay_handle_validate_promo_code() {
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

    if (!$api_key || !$base_url || !$server_community || !$server_variable_id) {
        wp_send_json_error(array('message' => 'XPay gateway is not configured'));
        return;
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
    $request_body = wp_json_encode(array(
        'name' => $name,
        'community_id' => $community_id,
        'amount' => $amount,
        'currency' => $currency,
        'payment_for' => $payment_for,
        'phone_number' => $phone_number,
        'variable_amount_id' => $variable_amount_id
    ));

    // Make the API request to validate the promo code
    $response = xpay_http_post($api_url, $request_body, $api_key, $debug);
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
        // Defensive: clear any previously-stored promo so a stale session value
        // doesn't survive a fresh failed validation.
        if (function_exists('WC') && WC()->session) {
            WC()->session->__unset('promocode_id');
            WC()->session->__unset('discount_amount');
        }
        wp_send_json_error(array('message' => $error_message));
        return;
    }

    // Check if response has data
    if (isset($body['data'])) {
        // SECURITY (C5): atomically store the server-validated discount in
        // the session here. The legacy `xpay_store_promo_details` AJAX handler
        // used to accept a discount_amount from $_POST and trust it
        // verbatim — anyone with a valid checkout-page nonce could set their
        // own discount. With this atomic-store, the session value is always
        // exactly what XPay's validate endpoint returned, and
        // xpay_store_promo_details no longer writes session at all.
        if (function_exists('WC') && WC()->session) {
            // Clear any prior promo before storing the new one. Without this,
            // a 200-OK response that XPay returns with empty/zero data (e.g. a
            // promo that validated but yielded no discount) would leave the
            // previous promo's id/amount in session, and the cart-fee hook
            // would keep applying the stale discount silently.
            WC()->session->__unset('promocode_id');
            WC()->session->__unset('discount_amount');

            $promo_id = isset($body['data']['promocode_id']) ? sanitize_text_field((string) $body['data']['promocode_id']) : '';
            $value    = isset($body['data']['value'])        ? (float) $body['data']['value']                              : 0.0;
            if ('' !== $promo_id && $value > 0) {
                WC()->session->set('promocode_id',    $promo_id);
                WC()->session->set('discount_amount', (string) $value);
            }
        }
        wp_send_json_success($body['data']);
    } else {
        wp_send_json_error(array('message' => 'Invalid response format'));
    }
}

// Update the action registration to match the function name
add_action('wp_ajax_xpay_store_promo_details', 'xpay_handle_store_promo_details');
add_action('wp_ajax_nopriv_xpay_store_promo_details', 'xpay_handle_store_promo_details');
function xpay_handle_store_promo_details() {
    check_ajax_referer('validate-promo-code', 'security');

    // SECURITY (C5): the discount amount must come from XPay's server-side
    // validate response (xpay_handle_validate_promo_code stores it in the
    // session atomically on success). The legacy implementation of this
    // handler accepted $_POST['discount_amount'] and wrote it directly to
    // the session — anyone with a valid checkout-page nonce could set their
    // own discount and pay the discounted amount.
    //
    // The handler is kept reachable so the existing front-end JS continues
    // to call it without errors. Its only behavior now is to read back what
    // is already in the session and return it. Any POST inputs are ignored.
    $session_promo_id = '';
    $session_discount = '';
    if (function_exists('WC') && WC()->session) {
        $session_promo_id = (string) WC()->session->get('promocode_id');
        $session_discount = (string) WC()->session->get('discount_amount');
    }

    wp_send_json_success(array(
        'promocode_id'    => $session_promo_id,
        'discount_amount' => $session_discount,
    ));
}

add_action('wp_ajax_xpay_clear_promo_details', 'xpay_handle_clear_promo_details');
add_action('wp_ajax_nopriv_xpay_clear_promo_details', 'xpay_handle_clear_promo_details');
function xpay_handle_clear_promo_details() {
    check_ajax_referer('validate-promo-code', 'security');

    // Mirror the guard used everywhere else we touch the session — WC's
    // session loader can be skipped in unusual AJAX contexts (third-party
    // checkout builders, very early plugin boot ordering), and a fatal
    // here would 500 the AJAX call instead of cleanly no-op'ing.
    if (function_exists('WC') && WC()->session) {
        WC()->session->__unset('promocode_id');
        WC()->session->__unset('discount_amount');
    }

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

    // Prefer the server-side cart total. The posted amount is client-supplied
    // and only used as a fallback when WC()->cart is unavailable in the AJAX
    // context (WPFunnels and other custom checkouts can render outside the
    // standard WC cart lifecycle, leaving cart->total at 0).
    $posted_amount = isset($_POST['amount']) ? floatval(wp_unslash($_POST['amount'])) : 0;
    $cart_amount   = (function_exists('WC') && WC()->cart) ? (float) WC()->cart->total : 0;
    $order_amount  = $cart_amount > 0 ? $cart_amount : $posted_amount;

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

    // WC fires `updated_checkout` on every address/shipping/method/coupon
    // tweak — without caching, each fire triggers an upstream prepare-amount
    // call (~500ms-1s), so a single checkout flow burns 2-3s waiting on a
    // deterministic fee calculation. Cache for 60s keyed by everything that
    // affects the upstream response.
    $cache_key = 'xpay_fees_' . md5($url . '|' . wp_json_encode($payload));
    $cached    = get_transient($cache_key);
    if (is_array($cached)) {
        wp_send_json_success($cached);
    }

    $response = xpay_http_post($url, wp_json_encode($payload), $api_key, $xpay_gateway->get_option('debug'));
    $resp     = json_decode($response, true);

    if (is_array($resp) && isset($resp['data'])) {
        set_transient($cache_key, $resp['data'], MINUTE_IN_SECONDS);
        wp_send_json_success($resp['data']);
    } else {
        wp_send_json_error(array('message' => 'Failed to retrieve prepare amount data from Backend.'));
    }
}

?>
