#!/usr/bin/env bash
set -Eeuo pipefail

workspace="$(mktemp -d "${TMPDIR:-/tmp}/arasya-api-release-test.XXXXXX")"
[[ "$workspace" == *"/arasya-api-release-test."* ]] || exit 1
cleanup() { rm -rf -- "$workspace"; }
trap cleanup EXIT

/bin/bash operations-api/tests/create-release-fixture.sh "$workspace/release" "$(printf 'a%.0s' {1..40})"
release="$workspace/release"
ARASYA_RELEASE_STRICT=1 /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null

cp "$release/release.json" "$workspace/release.original"
php -r '$p=$argv[1]; $r=json_decode(file_get_contents($p),true,flags:JSON_THROW_ON_ERROR); $r["version"]="2.26.1"; file_put_contents($p,json_encode($r,JSON_THROW_ON_ERROR));' "$release/release.json"
/bin/bash "$release/scripts/generate-sha256s.sh" "$release"
if /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null 2>&1; then
  printf 'Validator accepted stale API 2.26.1 metadata with valid checksums.\n' >&2; exit 1
fi
cp "$workspace/release.original" "$release/release.json"
cp "$release/src/Http/HealthController.php" "$workspace/health.original"
php -r '$p=$argv[1]; file_put_contents($p,str_replace("2.30.2","2.30.1",file_get_contents($p)));' "$release/src/Http/HealthController.php"
/bin/bash "$release/scripts/generate-sha256s.sh" "$release"
if /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null 2>&1; then
  printf 'Validator accepted metadata/runtime version mismatch with valid checksums.\n' >&2; exit 1
fi
cp "$workspace/health.original" "$release/src/Http/HealthController.php"
/bin/bash "$release/scripts/generate-sha256s.sh" "$release"

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
for required in bin/factory-provision.php database/reference/factory-onboarding.json src/Management/FactoryApproval.php src/Management/FactoryOnboardingPlan.php src/Management/FactoryProvisioner.php src/Management/ManagementService.php src/Iam/CredentialFile.php bin/cli-bootstrap.php; do
  mv "$release/$required" "$workspace/required.original"
  /bin/bash "$release/scripts/generate-sha256s.sh" "$release"
  if /bin/bash "$release/scripts/validate-release.sh" "$release" > "$workspace/validation-output" 2>&1; then
    printf 'Validator accepted missing factory component: %s.\n' "$required" >&2; exit 1
  fi
  grep -Fq "Required file is missing: $required" "$workspace/validation-output" || { printf 'Missing factory component was not rejected structurally: %s.\n' "$required" >&2; exit 1; }
  mv "$workspace/required.original" "$release/$required"
done
/bin/bash "$release/scripts/generate-sha256s.sh" "$release"
ARASYA_RELEASE_STRICT=1 /bin/bash "$release/scripts/validate-release.sh" "$release" >/dev/null

printf 'PASS API 2.29.0 release validator enforces runtime version consistency, required factory components, sensitive-file policy, provenance and byte-level checksums.\n'
