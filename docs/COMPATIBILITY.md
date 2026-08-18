# Compatibility notes

Things to know when running this plugin alongside other plugins, themes, or hosts that are common sources of friction.

The short version: v3 was rebuilt so that the conflict classes that dogged the v2 plugin **cannot occur by construction**. Payment happens in the XPay drop-in window over WooCommerce's own order-pay page — the plugin renders no iframe, no modal markup of its own, loads no Bootstrap, and uses no jQuery anywhere. What remains conflict-prone is a much shorter list, covered below.

If a shopper reports a broken checkout, turn on **Diagnostic logging** (WooCommerce → Settings → Payments → XPay) and reproduce — [TROUBLESHOOTING.md](TROUBLESHOOTING.md) maps the log entries to fixes.

---

## Built-in immunities

Facts about how v3 is built, each of which retires a whole category of conflict:

| Property | Why it matters |
|---|---|
| **HPOS and Cart/Checkout Blocks compatibility formally declared** (`FeaturesUtil::declare_compatibility` in [`xpay-for-woocommerce.php`](../xpay-for-woocommerce.php)) | WooCommerce never flags the plugin incompatible or disables it on HPOS/block-checkout stores |
| **Zero jQuery** — every script ([`checkout-modal.js`](../assets/js/checkout-modal.js), [`blocks-integration.js`](../assets/js/blocks-integration.js), the admin log viewer) is dependency-free plain JS | Themes that deregister, replace, or downgrade jQuery cannot break payment |
| **readyState-safe script boot** plus optimizer opt-out attributes on the payment-critical script tags ([`class-xpay-script-guard.php`](../includes/compat/class-xpay-script-guard.php)) | Late or reordered script execution doesn't strand the pay page; see [JS optimizers](#js-optimizers-and-caching-plugins) |
| **No server-rendered iframe, no modal markup** — the XPay SDK draws its own window | Iframe lazy-loaders, iframe height hacks, and modal-framework selector clashes have nothing to grab |
| **The SDK window pins itself above everything** (maximum z-index), and while it's open the plugin dims the page beneath it with a scrim one layer below | No theme header, sticky bar, or chat widget can cover the payment window or its 3DS challenges |
| **Webhook receiver at `/?wc-api=xpay_webhook`** — a standard WordPress front-controller route, not a direct plugin file | Security plugins that block direct access to PHP files under `wp-content/plugins/` don't affect it |
| **WooCommerce core marks the checkout page non-cacheable, and order-pay lives on it**; the plugin puts no nonces in any shopper-facing HTML | Page caches can't serve a stale payment page or an expired nonce |
| **A per-order lock serializes every payment transition** ([`class-xpay-order-lock.php`](../includes/gateway/class-xpay-order-lock.php)) | Duplicate webhook deliveries or a webhook racing the thank-you check can't double-fire emails, stock reduction, or anything else listening to WooCommerce's payment hooks |
| **Each order is charged in its own currency** — the checkout session is minted from `$order->get_currency()`, and an amount/currency disagreement parks the order on-hold instead of mis-marking it paid | Currency-switcher plugins are safe by design |
| **All URLs are built through WooCommerce's own endpoint builders** (`get_checkout_order_received_url()`, `wc_get_endpoint_url()`, …) | WPML / Polylang / any plugin that filters WooCommerce URLs sees and translates them normally |
| **All server-to-server HTTP goes through the WordPress HTTP API** (`wp_remote_request`) | Hosts that disable raw `curl_exec()` — the bug that broke v2 on EasyWP — are unaffected |

---

## WooCommerce checkout contexts

### Cart & Checkout Blocks (WC 8.3+ default checkout)

Fully supported. Implementation lives in [`class-xpay-blocks-support.php`](../includes/blocks/class-xpay-blocks-support.php) plus a small build-less registration script ([`blocks-integration.js`](../assets/js/blocks-integration.js)). One payment-method row is registered per active XPay row (the combined row, or one per ticked method in split mode), mirroring exactly what classic checkout shows — the two checkouts can never disagree about which rows exist.

The heavy lifting deliberately does **not** happen inside the checkout form: after Place Order, Blocks' standard redirect flow carries the shopper to the order-pay page, where the XPay window opens. One flow for classic checkout, block checkout, and admin-created pay links alike.

### Classic shortcode checkout (`[woocommerce_checkout]`)

Supported, same flow: the gateway row renders, Place Order redirects to the order-pay page, the XPay window opens there.

### Block themes / Full Site Editing

Supported. The plugin integrates only through WooCommerce's gateway lifecycle and standard hooks; the confirmation receipt renders on `woocommerce_before_thankyou`, which the block-based Order Confirmation replays, so it appears on both the classic thank-you template and block themes.

