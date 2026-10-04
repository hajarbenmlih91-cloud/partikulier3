#!/usr/bin/env bash
set -euo pipefail

if [ -n "${PK_LOCAL_N8N_CA_FILE:-}" ]; then
    for _ in $(seq 1 60); do
        if [ -s "$PK_LOCAL_N8N_CA_FILE" ]; then break; fi
        sleep 2
    done
    if [ ! -s "$PK_LOCAL_N8N_CA_FILE" ]; then
        echo "Local n8n certificate authority is unavailable." >&2
        exit 1
    fi
    install -m 0644 "$PK_LOCAL_N8N_CA_FILE" /usr/local/share/ca-certificates/partikulier-local.crt
    update-ca-certificates >/dev/null
fi

exec docker-entrypoint.sh "$@"
