# Configuration reference

Every setting on the **WooCommerce → Settings → Payments → Xpay** screen, what it controls, and what to do when something looks wrong.

Settings are stored in the WordPress option `woocommerce_xpay_gateway_settings` (a serialized array). You can read or modify any of them from the command line with:

```bash
wp option get woocommerce_xpay_gateway_settings --format=json
wp option patch update woocommerce_xpay_gateway_settings <key> <value>
```

---

## Display settings

These control how the gateway appears to your customers at checkout.

### Enable Xpay Payment

- **Default:** Yes
- **What it does:** Standard WooCommerce gateway toggle. When off, XPay does not appear at checkout at all.
- **Tip:** You can disable temporarily during a deploy or maintenance window without losing any other configuration.

### Title

- **Default:** `Xpay Payment`
- **What it does:** The label customers see next to the gateway radio at checkout.
- **Tip:** Most merchants change this to something brand-aligned like "Pay online with card" or "Credit / Debit card".

### Description

- **Default:** *(generic placeholder)*
- **What it does:** Short text shown under the title at checkout when this gateway is selected.
- **Tip:** Use it to mention which payment methods are available ("Card, Fawry, valU, Apple Pay") so customers know what to expect.

### Instructions

- **Default:** Empty
- **What it does:** Optional text added to the thank-you page and order emails after a successful payment. Same field as WooCommerce's standard "Instructions" pattern.
- **Tip:** Useful for "Your transaction will appear on your card statement as XPAYEGP" or links to your support channels.

---

## XPay account credentials

These tell the plugin which XPay account to authenticate as. You get all three from XPay during onboarding (separate values for staging and production).

### Community ID

