#!/usr/bin/env bash
set -Eeuo pipefail

log() {
  printf '[Arasya Operations API] %s\n' "$1"
}

fail() {
  printf '[Arasya Operations API] ERROR: %s\n' "$1" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "Required command is missing: $1"
}

resolve_path() {
  local target="$1"
  local suffix=""
  local resolved

  [[ "$target" == /* ]] || fail "Deployment destination must be an absolute path."
  case "/$target/" in
    *"/../"*|*"/./"*) fail "Deployment destination contains unsafe path segments." ;;
  esac

  while [[ ! -e "$target" ]]; do
    suffix="/${target##*/}$suffix"
    target="${target%/*}"
    [[ -n "$target" ]] || target="/"
  done

  [[ -d "$target" ]] || fail "Deployment destination base is not a directory."
  resolved="$(cd -- "$target" && pwd -P)"
  if [[ "$resolved" == "/" ]]; then
    printf '/%s\n' "${suffix#/}"
  else
    printf '%s%s\n' "$resolved" "$suffix"
  fi
}

require_command rsync

release_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
deploy_input="${ARASYA_API_DEPLOY_PATH:-$HOME/arasya-operations-api/current}"
deploy_path="$(resolve_path "$deploy_input")"
home_path="$(resolve_path "$HOME")"
staff_path="$(resolve_path "$HOME/staff.arasyahome.ro")"
config_path="$(resolve_path "$HOME/arasya-config")"

/bin/bash "$release_root/scripts/validate-release.sh" "$release_root"

[[ "$deploy_path" != "/" ]] || fail "Deployment destination cannot be /."
[[ "$deploy_path" != "$home_path" ]] || fail "Deployment destination cannot be HOME."
[[ "$deploy_path" != "$staff_path" ]] || fail "API files cannot target the Staff document root."
[[ "$deploy_path" != "$release_root" ]] || fail "Deployment destination cannot be the release repository."
[[ "$deploy_path" != "$config_path" ]] || fail "Deployment destination cannot be the private configuration directory."
case "$deploy_path" in
  "$release_root"/*) fail "Deployment destination cannot be inside the release repository." ;;
  "$config_path"/*) fail "Deployment destination cannot be inside the private configuration directory." ;;
esac

if [[ "${DRY_RUN:-0}" == "1" ]]; then
  log "Dry-run passed. Verified release destination: $deploy_path"
  exit 0
fi

log "Synchronizing verified PHP release without running migrations..."
mkdir -p -- "$deploy_path"
rsync --archive --delete --exclude='.git' "$release_root/" "$deploy_path/"
log "Code deployment completed: $deploy_path"
