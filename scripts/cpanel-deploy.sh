#!/usr/bin/env bash
set -Eeuo pipefail

log() {
  printf '[Arasya Staff] %s\n' "$1"
}

fail() {
  printf '[Arasya Staff] EROARE: %s\n' "$1" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "Comanda necesară lipsește: $1"
}

repository_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
dist_path="$repository_root/dist"
deploy_input="${STAFF_DEPLOY_PATH:-$HOME/public_html/staff.arasyahome.ro}"

require_command rsync

resolve_path() {
  local target="$1"
  local suffix=""
  local resolved

  [[ "$target" == /* ]] || fail "Destinația de deploy trebuie să fie o cale absolută."
  case "/$target/" in
    *"/../"*|*"/./"*) fail "Destinația de deploy conține segmente de cale nesigure." ;;
  esac

  while [[ ! -e "$target" ]]; do
    suffix="/${target##*/}$suffix"
    target="${target%/*}"
    [[ -n "$target" ]] || target="/"
  done

  [[ -d "$target" ]] || fail "Calea de bază a destinației nu este un director."
  resolved="$(cd -- "$target" && pwd -P)"
  if [[ "$resolved" == "/" ]]; then
    printf '/%s\n' "${suffix#/}"
  else
    printf '%s%s\n' "$resolved" "$suffix"
  fi
}

repository_root="$(resolve_path "$repository_root")"
dist_path="$(resolve_path "$dist_path")"
deploy_path="$(resolve_path "$deploy_input")"
home_path="$(resolve_path "$HOME")"
public_html_path="$(resolve_path "$HOME/public_html")"

[[ -d "$dist_path" ]] || fail "Lipsește directorul dist. Release-ul verificat nu a fost publicat corect de GitHub Actions."
[[ -f "$dist_path/index.html" ]] || fail "Lipsește dist/index.html. Release-ul verificat nu a fost publicat corect de GitHub Actions."
[[ -f "$dist_path/.htaccess" ]] || fail "Lipsește dist/.htaccess. Deploy-ul SPA nu poate continua."
[[ -d "$dist_path/assets" ]] || fail "Lipsește dist/assets. Release-ul verificat este incomplet."
[[ -f "$dist_path/manifest.webmanifest" ]] || fail "Lipsește manifestul PWA din dist."
[[ -f "$dist_path/sw.js" ]] || fail "Lipsește service worker-ul din dist."

shopt -s nullglob
javascript_assets=("$dist_path/assets/"*.js)
stylesheet_assets=("$dist_path/assets/"*.css)
(( ${#javascript_assets[@]} > 0 )) || fail "Lipsește bundle-ul JavaScript verificat din dist/assets."
(( ${#stylesheet_assets[@]} > 0 )) || fail "Lipsește bundle-ul CSS verificat din dist/assets."

[[ "$deploy_path" != "/" ]] || fail "Destinația de deploy nu poate fi /."
[[ "$deploy_path" != "$home_path" ]] || fail "Destinația de deploy nu poate fi HOME."
[[ "$deploy_path" != "$public_html_path" ]] || fail "Folosește document root-ul subdomeniului Staff, nu public_html."
[[ "$deploy_path" != "$repository_root" ]] || fail "Destinația de deploy nu poate fi repository-ul."
[[ "$deploy_path" != "$dist_path" ]] || fail "Destinația de deploy nu poate fi dist/."
case "$deploy_path" in
  "$repository_root"/*) fail "Destinația de deploy nu poate fi în repository." ;;
esac

if [[ "${DRY_RUN:-0}" == "1" ]]; then
  log "Dry-run static reușit. Release și destinație validate: $deploy_path"
  exit 0
fi

log "Sincronizare release static verificat..."
mkdir -p -- "$deploy_path"
rsync --archive --delete \
  --exclude='.well-known/' \
  --exclude='cgi-bin/' \
  "$dist_path/" "$deploy_path/"

log "Deploy finalizat: $deploy_path"
