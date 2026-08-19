#!/usr/bin/env bash
set -Eeuo pipefail

release_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
source "$release_root/scripts/api-release-common.sh"
for command in sha256sum php mv find grep tr wc; do api_require_command "$command"; done
[[ $# -eq 1 ]] || api_fail "Usage: cpanel-rollback-api.sh <sourceCommit|--previous>"
api_root="$(api_resolve_path "${ARASYA_API_ROOT_PATH:-$HOME/arasya-operations-api}")"
home_path="$(api_resolve_path "$HOME")"
staff_path="$(api_resolve_path "$HOME/staff.arasyahome.ro")"
config_path="$(api_resolve_path "$HOME/arasya-config")"
[[ "$api_root" != "/" && "$api_root" != "$home_path" && "$api_root" != "$staff_path" && "$api_root" != "$config_path" && "$api_root" != "$release_root" ]] \
  || api_fail "Rollback root is forbidden."
case "$api_root" in "$staff_path"/*|"$config_path"/*|"$release_root"/*) api_fail "Rollback root is inside a forbidden path." ;; esac
releases_root="$api_root/releases"; active_pointer="$api_root/active-release"; previous_pointer="$api_root/previous-release"
[[ -d "$releases_root" ]] || api_fail "API releases root is unavailable."
current="$(api_read_pointer "$active_pointer")"
if [[ "$1" == "--previous" ]]; then target="$(api_read_pointer "$previous_pointer")"; else target="$1"; api_validate_sha "$target"; fi
[[ "$target" != "$current" ]] || api_fail "Requested release is already active."
target_release="$releases_root/$target"; resolved_releases="$(cd -- "$releases_root" && pwd -P)"
resolved_target="$(cd -- "$target_release" 2>/dev/null && pwd -P)" || api_fail "Rollback release does not exist."
[[ "${resolved_target%/*}" == "$resolved_releases" && "${resolved_target##*/}" == "$target" ]] || api_fail "Rollback release escaped releases root."
api_validate_packaged_release "$resolved_target"
[[ "$(api_release_sha "$resolved_target")" == "$target" ]] || api_fail "Rollback release provenance is invalid."
if [[ "${DRY_RUN:-0}" == "1" ]]; then api_log "Would activate source commit: $target"; exit 0; fi
api_write_pointer "$previous_pointer" "$current"; api_write_pointer "$active_pointer" "$target"
[[ "$(api_read_pointer "$active_pointer")" == "$target" ]] || api_fail "Rollback pointer verification failed."
api_log "Activated source commit: $target"
