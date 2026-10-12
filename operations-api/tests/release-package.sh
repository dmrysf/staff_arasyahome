#!/usr/bin/env bash
set -Eeuo pipefail

# Exercise the main publish payload in PR CI without publishing or deploying anything.
workspace="$(mktemp -d "${TMPDIR:-/tmp}/arasya-api-package-test.XXXXXX")"
[[ "$workspace" == *"/arasya-api-package-test."* ]] || exit 1
cleanup() { rm -rf -- "$workspace"; }
trap cleanup EXIT
source_commit="${GITHUB_SHA:-$(git rev-parse HEAD)}"
[[ "$source_commit" =~ ^[0-9a-f]{40}$ ]] || exit 1
release_dir="$workspace/release"
mkdir -p "$release_dir/config" "$release_dir/scripts"
cp operations-api/bootstrap.php operations-api/.htaccess operations-api/.cpanel.yml operations-api/README.md "$release_dir/"
cp -R operations-api/public operations-api/src operations-api/database operations-api/bin "$release_dir/"
cp operations-api/config/config.example.php operations-api/config/config.production.example.php operations-api/config/secrets.example.json "$release_dir/config/"
cp operations-api/scripts/*.sh "$release_dir/scripts/"
chmod +x "$release_dir/scripts/"*.sh
printf '{\n  "sourceCommit": "%s",\n  "builtAt": "%s",\n  "version": "2.27.0"\n}\n' "$source_commit" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$release_dir/release.json"
php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); if ($r["sourceCommit"]!==$argv[2] || $r["version"]!=="2.27.0") exit(1);' "$release_dir/release.json" "$source_commit"
/bin/bash "$release_dir/scripts/generate-sha256s.sh" "$release_dir"
ARASYA_RELEASE_STRICT=1 /bin/bash "$release_dir/scripts/validate-release.sh" "$release_dir"

# The entire IAM/order/QR/PDF runtime, all CLI tools and reference data match the checkout byte for byte.
for directory in src bin database public; do
  diff -r "operations-api/$directory" "$release_dir/$directory"
done
for component in bin/factory-provision.php database/reference/factory-onboarding.json src/Management/FactoryApproval.php src/Management/FactoryOnboardingPlan.php src/Management/FactoryProvisioner.php src/Management/ManagementService.php src/Iam/CredentialFile.php bin/cli-bootstrap.php; do
  grep -Fq "  ./$component" "$release_dir/SHA256SUMS" || { printf 'Factory component is not checksummed: %s.\n' "$component" >&2; exit 1; }
done
php -r '
require $argv[1]."/bootstrap.php";
foreach ([Arasya\Operations\Management\FactoryApproval::class, Arasya\Operations\Management\FactoryOnboardingPlan::class, Arasya\Operations\Management\FactoryProvisioner::class, Arasya\Operations\Management\ManagementService::class, Arasya\Operations\Iam\CredentialFile::class] as $class) {
    if (!class_exists($class)) throw new RuntimeException("Packaged factory dependency cannot be autoloaded.");
}
$matrix=Arasya\Operations\Management\FactoryOnboardingPlan::fromFiles($argv[1]."/database/reference/factory-onboarding.json",$argv[1]."/database/reference/organization-roster.json");
if (count($matrix->people())!==29) throw new RuntimeException("Packaged factory matrix is incomplete.");
' "$release_dir"

test_api_root="$workspace/isolated-home/arasya-operations-api"
test_public_root="$workspace/isolated-home/api.arasyahome.ro"
ARASYA_API_ROOT_PATH="$test_api_root" ARASYA_API_PUBLIC_PATH="$test_public_root" DRY_RUN=1 /bin/bash "$release_dir/scripts/cpanel-deploy-api.sh"
[[ ! -e "$test_api_root" && ! -e "$test_public_root" ]] || { printf 'Packaged deploy dry-run mutated its isolated targets.\n' >&2; exit 1; }
printf 'PASS real API 2.27.0 package: source %s, complete/checksummed factory runtime, 29-person matrix, strict validation and zero-write deploy dry-run.\n' "$source_commit"
