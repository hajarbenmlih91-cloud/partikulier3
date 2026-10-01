#!/usr/bin/env bash
# Idempotent WordPress bootstrap for Partikulier (run as www-data):
#   docker compose exec -u www-data wordpress pk-wp-init
set -euo pipefail

WP="wp --path=/var/www/html"
: "${PK_SITE_URL:=http://localhost:8099}"
: "${PK_ADMIN_USER:?PK_ADMIN_USER is required (.env)}"
: "${PK_ADMIN_PASSWORD:?PK_ADMIN_PASSWORD is required (.env)}"
: "${PK_ADMIN_EMAIL:=admin@example.test}"
: "${PK_WITH_POLYLANG:=0}"

echo "==> Waiting for wp-config.php and the database"
for _ in $(seq 1 60); do
  if [ -f /var/www/html/wp-config.php ] && $WP db check >/dev/null 2>&1; then break; fi
  sleep 2
done
$WP db check >/dev/null

if ! $WP core is-installed 2>/dev/null; then
  echo "==> Installing WordPress at ${PK_SITE_URL}"
  $WP core install --url="${PK_SITE_URL}" --title="Partikulier (local)" \
    --admin_user="${PK_ADMIN_USER}" --admin_password="${PK_ADMIN_PASSWORD}" \
    --admin_email="${PK_ADMIN_EMAIL}" --skip-email
fi

echo "==> Estatik (required dependency)"
$WP plugin is-installed estatik || $WP plugin install estatik
$WP plugin activate estatik

if [ "${PK_WITH_POLYLANG}" = "1" ]; then
  echo "==> Polylang 3.8.7 (optional, version pinned in CI)"
  $WP plugin is-installed polylang || $WP plugin install polylang --version=3.8.7
  $WP plugin activate polylang
fi

echo "==> Activating partikulier-core then the partikulier theme (order matters)"
$WP plugin activate partikulier-core
$WP theme activate partikulier

$WP rewrite structure '/%postname%/'
$WP rewrite flush
mkdir -p /var/www/html/wp-content/uploads/partikulier-cache

echo "==> Health check"
$WP eval '$r = rest_do_request(new WP_REST_Request("GET", "/partikulier/v1/health")); $d = $r->get_data(); echo wp_json_encode(["status" => $d["status"] ?? null, "core" => $d["core_version"] ?? null, "schema" => $d["schema_version"] ?? null]), PHP_EOL; exit(($d["status"] ?? "") === "ok" ? 0 : 1);'
echo "==> Ready: ${PK_SITE_URL}  (admin: ${PK_SITE_URL}/wp-admin/)"
