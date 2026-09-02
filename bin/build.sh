#!/usr/bin/env bash
#
# Build a WordPress.org-ready ZIP of the plugin.
#
# Usage:
#   ./bin/build.sh
#
# - Reads .distignore at the plugin root for exclusions.
# - Stages everything else under the canonical slug (xpay-for-woocommerce/)
#   in a temp dir.
# - Zips it to dist/xpay-for-woocommerce-{VERSION}.zip where VERSION is
#   read from the plugin header.
# - Refuses to run if .distignore or the plugin main file is missing.
#
# Requirements: bash, PHP, rsync, and zip.

set -euo pipefail

SLUG="xpay-for-woocommerce"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE="$PLUGIN_DIR/xpay-for-woocommerce.php"
DISTIGNORE="$PLUGIN_DIR/.distignore"
DIST_DIR="$PLUGIN_DIR/dist"

if [[ ! -f "$MAIN_FILE" ]]; then
  echo "Error: plugin main file not found at $MAIN_FILE" >&2
  exit 1
fi

if [[ ! -f "$DISTIGNORE" ]]; then
  echo "Error: $DISTIGNORE not found" >&2
  exit 1
fi

# Extract Version: from the plugin header.
VERSION=$(grep -E '^[[:space:]*]*Version:' "$MAIN_FILE" | head -1 | sed -E 's/^[[:space:]*]*Version:[[:space:]]*([0-9.]+).*$/\1/')

if [[ -z "${VERSION:-}" ]]; then
  echo "Error: could not extract Version from $MAIN_FILE plugin header" >&2
  exit 1
fi

# Cross-check against the XPAY_WC_VERSION constant — they must match,
# otherwise enqueued asset versions and the plugin header drift apart.
CONST_VERSION=$(grep -E "define\(\s*'XPAY_WC_VERSION'" "$MAIN_FILE" | head -1 | sed -E "s/.*'([0-9.]+)'.*/\1/")
if [[ -z "${CONST_VERSION:-}" ]]; then
  # An unextractable constant must fail the build, not skip the check —
  # otherwise a renamed/removed constant ships silently unversioned.
  echo "Error: could not extract XPAY_WC_VERSION constant from $MAIN_FILE." >&2
  exit 1
fi
if [[ "$CONST_VERSION" != "$VERSION" ]]; then
  echo "Error: Plugin header Version ($VERSION) != XPAY_WC_VERSION constant ($CONST_VERSION)." >&2
  echo "Bump them in lockstep before building." >&2
  exit 1
fi

# Cross-check against readme.txt Stable tag — WP.org requires they match
# (otherwise WP.org will not consider the new version "stable" for users).
README="$PLUGIN_DIR/readme.txt"
if [[ ! -f "$README" ]]; then
  echo "Error: readme.txt not found — wp.org requires it." >&2
  exit 1
fi
STABLE_TAG=$(grep -E '^Stable tag:' "$README" | head -1 | sed -E 's/Stable tag:[[:space:]]*([0-9.]+).*/\1/')
if [[ -z "${STABLE_TAG:-}" ]]; then
  # An unextractable tag must fail the build, not skip the check —
  # otherwise a renamed/removed tag ships silently mismatched.
  echo "Error: could not extract Stable tag from $README." >&2
  exit 1
fi
if [[ "$STABLE_TAG" != "$VERSION" ]]; then
  echo "Error: Plugin header Version ($VERSION) != readme.txt Stable tag ($STABLE_TAG)." >&2
  echo "Update Stable tag in readme.txt before building." >&2
  exit 1
fi

# package.json is the Changesets version source. The release PR synchronizes it
# into the WordPress-facing files; refusing drift here prevents a tag and ZIP
# from describing different releases.
PACKAGE_VERSION=$(php -r '$data = json_decode(file_get_contents($argv[1]), true); echo $data["version"] ?? "";' "$PLUGIN_DIR/package.json")
if [[ -z "${PACKAGE_VERSION:-}" ]]; then
  echo "Error: could not extract version from $PLUGIN_DIR/package.json." >&2
  exit 1
