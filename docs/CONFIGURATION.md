# Configuration reference

Every setting on the **WooCommerce → Settings → Payments → XPay** screen, where its value comes from, and what goes wrong when it's wrong.

Two things to know before you start:

- **All XPay settings live on this one screen.** If you turn on separate checkout options per payment method (see [Checkout display](#checkout-display)), extra rows named "XPay — Card", "XPay — valU" and "XPay — Fawry" appear in the payments list, but they carry no settings of their own — their "Manage" pages are intentionally empty, and everything is controlled from the main XPay screen.
- **XPay stays hidden at checkout until it is configured.** An enabled gateway with no keys can only dead-end your shoppers, so the plugin hides every XPay option until both a secret key and a publishable key are saved for the selected mode.

Settings are stored in the WordPress option `woocommerce_xpay_settings` (a serialized array), shared by the combined option and all per-method rows.

---

## Display settings

These control how the gateway appears to your customers at checkout.

### Enable/Disable

- **Default:** Off
- **What it does:** The plugin-wide switch. When off, no XPay option appears at checkout — combined or per-method.
- **Tip:** You can switch off temporarily during maintenance without losing any other configuration.
- **When it's wrong:** Enabled but with no keys saved, XPay still stays hidden at checkout (see above), and saving the settings shows an explicit warning: *"no API key is saved for the selected mode, so XPay stays hidden at checkout until you add one."*

### Title

- **Default:** `XPay`
- **What it does:** The payment method name customers see at checkout when XPay appears as one combined option. (In split mode, each row uses the fixed method name — Card, valU, Fawry — instead.)

### Description

- **Default:** `Pay securely by card or valU.`
- **What it does:** One sentence under the payment method name at checkout.
- **Tip:** Mention the methods your account actually accepts, so customers know what to expect.

---

## Mode and API keys

The plugin talks to XPay's API with keys from **your XPay dashboard → Developers → API keys**. Test and live are completely separate: separate keys, separate webhook secrets, separate payments and customers. You can keep both key sets saved at the same time — the **Mode** selector decides which set is used.

### Mode

- **Default:** Test
- **Options:** **Test** | **Live**
- **What it does:** Selects which key set (and which webhook signing secret) the plugin uses. Test mode never charges real money.
- **Switching:** Flip the mode and save — nothing else to re-enter, as long as the matching key set is filled in. See [GOING_LIVE.md](GOING_LIVE.md) for the full checklist.
- **When it's wrong:** If the key saved for the selected mode belongs to the *other* mode, the save shows an error naming the mismatch (for example: *"the key in the selected mode is a LIVE key but the gateway is in Test mode"*). Fix it by pasting the key that matches the mode you selected.

### Test secret key / Live secret key

- **Required** (for the respective mode). No default.
- **Format:** `rk_test_…` (test) or `rk_live_…` (live).
- **Where to get it:** Your XPay dashboard → **Developers → API keys**. Create a **restricted key** with **Checkout Sessions** and **Refunds** access.
- **What it does:** Authenticates your server's calls to XPay — creating payment sessions and submitting refunds. It never reaches the customer's browser.
- **Sensitivity:** **Secret — never share it.** The field is masked in admin, and the diagnostic logger redacts it before anything is written.
- **Validation:** Saving the settings validates the key with a real API call. You'll see *"XPay connected (test mode)"* / *"XPay connected (live mode)"* on success, or *"the API key did not validate"* with the reason on failure.
- **When it's wrong:** Payments cannot start — shoppers see *"The payment could not be started. Please try again — your card has not been charged."* and the order gets a note with the real error.

### Test publishable key / Live publishable key

- **Required** (for the respective mode). No default.
- **Format:** `pk_test_…` or `pk_live_…`.
- **Where to get it:** Same place — your XPay dashboard → **Developers → API keys**.
- **What it does:** Used by the secure payment window in the customer's browser. It is not a secret, but it must match the mode and account of your secret key.
- **When it's wrong:** Empty → XPay stays hidden at checkout. Mistyped → the payment window fails to open on the pay page. Re-copy it from the dashboard.

### Test webhook signing secret / Live webhook signing secret

- **Required for automatic order confirmation.** No default.
- **Format:** `whsec_…`.
- **Where to get it:** Your XPay dashboard → **Developers → Webhooks** — it's shown when you create the endpoint for your store (see below).
- **What it does:** Lets the plugin verify that every incoming webhook was genuinely signed by XPay. Verification is fail-closed: an unsigned or wrongly-signed request is rejected, never trusted.
- **Sensitivity:** **Secret — never share it.**
- **When it's wrong or missing:** Orders stay **Pending payment** even though customers were charged, until the shopper happens to land on the confirmation page (which triggers a server-side re-check). With no secret saved, the plugin answers webhooks with HTTP 500 — XPay keeps retrying for about 3 days, so finishing the setup within that window recovers the missed events. With the *wrong* secret saved, deliveries show 401 in the XPay dashboard's delivery log. Details in [WEBHOOKS.md](WEBHOOKS.md).

### The webhook endpoint (what you configure on XPay's side)

This is not an editable plugin setting — it's the URL you give XPay. The exact URL for your store is shown in the webhook-secret field descriptions on the settings screen:

```text
https://<your-store>/?wc-api=xpay_webhook
```

In your XPay dashboard → **Developers → Webhooks**, add an endpoint pointing at that URL and subscribe it to the events `checkout.session.completed` and `checkout.session.expired`. **Test and live modes each need their own endpoint and their own signing secret.** XPay only delivers webhooks to HTTPS URLs, so your store must have a valid certificate.

---

## Checkout display

### Payment options

- **Default:** One XPay option for all methods
- **Options:**
  - **One XPay option for all methods** — a single "XPay" choice at checkout; the customer picks their method inside the payment window.
  - **A separate option per payment method** — dedicated Card / valU / Fawry rows, each with its logo. The payment window opens directly on the method the shopper picked.

### Card / valU / Fawry checkboxes

- **Defaults:** Card on, valU on, Fawry off
- **What they do:** In split mode, each ticked method gets its own row at checkout. (In combined mode the checkboxes have no effect.) Card covers Visa, Mastercard and Meeza.
- **Only tick methods enabled for your XPay account.** If a shopper picks a method your account doesn't have, they are shown the full XPay window instead — the payment still has a path forward — and you get an admin notice telling you which method to enable in your XPay dashboard or untick here.
- **Tip:** You can also toggle the per-method rows straight from the payments list (WooCommerce → Settings → Payments). Switching a method row on there automatically switches Payment options to split mode; switching every row off brings the combined XPay option back on its own — checkout never goes dark.

---

## WPFunnels compatibility

### Confirmation page

- **Default:** Off
- **What it does:** Only relevant when the WPFunnels plugin is active. WPFunnels reroutes the after-payment page into its funnel flow; without a WPFunnels Pro upsell step, that bounces shoppers to the cart with no confirmation. Turn this on to force the standard WooCommerce order-received page after payment. Applies to XPay orders only.
- **When to leave OFF:** If you run a working WPFunnels Pro upsell flow that XPay customers should enter after paying. See [COMPATIBILITY.md](COMPATIBILITY.md) for the full background.

---

## Diagnostic logging

### Diagnostic logging

- **Default:** Off
- **What it does:** Records every step of the XPay flow — session creation, webhook deliveries, order transitions, refunds. Keys, secrets, card numbers and personal data are redacted **before** anything is written, and nothing is ever transmitted anywhere automatically.
- **Where to view it:**
  - **WooCommerce → XPay Log** — the built-in viewer. Filter by order number, request id, or stage (e.g. `webhook.`); click **Copy debug report** for a one-click, redacted bundle to paste into a support ticket; **Clear log** wipes all entries.
  - **The order screen** — every XPay order has an XPay panel showing its payment identifiers and recent log entries, so "what happened to this payment" is answered on the order itself.
  - **WooCommerce → Status → Logs**, source `xpay` — the same events in WooCommerce's standard log stream, for developers.
- **Retention:** Entries live in a bounded database table — kept for 14 days, capped at 10,000 rows, pruned daily. Safe to leave on; safe to leave installed and off.
- **When to enable:** Turn on before reproducing an issue, then check the viewer. See [TROUBLESHOOTING.md](TROUBLESHOOTING.md).

---

## Configured elsewhere (no plugin setting)

Some things merchants look for on this screen are deliberately not here:

| What | Where it's controlled |
|---|---|
| Pay-page brand color | Your XPay dashboard. The pay page follows your merchant primary color automatically — the dashboard is the source of truth, and changes sync on their own. |
| Which payment methods your account accepts | Your XPay dashboard / XPay support. The plugin never shows a method your account cannot accept. |
| Refunds | The WooCommerce order screen — full and partial refunds go through the XPay API from there. valU payments cannot be refunded by the XPay platform yet; the plugin tells you so explicitly instead of failing silently. |
| Language | The payment window and receipts follow your store language (full Arabic and English, including right-to-left receipts). |

---

## Setting summary table

| Setting | Required? | Sensitive? | Storage key |
|---|---|---|---|
| Enable/Disable | yes | no | `enabled` |
| Title | no (has default) | no | `title` |
| Description | no (has default) | no | `description` |
| Mode | yes | no | `mode` |
| Test secret key | **yes** in test mode | **HIGH** — never share | `test_api_key` |
| Test publishable key | **yes** in test mode | no | `test_publishable_key` |
| Test webhook signing secret | **yes** for order confirmation | **HIGH** — never share | `test_webhook_secret` |
| Live secret key | **yes** in live mode | **HIGH** — never share | `live_api_key` |
| Live publishable key | **yes** in live mode | no | `live_publishable_key` |
| Live webhook signing secret | **yes** for order confirmation | **HIGH** — never share | `live_webhook_secret` |
| Payment options | no (default: combined) | no | `display_mode` |
| Card as its own option | no | no | `split_card` |
| valU as its own option | no | no | `split_valu` |
| Fawry as its own option | no | no | `split_fawry` |
| WPFunnels: Confirmation page | no | no | `wpfunnels_force_standard_redirect` |
| Diagnostic logging | no | no | `debug` |

---

## Programmatic configuration

For provisioning automation or multi-site rollouts, the settings are ordinary WordPress options:

```bash
# Read the whole settings blob
wp option get woocommerce_xpay_settings --format=json

# Update a single key
wp option patch update woocommerce_xpay_settings mode "live"
```

For multisite, scope to the right site:

```bash
wp --url=https://shop.example.com option patch update woocommerce_xpay_settings ...
```

Note that scripted changes skip the save-time checks the settings screen performs (key/mode mismatch detection and live key validation) — after a scripted rollout, open the settings screen once and save to confirm *"XPay connected"*.
