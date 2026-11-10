#!/bin/bash
# Tests d'intégration des blocs bash du workflow d'audit (séries A, B, C).
#   prérequis : bash 5, git, find GNU (les blocs sont extraits par gen.py)
#   usage     : bash run.sh   (depuis n'importe où ; travaille dans son propre répertoire)
set -uo pipefail
WORK="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$WORK" || exit 1
PASS=0; FAIL=0
ok()    { PASS=$((PASS+1)); printf '  PASS  %s\n' "$1"; }
ko()    { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$1"; }
check() { if eval "$2"; then ok "$1"; else ko "$1"; fi; }

for f in guard.sh lighthouse_fn.sh cache_probe.sh; do
  [ -f "$f" ] || { echo "gen.py n'a pas été exécuté : $f manquant" >&2; exit 2; }
done

echo "=== A. Garde de traçabilité (liste blanche exacte) ==="
rm -rf repoA; mkdir -p repoA; cd repoA
git init -q .
mkdir -p .github/workflows theme/partikulier/tests
echo a > .github/workflows/audit-uat.yml
echo b > .github/workflows/deploy.yml
echo c > theme/partikulier/tests/staging-cache.php
echo d > theme/partikulier/tests/audit-uat.mjs
echo e > theme/partikulier/functions.php
git add -A >/dev/null 2>&1
git -c user.email=t@t -c user.name=t commit -qm base
BASE=$(git rev-parse HEAD); export BASE
run_guard() { bash ../guard.sh > ../g.out 2>&1; }

echo a2 > .github/workflows/audit-uat.yml
echo c2 > theme/partikulier/tests/staging-cache.php
git -c user.email=t@t -c user.name=t commit -qam a1
run_guard; rc=$?
check "A1 harnais seul -> accepté" "[ $rc -eq 0 ] && grep -q 'écarts attendus' ../g.out"
git reset -q --hard "$BASE"

echo a2 > .github/workflows/audit-uat.yml
echo b2 > .github/workflows/deploy.yml
echo c2 > theme/partikulier/tests/staging-cache.php
git -c user.email=t@t -c user.name=t commit -qam a2
run_guard; rc=$?
check "A2 deploy.yml modifié -> refusé" "[ $rc -ne 0 ] && grep -q 'hors harnais autorisé : .github/workflows/deploy.yml' ../g.out"
git reset -q --hard "$BASE"

echo c2 > theme/partikulier/tests/staging-cache.php
echo d2 > theme/partikulier/tests/audit-uat.mjs
git -c user.email=t@t -c user.name=t commit -qam a3
run_guard; rc=$?
check "A3 autre test modifié -> refusé" "[ $rc -ne 0 ] && grep -q 'hors harnais autorisé : theme/partikulier/tests/audit-uat.mjs' ../g.out"
git reset -q --hard "$BASE"

echo c2 > theme/partikulier/tests/staging-cache.php
echo e2 > theme/partikulier/functions.php
git -c user.email=t@t -c user.name=t commit -qam a4
run_guard; rc=$?
check "A4 code applicatif modifié -> refusé" "[ $rc -ne 0 ] && grep -q 'hors harnais autorisé : theme/partikulier/functions.php' ../g.out"
git reset -q --hard "$BASE"
cd "$WORK"

echo
echo "=== B. Lighthouse : tentatives réellement exécutées ==="
mkdir -p binB
cat > binB/lighthouse <<'EOF'
#!/bin/bash
n=$(( $(cat "${LH_COUNTER}" 2>/dev/null || echo 0) + 1 )); echo "$n" > "${LH_COUNTER}"
case "${LH_MODE}" in
  ok) exit 0 ;;
  transient_once) [ "$n" -ge 2 ] && exit 0; echo "net::ERR_TIMED_OUT" >&2; exit 1 ;;
  always_fail)    echo "net::ERR_TIMED_OUT" >&2; exit 1 ;;
  config_error)   echo "Screen emulation mobile setting (true) does not match formFactor setting (desktop)." >&2; exit 1 ;;
esac
EOF
chmod +x binB/lighthouse

run_lh_scenario() {
  local mode="$1" out="$2"
  rm -f "$WORK/lh.counter"
  PATH="$WORK/binB:$PATH" LH_MODE="$mode" LH_COUNTER="$WORK/lh.counter" \
  bash -c "set -euo pipefail
chrome_path=/bin/true
export AUDIT_OUT_DIR=$WORK/tmp-lh; mkdir -p \"\$AUDIT_OUT_DIR\"
$(sed 's/^          //' lighthouse_fn.sh)
run_lh test 'https://exemple.test/fr/' mobile
echo \"LRESULT lh_failed=\$lh_failed\"
" > "$out" 2>&1
}

run_lh_scenario ok "$WORK/lh-ok.out"
check "B1 succès direct -> 1 tentative, lh_failed=0" "grep -q 'tentative 1/2' lh-ok.out && ! grep -q 'tentative 2/2' lh-ok.out && grep -q 'LRESULT lh_failed=0' lh-ok.out"
run_lh_scenario transient_once "$WORK/lh-tr.out"
check "B2 échec transitoire puis succès -> 2 tentatives, lh_failed=0" "grep -q 'tentative 2/2' lh-tr.out && grep -q 'LRESULT lh_failed=0' lh-tr.out"
run_lh_scenario always_fail "$WORK/lh-af.out"
check "B3 échec persistant -> 'après 2 tentative(s)', lh_failed=1" "grep -q 'après 2 tentative(s) réellement exécutée(s)' lh-af.out && grep -q 'LRESULT lh_failed=1' lh-af.out"
check "B3b jamais 'après 3'" "! grep -q 'tentative 3/2' lh-af.out && ! grep -q 'après 3' lh-af.out"
run_lh_scenario config_error "$WORK/lh-ce.out"
check "B4 erreur de configuration -> 1 seule tentative" "grep -q 'tentative 1/2' lh-ce.out && ! grep -q 'tentative 2/2' lh-ce.out && grep -q 'après 1 tentative(s)' lh-ce.out && grep -q 'LRESULT lh_failed=1' lh-ce.out"