### What's NOT separately verified

- The Cart Block on the cart page (the plugin adds nothing to it)
- Third-party marketplace block themes — architecture is the same; if something looks off it will be CSS, see the theme tiers below

---

## Themes

Because v3 ships no modal markup, no iframe, and no jQuery, theme risk now concentrates in exactly two places: **theme CSS bleeding into the receipt pages** (the order-pay receipt and the stamped confirmation receipt) and **theme-bundled JS optimizers** (covered in the next section). Risk tiers:

### Low risk — verified or designed for WooCommerce

| Theme | Status |
|---|---|
| **Storefront** | Designed for WC; no known issues |
| **Twenty Twenty-Five** (and the other block-based defaults) | Standard hooks and templates; no known issues |
| **Astra**, **Hello**, **Genesis children** | Standard WC templates; no known issues |

### Medium risk — usually fine, watch the receipt pages

| Pattern | What can happen | What the plugin already does |
|---|---|---|
| Aggressive CSS resets or utility-first themes | Receipt spacing/typography renders off | Every rule is namespaced (`.xpay-pay__…`, `.xpay-ty__…`) and image/button rules use doubled selectors (e.g. `.xpay-pay .xpay-pay__mark`) to outrank WooCommerce's and most themes' broad rules without `!important` |
| Themes styling `.woocommerce img` or buttons globally | Logos/badges inflate, button restyled | Same doubled-selector defense; purely cosmetic if it slips through |
| Dark themes | The page behind the payment window too busy during 3DS | The plugin paints its own scrim under the SDK window while it's open |

Receipt glitches are cosmetic only — the XPay window and the payment flow don't depend on theme CSS.

### High risk — can actually interfere with payment

