=== XPay for WooCommerce ===
Contributors: xpay
Tags: woocommerce, payments, payment gateway, egypt, valu
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card, ValU, Fawry, and other XPay-supported payments directly on your WooCommerce checkout.

== Description ==

XPay for WooCommerce adds secure XPay payment methods to your store. Customers pay without leaving your checkout, while card details go directly to XPay and never pass through your WordPress server.

The plugin supports classic checkout, Cart and Checkout Blocks, HPOS, Arabic, test and live modes, and WooCommerce refunds.

= Features =

* Card, ValU, Fawry, and other methods enabled for your XPay account.
* One-click connection through XPay. No API key is pasted or shown.
* Automatic, signed webhook setup for reliable order updates.
* Secure payment fields on the WooCommerce checkout and order-pay pages.
* Separate test and live connections.
* Full and partial refunds where the payment method supports them.
* Arabic and English support.
* Redacted diagnostic logs in WooCommerce.

== Installation ==

1. In WordPress, go to Plugins → Add New → Upload Plugin.
2. Upload the XPay plugin ZIP, install it, and activate it.
3. Go to WooCommerce → Settings → Payments → XPay.
4. Click Connect with XPay.
5. Sign in to XPay, choose your business, and approve the connection.
6. Return to WooCommerce and choose which payment methods to show.

The first connection uses test mode. Place a test order before accepting real payments.

= Going live =

1. Open the XPay connection dialog.
2. Select Live and click Connect live account.
3. Approve the connection on XPay.
4. Turn off Test mode and save.
5. Place a small real order and confirm that its WooCommerce status updates.

Test and live connections use separate keys and webhooks.

== External services ==

This plugin connects to XPay's services and cannot process payments without them.

* **api.xpay.app**: the store creates payment sessions and refunds here. It sends the order amount, currency, order number, and the customer's name, email, and phone number. The connection flow also sends the store name, URL, and icon so the merchant can identify the store before approving access.
* **checkout.xpay.app**: XPay's secure payment fields and script load from here in the customer's browser. The customer enters payment details directly into these fields. The customer's name, email, phone number, and payment information are sent to XPay to process the payment.

XPay's [Terms of Service](https://xpay.app/terms) and [Privacy Policy](https://xpay.app/privacy) apply.

== Frequently Asked Questions ==

= Do I need HTTPS? =

Yes. The connection, payment fields, and webhooks require HTTPS.

= Where do I enter API keys? =

Nowhere. Click Connect with XPay and approve access on XPay. The plugin saves the required credentials without displaying them.

= How do I choose payment methods? =

Enable them for the merchant account in XPay, then use the Payment Methods tab in the plugin to choose which available methods this store shows and their order.

= How do I test payments? =

Keep Test mode enabled and use the test payment details provided in your XPay dashboard. Test mode does not move real money.

= How do I configure webhooks? =

The plugin configures them automatically when you connect. If the webhook status becomes unhealthy, open the connection dialog and click Reconfigure webhook.

= How do I troubleshoot a payment? =

Turn on Diagnostic logging in the XPay settings, reproduce the issue, then open WooCommerce → Status → Logs and select the latest `xpay` log. Secrets and personal data are redacted before the log is written.

= Can I issue refunds from WooCommerce? =

Yes, when the payment method supports refunds. ValU refunds are not currently supported by XPay.

= Does XPay work with WPFunnels? =

Yes. If customers return to the cart after paying and you do not use a working WPFunnels Pro upsell step, turn on WPFunnels compatibility in the XPay settings.

== Changelog ==

= 1.0.0 =

* Launch XPay for WooCommerce with secure on-site payments, one-click account connection, automatic webhooks, refunds, test and live modes, payment-method controls, HPOS support, and Arabic translations.

