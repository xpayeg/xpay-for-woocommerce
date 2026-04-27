<?php

/**
 * Timeout for outbound XPay HTTP calls. Kept low so process_payment can
 * make 2 sequential calls (prepare-amount then pay) and still stay under
 * the default PHP max_execution_time of 30s — even when the prepare call
 * retries once. The pay call is invoked with max_retries=0 to bound risk
 * of double-charge if PHP is killed mid-flow.
 */
const XPAY_HTTP_TIMEOUT = 8;

/**
 * Browser-like User-Agent. The default WP_HTTP UA ("WordPress/X.X.X; ...")
 * trips Cloudflare Bot Fight Mode on some XPay endpoints; we override it
 * for every outbound XPay call so the requests look like a real client.
 */
function xpay_http_user_agent() {
    return 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
}

/**
 * Maps XPay's upstream method codes to the internal keys used by
 * WC_Gateway_Xpay::process_payment()'s $payment_config array. The upstream
 * code 'MEEZA/DIGITAL' contains a slash that sanitize_key() strips, and
 * the internal key is 'wallets' rather than the lowercased upstream code,
 * so a generic strtolower/sanitize_key would silently break that method.
 */
function xpay_normalize_method_code($upstream_code) {
    $map = array(
        'CARD'          => 'card',
        'FAWRY'         => 'fawry',
        'APPLE'         => 'apple',
        'VALU'          => 'valu',
        'MEEZA/DIGITAL' => 'wallets',
        'Installment'   => 'installment',
    );
    if (isset($map[$upstream_code])) {
        return $map[$upstream_code];
    }
    // Fallback for unknown codes: lowercase + strip everything but a-z0-9_.
    return preg_replace('/[^a-z0-9_]/', '', strtolower((string) $upstream_code));
}

/**
 * The internal payment-method keys process_payment knows how to handle.
 * Used as a whitelist when reading the form-submitted method.
 */
function xpay_allowed_method_keys() {
    return array('card', 'kiosk', 'fawry', 'apple', 'valu', 'wallets', 'installment');
}

function xpay_http_headers($api_key) {
    return array(
        'Accept'          => 'application/json',
        'Accept-Language' => 'en-US,en;q=0.9',
        'Content-Type'    => 'application/json',
        'x-api-key'       => $api_key,
    );
}

/**
 * Treats a response as a failure if it is a WP_Error, an HTTP failure
 * (403/429/5xx), or a body that does not look like JSON (e.g. Cloudflare's
 * HTML challenge page).
 */
function xpay_http_response_is_failure($response) {
    if (is_wp_error($response)) {
        return true;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code === 403 || $code === 429 || $code >= 500) {
        return true;
    }
    $body  = wp_remote_retrieve_body($response);
    $first = substr(ltrim($body), 0, 1);
    if ($first !== '{' && $first !== '[') {
        return true;
    }
    return false;
}

/**
 * Calls wp_remote_post or wp_remote_get with up to $max_retries additional
 * attempts (1s backoff between) when the first attempt looks like a failure.
 * Returns the last response.
 */
function xpay_http_request($url, $args, $method, $max_retries = 1) {
    $callable = ($method === 'POST') ? 'wp_remote_post' : 'wp_remote_get';
    $response = $callable($url, $args);
    $attempts = 0;
    while (xpay_http_response_is_failure($response) && $attempts < $max_retries) {
        usleep(1000000);
        $response = $callable($url, $args);
        $attempts++;
    }
    return $response;
}

function httpPost($url, $data, $api_key, $debug = 'no', $max_retries = 1) {
    $args = array(
        'headers'    => xpay_http_headers($api_key),
        'user-agent' => xpay_http_user_agent(),
        'body'       => $data,
        'timeout'    => XPAY_HTTP_TIMEOUT,
    );
    $response = xpay_http_request($url, $args, 'POST', $max_retries);
    if (is_wp_error($response)) {
        if ($debug === 'yes') {
            error_log('[xpay] httpPost error: ' . $response->get_error_message());
        }
        return null;
    }
    $body = wp_remote_retrieve_body($response);
    if ($debug === 'yes') {
        error_log('[xpay] httpPost http=' . wp_remote_retrieve_response_code($response) . ' body=' . substr($body, 0, 400));
    }
    return $body;
}

function httpGet($url, $api_key, $debug = 'no', $max_retries = 1) {
    $args = array(
        'headers'    => xpay_http_headers($api_key),
        'user-agent' => xpay_http_user_agent(),
        'timeout'    => XPAY_HTTP_TIMEOUT,
    );
    $response = xpay_http_request($url, $args, 'GET', $max_retries);
    if (is_wp_error($response)) {
        if ($debug === 'yes') {
            error_log('[xpay] httpGet error: ' . $response->get_error_message());
        }
        return null;
    }
    $body = wp_remote_retrieve_body($response);
    if ($debug === 'yes') {
        error_log('[xpay] httpGet http=' . wp_remote_retrieve_response_code($response) . ' body=' . substr($body, 0, 400));
    }
    return $body;
}