| Pattern | What breaks | Mitigation |
|---|---|---|
| **JS delay/defer optimizers** (theme-bundled or plugin) | The window doesn't open until the shopper interacts, or at all | Opt-out attributes are stamped on the payment scripts (below); worst case the page auto-continues to XPay's hosted checkout after ~6 seconds, so the shopper is never stranded |
| **Strict Content-Security-Policy headers** | The browser blocks the SDK script from `checkout.xpay.app` | Allow XPay's hosts in your CSP (at minimum the SDK script from `https://checkout.xpay.app`); until then, the ~6s hosted-checkout fallback carries shoppers through |
| **Funnel/page builders that reroute the after-payment page** | Shopper misses the order-received page entirely | See [Funnel builders](#funnel-builders) — this is a routing issue, not a rendering one |

### A note on history

The v2 plugin fought two theme wars that shaped v3: Bootstrap-version conflicts (its modal was Bootstrap-based; themes loading BS4/5 broke it) and iframe lazy-loading (themes lazy-loaded its payment iframe into invisibility). Both categories are gone, not patched: v3 renders no modal markup and no iframe of its own, so there is nothing for a theme's modal framework or lazy-loader to interfere with.

---

## JS optimizers and caching plugins

**Page caching** is a non-issue by default: WooCommerce itself excludes the checkout page from caching, the order-pay step lives on that page, and the plugin puts no nonces in checkout HTML. If you've overridden your cache to include checkout URLs, exclude `/checkout/order-pay/*` again.

**JS optimization** is the real interaction. Rocket Loader, WP Rocket's delay/defer, LiteSpeed, Autoptimize, Perfmatters and friends rewrite script tags to postpone execution — on the pay page that turns "the XPay window opens by itself" into "nothing happens until the shopper wiggles the mouse". The plugin stamps every documented opt-out attribute (`data-cfasync="false"`, `nowprocket`, `data-no-optimize`, `data-noptimize`, `data-no-defer`) onto its two payment-critical script handles — `xpay-checkout-modal` and `xpay-blocks` — and nothing else, so the rest of the page stays the optimizer's to improve.

Most setups honor those attributes. If yours doesn't (some aggressive "delay all JS" presets ignore opt-outs), exclude the two handles or `/checkout/order-pay/` URLs in the optimizer's own settings. Either way the shopper is never dead-ended: if the SDK can't start within ~6 seconds, the page redirects to XPay's hosted checkout for the same session.

---

## Funnel builders

### WPFunnels (confirmed)

WPFunnels filters `woocommerce_get_checkout_order_received_url` to rewrite the after-payment URL into its funnel-routing format (`?wpfnl-order=N&wpfnl-key=K`), expecting WPFunnels Pro's upsell handler to consume it. On WPFunnels Free — or Pro without an upsell step on that funnel — the rewritten URL falls through to an empty-cart guard and the shopper bounces to `/cart/` with no confirmation, often trying to pay again. The order itself is fine; only the shopper-facing landing breaks.

**Fix:** tick **Force the standard order-received page after payment** in the XPay settings (shown under "WPFunnels compatibility"). The shim ([`class-xpay-wpfunnels-compat.php`](../includes/compat/class-xpay-wpfunnels-compat.php)) then restores the standard order-received URL — for **XPay orders only**, and only when the URL actually carries WPFunnels' `wpfnl-order` fingerprint. It runs at filter priority 20, after WPFunnels' own rewrite at 10, so non-XPay orders and merchants with a real Pro upsell flow keep WPFunnels' routing untouched. Leave the setting off if XPay customers should enter your working Pro upsell flow.

While WPFunnels is active and the setting is off, the plugin shows an admin notice nudging you to enable it. Dismissal is per-user and persistent (it survives cache flushes). Each restored URL is logged as `compat.wpfunnels_url_restored`.

**Whatever you decide about routing:** a funnel with a custom thank-you step bypasses the order-received page, and with it the plugin's server-side re-verification — leaving the webhook as the **only** thing that confirms orders. Treat a failing webhook as a launch blocker; [GOING_LIVE.md](GOING_LIVE.md#funnel-builders-make-the-webhook-mandatory) covers this in detail.

### CartFlows / FunnelKit Funnels

Same family as WPFunnels and likely to share the same URL-rewrite pattern, but **we have not tested them and there is no shim for them yet** — the WPFunnels shim keys on WPFunnels' own fingerprint and won't fire for them. If you hit the same after-payment bounce with either: open a support ticket with the order number and the URL the shopper landed on, and we'll add a sibling shim on the first concrete report.

---

## The legacy XPay plugin (v2)

The retired "WooCommerce XPAY Gateway" plugin coexists with this one without fatals — different class names, different gateway id — which is exactly the trap: a store running both quietly shows **two separate XPay options at checkout**, two settings screens, and the legacy webhook endpoint. This plugin shows a persistent admin notice while the legacy plugin is detected; deactivate the legacy plugin once v3 is configured.

Settings are deliberately not migrated: v2 stored v2-API credentials, and this plugin authenticates with the v3 API's `rk_…`/`pk_…` keys. Get fresh keys from the XPay dashboard rather than copying old values.

---

## Security plugins

### Wordfence / Sucuri / Solid Security (iThemes)

The v3 webhook receiver is a normal WordPress request (`https://<store>/?wc-api=xpay_webhook`, routed through `index.php`), so the common WAF rule that blocks direct requests to PHP files inside `wp-content/plugins/` — which broke the v2 plugin's file-based receiver — does not apply.

What can still bite: rate limiting or country/IP blocking applied to all front-end traffic. The tell is 403s in the **XPay dashboard's webhook delivery log** with nothing in the XPay Log (the request died before WordPress). Allowlist the webhook URL in the security plugin. The receiver's own responses are only ever the statuses documented in [WEBHOOKS.md](WEBHOOKS.md#http-status-contract-drives-xpays-retry-engine); anything else came from a layer in front of it.

---

## Cookie consent banners

The payment window is XPay-hosted (`sdk.js` from `checkout.xpay.app`), so a consent tool configured to block third-party scripts until the shopper accepts can delay or block it — in which case the pay page auto-continues to XPay's hosted checkout after ~6 seconds, and the shopper can still pay. To keep the on-site window for everyone, allowlist `checkout.xpay.app` as strictly necessary in your consent tool.

---

## Hosts

No known host-specific issues. Two properties do the heavy lifting:

- All API calls use the WordPress HTTP API — hosts that disable `curl_exec()` (the v2-era EasyWP failure) work out of the box.
- Server-to-server calls run with a 30-second HTTP timeout; the one API read that blocks a shopper-facing page (the thank-you re-check) is capped at 5 seconds and fails open to the "Confirming payment" receipt, with the webhook and its ~3-day retry window as the safety net.

The only host-level dependency worth knowing: the per-order lock uses MySQL's `GET_LOCK`. On the rare stack where that's unavailable, the plugin logs `order_lock.unavailable` and proceeds with its fallback guards instead of failing payments — see [TROUBLESHOOTING.md](TROUBLESHOOTING.md#stage-reference).

---

## Multilingual and multi-currency

- **WPML / Polylang:** all checkout and confirmation URLs are built with WooCommerce's own endpoint builders, which these plugins filter and translate normally. The plugin ships an Arabic translation, the receipt pages include RTL styles, and the XPay window opens in Arabic whenever the WordPress locale is Arabic.
- **Currency switchers:** each checkout session is created in that order's own currency, and confirmation cross-checks the charged amount *and* currency against the order — a disagreement parks the order on-hold with both numbers in an order note rather than marking it paid. Keep the store on currencies XPay supports (EGP recommended; settlement is in EGP).
