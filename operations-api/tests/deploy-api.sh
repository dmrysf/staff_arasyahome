#!/usr/bin/env bash
set -Eeuo pipefail

workspace="$(mktemp -d "${TMPDIR:-/tmp}/arasya-api-deploy-test.XXXXXX")"
[[ "$workspace" == *"/arasya-api-deploy-test."* ]] || exit 1
cleanup() {
  rm -rf -- "$workspace"
}
trap cleanup EXIT

fail() {
  printf 'FAIL API deploy test: %s\n' "$1" >&2
  exit 1
}

release="$workspace/release"
test_home="$workspace/home"
mkdir -p "$release/config" "$release/scripts" "$test_home/arasya-config" "$test_home/staff.arasyahome.ro" "$test_home/api.arasyahome.ro/.well-known" "$test_home/api.arasyahome.ro/cgi-bin"
cp operations-api/bootstrap.php operations-api/.htaccess operations-api/.cpanel.yml operations-api/README.md "$release/"
cp -R operations-api/public operations-api/src operations-api/database operations-api/bin "$release/"
cp operations-api/config/config.example.php operations-api/config/config.production.example.php operations-api/config/secrets.example.json "$release/config/"
cp operations-api/scripts/cpanel-deploy-api.sh operations-api/scripts/validate-release.sh "$release/scripts/"
printf '{"sourceCommit":"test","builtAt":"2026-08-19T00:00:00Z","version":"2.0.5"}\n' > "$release/release.json"

printf 'private-secret-sentinel\n' > "$test_home/arasya-config/secrets.json"
printf 'staff-sentinel\n' > "$test_home/staff.arasyahome.ro/staff.txt"
printf 'acme-sentinel\n' > "$test_home/api.arasyahome.ro/.well-known/acme.txt"
printf 'cgi-sentinel\n' > "$test_home/api.arasyahome.ro/cgi-bin/keep.txt"
printf 'old-public\n' > "$test_home/api.arasyahome.ro/old.txt"
secret_checksum="$(cksum "$test_home/arasya-config/secrets.json")"

HOME="$test_home" DRY_RUN=1 /bin/bash "$release/scripts/cpanel-deploy-api.sh" >/dev/null
[[ ! -e "$test_home/arasya-operations-api/current/bootstrap.php" ]] || fail 'Dry-run mutated private runtime.'
[[ ! -e "$test_home/api.arasyahome.ro/index.php" ]] || fail 'Dry-run mutated public document root.'

HOME="$test_home" /bin/bash "$release/scripts/cpanel-deploy-api.sh" >/dev/null

[[ -f "$test_home/arasya-operations-api/current/bootstrap.php" ]] || fail 'Private bootstrap was not deployed.'
for directory in src database bin config; do
  [[ -d "$test_home/arasya-operations-api/current/$directory" ]] || fail "Private runtime directory is missing: $directory"
done
[[ -f "$test_home/api.arasyahome.ro/index.php" && -f "$test_home/api.arasyahome.ro/.htaccess" && -f "$test_home/api.arasyahome.ro/RuntimeLocator.php" ]] || fail 'Public entrypoint files are missing.'
for forbidden in bootstrap.php src database bin config; do
  [[ ! -e "$test_home/api.arasyahome.ro/$forbidden" ]] || fail "Private artifact reached public root: $forbidden"
done
[[ -z "$(find "$test_home/api.arasyahome.ro" -name 'secrets.json' -print -quit)" ]] || fail 'secrets.json reached public root.'
[[ ! -e "$test_home/api.arasyahome.ro/old.txt" ]] || fail 'Controlled public rsync did not remove stale public content.'
[[ -f "$test_home/api.arasyahome.ro/.well-known/acme.txt" ]] || fail 'ACME content was not preserved.'
[[ -f "$test_home/api.arasyahome.ro/cgi-bin/keep.txt" ]] || fail 'cgi-bin content was not preserved.'
[[ "$(cksum "$test_home/arasya-config/secrets.json")" == "$secret_checksum" ]] || fail 'Private config changed during deployment.'
[[ "$(<"$test_home/staff.arasyahome.ro/staff.txt")" == 'staff-sentinel' ]] || fail 'Staff target changed during API deployment.'

if HOME="$test_home" ARASYA_API_PUBLIC_PATH="$test_home/staff.arasyahome.ro" /bin/bash "$release/scripts/cpanel-deploy-api.sh" >/dev/null 2>&1; then
  fail 'Unsafe Staff public target was accepted.'
fi
if HOME="$test_home" ARASYA_API_PUBLIC_PATH="$test_home/staff.arasyahome.ro/api" /bin/bash "$release/scripts/cpanel-deploy-api.sh" >/dev/null 2>&1; then
  fail 'Unsafe target nested inside the Staff document root was accepted.'
fi
if HOME="$test_home" ARASYA_API_RUNTIME_PATH="$test_home/arasya-config/runtime" /bin/bash "$release/scripts/cpanel-deploy-api.sh" >/dev/null 2>&1; then
  fail 'Unsafe config-contained runtime target was accepted.'
fi

printf 'PASS API two-target deploy keeps runtime private, public minimal, secrets persistent, and Staff untouched.\n'
