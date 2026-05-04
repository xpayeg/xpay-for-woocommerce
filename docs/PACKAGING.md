# Packaging for distribution

The plugin ships through two channels:

- **WordPress.org plugin directory** — slug `xpay-for-woocommerce`. End users install via WP Admin → Plugins → Add New → search.
- **Manual download** (GitHub release / direct ZIP upload) — same slug, same ZIP shape, distributed however you like.

Both channels use the same ZIP. This doc explains how to build it and how to publish it.

---

## TL;DR — build the ZIP

From the plugin root:

```bash
./bin/build.sh
```

That writes:

```
dist/xpay-for-woocommerce-{VERSION}.zip
```

…where `{VERSION}` is read from the plugin header. The ZIP contains a top-level directory named `xpay-for-woocommerce/` with the plugin runtime files inside, ready to drop into `wp-content/plugins/`.

---

## What the build script does

1. Reads `Version:` from `woocommerce-xpay-gateway.php` plugin header.
2. Sanity-checks that the `WC_XPAY_VERSION` PHP constant matches.
3. Sanity-checks that `readme.txt`'s `Stable tag:` matches.
4. Refuses to build if any of those drift.
5. `rsync`s the plugin tree into a temp staging dir at `<tmp>/xpay-for-woocommerce/`, applying every exclusion pattern in `.distignore`.
6. `zip -r -X` the staging dir into `dist/xpay-for-woocommerce-{VERSION}.zip` (the `-X` strips macOS resource forks so the archive is byte-identical to one built on Linux).
7. Prints size + file count.

---

## What gets EXCLUDED from the ZIP

See [`.distignore`](../.distignore) for the canonical list. Roughly:

| Bucket | Examples |
|---|---|
| Version control | `.git/`, `.gitignore`, `.gitattributes`, `.github/` |
| Editor / IDE | `.idea/`, `.vscode/`, `*.swp` |
| OS junk | `.DS_Store`, `Thumbs.db`, `._*` |
| Build / CI | `bin/`, `dist/`, `build/`, `composer.lock`, `node_modules/`, `Makefile` |
| Tests | `tests/`, `phpunit.xml`, `phpcs.xml` |
| Repo-only docs | `README.md`, `CHANGELOG.md`, `docs/` (WP.org uses `readme.txt` and the bundled `== Changelog ==` section) |
| Repo-only assets | `screenshots/` (WP.org screenshots live in the SVN `/assets/` directory, uploaded separately) |
| Logs / temp | `*.log`, `*.tmp`, `*.bak` |

If you add a file to the plugin root that should NOT ship to merchants, add it to `.distignore` first.

---

## What's INSIDE the ZIP

After running the build, verify with:

```bash
unzip -l dist/xpay-for-woocommerce-2.0.0.zip
```

You should see (and ONLY see) these top-level paths under `xpay-for-woocommerce/`:

| Path | Purpose |
|---|---|
| `woocommerce-xpay-gateway.php` | Main plugin file (loaded by WP) |
| `readme.txt` | WP.org plugin directory metadata, description, FAQ, changelog |
| `actions.php` | AJAX action handlers |
| `utils.php` | HTTP wrappers + circuit breaker + helpers |
| `update_order.php` | Public webhook receiver |
| `check_transaction.php` | Modal-close status poll endpoint |
| `class-wc-xpay-blocks-integration.php` | Cart/Checkout Blocks integration |
| `promo_code_section.php` | Promo-code UI partial |
| `assets/` | CSS + JS bundle |
| `includes/` | Logger, admin notices, WPFunnels compat, blocks integration |
| `languages/xpay-for-woocommerce.pot` | Translator template (114 strings) |

There should be NO `.git/`, NO `.gitignore`, NO `bin/`, NO `dist/`, NO `docs/`, NO `README.md`, NO `CHANGELOG.md`, NO `.DS_Store`.

---

## Releasing a new version

For each release:

