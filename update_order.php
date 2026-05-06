<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant, not ours; required to skip theme loading on this bare-bones webhook entry point.
define( 'WP_USE_THEMES', false );
require( '../../../wp-load.php' );

// Wrap the request handler in an IIFE so all the variables it declares
// (transaction_id, signature_state, etc.) stay function-local and don't
// leak into PHP's global namespace — keeping this entry-point script
// PCP-clean for the PrefixAllGlobals.NonPrefixedVariableFound rule.
//
// error_log() calls below are intentional always-on diagnostic emissions
// for webhook signature outcomes (separate from the bundled diagnostic
// logger which is opt-in). Suppress PCP's blanket warning on this file.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
( function () {

header('Content-Type: application/json');

// Get and decode the JSON input
$inputJSON = file_get_contents('php://input');
$data = json_decode($inputJSON, true);

// Resolve the body's secret_key value. Real XPay webhooks have observed
// emitting the merchant secret at top level (`secret_key`) — but XPay's
// public examples wrap the same fields inside an `extra_details` object,
// so we accept either location for forward/backward compatibility.
$body_secret = '';
if (is_array($data)) {
    if (isset($data['secret_key'])) {
        $body_secret = trim((string) $data['secret_key']);
    } elseif (isset($data['extra_details']['secret_key'])) {
        $body_secret = trim((string) $data['extra_details']['secret_key']);
    }
}

// Logger: webhook.received. Headers and IP only — payload-level fields are
// emitted in webhook.lookup once we know which order it relates to. Body
// shape (key list, no values) is logged so unexpected payload structures
// surface in the log without leaking secret values.
$body_top_keys      = is_array($data) ? array_keys($data) : array();
$extra_details_keys = (is_array($data) && isset($data['extra_details']) && is_array($data['extra_details']))
    ? array_keys($data['extra_details'])
    : array();
do_action('xpay_logger_event', 'webhook.received', array(
    'remote_ip'        => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null,
    'forwarded_for'    => isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])) : null,
    'cf_ray'           => isset($_SERVER['HTTP_CF_RAY']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_RAY'])) : null,
    'content_length'   => isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0,
    'has_body_secret'  => '' !== $body_secret,
    'body_top_keys'    => $body_top_keys,
    'extra_details_keys' => $extra_details_keys,
    'json_parsed'      => is_array($data),
    'transaction_id'   => is_array($data) && isset($data['transaction_id']) ? trim((string) $data['transaction_id']) : null,
    'transaction_status' => is_array($data) && isset($data['transaction_status']) ? (string) $data['transaction_status'] : null,
), 'webhook received');

// ---------------------------------------------------------------------
// Optional webhook verification.
//
// XPay does not sign webhooks via a header. Instead, when the merchant
// configures a secret in their XPay community settings, XPay echoes
// that same value back inside the webhook JSON body under a
// `secret_key` field (top-level on observed production webhooks; XPay's
// public examples sometimes show it nested under `extra_details` —
// $body_secret resolution above accepts either). We verify by
// constant-time comparing that body field against the gateway's
// webhook_secret setting.
//
// Mode 1 — Legacy / unverified (default): leave 'webhook_secret' empty
// in the gateway settings. Webhooks are accepted without checks. Used
// by merchants who have not configured a secret in their XPay community,
// or who installed the plugin before secret support existed.
//
// Mode 2 — Verified: configure 'webhook_secret' in BOTH the gateway
// settings AND the matching field in your XPay community. Every webhook
// MUST then carry that same value as `secret_key` in the body. Missing
// or mismatched secrets are rejected with HTTP 401 — there is
// intentionally no silent fallback to unverified once a secret is
// configured, because that would defeat the merchant's choice and
// create a downgrade attack surface.
//
// Constant-time comparison via hash_equals() is used even though the
// secret is plain-text: a naive === would leak the secret byte-by-byte
// to an attacker timing the response.
// ---------------------------------------------------------------------
$xpay_settings  = get_option('woocommerce_xpay_gateway_settings', array());
$webhook_secret = isset($xpay_settings['webhook_secret']) ? trim((string) $xpay_settings['webhook_secret']) : '';