echo
echo "=== C. Sonde serveur du cache du thème ==="
mkdir -p binC fakehome/.ssh
: > fakehome/.ssh/config
cat > binC/ssh <<'EOF'
#!/bin/bash
# ssh -F <config> <hote> <commande>  ->  exécute la commande en local
bash -c "$4"
EOF
cat > binC/wp <<'EOF'
#!/bin/bash
case "$*" in
  *wp_get_upload_dir*) echo "${FAKE_ROOT}" ;;
  *home_url*)          echo "${FAKE_HOST}" ;;
  *)                   echo "" ;;
esac
EOF
chmod +x binC/ssh binC/wp
HOTE="darkcyan-yak-151002.hostingersite.com"
CACHE_ROOT="$WORK/fakeuploads"
CACHE="$CACHE_ROOT/partikulier-cache"

run_cache() {
  local out="$1"; shift
  rm -rf "$WORK/tmp-cache"; mkdir -p "$WORK/tmp-cache"
  # php_rc=0 reproduit l'initialisation du workflow (avant l'appel à staging-cache.php).
  { printf 'php_rc=0\n'; cat cache_probe.sh; printf 'echo "RCPHP=$php_rc"\n'; } | \
  env "$@" AUDIT_OUT_DIR="$WORK/tmp-cache" HOME="$WORK/fakehome" WP_PATH=/tmp \
      FAKE_ROOT="$CACHE_ROOT" FAKE_HOST="$HOTE" PATH="$WORK/binC:$PATH" \
      bash -s > "$out" 2>&1
}

rm -rf "$CACHE_ROOT"; mkdir -p "$CACHE"
run_cache c1.out
check "C1 répertoire vide -> NON VÉRIFIÉ" "grep -q 'CACHE-THEME: non vérifié (aucune entrée' c1.out && grep -q 'PORTÉE: cache fichier du thème NON VÉRIFIÉ' c1.out"

head -c 800 /dev/zero | tr '\0' 'x' > "$CACHE/${HOTE}_fr_annonces.html"
run_cache c2.out
check "C2 entrée exacte de l'hôte UAT -> vérifié" "grep -q 'CACHE-THEME: vérifié (${HOTE}_fr_annonces.html, 800 o)' c2.out && grep -q 'PORTÉE: cache fichier du thème vérifié' c2.out"

rm -f "$CACHE/${HOTE}_fr_annonces.html"
head -c 800 /dev/zero | tr '\0' 'x' > "$CACHE/default_fr_annonces.html"
run_cache c3.out
check "C3 entrée du même nom mais autre hôte -> refusée" "grep -q 'CACHE-THEME: non vérifié' c3.out && grep -q 'entrées proches trouvées' c3.out"

rm -f "$CACHE/default_fr_annonces.html"
head -c 800 /dev/zero | tr '\0' 'x' > "$CACHE/${HOTE}_fr_home.html"
run_cache c4.out
check "C4 autre page du bon hôte -> refusée" "grep -q 'CACHE-THEME: non vérifié' c4.out"

rm -f "$CACHE/${HOTE}_fr_home.html"
head -c 200 /dev/zero | tr '\0' 'x' > "$CACHE/${HOTE}_fr_annonces.html"
run_cache c5.out
check "C5 entrée tronquée (200 o) -> non vérifié" "grep -q 'CACHE-THEME: non vérifié' c5.out"

head -c 800 /dev/zero | tr '\0' 'x' > "$CACHE/${HOTE}_fr_annonces.html"
touch -d '2 hours ago' "$CACHE/${HOTE}_fr_annonces.html"
run_cache c6.out
check "C6 entrée périmée (2 h) -> non vérifié" "grep -q 'CACHE-THEME: non vérifié' c6.out"

rm -rf fakehome/.ssh
run_cache c7.out
check "C7 SSH absent -> non vérifié (hôte ou racine illisible)" "grep -q 'CACHE-THEME: non vérifié (hôte ou racine uploads illisible)' c7.out"
mkdir -p fakehome/.ssh && : > fakehome/.ssh/config

rm -f "$CACHE/${HOTE}_fr_annonces.html"
run_cache c8.out PK_THEME_CACHE_REQUIRED=true
check "C8 cache exigé et non vérifié -> échec (php_rc=1)" "grep -q 'RCPHP=1' c8.out && grep -q '::error::cache fichier du thème exigé' c8.out"

head -c 800 /dev/zero | tr '\0' 'x' > "$CACHE/${HOTE}_fr_annonces.html"
run_cache c9.out PK_THEME_CACHE_REQUIRED=true
check "C9 cache exigé et vérifié -> php_rc=0" "grep -q 'RCPHP=0' c9.out"

echo
echo "=== RÉSULTAT : $PASS PASS · $FAIL FAIL ==="
[ "$FAIL" -eq 0 ]
