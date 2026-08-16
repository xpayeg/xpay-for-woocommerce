=== XPay for WooCommerce ===
Contributors: xpay
Tags: woocommerce, payments, payment gateway, egypt, valu
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept cards, valU and more on your WooCommerce store via XPay (Egypt). Your customers pay in a secure XPay window without leaving your store.

== Description ==

XPay for WooCommerce connects your store to [XPay](https://xpay.app/), the Egyptian payment platform. Your customers pay by card or valU in a secure payment window that opens over your own order page, in English or Arabic.

= How payment works =

* Your customer clicks Place Order and a secure XPay payment window opens on your store's payment page.
* Card details are entered inside XPay's PCI-certified payment window. They never touch your WordPress server.
* 3-D Secure verification happens inside the same window.
* If the payment window cannot load (a script blocker, a very old browser), the customer is sent to XPay's hosted payment page instead. The payment always has a path forward.
* Your order is marked paid by a cryptographically signed webhook from XPay, with a server-side double check on the thank-you page. The plugin never trusts a browser redirect as proof of payment.

= Key features =

* Works with both the classic checkout and the block-based Cart and Checkout (WooCommerce 8.3+).
* Compatible with High-Performance Order Storage (HPOS).
* Full and partial refunds from the WooCommerce order screen.
* Arabic and English payment window, following your store language.
* Separate test and live modes with separate keys, so you can test safely before going live.
* Redacted diagnostic logging: card numbers, keys and secrets are stripped before anything is written to disk.

= Requirements =

* An XPay merchant account ([sign up](https://xpay.app/)).
* WordPress 6.0+, WooCommerce 8.3+, PHP 7.4+.
* Your store currency set to a currency XPay supports (EGP recommended; settlement is in EGP).

== External services ==

This plugin connects to XPay's payment platform. It will not work without it.

* **api.xpay.app** — your server creates payment sessions and refunds here, authenticated with your XPay API key. Order details sent: amount, currency, order number, and the customer's name, email and phone number (used by XPay for payment processing and receipts).
* **checkout.xpay.app** — the secure payment window and its script (`sdk.js`) load from here in the customer's browser on your store's payment page. Card details are entered directly into this window and never pass through your server.

XPay's terms and privacy policy: [Terms of Service](https://xpay.app/terms) · [Privacy Policy](https://xpay.app/privacy)

== Frequently Asked Questions ==

= Do I need an SSL certificate? =

Yes. Payments will not work on a store without HTTPS, and XPay webhooks are only delivered to https URLs.

= Which payment methods can my customers use? =

Whatever is enabled on your XPay merchant account — cards and valU today, with more methods appearing automatically as XPay enables them for you. The plugin never shows a method your account cannot accept.

= How do I set up the webhook? =

Your XPay dashboard → Developers → Webhooks. Add an endpoint pointing at the URL shown in the plugin settings, subscribe it to `checkout.session.completed` and `checkout.session.expired`, and paste the signing secret into the plugin. Test and live modes each need their own endpoint and secret.

= Why is refund disabled for a valU order? =

XPay cannot refund valU payments yet. The plugin tells you this instead of failing silently; refund those orders through your own arrangement with the customer.

== Changelog ==

= 3.0.0 =
* Complete rebuild on the XPay v3 platform: Checkout Sessions, signed webhooks, and API-based refunds.
* New on-site payment window (drop-in modal) with automatic hosted-page fallback.
* Cart/Checkout Blocks support and HPOS compatibility declared from this release.
* Full and partial refunds from the order screen.
* Orders are confirmed only by signed webhooks or a server-side session check, never by the browser redirect.
* Breaking: the v2 (community API) integration is removed. v2 merchants: install this version, paste your v3 keys, and configure the webhook — settings do not carry over.

= 2.0.1 =
* Payment modal now handles FAILED/INVALID terminal states instead of polling forever.

= 2.0.0 =
* Renamed for WordPress.org submission; all functions prefixed; security fixes. See CHANGELOG.md in the repository for the full history.
