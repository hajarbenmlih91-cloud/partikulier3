#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_INPUT="${1:-dist}"
if [[ "$OUT_INPUT" = /* ]]; then
  OUT="$OUT_INPUT"
else
  OUT="$ROOT/$OUT_INPUT"
fi
PLUGIN_VERSION="2.10.5"
THEME_VERSION="6.20.4"

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
  # SE-017 (E-1702/E-1704, campagne post-audit) : l'artefact distribuable ne
  # contient NI les suites de recette (tests/, __screens__/, __baseline__/ —
  # DP-4 option 1 : maintenues dans le repo, exclues du zip) NI les scripts
  # shell (staging, build) — le verrou CI « artefact propre » rejoue ce
  # contrôle sur chaque build. Découverte de l'étape 0 : le package.sh
  # d'origine n'excluait rien de tout cela (les zips 2.10.4/6.20.3 embarquaient
  # tests/ et staging-tout.sh — constat P0-2 de l'audit, côté artefact cette
  # fois et non plus seulement côté repo).
  find "$dir" -type d \( -name .git -o -name node_modules -o -name coverage -o -name dist -o -name tests -o -name __screens__ -o -name __baseline__ \) -prune -exec rm -rf {} +
  find "$dir" -type f \( -name '*.log' -o -name '*.sqlite' -o -name '*.sql.bak' -o -name '.DS_Store' -o -name '*.sh' \) -delete
}
clean_tree "$tmp/plugin"
clean_tree "$tmp/theme"

( cd "$tmp/plugin" && TZ=UTC zip -qr -X "$OUT/B-INSTALLER-PLUGIN-partikulier-core-${PLUGIN_VERSION}.zip" partikulier-core )
( cd "$tmp/theme" && TZ=UTC zip -qr -X "$OUT/partikulier-theme-${THEME_VERSION}.zip" partikulier )

( cd "$OUT" && sha256sum *.zip > SHA256SUMS )
for catalog in partikulier.pot ar.po ar.mo en_US.po en_US.mo; do
  sha256sum "$tmp/plugin/partikulier-core/languages/$catalog" >> "$OUT/SHA256SUMS"
done
printf 'Packages written to %s\n' "$OUT"
cat "$OUT/SHA256SUMS"