$signature_state = 'no_secret_configured';
if ('' !== $webhook_secret) {
    if ('' === $body_secret) {
        // Secret configured locally but the body did not carry one. Reject
        // rather than silently fall through to unverified — a merchant
        // who set the secret expects strict verification.
        error_log('[xpay] webhook rejected: secret_key missing in body while local secret is configured');
        do_action('xpay_logger_event', 'webhook.applied', array(
            'branch'          => 'secret_missing_in_body',
            'signature_state' => 'secret_missing_in_body',
        ), 'webhook rejected: secret_key missing in body while secret configured');
        status_header(401);
        wp_send_json_error([
            'message' => 'Webhook secret required',
        ]);
        return;
    }
    if (!hash_equals($webhook_secret, $body_secret)) {
        error_log('[xpay] webhook rejected: secret_key mismatch');
        do_action('xpay_logger_event', 'webhook.applied', array(
            'branch'          => 'secret_mismatch',
            'signature_state' => 'secret_mismatch',
        ), 'webhook rejected: secret_key mismatch');
        status_header(401);
        wp_send_json_error([
            'message' => 'Invalid webhook secret',
        ]);
        return;
    }
    error_log('[xpay] webhook secret verified');
    $signature_state = 'verified';
} else {
    error_log('[xpay] webhook accepted unverified (no secret configured)');
    $signature_state = 'no_secret_configured';
}

$transaction_id = isset($data["transaction_id"]) ? trim((string) $data["transaction_id"]) : null;
$transaction_status = isset($data["transaction_status"]) ? (string) $data["transaction_status"] : null;

// Handle missing transaction_id. Return real 4xx so XPay's webhook layer
// can retry (wp_send_json_error returns 200 by default).
if (!$transaction_id) {
    do_action('xpay_logger_event', 'webhook.applied', array(
        'branch' => 'missing_transaction_id',
    ), 'webhook applied: 400 returned');
    status_header(400);
    wp_send_json_error([
        'message'          => 'Missing transaction_id in payload',
        'received_payload' => $data,
    ]);
    return;
}

if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $transaction_id)) {
    do_action('xpay_logger_event', 'webhook.applied', array(
        'branch' => 'invalid_transaction_id_format',
    ), 'webhook applied: 400 returned');
    status_header(400);
    wp_send_json_error(['message' => 'Invalid transaction_id format']);
    return;
}

// HPOS-compatible lookup. wc_get_orders routes to wp_postmeta or
// wc_orders_meta depending on whether High-Performance Order Storage
// is enabled on the site. A direct $wpdb->postmeta query would silently
// miss orders on HPOS-enabled stores (the default for new WC 8.3+).
//
// We don't restrict status at the query level — that would break stores
// with custom statuses from B2B / fraud-review / pre-order plugins. The
// safety check (do not resurrect refunded/trashed orders) is applied
// per-branch below, after lookup. Cancelled is allowed for SUCCESSFUL —
// stores often auto-cancel unpaid orders after a hold (e.g. ~24h); XPay
// remains source of truth once payment actually succeeds.
//
// PCP flags meta_key/meta_value as a slow-query risk; for a webhook handler
// that fires once per order completion this single lookup is acceptable
// and there is no faster HPOS-aware alternative through the public API.
$orders = wc_get_orders(array(
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
    'meta_key'   => 'xpay_transaction_id',
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
    'meta_value' => $transaction_id,
    'limit'      => 1,
    'orderby'    => 'date',
    'order'      => 'DESC',
    'return'     => 'objects',
));

if (empty($orders)) {
    do_action('xpay_logger_event', 'webhook.lookup', array(
        'transaction_id'  => $transaction_id,
        'order_id'        => null,
        'signature_state' => $signature_state,
    ), 'order not found for transaction id');
    do_action('xpay_logger_event', 'webhook.applied', array(
        'branch' => 'order_not_found',
    ), 'webhook applied: 404 returned');
    // Real 404 so XPay's webhook layer retries — useful when the webhook
    // fires before process_payment finished saving the meta.
    status_header(404);
    wp_send_json_error([
        'message'        => 'Transaction ID not found',
        'transaction_id' => $transaction_id,
    ]);
    return;
}

