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

Screenshots land in `tools/browser-tests/screenshots/`, which is ignored by
both git and the distributable. Set `XPAY_SHOT_DIR` to send them elsewhere.

`blocks-wallet-phone-test.mjs` covers the same prompt on the Cart & Checkout
Blocks checkout, which is the test store's default page, so it needs no probe
page. It is really a test of a round trip: the rule stays in PHP and only its
verdict crosses, on the Store API cart response, so the test edits the billing
phone and asks whether the prompt follows.

It also pins the two traps that surfaced while building it. Selecting the valU
row makes Blocks sync its draft order against the same endpoint the gate
listens on, and nothing may be refused at that point; and an order whose
prompt has been answered must reach the payment attempt, which it did not
while the classic `validate_fields()` was still running on Store API requests
it could not read.

```bash
node tools/browser-tests/blocks-wallet-phone-test.mjs
```

Assertions there read the Store API responses rather than page text: the
prompt's own label contains the words "valU wallet", so a whole-page match
would read the prompt as an error and pass whatever happened.