/**
 * Fetches /api/communities/preferences/ with caching and a default-methods
 * fallback. Cache is keyed by every parameter that affects the response so
 * environment switches and api-key changes never serve stale data. Failures
 * are also cached for 60 seconds so a sustained WAF block doesn't make
 * every checkout render wait the full HTTP timeout.
 */
function xpay_get_community_preferences($base_url, $community_id, $api_key, $debug = 'no') {
    $cache_key = 'xpay_prefs_' . md5($base_url . '|' . $community_id . '|' . $api_key);
    $cached    = get_transient($cache_key);
    if (is_array($cached) && isset($cached['payment_methods'])) {
        return $cached;
    }

    $url  = rtrim($base_url, '/') . '/api/communities/preferences/?community_id=' . rawurlencode($community_id);
    $args = array(
        'headers'    => xpay_http_headers($api_key),
        'user-agent' => xpay_http_user_agent(),
        'timeout'    => XPAY_HTTP_TIMEOUT,
    );
    $response = xpay_http_request($url, $args, 'GET', 1);

    if (!xpay_http_response_is_failure($response)) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (isset($data['data']['payment_methods'])) {
            $prefs = array(
                'payment_methods'       => (array) $data['data']['payment_methods'],
                'allow_promo_code'      => !empty($data['data']['allow_promo_code']),
                'supports_installments' => !empty($data['data']['supports_installments']),
            );
            set_transient($cache_key, $prefs, 5 * MINUTE_IN_SECONDS);
            return $prefs;
        }
    }

    if ($debug === 'yes') {
        error_log('[xpay] preferences fetch failed; using default methods');
    }

    $fallback = array(
        'payment_methods'       => array('CARD', 'FAWRY', 'VALU', 'MEEZA/DIGITAL'),
        'allow_promo_code'      => false,
        'supports_installments' => true,
    );
    // Cache the fallback briefly so subsequent renders don't all wait the
    // full HTTP timeout while the WAF is still blocking us.
    set_transient($cache_key, $fallback, MINUTE_IN_SECONDS);
    return $fallback;
}

function jsprint($output, $is_alert=true, $with_script_tags = true) {
    if (defined('DOING_AJAX') && DOING_AJAX) {
        error_log(print_r($output, true));
        return;
    }

    if($is_alert) {
        $js_code = 'alert(' . json_encode($output, JSON_HEX_TAG) . ');';
    } else {
        $js_code = 'console.log(' . json_encode($output, JSON_HEX_TAG) . ');';
    }
    if ($with_script_tags) {
        $js_code = '<script>' . $js_code . '</script>';
    }
    echo $js_code;
}


add_action('wp_ajax_fetch_installment_plans', 'fetch_installment_plans');
add_action('wp_ajax_nopriv_fetch_installment_plans', 'fetch_installment_plans');

/**
 * AJAX: fetch installment plans for a given amount.
 *
 * api_key, community_id, base URL, and variable_amount_id are read from
 * gateway settings server-side. The previous version accepted these from
 * $_POST, which leaked the api_key into the page source AND let any caller
 * use this endpoint as an open relay to XPay. Nonce is required.
 */
function fetch_installment_plans() {
    check_ajax_referer('xpay-installments', 'nonce');

    $amount = isset($_POST['amount']) ? floatval(wp_unslash($_POST['amount'])) : 0;
    if ($amount <= 0) {
        echo wp_json_encode(null);
        wp_die();
    }

    $settings     = get_option('woocommerce_xpay_gateway_settings', array());
    $api_key      = isset($settings['payment_api_key'])    ? $settings['payment_api_key']    : '';
    $community_id = isset($settings['community_id'])       ? $settings['community_id']       : '';
    $base_url     = isset($settings['iframe_base_url'])    ? rtrim($settings['iframe_base_url'], '/') : '';
    $variable_id  = isset($settings['variable_amount_id']) ? $settings['variable_amount_id'] : '';
    $debug        = isset($settings['debug'])              ? $settings['debug']              : 'no';

    if (!$api_key || !$community_id || !$base_url) {
        echo wp_json_encode(null);
        wp_die();
    }

    $url     = $base_url . '/api/v1/payments/prepare-amount/';
    $payload = wp_json_encode(array(
        'community_id'            => $community_id,
        'amount'                  => $amount,
        'selected_payment_method' => 'installment',
        'variable_amount_id'      => $variable_id,
    ));

    $resp = httpPost($url, $payload, $api_key, $debug);
    // The inline JS in payment_fields() does JSON.parse(JSON.parse(response)).
    // Anything the inner JSON.parse can't decode (null, empty, HTML
    // challenge page, malformed JSON) would throw — actually json_decode
    // the body here; if it doesn't decode to a value, collapse to null so
    // the JS lands on its existing failure-alert path.
    if (!is_string($resp) || '' === $resp) {
        $resp = null;
    } else {
        json_decode($resp);
        if (JSON_ERROR_NONE !== json_last_error()) {
            $resp = null;
        }
    }
    echo wp_json_encode($resp);
    wp_die();
}

?>
