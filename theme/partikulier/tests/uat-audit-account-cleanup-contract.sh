#!/usr/bin/env bash
# Contrat du nettoyage : interruption après création, avant marqueur, puis refus hors identité.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
cleanup="$here/uat-audit-account-cleanup.sh"
[[ -f "$cleanup" ]] || { echo 'Script de nettoyage absent.' >&2; exit 1; }

root="$(mktemp -d)"
trap 'rm -rf "$root"' EXIT
export WP_FAKE_STATE="$root/state"
mkdir -p "$WP_FAKE_STATE/users"
printf '1\n' > "$WP_FAKE_STATE/admin_id"
: > "$WP_FAKE_STATE/deletes"
wp_path="$root/wp"
mkdir -p "$wp_path"

cat > "$root/wp-bin" << 'EOF'
#!/usr/bin/env bash
set -euo pipefail
state="${WP_FAKE_STATE:?}"
users="$state/users"
cmd="${1:-}"; shift || true
[[ "$cmd" == user ]] || { echo "commande inattendue: $cmd" >&2; exit 2; }
sub="${1:-}"; shift || true

find_login() {
  local id="$1" file login
  for file in "$users"/*; do
    [[ -f "$file" ]] || continue
    [[ "$(sed -n '1p' "$file")" == "$id" ]] || continue
    login="$(basename "$file")"
    printf '%s\n' "$login"
    return 0
  done
  return 1
}

case "$sub" in
  get)
    key="${1:-}"; shift || true
    field=""
    while [[ $# -gt 0 ]]; do
      case "$1" in
        --field=*) field="${1#--field=}" ;;
        --field) shift; field="${1:-}" ;;
      esac
      shift || true
    done
    if [[ "$key" =~ ^[0-9]+$ ]]; then
      login="$(find_login "$key" || true)"
    else
      login="$key"
    fi
    [[ -n "$login" && -f "$users/$login" ]] || exit 1
    id="$(sed -n '1p' "$users/$login")"
    email="$(sed -n '2p' "$users/$login")"
    role="$(sed -n '3p' "$users/$login")"
    case "$field" in
      ID) printf '%s\n' "$id" ;;
      user_email) printf '%s\n' "$email" ;;
      roles) printf '%s\n' "$role" ;;
      *) echo 'champ inconnu' >&2; exit 2 ;;
    esac
    ;;
  meta)
    action="${1:-}"; shift || true
    id="${1:-}"; shift || true
    key="${1:-}"
    [[ "$action" == get && "$key" == _pk_uat_audit_marker ]] || { echo 'meta inattendue' >&2; exit 2; }
    login="$(find_login "$id" || true)"
    [[ -n "$login" ]] || exit 1
    marker="$(sed -n '4p' "$users/$login")"
    [[ -n "$marker" ]] || exit 1
    printf '%s\n' "$marker"
    ;;
  list)
    role=""
    while [[ $# -gt 0 ]]; do
      case "$1" in
        --role=*) role="${1#--role=}" ;;
        --role) shift; role="${1:-}" ;;
      esac
      shift || true
    done
    [[ "$role" == administrator ]] || exit 1
    cat "$state/admin_id"
    ;;
  delete)
    id="${1:-}"; shift || true
    yes=0
    reassign=""
    while [[ $# -gt 0 ]]; do
      case "$1" in
        --yes) yes=1 ;;
        --reassign=*) reassign="${1#--reassign=}" ;;
        --reassign) shift; reassign="${1:-}" ;;
      esac
      shift || true
    done
    [[ "$yes" == 1 && "$reassign" =~ ^[0-9]+$ ]] || { echo 'suppression refusée par le faux WP-CLI' >&2; exit 1; }
    login="$(find_login "$id" || true)"
    [[ -n "$login" ]] || exit 1
    if [[ -f "$state/delete_noop" ]]; then
      printf '%s\n' "$id" >> "$state/deletes"
      exit 0
    fi
    rm -f "$users/$login"
    printf '%s\n' "$id" >> "$state/deletes"
    ;;
  *)
    echo "sous-commande inattendue: $sub" >&2
    exit 2
    ;;
esac
EOF
chmod 755 "$root/wp-bin"
mkdir -p "$root/bin"
ln -s "$root/wp-bin" "$root/bin/wp"
export PATH="$root/bin:$PATH"

marker_a="$(printf 'a%.0s' {1..48})"
marker_b="$(printf 'b%.0s' {1..48})"
pass=0
fail=0
ok() { pass=$((pass + 1)); printf 'PASS %s\n' "$1"; }
bad() { fail=$((fail + 1)); printf 'FAIL %s\n' "$1" >&2; }

reset_users() {
  rm -rf "$WP_FAKE_STATE/users"
  mkdir -p "$WP_FAKE_STATE/users"
  rm -f "$WP_FAKE_STATE/delete_noop"
  : > "$WP_FAKE_STATE/deletes"
}

add_user() {
  local login="$1" id="$2" email="$3" role="$4" marker="$5"
  printf '%s\n%s\n%s\n%s\n' "$id" "$email" "$role" "$marker" > "$WP_FAKE_STATE/users/$login"
}

exists() { [[ -f "$WP_FAKE_STATE/users/$1" ]]; }
deletes() { wc -l < "$WP_FAKE_STATE/deletes" | tr -d ' '; }

run() {
  local login="$1" email="$2" marker="$3"
  set +e
  out="$(bash "$cleanup" "$wp_path" "$login" "$email" "$marker" 2>"$root/err")"
  code=$?
  set -e
  err="$(cat "$root/err")"
}

# 1. SSH interrompu après création, avant marqueur.
reset_users
add_user pk_audit_42489653669_1 5 pk_audit_42489653669_1@example.invalid contributor ''
add_user pk_audit_1_1 8 pk_audit_1_1@example.invalid contributor ''
run pk_audit_42489653669_1 pk_audit_42489653669_1@example.invalid "$marker_a"
if [[ "$code" == 0 && ! -f "$WP_FAKE_STATE/users/pk_audit_42489653669_1" && -f "$WP_FAKE_STATE/users/pk_audit_1_1" && "$out" == *'Compte temporaire supprimé'* && "$out" != *'absent'* ]]; then
  ok 'interruption avant marqueur : le compte du run est supprimé, le témoin reste'
else
  bad "interruption avant marqueur (code=$code out=$out err=$err)"
fi
run pk_audit_42489653669_1 pk_audit_42489653669_1@example.invalid "$marker_a"
if [[ "$code" == 0 && "$out" == 'Compte temporaire absent; aucun nettoyage requis.' && "$(deletes)" == 1 ]]; then
  ok 'second passage idempotent : absence annoncée, pas de nouvelle suppression'
else
  bad "idempotence (code=$code out=$out deletes=$(deletes))"
fi

# 2. Marqueur attendu présent.
reset_users
add_user pk_audit_20_1 6 pk_audit_20_1@example.invalid contributor "$marker_a"
run pk_audit_20_1 pk_audit_20_1@example.invalid "$marker_a"
if [[ "$code" == 0 && ! -f "$WP_FAKE_STATE/users/pk_audit_20_1" ]]; then
  ok 'marqueur attendu : compte supprimé et absent ensuite'
else
  bad "marqueur attendu (code=$code out=$out)"
fi

# 3. Autre marqueur.
reset_users
add_user pk_audit_20_1 6 pk_audit_20_1@example.invalid contributor "$marker_b"
run pk_audit_20_1 pk_audit_20_1@example.invalid "$marker_a"
if [[ "$code" == 1 && -f "$WP_FAKE_STATE/users/pk_audit_20_1" && "$err" == *'Un autre marqueur existe'* && "$(deletes)" == 0 ]]; then
  ok 'autre marqueur : arrêt sans suppression'
else
  bad "autre marqueur (code=$code err=$err)"
fi

# 4. Adresse différente.
reset_users
add_user pk_audit_20_1 6 autre@example.invalid contributor ''
run pk_audit_20_1 pk_audit_20_1@example.invalid "$marker_a"
if [[ "$code" == 1 && -f "$WP_FAKE_STATE/users/pk_audit_20_1" && "$err" == *'identité hors contrat'* ]]; then
  ok 'adresse différente : arrêt sans suppression'
else
  bad "adresse différente (code=$code err=$err)"
fi

# 5. Rôle différent.
reset_users
add_user pk_audit_20_1 6 pk_audit_20_1@example.invalid administrator ''
run pk_audit_20_1 pk_audit_20_1@example.invalid "$marker_a"
if [[ "$code" == 1 && -f "$WP_FAKE_STATE/users/pk_audit_20_1" && "$err" == *'identité hors contrat'* && "$(deletes)" == 0 ]]; then
  ok 'rôle différent : arrêt sans suppression'
else
  bad "rôle différent (code=$code err=$err)"
fi

# 6. Identifiant hors run.
reset_users
add_user admin 2 admin@example.invalid contributor ''
run admin admin@example.invalid "$marker_a"
if [[ "$code" == 1 && -f "$WP_FAKE_STATE/users/admin" && "$err" == *'Identifiant de run hors contrat'* && "$(deletes)" == 0 ]]; then
  ok 'identifiant hors run : arrêt sans suppression'
else
  bad "identifiant hors run (code=$code err=$err)"
fi

# 7. Suppression sans effet : ne pas annoncer le succès.
reset_users
add_user pk_audit_20_1 6 pk_audit_20_1@example.invalid contributor ''
printf '1\n' > "$WP_FAKE_STATE/delete_noop"
run pk_audit_20_1 pk_audit_20_1@example.invalid "$marker_a"
if [[ "$code" == 1 && -f "$WP_FAKE_STATE/users/pk_audit_20_1" && "$err" == *'existe encore après suppression'* && "$out" != *'supprimé'* ]]; then
  ok 'compte encore présent : échec, sans phrase de suppression'
else
  bad "vérification après suppression (code=$code out=$out err=$err)"
fi

printf 'Contrat de nettoyage : %s réussites, %s échecs.\n' "$pass" "$fail"
[[ "$fail" == 0 ]]
