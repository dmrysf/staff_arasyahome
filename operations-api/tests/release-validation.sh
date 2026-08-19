#!/usr/bin/env bash
set -Eeuo pipefail

workspace="$(mktemp -d "${TMPDIR:-/tmp}/arasya-api-release-test.XXXXXX")"
[[ "$workspace" == *"/arasya-api-release-test."* ]] || exit 1
cleanup() {
  rm -rf -- "$workspace"
}
trap cleanup EXIT

mkdir -p "$workspace/public" "$workspace/src" "$workspace/database/migrations" "$workspace/bin" "$workspace/config" "$workspace/scripts"
touch "$workspace/.cpanel.yml" "$workspace/.htaccess" "$workspace/bootstrap.php" "$workspace/public/index.php" "$workspace/public/.htaccess"
cp operations-api/scripts/cpanel-deploy-api.sh operations-api/scripts/validate-release.sh "$workspace/scripts/"
printf '{"sourceCommit":"test","builtAt":"2026-08-19T00:00:00Z","version":"2.0.1"}\n' > "$workspace/release.json"

/bin/bash operations-api/scripts/validate-release.sh "$workspace" >/dev/null

mkdir "$workspace/.git"
touch "$workspace/.git/HEAD"
/bin/bash operations-api/scripts/validate-release.sh "$workspace" >/dev/null
if ARASYA_RELEASE_STRICT=1 /bin/bash operations-api/scripts/validate-release.sh "$workspace" >/dev/null 2>&1; then
  printf 'Strict release validator accepted Git metadata.\n' >&2
  exit 1
fi
rm -rf -- "$workspace/.git"

touch "$workspace/config.php"
if /bin/bash operations-api/scripts/validate-release.sh "$workspace" >/dev/null 2>&1; then
  printf 'Release validator accepted config.php.\n' >&2
  exit 1
fi
rm -- "$workspace/config.php"

mkdir "$workspace/tests"
if /bin/bash operations-api/scripts/validate-release.sh "$workspace" >/dev/null 2>&1; then
  printf 'Release validator accepted tests/.\n' >&2
  exit 1
fi

printf 'PASS API release validator accepts checkout metadata only outside strict packaging and rejects forbidden artifacts.\n'
