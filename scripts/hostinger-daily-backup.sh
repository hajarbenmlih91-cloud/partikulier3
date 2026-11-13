#!/usr/bin/env bash
set -Eeuo pipefail

# Hostinger recurring full backup (DB + wp-content/uploads + plugin + theme + config).
# Run directly on Hostinger via hPanel Cron Jobs or via SSH/GitHub Actions:
#   WP_PATH=/home/u.../domains/.../public_html RETENTION_DAYS=7 bash hostinger-daily-backup.sh
#
# Optional offsite encryption (recommended before pushing outside Hostinger):
#   If BACKUP_ENCRYPTION_PASSPHRASE is set in env (never stored in git), creates
#   encrypted *.enc bundles via OpenSSL AES-256-CBC PBKDF2 so no plaintext SQL/PII
#   leaves Hostinger unencrypted.

WP_PATH="${WP_PATH:-$(pwd)}"
BACKUP_BASE="${BACKUP_BASE:-$HOME/backups/partikulier-full}"
RETENTION_COUNT="${RETENTION_COUNT:-7}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT_DIR="${BACKUP_BASE}/${STAMP}"

test -f "${WP_PATH}/wp-config.php" || { echo "wp-config.php missing at WP_PATH=${WP_PATH}" >&2; exit 1; }
command -v wp >/dev/null 2>&1 || { echo "wp-cli missing" >&2; exit 1; }
command -v mysqldump >/dev/null 2>&1 || { echo "mysqldump missing" >&2; exit 1; }

umask 077
mkdir -p "${OUT_DIR}"
cd "${WP_PATH}"

db_name="$(wp config get DB_NAME)"
db_user="$(wp config get DB_USER)"
db_pass="$(wp config get DB_PASSWORD)"
db_host="$(wp config get DB_HOST)"
db_port=""
if [[ "${db_host}" == *:* ]]; then
  db_port="${db_host##*:}"
  db_host="${db_host%%:*}"
fi

MYSQL_PWD="${db_pass}" mysqldump --single-transaction --no-tablespaces \
  -h "${db_host}" ${db_port:+-P "${db_port}"} -u "${db_user}" "${db_name}" \
  | gzip -c > "${OUT_DIR}/database.sql.gz"

if [ -d "${WP_PATH}/wp-content/uploads" ]; then
  tar --exclude='partikulier-cache' -czf "${OUT_DIR}/uploads.tar.gz" -C "${WP_PATH}/wp-content" uploads
fi
if [ -d "${WP_PATH}/wp-content/plugins/partikulier-core" ]; then
  tar -czf "${OUT_DIR}/partikulier-core.tar.gz" -C "${WP_PATH}/wp-content/plugins" partikulier-core
fi
if [ -d "${WP_PATH}/wp-content/themes/partikulier" ]; then
  tar -czf "${OUT_DIR}/partikulier.tar.gz" -C "${WP_PATH}/wp-content/themes" partikulier
fi

# Config files kept locally with chmod 600 inside OUT_DIR
tar -czf "${OUT_DIR}/site-config.tar.gz" -C "${WP_PATH}" wp-config.php $( [ -f "${WP_PATH}/.htaccess" ] && echo .htaccess || true )

for gz in "${OUT_DIR}"/*.gz; do
  gzip -t "${gz}"
done
( cd "${OUT_DIR}" && sha256sum *.gz > SHA256SUMS && sha256sum -c SHA256SUMS >/dev/null )

if [ -n "${BACKUP_ENCRYPTION_PASSPHRASE:-}" ]; then
  for gz in "${OUT_DIR}"/*.gz; do
    openssl enc -aes-256-cbc -pbkdf2 -iter 100000 -salt -in "${gz}" -out "${gz}.enc" -pass env:BACKUP_ENCRYPTION_PASSPHRASE
  done
  ( cd "${OUT_DIR}" && sha256sum *.enc > SHA256SUMS.enc )
  echo "Encrypted backup bundle created in ${OUT_DIR} (*.enc)"
fi

find "${BACKUP_BASE}" -mindepth 1 -maxdepth 1 -type d | sort -r | awk "NR>${RETENTION_COUNT}" | xargs -r rm -rf --
echo "BACKUP_OK dir=${OUT_DIR} files=$(ls -1 "${OUT_DIR}" | wc -l) size=$(du -sh "${OUT_DIR}" | cut -f1)"
