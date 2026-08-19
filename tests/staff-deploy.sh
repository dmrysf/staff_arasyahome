#!/usr/bin/env bash
set -Eeuo pipefail

workspace="$(mktemp -d "${TMPDIR:-/tmp}/arasya-staff-deploy-test.XXXXXX")"
[[ "$workspace" == *"/arasya-staff-deploy-test."* ]] || exit 1
cleanup() { rm -rf -- "$workspace"; }
trap cleanup EXIT
fail() { printf 'FAIL Staff deploy test: %s\n' "$1" >&2; exit 1; }

create_release() {
  local target="$1" sha="$2" marker="$3"
  mkdir -p "$target/dist/assets" "$target/scripts"
  cp -R dist/. "$target/dist/"
  printf 'window.__ARASYA_RELEASE_MARKER__=%q;\n' "$marker" > "$target/dist/assets/release-$marker.js"
  sed "s#</body>#<script type=\"module\" src=\"/assets/release-$marker.js\"></script></body>#" "$target/dist/index.html" > "$target/dist/index.next"
  mv "$target/dist/index.next" "$target/dist/index.html"
  printf '{"commit":"%s","builtAt":"2026-08-19T00:00:00Z","version":"0.1.0","preview":true}\n' "$sha" > "$target/dist/release.json"
  /bin/bash scripts/generate-sha256s.sh "$target/dist"
  /bin/bash scripts/validate-staff-release.sh "$target/dist" >/dev/null
  cp .cpanel.yml "$target/.cpanel.yml"
  cp scripts/cpanel-deploy.sh scripts/cpanel-rollback-staff.sh scripts/staff-release-common.sh scripts/validate-staff-release.sh scripts/generate-sha256s.sh "$target/scripts/"
  chmod +x "$target/scripts/"*.sh
  /bin/bash "$target/scripts/generate-sha256s.sh" "$target"
}

sha_a="$(printf 'a%.0s' {1..40})"; sha_b="$(printf 'b%.0s' {1..40})"; sha_c="$(printf 'c%.0s' {1..40})"; sha_d="$(printf 'd%.0s' {1..40})"
release_a="$workspace/release-a"; release_b="$workspace/release-b"; release_c="$workspace/release-c"; release_d="$workspace/release-d"
create_release "$release_a" "$sha_a" a
create_release "$release_b" "$sha_b" b
create_release "$release_c" "$sha_c" c
create_release "$release_d" "$sha_d" d

test_home="$workspace/home"; live="$test_home/staff.arasyahome.ro"; storage="$test_home/arasya-staff-releases"
mkdir -p "$live/.well-known" "$live/cgi-bin" "$test_home/arasya-operations-api" "$test_home/arasya-config"
cp -R "$release_c/dist/." "$live/"
printf 'acme\n' > "$live/.well-known/acme.txt"; printf 'cgi\n' > "$live/cgi-bin/keep.txt"
printf 'api\n' > "$test_home/arasya-operations-api/api.txt"; printf 'secret\n' > "$test_home/arasya-config/secrets.json"

HOME="$test_home" DRY_RUN=1 /bin/bash "$release_a/scripts/cpanel-deploy.sh" >/dev/null
[[ ! -e "$storage/active-release" ]] || fail 'Dry-run changed Staff active pointer.'

HOME="$test_home" /bin/bash "$release_a/scripts/cpanel-deploy.sh" >/dev/null
[[ "$(tr -d '[:space:]' < "$storage/active-release")" == "$sha_a" ]] || fail 'Release A was not activated.'
[[ -d "$storage/$sha_c" ]] || fail 'Legacy Staff release was not retained.'
grep -q 'release-a.js' "$live/index.html" || fail 'Release A index was not activated.'

HOME="$test_home" /bin/bash "$release_b/scripts/cpanel-deploy.sh" >/dev/null
[[ "$(tr -d '[:space:]' < "$storage/active-release")" == "$sha_b" ]] || fail 'Release B was not activated.'
[[ "$(tr -d '[:space:]' < "$storage/previous-release")" == "$sha_a" ]] || fail 'Release A was not recorded as previous.'
grep -q 'release-b.js' "$live/index.html" || fail 'Release B index was not activated.'
[[ -f "$live/assets/release-a.js" && -f "$live/assets/release-b.js" ]] || fail 'Additive asset activation removed rollback assets.'

if HOME="$test_home" ARASYA_TEST_FAIL_BEFORE_INDEX=1 /bin/bash "$release_d/scripts/cpanel-deploy.sh" >/dev/null 2>&1; then fail 'Simulated Staff pre-index failure succeeded.'; fi
grep -q 'release-b.js' "$live/index.html" || fail 'Pre-index failure replaced the active index.'
[[ "$(tr -d '[:space:]' < "$storage/active-release")" == "$sha_b" ]] || fail 'Pre-index failure changed Staff pointer.'

HOME="$test_home" /bin/bash "$release_b/scripts/cpanel-rollback-staff.sh" "$sha_a" >/dev/null
[[ "$(tr -d '[:space:]' < "$storage/active-release")" == "$sha_a" ]] || fail 'Staff rollback did not activate A.'
grep -q 'release-a.js' "$live/index.html" || fail 'Staff rollback did not restore A entrypoint.'
HOME="$test_home" /bin/bash "$release_a/scripts/cpanel-rollback-staff.sh" --previous >/dev/null
[[ "$(tr -d '[:space:]' < "$storage/active-release")" == "$sha_b" ]] || fail 'Staff --previous did not reactivate B.'
HOME="$test_home" /bin/bash "$release_b/scripts/cpanel-rollback-staff.sh" --previous >/dev/null
[[ "$(tr -d '[:space:]' < "$storage/active-release")" == "$sha_a" ]] || fail 'Staff --previous did not return to A.'

printf '\ncorrupt\n' >> "$storage/$sha_b/index.html"
if HOME="$test_home" /bin/bash "$release_b/scripts/cpanel-rollback-staff.sh" "$sha_b" >/dev/null 2>&1; then fail 'Staff rollback accepted corrupt retained release.'; fi
[[ "$(tr -d '[:space:]' < "$storage/active-release")" == "$sha_a" ]] || fail 'Corrupt rollback changed Staff pointer.'

printf '\ncorrupt source\n' >> "$release_c/dist/index.html"
if HOME="$test_home" /bin/bash "$release_c/scripts/cpanel-deploy.sh" >/dev/null 2>&1; then fail 'Staff deploy accepted corrupt package checksum.'; fi
[[ "$(tr -d '[:space:]' < "$storage/active-release")" == "$sha_a" ]] || fail 'Corrupt package changed Staff pointer.'

[[ -f "$live/.well-known/acme.txt" && -f "$live/cgi-bin/keep.txt" ]] || fail 'Preserved live directories changed.'
[[ "$(<"$test_home/arasya-operations-api/api.txt")" == 'api' && "$(<"$test_home/arasya-config/secrets.json")" == 'secret' ]] || fail 'Staff deploy touched API/config roots.'
printf 'PASS Staff retained releases preload assets, switch entrypoints safely, survive failure, and rollback by checksum.\n'
