# Compatibility notes

Things to know when running this plugin alongside other plugins, themes, or hosts that are common sources of friction.

If a customer reports broken checkout, enable the diagnostic logger first
(**XPay gateway settings → Enable XPay flow logger**). The boot snapshot at
the top of every request flags any of the plugin classes mentioned below,
which usually narrows the hypothesis quickly. View the live tail under
**Tools → XPay Logger**.

---

## WordPress Blocks (Gutenberg) compatibility

"WP Blocks" covers two distinct contexts; this plugin handles both as
first-class citizens.

### Cart & Checkout Blocks (WC 8.3+ default checkout)

Fully supported. Implementation lives in
[`class-wc-xpay-blocks-integration.php`](class-wc-xpay-blocks-integration.php).
The plugin:

- Declares `cart_checkout_blocks` compatibility via
  `FeaturesUtil::declare_compatibility(...)` on `before_woocommerce_init`.
  Without this, WC marks the plugin as incompatible and may disable it on
  block-checkout stores.
- Registers a `WC_Xpay_Blocks_Integration` class on
  `woocommerce_blocks_payment_method_type_registration`.
- Ships a small block-side script
  ([`assets/js/blocks-integration.js`](assets/js/blocks-integration.js))
  that registers the gateway with `wc-blocks-registry`, mirrors the radio
  list from the same cached preferences helper used by classic checkout,
  and forwards the selected method as `paymentMethodData` (which WC
  blocks injects into `$_POST` so `process_payment` reads it the same way
  on both checkout types).
- Verified end-to-end against staging on every test pass — orders move
  `pending → processing` correctly via the standard webhook lifecycle.

### Block themes / Full Site Editing (FSE)

Supported. The plugin doesn't rely on any classic-theme template hooks
that would break under FSE — it integrates only via WC's standard
payment-gateway lifecycle, which is theme-architecture-independent.

Verified: Twenty Twenty-Five (default in WP 6.7+, fully block-based).

The boot snapshot logs `is_block_theme` (via `wp_is_block_theme()`) on
every render so support engineers can see the theme architecture at a
glance.

### Classic shortcode checkout (legacy)

Also supported. Tested against `[woocommerce_checkout]` end-to-end —
gateway radios render, AJAX handlers work, modal lifecycle is identical
to the block-checkout flow.

### What's NOT separately verified

- The Cart Block on the cart page (we don't add anything to it; it's
  read-only line items + totals + coupon entry — the surface is small)
- Block themes other than Twenty Twenty-Five (architecture is the same;
  if you hit an issue, the diagnostic logger will surface it via the
  `theme` and `parent_theme` fields in the boot snapshot)
- Third-party block themes from theme marketplaces

---

## Themes

Real-world theme conflicts cluster into a few patterns. Risk-categorized
below; the diagnostic logger flags the active theme on every request, so
when a merchant reports a checkout issue you can match against the table
in seconds.

### Low-risk — verified or designed for WC

