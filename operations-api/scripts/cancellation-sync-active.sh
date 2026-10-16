#!/usr/bin/env bash
set -Eeuo pipefail

# Cron launcher for the outbound cancellation worker (API 2.36.0) from the active API release. NOT scheduled by any
# deploy: the owner adds the cron entry only after explicit authorization (docs/order-cancellation-sync.md). It accepts
# exactly `run` or `reconcile --dry-run=1`; enabling, disabling and recorded reconciliation stay manual operator
# commands. The worker still contacts nobody unless ARASYA_CANCELLATION_SYNC=enabled and the provider flag is on.
# Overlapping runs are safe (each operation is leased by one worker), so no file lock is needed.
# Cron's PATH `php` is cPanel's CGI binary: always run the PHP CLI by absolute path (ARASYA_PHP_CLI for tests).
php_cli="${ARASYA_PHP_CLI:-/usr/local/bin/php}"
[[ "$php_cli" == /* && -f "$php_cli" && -x "$php_cli" ]] || { printf 'PHP CLI %s is unavailable.\n' "$php_cli" >&2; exit 1; }
[[ "$*" == "run" || "$*" == "reconcile --dry-run=1" ]] || { printf 'Only "run" or "reconcile --dry-run=1" may be scheduled.\n' >&2; exit 2; }

application_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
pointer="$application_root/active-release"
[[ -f "$pointer" && ! -L "$pointer" ]] || { printf 'Active API release pointer is unavailable.\n' >&2; exit 1; }
[[ "$(wc -c < "$pointer" | tr -d ' ')" -le 128 ]] || { printf 'Active API release pointer is invalid.\n' >&2; exit 1; }
source_commit="$(tr -d '[:space:]' < "$pointer")"
[[ "$source_commit" =~ ^[0-9a-f]{40}$ ]] || { printf 'Active API release pointer is invalid.\n' >&2; exit 1; }
release="$application_root/releases/$source_commit"
resolved_releases="$(cd -- "$application_root/releases" && pwd -P)"
resolved_release="$(cd -- "$release" 2>/dev/null && pwd -P)" \
  || { printf 'Active API cancellation command is unavailable.\n' >&2; exit 1; }
[[ "${resolved_release%/*}" == "$resolved_releases" && "${resolved_release##*/}" == "$source_commit" && -f "$resolved_release/bin/cancellation-sync.php" ]] \
  || { printf 'Active API cancellation command is unavailable.\n' >&2; exit 1; }
exec "$php_cli" "$resolved_release/bin/cancellation-sync.php" "$@"
