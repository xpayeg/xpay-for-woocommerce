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

## Queue — work on these one at a time, in this order

1. **Checkout-page modal** — open the payment window on the checkout page
   itself, keeping that page alive underneath. Mechanics are fully verified
   against WooCommerce core; see the task notes. **Blocked on two product
   calls from the user:** does it replace the redirect by default or become
   a setting, and what (if anything) the shopper sees when they close it.
2. **Full refunds for non-EGP orders** — the platform refunds the whole
   remaining balance when the amount is omitted, so full refunds need no
   conversion and could be re-enabled for non-EGP stores while partials stay
   blocked. **Blocked on the user's yes/no** (money policy).
3. **WordPress.org listing screenshots** — `readme.txt` still has no
   `== Screenshots ==` section. Propose five or six screens for approval.
   `wporg-assets/` and `screenshots/` are distignored by design; the SVN
   `/assets/` directory is the destination.
4. **WordPress 7.1 smoke test** — `readme.txt` declares "Tested up to: 7.1"
   because Plugin Check requires the current version, but no 7.1 install was
   ever exercised: this environment's network policy blocks wordpress.org.
   The claim currently rests on an API review only (every core and
   WooCommerce function the plugin calls is long-stable). Owed: update core
   on a machine with wordpress.org access, then walk settings, checkout
   (classic and Blocks), pay page, receipt and log.
5. **Currency-notice copy fix** — the admin notice says "your XPay account
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

- **Security cleanup owed by the user**: roll the test API key that was
  pasted into chat earlier in the project, and remove the tunnel entry
  pointing an `*.xpay.app` hostname at localhost from `~/.cloudflared/config.yml`.
- Webhook end-to-end test deferred until live hosting exists.
- CartFlows/FunnelKit shim: build only when a real rewritten URL is seen.
- The test store's XPay keys are dummies, so the settings badge honestly
  reads "Keys saved, not validated yet".
