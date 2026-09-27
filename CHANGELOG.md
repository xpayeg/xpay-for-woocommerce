# Changelog

## 1.0.4

### Patch Changes

- The installation steps on the plugin page now describe installing XPay from the WordPress plugin directory.
- The webhook health indicator no longer reports a failure when two payment updates for the same order arrive at the same moment. XPay retries the second update and the order is unaffected.

## 1.0.3

### Patch Changes

- XPay no longer clashes with other plugins on your site that use similar names. If you installed an earlier version from GitHub, open WooCommerce > Settings > Payments > XPay and connect again after updating.

## 1.0.2

### Patch Changes

- Corrected the Terms of Service and Privacy Policy links shown on the plugin page. Arabic now arrives as a WordPress language pack instead of being bundled, so translations can update without a plugin update.

## 1.0.1

### Patch Changes

- Corrected a link in the plugin header.

## 1.0.0

### Major Changes

- First release. Accept card, ValU and Fawry payments on your WooCommerce checkout. Connect your XPay account in one click with no API keys to copy, with webhooks set up for you, refunds from WooCommerce, test and live modes, payment method controls, HPOS support and Arabic.
