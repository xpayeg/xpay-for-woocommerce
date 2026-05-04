=== XPay for WooCommerce ===
Contributors: xpay
Tags: woocommerce, payments, payment gateway, egypt, fawry
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Card, Fawry, valU, Apple Pay, Wallets, and NBE Installments on your WooCommerce store via XPay (Egypt).

== Description ==

Adds [XPay](https://xpay.app/) as a payment method on any WooCommerce store. Egyptian merchants can accept payments via:

* **Card** (3DS-protected, multiple Egyptian banks supported)
* **Fawry** (kiosk and mobile-app payment)
* **valU** (consumer credit / installments)
* **Apple Pay** (with merchant domain association)
* **Wallets** (Meeza Digital, mobile money)
* **NBE Installments** (National Bank of Egypt installment plans)

The plugin supports the classic shortcode checkout, the new block-based Cart/Checkout (WC 8.3+), and High-Performance Order Storage (HPOS). It also includes a built-in diagnostic logger to help support engineers triage checkout issues quickly.

= Key features =

* Embedded payment iframe (no off-site redirect for cards)
* Server-side promo code validation (with WC cart-fee integration so totals reconcile)
* Modal lifecycle: poll → confirm → countdown → redirect, with manual-close fallback
* Diagnostic logger with redaction (PII and secrets stripped at write time)
* Compatibility shims for WPFunnels and other third-party checkout builders
* HPOS-compatible order lookup
* Block checkout support via Cart/Checkout Blocks integration

= Requirements =

* WordPress 6.0+
* WooCommerce 8.3+ (for HPOS and block-checkout support)
* PHP 7.4+ (PHP 8.0+ recommended)
* An XPay merchant account — sign up at <https://xpay.app/>
* HTTPS enabled on your site (required for production)

== Installation ==

1. Install the plugin from WordPress Admin → Plugins → Add New (search for "XPay for WooCommerce"), or upload the plugin ZIP via WP Admin → Plugins → Add New → Upload Plugin.
2. Activate the plugin through the WP Admin → Plugins menu.
3. Go to WooCommerce → Settings → Payments → Xpay and fill in your XPay community ID, payment API key, and variable amount template ID. Default Environment is **Staging** — leave it there for initial testing.
4. Copy the callback URL displayed in the gateway settings and paste it into the corresponding field on your XPay dashboard.
5. Place a test order with a staging test card. The order should move from `pending` to `processing` automatically within ~10 seconds.
6. When ready, swap to production credentials and switch Environment to **Production**.

== External services ==

This plugin connects to XPay's payment API to process payments and webhooks. **It will not function without this connection** — accepting payments is the entire purpose of the plugin.

= Service: XPay Payment Gateway =

* **Provider:** XPay (XPay for Online Payments S.A.E., Egypt) — <https://xpay.app/>
* **What the plugin sends:**
  * **On checkout (every payment attempt):** order ID, payment amount, currency, customer billing name + email + phone number, selected payment method, your community ID, your payment API key (in the request header for authentication), and (if applicable) the promo code the customer entered.
  * **During promo-code validation:** the promo-code string, current cart amount, currency, payment method, customer phone number.
  * **For payment-method discovery (cached 5 min):** your community ID and API key.
  * **During modal-close transaction-status check:** the transaction UUID + your community ID + API key.
* **What the plugin receives:**
  * **From `pay/variable-amount`:** an iframe URL where the customer enters card details, plus a transaction UUID.
  * **From the webhook (`update_order.php`):** transaction UUID + status (`SUCCESSFUL` / `FAILED`), used to mark the order paid or failed.
  * **From the polling endpoint (`check_transaction.php`):** current transaction status.
* **API endpoints used:**
  * Staging: `https://staging.xpay.app/api/...`
  * Production: `https://community.xpay.app/api/...`
* **When data is sent:** Only when a customer completes one of: checkout submission, promo-code validation, modal-close status check, or when XPay's servers POST a payment-confirmation webhook to your site.
* **Why:** Required to authorize and capture payments, validate merchant-defined promo codes, and confirm payment completion server-to-server.
* **XPay API documentation:** <https://xpayeg.github.io/docs/>
* **XPay Terms of Service:** <https://xpay.app/terms> (verify with XPay for the current URL)
* **XPay Privacy Policy:** <https://xpay.app/privacy> (verify with XPay for the current URL)

By using this plugin, you (the merchant) and your customers (whose checkout details flow through XPay) become subject to XPay's terms of service and privacy policy. Please review them and update your own store's privacy policy accordingly before deploying to production.

== Privacy ==

This plugin does NOT introduce any tracking, analytics, telemetry, or "phone-home" beyond the operational XPay API calls described above.

= What is logged locally =

When the optional diagnostic logger is enabled (off by default), per-payment events are written to `wp-content/uploads/xpay-logs/` for support troubleshooting. Logs include order IDs, transaction UUIDs, payment-method names, response timings, and the path of the upstream call. **Secrets (API keys, signature headers, Bearer tokens, full PAN-style digit runs) are redacted at write time.** Logs rotate automatically with 30-day retention and can be cleared from WP Admin → Tools → XPay Logger.

= What is sent to XPay =

See "External services" above for the complete list. In short: the same data the customer types into your checkout (name, email, phone, amount, currency, payment method, promo code).

= No third-party CDN dependencies =

The payment modal is a vanilla CSS + JavaScript implementation bundled with the plugin. The plugin does NOT load any external script or stylesheet from a CDN.

== Frequently Asked Questions ==

= Does the plugin support refunds? =

Not from inside WooCommerce. XPay's API does not currently expose a refund endpoint, so refunds must be issued from the XPay merchant dashboard.

= Does it support High-Performance Order Storage (HPOS)? =

Yes. The plugin declares HPOS compatibility and uses HPOS-aware order lookups in the webhook receiver.

= Does it support the new block-based checkout? =

Yes. A Cart/Checkout Blocks integration is included and registered automatically when the block-based checkout is in use.

= Where are the diagnostic logs stored? =

In `wp-content/uploads/xpay-logs/`. Files are rotated automatically (30-day retention). Secrets and PII are redacted at write time. The log can also be downloaded from WP Admin → Tools → XPay Logger.

= My checkout is on WPFunnels — does it work? =

Yes. A WPFunnels compatibility shim is bundled and can be enabled from the gateway settings.

= What payment methods are supported? =

Card, Fawry, valU, Apple Pay, Meeza Digital Wallets, and NBE Installments — subject to which methods XPay has enabled for your merchant community.

= What currency is supported? =

EGP (Egyptian Pound) is the supported currency.

= I previously used the "WooCommerce XPAY Gateway" plugin. Will my settings carry over? =

Yes. The gateway settings option key (`woocommerce_xpay_gateway_settings`) is unchanged across the rename, so deactivating the old plugin and activating "XPay for WooCommerce" preserves your community ID, API key, environment, and other configuration. The old plugin's directory (`wp-content/plugins/woocommerce-xpay-plugin/`) can be deleted after the new plugin is active.

== Screenshots ==

1. Checkout payment-method picker (classic checkout).
2. Embedded payment modal with 3D Secure card flow.
3. Diagnostic logger admin page (Tools → XPay Logger).
4. Gateway settings (WooCommerce → Settings → Payments → Xpay).

== Changelog ==

= 2.0.1 =
* Fixed: payment modal now stops polling and shows a clear failure message when the upstream transaction returns FAILED or when the order is no longer payable (cancelled, refunded, or invalid). Previously the modal polled silently every 10 seconds for as long as the customer left the page open — observed cases polled for 2+ hours after the order was auto-cancelled by WooCommerce, with the customer never seeing why the payment was not progressing. Polling continues unchanged for PENDING and unknown intermediate statuses.
* Added: new client log event `terminal_state` (under `modal.client_event`) so the diagnostic log records when the modal stops on a terminal status.

= 2.0.0 =
* Renamed plugin from "WooCommerce XPAY Gateway" to "XPay for WooCommerce" for WordPress.org plugin directory compliance.
* New plugin slug: `xpay-for-woocommerce` (was `woocommerce-xpay-plugin`).
* New text domain: `xpay-for-woocommerce` (was `wc-gateway-xpay`). All translatable strings updated.
* Added `readme.txt` with all WP.org-required headers.
* Added GPL-2.0-or-later license declaration in plugin header.
* Added `External services` and `Privacy` disclosure sections.
* Added bundled `/languages/xpay-for-woocommerce.pot` template (114 strings) for translators.
* Webhook verification now reads `secret_key` from the JSON body (XPay's actual scheme — top-level field on observed production webhooks; also accepted nested under `extra_details.secret_key` for forward-compat with XPay's published examples) and constant-time-compares it against the configured `webhook_secret`. Earlier 2.0.0 builds checked an `X-XPay-Signature` HMAC header that XPay's production webhooks don't send; with the secret configured, every real signed webhook was rejected and orders only completed via the modal-poll fallback.
* When `webhook_secret` is configured but no matching value arrives in the body, the webhook is rejected with HTTP 401 instead of silently accepted.
* Added `wp_cache_add` lock around `payment_complete()` in both the webhook receiver and the modal-close poll, preventing double-fire of `woocommerce_payment_complete` when the two paths race.
* Tightened prepare-amount call: dedicated 20-second timeout and zero retries, keeping worst-case checkout request budget under typical PHP-FPM `request_terminate_timeout`.
* Added transient-backed circuit breaker (5 consecutive failures → 60-second fail-fast window) to prevent PHP-FPM saturation when XPay is degraded.
* Replaced 3 panic-inducing customer-facing error strings with reassurance-first copy ("your card has not been charged", "your previous payment attempt is still in progress", "we could not reach the payment provider").
* Renamed all global functions to `xpay_*` prefix (`httpPost` → `xpay_http_post`, `httpGet` → `xpay_http_get`, `generate_payment_modal` → `xpay_generate_payment_modal`, etc.).
* Renamed all AJAX action names to `xpay_*` prefix.
* Wrapped webhook receiver and poll endpoint in IIFE for proper variable scoping.
* Replaced `unlink()` with `wp_delete_file()` in logger.
* Block checkout: installment selected without a period now correctly returns an error instead of silently submitting an empty plan.
* Defensive null-guard for `WC()->cart` in inline JS (covers WPFunnels and other non-standard checkout contexts).
* Removed the legacy debug helper `jsprint()` (unused).
* Internal: many phpcs/PCP fixes — pluginversion constant, escape calls, unslash on `$_SERVER` reads, translators comments, etc.

= 1.3.1 =
* Fixed: payment modal now exposed correctly to assistive tech (`aria-hidden` placement).
* See `CHANGELOG.md` for full version history.

= 1.3.0 =
* Replaced Bootstrap 3 modal dependency with vanilla CSS+JS modal.
* Added jQuery sanity check on modal init.
* Added `referrerpolicy="strict-origin-when-cross-origin"` on the payment iframe.
* Added accessibility attributes (`aria-modal`, `aria-labelledby`, `aria-hidden`) on modal markup.

= 1.2.1 =
* Security: promo discount no longer trusted from client (`xpay_handle_validate_promo_code` writes session atomically).
* Security: iframe URL host validated against `*.xpay.app` allowlist.
* Fixed: WC order totals now reflect XPay promo discount via cart-fee hook.

= 1.2.0 =
* Added: diagnostic flow logger with admin Tools page.
* Added: WPFunnels compatibility shim with admin notice.
* Hardened: webhook signature verification (configurable strict mode).

For older versions and full release notes, see the bundled `CHANGELOG.md` in the source repository.

== Upgrade Notice ==

= 2.0.1 =
Bug fix: the payment modal now handles failed and cancelled-order states correctly instead of polling silently forever. Recommended for all merchants.

= 2.0.0 =
Plugin renamed to XPay for WooCommerce. Merchants on the old `woocommerce-xpay-plugin` directory: deactivate the old plugin and install this one. Gateway settings carry over automatically. Includes security and reliability improvements; see the changelog for details.

= 1.3.1 =
Accessibility fix for the payment modal. No setting changes required. Hard-refresh after upgrade to clear cached modal markup.
