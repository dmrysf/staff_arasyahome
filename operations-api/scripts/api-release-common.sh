#!/usr/bin/env bash

api_log() { printf '[Arasya Operations API] %s\n' "$1"; }
api_fail() { printf '[Arasya Operations API] ERROR: %s\n' "$1" >&2; exit 1; }
api_require_command() { command -v "$1" >/dev/null 2>&1 || api_fail "Required command is missing: $1"; }

api_resolve_path() {
  local target="$1" suffix="" resolved
  [[ "$target" == /* ]] || api_fail "Deployment destination must be an absolute path."
  case "/$target/" in *"/../"*|*"/./"*) api_fail "Deployment destination contains unsafe path segments." ;; esac
  while [[ ! -e "$target" ]]; do
    suffix="/${target##*/}$suffix"; target="${target%/*}"; [[ -n "$target" ]] || target="/"
  done
  [[ -d "$target" ]] || api_fail "Deployment destination base is not a directory."
  resolved="$(cd -- "$target" && pwd -P)"
  if [[ "$resolved" == "/" ]]; then printf '/%s\n' "${suffix#/}"; else printf '%s%s\n' "$resolved" "$suffix"; fi
}

api_validate_sha() { [[ "$1" =~ ^[0-9a-f]{40}$ ]] || api_fail "Source commit must be exactly 40 lowercase hexadecimal characters."; }

api_read_pointer() {
  local pointer="$1" value
  [[ -f "$pointer" && ! -L "$pointer" ]] || api_fail "Release pointer is not a regular file."
  [[ "$(wc -c < "$pointer" | tr -d ' ')" -le 128 ]] || api_fail "Release pointer is too large."
  value="$(tr -d '[:space:]' < "$pointer")"; api_validate_sha "$value"; printf '%s\n' "$value"
}

api_write_pointer() {
  local pointer="$1" value="$2" temporary="${1}.tmp.$$"
  api_validate_sha "$value"; printf '%s\n' "$value" > "$temporary"; chmod 0644 "$temporary"; mv -f -- "$temporary" "$pointer"
}

api_release_sha() {
  local release_root="$1"
  php -r '$r=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); $s=$r["sourceCommit"] ?? ""; if (!is_string($s) || preg_match("/^[0-9a-f]{40}$/", $s) !== 1) { exit(2); } echo $s;' "$release_root/release.json" \
    || api_fail "release.json sourceCommit is invalid."
}

api_verify_checksum_manifest() {
  local release_root="$1" line checksum relative
  [[ -f "$release_root/SHA256SUMS" && ! -L "$release_root/SHA256SUMS" ]] || api_fail "SHA256SUMS is missing."
  while IFS= read -r line || [[ -n "$line" ]]; do
    checksum="${line%% *}"; relative="${line#*  }"
    [[ "$checksum" =~ ^[0-9a-f]{64}$ && "$relative" == ./* ]] || api_fail "SHA256SUMS contains an invalid entry."
    case "/${relative#./}/" in *"/../"*|*"/./"*) api_fail "SHA256SUMS contains an unsafe path." ;; esac
  done < "$release_root/SHA256SUMS"
  (cd -- "$release_root" && sha256sum -c SHA256SUMS >/dev/null) || api_fail "Release checksum verification failed."
}

api_validate_packaged_release() {
  local release_root="$1"
  api_verify_checksum_manifest "$release_root"
  /bin/bash "$release_root/scripts/validate-release.sh" "$release_root" >/dev/null || api_fail "Release structure validation failed."
  api_release_sha "$release_root" >/dev/null
}

api_atomic_copy_file() {
  local source="$1" destination="$2" temporary="${2}.tmp.$$"
  [[ -f "$source" && ! -L "$source" ]] || api_fail "Atomic source file is unavailable: ${source##*/}"
  cp -p -- "$source" "$temporary"; mv -f -- "$temporary" "$destination"
}

api_release_mtime() { if stat -c '%Y' "$1" >/dev/null 2>&1; then stat -c '%Y' "$1"; else stat -f '%m' "$1"; fi; }

api_gc_releases() {
  local releases_root="$1" active="$2" previous="$3" retain="$4" dry_run="${5:-0}"
  local listing="$releases_root/.gc-list.$$" directory name kept=0 protected=0
  [[ "$retain" =~ ^[0-9]+$ && "$retain" -ge 1 ]] || api_fail "Release retention must be a positive integer."
  : > "$listing"
  for directory in "$releases_root"/*; do
    [[ -d "$directory" && ! -L "$directory" ]] || continue
    name="${directory##*/}"; [[ "$name" =~ ^[0-9a-f]{40}$ ]] || continue
    printf '%s %s\n' "$(api_release_mtime "$directory")" "$name" >> "$listing"
  done
  [[ -d "$releases_root/$active" ]] && protected=$((protected + 1))
  [[ -n "$previous" && "$previous" != "$active" && -d "$releases_root/$previous" ]] && protected=$((protected + 1))
  kept="$protected"
  # cPanel deploy shells have no /dev/fd, so process substitution is unavailable: use files.
  sort -rn "$listing" > "$listing.sorted"
  while read -r _ name; do
    [[ -n "$name" ]] || continue
    if [[ "$name" == "$active" || "$name" == "$previous" ]]; then continue; fi
    if (( kept < retain )); then kept=$((kept + 1)); continue; fi
    directory="$releases_root/$name"
    [[ "${directory%/*}" == "$releases_root" && "$name" =~ ^[0-9a-f]{40}$ ]] || api_fail "Refusing unsafe release cleanup."
    if [[ "$dry_run" == "1" ]]; then api_log "Would remove retained release: $name"; else rm -rf -- "$directory"; fi
  done < "$listing.sorted"
  rm -f -- "$listing" "$listing.sorted"
}
