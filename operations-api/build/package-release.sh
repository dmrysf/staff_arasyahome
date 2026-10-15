#!/usr/bin/env bash
set -Eeuo pipefail

# Builds the dependency-free Operations API release payload. The main publish job and the PR package test both call this
# script, so a pull request exercises exactly the code that prepares the api-deploy branch.
#
# The release version has one source: HealthController::VERSION in the checkout. release.json is written from it and
# then validated against it; validate-release.sh separately pins the expected version and compares it to the packaged
# runtime constant.
#
# Usage: operations-api/build/package-release.sh <empty-release-dir> <40-hex-source-commit>   (run from the repository root)

release_dir="${1:?release directory required}"; source_commit="${2:?source commit required}"
[[ "$source_commit" =~ ^[0-9a-f]{40}$ ]] || { printf 'Invalid source commit.\n' >&2; exit 1; }
[[ -f operations-api/bootstrap.php && -f operations-api/src/Http/HealthController.php ]] || { printf 'Run from the repository root.\n' >&2; exit 1; }
[[ ! -e "$release_dir" ]] || [[ -d "$release_dir" && -z "$(ls -A -- "$release_dir")" ]] || { printf 'Release directory must be new or empty.\n' >&2; exit 1; }

version="$(php -r 'require $argv[1]; echo Arasya\Operations\Http\HealthController::VERSION;' operations-api/bootstrap.php)"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { printf 'HealthController::VERSION is not a release version.\n' >&2; exit 1; }

mkdir -p "$release_dir/config" "$release_dir/scripts"
cp operations-api/bootstrap.php operations-api/.htaccess operations-api/.cpanel.yml operations-api/README.md "$release_dir/"
cp -R operations-api/public operations-api/src operations-api/database operations-api/bin "$release_dir/"
cp operations-api/config/config.example.php operations-api/config/config.production.example.php operations-api/config/secrets.example.json "$release_dir/config/"
cp operations-api/scripts/*.sh "$release_dir/scripts/"
chmod +x "$release_dir/scripts/"*.sh
printf '{\n  "sourceCommit": "%s",\n  "builtAt": "%s",\n  "version": "%s"\n}\n' "$source_commit" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$version" > "$release_dir/release.json"
php -r '
$r = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
require $argv[2] . "/bootstrap.php";
if (($r["sourceCommit"] ?? null) !== $argv[3] || ($r["version"] ?? null) !== $argv[4] || $r["version"] !== Arasya\Operations\Http\HealthController::VERSION) {
    throw new RuntimeException("API release metadata does not match source.");
}' "$release_dir/release.json" "$release_dir" "$source_commit" "$version"
/bin/bash "$release_dir/scripts/generate-sha256s.sh" "$release_dir"
ARASYA_RELEASE_STRICT=1 /bin/bash "$release_dir/scripts/validate-release.sh" "$release_dir"
printf 'Built API %s release from %s.\n' "$version" "$source_commit"
