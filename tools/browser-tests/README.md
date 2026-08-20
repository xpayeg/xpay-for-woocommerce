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

## Elements tests

Two suites against a running test store, covering the payment fields on
the store's own checkout page.

`elements-test.mjs` needs no SDK. It covers the markup and the server
rules: one XPay row where there used to be up to four, the mount point and
the valU prompt present, a forged nonce refused, and the in-flight guard —
lock a payment, move the cart, and watch the amount change be refused
(`locked`) and the pay attempt refused (`stale-amount`) until the payment
ends. That guard is the plugin standing in for a platform check that does
not exist; see xpayeg/woocommerce#2.

`elements-sdk-test.mjs` needs a fake SDK, because the real one is remote
and a test store has dummy keys. It covers what only appears once the
fields are mounted: the valU prompt following the method picked inside the
fields (card no, valU yes, Fawry no — the rule is a method list, not a
not-card test), the store's own theme reaching the SDK, and the number the
shopper types being the one sent to be charged. Both checkouts, classic
and Blocks.

### Setup

The store needs a stub API and a fake SDK, and `wp-config.php` must point
at them:

```php
define( 'XPAY_WC_API_BASE', 'http://127.0.0.1:8099/v1' );
define( 'XPAY_WC_SDK_URL', 'http://127.0.0.1:8099/sdk/sdk.js' );
```

Both overrides are honoured only when the API base itself is loopback, so
they cannot quietly redirect a real store.

The tests assume a classic checkout page (the `[woocommerce_checkout]`
shortcode) alongside the Blocks one, since a modern store's `/checkout` is
Blocks. Point them elsewhere with `CLASSIC_CHECKOUT`, `BLOCKS_CHECKOUT`
and `SHOP_PAGE`.

```bash
node tools/browser-tests/elements-test.mjs
node tools/browser-tests/elements-sdk-test.mjs
```

## Foreign-card test

`foreign-card-test.mjs` covers the rule that must never leak: a shopper
whose billing number is not Egyptian or Jordanian must not have that
number sent as a valU number. It predates the Elements switch and drives
the old per-method rows, so it needs rewriting against the single row
before it can run again.
