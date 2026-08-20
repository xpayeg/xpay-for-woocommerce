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
