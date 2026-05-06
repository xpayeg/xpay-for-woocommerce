<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant, not ours; required to skip theme loading on this bare-bones poll endpoint.
define( 'WP_USE_THEMES', false ); // Don't load theme support functionality
require( '../../../wp-load.php' );

// Wrap the poll handler in an IIFE so all the variables it declares
// (uuid, order_id, resp, etc.) stay function-local and don't leak into
// PHP's global namespace — keeping this entry-point script PCP-clean for
// the PrefixAllGlobals.NonPrefixedVariableFound rule.
( function () {

// This endpoint is the modal's status-poll target — called by the
// customer's browser, not by an authenticated WP user, so it cannot
// carry a WP nonce. The IDOR guard at trim((string) $order->get_meta(...))
// !== trim($uuid) below is the actual security boundary. PCP can't model
// that, so it flags the bare $_REQUEST reads. Suppress for this file.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$uuid     = isset($_REQUEST['trn_uuid']) ? sanitize_text_field(wp_unslash($_REQUEST['trn_uuid'])) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$order_id = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id'])                          : 0;

$check_started = microtime(true);

if (!$uuid || !$order_id) {
    do_action('xpay_logger_event', 'check_transaction', array(
        'order_id' => $order_id,
        'has_uuid' => (bool) $uuid,
        'result'   => 'INVALID',
        'reason'   => 'missing_params',
    ), 'check_transaction: invalid params');
    echo 'INVALID';
    exit;
}

$order = wc_get_order($order_id);
if (!$order) {
    do_action('xpay_logger_event', 'check_transaction', array(
        'order_id' => $order_id,
        'result'   => 'INVALID',
        'reason'   => 'order_not_found',
    ), 'check_transaction: order missing');
    echo 'INVALID';
    exit;
}

// IDOR guard. Without this, any guest who knows ANY order id + ANY xpay
// transaction uuid in SUCCESSFUL state could fire payment_complete on
// the wrong order. Verify the uuid actually belongs to this order.
if (trim((string) $order->get_meta('xpay_transaction_id')) !== trim($uuid)) {
    do_action('xpay_logger_event', 'check_transaction', array(
        'order_id' => $order->get_id(),
        'result'   => 'INVALID',
        'reason'   => 'uuid_mismatch',
    ), 'check_transaction: idor guard hit');
    echo 'INVALID';
    exit;
}

// Skip the upstream call entirely if the order is already paid; the
// modal-close ping is informational at this point.
if ($order->has_status(array('processing', 'completed'))) {
    do_action('xpay_logger_event', 'check_transaction', array(
        'order_id'        => $order->get_id(),
        'result'          => 'SUCCESSFUL',
        'reason'          => 'already_paid_short_circuit',
        'order_status_in' => $order->get_status(),
    ), 'check_transaction: short-circuit success');
    echo 'SUCCESSFUL';
    exit;
}

// Refuse refunded/trashed orders. Cancelled is allowed: matches
// update_order.php — WC may auto-cancel pending orders; XPay success
// should still complete payment.
if ($order->has_status(array('refunded', 'trash'))) {
    do_action('xpay_logger_event', 'check_transaction', array(
        'order_id'        => $order->get_id(),
        'result'          => 'INVALID',
        'reason'          => 'closed_status',
        'order_status_in' => $order->get_status(),
    ), 'check_transaction: refused for closed order');
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
$resp = xpay_http_get($url, $wc_settings->get_option('payment_api_key'), 'no', 0);
$resp = json_decode($resp, true);

$status_returned = isset($resp['data']['status']) ? trim((string) $resp['data']['status']) : '';

if (is_array($resp) && 'SUCCESSFUL' === $status_returned) {
    // Same lock used in update_order.php — when the webhook arrives at
    // the same moment as this modal-close check, only one of them should
    // call payment_complete to avoid double-firing woocommerce_payment_complete.
    $lock_key = 'xpay_lock_' . $uuid;
    if (false !== wp_cache_add($lock_key, 1, '', 30)) {
        $order->payment_complete($uuid);
        $order->add_order_note(sprintf(
            /* translators: %s = XPay transaction UUID */
            __('XPay payment confirmed via modal-close check (txn %s).', 'xpay-for-woocommerce'),
            $uuid
        ));
    } else {
        do_action('xpay_logger_event', 'check_transaction', array(
            'order_id' => $order->get_id(),
            'branch'   => 'lock_held',
        ), 'check_transaction: skipped payment_complete (concurrent completion in progress)');
    }
}

do_action('xpay_logger_event', 'check_transaction', array(
    'order_id'         => $order->get_id(),
    'result'           => '' !== $status_returned ? $status_returned : 'EMPTY',
    'order_status_out' => $order->get_status(),
    'duration_ms'      => (int) ((microtime(true) - $check_started) * 1000),
), 'check_transaction: upstream queried');

echo isset($resp['data']['status']) ? esc_html(trim($resp['data']['status'])) : '';

} )(); // end IIFE

