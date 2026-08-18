# Going live

Switching the plugin from Test mode to Live mode. This guide assumes you've already gone through [GETTING_STARTED.md](GETTING_STARTED.md) and have the plugin working end-to-end in Test mode.

The actual switch is small (one Mode setting plus the live keys). Most of the work is the pre-flight verification that nothing breaks when real money starts flowing.

---

## Pre-flight checklist

Before you flip Mode to Live, confirm all of the following. Each failure here is something a real customer would experience as a broken payment.

- [ ] **HTTPS is enabled** on your site, and the certificate is valid. XPay only delivers webhooks to `https` URLs. Take the exact webhook URL from the plugin settings (it looks like `https://your-domain.example/?wc-api=xpay_webhook`), then test with `curl -i -X POST -H 'Content-Type: application/json' -d '{}' '<THAT_URL>'` — with a signing secret already saved in the plugin, you should get back HTTP 401 (unsigned request rejected). That confirms TLS works and the receiver is alive. A TLS error means the URL is unreachable; a 500 means the plugin has no webhook secret saved yet.
- [ ] **Live keys obtained** from your XPay dashboard → Developers → API keys: a live **restricted key** (`rk_live_…`) with Checkout Sessions and Refunds access, and the live **publishable key** (`pk_live_…`). These are different values from your test keys.
- [ ] **Live webhook endpoint created** — in your XPay dashboard → Developers → Webhooks, add a **live-mode** endpoint pointing at your store's `/?wc-api=xpay_webhook` URL (copy it character-for-character from the plugin settings), subscribed to `checkout.session.completed` and `checkout.session.expired`. Copy its signing secret (`whsec_…`) — the live endpoint has its own secret, separate from the test endpoint's.
- [ ] **Test mode verified end-to-end** — at minimum, one successful test payment with the order moving from `pending` to `processing` automatically (proves the webhook reached your site and verified), and one refund issued from the WooCommerce order screen.
- [ ] **Site backup taken** — most managed hosts (WP Engine, Kinsta, etc.) offer one-click backups. Take one before any production change.
- [ ] **Payment methods confirmed on your live XPay account** — especially if you use the per-method checkout rows (Card / valU / Fawry): only tick methods your live account actually has enabled. A shopper who picks a missing method falls back to the full XPay window, so nothing breaks, but you'll collect admin notices you don't need.
- [ ] **Diagnostic logging ON for cutover** — tick it in the gateway settings before switching, leave it on for 24-48 hours, then disable. This lets you debug the first real payments quickly from WooCommerce → XPay Log.

---

## The switch

This is the actual cutover. Plan for 15-30 minutes of focused attention.

### 1. Create the live webhook endpoint in your XPay dashboard

If you didn't already do this during pre-flight: in your XPay dashboard, go to **Developers → Webhooks** and add a **live-mode** endpoint.

1. **URL**: your store's webhook URL, copied exactly from the plugin settings:
   ```text
   https://your-domain.example/?wc-api=xpay_webhook
   ```
   (use your real production domain, not a staging copy or localhost)
2. **Events**: subscribe it to `checkout.session.completed` and `checkout.session.expired`.
3. Save, then copy the endpoint's signing secret (`whsec_…`). You'll paste it into the plugin next.

### 2. Update the plugin settings

WP Admin → **WooCommerce → Settings → Payments → XPay**.

Change ONLY these fields:

| Setting | New value |
|---|---|
| Live secret key | Your `rk_live_…` restricted key |
| Live publishable key | Your `pk_live_…` key |
| Live webhook signing secret | The `whsec_…` of the live endpoint you just created |
| Mode | **Live** |

Leave **Title**, **Description**, the checkout display options, and everything else as-is. Your test keys stay saved in their own fields — Mode picks which set is used, so switching back later is a one-field change.

Click **Save changes**. The plugin validates the saved key with a real API call: you should see **"XPay connected (live mode)."** If you pasted a test key while Mode is Live (or the other way around), the settings page tells you so at save time — fix the mismatch before going any further.

### 3. Smoke-test with a real card

The most important step. Do NOT skip this.

1. Open your storefront in a private/incognito window.
2. Add a small-priced product to cart (or temporarily create a 10-EGP test product). Use a real product so the test exercises the same WC pipeline a customer would.
3. Go to checkout, fill billing details with your real info.
4. Select XPay (or the Card row, if you use per-method options) and place the order.
5. You land on the branded pay page and the XPay payment window opens over it by itself.
6. Pay with a real card you own (we recommend a debit card with a small balance for the smoke test).
7. Complete 3DS in your bank's actual challenge flow.
8. Watch:
   - You're taken to the order-received page, where the receipt is stamped **PAID**. (If you outrun the webhook you may briefly see **Confirming payment** — the signed webhook and the server-side session check race, and either one confirms the order.)
   - As an admin, **WooCommerce → Orders** shows the order in **Processing** status.
9. **Important:** refund the test order from the WooCommerce order screen (Refund → Refund via XPay) so you don't ship a real product to yourself.

### 4. Verify the logs captured the flow

WP Admin → **WooCommerce → XPay Log** (needs Diagnostic logging on). For your test order you should see:

- A `session.created` entry — the checkout session was minted against the live API
- A `webhook.received` entry for the `checkout.session.completed` event with `livemode: true`
- An `order.paid` entry — the paid transition fired exactly once

