#!/usr/bin/env bash
# Run one recipe setup operation, retain only a redacted, bounded log, and
# return the original exit code. Never logs argv, environment, or file contents.
set -uo pipefail

label="${1:-}"
shift || true
if [[ ! "$label" =~ ^[a-z0-9-]{1,64}$ || "${1:-}" != "--" ]]; then
  echo "usage: recipe-setup-command.sh LABEL -- COMMAND [ARG ...]" >&2
  exit 64
fi
shift
: "${RUNNER_TEMP:?RUNNER_TEMP is required}"
evidence_dir="$RUNNER_TEMP/recipe-setup-evidence"
mkdir -p "$evidence_dir"
raw_log="$(mktemp "$RUNNER_TEMP/recipe-setup-raw.XXXXXX")"
safe_log="$evidence_dir/${label}.log"

set +e
"$@" >"$raw_log" 2>&1
command_status=$?
set -e

python3 - "$raw_log" "$safe_log" "$label" "$command_status" <<'PY'
import json
import pathlib
import re
import sys

raw_path, safe_path, label, status = sys.argv[1:]
text = pathlib.Path(raw_path).read_text(errors="replace")
# Redact common credential forms before anything is emitted or uploaded.
patterns = [
    (r"(?i)(--(?:dbpass|admin_password|password|secret|token|api[-_]?key)(?:=|\s+))([^\s'\"&,]+)", r"\1[REDACTED]"),
    (r"(?i)((?:password|passwd|secret|token|api[-_]?key)\s*[=:]\s*['\"]?)([^\s'\"&,]+)", r"\1[REDACTED]"),
    (r"(?i)(authorization\s*:\s*bearer\s+)[A-Za-z0-9._~+/-]+=*", r"\1[REDACTED]"),
    (r"(https?://)[^/@\s:]+:[^/@\s]+@", r"\1[REDACTED]@"),
]
for pattern, replacement in patterns:
    text = re.sub(pattern, replacement, text)
if len(text) > 250_000:
    text = "[truncated: kept final 250000 characters]\n" + text[-250_000:]
lines = [line[:2000] for line in text.splitlines()]
if len(lines) > 400:
    lines = [f"[truncated: kept final 400 of {len(lines)} log lines]"] + lines[-400:]
text = "\n".join(lines) + ("\n" if lines else "")
pathlib.Path(safe_path).write_text(text, encoding="utf-8")
if int(status) != 0:
    excerpt = "\n".join(lines[-25:]) or "(empty command output)"
    excerpt = excerpt.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")
    print(f"::error title=Recipe setup failed ({label})::{excerpt}", file=sys.stderr)
with pathlib.Path(safe_path).open("a", encoding="utf-8") as stream:
    stream.write(f"\n[recipe-setup] step={label} exit_code={status}\n")
status_path = pathlib.Path(safe_path).parent / "status.jsonl"
with status_path.open("a", encoding="utf-8") as stream:
    stream.write(json.dumps({"step": label, "exit_code": int(status)}, separators=(",", ":")) + "\n")
PY
rm -f "$raw_log"
cat "$safe_log"
exit "$command_status"
