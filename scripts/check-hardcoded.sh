#!/usr/bin/env bash
set -Eeuo pipefail
# Senior gate: détecte les chaînes dures hors __()
# Autorise: echo '<script' (jsonld), echo HTML avec esc_*, et les tests
bad=$(grep -R --include="*.php" -n 'echo "' plugin/theme --exclude-dir=tests 2>/dev/null | grep -v "__(" | grep -v "_e(" | grep -v "esc_" | grep -v "wp_json_encode" | grep -v "phps:ignore" || true)
if [[ -n "$bad" ]]; then
  echo "Hardcoded strings trouvées (hors __()):"
  echo "$bad"
  exit 1
fi
echo "Hardcoded strings: PASS (0 hors __())"
