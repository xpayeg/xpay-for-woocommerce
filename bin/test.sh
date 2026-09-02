#!/usr/bin/env bash
# Every suite, in one command.
#
# Three suites, and they are not interchangeable:
#   unit        — WordPress-free logic (money, signature, phone plans)
#   contracts   — state machines against an in-memory WordPress shim
#   integration — REAL WordPress, REAL WooCommerce, both order storages
#
# Anything that asserts about WooCommerce behavior belongs in the integration
# suite, where WooCommerce is the thing answering.
#
# Integration needs the wp-env tests container, so it is skipped (loudly)
# when that is not running.
set -uo pipefail
cd "$(dirname "$0")/.."

fail=0
run() { echo; echo "── $1 ──"; shift; "$@" || fail=1; }

run "unit" ./vendor/bin/phpunit
run "contracts" ./vendor/bin/phpunit -c phpunit-contracts.xml

if [ -n "${WP_TESTS_DIR:-}" ] && [ -d "${WP_TESTS_DIR}" ]; then
  run "integration (real WordPress)" ./vendor/bin/phpunit -c phpunit-integration.xml
else
  echo
  echo "── integration: SKIPPED ──"
  echo "   Needs the WordPress test library. Run it inside wp-env:"
  echo "   wp-env run tests-cli --env-cwd=wp-content/plugins/xpay-for-woocommerce bin/test.sh"
fi

echo; echo "── javascript ──"
if command -v node >/dev/null 2>&1; then
  for f in assets/js/*.js; do node --check "$f" || fail=1; done
  node --test tools/js-tests/*.mjs || fail=1
else
  # The wp-env tests container has PHP but no node. The JS suites run on the
  # host and in CI; skipping loudly here beats a false pass or a false fail.
  echo "   SKIPPED: no node in this container. Run 'bin/test.sh' on the host too."
fi

run "versions agree" bash bin/check-versions.sh
run "voice" bash bin/check-voice.sh
run "doc links" bash bin/check-doclinks.sh

echo
[ $fail -eq 0 ] && echo "== ALL SUITES PASS ==" || echo "== FAILURES =="
exit $fail
