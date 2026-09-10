#!/bin/bash
# ============================================================================
# staging-tout.sh — Passe complète de validation d'un staging réel (GO/NO-GO).
#
# Enchaîne ce qu'un dev senior vérifie avant une mise en prod, DANS L'ORDRE :
#   1. staging-securite.php  (les gardes répondent-elles ? 401/403 attendus)
#   2. staging-cache.php     (MISS->HIT non vide, TTFB médian)
#   3. staging-charge.php    (le site tient-il 100 req/s ?)
# et écrit un rapport horodaté rapport-staging-<date>.md à renvoyer au dev.
#
# Usage (depuis ta machine ou celle du dev ; PHP CLI 7.4+ et curl) :
#   php wp-content/themes/partikulier/tests/staging-tout.sh https://staging.example.com
#   PK_SECRET='<secret>' bash staging-tout.sh https://staging.example.com
#   bash staging-tout.sh https://staging.example.com 100 30   # rps et durée
#
# ⚠ RÈGLES D'OR avant de lancer (détail dans LISEZMOI-STAGING.md) :
#   - sondage d'abord : bash staging-tout.sh <url> 10 10
#   - une seule session de test à la fois, hors heures de pointe
# ============================================================================
set -uo pipefail

BASE=${1:-}
RPS=${2:-100}
DUREE=${3:-30}
OUT="rapport-staging-$(date +%Y%m%d-%H%M%S).md"

if [ -z "$BASE" ] || ! printf '%s' "$BASE" | grep -qE '^https?://'; then
  echo "Usage : bash staging-tout.sh <base-url> [rps=100] [durée s=30]"
  exit 64
fi

DIR=$(cd "$(dirname "$0")" && pwd)
PHP_BIN=${PHP_BIN:-php}
command -v "$PHP_BIN" > /dev/null || { echo "PHP CLI introuvable (variable PHP_BIN pour en préciser le chemin)"; exit 64; }

echo "# Rapport de validation staging — $BASE" > "$OUT"
echo "_Généré le $(date '+%d/%m/%Y à %H:%M:%S') · rps visé : $RPS · durée : $DUREE s_" >> "$OUT"
echo "" >> "$OUT"

run() { # $1=outil $2=titre $3.. args
  local outil=$1 titre=$2; shift 2
  echo "" >> "$OUT"; echo "## $titre" >> "$OUT"; echo '```' >> "$OUT"
  echo "" ; echo "════ $titre ════"
  "$PHP_BIN" "$DIR/$outil" "$@" 2>&1 | tee -a "$OUT"
  local code=${PIPESTATUS[0]}
  echo '```' >> "$OUT"
  return $code
}

rc_sec=0; rc_cache=0; rc_charge=0
run staging-securite.php "Sécurité (HMAC, sonde, portes)" "$BASE" || rc_sec=1
run staging-cache.php    "Cache et TTFB (contrat LOT 3)"  "$BASE" || rc_cache=1
run staging-charge.php   "Charge $RPS req/s pendant $DUREE s" "$BASE" "$RPS" "$DUREE" || rc_charge=$?

echo "" >> "$OUT"
echo "## Synthèse GO / NO-GO" >> "$OUT"
{
  echo ""
  [ $rc_sec -eq 0 ]    && echo "- Sécurité : OK"     || echo "- Sécurité : KO — corriger avant tout (gardes HMAC/sonde)"
  [ $rc_cache -eq 0 ]  && echo "- Cache/TTFB : OK"   || echo "- Cache/TTFB : KO — revoir la purge hPanel puis relancer (hPanel > Cache, LiteSpeed + HCDN)"
  [ $rc_charge -eq 0 ] && echo "- Charge $RPS req/s : TENUE" || { [ $rc_charge -eq 3 ] && echo "- Charge : TENU-DÉGRADÉ (latence p95 > 800 ms)" || echo "- Charge : TOMBÉ à $RPS req/s — noter le seuil qui tient (essayer la moitié)"; }
  echo ""
  echo "Rappel cibles CDC : HIT extérieur < 800 ms · HIT origine < 200 ms · TTFB HIT serveur LiteSpeed via x-litespeed-cache: hit"
} | tee -a "$OUT"

echo ""
echo "Rapport écrit : $OUT — à renvoyer tel quel au dev."
[ $rc_sec -eq 0 ] && [ $rc_cache -eq 0 ] && [ $rc_charge -eq 0 ] && exit 0
exit 1
