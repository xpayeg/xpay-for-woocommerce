# Browser tests

Playwright checks for behaviour that only exists in a browser, against the
plugin's real front-end files. They are not part of `composer test` (that
suite is PHP and hermetic); run them by hand when touching the pay page.

## Payment-window escape test

`trap-harness.html` stands in for the XPay SDK with a faithful model of its
close semantics, taken from the SDK source: closing is allowed until the
shopper submits a payment (`XPAY_EMBED_CONFIRMED`), re-allowed only on
success (`XPAY_EMBED_SUCCESS`), and a close request is otherwise dropped.
That is the platform bug reported as xpayeg/woocommerce#1, and the reason
`checkout-modal.js` honours the dropped request itself.

The harness loads `assets/js/checkout-modal.js` unchanged, so the test
exercises the shipped file rather than a copy.

```bash
# from the plugin root
npm install playwright-core        # once; not a plugin dependency
php -S 127.0.0.1:8099 -t . &
node tools/browser-tests/trap-test.mjs
```

Needs `playwright-core` and a Chromium build. In a Claude Code cloud
session both are present: Chromium lives under `/opt/pw-browsers/`, and the
test points at it directly.

Eleven checks cover: the trap reproducing, the shopper being freed, the
message naming a failed attempt, Pay now building a fresh window, the
success path staying untouched, a normal pre-payment close remaining the
SDK's job, and a close message from a foreign origin being ignored.

## valU wallet-number tests

Two checks against a running test store, not a harness: they drive the real
classic checkout and the real gateway rows.

`wallet-phone-test.mjs` covers the prompt itself. The card row never shows
it, the valU row shows it for a number that completes to a well-formed +20
that reaches nobody, it disappears once a real Egyptian mobile is entered,
and a number typed into it survives a WooCommerce checkout refresh.

`foreign-card-test.mjs` covers the rule that must never leak. A shopper in
the United Kingdom with a British mobile pays by card and is not stopped.
The proof is precise: with dummy keys the session call fails and the shopper
sees "The payment could not be started", which is produced inside
process_payment, and WooCommerce only reaches process_payment once
validate_fields() has passed.

Both need the classic checkout. The test store's checkout page is the
Blocks one, so create a probe page first and delete it after:

```bash
wp post create --post_type=page --post_status=publish \
  --post_title="Classic Checkout Probe" --post_name=classic-checkout-probe \
  --post_content='[woocommerce_checkout]' --allow-root
node tools/browser-tests/wallet-phone-test.mjs
node tools/browser-tests/foreign-card-test.mjs
wp post delete <id> --force --allow-root
```

Set `XPAY_SHOT_DIR` to choose where screenshots land; it defaults to the
working directory.

The classic checkout is the only surface these cover. The Blocks checkout
does not use `payment_fields()` and has no prompt yet.