$order = $orders[0];

do_action('xpay_logger_event', 'webhook.lookup', array(
    'transaction_id'   => $transaction_id,
    'order_id'         => $order->get_id(),
    'order_status_in'  => $order->get_status(),
    'order_payment'    => $order->get_payment_method(),
    'signature_state'  => $signature_state,
), 'order resolved');

// Defensively verify the order's saved meta exactly matches the webhook
// payload. wc_get_orders' meta_value comparison is permissive on some
// HPOS releases (LIKE on the underlying column), so an exact-match
// safety check here protects against partial-string false positives.
if ($order->get_meta('xpay_transaction_id') !== $transaction_id) {
    do_action('xpay_logger_event', 'webhook.applied', array(
        'order_id' => $order->get_id(),
        'branch'   => 'meta_mismatch',
    ), 'order meta does not match webhook transaction id');
    status_header(409);
    wp_send_json_error([
        'message'        => 'Order meta does not match webhook transaction id',
        'transaction_id' => $transaction_id,
        'order_id'       => $order->get_id(),
    ]);
    return;
}

// Defensively verify this is actually an XPay order before completing it.
// Without this guard, any matching meta value on a non-XPay order would
// trigger payment_complete on the wrong gateway's lifecycle hooks.
if ('xpay_gateway' !== $order->get_payment_method()) {
    do_action('xpay_logger_event', 'webhook.applied', array(
        'order_id'       => $order->get_id(),
        'branch'         => 'wrong_gateway',
        'payment_method' => $order->get_payment_method(),
    ), 'order is not an xpay order');
    status_header(409);
    wp_send_json_error([
        'message'        => 'Order is not an XPay order',
        'transaction_id' => $transaction_id,
        'order_id'       => $order->get_id(),
        'payment_method' => $order->get_payment_method(),
    ]);
    return;
}

