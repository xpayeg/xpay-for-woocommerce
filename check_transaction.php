<?php

define( 'WP_USE_THEMES', false ); // Don't load theme support functionality
require( '../../../wp-load.php' );



$uuid     = isset($_REQUEST['trn_uuid']) ? sanitize_text_field(wp_unslash($_REQUEST['trn_uuid'])) : '';
$order_id = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id'])                          : 0;

if (!$uuid || !$order_id) {
    echo 'INVALID';
    exit;
}

$order = wc_get_order($order_id);
if (!$order) {
    echo 'INVALID';
    exit;
}

// IDOR guard. Without this, any guest who knows ANY order id + ANY xpay
// transaction uuid in SUCCESSFUL state could fire payment_complete on
// the wrong order. Verify the uuid actually belongs to this order.
if (trim((string) $order->get_meta('xpay_transaction_id')) !== trim($uuid)) {
    echo 'INVALID';
    exit;
}

// Skip the upstream call entirely if the order is already paid; the
// modal-close ping is informational at this point.
if ($order->has_status(array('processing', 'completed'))) {
    echo 'SUCCESSFUL';
    exit;
}

// Refuse to resurrect orders the merchant has explicitly closed. Without
// this, a customer with the modal still open could pay in the iframe
// after the merchant cancels the order, and payment_complete would flip
// the status back to processing silently.
if ($order->has_status(array('cancelled', 'refunded', 'trash'))) {
    echo 'INVALID';
    exit;
}

// Read community_id from server settings — never from the request. A
// caller-supplied community_id can redirect this server-side check at
// an unrelated XPay community whose transaction UUIDs may collide.
$wc_settings  = new WC_Gateway_Xpay;
$community_id = $wc_settings->get_option('community_id');
$url          = rtrim($wc_settings->get_option('iframe_base_url'), '/')
              . '/api/v1/communities/' . rawurlencode($community_id)
              . '/transactions/' . rawurlencode($uuid) . '/';
// max_retries=0 — this is on the customer's interactive path and a
// retry-on-429 would just amplify any rate-limit response.
$resp = httpGet($url, $wc_settings->get_option('payment_api_key'), 'no', 0);
$resp = json_decode($resp, true);

if (is_array($resp) && isset($resp['data']['status']) && 'SUCCESSFUL' === $resp['data']['status']) {
    $order->payment_complete($uuid);
    $order->add_order_note(sprintf(__('XPay payment confirmed via modal-close check (txn %s).', 'wc-gateway-xpay'), $uuid));
}

echo isset($resp['data']['status']) ? esc_html(trim($resp['data']['status'])) : '';

