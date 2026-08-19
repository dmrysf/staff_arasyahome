#!/usr/bin/env bash
set -Eeuo pipefail

release_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
source "$release_root/scripts/api-release-common.sh"
for command in rsync sha256sum php mv cp mkdir find sort stat chmod tr wc grep rm xargs; do api_require_command "$command"; done
gc_dry_run="${ARASYA_RELEASE_GC_DRY_RUN:-0}"
[[ "$gc_dry_run" == "0" || "$gc_dry_run" == "1" ]] || api_fail "ARASYA_RELEASE_GC_DRY_RUN must be 0 or 1."
release_retention="${ARASYA_API_RELEASE_RETENTION:-5}"
[[ "$release_retention" =~ ^[0-9]+$ && "$release_retention" -ge 1 ]] || api_fail "ARASYA_API_RELEASE_RETENTION must be positive."

api_root_input="${ARASYA_API_ROOT_PATH:-}"
if [[ -z "$api_root_input" && -n "${ARASYA_API_RUNTIME_PATH:-}" ]]; then
  [[ "${ARASYA_API_RUNTIME_PATH##*/}" == "current" ]] || api_fail "Legacy runtime override must end in /current."
  api_root_input="${ARASYA_API_RUNTIME_PATH%/current}"
fi
api_root_input="${api_root_input:-$HOME/arasya-operations-api}"
public_input="${ARASYA_API_PUBLIC_PATH:-$HOME/api.arasyahome.ro}"
api_root="$(api_resolve_path "$api_root_input")"
public_path="$(api_resolve_path "$public_input")"
home_path="$(api_resolve_path "$HOME")"
staff_path="$(api_resolve_path "$HOME/staff.arasyahome.ro")"
config_path="$(api_resolve_path "$HOME/arasya-config")"

api_validate_packaged_release "$release_root"
source_commit="$(api_release_sha "$release_root")"
api_validate_sha "$source_commit"

for target in "$api_root" "$public_path"; do
  [[ "$target" != "/" && "$target" != "$home_path" && "$target" != "$staff_path" && "$target" != "$config_path" && "$target" != "$release_root" ]] \
    || api_fail "Deployment target is forbidden."
  case "$target" in "$staff_path"/*|"$config_path"/*|"$release_root"/*) api_fail "Deployment target is inside a forbidden root." ;; esac
done
[[ "$api_root" != "$public_path" ]] || api_fail "Private runtime and public root must differ."
case "$api_root/" in "$public_path/"*) api_fail "Private runtime cannot be inside public root." ;; esac
case "$public_path/" in "$api_root/"*) api_fail "Public root cannot be inside private runtime." ;; esac

if [[ "${DRY_RUN:-0}" == "1" ]]; then api_log "Dry-run passed for source commit $source_commit."; exit 0; fi

releases_root="$api_root/releases"
target_release="$releases_root/$source_commit"
staging_release="$releases_root/.staging-$source_commit-$$"
active_pointer="$api_root/active-release"
previous_pointer="$api_root/previous-release"
stable_bin="$api_root/bin"
cleanup() { [[ -d "$staging_release" ]] && rm -rf -- "$staging_release"; }
trap cleanup EXIT

mkdir -p -- "$releases_root" "$public_path" "$stable_bin"
resolved_releases="$(cd -- "$releases_root" && pwd -P)"
[[ "${target_release%/*}" == "$resolved_releases" ]] || api_fail "Release target escaped releases root."
for forbidden in src database config bootstrap.php release.json; do
  [[ ! -e "$public_path/$forbidden" ]] || api_fail "Private artifact exists in API public root: $forbidden"
done

if [[ -d "$target_release" ]]; then
  api_validate_packaged_release "$target_release"
else
  mkdir -- "$staging_release"
  rsync --archive --delete --exclude='.git' "$release_root/" "$staging_release/"
  api_validate_packaged_release "$staging_release"
  [[ "$(api_release_sha "$staging_release")" == "$source_commit" ]] || api_fail "Staged release provenance changed."
  mv -- "$staging_release" "$target_release"
fi

old_active=""
if [[ -e "$active_pointer" || -L "$active_pointer" ]]; then
  old_active="$(api_read_pointer "$active_pointer")"
  [[ -d "$releases_root/$old_active" ]] || api_fail "Existing active pointer target is unavailable."
fi

legacy_current="$api_root/current"
if [[ -z "$old_active" && -d "$legacy_current" && -f "$legacy_current/release.json" && -x "$legacy_current/scripts/validate-release.sh" ]]; then
  legacy_commit="$(api_release_sha "$legacy_current" 2>/dev/null || true)"
  if [[ "$legacy_commit" =~ ^[0-9a-f]{40}$ && ! -d "$releases_root/$legacy_commit" ]] \
    && /bin/bash "$legacy_current/scripts/validate-release.sh" "$legacy_current" >/dev/null 2>&1; then
    legacy_staging="$releases_root/.staging-legacy-$legacy_commit-$$"
    mkdir -- "$legacy_staging"
    rsync --archive --delete --exclude='.git' "$legacy_current/" "$legacy_staging/"
    /bin/bash "$release_root/scripts/generate-sha256s.sh" "$legacy_staging"
    api_verify_checksum_manifest "$legacy_staging"
    mv -- "$legacy_staging" "$releases_root/$legacy_commit"
    api_log "Retained verified legacy current release: $legacy_commit"
  else
    api_log "Legacy current release was not snapshot; live legacy directory remains untouched."
  fi
fi

[[ "${ARASYA_TEST_FAIL_BEFORE_ACTIVATION:-0}" != "1" ]] || api_fail "Simulated failure before activation."

api_atomic_copy_file "$target_release/public/RuntimeLocator.php" "$public_path/RuntimeLocator.php"
api_atomic_copy_file "$target_release/public/index.php" "$public_path/index.php"
api_atomic_copy_file "$target_release/public/.htaccess" "$public_path/.htaccess"
api_atomic_copy_file "$target_release/scripts/maintenance-active.sh" "$stable_bin/maintenance-active.sh"
chmod 0755 "$stable_bin/maintenance-active.sh"

if [[ -n "$old_active" ]]; then api_write_pointer "$previous_pointer" "$old_active"; fi
api_write_pointer "$active_pointer" "$source_commit"
[[ "$(api_read_pointer "$active_pointer")" == "$source_commit" ]] || api_fail "Active release verification failed."
api_validate_packaged_release "$target_release"

previous=""; if [[ -f "$previous_pointer" ]]; then previous="$(api_read_pointer "$previous_pointer")"; fi
api_gc_releases "$releases_root" "$source_commit" "$previous" "$release_retention" "$gc_dry_run"
trap - EXIT
api_log "Activated source commit: $source_commit"