// Update order status based on transaction result
if ($transaction_status === "SUCCESSFUL") {
    // Idempotency guard. payment_complete is only mostly idempotent in WC
    // (it skips status update if already paid but still fires actions).
    // We hard-skip if the order is already in a paid state so duplicate
    // webhook + modal-close pings don't re-fire downstream hooks.
    if ($order->has_status(array('processing', 'completed'))) {
        do_action('xpay_logger_event', 'webhook.applied', array(
            'order_id'        => $order->get_id(),
            'branch'          => 'successful_already_paid',
            'order_status_in' => $order->get_status(),
        ), 'webhook applied: no-op (already paid)');
        wp_send_json_success([
            'message'  => 'Order already paid; no-op',
            'order_id' => $order->get_id(),
        ]);
        return;
    }
    // Refuse to resurrect terminal orders where payment must not be
    // re-opened (refund / trash). Cancelled is not blocked: WooCommerce
    // may auto-cancel pending orders after inactivity while XPay still
    // completes payment — webhook is source of truth for payment outcome.
    if ($order->has_status(array('refunded', 'trash'))) {
        do_action('xpay_logger_event', 'webhook.applied', array(
            'order_id'        => $order->get_id(),
            'branch'          => 'successful_closed_status',
            'order_status_in' => $order->get_status(),
        ), 'webhook applied: refused to resurrect closed order');
        status_header(409);
        wp_send_json_error([
            'message'      => 'Order is in a closed status; refusing to resurrect',
            'order_id'     => $order->get_id(),
            'order_status' => $order->get_status(),
        ]);
        return;
    }
    // Acquire a short-lived lock so the same transaction can't be
    // completed twice concurrently. The race is real even with the
    // has_status guard above: between this point and payment_complete's
    // status save, a second webhook (or check_transaction.php firing on
    // modal close) can pass the same has_status check on a still-pending
    // order and double-fire woocommerce_payment_complete (double stock
    // decrement, double new-order email, double affiliate commission).
    //
    // Effective on hosts with a persistent object cache (Redis/Memcached);
    // on default WP without one, wp_cache_add is per-request, so the lock
    // is best-effort across PHP-FPM workers. The has_status guard above
    // still provides partial protection in that case.
    $lock_key = 'xpay_lock_' . $transaction_id;
    if (false === wp_cache_add($lock_key, 1, '', 30)) {
        do_action('xpay_logger_event', 'webhook.applied', array(
            'order_id' => $order->get_id(),
            'branch'   => 'successful_lock_held',
        ), 'webhook applied: skipped (concurrent completion in progress)');
        wp_send_json_success([
            'message'  => 'Concurrent completion in progress; treating as idempotent',
            'order_id' => $order->get_id(),
        ]);
        return;
    }
    // payment_complete() handles stock reduction (if not already reduced),
    // status routing per product type, the canonical _transaction_id meta,
    // and triggers the woocommerce_payment_complete action.
    $order->payment_complete($transaction_id);
    $order->add_order_note(sprintf(
        /* translators: %s = XPay transaction UUID */
        __('XPay payment confirmed (txn %s).', 'xpay-for-woocommerce'),
        $transaction_id
    ));
    do_action('xpay_logger_event', 'webhook.applied', array(
        'order_id'         => $order->get_id(),
        'branch'           => 'successful',
        'order_status_out' => $order->get_status(),
    ), 'webhook applied: payment_complete');
    wp_send_json_success([
        'message'  => 'Order updated via payment_complete',
        'order_id' => $order->get_id(),
    ]);
    return;
} elseif ($transaction_status === "FAILED") {
    // If the order is already paid (e.g. a SUCCESSFUL webhook arrived
    // first and a stale FAILED arrives later), do NOT increase stock
    // back — that would oversell paid items.
    if ($order->has_status(array('processing', 'completed'))) {
        do_action('xpay_logger_event', 'webhook.applied', array(
            'order_id'        => $order->get_id(),
            'branch'          => 'failed_already_paid',
            'order_status_in' => $order->get_status(),
        ), 'webhook applied: ignoring stale FAILED on paid order');
        status_header(409);
        wp_send_json_error([
            'message'  => 'Order already paid; ignoring FAILED webhook',
            'order_id' => $order->get_id(),
        ]);
        return;
    }
    // Don't overwrite refunded/trashed orders. Cancelled is allowed — WC
    // may auto-cancel pending orders; XPay FAILED remains source of truth.
    if ($order->has_status(array('refunded', 'trash'))) {
        do_action('xpay_logger_event', 'webhook.applied', array(
            'order_id'        => $order->get_id(),
            'branch'          => 'failed_closed_status',
            'order_status_in' => $order->get_status(),
        ), 'webhook applied: refused to overwrite closed order');
        status_header(409);
        wp_send_json_error([
            'message'      => 'Order is in a closed status; refusing to overwrite',
            'order_id'     => $order->get_id(),
            'order_status' => $order->get_status(),
        ]);
        return;
    }
    // Restore stock only when stock was actually reduced; the helper
    // checks the per-item _reduced_stock meta and is a no-op otherwise.
    wc_increase_stock_levels($order->get_id());
    $order->update_status('failed', __('Transaction failed', 'xpay-for-woocommerce'));
    do_action('xpay_logger_event', 'webhook.applied', array(
        'order_id'         => $order->get_id(),
        'branch'           => 'failed',
        'order_status_out' => $order->get_status(),
    ), 'webhook applied: marked failed; stock restored');
    wp_send_json_success([
        'message'  => 'Order updated to failed',
        'order_id' => $order->get_id(),
    ]);
    return;
} else {
    // Unknown status — return a real 4xx so XPay's webhook layer retries
    // (wp_send_json_error returns 200 by default, which most webhook
    // dispatchers treat as success and will not retry).
    do_action('xpay_logger_event', 'webhook.applied', array(
        'order_id'           => $order->get_id(),
        'branch'             => 'unknown_status',
        'transaction_status' => $transaction_status,
    ), 'webhook applied: unknown transaction_status');
    status_header(400);
    wp_send_json_error([
        'message'            => 'Unknown transaction status',
        'transaction_status' => $transaction_status,
        'transaction_id'     => $transaction_id,
        'order_id'           => $order->get_id(),
    ]);
    return;
}

} )(); // end IIFE
