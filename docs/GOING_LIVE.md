# Going live

Switching from XPay's staging environment to production. This guide assumes you've already gone through [GETTING_STARTED.md](GETTING_STARTED.md) and have the plugin working end-to-end against staging.

The actual switch is small (a few setting changes). Most of the work is the pre-flight verification that nothing breaks when real money starts flowing.

---

## Pre-flight checklist

Before you flip the environment to Production, confirm all of the following. Each failure here is something a real customer would experience as a broken payment.

- [ ] **HTTPS is enabled** on your site, and the certificate is valid. The webhook callback URL must be reachable over HTTPS in production. Take the exact callback URL from the plugin settings (WC → Settings → Payments → Xpay → Manage), then test with `curl -i -X POST -H 'Content-Type: application/json' -d '{}' <THAT_URL>` — you should get back HTTP 400 with a "Missing transaction_id" body. That confirms TLS works and the endpoint is alive. Any TLS error or 5xx means the URL is unreachable.
- [ ] **Production credentials obtained** from XPay: production `community_id`, production `payment_api_key`, production `variable_amount_id`. These are different values from your staging credentials.
- [ ] **Production webhook secret** generated (32+ random characters; `openssl rand -hex 32` works) and ready to paste into both XPay's production dashboard and the plugin's setting.
- [ ] **Staging end-to-end verified** — at minimum, one successful payment with a test card, with the order moving from `pending` to `processing` automatically (proves the webhook reached your site and was processed).
- [ ] **Site backup taken** — most managed hosts (WP Engine, Kinsta, etc.) offer one-click backups. Take one before any production change.
- [ ] **Production payment methods enabled on the XPay community** — log into the XPay production dashboard and verify that the payment methods you want offered (Card, Fawry, valU, Apple Pay, Wallets, Installments) are actually enabled for your community. The plugin renders whatever XPay's preferences endpoint returns.
- [ ] **Logger ON for cutover** — enable the diagnostic logger before switching, leave it on for 24-48 hours, then disable. This lets you debug the first real payments quickly.

---

## The switch

This is the actual cutover. Plan for 15-30 minutes of focused attention.

### 1. Update the XPay production dashboard

Log into <https://community.xpay.app/admin/login/> with your production credentials.

1. Find your community settings.
2. **Callback URL** field: paste your production callback URL — copy it directly from the plugin settings (WC → Settings → Payments → Xpay → Manage). The plugin computes the URL from its own install path so it's always correct. For a stock v2.0.0 install it looks like:
   ```text
   https://your-domain.example/wp-content/plugins/xpay-for-woocommerce/update_order.php
   ```
   (use your real production domain, not staging or localhost)
3. **Secret** field (next to the callback URL): paste the 32-character webhook secret you generated above.
4. Save.

### 2. Update the plugin settings

WP Admin → **WooCommerce → Settings → Payments → Xpay**.

Change ONLY these fields:

| Setting | New value |
|---|---|
| Community ID | Your **production** community ID |
| Variable Amount Template ID | Your **production** variable amount ID |
| XPAY payment API key | Your **production** API key |
| Environment | **Production** (`https://community.xpay.app`) |
| Webhook secret | The same 32-character secret you pasted into XPay's dashboard |

Leave **Title**, **Description**, **Instructions**, and other display fields as-is.

Click **Save changes**.

The plugin's preferences cache is keyed by environment + community + api_key, so this combination of changes invalidates the staging cache and the next checkout-page load will hit production's `/api/communities/preferences/` endpoint to fetch the live payment-method list.

### 3. Smoke-test with a real card

The most important step. Do NOT skip this.

1. Open your storefront in a private/incognito window.
2. Add a small-priced product to cart (or temporarily create a 10-EGP test product). Use a real product so the test exercises the same WC pipeline a customer would.
3. Go to checkout, fill billing details with your real info.
4. Select Xpay Payment, place the order.
5. The XPay iframe should load with your production-environment branding.
6. Pay with a real card you own (we recommend a debit card with a small balance for the smoke test).
7. Complete 3DS in your bank's actual challenge flow.
8. Watch:
   - The modal should detect success within ~10s and show the 5-second countdown banner.
   - You're redirected to the order-received "Thank you for your order" page.
   - As an admin, **WooCommerce → Orders** shows the order in **Processing** status.
9. **Important:** refund the test order from your XPay production dashboard so you don't ship a real product to yourself.

### 4. Verify the logger captured the flow correctly

WP Admin → **Tools → XPay Logger**. You should see:

- A `boot` snapshot showing `wc_version`, `php_version`, and your active plugins
- A `prefs.fetch` entry showing HTTP 200 from the production preferences endpoint
- A `process_payment.start` → `.prepare` → `.pay` → `.end` chain for your test order
- A `webhook.received` entry showing `has_body_secret: true` (XPay included the secret in the webhook body)
- A `webhook.lookup` entry showing `signature_state: verified` (this is where verification status appears in the success flow)
- A `webhook.applied` entry showing `branch: successful`

If `webhook.lookup` shows `signature_state: no_secret_configured`, `secret_missing_in_body`, or `secret_mismatch`, **stop and fix this before any more orders flow**. Anyone could mark random orders paid by sending crafted POSTs to your callback URL.

---

## First 24-48 hours

Keep the diagnostic logger on. Check it at least once per day for:

- **`webhook.lookup` entries with `signature_state` other than `verified`** — every webhook in production should verify successfully. `no_secret_configured` means the plugin has no secret set; anything else (`secret_missing_in_body`, `secret_mismatch`) is either XPay misconfiguration or a hostile probe.
- **`webhook.applied` entries with `branch: secret_missing_in_body` or `branch: secret_mismatch`** — these are explicit security rejections (the webhook was rejected before any order lookup). Both are worth investigating immediately.
- **`process_payment.pay` entries with `duration_ms` over 20000** — sustained slowness from XPay. If frequent, raise it with XPay support.
- **`process_payment.end` entries with `branch: pay_failed` and `upstream_status_code: null`** — pay-call timeouts. The plugin keeps the concurrent-attempt fingerprint to block retries; affected customers will see "A previous payment attempt is still being processed" until the 10-minute window expires.
- **`webhook.applied` entries with `branch: order_not_found`** — webhook arrived for a transaction the plugin doesn't know about. Could indicate a race (webhook before process_payment finished saving) or, in very rare cases, hostile probing.

Once you're comfortable everything is stable, **disable the diagnostic logger** in the gateway settings. Logs will continue to be auto-pruned after 30 days but no new entries will be written.

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

If something goes badly wrong, you can roll back to staging in under a minute:

1. WP Admin → **WooCommerce → Settings → Payments → Xpay**
2. Set **Environment** back to **Staging**, paste the staging credentials back in
3. **Disable Xpay Payment** entirely while you investigate (uncheck the **Enable Xpay Payment** toggle and save)

Customers attempting to check out will not see the gateway. Existing orders that were marked `processing` from real payments are still real; XPay has the funds. Refund them from the XPay production dashboard and from WC if needed.

Then take a look at the diagnostic logger and identify what went wrong before flipping back on.

---

## After launch

- Disable the diagnostic logger once you're confident in stability (typically 1-2 weeks).
- Plan for periodic webhook secret rotation (annually is reasonable). Rotate by:
  1. Generate a new secret
  2. Paste it into BOTH XPay's dashboard AND the plugin setting at the same time
  3. There will be a brief window where in-flight webhooks may fail signature verification — schedule the rotation during low-traffic hours
- Keep an eye on the [CHANGELOG](../CHANGELOG.md) when updating the plugin so you know what's changing.
