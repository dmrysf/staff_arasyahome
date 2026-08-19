#!/usr/bin/env bash
set -Eeuo pipefail

repository_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
source "$repository_root/scripts/staff-release-common.sh"
for command in rsync sha256sum php mv cp find sort tr grep; do staff_require_command "$command"; done
[[ $# -eq 1 ]] || staff_fail "Utilizare: cpanel-rollback-staff.sh <sourceCommit|--previous>"
deploy_path="$(staff_resolve_path "${STAFF_DEPLOY_PATH:-$HOME/staff.arasyahome.ro}")"
storage_path="$(staff_resolve_path "${STAFF_RELEASE_STORAGE_PATH:-$HOME/arasya-staff-releases}")"
home_path="$(staff_resolve_path "$HOME")"
api_path="$(staff_resolve_path "$HOME/arasya-operations-api")"
api_public_path="$(staff_resolve_path "$HOME/api.arasyahome.ro")"
config_path="$(staff_resolve_path "$HOME/arasya-config")"
for target_path in "$deploy_path" "$storage_path"; do
  [[ "$target_path" != "/" && "$target_path" != "$home_path" && "$target_path" != "$repository_root" && "$target_path" != "$api_path" && "$target_path" != "$api_public_path" && "$target_path" != "$config_path" ]] \
    || staff_fail "Destinația de rollback este interzisă."
  case "$target_path" in "$repository_root"/*|"$api_path"/*|"$api_public_path"/*|"$config_path"/*) staff_fail "Destinația de rollback este într-o rădăcină interzisă." ;; esac
done
[[ "$deploy_path" != "$storage_path" ]] || staff_fail "Document root și stocarea release trebuie separate."
case "$storage_path/" in "$deploy_path/"*) staff_fail "Stocarea release nu poate fi în document root." ;; esac
case "$deploy_path/" in "$storage_path/"*) staff_fail "Document root nu poate fi în stocarea release." ;; esac
current="$(staff_read_pointer "$storage_path/active-release")"
if [[ "$1" == "--previous" ]]; then target="$(staff_read_pointer "$storage_path/previous-release")"; else target="$1"; staff_validate_sha "$target"; fi
[[ "$target" != "$current" ]] || staff_fail "Release-ul cerut este deja activ."
retained="$storage_path/$target"; resolved_storage="$(cd -- "$storage_path" && pwd -P)"
resolved_retained="$(cd -- "$retained" 2>/dev/null && pwd -P)" || staff_fail "Release-ul de rollback nu există."
[[ "${resolved_retained%/*}" == "$resolved_storage" && "${resolved_retained##*/}" == "$target" ]] || staff_fail "Release-ul de rollback a ieșit din stocarea controlată."
/bin/bash "$repository_root/scripts/validate-staff-release.sh" "$resolved_retained" >/dev/null
[[ "$(staff_release_commit "$resolved_retained")" == "$target" ]] || staff_fail "Proveniența rollback-ului este invalidă."
if [[ "${DRY_RUN:-0}" == "1" ]]; then staff_log "Ar activa commit Staff: $target"; exit 0; fi
staff_activate_release "$resolved_retained" "$deploy_path" "$storage_path" "$target"
staff_log "Activat commit Staff: $target"
