#!/usr/bin/env bash
set -Eeuo pipefail

# Cron launcher for Trendyol intake from the active API release. NOT installed by any deploy: the owner adds the
# cron entry only after explicit authorization (docs/trendyol-intake.md). It runs bin/sync-trendyol.php, which
# still does nothing unless credentials, ARASYA_TRENDYOL_INTAKE = 'enabled' and the database activation exist.
# Cron's PATH `php` is cPanel's CGI binary: always run the PHP CLI by absolute path (ARASYA_PHP_CLI for tests).
php_cli="${ARASYA_PHP_CLI:-/usr/local/bin/php}"
[[ "$php_cli" == /* && -f "$php_cli" && -x "$php_cli" ]] || { printf 'PHP CLI %s is unavailable.\n' "$php_cli" >&2; exit 1; }

application_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
pointer="$application_root/active-release"
[[ -f "$pointer" && ! -L "$pointer" ]] || { printf 'Active API release pointer is unavailable.\n' >&2; exit 1; }
[[ "$(wc -c < "$pointer" | tr -d ' ')" -le 128 ]] || { printf 'Active API release pointer is invalid.\n' >&2; exit 1; }
source_commit="$(tr -d '[:space:]' < "$pointer")"
[[ "$source_commit" =~ ^[0-9a-f]{40}$ ]] || { printf 'Active API release pointer is invalid.\n' >&2; exit 1; }
release="$application_root/releases/$source_commit"
resolved_releases="$(cd -- "$application_root/releases" && pwd -P)"
resolved_release="$(cd -- "$release" 2>/dev/null && pwd -P)" \
  || { printf 'Active API Trendyol intake command is unavailable.\n' >&2; exit 1; }
[[ "${resolved_release%/*}" == "$resolved_releases" && "${resolved_release##*/}" == "$source_commit" && -f "$resolved_release/bin/sync-trendyol.php" ]] \
  || { printf 'Active API Trendyol intake command is unavailable.\n' >&2; exit 1; }
exec "$php_cli" "$resolved_release/bin/sync-trendyol.php" "$@"
