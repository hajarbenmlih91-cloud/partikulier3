#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ "${1:-}" == "--verify" ]]; then
  # SE-022 (E-2203) — mode contrôle : vérifie un manifeste DEJA assemblé
  # (dist/ du build, ou assets de release packagés) sans rien reconstruire.
  # Toute entrée sans fichier correspondant bloque (exit 1). Les zips se
  # résolvent dans OUT ; les entrées build/… dans l'arbre source du dépôt.
  OUT="${2:-dist}"
  if [[ "$OUT" != /* ]]; then OUT="$ROOT/$OUT"; fi
  [[ -f "$OUT/SHA256SUMS" ]] || { printf 'SHA256SUMS introuvable : %s\n' "$OUT/SHA256SUMS" >&2; exit 1; }
  while read -r hash name; do
    [[ -n "$name" ]] || continue
    if [[ "$name" == *.zip ]]; then
      target="$OUT/$name"
    elif [[ "$name" == build/* ]]; then
      target="$ROOT/plugin/partikulier-core/languages/$(basename "$name")"
    else
      printf 'SHA256SUMS : entrée de forme inconnue — %s (E-2203)\n' "$name" >&2
      exit 1
    fi
    if [[ ! -f "$target" ]]; then
      printf 'SHA256SUMS : entrée sans fichier correspondant — %s (E-2203)\n' "$name" >&2
      exit 1
    fi
  done < "$OUT/SHA256SUMS"
  printf 'manifeste cohérent : %s\n' "$OUT/SHA256SUMS"
  exit 0
fi
OUT_INPUT="${1:-dist}"
if [[ "$OUT_INPUT" = /* ]]; then
  OUT="$OUT_INPUT"
else
  OUT="$ROOT/$OUT_INPUT"
fi
PLUGIN_VERSION="2.10.7"
THEME_VERSION="6.20.6"

rm -rf "$OUT"
mkdir -p "$OUT"

cd "$ROOT"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# SE-022 : copie du RÉPERTOIRE (pas « mkdir + cp contenu ») — le mtime du
# dossier racine est préservé, les octets du zip sont reproductibles (le
# dossier pré-créé portait l'heure du build dans l'entrée zip racine).
mkdir -p "$tmp/plugin" "$tmp/theme"
cp -a plugin/partikulier-core "$tmp/plugin/"
cp -a theme/partikulier "$tmp/theme/"

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
# SE-022 : mtimes des RÉPERTOIRES normalisés — les suppressions de clean_tree
# rafraîchissent le mtime des dossiers parents (heure du build) : les octets
# du zip divergeaient entre deux builds identiques. Les fichiers gardent
# leurs mtime source ; les dossiers portent une date fixe → zip
# déterministe, manifeste reproductible.
find "$tmp/plugin" "$tmp/theme" -type d -exec touch -d '1980-01-01 00:00:00' {} +

( cd "$tmp/plugin" && TZ=UTC zip -qr -X "$OUT/B-INSTALLER-PLUGIN-partikulier-core-${PLUGIN_VERSION}.zip" partikulier-core )
( cd "$tmp/theme" && TZ=UTC zip -qr -X "$OUT/partikulier-theme-${THEME_VERSION}.zip" partikulier )

( cd "$OUT" && sha256sum *.zip > SHA256SUMS )
# SE-022 (E-2203) : entrées de catalogue sous forme STABLE build/… (le chemin
# temporaire de build ne doit pas fuir dans le manifeste — reproductibilité
# du texte, aligné sur la convention de publication du train 1).
for catalog in partikulier.pot ar.po ar.mo en_US.po en_US.mo; do
  printf '%s  %s\n' \
    "$(sha256sum "$tmp/plugin/partikulier-core/languages/$catalog" | cut -d' ' -f1)" \
    "build/partikulier-core/languages/$catalog" >> "$OUT/SHA256SUMS"
done

# SE-022 (E-2203, CDC v4.1 §8B) — hygiène du manifeste : chaque entrée doit
# référencer un fichier RÉELLEMENT présent dans les assets générés. Constat
# train 1 : 2 entrées 09-DEPOT-GIT/*.patch listées sans fichiers
# correspondants (manifeste post-traité à la publication). Le contrôle
# bloque le build sur toute entrée fantôme — si un fichier doit figurer au
# manifeste, il est ajouté aux assets, jamais l'inverse. Le mode
# « package.sh --verify <dist> » re-joque le même contrôle à la publication
# sur le manifeste assemblé (zips dans le dist, catalogues dans la source).
while read -r hash name; do
  if [[ "$name" == *.zip ]]; then
    target="$OUT/$name"
  else
    target="$tmp/plugin/partikulier-core/languages/$(basename "$name")"
  fi
  if [[ ! -f "$target" ]]; then
    printf 'SHA256SUMS : entrée sans fichier correspondant — %s (E-2203)\n' "$name" >&2
    exit 1
  fi
done < "$OUT/SHA256SUMS"
printf 'Packages written to %s\n' "$OUT"
cat "$OUT/SHA256SUMS"