Also check the webhook delivery log in your XPay dashboard: the live endpoint should show green 200s.

If you see `webhook.rejected` instead, **stop and fix this before any more orders flow**: `webhook_signature_invalid` means the `whsec_…` pasted into the plugin doesn't match the endpoint's; `webhook_timestamp_out_of_tolerance` means your server clock is off by more than 5 minutes (fix NTP). The receiver is fail-closed — nothing gets marked paid by an unverified request — so until this is fixed, orders confirm only when the shopper reaches the order-received page (and, on funnel checkouts, not even then; see below).

---

## First 24-48 hours

Keep Diagnostic logging on. Check **WooCommerce → XPay Log** at least once per day for:

- **`webhook.rejected` entries** — every live delivery should verify. Persistent rejections mean a wrong secret, clock skew, or a hostile probe; the delivery log in your XPay dashboard tells you whether real deliveries are failing. XPay retries for ~3 days, so fixing a wrong secret recovers the missed events.
- **`webhook.ownership_mismatch` or `webhook.order_not_found` entries** — an event arrived whose session doesn't match the order's stored session (or matches no order at all). This should never happen organically; investigate immediately.
- **`order.amount_mismatch` entries** — XPay reported a charged amount that disagrees with the order total, so the plugin parked the order **on-hold** with a note instead of marking it paid. Resolve by hand: check the payment in your XPay dashboard, then complete or refund the order.
- **`process_payment.failed` or `api.transport_error` entries** — sessions failing to create, or connectivity trouble reaching `api.xpay.app`. Occasional blips resolve themselves (the shopper retries from the pay page); a sustained pattern is worth raising with XPay support.

Also glance at the webhook delivery log in your XPay dashboard daily — sustained non-200s there mean orders are confirming late, or on funnel checkouts not at all.

Once you're comfortable everything is stable, **untick Diagnostic logging** in the gateway settings. Existing log rows age out automatically (14-day / 10,000-row retention) but no new entries will be written.

---

## Common gotchas at launch

| Symptom | Likely cause | Fix |
|---|---|---|
| Orders stay "pending" until the shopper reaches the confirmation page | Webhook endpoint on the XPay dashboard missing, or still pointing at staging or `localhost` | Point a live-mode endpoint at the `/?wc-api=xpay_webhook` URL shown in the plugin settings |
| First real payment marks the order paid but customer lands on `/cart/` | WPFunnels active, compatibility setting still off | Enable **WPFunnels compatibility** in gateway settings (see [COMPATIBILITY.md](COMPATIBILITY.md)) |
| Customer sees "The payment could not be started" | Live keys missing, or Test keys pasted into Live mode (or vice versa) | The settings page shows a key/mode mismatch notice — paste the matching `rk_live_…` / `pk_live_…` pair for the selected mode |
| No payment methods show on checkout | Production account has no methods enabled, or the API key is wrong | Log into the XPay production dashboard, enable methods, verify the key |
| Webhook returns 401 | The plugin's signing secret doesn't match the endpoint's `whsec_…` on the XPay dashboard | Re-paste the secret on both sides; they must be byte-for-byte identical |
| Webhook returns 404 | The endpoint URL on the XPay dashboard doesn't carry the exact `/?wc-api=xpay_webhook` query — a dropped `?` or a dash instead of the underscore is the usual culprit | Copy the URL character-for-character from the plugin settings; the receiver itself only ever answers 200, 400, or 401 |
| Payment window doesn't open | A JS optimizer delaying scripts (the plugin stamps opt-out attributes, but some optimizers ignore them), or a browser extension | Check the browser console; the page auto-continues to the hosted XPay checkout after ~6 seconds either way, so shoppers are never stranded |

### Funnel builders make the webhook mandatory

On a standard store the plugin has a second confirmation path: when the
shopper lands on WooCommerce's order-received page, it re-verifies the
session server-side even if the webhook hasn't arrived yet. **A funnel
builder with a custom thank-you step (WPFunnels Pro upsells, CartFlows,
FunnelKit) bypasses that page, so the webhook becomes the only thing that
confirms orders.** If you run funnels, do not go live until the webhook
delivery log on the XPay dashboard shows green 200s — treat a failing
webhook as a launch blocker, not a cosmetic issue.

---

## Rollback

If something goes badly wrong, you can roll back in under a minute:

1. WP Admin → **WooCommerce → Settings → Payments → XPay**
2. Untick **Enable XPay** and save — the gateway (and any per-method rows) disappears from checkout while you investigate.
3. Alternatively, if you want to keep exercising the flow yourself without charging anyone, switch **Mode** back to **Test** — your live keys stay saved for when you return.

Customers attempting to check out will not see the gateway. Existing orders that were paid with real money are still real; XPay has the funds. Refund them from the WooCommerce order screen (or your XPay dashboard) if needed.

Then read WooCommerce → XPay Log and identify what went wrong before flipping back on.

---

## After launch

- Untick Diagnostic logging once you're confident in stability (typically 1-2 weeks).
- If you ever rotate the live endpoint's signing secret in your XPay dashboard, paste the new `whsec_…` into the plugin right away. Deliveries signed with the other secret are rejected with 401 and retried — XPay retries for ~3 days, so a short overlap loses nothing, but schedule the rotation during low-traffic hours anyway.
- Keep an eye on the [CHANGELOG](../CHANGELOG.md) when updating the plugin so you know what's changing.
