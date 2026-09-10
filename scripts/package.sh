#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_INPUT="${1:-dist}"
if [[ "$OUT_INPUT" = /* ]]; then
  OUT="$OUT_INPUT"
else
  OUT="$ROOT/$OUT_INPUT"
fi
PLUGIN_VERSION="2.8.0"
THEME_VERSION="6.18.9"

rm -rf "$OUT"
mkdir -p "$OUT"

cd "$ROOT"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

mkdir -p "$tmp/plugin/partikulier-core" "$tmp/theme/partikulier"
cp -a plugin/partikulier-core/. "$tmp/plugin/partikulier-core/"
cp -a theme/partikulier/. "$tmp/theme/partikulier/"

clean_tree() {
  local dir="$1"
  find "$dir" -type d \( -name .git -o -name node_modules -o -name coverage -o -name dist \) -prune -exec rm -rf {} +
  find "$dir" -type f \( -name '*.log' -o -name '*.sqlite' -o -name '*.sql.bak' -o -name '.DS_Store' \) -delete
}
clean_tree "$tmp/plugin"
clean_tree "$tmp/theme"

( cd "$tmp/plugin" && TZ=UTC zip -qr -X "$OUT/B-INSTALLER-PLUGIN-partikulier-core-${PLUGIN_VERSION}.zip" partikulier-core )
( cd "$tmp/theme" && TZ=UTC zip -qr -X "$OUT/partikulier-theme-${THEME_VERSION}.zip" partikulier )

( cd "$OUT" && sha256sum *.zip > SHA256SUMS )
printf 'Packages written to %s\n' "$OUT"
cat "$OUT/SHA256SUMS"
