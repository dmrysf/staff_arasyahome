#!/usr/bin/env bash
set -Eeuo pipefail

repository_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
source "$repository_root/scripts/staff-release-common.sh"
for command in rsync sha256sum php mv cp mkdir find sort stat tr grep rm xargs; do staff_require_command "$command"; done
gc_dry_run="${STAFF_RELEASE_GC_DRY_RUN:-0}"
[[ "$gc_dry_run" == "0" || "$gc_dry_run" == "1" ]] || staff_fail "STAFF_RELEASE_GC_DRY_RUN trebuie să fie 0 sau 1."
release_retention="${STAFF_RELEASE_RETENTION:-5}"
[[ "$release_retention" =~ ^[0-9]+$ && "$release_retention" -ge 1 ]] || staff_fail "STAFF_RELEASE_RETENTION trebuie să fie pozitivă."

dist_path="$repository_root/dist"
deploy_path="$(staff_resolve_path "${STAFF_DEPLOY_PATH:-$HOME/staff.arasyahome.ro}")"
storage_path="$(staff_resolve_path "${STAFF_RELEASE_STORAGE_PATH:-$HOME/arasya-staff-releases}")"
home_path="$(staff_resolve_path "$HOME")"
api_path="$(staff_resolve_path "$HOME/arasya-operations-api")"
api_public_path="$(staff_resolve_path "$HOME/api.arasyahome.ro")"
config_path="$(staff_resolve_path "$HOME/arasya-config")"

[[ -f "$repository_root/SHA256SUMS" ]] || staff_fail "Lipsește manifestul SHA256 al pachetului deploy."
(cd -- "$repository_root" && sha256sum -c SHA256SUMS >/dev/null) || staff_fail "Integritatea pachetului deploy a eșuat."
/bin/bash "$repository_root/scripts/validate-staff-release.sh" "$dist_path" >/dev/null
commit="$(staff_release_commit "$dist_path")"; staff_validate_sha "$commit"

for target in "$deploy_path" "$storage_path"; do
  [[ "$target" != "/" && "$target" != "$home_path" && "$target" != "$repository_root" && "$target" != "$dist_path" && "$target" != "$api_path" && "$target" != "$api_public_path" && "$target" != "$config_path" ]] \
    || staff_fail "Destinația de deploy este interzisă."
  case "$target" in "$repository_root"/*|"$api_path"/*|"$api_public_path"/*|"$config_path"/*) staff_fail "Destinația este într-o rădăcină interzisă." ;; esac
done
[[ "$deploy_path" != "$storage_path" ]] || staff_fail "Document root și stocarea release trebuie separate."
case "$storage_path/" in "$deploy_path/"*) staff_fail "Stocarea release nu poate fi în document root." ;; esac
case "$deploy_path/" in "$storage_path/"*) staff_fail "Document root nu poate fi în stocarea release." ;; esac

if [[ "${DRY_RUN:-0}" == "1" ]]; then staff_log "Dry-run valid pentru commit $commit."; exit 0; fi

staging="$storage_path/.staging-$commit-$$"; retained="$storage_path/$commit"
cleanup() { [[ -d "$staging" ]] && rm -rf -- "$staging"; }
trap cleanup EXIT
mkdir -p -- "$storage_path" "$deploy_path"
resolved_storage="$(cd -- "$storage_path" && pwd -P)"
[[ "${retained%/*}" == "$resolved_storage" ]] || staff_fail "Release-ul a ieșit din stocarea controlată."

if [[ -d "$retained" ]]; then
  /bin/bash "$repository_root/scripts/validate-staff-release.sh" "$retained" >/dev/null
else
  mkdir -- "$staging"
  rsync --archive --delete "$dist_path/" "$staging/"
  /bin/bash "$repository_root/scripts/validate-staff-release.sh" "$staging" >/dev/null
  [[ "$(staff_release_commit "$staging")" == "$commit" ]] || staff_fail "Proveniența release-ului staged s-a schimbat."
  mv -- "$staging" "$retained"
fi

if [[ ! -f "$storage_path/active-release" && -f "$deploy_path/release.json" && -f "$deploy_path/index.html" ]]; then
  legacy_commit="$(staff_release_commit "$deploy_path" 2>/dev/null || true)"
  if [[ "$legacy_commit" =~ ^[0-9a-f]{40}$ && ! -d "$storage_path/$legacy_commit" ]]; then
    legacy_staging="$storage_path/.staging-legacy-$legacy_commit-$$"
    mkdir -- "$legacy_staging"
    rsync --archive --exclude='.well-known/' --exclude='cgi-bin/' "$deploy_path/" "$legacy_staging/"
    /bin/bash "$repository_root/scripts/generate-sha256s.sh" "$legacy_staging"
    if /bin/bash "$repository_root/scripts/validate-staff-release.sh" "$legacy_staging" >/dev/null 2>&1; then
      mv -- "$legacy_staging" "$storage_path/$legacy_commit"
      staff_log "Release-ul Staff legacy a fost reținut: $legacy_commit"
    else
      rm -rf -- "$legacy_staging"
      staff_log "Release-ul Staff legacy nu a putut fi reținut; aplicația live rămâne neatinsă până la activare."
    fi
  fi
fi

staff_activate_release "$retained" "$deploy_path" "$storage_path" "$commit"
/bin/bash "$repository_root/scripts/validate-staff-release.sh" "$retained" >/dev/null
staff_gc_releases "$storage_path" "$deploy_path" "$release_retention" "$gc_dry_run"
trap - EXIT
staff_log "Activat commit Staff: $commit"
