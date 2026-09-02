#!/bin/bash
# Voice guard: AGENTS.md bans em dashes in user-facing copy, and the ban
# only holds if a machine enforces it. The first audit found 28 violations
# that slipped in one string at a time.
#
# Written with grep -E, not grep -P. The earlier version used -P and
# swallowed failures with `|| true`, so on macOS — where BSD grep has no
# PCRE support — it printed "passed" on every run no matter what the code
# said, and a violation shipped through it twice on the day this was
# noticed. A check that cannot fail is not a check.
set -uo pipefail
cd "$(dirname "$0")/.."

PATTERN="(__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\([[:space:]]*'[^']*—"

hits=$(grep -rnoE "$PATTERN" includes ./*.php 2>/dev/null)
status=$?

# grep exits 0 with matches, 1 with none, and 2+ on an actual error. Only
# the middle one is a pass.
if [ "$status" -gt 1 ]; then
	echo "Voice check could not run (grep exited $status). Failing rather than reporting a pass."
	exit 2
fi

if [ -n "$hits" ]; then
	echo "Em dash inside a translatable string (AGENTS.md voice rule: rewrite with a period, colon, or comma):"
	echo "$hits"
	exit 1
fi

echo "Voice check passed: no em dashes in translatable strings."