| Theme | Status | Notes |
|---|---|---|
| **Storefront** (WC's official theme) | Designed for WC | No known issues |
| **Twenty Twenty-Five** | Verified end-to-end | Block theme, default in WP 6.7+ |
| **Twenty Twenty-Four / -Three / -Two** | Same architecture as TT-25 | Should work; not specifically tested |
| **Astra** | Architecturally compatible | Widely used with WC |
| **Hello** (Elementor's barebones) | Minimal, no jQuery overrides | Fine |
| **Genesis Framework children** | Use standard WC templates | Fine |

### Medium-risk — usually fine, watch for

| Pattern | Risk | What to watch for in the log |
|---|---|---|
| Themes that deregister and re-register jQuery with a different version | Some performance-optimization themes; some custom builds | `js.compat_warning` event with `jquery_below_3:...` or `jquery_missing` (the modal still works — these warnings are informational) |
| Themes with aggressive CSS resets (Tailwind-based, minimal "starter" themes) | Modal styling may render wrong but flow still works | No log signal — visual inspection only |
| Themes with custom checkout templates that override `checkout/form-checkout.php` | If the override doesn't call `wp_head()`/`wp_footer()`, our scripts may not enqueue | Missing `payment_fields.render` log entry on a checkout page that loaded — confirms the theme bypassed our script enqueue |

### High-risk — known to cause issues

| Pattern | Examples | What breaks | Mitigation |
|---|---|---|---|
| **Themes loading their own jQuery** | Some heavy ThemeForest themes | Other XPay JS (installment selector, promo code) may misbehave; modal itself still works | Modal CSS+JS is jQuery-independent as of 1.3.0; warning logged via `js.compat_warning` |
| **Themes that lazy-load iframes** | Themes with built-in lazy-load using non-standard opt-out classes | Iframe doesn't load until scrolled into view; customer never sees the payment form | The iframe ships with `class="no-lazy skip-lazy"` (the two most common opt-out conventions) plus `loading="eager"` semantics. Themes using a different opt-out class need a merchant-side tweak |
| **Strict Content-Security-Policy headers** | Security-hardened bespoke themes; some enterprise distributions | XPay iframe blocked by browser's CSP enforcement | Merchant must add `frame-src https://*.xpay.app` to their site CSP |
| **Page builders with their own form handling** | Divi, Avada, Bricks, Oxygen | Builder may intercept the WC checkout submit; our `process_payment` never runs | Use the page builder's WC integration mode (each builder has one); test before launch |
| **Themes that render the cart/checkout via custom templates without standard WC hooks** | Some bespoke themes | Our script enqueue may not fire; modal markup never rendered | Have the theme author call `wc_get_template()` instead of inlining template HTML |

### Bootstrap version conflicts (RESOLVED in 1.3.0)

Previous versions of the plugin loaded Bootstrap 3.4.1 from a public CDN
to drive the payment modal. Themes loading Bootstrap 4 or 5 caused
selector conflicts (BS3 uses `data-toggle="modal"`, BS4+ use
`data-bs-toggle="modal"`) — symptoms ranged from "modal won't open" to
"modal won't close cleanly".

**As of plugin version 1.3.0**, the modal is implemented in vanilla
CSS+JS, scoped under the `xpay-modal*` class namespace. There is no
remaining Bootstrap dependency. The plugin is now safe on themes loading
Bootstrap 3, 4, or 5 (or none of them).

### How to diagnose a theme conflict

1. Enable the diagnostic logger (gateway settings → Diagnostic logger).
2. Reproduce the issue.
3. WP Admin → Tools → XPay Logger → look at the most recent `boot` entry.
   The `theme` and `parent_theme` fields name the active theme and its
   parent (if any).
4. Look at `boot.hooks_inventory` — any non-XPay callbacks listed under
   `the_title`, `woocommerce_thankyou`, or `woocommerce_checkout_process`
   from a `wp-content/themes/...` source path are candidates for the
   conflict.
5. If the modal won't open, look for `modal.client_event js_error`
   entries — uncaught JS errors captured from the page often pinpoint
   the offending script.
6. If the modal opens but the iframe is blank, check browser DevTools
   console for CSP violations and look for an `iframe.blocked` log entry
   (means our host allowlist refused the URL).

If a theme conflict is found that isn't in this list, file a support
ticket with the log file attached and we'll add it.

---

## Funnel builders

### WPFunnels (confirmed)

WPFunnels filters `woocommerce_get_checkout_order_received_url` to inject
its own funnel-routing query params (`?wpfnl-order=N&wpfnl-key=K`). The
intent is for WPFunnels Pro's upsell handler to consume those params and
render an upsell page.

**Symptom on WPFunnels Free** (or Pro without an upsell step configured for
the funnel): customers paying via XPay land on `/cart/` instead of a
"Thank you for your order" page. The order is correctly recorded as
`processing` and the merchant receives the new-order email, but the
customer gets no visible confirmation and may attempt to pay again.

**Fix**: turn on **WPFunnels compatibility → Force standard order-received
page after payment** in the gateway settings. The plugin restores the
standard WooCommerce `/checkout/order-received/{id}/` URL for XPay orders
only. WPFunnels' rewrite still applies to non-XPay orders. Leave the
setting OFF if you have a working WPFunnels Pro upsell flow you want
XPay customers to enter.

The plugin shows an admin notice nudging you to enable the setting
whenever WPFunnels is detected and the setting is off. Dismissing the
notice is per-user and persistent.

### CartFlows / FunnelKit Funnels

Same family as WPFunnels and almost certainly share the same URL-rewrite
pattern. We have not concretely tested. If you hit the same `/cart/` bounce
symptom: open a support ticket and we'll add a sibling shim. The diagnostic
logger's boot snapshot will flag both via the `cartflows_active` and
`funnelkit_active` fields.

---

## Caching plugins

### WP Rocket / LiteSpeed Cache / W3 Total Cache

All three exclude `/checkout/`, `/cart/`, and `/my-account/` from page
caching by default — so this is a latent risk, not an active bug. If a
merchant explicitly cache-includes those paths, the inline
`wp_create_nonce('xpay-installments')` in our payment-fields render gets
served stale; once the cached version outlives the 12-hour nonce TTL, the
installment AJAX returns 403.

**If you intentionally cache checkout pages**, turn off page cache for any
URL matching `/checkout/order-pay/*` at minimum, and ideally `/checkout/`
too. The diagnostic logger flags the active caching plugin in every boot
snapshot under `caching_plugin`.

---

## Security plugins

### Wordfence / Sucuri / iThemes Security

Their default WAF rule sets sometimes block direct requests to PHP files
inside `/wp-content/plugins/`. Our webhook receiver lives at
`/wp-content/plugins/xpay-for-woocommerce/update_order.php` (or whatever
folder name the plugin is installed under — the exact path is shown in
WC → Settings → Payments → Xpay → Manage as the **Callback URL**).
If Wordfence (etc.) blocks it, XPay's webhook returns 403 and the order
is stuck in `pending` even after the customer paid.

**Fix**: whitelist the callback URL shown in the plugin settings in your
security plugin's WAF rules. If that's not possible, we can ship the
webhook as a `register_rest_route` endpoint at `/wp-json/xpay/v1/webhook`
— REST endpoints are rarely WAF-blocked. File a request and we'll
prioritize.

---

## Cookie consent banners

### CookieYes / Complianz / Borlabs Cookie

If the consent banner is configured to block third-party iframe cookies on
first load, the XPay payment iframe (which needs cookies for the 3DS
challenge round-trip) can fail silently. The customer sees a blank or
unresponsive iframe.

**Fix**: in your consent plugin's allowlist, add `staging-payment.xpay.app`
and `community.xpay.app` (and `staging.xpay.app`, `new-dev.xpay.app` for
non-production environments).

---

## Hosts

### EasyWP (Namecheap managed) — RESOLVED

`curl_exec()` is disabled by default. The original plugin called it
directly; we now use `wp_remote_post()` everywhere, which goes through
WordPress's HTTP API and works on EasyWP. No action required.

### Cloudflare in front of the merchant's site — RESOLVED

Cloudflare's Bot Fight Mode blocks our preferences-endpoint call when it
sees the default WordPress user-agent. We override the UA in
`utils.php` to a browser-like string. No action required.

### WP Engine / Kinsta / Pantheon

Some managed hosts cap `max_execution_time` to 5–10s. The XPay pay call
timeout is 25s and the prepare-amount call has a 1s retry budget — worst
case ~50s. If your host caps PHP under 30s, you may see timed-out pay calls
on slow XPay responses.

**Diagnostics**: the boot snapshot logs `php_max_execution`. If that value
is below 30 on your environment and you're seeing timeouts, file a support
ticket and we'll add a host-detection branch with shorter retry budgets.

---

## Page builders other than Gutenberg

### Divi / Beaver Builder / Bricks / Oxygen

Each builder has its own checkout-page rendering and may intercept WC
gateway hooks differently. Validated checkout paths so far:

- Block (Gutenberg) checkout — primary tested path
- Classic shortcode (`[woocommerce_checkout]`)
- WPFunnels Gutenberg-based checkout step (with the compat shim above)

For any other builder, the diagnostic logger will flag it in the boot
snapshot's `page_builder` field. Reproduce the issue with the logger on,
download the log, and the boot snapshot plus per-stage timings will
usually point at the offending hook. See the **How to diagnose a theme
conflict** recipe under [Themes](#themes) above — the same approach
applies to page builders.