1. **Bump the version** in three places (the build script enforces they all match):
   - `woocommerce-xpay-gateway.php` plugin header `Version:` line
   - The `WC_XPAY_VERSION` constant on the next non-comment line
   - `readme.txt` `Stable tag:` line
2. **Add a changelog entry** to `CHANGELOG.md` (full detail, semver-formatted) AND to `readme.txt` under `== Changelog ==` (user-facing summary).
3. **Add or update** the `== Upgrade Notice ==` block in `readme.txt` if anything notable changes for upgraders.
4. **Re-generate the `.pot`** so translators see the new strings:
   ```bash
   wp i18n make-pot . languages/xpay-for-woocommerce.pot \
     --slug=xpay-for-woocommerce --domain=xpay-for-woocommerce --skip-audit
   ```
   (Run in the plugin root, requires `wp-cli/i18n-command` package.)
5. **Build:**
   ```bash
   ./bin/build.sh
   ```
6. **Smoke-test the ZIP** by uploading it on a fresh WP install (WP Admin → Plugins → Add New → Upload Plugin) and confirming activation, basic checkout flow, and the Plugins-list metadata.
7. **Commit** the source state to git.
8. **Tag the release:** `git tag v{VERSION} && git push --tags`.
9. **Publish to WP.org SVN** (see next section).

---

## First-time submission to wordpress.org

If this is the first time submitting to WP.org:

1. Submit the plugin for review at <https://wordpress.org/plugins/developers/add/>.
2. Upload the ZIP produced by `bin/build.sh`.
3. Wait for the WP.org plugin review team to approve (typically 1–14 days).
4. After approval, you'll receive SVN repository credentials at `https://plugins.svn.wordpress.org/xpay-for-woocommerce/`.
5. Continue with the per-release publish flow below.

---

## Publishing a new version to wordpress.org SVN

WP.org uses Subversion, not git. After the build:

```bash
# One-time: check out the WP.org SVN repo into a separate working directory
svn co https://plugins.svn.wordpress.org/xpay-for-woocommerce/ wporg-svn

# Per-release:
cd wporg-svn

# 1. Sync the freshly-built plugin into trunk/
rm -rf trunk/*
unzip -d trunk/.. ../xpay-for-woocommerce/dist/xpay-for-woocommerce-2.0.0.zip
# (the unzip puts xpay-for-woocommerce/ into trunk/.., which becomes trunk/)

# 2. svn add any new files, svn rm any deletions
svn status | grep '^?' | awk '{print $2}' | xargs -r svn add
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm

# 3. Tag the release
svn cp trunk tags/2.0.0

# 4. Commit
svn ci -m "Release 2.0.0"
```

Within ~30 minutes WP.org's plugin directory will pick up the new tag and update the "Stable tag" pointer (assuming `readme.txt` in trunk/ has `Stable tag: 2.0.0`).

---

## Banner / icon / screenshots (WP.org SVN /assets/ directory)

WP.org plugin pages support custom imagery uploaded into `/assets/` at the SVN root (NOT inside the plugin ZIP):

- `/assets/banner-1544x500.png` (large banner shown at the top of the plugin page)
- `/assets/banner-772x250.png` (small banner for legacy themes)
- `/assets/icon-256x256.png` (icon shown in WP Admin → Plugins → Add New cards)
- `/assets/icon-128x128.png` (smaller icon)
- `/assets/screenshot-1.png`, `/assets/screenshot-2.png`, … (matching the `== Screenshots ==` section in `readme.txt`)

Drop them into the `assets/` directory at the SVN root and `svn ci`. They appear on the public plugin page within ~30 min.

---

## Requirements

- **bash** (the build script uses `set -euo pipefail`)
- **rsync** (filesystem copy with exclusions)
- **zip** (BSD or InfoZIP)
- **wp-cli** with `wp-cli/i18n-command` package — only needed for `.pot` regeneration. Install once with `wp package install wp-cli/i18n-command`.
- **svn** — only needed for WP.org publishing.

All available on macOS / Linux out of the box, except the wp-cli i18n package which is a one-time install.
