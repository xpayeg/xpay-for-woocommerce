<?php

defined( 'ABSPATH' ) or exit;

// error_log() calls in this file are intentional always-on diagnostics for
// outbound XPay HTTP failures and circuit-breaker state changes. They run
// independently of the opt-in diagnostic logger so a merchant can grep
// their PHP error log for `[xpay]` and find issues even with the logger
// disabled. Suppress PCP's blanket warning at file scope.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log

/**
 * Timeout for outbound XPay HTTP calls. Staging has been observed taking
 * up to 15s on the pay/variable-amount endpoint, so we leave 25s of
 * headroom. The pay call uses max_retries=0 to bound double-charge risk.
 *
 * Prepare-amount is a lightweight call (compute fees, validate community)
 * with a much tighter latency budget — give it its own shorter timeout
 * and call it with max_retries=0 from process_payment so the worst-case
 * checkout request budget stays under typical PHP-FPM request_terminate
 * windows (30s) and one slow upstream cannot saturate the worker pool.
 */
const XPAY_HTTP_TIMEOUT     = 25;
const XPAY_PREPARE_TIMEOUT  = 20;

/**
 * Circuit breaker state. After XPAY_CB_FAILURE_THRESHOLD consecutive
 * outbound failures, xpay_http_request fails fast for XPAY_CB_OPEN_SECONDS
 * before allowing another upstream call. This prevents a sustained XPay
 * outage from saturating PHP-FPM workers — without it, every checkout
 * waits the full HTTP timeout before failing. State is stored in
 * transients so it is shared across PHP-FPM workers.
 */
const XPAY_CB_FAILURE_THRESHOLD = 5;
const XPAY_CB_OPEN_SECONDS      = 60;
const XPAY_CB_FAILURE_WINDOW    = 300;

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

/**
 * Single source of truth for promo-code UI labels. Read by the classic
 * checkout template (promo_code_section.php) and serialized into the
 * block-checkout payment data so the React component renders the same
 * copy. Keeping the strings here means a translator only updates them
 * once.
 */
