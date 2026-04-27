<?php
define( 'WP_USE_THEMES', false );
require( '../../../wp-load.php' );

header('Content-Type: application/json');

// Get and decode the JSON input
$inputJSON = file_get_contents('php://input');
$data = json_decode($inputJSON, true);

$transaction_id = isset($data["transaction_id"]) ? trim($data["transaction_id"]) : null;
$transaction_status = isset($data["transaction_status"]) ? $data["transaction_status"] : null;

// Handle missing transaction_id. Return real 4xx so XPay's webhook layer
// can retry (wp_send_json_error returns 200 by default).
if (!$transaction_id) {
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
    // Real 404 so XPay's webhook layer retries — useful when the webhook
    // fires before process_payment finished saving the meta.
    status_header(404);
    wp_send_json_error([
        'message'        => 'Transaction ID not found',
        'transaction_id' => $transaction_id,
    ]);
}

$order = $orders[0];

// Defensively verify the order's saved meta exactly matches the webhook
// payload. wc_get_orders' meta_value comparison is permissive on some
// HPOS releases (LIKE on the underlying column), so an exact-match
// safety check here protects against partial-string false positives.
if ($order->get_meta('xpay_transaction_id') !== $transaction_id) {
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
        wp_send_json_success([
            'message'  => 'Order already paid; no-op',
            'order_id' => $order->get_id(),
        ]);
    }
    // Refuse to resurrect explicitly closed orders. Without this, a
    // customer who pays in the iframe after the merchant cancels the
    // order would silently flip the status back to processing.
    if ($order->has_status(array('cancelled', 'refunded', 'trash'))) {
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
    wp_send_json_success([
        'message'  => 'Order updated via payment_complete',
        'order_id' => $order->get_id(),
    ]);
} elseif ($transaction_status === "FAILED") {
    // If the order is already paid (e.g. a SUCCESSFUL webhook arrived
    // first and a stale FAILED arrives later), do NOT increase stock
    // back — that would oversell paid items.
    if ($order->has_status(array('processing', 'completed'))) {
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
    wp_send_json_success([
        'message'  => 'Order updated to failed',
        'order_id' => $order->get_id(),
    ]);
} else {
    // Unknown status — return a real 4xx so XPay's webhook layer retries
    // (wp_send_json_error returns 200 by default, which most webhook
    // dispatchers treat as success and will not retry).
    status_header(400);
    wp_send_json_error([
        'message'            => 'Unknown transaction status',
        'transaction_status' => $transaction_status,
        'transaction_id'     => $transaction_id,
        'order_id'           => $order->get_id(),
    ]);
}
