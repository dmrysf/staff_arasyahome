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
runtime_input="${ARASYA_API_RUNTIME_PATH:-$HOME/arasya-operations-api/current}"
public_input="${ARASYA_API_PUBLIC_PATH:-$HOME/api.arasyahome.ro}"
runtime_path="$(resolve_path "$runtime_input")"
public_path="$(resolve_path "$public_input")"
home_path="$(resolve_path "$HOME")"
staff_path="$(resolve_path "$HOME/staff.arasyahome.ro")"
config_path="$(resolve_path "$HOME/arasya-config")"

/bin/bash "$release_root/scripts/validate-release.sh" "$release_root"

for target in "$runtime_path" "$public_path"; do
  [[ "$target" != "/" ]] || fail "Deployment destinations cannot be /."
  [[ "$target" != "$home_path" ]] || fail "Deployment destinations cannot be HOME."
  [[ "$target" != "$staff_path" ]] || fail "API files cannot target the Staff document root."
  [[ "$target" != "$config_path" ]] || fail "Deployment destinations cannot be the private configuration directory."
  [[ "$target" != "$release_root" ]] || fail "Deployment destinations cannot be the release repository."
  case "$target" in
    "$staff_path"/*) fail "API files cannot target a directory inside the Staff document root." ;;
    "$config_path"/*) fail "Deployment destinations cannot be inside the private configuration directory." ;;
    "$release_root"/*) fail "Deployment destinations cannot be inside the release repository." ;;
  esac
done

[[ "$runtime_path" != "$public_path" ]] || fail "Private runtime and public document root must be different."
case "$runtime_path/" in "$public_path/"*) fail "Private runtime cannot be inside the public document root." ;; esac
case "$public_path/" in "$runtime_path/"*) fail "Public document root cannot be inside the private runtime." ;; esac

if [[ "${DRY_RUN:-0}" == "1" ]]; then
  log "Dry-run passed. Runtime: $runtime_path; public: $public_path"
  exit 0
fi

log "Synchronizing verified private runtime first (migrations remain manual)..."
mkdir -p -- "$runtime_path"
rsync --archive --delete --exclude='.git' "$release_root/" "$runtime_path/"
[[ -f "$runtime_path/bootstrap.php" ]] || fail "Private runtime verification failed: bootstrap.php is missing."
for directory in src database/migrations bin config; do
  [[ -d "$runtime_path/$directory" ]] || fail "Private runtime verification failed: $directory is missing."
done
[[ -f "$runtime_path/release.json" ]] || fail "Private runtime verification failed: release.json is missing."

log "Synchronizing public-only API files second..."
mkdir -p -- "$public_path"
rsync --archive --delete \
  --exclude='.well-known/' \
  --exclude='cgi-bin/' \
  "$release_root/public/" "$public_path/"

[[ -f "$public_path/index.php" ]] || fail "Public deployment verification failed: index.php is missing."
[[ -f "$public_path/.htaccess" ]] || fail "Public deployment verification failed: .htaccess is missing."
[[ -f "$public_path/RuntimeLocator.php" ]] || fail "Public deployment verification failed: RuntimeLocator.php is missing."
for forbidden in src database bin config bootstrap.php; do
  [[ ! -e "$public_path/$forbidden" ]] || fail "Private artifact appeared in the public document root: $forbidden"
done
if find "$public_path" -name 'secrets.json' -print -quit | grep -q .; then
  fail "A private secrets.json file appeared in the public document root."
fi

log "Two-target code deployment completed successfully."
