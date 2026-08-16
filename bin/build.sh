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
# Requirements: bash, rsync, zip (all default on macOS / Linux).

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
if [[ -n "${CONST_VERSION:-}" && "$CONST_VERSION" != "$VERSION" ]]; then
  echo "Error: Plugin header Version ($VERSION) != XPAY_WC_VERSION constant ($CONST_VERSION)." >&2
  echo "Bump them in lockstep before building." >&2
  exit 1
fi

# Cross-check against readme.txt Stable tag — WP.org requires they match
# (otherwise WP.org will not consider the new version "stable" for users).
README="$PLUGIN_DIR/readme.txt"
if [[ -f "$README" ]]; then
  STABLE_TAG=$(grep -E '^Stable tag:' "$README" | head -1 | sed -E 's/Stable tag:[[:space:]]*([0-9.]+).*/\1/')
  if [[ -n "${STABLE_TAG:-}" && "$STABLE_TAG" != "$VERSION" ]]; then
    echo "Error: Plugin header Version ($VERSION) != readme.txt Stable tag ($STABLE_TAG)." >&2
    echo "Update Stable tag in readme.txt before building." >&2
    exit 1
  fi
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
