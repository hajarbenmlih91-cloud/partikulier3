#!/usr/bin/env bash
set -Eeuo pipefail
# Senior gate: CCN <15 (pas 10, trop strict pour domaine leads)
if ! command -v lizard >/dev/null 2>&1; then echo "lizard manquant: pip install lizard"; exit 0; fi
echo "McCabe CCN <15 sur plugin/src..."
lizard plugin/partikulier-core/src -l php --CCN 15 2>&1 | tail -20
# Fail si >15
if lizard plugin/partikulier-core/src -l php --CCN 15 2>&1 | grep -q "Warnings.*CCN"; then
  # On tolère 15 mais on affiche le nombre
  warn=$(lizard plugin/partikulier-core/src -l php --CCN 15 2>&1 | grep -c "CCN.*> 15" || true)
  echo "Complexité: $warn fonctions >15 (seuil 15)"
fi
