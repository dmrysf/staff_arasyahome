#!/usr/bin/env bash
set -Eeuo pipefail

fail() {
  printf '[Arasya Operations API] RELEASE INVALID: %s\n' "$1" >&2
  exit 1
}

release_root="${1:-}"
[[ -n "$release_root" && -d "$release_root" ]] || fail "Release directory is missing."
for command in find sha256sum grep php; do command -v "$command" >/dev/null 2>&1 || fail "$command is required."; done
release_root="$(cd -- "$release_root" && pwd -P)"
[[ -z "$(find "$release_root" -type l -print -quit)" ]] || fail "Symlinks are forbidden in API releases."

for file in \
  .cpanel.yml \
  .htaccess \
  bootstrap.php \
  public/index.php \
  public/.htaccess \
  public/RuntimeLocator.php \
  config/secrets.example.json \
  bin/maintenance.php \
  bin/migration-status.php \
  bin/readiness.php \
  bin/order-qr.php \
  bin/sync-trendyol.php \
  database/migrations/004_staff_operations.sql \
  database/migrations/010_b2b_orders.sql \
  src/B2B/OrderController.php \
  src/B2B/OrderCommands.php \
  src/B2B/OrderQueries.php \
  src/B2B/OrderCalculator.php \
  src/B2B/OrderStore.php \
  database/migrations/011_b2b_current_account.sql \
  database/migrations/012_b2b_production_handoff.sql \
  src/B2B/ProductionAccess.php \
  src/B2B/ProductionCommands.php \
  src/B2B/ProductionQueries.php \
  src/B2B/ProductionInput.php \
  src/B2B/AccountAccess.php \
  src/B2B/AccountCommands.php \
  src/B2B/AccountController.php \
  src/B2B/AccountInput.php \
  src/B2B/AccountLedger.php \
  src/B2B/AccountMoney.php \
  src/B2B/AccountQueries.php \
  src/B2B/AccountStatementExport.php \
  src/B2B/Pdf/PdfDocument.php \
  src/B2B/Pdf/TrueTypeFont.php \
  src/B2B/Pdf/fonts/DejaVuSansCondensed.ttf \
  src/B2B/Pdf/fonts/DejaVuSansCondensed-Bold.ttf \
  src/B2B/Pdf/fonts/DejaVu-LICENSE.txt \
  scripts/api-release-common.sh \
  scripts/cpanel-deploy-api.sh \
  scripts/cpanel-rollback-api.sh \
  scripts/generate-sha256s.sh \
  scripts/maintenance-active.sh \
  scripts/validate-release.sh \
  release.json \
  SHA256SUMS; do
  [[ -f "$release_root/$file" ]] || fail "Required file is missing: $file"
done

(cd -- "$release_root" && sha256sum -c SHA256SUMS >/dev/null) || fail "SHA256SUMS verification failed."
php -r '$r=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); if (!is_array($r) || preg_match("/^[0-9a-f]{40}$/", $r["sourceCommit"] ?? "") !== 1 || ($r["version"] ?? null) !== "2.12.1") exit(2);' "$release_root/release.json" \
  || fail "release.json provenance/version is invalid."

for directory in src database/migrations bin config; do
  [[ -d "$release_root/$directory" ]] || fail "Required directory is missing: $directory"
done

for forbidden_directory in tests var node_modules; do
  [[ ! -e "$release_root/$forbidden_directory" ]] || fail "Forbidden artifact is present: $forbidden_directory"
done
if [[ "${ARASYA_RELEASE_STRICT:-0}" == "1" && -e "$release_root/.git" ]]; then
  fail "Git metadata is forbidden in the packaged release."
fi

forbidden_file="$(find "$release_root" -path "$release_root/.git" -prune -o -type f \( -name 'secrets.json' -o -name 'operations-api.php' -o -name 'config.php' -o -name '.env' -o -name '.env.*' -o -name '*.log' \) -print -quit)"
[[ -z "$forbidden_file" ]] || fail "Forbidden configuration/log artifact is present."

if [[ -f "$release_root/config/secrets.example.json" ]]; then
  grep -Eq '"DB_USER_PASSWORD"[[:space:]]*:[[:space:]]*"replace-with-' "$release_root/config/secrets.example.json" \
    || fail "The JSON config example does not contain a password placeholder."
  grep -Eq '"ARASYA_APP_SECRET"[[:space:]]*:[[:space:]]*"replace-with-' "$release_root/config/secrets.example.json" \
    || fail "The JSON config example does not contain an app-secret placeholder."
fi

if grep -RIEq --exclude-dir='.git' --exclude='*.example.php' --exclude='*.example.json' --exclude='README.md' \
  'BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|AKIA[0-9A-Z]{16}' "$release_root"; then
  fail "A likely credential marker was found in the release."
fi

printf '[Arasya Operations API] Release validation passed: %s\n' "$release_root"
