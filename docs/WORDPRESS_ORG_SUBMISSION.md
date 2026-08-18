# WordPress.org plugin directory — submission guide

This is a step-by-step checklist for getting **XPay for WooCommerce** listed at <https://wordpress.org/plugins/xpay-for-woocommerce/>. Follow it in order on the first submission. After approval, jump to "Per-release publishing" for every subsequent version.

> **Audience:** the merchant who owns the wordpress.org plugin entry. Steps marked **[YOU]** require you to do something outside the codebase (account, URL, image creation, manual upload).

---

## 0. Before you start — pre-submission checklist

Run through these once. Anything not checked here will trip the WP.org review.

### Codebase state (already done in v3.0.0)

- [x] Plugin name does not start with "WordPress" or "WooCommerce" ("XPay for WooCommerce")
- [x] Plugin slug matches text domain (`xpay-for-woocommerce`)
- [x] `readme.txt` present at plugin root with all required headers, including the `== External services ==` disclosure
- [x] `License: GPL-2.0-or-later` declared in plugin header AND in `readme.txt`
- [x] No CDN-hosted libraries or remote assets — styles, scripts, and payment-method logos all ship in the plugin; the only remote script is XPay's own payment SDK (`sdk.js` from checkout.xpay.app), disclosed under External services
- [x] No tracking/telemetry beyond operational XPay API calls
- [x] Bundled `.pot` translation template plus the Arabic translation (`-ar.po` / `-ar.mo`) in `/languages/`
- [x] HPOS and Cart/Checkout Blocks compatibility declared on `before_woocommerce_init`
- [x] WordPress Plugin Check runs green in CI on every PR, against the `.distignore`-staged distributable (the same check WP.org runs on submission)
- [x] PHPCS (WordPress-Extra) and PHPUnit gate every PR in CI
- [x] End-to-end verified in test mode: payment window, signed webhook, refund from the order screen

### Three things that need YOUR action before submission

These are the gaps documented in earlier rounds — they're all manual / external:

