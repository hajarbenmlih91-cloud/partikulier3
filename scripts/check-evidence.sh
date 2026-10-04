#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EVIDENCE="$ROOT/preuves"
test -f "$EVIDENCE/MANIFESTE.md"

expected=(9 10 11 12 13 14)
for index in "${!expected[@]}"; do
  lot=$((index + 1))
  dir="$EVIDENCE/lot-B$lot/contrats"
  count=$(find "$dir" -maxdepth 1 -type f -name '*.json' | wc -l)
  if [[ "$count" -ne "${expected[$index]}" ]]; then
    echo "lot-B$lot: expected ${expected[$index]} contract JSON files, got $count" >&2
    exit 1
  fi
done

if find "$EVIDENCE" -type f \( -name '*.sqlite' -o -name '*.png' -o -name '*.zip' \) -print -quit | grep -q .; then
  echo 'binary evidence must stay in the GitHub Release, not git' >&2
  exit 1
fi

if ! grep -q '189/189' "$EVIDENCE/MANIFESTE.md"; then
  echo 'MANIFESTE.md does not record the 189/189 audit' >&2
  exit 1
fi

echo 'evidence manifest: PASS (B1..B6 = 9/10/11/12/13/14 contract JSON files; no heavy binaries)'