- **Required.** No default.
- **Format:** Short alphanumeric string, typically 7 characters.
- **Where to get it:** XPay onboarding email or the XPay dashboard top-right corner.
- **What happens if wrong:** The preferences API call returns no payment methods. At checkout, the gateway radio appears with no method radios under it (or the cached default fallback list — see [TROUBLESHOOTING.md](TROUBLESHOOTING.md#empty-payment-method-list)).

### Variable Amount Template ID

- **Default:** Empty
- **Format:** Numeric string (4-6 digits typically).
- **Where to get it:** XPay dashboard → Variable Amount Templates → click your template → copy the numeric ID from the URL or detail page.
- **What it does:** Tells XPay's `pay/variable-amount` API which template to use for fee/total calculation. Without it, the API returns 403.
- **What happens if wrong:** Every payment attempt fails at the prepare-amount step. The customer sees "Payment processing failed."

### XPAY payment API key

- **Required.** No default.
- **Format:** Long alphanumeric string with at least one dot (roughly `XXXXXXXX.YYYYYYYYYYYYYYYYYYYYYYYYYYYYYYY`).
- **Where to get it:** XPay dashboard → Settings → API Keys.
- **Sensitivity:** **This is a secret.** It is never echoed back to the browser by the plugin (a previous version did so via inline JS — that was a security bug, since fixed). Keep it secret. The diagnostic logger redacts it from log files.
- **What happens if wrong:** All API calls return 401/403. Same symptoms as a bad community_id.

---

## Environment

### Environment

- **Default:** Staging (`https://staging.xpay.app`)
- **Options:**
  - **Local** (`http://127.0.0.1:8000`) — only useful if you're an XPay developer running the API locally
  - **Development** (`https://new-dev.xpay.app`) — XPay's internal dev environment
  - **Staging** (`https://staging.xpay.app`) — for testing with test cards before going live
  - **Production** (`https://community.xpay.app`) — real money flows here
- **What it does:** Sets the base URL for every XPay API call (`prepare-amount`, `pay/variable-amount`, `preferences`, `transactions`, promo validation). The iframe URL the customer's browser hits is returned in the pay-call response, so it follows the active environment automatically.
- **Switching environments:** When you flip this setting, you also need to swap to the matching set of credentials (community_id, payment_api_key, variable_amount_id) from XPay. Staging credentials will not authenticate against production and vice versa. See [GOING_LIVE.md](GOING_LIVE.md).

### Callback URL (informational, not editable)

- **What you see:** A read-only field showing the URL XPay should send webhooks to. The plugin computes it dynamically from its own install path, so it's always correct regardless of the directory name. For a stock v2.0.0 install it looks like:
  ```text
  https://{your-domain}/wp-content/plugins/xpay-for-woocommerce/update_order.php
  ```
  …but if you installed under a different folder name, the field reflects that — always copy what the plugin shows you, not the example.
- **What to do with it:** Copy the exact URL the plugin shows and paste it into the **Callback URL** field on your XPay dashboard (separately for staging and production accounts). Without this, XPay doesn't know where to send payment-confirmation webhooks, and orders will sit in `pending` even after the customer pays.
- **HTTPS:** XPay requires HTTPS in production. If your site isn't on HTTPS, get a Let's Encrypt certificate before going live.

### Webhook secret

- **Default:** Empty
- **Format:** Any random string ≥ 32 characters. Generate with `openssl rand -hex 32` or any password manager.
- **What it does:** Enables HMAC-SHA256 signature verification on incoming webhooks. The exact behavior depends on whether the secret is set — see "Operating modes" below.
- **Signature scheme:**
  - **Header name:** `X-XPay-Signature`
  - **Algorithm:** HMAC-SHA256
  - **Signed payload:** the raw JSON body of the request (byte-for-byte)
  - **Encoding:** hex (lowercase)
  - These three constants are at the top of [`update_order.php`](../update_order.php) — edit them if XPay changes its scheme.
- **Operating modes:**
  - **Legacy / unsigned (secret empty):** Every webhook is accepted without signature checks; the plugin writes `[xpay] webhook accepted unsigned (no secret configured)` to the WP debug log on each accepted webhook. Used by merchants who connected before XPay supported signing, and during initial integration testing.
  - **Strict (secret set):** Every webhook must carry a valid `X-XPay-Signature` header. A missing header or a signature mismatch is rejected with HTTP 401 — there is no silent fallback to unsigned, since that would defeat the merchant's choice to enable verification. This is the recommended posture for real-money traffic.
- **Strong recommendation:** Configure this before any real-money traffic. Without it, anyone who can guess a transaction UUID can mark orders paid by sending a crafted POST to the callback URL.

---

## Diagnostics and compatibility

### Debug

- **Default:** Off
- **What it does:** When on, the plugin writes verbose entries to the WordPress `debug.log` (under `wp-content/debug.log` if `WP_DEBUG_LOG` is enabled in `wp-config.php`). Specifically, every outbound XPay HTTP call's HTTP code and first ~400 bytes of response body are logged.
- **Tip:** Use the **Diagnostic logger** (below) instead for normal troubleshooting — it's structured and redacts secrets. The Debug toggle is mainly for cases where you need raw HTTP response bodies and don't trust the diagnostic logger to preserve them faithfully.

### Diagnostic logger

- **Default:** Off
- **What it does:** Records every step of the XPay flow to a daily-rotated log file at `wp-content/uploads/xpay-logs/xpay-flow-YYYY-MM-DD.log`. Each entry includes a timestamp, request ID, stage, order ID, and structured context. Secrets and PII are redacted at write time. Logs older than 30 days are auto-pruned by a daily WP-Cron event.
- **Where to view it:** WP Admin → **Tools → XPay Logger** (admin-only). The page shows a live tail of the current day's log with stage filtering, free-text grep, "Run diagnostics" button, and download.
- **Performance:** When the toggle is OFF, the logger is a true no-op — no listeners are attached, no file IO happens, no boot snapshot runs. Hot-path cost: a single option-cache lookup. Safe to leave installed but disabled in production.
- **When to enable:** Turn on while reproducing an issue, then turn off. See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for what to do with the resulting logs.

### WPFunnels compatibility

- **Default:** Off
- **What it does:** Only relevant if you also have WPFunnels active. WPFunnels filters the post-payment URL through its own funnel-routing format. On WPFunnels Free without a configured upsell step, this rewrite causes XPay customers to land on `/cart/` instead of a "Thank you for your order" page. Turn this on to force the standard WooCommerce order-received URL for XPay orders.
- **When to leave OFF:** If you have a configured WPFunnels Pro upsell flow that you want XPay customers to enter after paying.
- **Detection:** The plugin shows a one-time admin notice nudging you to enable this whenever WPFunnels is detected and the setting is still off. Dismissal is per-user and persistent. See [COMPATIBILITY.md](COMPATIBILITY.md#wpfunnels-confirmed) for the full background.

---

## Setting summary table

| Setting | Required? | Sensitive? | Storage key |
|---|---|---|---|
| Enable | yes | no | `enabled` |
| Title | no (has default) | no | `title` |
| Description | no | no | `description` |
| Instructions | no | no | `instructions` |
| Community ID | **yes** | low (identifies your account) | `community_id` |
| Variable Amount Template ID | **yes** for variable-amount API | no | `variable_amount_id` |
| XPAY payment API key | **yes** | **HIGH** — never share | `payment_api_key` |
| Environment | yes | no | `iframe_base_url` |
| Webhook secret | recommended | **HIGH** — never share | `webhook_secret` |
| Debug | no | no | `debug` |
| Diagnostic logger | no | no | `logger_enabled` |
| WPFunnels compatibility | no | no | `wpfunnels_force_standard_redirect` |

---

## Programmatic configuration

For provisioning automation, multi-site rollouts, or staging-environment scripts:

```bash
# Read the whole settings blob
wp option get woocommerce_xpay_gateway_settings --format=json

# Update a single key
wp option patch update woocommerce_xpay_gateway_settings community_id "ABC123"

# Insert a new key (use 'insert' instead of 'update' the first time)
wp option patch insert woocommerce_xpay_gateway_settings webhook_secret "your-32-char-secret"

# Replace the whole blob from a JSON file
wp option update woocommerce_xpay_gateway_settings "$(cat settings.json)" --format=json
```

For multisite, scope to the right site:

```bash
wp --url=https://shop.example.com option patch update woocommerce_xpay_gateway_settings ...
```
