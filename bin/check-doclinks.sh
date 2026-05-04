#!/usr/bin/env bash
#
# Validate every relative markdown link [text](path) in every .md file under
# the plugin tree. Skips http(s):// URLs, mailto:, and pure-anchor (#…) links.
# A link is considered broken if the path it resolves to (relative to the
# directory of the doc that contains it) does not exist.
#
# Run from the plugin root:
#
#     bin/check-doclinks.sh
#
# Exits 0 with the broken-count line. Returns non-zero if any links are broken,
# so the script is suitable for CI gates.
#
# Background: introduced after the v2.0.0 directory rename, where six docs
# survived with `../COMPATIBILITY.md` style links that resolved to the repo
# root (where COMPATIBILITY.md doesn't live — it's at docs/COMPATIBILITY.md).
# Earlier reviews didn't catch this because they read code, not docs. This
# script is fast enough to run in pre-commit and on every release pass.

set -uo pipefail

ROOT="${1:-$(pwd)}"
cd "$ROOT" || { echo "cannot cd to $ROOT" >&2; exit 2; }

broken=0
checked=0

# Pick a path-normalizer that exists on the host. macOS realpath has no -m
# until coreutils; Python is universally available and portable.
normalize() {
  python3 -c "import os,sys; print(os.path.normpath(sys.argv[1]))" "$1"
}

while IFS= read -r mdfile; do
  while IFS= read -r match; do
    target=$(printf '%s' "$match" | sed -E 's/.*\(([^)]+)\).*/\1/')
    path_part=${target%%#*}
    [ -z "$path_part" ]            && continue
    case "$path_part" in
      http://*|https://*|mailto:*) continue ;;
    esac
    doc_dir=$(dirname "$mdfile")
    resolved=$(normalize "$doc_dir/$path_part")
    checked=$((checked + 1))
    if [ ! -e "$resolved" ]; then
      printf 'BROKEN: %s -> %s (resolves to %s)\n' "$mdfile" "$target" "$resolved"
      broken=$((broken + 1))
    fi
  done < <(grep -oE '\[[^]]+\]\([^)]+\)' "$mdfile" || true)
done < <(find . -name "*.md" -not -path "./.git/*" -not -path "./node_modules/*")

echo
echo "Checked: $checked links across all .md files"
echo "Broken:  $broken"
[ "$broken" -eq 0 ]
