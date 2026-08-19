# Getting started

This guide takes you from a fresh WordPress + WooCommerce install to a successful test payment in XPay's test mode. No real money moves at any point. Going live afterwards is a separate, shorter step covered in [GOING_LIVE.md](GOING_LIVE.md).

---

## 1. Prerequisites

Before installing the plugin, make sure you have:

- **WordPress 6.2 or newer**
- **WooCommerce 8.3 or newer** — required for the block-based Cart/Checkout integration and HPOS (High-Performance Order Storage), both of which the plugin supports
- **PHP 7.4 or newer** — PHP 8.0+ recommended
- **HTTPS on your store** — payments will not work without it, and XPay only delivers webhooks to HTTPS URLs. Your hosting provider will offer a free Let's Encrypt certificate if you don't already have one.
- **An XPay merchant account** — sign up at <https://xpay.app/>. Everything you need for this guide comes from **your XPay dashboard**: API keys, webhook setup, and the test-payment delivery log.
- **A supported store currency** — EGP recommended (settlement is in EGP).

---

## 2. Install the plugin

### Option A — manual upload (most common)

1. Download the plugin `.zip`.
2. WP Admin → **Plugins → Add New → Upload Plugin**, choose the `.zip`, click **Install Now**.
   - Or, extract the zip and copy the resulting `xpay-for-woocommerce/` folder to `wp-content/plugins/` over SFTP/SSH.
3. WP Admin → **Plugins → Installed Plugins** — find **XPay for WooCommerce** and click **Activate**.

### Option B — via WP-CLI

```bash
# Assuming the plugin folder lives at wp-content/plugins/xpay-for-woocommerce
wp plugin activate xpay-for-woocommerce
```

If you still have the old XPay plugin (v2) installed, deactivate it — otherwise shoppers see two separate XPay options at checkout, and this plugin shows you an admin warning until the legacy one is gone. Settings do not carry over from v2: this plugin uses the v3 platform's keys, which you'll get in the next step.

---

## 3. Get your test keys

Log in to your XPay dashboard and go to **Developers → API keys**. You need two values, both for **test mode**:

| Field | What it looks like | Notes |
|---|---|---|
| Test secret key | `rk_test_…` | Create a **restricted key** with **Checkout Sessions** and **Refunds** access. This is a secret — never share it. |
| Test publishable key | `pk_test_…` | Used by the secure payment window in the browser. Not a secret. |

You'll create the live key set (`rk_live_…`, `pk_live_…`) later when going live — test and live are completely separate, and the plugin keeps a field for each so you never have to overwrite one with the other.

---

## 4. Configure the gateway

WP Admin → **WooCommerce → Settings → Payments** — find the **XPay** row and click **Complete setup**. (Opening XPay's settings any other way on a fresh install shows a welcome page introducing the plugin first — its **Activate XPay** button lands in the same place.)

You arrive at XPay's guided setup — three steps on one screen:

1. **Connect your test keys.** Paste your `rk_test_…` secret key and `pk_test_…` publishable key and click **Validate & save keys**. The plugin validates the key with a real API call — the header badge flips to **Connected — Test mode**. If you instead see an error about a missing key, a key/mode mismatch, or a key that did not validate, fix that before continuing: XPay stays hidden at checkout until a valid key set is saved. Saving this step also enables the gateway — there is no separate on/off checkbox to remember.
2. **Connect the webhook** — the next section of this guide walks through it.
3. **Place a test payment** — section 6.

Once your keys are in, the same screen becomes the management view: status rows for connection, webhook health and latest payment (each backed by a real check, never painted green on hope), plus every other setting — title, description, payment options, diagnostic logging. Turn **Diagnostic logging** on while you set things up: it records every step, redacted, viewable under WooCommerce → XPay Log.

For the full reference of every setting, see [CONFIGURATION.md](CONFIGURATION.md).

---

## 5. Set up the webhook

XPay confirms payments by sending your store a cryptographically signed webhook. This is what flips an order from *Pending payment* to *Processing* — without it, orders don't confirm automatically even though customers were charged. Do not skip this step.

1. Copy your store's webhook URL from the plugin settings (it's shown in the webhook-secret field description). It looks like:
   ```text
   https://your-store.example/?wc-api=xpay_webhook
   ```
2. In your XPay dashboard, go to **Developers → Webhooks** and add an endpoint pointing at that URL.
3. Subscribe the endpoint to exactly these two events:
   - `checkout.session.completed`
   - `checkout.session.expired`
4. Copy the endpoint's signing secret (`whsec_…`) and paste it into **Test webhook signing secret** in the plugin settings. Save.

The plugin verifies the signature on every delivery and rejects anything unsigned — so the secret in the plugin must be the one for this exact endpoint. When you go live later, you'll create a second, separate endpoint with its own secret for live mode. Details in [WEBHOOKS.md](WEBHOOKS.md).

---

## 6. Place your first test payment

1. Create or pick a simple product (WooCommerce → Products) with a small price.
2. Open your storefront in a private/incognito window so you're testing as a customer.
3. Add the product to the cart, go to checkout, fill in billing details.
4. Select **XPay** and click **Place order**.
5. You land on your store's payment page — a branded receipt for your order — and the secure XPay payment window opens over it. (If the window can't load, for example due to a script blocker, the page automatically continues to XPay's hosted payment page after a few seconds — same payment, never a dead end.)
6. Pay with one of XPay's **test cards** — the numbers are listed in your XPay dashboard's test-mode documentation. Test mode never charges real money.
7. Complete the payment. You're taken to the order confirmation page.

---

## 7. What success looks like

Check these three places — together they prove the whole pipeline works:

1. **The confirmation page** shows your receipt stamped **PAID** in green. (If it says **Confirming payment** instead, the webhook simply hasn't landed yet — the order will confirm as soon as it does. A receipt that *stays* unconfirmed means the webhook isn't reaching your store: recheck step 5.)
2. **The XPay dashboard** → Developers → Webhooks → your endpoint's delivery log shows the `checkout.session.completed` delivery in green with a **200** response from your store.
3. **WooCommerce → Orders** — the test order is in **Processing**, with an order note reading *"XPay payment confirmed via webhook"* followed by the payment intent id. (If the note says *"confirmed via thank-you page check"*, the payment is just as real — it means your shopper outran the webhook and the plugin's server-side re-check got there first.)

If something doesn't match, see [TROUBLESHOOTING.md](TROUBLESHOOTING.md). With diagnostic logging on, open **WooCommerce → XPay Log** — the entries usually pinpoint the issue immediately, and **Copy debug report** gives you a redacted bundle to paste into a support ticket.

---

## 8. What's next

- **Test more of the flow** — close the payment window and reopen it with the **Pay now** button; try a payment via the hosted page; if your account has valU or Fawry enabled, try the separate-options display mode ([CONFIGURATION.md](CONFIGURATION.md#checkout-display)).
- **Try a refund** — full or partial, straight from the order screen. (valU refunds are not supported by the XPay platform; the plugin tells you so explicitly.)
- **Read [GOING_LIVE.md](GOING_LIVE.md)** when you're ready to switch Mode to Live — you'll need the live key set and a second webhook endpoint.
- **Read [COMPATIBILITY.md](COMPATIBILITY.md)** if you use other plugins that touch the checkout flow (WPFunnels, caching plugins, security plugins, script optimizers).
