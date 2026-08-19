# Troubleshooting

Most issues with the XPay gateway are diagnosable in minutes once you know where the two diagnostic surfaces are:

1. **WooCommerce → XPay Log** (in your WP admin) — the plugin's own structured log viewer. Turn logging on first: WP Admin → **WooCommerce → Settings → Payments → XPay**, tick **Diagnostic logging** ("Write redacted diagnostic logs"), save. Entries are redacted at write time (keys, secrets, card-shaped values are masked), so the log is safe to share with support.
2. **The webhook delivery log in your XPay dashboard** — shows every webhook attempt XPay made against your store, with the HTTP status your site answered. For anything involving order status, check both sides.

This guide is symptom-first: find what you're seeing, follow the checks, apply the fix.

---

## The diagnostic log

### Where it lives

With **Diagnostic logging** on, every entry is written twice:

- **WooCommerce → XPay Log** — a filterable table (filter by **Order #**, **Request id**, or **Stage starts with**, e.g. `webhook.`), showing the most recent 100 entries. This is the screen this guide refers to.
- **WooCommerce → Status → Logs**, source **xpay** — the same entries as a raw stream, for developers.

Two more places surface the same data:

- **The order edit screen** — an **XPay** panel shows that order's XPay identifiers (session id `cs_…`, payment intent `pi_…`) and its recent log entries, so most per-order questions are answered without opening the log at all.
- **Copy debug report** — a button at the top of the XPay Log screen. One click copies a plain-text bundle (plugin/WP/WC/PHP versions, mode, webhook URL, redacted settings, last 50 entries) ready to paste into a support ticket.

Entries are kept for 14 days, capped at 10,000 rows, pruned daily. When you're done debugging, turn **Diagnostic logging** off — disabled logging has effectively zero runtime cost.

### Reading an entry

Each row carries a UTC timestamp, a 12-character **request id** (identical across every entry from the same HTTP request — filter by it to see one request's whole story), a **stage** name, the order id where applicable, and structured JSON context.

### Stage reference

These are the exact stage names the plugin writes:

| Stage | When it fires | What to look for |
|---|---|---|
| `session.created` | An XPay Checkout Session was minted for an order | `session_id`, `attempt`, `live_mode` |
| `session.superseded_expired` | An older session for the same order was expired after a new one replaced it | Normal during retries with a changed total |
| `session.expire_failed` | Expiring that older session failed (best-effort; the platform expires it on its own clock) | `code` |
| `session.method_pin_rejected` | The API refused a per-method row's restriction (method not enabled on your XPay account) | `types`; an admin notice appears too |
| `customer.linked` / `customer.stale_link_cleared` | A logged-in shopper was linked to a stored XPay customer id / a stale stored id was dropped | Informational |
| `process_payment.failed` | Session creation failed when the shopper clicked Place Order | `code` — the shopper saw "The payment could not be started." |
| `api.response` | Any XPay API call completed | `path`, status, timing |
| `api.transport_error` | An XPay API call got no HTTP response at all (timeout, DNS, TLS) | `code: transport_error` |
| `webhook.received` | A webhook passed signature verification | `event_id`, `event_type`, `livemode` |
| `webhook.rejected` | A webhook failed verification | `code` — see [Webhook rejections](#webhook-deliveries-fail-with-401) |
| `webhook.order_not_found` | A verified event referenced an order this store doesn't have | Foreign or deleted order; acknowledged and ignored |
| `webhook.ownership_mismatch` | A verified event's session doesn't match the session stored on the order it points at | Should never happen organically — see below |
| `webhook.apply_failed` | Applying a verified event threw; the receiver answered 500 so XPay retries | `code` / `error` |
| `order.paid` | The order was marked paid | `via`: `webhook` or `thankyou` |
| `order.amount_mismatch` | XPay charged an amount/currency different from the order total — order parked **on-hold** | See the order note for both numbers |
| `order.session_expired` | A session expired unpaid and the pending order was marked failed (still payable via its pay link) | — |
| `order_lock.unavailable` | MySQL `GET_LOCK` isn't available on this host; the plugin proceeded with its fallback guards | `db_error` — worth mentioning to your host |
| `thankyou.check_failed` | The thank-you page's server-side re-check couldn't reach the API (fails open; webhook remains the safety net) | `code` |
| `refund.submitted` / `refund.not_completed` | A refund succeeded / came back rejected or still in flight | `refund_id`, `status` |
| `compat.wpfunnels_url_restored` | The WPFunnels safeguard restored the standard order-received URL for an XPay order | Confirms the shim is working |

---

## Common symptoms

### XPay doesn't appear at checkout

The gateway hides itself rather than dead-end a shopper. In order of likelihood:

1. **WooCommerce inactive or plugin deactivated** — the plugin stays dormant without WooCommerce.
2. **Enable XPay unticked** — the shared switch at the top of the settings.
3. **No keys for the selected mode** — the gateway hides whenever the secret key or publishable key for the *selected mode* is empty. Saving in that state shows the admin error: *"XPay: no API key is saved for the selected mode, so XPay stays hidden at checkout until you add one."* Note that keys are per mode: switching Test → Live with only test keys saved hides every XPay row.
4. **Key pasted into the wrong mode** — caught at save time with a specific error (*"the key in the selected mode is a LIVE key but the gateway is in Test mode"*, or the reverse). Fix the pairing; a valid save confirms with *"XPay connected (test mode)"* / *"(live mode)"*.
5. **Looking for the combined "XPay" row while split mode is on** — with **Payment options → A separate option per payment method**, the single XPay row deliberately steps aside and the ticked method rows (Card / valU / Fawry) show instead. That's one gateway, not a conflict.

**Not a cause:** store currency. XPay shows at checkout regardless of currency; an unsupported currency fails later, at Place Order (next symptom).

### Customer sees "The payment could not be started."

Session creation against the XPay API failed.

**Check:** the order's notes (the plugin writes *"XPay session creation failed [code]: message"* on the order) and the `process_payment.failed` entry in the XPay Log.

Common codes:

| Code in the note/log | Meaning | Fix |
|---|---|---|
| `api_key_invalid` / `api_key_inactive` | The key is wrong, revoked, or inactive | Paste a fresh restricted key (`rk_test_…` / `rk_live_…`) with Checkout Sessions and Refunds access |
| `parameter_invalid` | The API refused something in the request — the order note carries XPay's own message saying what | If the message names the currency: set the store currency to one XPay supports (EGP recommended; settlement is in EGP) |
| `transport_error` | Your server couldn't reach `api.xpay.app` at all | Hosting firewall / outbound DNS / TLS issue — ask your host |

### The payment window doesn't open

On the pay page, the XPay window normally opens by itself over the receipt. If the XPay SDK (`sdk.js` from `checkout.xpay.app`) fails to load or start, the page **automatically continues to XPay's hosted checkout after ~6 seconds** — the shopper sees "Taking you to the secure payment page…" and can still pay. So this symptom is a degradation, not an outage; but find the cause:

1. **JS optimizer or delay-until-interaction feature** (Cloudflare Rocket Loader, WP Rocket delay/defer, LiteSpeed, Autoptimize, Perfmatters). The plugin stamps opt-out attributes on its two payment-critical scripts, but some configurations ignore them — see [COMPATIBILITY.md](COMPATIBILITY.md#js-optimizers-and-caching-plugins). Exclude the `xpay-checkout-modal` and `xpay-blocks` script handles (or `/checkout/order-pay/` URLs) from optimization.
2. **A browser extension or consent tool blocking third-party scripts** — the payment window is XPay-hosted, and tools that block third-party scripts can delay or block it; the hosted fallback covers the shopper either way. Reproduce in a private window to confirm.
3. **A strict Content-Security-Policy** on your site blocking the SDK script — check the browser console for CSP violations naming `checkout.xpay.app`.

**Check:** browser DevTools console on the pay page. The XPay Log has no browser-side events; the absence of any `webhook.received` / `order.paid` later tells you the shopper never completed payment.

### The page says "Your order is saved. Pay when you are ready."

Not an error. The shopper closed the XPay window without paying. The pay page switches to a calm paused state — the receipt shows an "Awaiting payment" stamp and a **Pay now** button that reopens the window. The order stays **pending** and remains payable (from account order history or an admin pay link). If the session later expires unpaid, the order is marked **failed** with the note *"XPay checkout session expired without payment. The order can still be paid through its payment link."* (`order.session_expired` in the log) — a failed order stays payable, and a shopper returning through the emailed pay link gets a fresh payment session automatically. If any attempts were declined during the session, the note also quotes how many and the last decline reason.

### Customer paid but the order is stuck in "pending"

The single most common report, and almost always a webhook problem. The webhook is the authoritative confirmation path; the order-received page also re-checks server-side, but a shopper who never lands there (closed the tab, or a funnel plugin rerouted them) leaves the webhook as the only path — see [GOING_LIVE.md](GOING_LIVE.md#funnel-builders-make-the-webhook-mandatory).

**Check, in this order:**

1. **XPay dashboard → the payment.** Is it actually paid there? If not, the shopper never completed payment — nothing is stuck.
2. **XPay dashboard → webhook delivery log.** What status did your store answer?
   - **No deliveries at all** → no webhook endpoint is configured for your store URL in this mode, or it points elsewhere. Create/point an endpoint at exactly `https://<your-store>/?wc-api=xpay_webhook`, subscribed to `checkout.session.completed`, `checkout.session.expired`, `payment_intent.payment_failed`, `charge.refunded` and `refund.failed`.
   - **404** → URL typo — see [next symptom](#webhook-deliveries-return-404).
   - **401** → signature failure — see [below](#webhook-deliveries-fail-with-401). The classic cause: the endpoint on the XPay dashboard is in one mode and the store is in the other, so the wrong `whsec_…` signs the events.
   - **500** → with `webhook_not_configured` in the XPay Log: the plugin has no signing secret saved for the current mode. Paste the endpoint's `whsec_…` into the matching **webhook signing secret** field.
   - **200** → the event was received; check the XPay Log for what happened next (`webhook.received` then `order.paid`, or `webhook.order_not_found` / `webhook.ownership_mismatch`).
3. **WooCommerce → XPay Log**, filtered to the order number.

**Good news about retries:** XPay redelivers failed webhooks for about 3 days ([WEBHOOKS.md](WEBHOOKS.md)). Fix the secret or URL within that window and the queued events arrive and complete the orders on their own — no manual status edits needed.

**Manual recovery**, only when the delivery window has passed: confirm the payment in the XPay dashboard, then set the order to Processing by hand.

### Webhook deliveries return 404

The receiver lives at the WordPress front controller — the URL must be exactly:

```text
https://<your-store>/?wc-api=xpay_webhook
```

A dropped `?`, a dash instead of the underscore (`xpay-webhook`), or a path-style guess (`/wc-api/xpay_webhook/` may work on some permalink setups, but the query form works on all) produces 404s in the delivery log. Copy the URL from the field description under the webhook-secret settings, character for character. The receiver itself answers only the statuses in [WEBHOOKS.md](WEBHOOKS.md#http-status-contract-drives-xpays-retry-engine) — a 404 means the request never reached it.

### Webhook deliveries fail with 401

Signature verification failed. The XPay Log shows `webhook.rejected` with one of:

| Code | Meaning | Fix |
|---|---|---|
| `webhook_signature_invalid` | The `whsec_…` in the plugin doesn't match the one signing these events | Re-paste the secret from the endpoint's page in the XPay dashboard. Secrets are **per endpoint and per mode** — a test-mode secret can never verify live events |
| `webhook_signature_missing` | POSTs arriving with no `XPay-Signature` header | Not from XPay — probes, or a proxy stripping headers |
| `webhook_timestamp_out_of_tolerance` | The signature is older/newer than the 300-second replay window | Your server's clock is more than 5 minutes off — fix NTP |

(`webhook_not_configured` is the 500 case: the plugin side has no secret saved yet.)

### `webhook.ownership_mismatch` in the log

A correctly signed event pointed at one of your orders, but its session id doesn't match the session this plugin stored on that order. The event is acknowledged (200) and **deliberately not applied** — this check is what stops a crafted or cross-wired event from marking an arbitrary order paid.

This should never happen organically. If you see it: check whether two stores (e.g. staging and production sharing a database copy) are receiving each other's events, and report it to XPay support with the `event_id` from the log entry. See also [WEBHOOKS.md](WEBHOOKS.md#troubleshooting).

### Order is on-hold with an "XPay charged … but this order totals …" note

The amount-mismatch guard: the paid session's amount or currency didn't equal the order total at confirmation time (typically an admin edited the order while the shopper held an open pay page). The money is safe at XPay; the order is parked for a human. Review the payment in the XPay dashboard, adjust the order if needed, then complete or refund it manually. Log stage: `order.amount_mismatch`.

### Shopper lands on the cart page after paying

WPFunnels is rewriting the after-payment URL into its funnel-routing format; without a WPFunnels Pro upsell step consuming it, the shopper bounces to `/cart/` with no confirmation. The order itself is fine.

**Fix:** WooCommerce → Settings → Payments → XPay → tick **Force the standard order-received page after payment**, save. Applies to XPay orders only; confirmed working when `compat.wpfunnels_url_restored` appears in the log after the next payment. Full background in [COMPATIBILITY.md](COMPATIBILITY.md#wpfunnels-confirmed).

### Refund fails from the WooCommerce order screen

The plugin refunds through the XPay Refunds API and only records the WooCommerce refund when XPay reports the refund **succeeded**. The failure messages mean:

| Message shown | What happened | What to do |
|---|---|---|
| *"XPay cannot refund this payment in its current state."* | The API refused the state transition | Check the payment in the XPay dashboard |
| *"XPay accepted this refund and is still processing it. Do not submit it again…"* | The refund is in flight | Wait; confirm in the XPay dashboard, then record the refund in WooCommerce manually once it completes |
| *"XPay accepted the request but did not complete the refund."* | Synchronous decline by the processor | Check the payment in the XPay dashboard before retrying |
| A message from the API, sometimes with a docs link | A typed API rejection, relayed verbatim | **valU orders are the common case: the XPay platform cannot refund valU payments today.** Arrange the valU refund with XPay support outside the API |

Log stages: `refund.submitted` on success, `refund.not_completed` otherwise, with the XPay refund id and status.

One more thing worth knowing: refunds issued **from the XPay dashboard** are not synced back into WooCommerce (the plugin doesn't subscribe to refund events yet) — record those in WooCommerce manually so the totals agree.

### Two XPay options at checkout

The retired v2 plugin ("WooCommerce XPAY Gateway") is still active alongside this one. They coexist without crashing — which is exactly the trap: shoppers quietly see two separate XPay options, and admin has two settings screens. This plugin shows a persistent admin notice while the legacy plugin is detected.

**Fix:** deactivate the legacy plugin (Plugins page). Settings are deliberately not migrated — v2 stored v2-API credentials, and this plugin authenticates with the v3 API's `rk_…`/`pk_…` keys. Get fresh keys from the XPay dashboard rather than copying old values.

(If you see the combined "XPay" row *plus* method rows, that's not this issue — the combined row hides automatically whenever split rows are active, so that combination shouldn't occur; re-save the XPay settings if it does.)

### Arabic store shows English payment pages

The plugin ships a full Arabic translation (`languages/xpay-for-woocommerce-ar.*`) and RTL styling for the pay and confirmation receipts, and the XPay window itself opens in Arabic whenever the WordPress locale is Arabic.

**Fix:** WP Admin → Settings → General → **Site Language** → العربية. The plugin's strings, the receipt layout (RTL), and the XPay window's language all follow the WordPress locale — there is no separate language setting in the plugin. If admin looks Arabic but the storefront doesn't, check a logged-in user's per-profile language override.

---

## Sharing logs with XPay support

1. Turn on **Diagnostic logging** and reproduce the issue once.
2. WP Admin → **WooCommerce → XPay Log** → **Copy debug report** → paste into your ticket.
3. Add the order number(s) and, for webhook issues, a screenshot of the delivery attempts from the XPay dashboard.

Everything in the report was redacted at write time; keys and secrets never reach the log.

---

## When the log is no help

Some failures happen before the plugin can log:

- **PHP fatal at activation** — check `wp-content/debug.log` (requires `WP_DEBUG_LOG` in `wp-config.php`).
- **Gateway missing everywhere, no XPay settings section** — WooCommerce isn't active, or the plugin isn't. The plugin stays fully dormant unless WooCommerce is loaded.
- **Settings page 500s** — usually another plugin's fatal; check `debug.log`, deactivate other plugins one at a time.

Enable WordPress debug logging with:

```php
// In wp-config.php, BEFORE the "That's all, stop editing!" comment:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```
