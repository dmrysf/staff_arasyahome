#!/usr/bin/env bash
set -Eeuo pipefail

application_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
pointer="$application_root/active-release"
[[ -f "$pointer" && ! -L "$pointer" ]] || { printf 'Active API release pointer is unavailable.\n' >&2; exit 1; }
[[ "$(wc -c < "$pointer" | tr -d ' ')" -le 128 ]] || { printf 'Active API release pointer is invalid.\n' >&2; exit 1; }
source_commit="$(tr -d '[:space:]' < "$pointer")"
[[ "$source_commit" =~ ^[0-9a-f]{40}$ ]] || { printf 'Active API release pointer is invalid.\n' >&2; exit 1; }
release="$application_root/releases/$source_commit"
resolved_releases="$(cd -- "$application_root/releases" && pwd -P)"
resolved_release="$(cd -- "$release" 2>/dev/null && pwd -P)" \
  || { printf 'Active API maintenance command is unavailable.\n' >&2; exit 1; }
[[ "${resolved_release%/*}" == "$resolved_releases" && "${resolved_release##*/}" == "$source_commit" && -f "$resolved_release/bin/maintenance.php" ]] \
  || { printf 'Active API maintenance command is unavailable.\n' >&2; exit 1; }
exec php "$resolved_release/bin/maintenance.php" "$@"
