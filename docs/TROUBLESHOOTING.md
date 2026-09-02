# Troubleshooting XPay for WooCommerce

## Start with the log

1. Go to **WooCommerce → Settings → Payments → XPay**.
2. Turn on **Diagnostic logging** and save.
3. Reproduce the problem.
4. Go to **WooCommerce → Status → Logs**.
5. Open the latest log whose source starts with `xpay`.

Search by WooCommerce order number. XPay keys, secrets, card details, and personal data are redacted before the log is written.

## XPay does not appear at checkout

Check that:

- XPay is enabled in WooCommerce.
- The selected test or live account shows as connected.
- At least one payment method is enabled on the plugin's **Payment Methods** tab.
- The store currency is supported by that method. ValU and Fawry require EGP.

Reconnect the selected mode if its account status says that its keys are invalid or missing.

## The payment fields do not load

A JavaScript optimizer, consent tool, browser extension, or strict Content Security Policy may be blocking XPay.

- Exclude `xpay-elements`, `xpay-checkout-driver`, `xpay-blocks`, and `xpay-pay-page` from script delay or optimization.
- Allow the script and payment fields from `https://checkout.xpay.app`.
- Treat XPay payment fields as strictly necessary in the site's consent tool.
- Test once in a private browser window with extensions disabled.

Do not cache WooCommerce checkout or order-pay pages.

## The customer paid but the order is still pending

The webhook normally confirms the payment. Open the connection dialog and check the webhook status for the active mode.

- If it is missing or unhealthy, click **Reconfigure webhook**.
- Make sure the store is using HTTPS.
- Check that a firewall or security plugin is not blocking `/?wc-api=xpay_webhook`.
- A `401` delivery usually means the stored signing secret does not match; reconfigure the webhook.
- A `404` may be a short race while WooCommerce creates the order; XPay retries it automatically.

If the shopper reaches the normal confirmation page, the plugin also checks the payment server-side.

## The shopper returns to the cart after paying

This can happen with WPFunnels when there is no working Pro upsell step.

Turn on **WPFunnels compatibility** in the XPay settings. Leave it off when XPay customers should enter a working WPFunnels Pro upsell flow.

## A refund fails

Check the order notes and XPay log for the reason.

- ValU refunds are not supported by XPay.
- A partial non-EGP refund needs the exchange rate saved with the payment.
- The connected account must still have permission to create refunds.

If needed, issue the refund in the XPay dashboard. Dashboard refunds are synchronized back to WooCommerce by webhook.

## Arabic checkout shows English payment fields

The plugin uses the WordPress locale. Confirm that the active site or page language is Arabic, then clear page and translation caches.

## What to send to support

Send:

- The WooCommerce order number.
- What the shopper did and what they saw.
- The approximate time and timezone.
- The downloaded XPay log for that day.
- A screenshot of failed webhook deliveries when the order is stuck pending.

Never send API keys, webhook secrets, or card details.