1. **WordPress.org account** **[YOU]**
   - Register at <https://login.wordpress.org/register> if you don't have an account.
   - Note your username — it goes in `readme.txt` `Contributors:` line.
   - Currently `readme.txt` says `Contributors: xpay`. **Edit that line to your actual wordpress.org username (or comma-separated list if multiple maintainers).** Find it in [`readme.txt`](../readme.txt#L2).

2. **Verify XPay legal URLs** **[YOU]**
   - In `readme.txt` under `== External services ==` you'll see:
     - `XPay Terms of Service: <https://xpay.app/terms>`
     - `XPay Privacy Policy: <https://xpay.app/privacy>`
   - **These are placeholders** — confirm with XPay's team that these URLs exist and serve the correct content. Replace with the canonical URLs they publish.

3. **WordPress.org plugin-page assets (banner + icon + screenshots)** **[YOU]**
   - These are NOT part of the plugin ZIP. They live separately in the SVN `/assets/` directory at the WP.org plugin's SVN root and are uploaded by SVN once the plugin is approved.
   - See [`wporg-assets/README.md`](../wporg-assets/README.md) in this repo for the exact files, specs, and a placeholder-friendly workflow.
   - **You can submit the plugin ZIP for review BEFORE these are ready** — assets can be added after approval. `readme.txt` currently has no `== Screenshots ==` section; add one (with captions matching the numbered files) in the same release that uploads the screenshots.

---

## 1. Build the submission ZIP

From the plugin root:

```bash
./bin/build.sh
```

Output:

```
dist/xpay-for-woocommerce-3.0.0.zip
```

**Verify the ZIP** by opening it locally:

```bash
unzip -l dist/xpay-for-woocommerce-3.0.0.zip | head -30
```

Confirm:
- Top-level directory is `xpay-for-woocommerce/`
- `readme.txt` is present
- `xpay-for-woocommerce.php` is present
- `languages/xpay-for-woocommerce.pot` and the Arabic `-ar.po` / `-ar.mo` are present
- NO `.git`, `.gitignore`, `.distignore`, `bin/`, `dist/`, `docs/`, `tests/`, `vendor/`, `composer.json`, `AGENTS.md`, `README.md`, `CHANGELOG.md`, `wporg-assets/`

### Smoke-test the ZIP on a fresh WordPress install

Before submitting, install this exact ZIP on a clean WordPress install and confirm:
- Plugin activates without errors
- WooCommerce → Settings → Payments shows the "XPay" gateway
- Configuring test-mode keys and placing a test order works end-to-end
- Plugin shows up in WP Admin → Plugins with the right name + version

Use a separate test site or a fresh `wp-env` instance. Do NOT smoke-test on a site that still has the legacy v2 plugin active — the two coexist without fatals (the plugin shows an admin warning about it), but the duplicate XPay options at checkout complicate the test.

---

## 2. Submit for review

**One time only**, when first listing on wordpress.org:

1. **[YOU]** Go to <https://wordpress.org/plugins/developers/add/>
2. Sign in with your wordpress.org account
3. Upload `dist/xpay-for-woocommerce-3.0.0.zip`
4. Confirm:
   - Plugin name shown as "XPay for WooCommerce"
   - Slug suggested as `xpay-for-woocommerce`
5. Submit

### What happens next

- The Plugin Review Team queues your plugin for review.
- **Typical wait: 1 to 14 days.** Volume varies; review is by humans.
- You'll receive emails at the address registered to your wordpress.org account.

### Possible outcomes

- **Approved as-is** — you'll get SVN credentials and a link to your plugin page (will 404 until you push the first version to SVN — see step 4).
- **Approved with minor changes requested** — you'll get an email listing the requested fixes. Apply them, rebuild the ZIP, and reply to the email with the new ZIP attached. Don't resubmit via the form; reply on-thread.
- **Rejected** — you'll get a rationale. Most rejections are about: trademark in plugin name (already addressed), missing License header (already addressed), `Tested up to` too old (currently 7.0), tracking/telemetry without consent (we have none), or third-party CDN dependencies (we have none). If you do get rejected, address the feedback and reply on-thread with a fixed ZIP.

### Common review feedback patterns to expect for THIS plugin

Even with our cleanup, the WP.org reviewers may flag:

- **The remote payment SDK** (`sdk.js` loaded from checkout.xpay.app on the pay page). Remote scripts are normally disallowed, but PCI-scoped payment SDKs are the accepted exception when disclosed — same pattern as Stripe.js. Reply pointing to the `== External services ==` section in `readme.txt`; the SDK cannot be bundled because the card fields must be served by XPay's PCI-certified origin.
- **`load_plugin_textdomain()`** in `xpay-for-woocommerce.php` (Plugin Check discourages it for WP.org-hosted plugins, and the call carries a `phpcs:ignore`). Reply explaining the plugin also ships via direct download from XPay, where WP.org's automatic translation loading does not apply.
- **Direct database queries** in `includes/logger/class-xpay-log-store.php` (file-level `phpcs:disable` with rationale). Reply explaining it's a bounded custom log table — no core API or cache layer exists for it; identifiers are bound with `wpdb::prepare()`'s `%i` placeholder and every value is prepared.
- **The `xpay_logger_event` action hook** as a "non-prefixed" hook bus. It IS prefixed with `xpay_`. If flagged, reply citing the prefix.

If a reviewer asks for any of these to be removed, push back politely with the design reasoning. They're usually accommodating when the rationale is sound.

---

## 3. After approval — SVN setup

You'll receive an email with:
- Your plugin SVN URL: `https://plugins.svn.wordpress.org/xpay-for-woocommerce/`
- A note that you commit with the wordpress.org account credentials.

**One-time setup:**

```bash
# Choose a folder OUTSIDE this git repo for the SVN checkout
mkdir -p ~/wporg && cd ~/wporg
svn co https://plugins.svn.wordpress.org/xpay-for-woocommerce/ xpay-for-woocommerce-svn
cd xpay-for-woocommerce-svn

# You'll see this layout:
# trunk/    (where the plugin source lives — currently empty)
# tags/     (where each release is tagged — currently empty)
# assets/   (where banner/icon/screenshots live — currently empty)
```

---

## 4. First publish — push v3.0.0 to SVN

From your SVN checkout directory:

```bash
# 1. Empty trunk/ and copy in the plugin from the ZIP
rm -rf trunk/*
unzip -d trunk-tmp /path/to/dist/xpay-for-woocommerce-3.0.0.zip
mv trunk-tmp/xpay-for-woocommerce/* trunk/
rmdir trunk-tmp/xpay-for-woocommerce trunk-tmp

# 2. Add new files, remove deleted ones
svn status | grep '^?' | awk '{print $2}' | xargs -r svn add
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm

# 3. Tag the release
svn cp trunk tags/3.0.0

# 4. Commit
svn ci -m "Release 3.0.0 — initial wordpress.org listing"
```

**SVN will prompt for your wordpress.org username + password.** You may want to set up SVN credential caching so you don't type them every release.

Within ~30 minutes, your plugin page will go live at:

> <https://wordpress.org/plugins/xpay-for-woocommerce/>

WP.org reads the `Stable tag:` line in `trunk/readme.txt` to decide which `tags/X.Y.Z/` directory to serve. As long as `Stable tag: 3.0.0` matches the `tags/3.0.0/` directory you just created, WP.org will offer 3.0.0 as the install version.

---

## 5. Upload the banner / icon / screenshots

These go in `/assets/` at the SVN root (NOT inside `trunk/`).

```bash
# From your SVN checkout dir
cp /path/to/banner-1544x500.png  assets/
cp /path/to/banner-772x250.png   assets/
cp /path/to/icon-256x256.png     assets/
cp /path/to/icon-128x128.png     assets/
cp /path/to/screenshot-1.png     assets/
cp /path/to/screenshot-2.png     assets/
cp /path/to/screenshot-3.png     assets/
cp /path/to/screenshot-4.png     assets/

svn add assets/*
svn ci -m "Add plugin banner, icon, and screenshots"
```

Required files and specs are documented in [`wporg-assets/README.md`](../wporg-assets/README.md).

---

## 6. Per-release publishing (every version after v3.0.0)

Once the plugin is live on wordpress.org, every subsequent release follows this loop:

### Step A — bump versions in the codebase

Three places must match (the build script enforces this):

1. `xpay-for-woocommerce.php` plugin header `Version:` line
2. The `XPAY_WC_VERSION` constant just below the header
3. `readme.txt` `Stable tag:` line

### Step B — update changelogs

1. Add a new section to `CHANGELOG.md` under `## [X.Y.Z] — YYYY-MM-DD`
2. Mirror a user-facing summary into `readme.txt` under `== Changelog ==`
3. Update or add `== Upgrade Notice ==` block in `readme.txt` if breaking/notable change

### Step C — regenerate the .pot and update translations

```bash
wp i18n make-pot . languages/xpay-for-woocommerce.pot \
  --slug=xpay-for-woocommerce --domain=xpay-for-woocommerce --skip-audit
```

Then update `languages/xpay-for-woocommerce-ar.po` for any new or changed strings and recompile the `.mo` (`wp i18n make-mo languages/`). Both steps are manual.

(One-time wp-cli setup if needed: `wp package install wp-cli/i18n-command`)

### Step D — build the ZIP

```bash
./bin/build.sh
```

### Step E — smoke-test on a fresh WP install

Same as for the first submission — install the ZIP on a clean WordPress and verify activation + basic checkout.

### Step F — commit source state to git

```bash
git add ...
git commit -m "release vX.Y.Z: <one-line summary>"
git tag vX.Y.Z
git push origin <branch> --tags
```

### Step G — publish to WP.org SVN

```bash
cd ~/wporg/xpay-for-woocommerce-svn
svn up

# Sync the freshly-built plugin into trunk/
rm -rf trunk/*
unzip -d trunk-tmp /path/to/dist/xpay-for-woocommerce-X.Y.Z.zip
mv trunk-tmp/xpay-for-woocommerce/* trunk/
rmdir trunk-tmp/xpay-for-woocommerce trunk-tmp

svn status | grep '^?' | awk '{print $2}' | xargs -r svn add
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm

svn cp trunk tags/X.Y.Z
svn ci -m "Release X.Y.Z"
```

WP.org will pick up the new tag within ~30 min.

---

## 7. Files in this repo that support submission

| Path | Purpose |
|---|---|
| [`readme.txt`](../readme.txt) | The wordpress.org plugin metadata (description, FAQ, changelog, external-services + privacy disclosure). Required at plugin root. Goes into the ZIP. |
| [`xpay-for-woocommerce.php`](../xpay-for-woocommerce.php) | Plugin main file with the canonical headers (Plugin Name, License, Text Domain, etc.) WP.org indexes. |
| [`languages/xpay-for-woocommerce.pot`](../languages/xpay-for-woocommerce.pot) | Translator template (the bundled Arabic `-ar.po`/`-ar.mo` ship alongside it). WP.org's GlotPress imports from here to enable community translations. |
| [`.distignore`](../.distignore) | Build-time exclusions (everything not in the WP.org ZIP). |
| [`bin/build.sh`](../bin/build.sh) | The reproducible ZIP builder. |
| [`docs/PACKAGING.md`](PACKAGING.md) | Day-to-day build + SVN-publish technical reference. |
| [`docs/WORDPRESS_ORG_SUBMISSION.md`](WORDPRESS_ORG_SUBMISSION.md) | This file — the end-to-end submission guide. |
| [`wporg-assets/README.md`](../wporg-assets/README.md) | Spec for the banner / icon / screenshot files (which live in SVN `/assets/`, NOT in the plugin ZIP). |

---

## 8. What to do RIGHT NOW

If you want to submit today, here's the minimal sequence:

```bash
# 1. Edit Contributors line in readme.txt to your actual WP.org username
$EDITOR readme.txt

# 2. Verify XPay TOS / Privacy Policy URLs are correct (search "External services" in readme.txt)
$EDITOR readme.txt

# 3. Build the ZIP
./bin/build.sh

# 4. Smoke-test on a fresh WP install (use the ZIP at dist/xpay-for-woocommerce-3.0.0.zip)

# 5. Submit at https://wordpress.org/plugins/developers/add/
#    Upload dist/xpay-for-woocommerce-3.0.0.zip

# 6. (Once approved) follow steps 3-5 above for SVN setup + publish
# 7. (Once approved) prepare and upload the assets per wporg-assets/README.md
```

That's it. Email questions about the plugin review process to: <plugins@wordpress.org>.

---

## 9. Reference — official WP.org documentation

- Detailed plugin guidelines: <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>
- Plugin readme.txt standard: <https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/>
- Plugin assets (banner/icon): <https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/>
- Using SVN to publish: <https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/>
- Common review issues: <https://developer.wordpress.org/plugins/wordpress-org/common-issues/>
