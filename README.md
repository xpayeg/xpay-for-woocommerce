# XPay for WooCommerce

Accept card, ValU, Fawry, and other XPay-supported payments directly on your WooCommerce checkout.

XPay provides the secure payment fields, so card details never pass through your WordPress server. The plugin supports classic checkout, Cart and Checkout Blocks, HPOS, Arabic, test and live modes, and WooCommerce refunds.

## Requirements

- WordPress 6.2 or newer
- WooCommerce 8.3 or newer
- PHP 7.4 or newer
- HTTPS
- An [XPay merchant account](https://xpay.app/)

## Install and connect

1. In WordPress, go to **Plugins → Add New → Upload Plugin**.
2. Upload the XPay plugin ZIP, install it, and activate it.
3. Go to **WooCommerce → Settings → Payments → XPay**.
4. Click **Connect with XPay**.
5. Sign in to XPay, choose your business, and approve the connection.

You return to WooCommerce with the test keys validated and the webhook configured automatically. No key is pasted or shown.

## Test a payment

1. Keep **Test mode** enabled.
2. Open the **Payment Methods** tab and choose which available methods to show.
3. Add a product to the cart and complete checkout with an XPay test payment method.
4. Confirm that the order becomes **Processing** or **Completed** in WooCommerce.

The test payment details are available in your XPay dashboard.

## Go live

1. Open the XPay connection dialog in WooCommerce.
2. Select **Live** and click **Connect live account**.
3. Approve the connection on XPay.
4. Turn off **Test mode** and save.
5. Place a small real order and confirm that the order status updates correctly.

Test and live connections use separate keys and webhooks.

## Settings

- **Enable XPay:** shows or hides XPay at checkout.
- **Test mode:** switches between test and live payments.
- **Payment Methods:** chooses which methods appear and their order.
- **Theme:** uses automatic, light, or dark payment fields.
- **Diagnostic logging:** writes redacted payment events to WooCommerce logs.
- **WPFunnels compatibility:** restores the standard WooCommerce confirmation page when a WPFunnels setup redirects XPay orders to the cart. Leave it off when using a working WPFunnels Pro upsell flow.

Payment methods are enabled for the merchant account in XPay first. The plugin then lets the store choose which available methods to offer.

## Refunds

Open a WooCommerce order and use the normal **Refund** action. Full and partial refunds are supported where the payment method allows them. ValU refunds are not currently supported by XPay.

## Troubleshooting

Turn on **Diagnostic logging**, reproduce the issue, then go to **WooCommerce → Status → Logs** and select the latest `xpay` log.

See the [troubleshooting guide](docs/TROUBLESHOOTING.md) for common fixes. Logs are redacted before they are written and are never sent automatically.

## Support

- Plugin bugs and feature requests: open an issue in this repository.
- Account access, settlements, and payment-method availability: contact XPay support.

Please read [CONTRIBUTING.md](CONTRIBUTING.md) before reporting a bug. Report security issues privately as described in [SECURITY.md](SECURITY.md).

## License

XPay for WooCommerce is licensed under the [GNU General Public License, version 2 or later](LICENSE).
