# Session handoff — XPay for WooCommerce v3

Read this first when picking the work up in a new session. It is the
continuation contract: what is done, what is queued, what is waiting on a
decision, and how to rebuild the environment.

Working with **ma@xpay.app** (XPay Egypt founder, macOS). Give
keystroke-level, plain-English instructions for anything that happens on
their own machine. This file is repo-only (`.distignore`), so it never
ships to WordPress.org.

## Repos and branches

| What | Where | Branch | Never do |
|---|---|---|---|
| Plugin (primary) | `xpayeg/xpay-for-woocommerce` | **`v3`** | push anywhere else |
| Test store (vendors the plugin) | `xpayeg/woocommerce` | **`v3-test-store`** | push anywhere else |
| Platform monorepo (read-only reference) | `xpayeg/xpay` | `master` | never push |

No pull requests unless explicitly asked. No model identifiers in anything
pushed to a repository (the harness commit footer is the established
exception).

After every plugin change, mirror it into the store repo and commit both:

```bash
rsync -a --delete --exclude vendor --exclude .git --exclude node_modules \
  --exclude .phpunit.result.cache \
  "$PLUGIN_REPO"/ \
  /home/user/woocommerce/wp-content/plugins/xpay-for-woocommerce/
```

## Gates before every commit

```bash
cd /workspace/xpay-for-woocommerce
COMPOSER_ALLOW_SUPERUSER=1 composer lint      # phpcs, WordPress-Extra
COMPOSER_ALLOW_SUPERUSER=1 composer test      # both suites: 95 pure + 65 contract
bash bin/check-voice.sh                        # no em dashes in translatable strings
bash bin/check-doclinks.sh                     # after touching any .md
node --check assets/js/<file>                  # after touching any JS
```

Never read `$?` after a pipe. New top-level files must be weighed against
`.distignore`: Plugin Check scans the staged ZIP, and shipping a dev file
has broken CI before.

For every new user-facing string: regenerate the POT, add the Arabic to the
dictionary in `tools/make-ar-po.py`, regenerate, then build the MO.

```bash
wp i18n make-pot . languages/xpay-for-woocommerce.pot \
  --exclude=vendor,node_modules,tests,tests-contracts --allow-root
python3 tools/make-ar-po.py     # fails loudly on any msgid it cannot translate
wp i18n make-mo languages --allow-root
```

The generator's dictionary is keyed by exact English msgid: when an English
string changes, its dictionary key must change in the same edit. 260 strings
are translated today.

## Rebuilding the environment in a fresh session

1. **Attach the other repos** (the session starts with the store repo only):
   use `add_repo` for `xpayeg/xpay-for-woocommerce` and, when platform
   questions come up, `xpayeg/xpay`. **Do not trust the path `add_repo`
   reports.** It says `/workspace/`, but a repo the session already carries
   is checked out under `/home/user/` instead, and `/workspace/` may not
   exist at all until you `mkdir` it. Locate each one before using it, and
   set `PLUGIN_REPO` for the mirror command above:
   ```bash
   PLUGIN_REPO=$(ls -d /home/user/xpay-for-woocommerce /workspace/xpay-for-woocommerce 2>/dev/null | head -1)
   ```
   Last session: store at `/home/user/woocommerce`, plugin at
   `/home/user/xpay-for-woocommerce`, monorepo at `/workspace/xpay`.
2. **Start the test store**. MariaDB is *not* in the container image, so a
   fresh session installs it first and gives root the password `.env`
   expects (the restore script authenticates as root over TCP):
   ```bash
   sudo apt-get update -qq
   sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mariadb-server mariadb-client
   sudo service mariadb start
   cd /home/user/woocommerce
   set -a; . ./.env; set +a
   sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('$DB_PASSWORD'); FLUSH PRIVILEGES;"
   nohup php -S 127.0.0.1:8080 -t /home/user/woocommerce >/dev/null 2>&1 &
   ```
   (`apt-get update` prints 403s for the deadsnakes and ondrej PPAs. They
   are unrelated repositories and the install still succeeds.)

   **Both services die when the session idles, not just MariaDB.** The PHP
   server goes with its shell. Any `500`, a connection refused on `:8080`,
   or a `wp` command exiting 1 with no output means restart both before
   diagnosing anything else. After a restart the database itself survives on
   disk, so the fixtures do not need restoring again.
3. **Restore the fixtures** (a fresh container has an empty database):
   ```bash
   bash /home/user/woocommerce/dev-fixtures/restore-test-store.sh
   ```
   See `dev-fixtures/README.md` for what the snapshot contains.
4. **Browser checks**: Chromium is at `/opt/pw-browsers/`, and
   `npm install playwright-core` provides the driver. See
   `tools/browser-tests/README.md`.

WP admin is `admin` / password held by the user (deliberately not in git).

## State as of this handoff

Plugin `v3` and the store branch are both pushed and CI is green across the
matrix (PHP 7.4/8.3/8.4, phpcs, voice check, Plugin Check, both suites).

