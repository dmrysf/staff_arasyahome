#!/usr/bin/env bash
set -Eeuo pipefail

workspace="$(mktemp -d "${TMPDIR:-/tmp}/arasya-api-release-test.XXXXXX")"
[[ "$workspace" == *"/arasya-api-release-test."* ]] || exit 1
cleanup() { rm -rf -- "$workspace"; }
trap cleanup EXIT

/bin/bash operations-api/tests/create-release-fixture.sh "$workspace/release" "$(printf 'a%.0s' {1..40})"
release="$workspace/release"
ARASYA_RELEASE_STRICT=1 /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null

mkdir "$release/.git"; touch "$release/.git/HEAD"
if ARASYA_RELEASE_STRICT=1 /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null 2>&1; then
  printf 'Strict validator accepted Git metadata.\n' >&2; exit 1
fi
rm -rf -- "$release/.git"

touch "$release/config.php"
if /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null 2>&1; then printf 'Validator accepted config.php.\n' >&2; exit 1; fi
rm -- "$release/config.php"

for forbidden in secrets.json operations-api.php .env application.log; do
  touch "$release/$forbidden"
  if /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null 2>&1; then printf 'Validator accepted forbidden file: %s.\n' "$forbidden" >&2; exit 1; fi
  rm -- "$release/$forbidden"
done

cp "$release/bootstrap.php" "$workspace/bootstrap.original"
printf '\ncorrupt\n' >> "$release/bootstrap.php"
if /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null 2>&1; then printf 'Validator accepted a checksum mismatch.\n' >&2; exit 1; fi
cp "$workspace/bootstrap.original" "$release/bootstrap.php"

for required in database/migrations/016_management_analytics.sql bin/rebuild-analytics.php src/Analytics/AnalyticsService.php; do
  mv "$release/$required" "$workspace/required.original"
  /bin/bash "$release/scripts/generate-sha256s.sh" "$release"
  if /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null 2>&1; then printf 'Validator accepted missing analytics component: %s.\n' "$required" >&2; exit 1; fi
  mv "$workspace/required.original" "$release/$required"
done
for required in database/migrations/017_production_documents.sql src/Integration/SourceRegistry.php src/Integration/SourceIngestionController.php; do
  mv "$release/$required" "$workspace/required.original"
  /bin/bash "$release/scripts/generate-sha256s.sh" "$release"
  if /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null 2>&1; then printf 'Validator accepted missing runtime component: %s.\n' "$required" >&2; exit 1; fi
  mv "$workspace/required.original" "$release/$required"
done
/bin/bash "$release/scripts/generate-sha256s.sh" "$release"
ARASYA_RELEASE_STRICT=1 /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null

printf 'PASS API release validator enforces structure, sensitive-file policy, provenance and byte-level checksums.\n'
