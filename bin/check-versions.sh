#!/usr/bin/env bash
# Every place this plugin states its own version must agree.
#
# They are read by different audiences and drift silently:
#   - the plugin header      -> what WordPress shows and updates against
#   - XPAY_WC_VERSION        -> what asset URLs and logs are stamped with
#   - readme.txt Stable tag  -> what wordpress.org actually serves
#
# A mismatched stable tag is the worst of the three: wordpress.org serves
# the tag, so a release can ship with the previous version's code and no
# error anywhere.
set -euo pipefail

cd "$(dirname "$0")/.."

# A renamed constant or a missing file makes grep exit non-zero, which under
# `set -e` would kill the script before it reports anything. Each extraction
# absorbs that into an empty string so the missing-value report below runs and
# the check fails with a message instead of dying silently.
header=$(grep -m1 -E '^\s*\*\s*Version:' xpay-for-woocommerce.php | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]' || true)
constant=$(grep -m1 "define( 'XPAY_WC_VERSION'" xpay-for-woocommerce.php | sed -E "s/.*'XPAY_WC_VERSION', '([^']+)'.*/\1/" || true)
stable=$(grep -m1 -E '^Stable tag:' readme.txt | sed -E 's/.*Stable tag:[[:space:]]*//' | tr -d '[:space:]' || true)
package=$(php -r '$data = json_decode(file_get_contents("package.json"), true); echo $data["version"] ?? "";' || true)
pot=$(grep -m1 'Project-Id-Version: XPay for WooCommerce ' languages/xpay-for-woocommerce.pot | sed -E 's/.*WooCommerce ([0-9.]+).*/\1/' || true)

echo "plugin header : ${header:-<missing>}"
echo "XPAY_WC_VERSION: ${constant:-<missing>}"
echo "readme stable  : ${stable:-<missing>}"
echo "package version: ${package:-<missing>}"
echo "POT version    : ${pot:-<missing>}"

fail=0
for v in "$header" "$constant" "$stable" "$package" "$pot"; do
  [ -n "$v" ] || { echo "A version string is missing."; fail=1; }
done

if [ "$header" != "$constant" ] || [ "$header" != "$stable" ] || [ "$header" != "$package" ] || [ "$header" != "$pot" ]; then
  echo "MISMATCH: all version declarations must be identical."
  fail=1
fi

# Release changelogs must mention the version being shipped. The 0.0.0
# development state deliberately has no public CHANGELOG.md release.
if [ "$header" != "0.0.0" ]; then
  if ! grep -qE "^= ${header//./\\.} =" readme.txt; then
    echo "readme.txt changelog has no '= $header =' entry."
    fail=1
  fi
  if ! grep -qE "^## (\\[)?${header//./\\.}(\\])?($| )" CHANGELOG.md; then
    echo "CHANGELOG.md has no '$header' release."
    fail=1
  fi
fi

[ $fail -eq 0 ] && echo "OK: versions agree." || echo "FAILED"
exit $fail
