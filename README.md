# XPay for WooCommerce

Adds [XPay](https://xpay.app/) as a payment method on any WooCommerce store. Supports the classic shortcode checkout, the new block-based Cart/Checkout, and HPOS (High-Performance Order Storage). Includes a built-in diagnostic logger for troubleshooting.

---

## Quick start

1. **Install** — upload the plugin folder to `wp-content/plugins/` and activate, or install from a `.zip` via WP Admin → Plugins → Add New → Upload Plugin.
2. **Configure** — WP Admin → WooCommerce → Settings → Payments → Xpay → fill in your XPay community ID, payment API key, and variable amount template ID. Default Environment is **Staging** — leave it there for now.
3. **Set the callback URL** — copy the URL displayed in the gateway settings and paste it into the corresponding field on your XPay dashboard.
4. **Test** — place an order with a staging test card. Order should move from `pending` to `processing` automatically within ~10 seconds.
5. **Go live** — when ready, swap to production credentials and switch Environment to **Production**.

Full walkthrough in [docs/GETTING_STARTED.md](docs/GETTING_STARTED.md).

---

## Documentation

| Doc | When to read it |
|---|---|
| [Getting started](docs/GETTING_STARTED.md) | First-time installation and your first test payment |
| [Configuration reference](docs/CONFIGURATION.md) | What every setting does, where to find each value, what happens if it's wrong |
| [Going live](docs/GOING_LIVE.md) | Migrating from staging to production, with a pre-flight checklist |
| [Troubleshooting](docs/TROUBLESHOOTING.md) | Diagnosing checkout issues using the built-in logger; common symptoms and fixes |
| [Compatibility](docs/COMPATIBILITY.md) | Known interactions with WPFunnels, caching plugins, security plugins, themes, and managed hosts |
| [Changelog](CHANGELOG.md) | Version history |

---

## Requirements

- WordPress 6.0+
- WooCommerce 8.3+ (for HPOS and block-checkout support)
- PHP 7.4+ (PHP 8.0+ recommended; tested on 8.3)
- An XPay merchant account — sign up at <https://xpay.app/>

---

## Support

- **Plugin bugs / feature requests:** open an issue in this repo.
- **XPay account, API access, payment-method enablement, refunds, settlements:** contact XPay support directly.
- **Staging dashboard:** <https://staging.xpay.app/admin/login/>
- **Production dashboard:** <https://community.xpay.app/admin/login/>
- **XPay API docs:** <https://xpayeg.github.io/docs/>

When reporting a checkout issue, enable the diagnostic logger first (gateway settings → Diagnostic logger), reproduce the issue, then attach the resulting log file from `wp-content/uploads/xpay-logs/`. Secrets and PII are redacted at write time. See [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) for details.

---

## License

Refer to LICENSE in this repository, or contact the plugin author.