Shipped recently: the ten-defect money-truth pass (EGP-only refunds failing
closed, deterministic refund idempotency, already-paid session handling,
pay-page revalidation, gross totals, per-plane webhook health, currency
gating, multisite lifecycle, in-admin doc viewer); the eight founder
decisions (expiry marks orders failed so pay links survive, per-decline
order notes, dashboard refunds mirrored back, superseded-session payments
parked on hold, currency-rejection notice, cron schema convergence); the
"Pay now" checkout button and `metadata.integration = woocommerce`; and the
escape from a payment window the SDK refuses to close.

**The Elements switch.** XPay's payment fields now render on the store's own
checkout page instead of opening the drop-in window, which is what avoids
issue #1 (the window that will not close after a failed attempt). The pay
page is deliberately unchanged and still uses the window: there the order
and its total are already final.

This brought a session that must exist *before* the order does, because the
fields live in an iframe whose URL is built from the session id
(`packages/sdk/runtime/src/elements.ts:296`). So the session is created from
the cart and its amount has to follow a cart that is still moving. Four
admin-ajax endpoints carry that: `session`, `sync`, `paying`, `paid`. The
amount is never read from the request; it is recomputed from the cart on
every call.

The per-method gateway rows are gone — one XPay row now, because the
element's own accordion lists exactly what the merchant's account has
enabled and a second list in WooCommerce can disagree with it. `wallet` was
renamed to `bnpl` throughout (valU is buy-now-pay-later, not a wallet). The
methods selector in settings was replaced by a Theme setting: Auto (measures
the store's real colours and follows the shopper's device) / Light / Dark.

**Rebuilt at `87a2737` after reading the documentation properly.** The first
cut was written from SDK source and inference; the Elements docs at
`/workspace/xpay/apps/docs/content/docs/(docs)/integrate/integration-patterns/elements.mdx`
answer almost all of it and were not read. That cost:

- The `lineItems` payload was invalid. `LineItemInputDto` takes
  `price | priceData | quantity | adjustableQuantity`; the plugin sent flat
  `{ name, unitAmount, quantity }`, which the API rejects rather than strips
  (`forbidNonWhitelisted`). Every session it opened would have failed.
- `checkout.fetchUpdates()` is the documented way to tell a mounted element
  the session changed. It was never called, so `sync` patched server-side
  and the fields kept quoting their load-time total.
- The pay button had no gate. The docs require `event.complete` **and**
  `checkout.canConfirm`; neither was consulted.
- Terminal sessions (`expired`, `complete`) were not checked before mounting.
- `checkout.on('error')` was not subscribed.

All fixed. Three adversarial reviewers (invented-API, wrong-amount,
regression) raised ten findings that reduced to four real bugs, all fixed.

## Queue — work on these one at a time, in this order

0. **Test API keys, then delete the stub.** Nothing has ever run against the
   real XPay API. Set `XPAY_TEST_API_KEY` (`rk_test_…`, restricted, Checkout
   Sessions + Refunds), `XPAY_TEST_PUBLISHABLE_KEY` (`pk_test_…`) and
   optionally `XPAY_TEST_WEBHOOK_SECRET` (`whsec_…`) as environment
   variables, write them into `woocommerce_xpay_settings` without echoing
   them, then **delete the `XPAY_WC_API_BASE` define from the store's
   `wp-config.php`**. That define points at a local stub which validated
   nothing: it read `lineItems[0].unitAmount` out of whatever it was handed
   and echoed a session back, which is exactly why an invalid payload passed
   18 of 18 browser tests. The SDK is not stubbed and must not be — the real
   one at `https://checkout.xpay.app/v1/sdk.js` is reachable.

   There is **no firewall problem**. An earlier session claimed XPay's WAF
   was blocking this host; that was wrong and is corrected in
   `xpayeg/woocommerce@1f9ac4ac`. `POST /checkout/sessions` answers 401;
   only the invented `/v1/` prefix trips the WAF, because no such path
   exists.

1. **Verify the pay gate per payment method. Ship blocker.** `confirm()` is
   gated on `event.complete && checkout.canConfirm`. `complete` is confirmed
   to arrive for card and is **unverified for valU and Fawry**, whose embeds
   may need no local input and may never emit it. If one does not, that
   method can never be paid and the button stays dead. If it bites: treat a
   non-card selection as complete, or fall back to `submit()` alone.

2. **Rewrite `tools/browser-tests/foreign-card-test.mjs`.** It drives the
   per-method rows that no longer exist, so it does not run. It covers a
   rule that must never leak: a shopper whose billing number is not Egyptian
   or Jordanian must never have that number sent as a valU number.

3. **Give the new behaviour permanent tests.** Terminal states, the pay
   gate, `refresh()` and the error subscription were proven by a throwaway
   smoke script that no longer exists. Promote that into
   `tools/js-tests/elements-test.mjs`.

4. **Verify the Blocks sync prop.** `blocks-integration.js` keys its sync
   effect on `props.billing.cartTotal.value`, unverified against the Blocks
   version this plugin targets. It degrades safely, so the symptom is a fix
   that silently does nothing.

