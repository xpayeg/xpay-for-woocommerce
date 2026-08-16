# Webhooks — how order state stays true

## Code map

| Concern | File |
|---|---|
| Signature verification (pure, unit-tested) | `includes/api/class-xpay-signature.php` |
| The receiver (HTTP statuses, dedupe, routing) | `includes/webhooks/class-xpay-webhook-controller.php` |
| Order transitions (the only writer) | `includes/gateway/class-xpay-order-sync.php` |
| Event-name registry | `includes/constants/class-xpay-event-names.php` |
| Signature unit tests | `tests/SignatureTest.php` |

## How it works

1. XPay POSTs events to `https://<store>/?wc-api=xpay_webhook` with an
   `XPay-Signature: t=<unix>,v1=<hmac-sha256-hex>` header. The HMAC is
   computed over `"<timestamp>.<raw body>"` with the endpoint's `whsec_…`
   secret.
2. `XPay_Signature::verify()` checks the header constant-time
   (`hash_equals`) with a 300-second replay window. XPay does not enforce
   the window sender-side; the receiver must (their docs say so).
3. The order is located via `metadata.wc_order_id`, then the **ownership
   check** runs: the event's session id must equal the session id this
   plugin stored on that order. Existence is never enough.
4. `checkout.session.completed` → `payment_complete()` (idempotent);
   `checkout.session.expired` → cancel a still-pending order. Event ids
   are remembered per order, so redeliveries are acknowledged without
   side effects.
5. The thank-you page independently re-fetches the session server-side
   (`XPay_Order_Sync::verify_on_thankyou`) so a shopper who outruns the
   webhook still sees the truth.

## HTTP status contract (drives XPay's retry engine)

| Response | Meaning | XPay behavior |
|---|---|---|
| 200 | Verified; applied or deliberately ignored | Done |
| 400 | Malformed payload | Retries (sender-side fault would be theirs) |
| 401 | Signature missing/invalid/stale | Retries; persistent 401 = wrong secret pasted |
| 500 | Plugin misconfigured (no secret yet) or internal fault | Retries up to ~3 days — finishing setup within that window recovers the events |

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Orders stay "pending" after successful payment | No webhook endpoint configured in the XPay dashboard, or wrong mode (test endpoint while store is live) |
| Log shows `webhook.rejected` with `webhook_signature_invalid` | Signing secret mismatch — re-paste the `whsec_…` for the matching mode |
| Log shows `webhook_timestamp_out_of_tolerance` | Server clock skew over 5 minutes — fix NTP |
| Log shows `webhook.ownership_mismatch` | Event's session doesn't match the order's stored session — investigate, this should never happen organically |
