# XPay for WooCommerce

Adds [XPay](https://xpay.app/) as a payment method on any WooCommerce store. Shoppers pay by card, valU and more in a secure XPay window that opens over your own order page — in English or Arabic — and land on an order confirmation styled as a stamped receipt. Supports the classic shortcode checkout, the block-based Cart/Checkout, and HPOS (High-Performance Order Storage). Includes a redacting diagnostic logger with an in-admin viewer.

---

## Quick start

1. **Install** — upload the plugin folder to `wp-content/plugins/` and activate, or install from a `.zip` via WP Admin → Plugins → Add New → Upload Plugin.
2. **Configure** — WP Admin → WooCommerce → Settings → Payments → XPay. Leave **Mode** on **Test**, then paste your test keys from the XPay dashboard → Developers → API keys: a restricted secret key (`rk_test_…`) and the publishable key (`pk_test_…`).
3. **Set up the webhook** — in the XPay dashboard, add a webhook endpoint pointing at the URL shown in the plugin settings (`https://your-store/?wc-api=xpay_webhook`), subscribe it to `checkout.session.completed`, `checkout.session.expired`, `payment_intent.payment_failed`, `charge.refunded` and `refund.failed`, and paste its signing secret (`whsec_…`) into the plugin.
4. **Test** — place an order with a test card. The XPay window opens on the pay page; after payment the confirmation page shows the receipt stamped PAID, and the order note says how it was confirmed.
5. **Go live** — switch **Mode** to **Live** and paste the live key set (keys and webhook secrets are separate per mode). Full checklist in [docs/GOING_LIVE.md](docs/GOING_LIVE.md).

Full walkthrough in [docs/GETTING_STARTED.md](docs/GETTING_STARTED.md).

---

## Documentation

| Doc | When to read it |
|---|---|
| [Getting started](docs/GETTING_STARTED.md) | First-time installation and your first test payment |
| [Configuration reference](docs/CONFIGURATION.md) | What every setting does, where to find each value, what happens if it's wrong |
| [Webhooks](docs/WEBHOOKS.md) | How order state stays true; the receiver's HTTP status contract |
| [Going live](docs/GOING_LIVE.md) | Switching from test to live, with a pre-flight checklist |
| [Troubleshooting](docs/TROUBLESHOOTING.md) | Diagnosing checkout issues with the built-in log viewer; common symptoms and fixes |
| [Compatibility](docs/COMPATIBILITY.md) | Known interactions with funnel builders, caching plugins, security plugins, themes, and the legacy v2 plugin |
| [Packaging](docs/PACKAGING.md) | Building the release zip |
| [Changelog](CHANGELOG.md) | Version history |

---

## Requirements

- WordPress 6.2+
- WooCommerce 8.3+ (for HPOS and block-checkout support)
- PHP 7.4+ (PHP 8.0+ recommended; CI runs 7.4 and 8.3)
- An XPay merchant account — sign up at <https://xpay.app/>

---

## Support

- **Plugin bugs / feature requests:** open an issue in this repo.
- **XPay account, API keys, payment-method enablement, refunds, settlements:** contact XPay support directly.

When reporting a checkout issue, turn on diagnostic logging (XPay settings → Diagnostic logging), reproduce the issue, then open WP Admin → WooCommerce → XPay Log and click **Copy debug report**. Keys, secrets and card data are redacted at write time, and nothing is ever transmitted automatically — you paste the report yourself. See [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md).

---

## License

GPL-2.0-or-later. See [readme.txt](readme.txt).
