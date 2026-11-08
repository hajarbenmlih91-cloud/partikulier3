#!/usr/bin/env bash
# Nettoyage distant du compte jetable d'audit UAT.
# Arguments : chemin WordPress, identifiant de run, adresse unique, marqueur attendu.
# Un marqueur absent est le cas d'une création interrompue avant l'écriture du marqueur.
# Un autre marqueur, ou une identité différente, interdit toute suppression.
set -euo pipefail
wp_path="${1:?}"
login="${2:?}"
expected_email="${3:?}"
expected_marker="${4:?}"

if [[ ! "$login" =~ ^pk_audit_[0-9]+_[0-9]+$ ]]; then
  echo 'Identifiant de run hors contrat; aucune suppression effectuée.' >&2
  exit 1
fi
if [[ ! "$expected_email" =~ ^pk_audit_[0-9]+_[0-9]+@example\.invalid$ ]]; then
  echo 'Adresse hors contrat; aucune suppression effectuée.' >&2
  exit 1
fi
if [[ "${expected_email%@example.invalid}" != "$login" ]]; then
  echo 'Identifiant et adresse ne correspondent pas; aucune suppression effectuée.' >&2
  exit 1
fi
if [[ ! "$expected_marker" =~ ^[0-9a-f]{48}$ ]]; then
  echo 'Marqueur attendu hors contrat; aucune suppression effectuée.' >&2
  exit 1
fi

cd "$wp_path"
command -v wp >/dev/null 2>&1 || { echo 'WP-CLI absent' >&2; exit 1; }

user_id="$(wp user get "$login" --field=ID 2>/dev/null || true)"
if [[ -z "$user_id" ]]; then
  echo 'Compte temporaire absent; aucun nettoyage requis.'
  exit 0
fi
if [[ ! "$user_id" =~ ^[0-9]+$ ]]; then
  echo 'ID utilisateur non numérique; arrêt sûr.' >&2
  exit 1
fi

email="$(wp user get "$user_id" --field=user_email)"
role="$(wp user get "$user_id" --field=roles)"
marker="$(wp user meta get "$user_id" _pk_uat_audit_marker 2>/dev/null || true)"
email="${email//$'\r'/}"
role="${role//$'\r'/}"
marker="${marker//$'\r'/}"
email="${email#"${email%%[![:space:]]*}"}"
email="${email%"${email##*[![:space:]]}"}"
role="${role#"${role%%[![:space:]]*}"}"
role="${role%"${role##*[![:space:]]}"}"

if [[ "$email" != "$expected_email" || "$role" != contributor ]]; then
  echo 'Compte trouvé mais identité hors contrat; aucune suppression effectuée.' >&2
  exit 1
fi
if [[ -n "$marker" && "$marker" != "$expected_marker" ]]; then
  echo 'Un autre marqueur existe; aucune suppression effectuée.' >&2
  exit 1
fi

admin_id="$(wp user list --role=administrator --field=ID --format=ids | awk '{print $1}')"
if [[ ! "$admin_id" =~ ^[0-9]+$ || "$admin_id" == "$user_id" ]]; then
  echo 'Aucun administrateur de réaffectation sûr; compte conservé.' >&2
  exit 1
fi

wp user delete "$user_id" --yes --reassign="$admin_id"
still="$(wp user get "$login" --field=ID 2>/dev/null || true)"
if [[ -n "$still" ]]; then
  echo 'Le compte temporaire existe encore après suppression.' >&2
  exit 1
fi
echo 'Compte temporaire supprimé et contenu réaffecté à un administrateur si nécessaire.'
