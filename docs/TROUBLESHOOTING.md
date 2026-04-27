# Troubleshooting

Most issues with the XPay gateway are diagnosable in under five minutes if the diagnostic logger is on while the issue reproduces. This guide covers how to use the logger, what each log stage means, and the most common symptom-to-fix mappings we've seen.

---

## Step 1 — enable the diagnostic logger

WP Admin → **WooCommerce → Settings → Payments → Xpay → Manage**, scroll to **Diagnostic logger**, check the box, save.

The logger has zero runtime cost when disabled. When enabled it appends to a daily-rotated file at `wp-content/uploads/xpay-logs/xpay-flow-YYYY-MM-DD.log` (auto-pruned after 30 days). Secrets and PII are redacted at write time.

To view the live tail: WP Admin → **Tools → XPay Logger**.

To download today's log: same page, **Download today's log** button.

To capture a one-shot environment snapshot (versions, active plugins, php settings): same page, **Run diagnostics snapshot** button. Useful when sharing context with XPay support.

When you're done debugging, **disable the logger** to stop further writes.

---

## Step 2 — reproduce the issue

In a private/incognito window so cookies don't carry over from previous sessions, walk through the customer's exact steps. Note the order ID once one is created.

---

## Step 3 — read the log

The log is line-oriented. Each entry looks like:

```
[2026-04-27T11:30:57Z] [de8234993c45] [webhook.applied] [order=39] webhook applied: payment_complete {"branch":"successful","order_status_out":"processing"}
```

Columns:
- **Timestamp** — ISO 8601, UTC
- **Request ID** — 12-char hex; identical across all entries from the same HTTP request, so you can `grep` for it to see the whole story of one request
- **Stage** — see the table below
- **Order ID** — the WC order being touched, or `-` if not applicable yet
- **Message** — human-readable summary
- **Context** — JSON with structured details

Filter to one order: `grep "order=39" log-file.log`. Filter to one request: `grep "de8234993c45" log-file.log`.

---

## Stage reference

| Stage | When it fires | What to look for |
|---|---|---|
| `boot` | Top of every request | Versions, theme, conflicting plugins detected (`wpfunnels_active`, `caching_plugin`, etc.) |
| `boot.hooks_inventory` | Top of every request | Non-XPay callbacks attached to WC checkout/payment hooks |
| `prefs.fetch` | Community preferences API call | `http_code`, `body_excerpt`, `looks_like_html` (true means WAF blocked) |
| `payment_fields.render` | Gateway radios rendered | `methods` list, `render_context` (classic/blocks), `allow_promo_code` |
| `process_payment.start` | Customer clicked Place Order | Order ID, posted method, cart total |
| `prepare_amount.http` | Outbound prepare-amount call | HTTP code, response time, body excerpt |
| `process_payment.prepare` | After prepare-amount | `parsed_ok`, `has_total`, amount in/out |
| `pay.http` | Outbound pay/variable-amount call | HTTP code, response time, body excerpt |
| `process_payment.pay` | After pay call | `parsed_ok`, `status_code`, `has_iframe_url`, `has_txn_uuid` |
| `process_payment.end` | End of process_payment | `branch` (success / pay_failed / prepare_failed / idempotent_reuse / concurrent_attempt_blocked), `duration_ms` |
| `modal.client_event` | Browser-side events from the modal | `event` (modal_shown / poll_response / countdown_started / redirect_initiated / modal_hidden_manual / js_error), `jq` (jQuery version), `details` |
| `check_transaction` | Modal-poll endpoint hit | `result` (PENDING / SUCCESSFUL / FAILED / INVALID), `reason` if short-circuited |
| `check_transaction.http` | Outbound transaction-status call | HTTP code, response time |
| `webhook.received` | Top of webhook receiver | Source IP, `has_signature_hdr`, `transaction_id`, `transaction_status` |
| `webhook.lookup` | After order lookup | `order_id` (or null), `signature_state` (verified / no_secret_configured / no_header_present / mismatch) |
| `webhook.applied` | End of webhook | `branch` (successful / failed / order_not_found / signature_mismatch / wrong_gateway / closed-status / already-paid / unknown_status), `order_status_out` |
| `wpfunnels.url_override` | When the WPFunnels compat shim restored a standard URL | `wpfunnels_url` (rejected), `restored_url` (used) |
| `diagnostics.snapshot` | "Run diagnostics" button click | One-shot env dump |

