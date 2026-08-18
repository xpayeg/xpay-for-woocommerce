# Changelog

All notable changes to this plugin are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

---

## [3.0.0] — Unreleased

Major version: complete rebuild against the XPay v3 platform (Checkout Sessions, signed webhooks, API refunds). The v2 community-API integration is removed entirely — a clean break, per the no-backward-compatibility rule now that the v3 API is the platform's forward path and the v2 API's Cloudflare WAF instability (documented in 2.0.1's Notes) made it unreliable in production.

### Added

- **On-site payment window.** `process_payment` redirects to WooCommerce's own order-pay page, where the XPay drop-in modal (`sdk.js` from checkout.xpay.app) opens over the store. One flow serves classic checkout, Blocks checkout, and admin pay links — and the pay page doubles as the retry surface. If the SDK cannot load within 6 seconds, the shopper is sent to the hosted checkout page for the same session: no dead ends.
- **Signed webhook receiver** at `?wc-api=xpay_webhook`: HMAC-SHA256 `XPay-Signature` verification (constant-time, 300s replay window, fail-closed), per-order event dedupe, and an ownership check requiring the event's session id to match the id stored on the order. Response codes follow the caller's-fault-4xx / our-fault-5xx rule so XPay's ~3-day retry engine behaves correctly against a half-configured plugin.
- **Refunds** (full and partial) from the order screen via `POST /refunds`, recorded in WooCommerce only when the API answers `SUCCEEDED`. Deliberately no client-side lock: the platform serializes refunds per charge and re-validates the remaining refundable amount inside its own critical section, so over-refunding is impossible regardless of what any client does.
- **Cart & Checkout Blocks** integration and **HPOS** compatibility declaration (`before_woocommerce_init`).
- **Money helper** doing string-based minor-unit conversion (no float arithmetic on amounts; 3-decimal currencies handled).
- **Redacting logger** rebuilt on `WC_Logger` with the v2 two-tier taxonomy plus a value-shape PAN scrub and v3 key-prefix patterns (`sk_`/`rk_`/`pk_`/`whsec_`).
- **Customer linking.** Logged-in shoppers are linked to a persistent XPay Customer: the first paid session creates one (customerCreation=always) and its cus_… id is stored per mode in user meta; later checkouts send customerId so payments group under one customer in the merchant's XPay dashboard and fraud enrichment accumulates on a stable identity. A stale stored id (customer deleted in the dashboard) is detected on session create, cleared, and retried once as a fresh customer. Guests are deliberately left to the platform's own if_required + fingerprint dedupe. Groundwork for saved cards/subscriptions (Phase 5), which require customer objects.
- **In-admin log viewer** (WooCommerce → XPay Log): filterable tail of a bounded custom table (14-day / 10k-row retention, daily prune), a one-click redacted **Copy debug report** for support tickets, a per-order XPay panel on the order screen showing that order's payment story, and a nonce-guarded Clear action. Rows are redacted at write time and nothing is ever transmitted anywhere — the merchant pastes manually.
- **Standards tooling**: AGENTS.md engineering standard, PHPCS (WordPress-Extra + PHPCompatibilityWP), PHPUnit suite for the pure classes (37 tests), and CI that gates every PR on phpcs + Plugin Check + PHPUnit.
- **Per-method checkout rows.** A "Checkout display" setting offers XPay as one combined option (default) or as separate Card / valU / Fawry rows with method logos, each opening the payment window directly on that method via the session's `paymentMethodTypes` pin. The Card row shows the accepted networks (Visa, Mastercard, Meeza — Amex is not accepted); logos are XPay's own artwork shipped locally (wp.org forbids CDN assets); Fawry stays text-labeled until design provides the official mark. The merchant declares offered methods with checkboxes because restricted keys cannot read the account's enabled set yet — a shopper picking a method the account lacks falls back to the full XPay window (fail-open for the shopper) while the merchant gets an admin notice and a log entry. Session reuse compares the stored method pin, so switching rows mints a fresh session instead of reusing one pinned to the wrong method.

### Removed

- All v2 runtime code: the community/variable-amount API client, bare-file endpoints (`update_order.php`, `check_transaction.php` — replaced by the WC-API receiver), the Bootstrap-era modal, promo-code and installment features tied to the old API, and the WPFunnels compat shim. v2 lives on the `v2-maintenance` branch.

### Notes

- Settings do NOT migrate from v2 (different API, different credentials). v2 merchants install fresh keys and a webhook.
- Deliberately not in 3.0.0: embedded PaymentElement fields (planned 3.1), Apple Pay/Google Pay (no platform adapters yet), subscriptions (platform saved-card charging not production-ready), automatic live-mode webhook provisioning (blocked on xpay#411 — guided manual setup ships instead).
- WordPress floor raised 6.0 → 6.2: the log store binds table identifiers with `wpdb::prepare()`'s `%i` placeholder (added in WP 6.2), and WooCommerce 8.3 — already our floor — requires a newer WordPress than 6.2 anyway.
- `languages/xpay-for-woocommerce.pot` still contains v2 strings; regeneration is part of the release build before submission.

---

## [2.0.1] — 2026-05-03

### Fixed

- **Payment modal terminal-state handling.** The modal previously only acted on `SUCCESSFUL` poll responses; `FAILED` and `INVALID` (the latter covering IDOR mismatch, missing order, and order in `cancelled`/`refunded`/`trash`) were explicitly ignored, leaving the modal polling silently every 10 seconds until the customer manually closed it. Diagnostic logs from a production merchant showed cases polling for 2+ hours after the WooCommerce hold-stock cron auto-cancelled the order — every poll returning `INVALID (closed_status)`, never surfaced to the customer. The modal now treats `FAILED` and `INVALID` as terminal: polling stops, the iframe and "don't close the popup" warning are hidden, and a red in-modal banner explains the customer should close and try again. No auto-redirect on failure (unlike success) — the customer decides what to do next. `PENDING`, empty responses, and any unknown future status names continue to poll (allowlist of terminal states only — does not fail-close on unknowns so XPay can introduce new intermediate states without breaking the modal).

### Added

- New client log event `terminal_state` (under `modal.client_event`) records the terminal status string so the diagnostic log shows when the new behavior fires.

### Notes

- Does not address upstream Cloudflare WAF challenges that intermittently 403-block `prepare-amount` and `pay/variable-amount` calls — that is a server-side configuration on `community.xpay.app` and requires a WAF rule change there, not a plugin change. This release only stops the secondary "stuck modal" symptom that compounded the WAF-induced failures in observed merchant logs.

---

## [2.0.0] — 2026-04-27

Major version: plugin renamed for WordPress.org plugin directory submission. Existing merchants on the legacy `woocommerce-xpay-plugin` directory should deactivate the old plugin and install this one — gateway settings carry over automatically because the underlying option key (`woocommerce_xpay_gateway_settings`) is unchanged.

### Renamed (clean break, breaking for downstream callers)

- **Plugin name:** "WooCommerce XPAY Gateway" → "XPay for WooCommerce" (avoids WP.org's "WooCommerce as prefix" rule).
- **Plugin slug / directory:** `woocommerce-xpay-plugin` → `xpay-for-woocommerce` (matches WP.org slug rules).
- **Text domain:** `wc-gateway-xpay` → `xpay-for-woocommerce` (must match slug per WP.org). All translatable strings updated.
- **Global PHP functions** all renamed to the `xpay_` prefix:
  - `httpPost` → `xpay_http_post`
  - `httpGet` → `xpay_http_get`
  - `generate_payment_modal` → `xpay_generate_payment_modal`
  - `fetch_installment_plans` → `xpay_fetch_installment_plans`
  - `enqueue_xpay_styles` → `xpay_enqueue_styles`
  - `enqueue_checkout_scripts` → `xpay_enqueue_checkout_scripts`
  - `wc_xpay_add_to_gateways` → `xpay_add_to_gateways`
  - `wc_xpay_gateway_plugin_links` → `xpay_gateway_plugin_links`
  - `handle_validate_xpay_promo_code` → `xpay_handle_validate_promo_code`
  - `handle_store_promocode_details` → `xpay_handle_store_promo_details`
  - `handle_clear_promocode_details` → `xpay_handle_clear_promo_details`
- **AJAX action names** all renamed to the `xpay_` prefix:
  - `validate_xpay_promo_code` → `xpay_validate_promo_code`
  - `store_promocode_details` → `xpay_store_promo_details`
  - `clear_promocode_details` → `xpay_clear_promo_details`
  - `fetch_installment_plans` → `xpay_fetch_installment_plans`

If any external code (theme, sibling plugin, custom mu-plugin) called the old function names or hooked the old AJAX actions, it must be updated.

### Added

- **`readme.txt`** with all WordPress.org-required headers (Stable tag, Tested up to, Requires at least, Requires PHP, Contributors, License, License URI, Tags, Description, Installation, FAQ, Changelog, Upgrade Notice).
- **External services and Privacy disclosure sections** in `readme.txt` listing exactly what data is sent to XPay, when, why.
- **`Text Domain` and `Domain Path` headers** in plugin file. Explicit `load_plugin_textdomain()` call on `init` so non-WP.org installs (manual / GitHub direct download) also pick up bundled `.mo` translations.
- **`/languages/xpay-for-woocommerce.pot`** template — 114 strings, ready for translators.
- **`License: GPL-2.0-or-later` / `License URI:`** in plugin header.
- **`WC_XPAY_VERSION` plugin constant** used by all enqueue calls (replaces hardcoded `'1.3.0'` strings and stale "cache bust" comment).
- **`.distignore` + `bin/build.sh`** packaging infrastructure for producing WP.org-ready ZIPs from source.
- **`docs/PACKAGING.md`** with step-by-step build and submission instructions.
- **`.gitignore`** at plugin root (covers `.DS_Store`, IDE folders, build outputs).

### Security

- **Webhook verification fail-closed when `webhook_secret` is configured.** Previously, if a merchant set the secret but XPay sent the webhook without it, the request silently fell through to the unverified-accept branch — defeating the point of configuring the secret. Now: secret configured + missing/mismatched secret in body → HTTP 401. Legacy unverified mode (no secret configured) is unchanged.
- **Webhook verification scheme corrected.** Earlier 2.0.0 builds checked an `X-XPay-Signature` HMAC-SHA256 header that XPay's actual production webhooks do not send — this caused every real signed webhook to be rejected with `signature_missing` and orders only completed via the modal-close poll fallback. The verification now reads `secret_key` from the JSON body (XPay's real scheme: the merchant-configured secret echoed back inside the payload) and constant-time-compares it via `hash_equals()`. The lookup tries the top-level `secret_key` first (matches observed production webhooks) then falls back to `extra_details.secret_key` (matches XPay's published examples) — both are accepted. Logger branch labels also updated: `signature_missing` → `secret_missing_in_body`, `signature_mismatch` → `secret_mismatch`. The `webhook.received` logger entry now also records `body_top_keys` and `extra_details_keys` (key names only, never values) so unfamiliar payload shapes surface in the log without leaking secrets.
- **`wp_cache_add` lock around `payment_complete()`** in both `update_order.php` (webhook) and `check_transaction.php` (modal-close poll). Prevents `woocommerce_payment_complete` double-fire (double stock decrement, double new-order email, double affiliate commission) when XPay's webhook arrives at the same moment as the customer's modal-close poll. Effective on hosts with a persistent object cache; degrades gracefully on default WP.
- **Defensive `(string)` casts** on re-extracted `$transaction_id` / `$transaction_status` in `update_order.php` so non-string payloads don't trigger PHP 8.1+ deprecation warnings.
- **`wp_unslash()` + `sanitize_text_field()`** on the `$_SERVER` reads in the webhook receiver (`REMOTE_ADDR`, `HTTP_X_FORWARDED_FOR`, `HTTP_CF_RAY`, `HTTP_X_XPAY_SIGNATURE`).
- **`esc_attr()`** on the dynamic `$checked` attribute in payment-method radio rendering.
- **`esc_html__()`** wrapping for translatable strings echoed into HTML.

### Performance / SRE

- **Dedicated `XPAY_PREPARE_TIMEOUT = 20` seconds** for prepare-amount with `max_retries = 0`. Combined with the existing 25-second pay-call timeout, worst-case checkout request budget drops from 76 seconds to 45 seconds — under typical PHP-FPM `request_terminate_timeout` ceilings.
- **Transient-backed circuit breaker** (`xpay_circuit_breaker_*`). After 5 consecutive outbound failures within 5 minutes, the breaker opens for 60 seconds and `xpay_http_request` returns `WP_Error` immediately without making the upstream call. Prevents PHP-FPM saturation when XPay is degraded — every checkout fails fast in milliseconds instead of waiting the full 25-second timeout.

### UX / Accessibility

- **Three reassurance-first error strings** replacing panic-inducing copy:
  - "A previous payment attempt … is still being processed" → "Your previous payment attempt is still in progress. To prevent a double charge we are pausing new attempts for up to 10 minutes. If you do not see a confirmation email by then, contact support with order #N — your card will not be charged twice."
  - "Payment processing failed. Please try again." → "We could not reach the payment provider. Please check your internet connection and try again. If the problem continues, try a different payment method or contact us."
  - "Payment system error: the payment URL did not pass safety checks" → "We could not securely load the payment form. Your card has not been charged. Please contact support and quote order #N so we can complete this payment for you."
- **Block checkout: installment without a period selected now blocks submission with an error notice** ("Please select an installment period before continuing.") instead of silently posting an empty plan that the gateway can't process.

### Fixed

- **WP_USE_THEMES define** in webhook + poll entry points marked phpcs:ignore (it's a WP core constant we don't own).
- **`apply_filters('active_plugins', get_option('active_plugins'))`** WC-active check replaced with `is_plugin_active('woocommerce/woocommerce.php')` (loads `wp-admin/includes/plugin.php` on demand). Avoids invoking a non-prefixed core filter from plugin code.
- **`unlink()` → `wp_delete_file()`** in logger and logger-admin.
- **Removed the unused `apply_filters('woocommerce_offline_icon', '')` filter** (just `$this->icon = ''` directly).
- **Removed the legacy `jsprint()` debug helper** (no callers in PHP).
- **Removed the stale `Cache bust: 2025-12-08-07-45` comment** at the top of the plugin file.
- **`COMPATIBILITY.md` moved from plugin root to `docs/`** so the WP.org-distributed root is markdown-clean.
- **Soft-hyphen typo** in one text-domain literal (`'wc­gateway-xpay'` with U+00AD soft hyphen) corrected.
- **Empty `__('', 'wc-gateway-xpay')` defaults** replaced with plain `''` (PCP `NoEmptyStrings`).
- **Concatenated `__()` calls** for the callback-URL field title/default refactored to keep only the literal string inside the gettext call (PCP `NonSingularStringLiteralText`).
- **Translators comments** added at every `sprintf(__('… %s …'))` site (PCP `MissingTranslatorsComment`).
- **`Text Domain` argument added** to 4 environment-option `__()` calls that were missing it.
- **Webhook handler and modal-close poll** now wrapped in IIFE so all locals stay function-scoped — eliminates 23 `NonPrefixedVariableFound` PCP warnings without renaming each variable.

### Operation safety

- Verified end-to-end against XPay staging: order placed via Block checkout, paid via card with 3DS, webhook + check_transaction.php both correctly resolved (single `payment_complete` per order, lock confirmed working).
- All 13 PHP files lint clean (`php -l`).
- WordPress Plugin Check (PCP) reduced from 239 findings to 3 remaining (the 2 intentional public webhook entry points + `.gitignore`, all unfixable in source).

### Migration notes (1.3.x → 2.0.0)

- **Settings carry over.** Gateway option key (`woocommerce_xpay_gateway_settings`) is unchanged. Community ID, API key, environment, webhook secret, debug flag, etc. are all preserved when a merchant deactivates the old plugin and activates the new one.
- **The plugin DIRECTORY changes** from `wp-content/plugins/woocommerce-xpay-plugin/` to `wp-content/plugins/xpay-for-woocommerce/`. WordPress treats this as a different plugin — there is NO automatic update from 1.x to 2.0.0. Merchants must install 2.0.0 and deactivate (then optionally delete) 1.x.
- **The "WooCommerce XPay Gateway" entry will remain visible in the Plugins list** until a merchant clicks Deactivate → Delete on the old slug. Their settings are not lost during this.
- **Downstream code that called the old function names or hooked the old AJAX actions will break.** See "Renamed" section above for the mapping.

---

## [1.3.1] — 2026-04-27

### Fixed

- **Accessibility: payment modal now exposed correctly to assistive tech.** The 1.3.0 modal markup placed `aria-hidden="true"` on the backdrop element, which hid the entire modal subtree (including the iframe) from the accessibility tree even when the modal was visually open. Screen-reader users couldn't reach the payment form. The attribute is now placed on the `<div id="xpay_modal" role="dialog">` element with default `"true"`, and the vanilla JS flips it to `"false"` on open and back to `"true"` on close. The backdrop is purely visual and no longer carries `aria-hidden`. Caught during the post-1.3.0 full regression run.

### Operation safety

- No behavior change for sighted customers — the modal opens, polls, and redirects identically to 1.3.0.
- Verified end-to-end via the same regression matrix used for 1.3.0 (10 tests across HPOS on/off × block/classic/WPFunnels checkouts × concurrent-guard + abandoned scenarios). All pass.

---

## [1.3.0] — 2026-04-27

Replaces the Bootstrap 3 modal dependency with a vanilla CSS+JS implementation, eliminating an entire class of theme-conflict failure modes and the EOL Bootstrap 3 supply-chain risk. Also adds a defensive jQuery-version check that surfaces unexpected versions in the diagnostic logger.

### Removed

- **Bootstrap 3.4.1 CDN dependency.** The payment modal no longer loads `https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/...` on the pay-for-order page. ~160KB of CSS+JS removed from every payment page render. EOL software (BS3 went EOL July 2019) no longer in the supply chain.

### Added

- **Vanilla CSS+JS payment modal.**
  - [assets/css/xpay-modal.css](assets/css/xpay-modal.css) — minimal stylesheet, all selectors scoped under `.xpay-modal*` so it cannot collide with theme or other-plugin styles. Responsive (handles small-screen layout). Themes can override visual aspects via higher-specificity selectors without touching structure.
  - [assets/js/xpay-modal.js](assets/js/xpay-modal.js) — lifecycle module that owns show/hide, polling `check_transaction.php`, the success-banner countdown, and the redirect. No jQuery dependency. No external libraries.
  - Dynamic data (plugin URL, nonces, order id, thank-you URL) is injected via `wp_localize_script` rather than echoed inline.
- **jQuery sanity check on modal init.** Detects whether `window.jQuery` is present and whether its major version is 3 or above. If jQuery is missing or pre-3.x, emits a browser console warning AND a `js.compat_warning` log event (`details: jquery_missing` or `jquery_below_3:<version>`). The modal itself does not depend on jQuery, so the warning is informational rather than blocking — but other XPay UI surfaces (installment selector, promo code) still need it via WP's bundled copy.
- **`referrerpolicy="strict-origin-when-cross-origin"` on the iframe** so XPay only sees the origin (not the full URL) of the merchant's payment page.
- **`aria-modal`, `aria-labelledby`, `aria-hidden` attributes** on the modal markup — accessibility improvement that comes for free with the rewrite.
- **Documentation:** new "WordPress Blocks (Gutenberg) compatibility" section in [COMPATIBILITY.md](docs/COMPATIBILITY.md) covering Cart/Checkout Blocks, block themes/FSE, and classic shortcode checkout. New risk-categorized "Themes" section listing low/medium/high-risk patterns with diagnosis recipes. New "Theme conflict" and "jQuery loaded but version is unexpected" sections in [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md).

### Changed

- The "click here" fallback link on the pay-for-order page no longer uses Bootstrap `data-toggle`/`data-target` attributes; it's a plain anchor with `id="xpay_modal_open_link"` that the vanilla modal JS attaches a click handler to.
- The modal markup now uses `data-xpay-close` on close affordances instead of Bootstrap's `data-dismiss="modal"`.
- The modal CSS uses `.xpay-modal-backdrop.is-open` toggling instead of Bootstrap's `.modal.in`.

### Fixed

- **Resolves the H4-class supply-chain risk** entirely (rather than mitigating it via SRI hashes as 1.2.1 did). With no CDN dependency, there is no scenario where compromised third-party content can reach the customer's payment page through the modal.
- **Eliminates the Bootstrap 3 vs 4/5 selector-conflict failure mode** for themes that ship a different Bootstrap version. Symptoms previously included "modal won't open", "modal won't close cleanly", and "backdrop doesn't dismiss".

### Operation safety

- Behavior parity verified end-to-end: open → poll PENDING ×N → SUCCESSFUL → countdown → redirect; manual close → final-poll → no redirect on PENDING. Identical to 1.2.x.
- Modal markup IDs (`xpay_modal`, `xpay_trn_uuid`, `xpay_success_banner`, `xpay_redirect_countdown`, `xpay_message`, `xpay_modal_open_link`) preserved so any theme/plugin CSS or JS that targets them by ID continues to work.
- Diagnostic logger client-side events (`modal_shown`, `poll_response`, `countdown_started`, `redirect_initiated`, `modal_hidden_manual`, `js_error`) preserved with identical event names. Adds new `js.compat_warning` for the jQuery check.
- The previous behavior of NOT closing the modal on backdrop-click or Escape-key (payment closure must be deliberate) is preserved.
- WP Cart Block and FSE compatibility unchanged — verified Twenty Twenty-Five end-to-end.

### Migration notes (1.2.1 → 1.3.0)

- **No setting changes required.** The vanilla modal is a drop-in replacement.
- **No customer-visible behavior change.** Modal looks slightly different (cleaner, native fonts, no Bootstrap chrome) but the lifecycle is identical.
- **Theme/site CSS that targeted Bootstrap classes** (`.modal-dialog`, `.modal-content`, `.modal-header`, `.modal-title`, `.modal-body`, `.modal-footer`, `.btn`, `.close`) inside the XPay modal will no longer apply because we no longer use those class names. ID selectors (e.g. `#xpay_modal`) still work. If a merchant has custom modal styling, they'll need to update selectors to `.xpay-modal*`.
- **Browser cache may serve the old inline modal markup** for 1-2 page loads after upgrade. Hard-refresh resolves it. Caching plugins should be flushed.

---

## [1.2.1] — 2026-04-27

Closes the four remaining items from the original security/quality assessment
(`CODE_REVIEW.md`). With this release, all 28 issues from the initial review
and subsequent adversarial passes are resolved.

### Security

- **C5 — Promo discount no longer trusted from the client.** `handle_validate_xpay_promo_code` now writes the validated `promocode_id` and `discount_amount` into the WC session atomically on success (and clears stale values on failure). `handle_store_promocode_details` is kept reachable for backward compatibility with the existing front-end JS but ignores all `$_POST` inputs — it only returns whatever the session already has. Previously, anyone with a valid checkout-page nonce could call `store_promocode_details` directly with `discount_amount=1` and pay 1 unit while the WC order showed the full price.
- **H4 — Bootstrap 3.4.1 CDN now uses Subresource Integrity.** Both `<link>` and `<script>` tags include `integrity="sha384-..." crossorigin="anonymous"` on the page where customers enter card data. Hashes were computed directly from the live CDN files (`openssl dgst -sha384 -binary`) and match the published Bootstrap 3.4.1 starter values. If the CDN ever serves modified content, the browser refuses to load and the modal shows the existing fallback "click here" link.
- **H5 — Iframe URL host validated against an XPay allowlist.** New `xpay_iframe_host_is_allowed()` accepts only `*.xpay.app` (and `127.0.0.1`/`localhost` when the gateway's Environment is set to Local). On reject, the modal is not rendered, a customer-facing error referencing the order ID is shown, and a `iframe.blocked` event is logged with the rejected host so support can investigate. Previously a compromised or misconfigured upstream response could render an arbitrary iframe on the merchant's payment page (`esc_url` blocks unsafe schemes but not arbitrary hosts).

### Fixed

- **H14 — WC order totals now reflect the XPay promo discount.** New `xpay_apply_promo_fee_to_cart` on `woocommerce_cart_calculate_fees` adds a negative cart fee labelled "XPay promo discount" when the session has a server-validated promo. Cart preview, checkout summary, order totals, customer-facing emails, and WC reports all show the discounted amount the customer is actually charged. `process_payment` adds the fee value back when computing `original_amount` so the XPay API payload semantics are preserved exactly: `original_amount = pre-discount`, `amount = discounted`, `promocode_id` set. Previously the WC order showed the full price while XPay charged the discounted amount, causing reconciliation to diverge.

### Operation safety

- The new cart-fee hook is a no-op when no XPay promo is active (`$promo_id === '' || $value <= 0`), so non-promo checkouts behave identically to 1.2.0.
- The `original_amount` adjustment in `process_payment` only applies when an XPay promo fee actually exists on the order; other plugins' fees are deliberately preserved in the total.
- The `127.0.0.1`/`localhost` escape hatch in the iframe host check is gated behind the gateway's `iframe_base_url` ALSO being a localhost URL — production deploys cannot be tricked into accepting localhost iframes.
- Verified end-to-end: order #40 placed and processed against staging with no spurious fees, identical timing, identical XPay payload semantics.

### Migration notes (1.2.0 → 1.2.1)

- **No setting changes required.** All four fixes are transparent at the configuration level.
- **Existing promo codes continue to work.** The JS flow is unchanged; the server is now authoritative about what discount is applied.
- **Stores with a previously-applied (and now-invalid) session promo:** when the customer next loads the checkout, the cart-fee hook will only fire if the session value matches what XPay's validate endpoint last returned. Stale or invalid session values get reset by the customer's next promo action. No manual cleanup needed.
- **WC reports for orders placed before this release with an XPay promo applied** will continue to show the un-discounted total (that's how the orders were stored). New orders will show the correct discounted total.

---

## [1.2.0] — 2026-04-27

This release rewrites large portions of the plugin to fix production-blocking
bugs found during a security review and on real merchant deployments. It
adds first-class HPOS and Cart/Checkout Blocks support, hardens the webhook
receiver, and ships a built-in diagnostic logger for debugging conflicts.

### Added

- **Cart/Checkout Blocks integration** (`class-wc-xpay-blocks-integration.php`). The gateway now appears on stores using WooCommerce's block-based checkout (default for WC 8.3+).
- **HPOS (High-Performance Order Storage) compatibility** declared via `FeaturesUtil::declare_compatibility('custom_order_tables', ...)`. Webhook lookup uses `wc_get_orders()` so it works on both legacy postmeta and HPOS storage.
- **Webhook HMAC-SHA256 signature verification** (`update_order.php`). When both the plugin's `webhook_secret` setting AND the `X-XPay-Signature` header are present, the plugin verifies the signature and rejects mismatches with HTTP 401. Fail-open mode is preserved for setup convenience.
- **Concurrent-attempt guard** in `process_payment`. Blocks a second pay attempt within 10 minutes of an in-flight first attempt to bound double-charge risk on PHP-killed-mid-flow scenarios.
- **Auto-close countdown** in the iframe modal. Polls `check_transaction.php` every 10 seconds; on SUCCESSFUL, shows a 5-second countdown banner then redirects to the order-received page.
- **Diagnostic logger** (`includes/logger/`). Records every step of the XPay flow with secrets/PII redacted at write time. Daily file rotation, 30-day retention, admin Tools page (Tools → XPay Logger) with live tail, filters, download, and on-demand environment snapshot. Off by default; zero runtime cost when disabled.
- **WPFunnels compatibility shim** (`includes/compat/class-wc-xpay-wpfunnels-compat.php`). When enabled, restores the standard WooCommerce order-received URL for XPay orders even when WPFunnels would have rewritten it (works around the `/cart/` redirect issue on WPFunnels Free).
- **Admin notice** that nudges merchants with WPFunnels installed to enable the compatibility setting. Dismissible per-user.
- **Default-methods fallback** in `xpay_get_community_preferences()`. When the preferences API call fails (typically Cloudflare WAF), the plugin renders a sane default method list (CARD, FAWRY, VALU, MEEZA/DIGITAL) instead of an empty radio list.
- **Documentation suite** under `docs/`: GETTING_STARTED, CONFIGURATION, GOING_LIVE, TROUBLESHOOTING. Plus root `COMPATIBILITY.md` covering known plugin/theme/host interactions.

### Changed

- Switched all outbound HTTP calls from `curl_exec` to `wp_remote_post`/`wp_remote_get`. `curl_exec` is disabled on EasyWP and other hardened hosts; the WordPress HTTP API works everywhere.
- HTTP timeout for outbound XPay calls raised from 8 seconds to 25 seconds (with `@set_time_limit(60)` at the top of `process_payment`) based on observed staging latency.
- Browser-like User-Agent set on every outbound XPay call to bypass Cloudflare Bot Fight Mode on the merchant's origin.
- Order status flow corrected: payments now move `pending → processing` (via `payment_complete()`) instead of jumping straight to `completed`. This restores the standard fulfillment workflow.
- Cart is no longer emptied and stock is no longer reduced inside `process_payment`. Both happen via `payment_complete()` once the webhook confirms the payment, so abandoned payments don't leak inventory.
- Inline JS no longer leaks the API key. The `fetch_installment_plans` AJAX endpoint reads credentials server-side and accepts only the amount + nonce from the client.
- Promo code AJAX no longer accepts a caller-controlled `url` parameter (was an SSRF risk). Reads the upstream URL and credentials server-side.
- Discount/promo amounts are validated against the upstream API response, not trusted from `$_POST`.
- All AJAX endpoints (`fetch_installment_plans`, `validate_xpay_promo_code`, `xpay_get_payment_methods_fees`) require nonces.
- `MEEZA/DIGITAL` payment method is normalized via an explicit map, not `sanitize_key()` (which strips the `/` and broke the upstream call).
- `update_post_meta` used everywhere instead of `add_post_meta` to avoid duplicate transaction-id rows on retries.
- Webhook receiver uses `$order->payment_complete()` (not direct status update) to trigger the standard WC payment-complete actions (stock reduction, status routing, canonical `_transaction_id` meta).
- `WC_Gateway_Xpay::receipt_page` is registered exactly once even when the gateway is instantiated multiple times (was firing twice and rendering the modal HTML twice).

### Fixed

- `payment_fields()` now correctly fetches and renders the available payment methods. Previously the `wp_remote_get` to the preferences endpoint sent no `x-api-key` header, returning empty.
- `xpay_get_payment_methods_fees` AJAX handler now accepts an `amount` from `$_POST` as a fallback when `WC()->cart->total` returns 0 (fixes WPFunnels-style cart-less checkouts).
- `woo_change_order_received_text()` no longer fires payment-creation API calls inside a text filter (was creating duplicate transactions on every thank-you-page render).
- Removed `jQuery.noConflict(true)` from the modal init (was wiping `window.jQuery` and `window.$` for the entire page).
- `json_decode(null)` PHP 8 deprecation when `httpPost` returns null on timeout — guarded with `is_string` check.
- PHP 8 fatal in `woo_personalize_order_received_title` when `wc_get_order` returns false — guarded.
- Webhook handler refuses to flip `cancelled`/`refunded` orders back to `processing` (closed-status guard).
- Webhook handler refuses to overwrite an already-paid order with a stale FAILED webhook (oversell guard).
- Webhook lookup uses an exact-match safety check after `wc_get_orders` (the underlying meta_value comparison is permissive on some HPOS releases).
- Webhook handler verifies the order is actually an XPay order before completing it (`get_payment_method() === 'xpay_gateway'`).
- `check_transaction.php` reads `community_id` from server settings, not from the request (was an IDOR / open-relay risk).
- IDOR guard in `check_transaction.php`: verifies the supplied uuid actually belongs to the supplied order.
- Phone-number validation accepts all reasonable international formats, not just US/EG-specific patterns.

### Security

- API key no longer leaked into inline JS on every checkout page (CVSS-equivalent High).
- Webhook receiver now verifies HMAC signature when configured. Without it, in fail-open mode, log entries explicitly flag every unsigned request so the gap is visible.
- SSRF / credential-pass-through in `fetch_installment_plans` removed; URL is server-controlled.
- Promo discount amount is no longer trusted from `$_POST` and used as the charge amount.
- All AJAX endpoints have nonces.

### Internal

- New file layout under `includes/`:
  - `includes/logger/` — diagnostic logger (facade, listeners, redactor, admin tools page)
  - `includes/compat/` — third-party compatibility shims (WPFunnels)
  - `includes/class-wc-xpay-admin-notices.php` — admin-notice infrastructure
- Activation hook auto-creates the log directory and schedules the daily cleanup cron.
- Deactivation hook clears the cron (logs are intentionally left in place for inspection).
- Auto-install fallback: if the cron isn't scheduled when the plugin loads (e.g. existing merchant upgrading to this version), the activation logic runs at runtime on the next request.

### Migration notes (1.1 → 1.2)

- **No setting changes required** for existing merchants. Existing `community_id`, `payment_api_key`, `variable_amount_id`, and `iframe_base_url` values are preserved.
- **The default for the new `webhook_secret` setting is empty**, which keeps the plugin in fail-open mode (same effective behavior as 1.1). Configure the secret to enable strict signature verification — see [docs/CONFIGURATION.md](docs/CONFIGURATION.md#webhook-secret).
- **The diagnostic logger is OFF by default**. Existing merchants will not see any change in behavior. Enable it from the gateway settings while debugging an issue.
- **WPFunnels merchants:** the plugin auto-detects WPFunnels and shows an admin notice nudging you to enable the compatibility setting if you don't have a working WPFunnels Pro upsell flow. See [COMPATIBILITY.md](docs/COMPATIBILITY.md#wpfunnels-confirmed).
- **HPOS merchants:** no action needed. The plugin works on both HPOS-on and HPOS-off stores.

---

## [1.1] — earlier

Original release. Functionality preserved by 1.2 above; refer to git history for the detailed change set if needed.