fi
if [[ "$PACKAGE_VERSION" != "$VERSION" ]]; then
  echo "Error: Plugin header Version ($VERSION) != package.json version ($PACKAGE_VERSION)." >&2
  exit 1
fi

POT="$PLUGIN_DIR/languages/${SLUG}.pot"
POT_VERSION=$(grep -m1 'Project-Id-Version: XPay for WooCommerce ' "$POT" | sed -E 's/.*WooCommerce ([0-9.]+).*/\1/')
if [[ -z "${POT_VERSION:-}" || "$POT_VERSION" != "$VERSION" ]]; then
  echo "Error: Plugin header Version ($VERSION) != translation template version (${POT_VERSION:-missing})." >&2
  exit 1
fi

if [[ "$VERSION" != "0.0.0" ]] && ! grep -qE "^## (\[)?${VERSION//./\.}(\])?($| )" "$PLUGIN_DIR/CHANGELOG.md"; then
  echo "Error: CHANGELOG.md has no $VERSION release." >&2
  exit 1
fi

# Enforce the voice rule in release builds as well as CI.
if [[ -x "$PLUGIN_DIR/bin/check-voice.sh" ]]; then
  bash "$PLUGIN_DIR/bin/check-voice.sh" || exit 1
fi

# Translations are a release artifact, and a stale template is invisible:
# every string added since the last regeneration is simply untranslatable,
# with nothing failing anywhere. Refuse to build on a stale one rather than
# regenerate silently, so the missing Arabic is a decision somebody makes.
if command -v wp >/dev/null 2>&1; then
  TMP_POT=$(mktemp)
  wp i18n make-pot "$PLUGIN_DIR" "$TMP_POT" --slug="$SLUG" \
    --exclude=tests,tests-contracts,tests-integration,tools,bin,vendor,node_modules,dist >/dev/null 2>&1 || true
  if [[ -s "$TMP_POT" ]]; then
    # Compare the msgids only: the header carries a creation date that
    # changes on every run and would make this fire every time.
    if ! diff -q <(grep '^msgid' "$POT" | sort) <(grep '^msgid' "$TMP_POT" | sort) >/dev/null; then
      echo "Error: languages/${SLUG}.pot is out of date." >&2
      echo "Regenerate it, then decide what the new strings should say in Arabic:" >&2
      echo "  wp i18n make-pot . languages/${SLUG}.pot --slug=${SLUG} --exclude=tests,tests-contracts,tests-integration,tools,bin,vendor,node_modules,dist" >&2
      rm -f "$TMP_POT"
      exit 1
    fi
  fi
  rm -f "$TMP_POT"
fi

ZIP_NAME="${SLUG}-${VERSION}.zip"
ZIP_PATH="$DIST_DIR/$ZIP_NAME"
TMP_DIR=$(mktemp -d)
STAGE_DIR="$TMP_DIR/$SLUG"

# Always cleanup the temp dir, even on failure.
trap 'rm -rf "$TMP_DIR"' EXIT

mkdir -p "$DIST_DIR"
mkdir -p "$STAGE_DIR"

# Copy plugin tree to staging, applying .distignore exclusions.
rsync -a --delete --exclude-from="$DISTIGNORE" "$PLUGIN_DIR/" "$STAGE_DIR/"

# Remove any prior build at the target path.
rm -f "$ZIP_PATH"

# Build the ZIP. -X strips macOS extra fields (resource forks, finder info)
# so the archive matches what would be produced on Linux/CI.
( cd "$TMP_DIR" && zip -q -r -X "$ZIP_PATH" "$SLUG/" )

# Report.
SIZE=$(du -h "$ZIP_PATH" | cut -f1)
COUNT=$(unzip -l "$ZIP_PATH" | tail -1 | awk '{print $2}')

echo
echo "Built  : $ZIP_PATH"
echo "Slug   : $SLUG"
echo "Version: $VERSION"
echo "Size   : $SIZE"
echo "Files  : $COUNT"
echo
echo "Inspect with:"
echo "  unzip -l $ZIP_PATH"
echo
echo "Test-install with:"
echo "  WP Admin → Plugins → Add New → Upload Plugin → choose $ZIP_NAME"
