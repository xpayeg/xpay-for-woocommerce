<?php
define( 'WP_USE_THEMES', false );
require( '../../../wp-load.php' );

header('Content-Type: application/json');

// Get and decode the JSON input
$inputJSON = file_get_contents('php://input');
$data = json_decode($inputJSON, true);

// Logger: webhook.received. Headers and IP only — payload-level fields are
// emitted in webhook.lookup once we know which order it relates to.
do_action('xpay_logger_event', 'webhook.received', array(
    'remote_ip'        => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
    'forwarded_for'    => isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : null,
    'cf_ray'           => isset($_SERVER['HTTP_CF_RAY']) ? $_SERVER['HTTP_CF_RAY'] : null,
    'content_length'   => isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0,
    'has_signature_hdr' => isset($_SERVER['HTTP_X_XPAY_SIGNATURE']),
    'json_parsed'      => is_array($data),
    'transaction_id'   => is_array($data) && isset($data['transaction_id']) ? trim((string) $data['transaction_id']) : null,
    'transaction_status' => is_array($data) && isset($data['transaction_status']) ? (string) $data['transaction_status'] : null,
), 'webhook received');

// ---------------------------------------------------------------------
// Optional HMAC signature verification (fail-open during setup).
//
// Configure 'webhook_secret' in the gateway settings AND paste the same
// value into XPay staging's "secret" field. Once BOTH are set, every
// webhook MUST carry a valid X-XPay-Signature header — invalid signatures
// are rejected with 401.
//
// If either side is missing (no secret configured OR no signature header
// arrived) we accept the request and log it. This lets testing proceed
// before XPay is configured to sign, and it tolerates a transient gap if
// the merchant rotates the secret.
//
// Header name and format below are guesses based on common conventions
// (Stripe / GitHub / Shopify all use HMAC-SHA256 of the raw body in some
// header). When XPay's actual scheme is observed in real traffic, update
// the three constants at the top of this block.
// ---------------------------------------------------------------------
$xpay_sig_header = 'HTTP_X_XPAY_SIGNATURE'; // PHP server-var form of X-XPay-Signature
$xpay_sig_algo   = 'sha256';
$xpay_sig_format = 'hex'; // 'hex' or 'base64'

$xpay_settings   = get_option('woocommerce_xpay_gateway_settings', array());
$webhook_secret  = isset($xpay_settings['webhook_secret']) ? trim((string) $xpay_settings['webhook_secret']) : '';
$received_sig    = isset($_SERVER[$xpay_sig_header]) ? trim((string) $_SERVER[$xpay_sig_header]) : '';

$signature_state = 'unsigned';
if ('' !== $webhook_secret && '' !== $received_sig) {
    $hmac     = hash_hmac($xpay_sig_algo, $inputJSON, $webhook_secret, 'base64' === $xpay_sig_format);
    $expected = ('base64' === $xpay_sig_format) ? base64_encode($hmac) : $hmac;
    if (!hash_equals($expected, $received_sig)) {
        error_log('[xpay] webhook rejected: HMAC signature mismatch');
        do_action('xpay_logger_event', 'webhook.applied', array(
            'branch'          => 'signature_mismatch',
            'signature_state' => 'mismatch',
        ), 'webhook rejected: HMAC mismatch');
        status_header(401);
        wp_send_json_error([
            'message' => 'Invalid webhook signature',
        ]);
    }
    error_log('[xpay] webhook signature verified');
    $signature_state = 'verified';
} else {
    error_log(sprintf(
        '[xpay] webhook accepted unsigned (secret_configured=%d, signature_present=%d) — set both to enable strict verification',
        '' !== $webhook_secret ? 1 : 0,
        '' !== $received_sig ? 1 : 0
    ));
    $signature_state = ('' === $webhook_secret) ? 'no_secret_configured' : 'no_header_present';
}

$transaction_id = isset($data["transaction_id"]) ? trim($data["transaction_id"]) : null;
$transaction_status = isset($data["transaction_status"]) ? $data["transaction_status"] : null;

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
}

// HPOS-compatible lookup. wc_get_orders routes to wp_postmeta or
// wc_orders_meta depending on whether High-Performance Order Storage
// is enabled on the site. A direct $wpdb->postmeta query would silently
// miss orders on HPOS-enabled stores (the default for new WC 8.3+).
//
// We don't restrict status at the query level — that would break stores
// with custom statuses from B2B / fraud-review / pre-order plugins. The
// safety check (do not resurrect cancelled/refunded orders) is applied
// per-branch below, after lookup.
$orders = wc_get_orders(array(
    'meta_key'   => 'xpay_transaction_id',
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
    }
    // Refuse to resurrect explicitly closed orders. Without this, a
    // customer who pays in the iframe after the merchant cancels the
    // order would silently flip the status back to processing.
    if ($order->has_status(array('cancelled', 'refunded', 'trash'))) {
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
    }
    // payment_complete() handles stock reduction (if not already reduced),
    // status routing per product type, the canonical _transaction_id meta,
    // and triggers the woocommerce_payment_complete action.
    $order->payment_complete($transaction_id);
    $order->add_order_note(sprintf(__('XPay payment confirmed (txn %s).', 'wc-gateway-xpay'), $transaction_id));
    do_action('xpay_logger_event', 'webhook.applied', array(
        'order_id'         => $order->get_id(),
        'branch'           => 'successful',
        'order_status_out' => $order->get_status(),
    ), 'webhook applied: payment_complete');
    wp_send_json_success([
        'message'  => 'Order updated via payment_complete',
        'order_id' => $order->get_id(),
    ]);
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
    }
    // Don't overwrite a merchant's deliberate cancellation/refund with a
    // 'failed' status when a stale FAILED webhook arrives. Mirrors the
    // closed-status guard in the SUCCESSFUL branch above.
    if ($order->has_status(array('cancelled', 'refunded', 'trash'))) {
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
    }
    // Restore stock only when stock was actually reduced; the helper
    // checks the per-item _reduced_stock meta and is a no-op otherwise.
    wc_increase_stock_levels($order->get_id());
    $order->update_status('failed', __('Transaction failed', 'wc-gateway-xpay'));
    do_action('xpay_logger_event', 'webhook.applied', array(
        'order_id'         => $order->get_id(),
        'branch'           => 'failed',
        'order_status_out' => $order->get_status(),
    ), 'webhook applied: marked failed; stock restored');
    wp_send_json_success([
        'message'  => 'Order updated to failed',
        'order_id' => $order->get_id(),
    ]);
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
}
