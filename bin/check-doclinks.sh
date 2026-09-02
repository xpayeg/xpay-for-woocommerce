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

set -uo pipefail

ROOT="${1:-$(pwd)}"
cd "$ROOT" || { echo "cannot cd to $ROOT" >&2; exit 2; }

broken=0
checked=0

# Tidy a path for display only. Existence checks use the raw joined path.
normalize() {
  if command -v python3 >/dev/null 2>&1; then
    python3 -c "import os,sys; print(os.path.normpath(sys.argv[1]))" "$1"
  else
    printf '%s' "$1"
  fi
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
    checked=$((checked + 1))
    # Tested un-normalized on purpose: the kernel resolves `..` itself, so
    # this needs no helper and cannot be defeated by a missing one.
    if [ ! -e "$doc_dir/$path_part" ]; then
      printf 'BROKEN: %s -> %s (resolves to %s)\n' "$mdfile" "$target" "$(normalize "$doc_dir/$path_part")"
      broken=$((broken + 1))
    fi
  done < <(grep -oE '\[[^]]+\]\([^)]+\)' "$mdfile" || true)
done < <(
	find . -name "*.md" \
		-not -path "*/.git/*" \
		-not -path "*/node_modules/*" \
		-not -path "*/vendor/*" \
		-not -path "*/dist/*" \
		-not -path "*/plugin-build/*"
)

echo
echo "Checked: $checked links across all .md files"
echo "Broken:  $broken"
[ "$broken" -eq 0 ]
