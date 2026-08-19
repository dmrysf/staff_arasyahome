#!/usr/bin/env bash
set -Eeuo pipefail

fail() {
  printf '[Arasya Operations API] RELEASE INVALID: %s\n' "$1" >&2
  exit 1
}

release_root="${1:-}"
[[ -n "$release_root" && -d "$release_root" ]] || fail "Release directory is missing."
release_root="$(cd -- "$release_root" && pwd -P)"

for file in \
  .cpanel.yml \
  .htaccess \
  bootstrap.php \
  public/index.php \
  public/.htaccess \
  scripts/cpanel-deploy-api.sh \
  scripts/validate-release.sh \
  release.json; do
  [[ -f "$release_root/$file" ]] || fail "Required file is missing: $file"
done

for directory in src database/migrations bin config; do
  [[ -d "$release_root/$directory" ]] || fail "Required directory is missing: $directory"
done

for forbidden_directory in tests var node_modules; do
  [[ ! -e "$release_root/$forbidden_directory" ]] || fail "Forbidden artifact is present: $forbidden_directory"
done
if [[ "${ARASYA_RELEASE_STRICT:-0}" == "1" && -e "$release_root/.git" ]]; then
  fail "Git metadata is forbidden in the packaged release."
fi

forbidden_file="$(find "$release_root" -path "$release_root/.git" -prune -o -type f \( -name 'config.php' -o -name '.env' -o -name '.env.*' -o -name '*.log' \) -print -quit)"
[[ -z "$forbidden_file" ]] || fail "Forbidden configuration/log artifact is present."

if grep -RIEq --exclude-dir='.git' --exclude='*.example.php' --exclude='README.md' \
  'BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|AKIA[0-9A-Z]{16}' "$release_root"; then
  fail "A likely credential marker was found in the release."
fi

printf '[Arasya Operations API] Release validation passed: %s\n' "$release_root"
