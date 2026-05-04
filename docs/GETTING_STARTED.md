# Getting started

This guide takes you from a fresh WordPress + WooCommerce install to a successful test payment on the XPay staging environment. Going to production after this is a separate, shorter step covered in [GOING_LIVE.md](GOING_LIVE.md).

---

## 1. Prerequisites

Before installing the plugin, make sure you have:

- **WordPress 6.0 or newer** — the plugin uses WP HTTP, transient, and option APIs introduced or stabilized in 6.x
- **WooCommerce 8.3 or newer** — required for HPOS (High-Performance Order Storage) and the block-based Cart/Checkout integration
- **PHP 7.4 or newer** — PHP 8.0+ recommended; the plugin is tested on PHP 8.3
- **An XPay merchant account** — sign up at <https://xpay.app/>. Once approved, XPay's onboarding team will give you:
  - A **community ID** (short alphanumeric string identifying your XPay account)
  - A **payment API key** (longer secret used to authenticate API calls)
  - A **variable amount template ID** (numeric — used by the pay/variable-amount API)
  - Access to XPay's **staging dashboard** at <https://staging.xpay.app/admin/login/> for testing
  - Access to XPay's **production dashboard** at <https://community.xpay.app/admin/login/> when you go live
- **HTTPS on your site** — required for the webhook receiver in production. Your hosting provider will offer a free Let's Encrypt certificate if you don't already have one. (Pure local dev on `http://localhost` is fine for testing.)

---

## 2. Install the plugin

### Option A — manual upload (most common)

1. Download the plugin `.zip` (`xpay-for-woocommerce-{VERSION}.zip` from the GitHub releases page or WordPress.org).
2. WP Admin → **Plugins → Add New → Upload Plugin**, choose the `.zip`, click **Install Now**.
   - Or, extract the zip and copy the resulting `xpay-for-woocommerce/` folder to `wp-content/plugins/` over SFTP/SSH.
3. WP Admin → **Plugins → Installed Plugins** — find **XPay for WooCommerce** and click **Activate**.

On activation, the plugin auto-creates the directory `wp-content/uploads/xpay-logs/` (used by the diagnostic logger when you enable it later) and schedules a daily WP-Cron event to prune old log files.

### Option B — via WP-CLI

```bash
# Assuming the plugin folder lives at wp-content/plugins/xpay-for-woocommerce
wp plugin activate xpay-for-woocommerce
```

---

## 3. Get your staging credentials

Contact XPay support or check your XPay onboarding email for **staging** credentials. You need three values:

| Field | Where to find it |
|---|---|
| `community_id` | XPay onboarding email or the XPay staging dashboard, top right |
| `payment_api_key` | XPay staging dashboard → Settings → API Keys |
| `variable_amount_id` | XPay staging dashboard → Variable Amount Templates → click your template, copy the numeric ID |

Keep these handy for the next step. You'll get a separate set of **production** credentials later when going live — never reuse staging credentials in production.

---

## 4. Configure the gateway

WP Admin → **WooCommerce → Settings → Payments → Xpay → Manage**.

Fill in the settings:

| Setting | Value |
|---|---|
| Enable Xpay Payment | **Checked** |
| Title | `Xpay Payment` (or whatever you want shown to customers at checkout) |
| Description | Brief description shown under the title at checkout |
| Community ID | Your staging `community_id` |
| Variable Amount Template ID | Your staging `variable_amount_id` |
| XPAY payment API key | Your staging `payment_api_key` |
| Environment | **Staging** (`https://staging.xpay.app`) |
| Callback URL (informational) | The plugin auto-displays this — you'll paste it into the XPay dashboard in step 5 |
| Webhook secret | Leave empty for now — see step 6 |
| Debug | Off (turn on temporarily if you need verbose `error_log` output) |
| Diagnostic logger | Off (turn on if you hit any issues — see [TROUBLESHOOTING.md](TROUBLESHOOTING.md)) |
| WPFunnels compatibility | Off (turn on if you also use WPFunnels — see [COMPATIBILITY.md](COMPATIBILITY.md)) |

Click **Save changes**.

For the full reference of every setting, see [CONFIGURATION.md](CONFIGURATION.md).

---

## 5. Configure the callback URL on the XPay dashboard

XPay sends a webhook to your site after each transaction completes. Without this configured, orders will stay in `pending` forever even though customers' cards are charged.

1. Copy the callback URL shown in the gateway settings page (the plugin generates it dynamically from its install path — always copy what the plugin displays, don't hardcode it from this doc). It looks like:
   ```
   https://yoursite.example/wp-content/plugins/xpay-for-woocommerce/update_order.php
   ```
2. Log into the **staging** dashboard at <https://staging.xpay.app/admin/login/>.
3. Navigate to your community settings → **Callback URL** field.
4. Paste the URL and save.

---

## 6. (Recommended) Configure the webhook secret

Without a webhook secret, the plugin runs in **fail-open** mode — it accepts any well-formed POST to the callback URL as a legitimate webhook. This is fine for staging, but you should configure a secret before any real money flows.

1. Generate a random secret. Any 32+ character string works. On macOS/Linux:
   ```bash
   openssl rand -hex 32
   ```
2. In XPay's staging dashboard, paste the secret into the field labelled **Secret** next to the callback URL.
3. In the plugin settings (WC → Settings → Payments → Xpay), paste the same secret into **Webhook secret**, save.

When both sides have a secret configured, the plugin verifies the `secret_key` echoed in every incoming webhook body using a constant-time compare against the saved `webhook_secret` and rejects mismatches with HTTP 401. See [CONFIGURATION.md](CONFIGURATION.md#webhook-secret) for details on the verification scheme.

---

## 7. Place your first staging test payment

1. From the WordPress admin, create or pick a **simple** product (under WooCommerce → Products) with a small price (e.g. 10 EGP). Set stock to a reasonable number.
2. Open your storefront in a private/incognito window so you're testing as a customer.
3. Add the product to cart, go to checkout, fill billing details.
4. Select **Xpay Payment** and confirm a list of payment methods appears (Card / Fawry / valU / etc. — the exact list depends on what's enabled on your XPay community).
5. Click **Place order**. You'll be redirected to the XPay iframe.
6. Use a staging test card. XPay's commonly-published staging cards include:

   | Card Number | Expiry | CVV | Notes |
   |---|---|---|---|
   | 5123450000000008 | 01/39 | 100 | NBE Mastercard |
   | 4508750015741019 | 01/39 | 100 | Bank Misr Visa |

   Cardholder name can be anything. Use any future expiry.
7. Submit. The XPay iframe will present the 3DS challenge — accept it.
8. Wait. Within ~10 seconds, the plugin's modal will detect the success, show a 5-second countdown banner, then redirect you to the order-received page.
9. As an admin, check **WooCommerce → Orders** — your test order should be in **Processing** status.

If something doesn't work as described, see [TROUBLESHOOTING.md](TROUBLESHOOTING.md). Enable the diagnostic logger first; the resulting log usually pinpoints the issue immediately.

---

## 8. What's next

- **Test the full flow** before going live — try a successful payment, an abandoned payment (close the iframe), and a different payment method (Fawry, valU) if your community has them enabled.
- **Read [GOING_LIVE.md](GOING_LIVE.md)** when you're ready to switch to production.
- **Read [COMPATIBILITY.md](COMPATIBILITY.md)** if you use other plugins that touch the checkout flow (WPFunnels, caching plugins, security plugins, etc.) — there are some known interactions worth knowing about.
