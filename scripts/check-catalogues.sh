#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="$ROOT/plugin/partikulier-core/languages"
THEME="$ROOT/theme/partikulier/languages"

for catalog in partikulier.pot ar.po ar.mo en_US.po en_US.mo; do
  test -f "$PLUGIN/$catalog" || {
    echo "missing plugin canonical catalogue: $catalog" >&2
    exit 1
  }
  test ! -e "$THEME/$catalog" || {
    echo "forbidden theme catalogue copy: $catalog" >&2
    exit 1
  }
done

test -f "$THEME/estatik/es-ar.mo" || {
  echo 'missing Estatik Arabic catalogue: es-ar.mo' >&2
  exit 1
}
test -f "$THEME/estatik/es-ar.po" || {
  echo 'missing Estatik Arabic source catalogue: es-ar.po' >&2
  exit 1
}
test -f "$ROOT/plugin/partikulier-core/src/Domain/I18n/I18nDomainLoader.php" || {
  echo 'missing canonical i18n domain loader' >&2
  exit 1
}
if grep -rnE 'load_(theme_|plugin_)?textdomain' --include='*.php' "$ROOT/theme/partikulier/"; then
  echo 'theme still contains a textdomain loader call' >&2
  exit 1
fi

echo 'catalogue integrity: PASS (canonical plugin kit, zero theme kit copies)'
