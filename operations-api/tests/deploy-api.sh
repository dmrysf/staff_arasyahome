#!/usr/bin/env bash
set -Eeuo pipefail

workspace="$(mktemp -d "${TMPDIR:-/tmp}/arasya-api-deploy-test.XXXXXX")"
[[ "$workspace" == *"/arasya-api-deploy-test."* ]] || exit 1
cleanup() { rm -rf -- "$workspace"; }
trap cleanup EXIT
fail() { printf 'FAIL API deploy test: %s\n' "$1" >&2; exit 1; }

# cPanel deploy shells have no /dev/fd; process substitution there silently skips release cleanup.
if grep -nE '<\(' operations-api/scripts/*.sh; then
  fail "deploy scripts must not use process substitution"
fi

sha_a="$(printf 'a%.0s' {1..40})"; sha_b="$(printf 'b%.0s' {1..40})"; sha_c="$(printf 'c%.0s' {1..40})"; sha_d="$(printf 'd%.0s' {1..40})"
release_a="$workspace/release-a"; release_b="$workspace/release-b"; release_c="$workspace/release-c"; release_d="$workspace/release-d"
/bin/bash operations-api/tests/create-release-fixture.sh "$release_a" "$sha_a"
/bin/bash operations-api/tests/create-release-fixture.sh "$release_b" "$sha_b"
/bin/bash operations-api/tests/create-release-fixture.sh "$release_c" "$sha_c"
/bin/bash operations-api/tests/create-release-fixture.sh "$release_d" "$sha_d"

test_home="$workspace/home"; api_root="$test_home/arasya-operations-api"; public_root="$test_home/api.arasyahome.ro"
mkdir -p "$api_root" "$public_root/.well-known" "$public_root/cgi-bin" "$test_home/arasya-config" "$test_home/staff.arasyahome.ro"
cp -R "$release_c" "$api_root/current"
printf 'legacy-current-sentinel\n' > "$api_root/current/legacy-sentinel.txt"
/bin/bash "$api_root/current/scripts/generate-sha256s.sh" "$api_root/current"
printf 'private-secret-sentinel\n' > "$test_home/arasya-config/secrets.json"
printf 'staff-sentinel\n' > "$test_home/staff.arasyahome.ro/staff.txt"
printf 'acme-sentinel\n' > "$public_root/.well-known/acme.txt"
printf 'cgi-sentinel\n' > "$public_root/cgi-bin/keep.txt"
secret_checksum="$(cksum "$test_home/arasya-config/secrets.json")"

HOME="$test_home" DRY_RUN=1 /bin/bash "$release_a/scripts/cpanel-deploy-api.sh" >/dev/null
[[ ! -e "$api_root/active-release" ]] || fail 'Dry-run changed active pointer.'

HOME="$test_home" /bin/bash "$release_a/scripts/cpanel-deploy-api.sh" >/dev/null
[[ "$(tr -d '[:space:]' < "$api_root/active-release")" == "$sha_a" ]] || fail 'Release A was not activated.'
[[ -d "$api_root/releases/$sha_a" && -f "$api_root/releases/$sha_a/SHA256SUMS" ]] || fail 'Release A was not retained and checksummed.'
[[ -d "$api_root/releases/$sha_c" ]] || fail 'Verified legacy current was not retained on first atomic deployment.'
[[ -f "$api_root/current/legacy-sentinel.txt" ]] || fail 'Legacy current was modified.'
[[ -x "$api_root/bin/maintenance-active.sh" ]] || fail 'Stable maintenance launcher is missing.'
[[ -f "$public_root/index.php" && -f "$public_root/RuntimeLocator.php" && -f "$public_root/.htaccess" ]] || fail 'Public bootstrap files are missing.'

HOME="$test_home" /bin/bash "$release_b/scripts/cpanel-deploy-api.sh" >/dev/null
[[ "$(tr -d '[:space:]' < "$api_root/active-release")" == "$sha_b" ]] || fail 'Release B was not activated.'
[[ "$(tr -d '[:space:]' < "$api_root/previous-release")" == "$sha_a" ]] || fail 'Release A was not recorded as previous.'
[[ -d "$api_root/releases/$sha_a" ]] || fail 'Release A was deleted after B activation.'

HOME="$test_home" /bin/bash "$release_b/scripts/cpanel-rollback-api.sh" "$sha_a" >/dev/null
[[ "$(tr -d '[:space:]' < "$api_root/active-release")" == "$sha_a" ]] || fail 'Rollback did not reactivate A.'
[[ "$(tr -d '[:space:]' < "$api_root/previous-release")" == "$sha_b" ]] || fail 'Rollback did not preserve B as previous.'
HOME="$test_home" /bin/bash "$release_a/scripts/cpanel-rollback-api.sh" --previous >/dev/null
[[ "$(tr -d '[:space:]' < "$api_root/active-release")" == "$sha_b" ]] || fail 'API --previous did not reactivate B.'
HOME="$test_home" /bin/bash "$release_b/scripts/cpanel-rollback-api.sh" --previous >/dev/null
[[ "$(tr -d '[:space:]' < "$api_root/active-release")" == "$sha_a" ]] || fail 'API --previous did not return to A.'

printf '\ncorrupt\n' >> "$api_root/releases/$sha_b/bootstrap.php"
if HOME="$test_home" /bin/bash "$release_b/scripts/cpanel-rollback-api.sh" "$sha_b" >/dev/null 2>&1; then fail 'Rollback accepted corrupt release B.'; fi
[[ "$(tr -d '[:space:]' < "$api_root/active-release")" == "$sha_a" ]] || fail 'Corrupt rollback changed active release.'

if HOME="$test_home" ARASYA_TEST_FAIL_BEFORE_ACTIVATION=1 /bin/bash "$release_d/scripts/cpanel-deploy-api.sh" >/dev/null 2>&1; then fail 'Simulated pre-activation failure succeeded.'; fi
[[ "$(tr -d '[:space:]' < "$api_root/active-release")" == "$sha_a" ]] || fail 'Pre-activation failure changed active release.'

printf '\ncorrupt source\n' >> "$release_c/bootstrap.php"
if HOME="$test_home" /bin/bash "$release_c/scripts/cpanel-deploy-api.sh" >/dev/null 2>&1; then fail 'Deploy accepted corrupt source checksum.'; fi
[[ "$(tr -d '[:space:]' < "$api_root/active-release")" == "$sha_a" ]] || fail 'Checksum failure changed active release.'

for forbidden in bootstrap.php src database bin config release.json; do [[ ! -e "$public_root/$forbidden" ]] || fail "Private artifact reached public root: $forbidden"; done
[[ -f "$public_root/.well-known/acme.txt" && -f "$public_root/cgi-bin/keep.txt" ]] || fail 'Public preserved directories changed.'
[[ "$(cksum "$test_home/arasya-config/secrets.json")" == "$secret_checksum" ]] || fail 'Private config changed.'
[[ "$(<"$test_home/staff.arasyahome.ro/staff.txt")" == 'staff-sentinel' ]] || fail 'Staff root changed.'

if HOME="$test_home" ARASYA_API_PUBLIC_PATH="$test_home/staff.arasyahome.ro" /bin/bash "$release_a/scripts/cpanel-deploy-api.sh" >/dev/null 2>&1; then fail 'Unsafe Staff target was accepted.'; fi
if HOME="$test_home" ARASYA_API_ROOT_PATH="$test_home/arasya-config/runtime" /bin/bash "$release_a/scripts/cpanel-deploy-api.sh" >/dev/null 2>&1; then fail 'Unsafe config-contained runtime was accepted.'; fi

printf 'PASS API staged releases activate atomically, preserve legacy/current/public boundaries, reject corruption, and rollback by checksum.\n'