function xpay_promo_strings() {
    return array(
        'show'        => __( 'Have Xpay Promo Code?', 'xpay-for-woocommerce' ),
        'hide'        => __( 'Hide Promo Code', 'xpay-for-woocommerce' ),
        'placeholder' => __( 'Enter promo code', 'xpay-for-woocommerce' ),
        'apply'       => __( 'Apply', 'xpay-for-woocommerce' ),
        'applying'    => __( 'Validating...', 'xpay-for-woocommerce' ),
        'applied'     => __( 'Promocode applied successfully', 'xpay-for-woocommerce' ),
        'invalid'     => __( 'Invalid promo code', 'xpay-for-woocommerce' ),
        'empty'       => __( 'Please enter a promo code', 'xpay-for-woocommerce' ),
        'phone_first' => __( 'Enter your billing phone number first.', 'xpay-for-woocommerce' ),
    );
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
 * After an xpay_http_* call, returns a label classifying any failure:
 *   'pre_flight' — request definitely never reached XPay (breaker open,
 *                  DNS resolution failed, connect refused, TLS handshake
 *                  failure). Safe to retry without double-charge risk.
 *   'ambiguous'  — request may or may not have been processed (read
 *                  timeout, TLS error mid-stream, server returned 5xx,
 *                  Cloudflare HTML challenge). Treat as potentially
 *                  charged.
 *   null         — no failure (last call succeeded), or no call yet.
 *
 * Pass an argument to set; call without arguments to read.
 */
function xpay_http_last_failure_type($set_to = '__noarg__') {
    static $value = null;
    if ($set_to !== '__noarg__') {
        $value = $set_to;
    }
    return $value;
}

/**
 * Classify a WP_Error from xpay_http_request as 'pre_flight' or
 * 'ambiguous'. Uses the WP error code first, then parses cURL error
 * numbers from the error message when present.
 *
 * Pre-flight cURL codes (definitely no bytes sent to XPay):
 *   6  CURLE_COULDNT_RESOLVE_HOST
 *   7  CURLE_COULDNT_CONNECT
 *   35 CURLE_SSL_CONNECT_ERROR
 *   51 CURLE_PEER_FAILED_VERIFICATION
 *   60 CURLE_SSL_CACERT
 *
 * Ambiguous cURL codes (request bytes may have reached XPay):
 *   28 CURLE_OPERATION_TIMEDOUT (could be DNS timeout but conservatively ambiguous)
 *   55 CURLE_SEND_ERROR
 *   56 CURLE_RECV_ERROR
 */
function xpay_classify_wp_error($wp_error) {
    if (!is_wp_error($wp_error)) {
        return 'ambiguous';
    }
    if ($wp_error->get_error_code() === 'xpay_circuit_open') {
        return 'pre_flight';
    }
    $msg = $wp_error->get_error_message();
    if (preg_match('/cURL error (\d+)/', $msg, $m)) {
        $cerr = (int) $m[1];
        if (in_array($cerr, array(6, 7, 35, 51, 60), true)) {
            return 'pre_flight';
        }
        // 28, 55, 56 and unknowns: keep ambiguous (safer default)
    }
    return 'ambiguous';
}

/**
 * Returns true while the circuit breaker is open (recent sustained
 * failures observed). Callers should fail fast instead of issuing a new
 * outbound request — this is what stops PHP-FPM saturation when XPay is
 * degraded.
 */
function xpay_circuit_breaker_is_open() {
    return (bool) get_transient('xpay_cb_open');
}

/**
 * Record an outbound failure. After XPAY_CB_FAILURE_THRESHOLD consecutive
 * failures within XPAY_CB_FAILURE_WINDOW seconds, open the breaker for
 * XPAY_CB_OPEN_SECONDS. Any success resets the counter.
 */
function xpay_circuit_breaker_record_failure() {
    $count = (int) get_transient('xpay_cb_failures');
    $count++;
    if ($count >= XPAY_CB_FAILURE_THRESHOLD) {
        set_transient('xpay_cb_open', 1, XPAY_CB_OPEN_SECONDS);
        delete_transient('xpay_cb_failures');
        error_log('[xpay] circuit breaker opened — ' . XPAY_CB_FAILURE_THRESHOLD . ' consecutive failures');
        return;
    }
    set_transient('xpay_cb_failures', $count, XPAY_CB_FAILURE_WINDOW);
}

function xpay_circuit_breaker_record_success() {
    delete_transient('xpay_cb_failures');
}

/**
 * Calls wp_remote_post or wp_remote_get with up to $max_retries additional
 * attempts (1s backoff between) when the first attempt looks like a failure.
 * Returns the last response. When the circuit breaker is open, returns a
 * WP_Error immediately without making the upstream call.
 */
function xpay_http_request($url, $args, $method, $max_retries = 1) {
    // Reset the side-channel at the top of every request so stale values
    // from a previous call in the same PHP process never bleed through.
    xpay_http_last_failure_type(null);

    if (xpay_circuit_breaker_is_open()) {
        xpay_http_last_failure_type('pre_flight');
        return new WP_Error(
            'xpay_circuit_open',
            __('XPay temporarily unavailable (circuit breaker open).', 'xpay-for-woocommerce')
        );
    }
    $callable = ($method === 'POST') ? 'wp_remote_post' : 'wp_remote_get';
    $response = $callable($url, $args);
    $attempts = 0;
    while (xpay_http_response_is_failure($response) && $attempts < $max_retries) {
        usleep(1000000);
        $response = $callable($url, $args);
        $attempts++;
    }
    if (xpay_http_response_is_failure($response)) {
        xpay_circuit_breaker_record_failure();
        if (is_wp_error($response)) {
            xpay_http_last_failure_type(xpay_classify_wp_error($response));
        } else {
            // HTTP failure code (5xx/403/429) or non-JSON body: request
            // reached a server, so we cannot rule out a charge.
            xpay_http_last_failure_type('ambiguous');
        }
    } else {
        xpay_circuit_breaker_record_success();
        // Success: side-channel stays null (already reset at the top).
    }
    return $response;
}

function xpay_http_post($url, $data, $api_key, $debug = 'no', $max_retries = 1, $timeout = null) {
    $args = array(
        'headers'    => xpay_http_headers($api_key),
        'user-agent' => xpay_http_user_agent(),
        'body'       => $data,
        'timeout'    => null !== $timeout ? (int) $timeout : XPAY_HTTP_TIMEOUT,
    );
    $response = xpay_http_request($url, $args, 'POST', $max_retries);
    if (is_wp_error($response)) {
        if ($debug === 'yes') {
            error_log('[xpay] xpay_http_post error: ' . $response->get_error_message());
        }
        return null;
    }
    $body = wp_remote_retrieve_body($response);
    if ($debug === 'yes') {
        error_log('[xpay] xpay_http_post http=' . wp_remote_retrieve_response_code($response) . ' body=' . substr($body, 0, 400));
    }
    return $body;
}

function xpay_http_get($url, $api_key, $debug = 'no', $max_retries = 1) {
    $args = array(
        'headers'    => xpay_http_headers($api_key),
        'user-agent' => xpay_http_user_agent(),
        'timeout'    => XPAY_HTTP_TIMEOUT,
    );
    $response = xpay_http_request($url, $args, 'GET', $max_retries);
    if (is_wp_error($response)) {
        if ($debug === 'yes') {
            error_log('[xpay] xpay_http_get error: ' . $response->get_error_message());
        }
        return null;
    }
    $body = wp_remote_retrieve_body($response);
    if ($debug === 'yes') {
        error_log('[xpay] xpay_http_get http=' . wp_remote_retrieve_response_code($response) . ' body=' . substr($body, 0, 400));
    }
    return $body;
}

/**
 * Walks an XPay error payload and returns a flat array of human-readable
 * "Field: message" strings suitable for wc_add_notice. Returns an empty
 * array when no structured errors are present.
 *
 * Handles XPay's nested error shape:
 *   {"status":{"code":400,"message":"...","errors":[{"billing_data":{"phone_number":["This field may not be blank."]}}]}}
 *
 * @param mixed $resp Decoded JSON response (array) or null.
 * @return array<int, string>
 */
function xpay_extract_user_errors($resp) {
    if (!is_array($resp) || empty($resp['status']['errors']) || !is_array($resp['status']['errors'])) {
        return array();
    }
    $out = array();
    foreach ($resp['status']['errors'] as $err) {
        if (!is_array($err)) {
            if (is_string($err) && '' !== trim($err)) {
                $out[] = $err;
            }
            continue;
        }
        foreach ($err as $section_key => $section_value) {
            // section_value can be either a list of messages, or another nested
            // associative array of field => list of messages.
            if (is_array($section_value)) {
                foreach ($section_value as $field_key => $field_value) {
                    if (is_array($field_value)) {
                        foreach ($field_value as $msg) {
                            if (is_string($msg) && '' !== $msg) {
                                $out[] = ucfirst(str_replace('_', ' ', (string) $field_key)) . ': ' . $msg;
                            }
                        }
                    } elseif (is_string($field_value) && '' !== $field_value) {
                        $out[] = ucfirst(str_replace('_', ' ', (string) $field_key)) . ': ' . $field_value;
                    }
                }
            } elseif (is_string($section_value) && '' !== $section_value) {
                $out[] = ucfirst(str_replace('_', ' ', (string) $section_key)) . ': ' . $section_value;
            }
        }
    }
    return array_values(array_unique($out));
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

add_action('wp_ajax_xpay_fetch_installment_plans', 'xpay_fetch_installment_plans');
add_action('wp_ajax_nopriv_xpay_fetch_installment_plans', 'xpay_fetch_installment_plans');

/**
 * AJAX: fetch installment plans for a given amount.
 *
 * api_key, community_id, base URL, and variable_amount_id are read from
 * gateway settings server-side. The previous version accepted these from
 * $_POST, which leaked the api_key into the page source AND let any caller
 * use this endpoint as an open relay to XPay. Nonce is required.
 */
function xpay_fetch_installment_plans() {
    check_ajax_referer('xpay-installments', 'nonce');

    $amount = isset($_POST['amount']) ? floatval(wp_unslash($_POST['amount'])) : 0;
    if ($amount <= 0) {
        wp_send_json_error(null);
    }

    $settings     = get_option('woocommerce_xpay_gateway_settings', array());
    $api_key      = isset($settings['payment_api_key'])    ? $settings['payment_api_key']    : '';
    $community_id = isset($settings['community_id'])       ? $settings['community_id']       : '';
    $base_url     = isset($settings['iframe_base_url'])    ? rtrim($settings['iframe_base_url'], '/') : '';
    $variable_id  = isset($settings['variable_amount_id']) ? $settings['variable_amount_id'] : '';
    $debug        = isset($settings['debug'])              ? $settings['debug']              : 'no';

    if (!$api_key || !$community_id || !$base_url) {
        wp_send_json_error(null);
    }

    $url     = $base_url . '/api/v1/payments/prepare-amount/';
    $payload = wp_json_encode(array(
        'community_id'            => $community_id,
        'amount'                  => $amount,
        'selected_payment_method' => 'installment',
        'variable_amount_id'      => $variable_id,
    ));

    $resp = xpay_http_post($url, $payload, $api_key, $debug);
    if (!is_string($resp) || '' === $resp) {
        wp_send_json_error(null);
    }
    $decoded = json_decode($resp, true);
    if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
        wp_send_json_error(null);
    }
    wp_send_json_success($decoded);
}

?>
