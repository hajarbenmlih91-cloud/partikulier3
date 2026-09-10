#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if command -v php >/dev/null 2>&1; then
  find plugin theme -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
  echo 'PHP lint: PASS'
else
  echo 'PHP lint: SKIP (php executable not available)'
fi

find scripts -name '*.sh' -print0 | xargs -0 -n1 bash -n
echo 'Shell lint: PASS'

if command -v node >/dev/null 2>&1; then
  find theme/partikulier -name '*.mjs' -print0 | while IFS= read -r -d '' file; do node --check "$file" >/dev/null; done
  echo 'JavaScript lint: PASS'
else
  echo 'JavaScript lint: SKIP (node executable not available)'
fi

python3 - <<'PY'
import json
from pathlib import Path
for path in [Path('theme/partikulier/package.json')]:
    json.loads(path.read_text())
print('JSON lint: PASS')
PY

if find . -type f \( -name '.env' -o -name '.env.*' -o -name '*.sqlite' -o -name '*.sql.bak' \) -not -path './plugin/partikulier-core/migrations/*' -print -quit | grep -q .; then
  echo 'Forbidden artifact found' >&2
  exit 1
fi
echo 'Artifact hygiene: PASS'

bash scripts/check-catalogues.sh