---

## Common symptoms

### Customer paid but order is still pending

**Most common cause:** webhook isn't reaching your site.

**Diagnose:**
1. Find the order's `process_payment.end` entry — it should show `branch: success`. If yes, the customer paid through the iframe successfully.
2. Search for `webhook.received` entries with the same `transaction_id` (visible in `process_payment.pay` context as `has_txn_uuid: true`, or check the order's `xpay_transaction_id` meta).
3. **No `webhook.received` entries** → XPay never sent the webhook OR your site never received it.
4. **`webhook.received` exists but `webhook.applied branch: order_not_found`** → race condition (webhook arrived before process_payment finished). The order should be retried automatically by XPay; check after a minute.
5. **`webhook.applied branch: signature_mismatch`** → secret mismatch between plugin and XPay dashboard.

**Fix:**
- Confirm the callback URL on your XPay dashboard matches `https://your-domain/wp-content/plugins/woocommerce-xpay-plugin/update_order.php` exactly (HTTPS, correct domain, exact path).
- Confirm no security plugin (Wordfence, Sucuri, etc.) is blocking the URL — see [COMPATIBILITY.md](../COMPATIBILITY.md#wordfence--sucuri--ithemes-security).
- Test the URL is reachable from outside: `curl -I https://your-domain/wp-content/plugins/woocommerce-xpay-plugin/update_order.php` should NOT return 403/404. (It returns 405 Method Not Allowed because GET is not implemented — that's fine; it proves the URL is reachable.)

**Manual recovery for a stuck order:**
- WP Admin → WooCommerce → Orders → click the order → change status to **Processing** manually. The customer paid; the order is real.

---

### Customer sees "Payment processing failed."

**Cause:** the `prepare-amount` API call to XPay returned an error or no `total_amount`.

**Diagnose:**
1. Find the order's `process_payment.prepare` entry.
2. `parsed_ok: false` → XPay returned non-JSON (likely a Cloudflare HTML challenge page or a 5xx). Check the `prepare_amount.http` entry above it for `http_code`.
3. `has_total: false` → XPay returned valid JSON but with no `data.total_amount` field. Usually means missing or invalid `variable_amount_id`.

**Common fixes:**
- Wrong `variable_amount_id` → fetch the right one from the XPay dashboard
- Wrong `payment_api_key` → returns 401/403 from prepare-amount
- Wrong `community_id` → returns empty methods or 403
- Cloudflare WAF on your community blocking outbound calls — XPay support ticket

---

### "There are no payment methods available"

This is a WC core message, not from our plugin. It appears at checkout when WC asks all gateways "are you available for this cart?" and they all say no.

**Diagnose with our plugin:**
1. Find the most recent `payment_fields.render` entry (classic checkout) or check the block-checkout's `paymentMethodData` source.
2. `method_count: 0` → preferences fetch returned no methods.
3. Look at the `prefs.fetch` entry above it. `looks_like_html: true` → Cloudflare WAF blocked the call. `http_code: 401/403` → bad API key. `http_code: 200` but empty methods → community has no methods enabled.

**Fix:**
- Log into XPay dashboard and verify your community has methods enabled
- Confirm `community_id` and `payment_api_key` are correct
- If `looks_like_html: true`, raise it with XPay support — it's a WAF rule blocking your origin IP

---

### Iframe doesn't open / appears empty

**Cause:** browser blocked the iframe (cookie consent), JS conflict, theme stripped our markup, lazy-loader hid it, or our host allowlist refused the URL.

**Diagnose:**
1. Find the `modal.client_event modal_shown` entry — if it's missing, the modal never opened (JS error before init, or our markup wasn't in the page).
2. Look for `modal.client_event js_error` entries — these are uncaught JS errors captured by our `window.addEventListener('error')`. The error message often points at a theme/plugin script.
3. Look for `iframe.blocked` entries — means our XPay-host allowlist refused the URL XPay returned. Investigate the `rejected_host` field; if it looks legitimate, file a ticket.
4. Look for `js.compat_warning` entries — flag jQuery missing or version below 3.x. The modal still works (it's vanilla JS as of 1.3.0), but other XPay UI like the installment selector needs jQuery.
5. Check the `boot` entry's `cookie_consent` field — if a consent plugin is detected, the iframe cookies may be blocked.

**Fix:**
- See [COMPATIBILITY.md](../COMPATIBILITY.md) — sections on cookie consent, themes, and CSP
- For the consent banner case, allowlist `*.xpay.app` for iframe cookies
- For the lazy-loader case, our iframe ships `class="no-lazy skip-lazy"` (the two most common opt-out conventions); themes using a different opt-out class need a merchant-side tweak

---

### Theme conflict — modal misbehaves on a specific theme

**Cause:** something the theme does (loads a different jQuery, intercepts checkout templates, applies aggressive CSS resets, sets a strict CSP) interferes with our modal or scripts.

**Diagnose:**
1. WP Admin → Tools → XPay Logger → look at the most recent `boot` entry. The `theme` and `parent_theme` fields name the active theme.
2. Cross-reference with [COMPATIBILITY.md → Themes](../COMPATIBILITY.md#themes). Is the theme in the Low/Medium/High-risk table?
3. Look at `boot.hooks_inventory` — any non-XPay callbacks listed under `the_title`, `woocommerce_thankyou`, or `woocommerce_checkout_process` from a `wp-content/themes/...` source path are candidates for the conflict.
4. If the modal won't open or render correctly, check for `modal.client_event js_error` entries — uncaught JS errors often pinpoint the offending script.
5. If the modal opens but the iframe is blank, open browser DevTools → Network tab and check for the iframe URL. If blocked, look for CSP violations in the Console tab. If lazy-loaded, the iframe element will be present but `src` swapped to a placeholder.

**Fix:**
- For known patterns: see the table in [COMPATIBILITY.md → Themes](../COMPATIBILITY.md#themes).
- For unknown patterns: temporarily switch to a known-good theme (Storefront or Twenty Twenty-Five) and reproduce. If the issue goes away, you've isolated the theme as the cause. File a ticket with the log file and the theme name; we'll add a section to COMPATIBILITY.md.

---

### "jQuery loaded but version is unexpected" warning in the log

**Cause:** the page's jQuery is missing or its major version is below 3 (the plugin tests against jQuery 3.x, the WordPress-bundled version since WP 5.6).

**Diagnose:**
1. Look for `modal.client_event js.compat_warning` entries. The `details` field will be `jquery_missing` or `jquery_below_3:<version>`.
2. The modal itself still works as of 1.3.0 — it's pure vanilla JS with no jQuery dependency. The warning is informational.

**Fix:**
- Other XPay UI surfaces (installment-plan selector on classic checkout, promo-code apply button) still use jQuery via WP's bundled copy. If a theme has deregistered it or replaced it with an older version, those features will silently fail.
- Find the theme/plugin that's swapping jQuery (often a "performance optimization" plugin) and disable that behavior. Or switch to a theme that doesn't override jQuery.

---

### "A previous payment attempt for this order is still being processed"

**Cause:** the plugin's concurrent-attempt guard. Triggered when `process_payment` ran and started the pay call but never finished writing the transaction meta (e.g. PHP timed out mid-flow). Without this guard the customer would risk being double-charged on retry.

**Diagnose:**
1. Find the order's most recent `process_payment.end` entry.
2. `branch: concurrent_attempt_blocked` → the guard fired.
3. Look at the older `process_payment.end` entry for this order. Was it `branch: pay_failed` with `upstream_status_code: null`? That's a timeout — XPay may or may not have charged the customer.

**Fix:**
- The guard auto-expires after 10 minutes. Tell the customer to wait 10 minutes and try again.
- If they're worried about a charge, check the XPay dashboard for that customer's email/phone in the recent transactions list.
- For an immediate retry, you can clear the guard manually: `wp eval '$o=wc_get_order(ORDER_ID); $o->delete_meta_data("xpay_pay_started_at"); $o->save();'` — but ONLY do this after confirming with XPay that no charge went through.

---

### Customer lands on `/cart/` after paying

**Cause:** WPFunnels (or similar funnel-builder) is rewriting the post-payment URL.

**Diagnose:**
1. Find the order's `modal.client_event redirect_initiated` entry.
2. If `details` contains `wpfnl-order=` — confirmed WPFunnels rewrite.

**Fix:**
- WP Admin → WC → Settings → Payments → Xpay → enable **WPFunnels compatibility**, save.
- See [COMPATIBILITY.md](../COMPATIBILITY.md#wpfunnels-confirmed) for the full background.

---

### Webhook returns 401

**Cause:** signature mismatch between the plugin's secret and what XPay is signing with.

**Diagnose:**
1. Find the `webhook.received` entry. `has_signature_hdr: true` → XPay is signing.
2. Find the `webhook.applied` entry. `branch: signature_mismatch` confirms.

**Fix:**
- The two secrets MUST be byte-for-byte identical.
- Re-paste both from a single source (a password manager works well).
- If the secrets match but the verification still fails, the signature scheme may have changed on XPay's side. The plugin currently expects `X-XPay-Signature` header, HMAC-SHA256, hex-encoded — see [`update_order.php`](../update_order.php) lines 29-31. If XPay changed any of these, update the constants and reload.

---

### Order moves to processing then back to failed

**Cause:** XPay sent a SUCCESSFUL webhook, then a stale FAILED webhook arrived later. The plugin has a guard against this — see if it fired.

**Diagnose:**
1. Find both `webhook.applied` entries for the order.
2. The first should be `branch: successful, order_status_out: processing`.
3. The second SHOULD be `branch: failed_already_paid, order_status_in: processing` (HTTP 409 returned).

**If the second branch is `failed` instead** (without `_already_paid` suffix), there's a bug — please report with the log excerpt.

---

### High `process_payment.pay duration_ms`

**Cause:** XPay's pay/variable-amount endpoint slow.

**Diagnose:**
- `duration_ms < 5000` — fast, no issue.
- `duration_ms 5000-15000` — normal range for staging.
- `duration_ms > 20000` — slow. Customers may abandon.
- `duration_ms === 25000` (exactly) — timeout. The pay call hit the 25s timeout at [utils.php:11](../utils.php).

**Fix:**
- Sustained timeouts → raise with XPay support, share log excerpts.
- Single timeouts may be transient. The plugin keeps the concurrent-attempt fingerprint to bound double-charge risk; the customer's next attempt within 10 minutes will be blocked with the "previous attempt still being processed" message.

---

## Sharing logs with XPay support

When opening a support ticket with XPay:

1. Reproduce the issue once with the logger ON.
2. WP Admin → **Tools → XPay Logger** → click **Run diagnostics snapshot**.
3. Click **Download today's log**.
4. Attach the `.log` file to the ticket.
5. Note the order ID(s) and approximate UTC timestamps.

The log is pre-redacted of secrets and PII. The diagnostics snapshot includes plugin versions, PHP configuration, and active plugin list — XPay support can usually identify the issue from this alone.

---

## Manual cleanup recipes

### Clear a stuck concurrent-attempt fingerprint

```bash
wp eval '$o=wc_get_order(123); $o->delete_meta_data("xpay_pay_started_at"); $o->delete_meta_data("xpay_transaction_id"); $o->delete_meta_data("xpay_iframe_url"); $o->save();'
```

Use only after confirming with XPay that no charge went through for the previous attempt.

### Force-clear the preferences cache

The plugin caches the preferences fetch for 5 minutes (60 seconds for failures). Force a refresh:

```bash
wp eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"_transient_xpay_prefs_%\" OR option_name LIKE \"_transient_timeout_xpay_prefs_%\"");'
```

### Clear today's log

WP Admin → Tools → XPay Logger → **Clear today's log** button. Or:

```bash
rm wp-content/uploads/xpay-logs/xpay-flow-$(date -u +%Y-%m-%d).log
```

### Reset the WPFunnels admin notice (re-show it after dismissal)

```bash
wp user meta delete <user_id> xpay_dismissed_notice_wpfunnels_redirect
```

---

## When the logger is no help

Some failures happen before the plugin loads:

- **PHP fatal at activation** — check `wp-content/debug.log` (requires `WP_DEBUG_LOG` in `wp-config.php`)
- **Plugin doesn't appear in the gateway list** — check that WooCommerce is active and that the plugin is activated. The XPay gateway only registers itself if WooCommerce is detected.
- **Settings page returns 500** — usually an incompatibility with another plugin. Try deactivating other plugins one at a time.

For these, the WordPress debug log (`wp-content/debug.log`) is the source of truth. Enable with:

```php
// In wp-config.php, BEFORE the "That's all, stop editing!" comment:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```
