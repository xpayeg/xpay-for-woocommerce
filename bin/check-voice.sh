#!/bin/bash
# Voice guard: AGENTS.md bans em dashes in user-facing copy, and the
# ban only holds if a machine enforces it — the first audit found 28
# violations that slipped in one string at a time. Scans the first
# argument of every i18n call in the plugin's PHP.
set -euo pipefail
cd "$(dirname "$0")/.."

hits=$(grep -rnoP "(?:__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*'[^']*—" includes ./*.php 2>/dev/null || true)
if [ -n "$hits" ]; then
	echo "Em dash inside a translatable string (AGENTS.md voice rule — rewrite with a period, colon, or comma):"
	echo "$hits"
	exit 1
fi
echo "Voice check passed: no em dashes in translatable strings."
