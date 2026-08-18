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

require_command node
require_command corepack
require_command rsync

node -e 'const [major, minor] = process.versions.node.split(".").map(Number); if (major < 22 || (major === 22 && minor < 13)) process.exit(1)' \
  || fail "Node 22.13.0 sau mai nou este necesar pentru build."

resolve_path() {
  node -e 'const fs = require("node:fs"); const path = require("node:path"); let target = path.resolve(process.argv[1]); const missing = []; while (!fs.existsSync(target)) { const parent = path.dirname(target); if (parent === target) break; missing.unshift(path.basename(target)); target = parent; } const resolved = fs.existsSync(target) ? fs.realpathSync(target) : target; console.log(path.join(resolved, ...missing));' "$1"
}

repository_root="$(resolve_path "$repository_root")"
dist_path="$(resolve_path "$dist_path")"
deploy_path="$(resolve_path "$deploy_input")"
home_path="$(resolve_path "$HOME")"
public_html_path="$(resolve_path "$HOME/public_html")"

[[ "$deploy_path" != "/" ]] || fail "Destinația de deploy nu poate fi /."
[[ "$deploy_path" != "$home_path" ]] || fail "Destinația de deploy nu poate fi HOME."
[[ "$deploy_path" != "$public_html_path" ]] || fail "Folosește document root-ul subdomeniului Staff, nu public_html."
[[ "$deploy_path" != "$repository_root" ]] || fail "Destinația de deploy nu poate fi repository-ul."
[[ "$deploy_path" != "$dist_path" ]] || fail "Destinația de deploy nu poate fi dist/."
case "$deploy_path" in
  "$repository_root"/*) fail "Destinația de deploy nu poate fi în repository." ;;
esac

log "Instalare dependențe din lockfile..."
cd "$repository_root"
corepack pnpm install --frozen-lockfile --config.confirmModulesPurge=false

log "Rulare verificări obligatorii..."
corepack pnpm verify

[[ -f "$dist_path/index.html" ]] || fail "Build-ul verificat nu conține dist/index.html."

if [[ "${DRY_RUN:-0}" == "1" ]]; then
  log "Dry-run reușit. Destinația validată: $deploy_path"
  exit 0
fi

log "Sincronizare artefacte statice..."
mkdir -p -- "$deploy_path"
rsync --archive --delete \
  --exclude='.well-known/' \
  --exclude='cgi-bin/' \
  "$dist_path/" "$deploy_path/"

log "Deploy finalizat: $deploy_path"
