#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="$ROOT/plugin/partikulier-core/languages"
THEME="$ROOT/theme/partikulier/languages"

for catalog in partikulier.pot ar.po ar.mo en_US.po en_US.mo; do
  plugin_file="$PLUGIN/$catalog"
  theme_file="$THEME/$catalog"
  test -f "$plugin_file" || { echo "missing plugin catalogue: $catalog" >&2; exit 1; }
  test -f "$theme_file" || { echo "missing theme fallback catalogue: $catalog" >&2; exit 1; }
  cmp -s "$plugin_file" "$theme_file" || {
    echo "catalogue divergence: $catalog" >&2
    sha256sum "$plugin_file" "$theme_file" >&2
    exit 1
  }
done

echo 'catalogue integrity: PASS (5/5 canonical files byte-identical)'