5. **Checkout-page modal** — open the payment window on the checkout page
   itself, keeping that page alive underneath. Mechanics are fully verified
   against WooCommerce core; see the task notes. **Blocked on two product
   calls from the user:** does it replace the redirect by default or become
   a setting, and what (if anything) the shopper sees when they close it.
6. **Full refunds for non-EGP orders** — the platform refunds the whole
   remaining balance when the amount is omitted, so full refunds need no
   conversion and could be re-enabled for non-EGP stores while partials stay
   blocked. **Blocked on the user's yes/no** (money policy).
7. **WordPress.org listing screenshots** — `readme.txt` still has no
   `== Screenshots ==` section. Propose five or six screens for approval.
   `wporg-assets/` and `screenshots/` are distignored by design; the SVN
   `/assets/` directory is the destination.
8. **WordPress 7.1 smoke test** — `readme.txt` declares "Tested up to: 7.1"
   because Plugin Check requires the current version, but no 7.1 install was
   ever exercised: this environment's network policy blocks wordpress.org.
   The claim currently rests on an API review only (every core and
   WooCommerce function the plugin calls is long-stable). Owed: update core
   on a machine with wordpress.org access, then walk settings, checkout
   (classic and Blocks), pay page, receipt and log.
9. **Currency-notice copy fix** — the admin notice says "your XPay account
   has no exchange rate configured"; rates are platform-global, not
   per-account. Fold into the next commit.

## Platform issues (filed, not ours to fix)

Twelve findings from the adversarially-verified investigation are filed as
issues **#1 to #12 on `xpayeg/woocommerce`**, assigned to `Elmosh`, each
stating the problem, the evidence, and how the plugin copes. Three more were
already open on the platform repo (`xpayeg/xpay` #411 webhook 2FA guard,
#414 webhook API surface, #413 vault/off-session) and one original suspicion
was refuted (the WAF user-agent ask). The full dossier with verdicts is
published at
<https://claude.ai/code/artifact/7d8bc988-9f61-4ecb-8334-cde4b2eb1916>.

Issue #1 (the payment window that will not close) is the one with live
shopper impact; the plugin works around it, and the comment on that issue
records what the workaround cannot cover.

## Working norms

- UI work starts with design options on a canvas for the user to pick from,
  built with real XPay tokens from the monorepo, then Playwright screenshots
  as proof. Canvases: welcome screens
  <https://claude.ai/code/artifact/43346c25-d48f-47b0-80ed-83d98c8ae76a>,
  confirmation page
  <https://claude.ai/code/artifact/cc092d2b-7f53-496f-bf38-5a6359f4069d>,
  manage screen
  <https://claude.ai/code/artifact/59d63401-409b-4b1d-826e-b794ddc8a993>.
- **Read the documentation before the source.** The Elements docs and this
  repo's own `AGENTS.md` both existed and both went unread for a whole
  session, which produced an invalid payload, a reinvented `fetchUpdates()`,
  and a rule violation (a registry key dissolved into five hardcoded
  copies). Inference from source is the fallback, not the opening move.
- **Never state a URL, endpoint or API you have not fetched or grepped.**
  One session invented `sdk.xpay.app` and a `/v1/` path prefix, then built a
  confident story about XPay's firewall blocking its own API on top of the
  second one and reported it as fact. Note that this project had *already*
  refuted one WAF theory before that.
- **A stub that does not validate is worse than no stub**, and a test that
  passes against your own assumptions has proven nothing. Ask what the test
  would have to do to fail; if the answer is "nothing the real system does",
  it is not a test.
- **Say "I am inferring" while inferring.** If a user has to ask whether
  something was verified or assumed, the answer was already assumed.
- Never paint a status green without a real truth source. This rule is why
  webhook health is per-plane and why the 7.1 claim above is stated as debt.
- Audits and multi-agent work run as workflows with at least two aggressive
  validators per finding (an evidence lens and an applicability lens).
- The live product is ground truth over the monorepo when they disagree:
  dashboard copy was corrected once from a screenshot the user supplied.
- Commit messages are story-first prose explaining why, not what.
- After pushing, arm a CI check-in with `send_later` (about 11 minutes).
  Listing GitHub Actions returns a huge payload: save it and parse only
  `head_sha`, `status` and `conclusion` with python.

## Standing items

- Environment quirks worth not rediscovering: `ignoreHTTPSErrors` is a
  Playwright **context** option, not a launch option; the store's
  `wp-config.php` is CRLF, so edit it byte-wise or the diff rewrites every
  line; `wp-cli` is not installed and must be fetched as a phar for the i18n
  steps; the store's `/checkout` (page 12) is the **Blocks** checkout and a
  classic `[woocommerce_checkout]` page lives at **page 29** — both need
  testing and they render the XPay row differently.
- **Security cleanup owed by the user**: roll the test API key that was
  pasted into chat earlier in the project, and remove the tunnel entry
  pointing an `*.xpay.app` hostname at localhost from `~/.cloudflared/config.yml`.
- Webhook end-to-end test deferred until live hosting exists.
- CartFlows/FunnelKit shim: build only when a real rewritten URL is seen.
- The test store's XPay keys are dummies, so the settings badge honestly
  reads "Keys saved, not validated yet".
