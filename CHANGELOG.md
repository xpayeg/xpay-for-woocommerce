# Changelog

## 1.0.3

### Patch Changes

- Every name the plugin defines now carries the xpayeg prefix: the gateway id, classes, functions, options, hooks, AJAX actions, order meta, constants, script handles, CSS classes, element ids, data attributes and the WooCommerce log source. This answers the WordPress.org review. A store that installed 1.0.x must open WooCommerce > Settings > Payments > XPay and connect again; the webhook address and the settings option changed with the gateway id.

## 1.0.2

### Patch Changes

- Fix the Terms of Service and Privacy Policy links and the WordPress.org contributor name in the plugin listing, and stop bundling translation files: WordPress now installs the Arabic translation as a language pack from WordPress.org.

## 1.0.1

### Patch Changes

- Point the plugin's `Plugin URI` header at the public repository so it differs from `Author URI`, resolving the WordPress.org Plugin Check error that blocks submission.

## 1.0.0

### Major Changes

- Launch XPay for WooCommerce with secure on-site payments, one-click account connection, automatic webhooks, refunds, test and live modes, payment-method controls, HPOS support, and Arabic translations.
