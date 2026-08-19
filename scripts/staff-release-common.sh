#!/usr/bin/env bash

staff_log() { printf '[Arasya Staff] %s\n' "$1"; }
staff_fail() { printf '[Arasya Staff] EROARE: %s\n' "$1" >&2; exit 1; }
staff_require_command() { command -v "$1" >/dev/null 2>&1 || staff_fail "Comanda necesară lipsește: $1"; }
staff_validate_sha() { [[ "$1" =~ ^[0-9a-f]{40}$ ]] || staff_fail "Commit-ul trebuie să fie SHA lowercase cu 40 de caractere."; }

staff_resolve_path() {
  local target="$1" suffix="" resolved
  [[ "$target" == /* ]] || staff_fail "Destinația trebuie să fie o cale absolută."
  case "/$target/" in *"/../"*|*"/./"*) staff_fail "Destinația conține segmente nesigure." ;; esac
  while [[ ! -e "$target" ]]; do suffix="/${target##*/}$suffix"; target="${target%/*}"; [[ -n "$target" ]] || target="/"; done
  [[ -d "$target" ]] || staff_fail "Baza destinației nu este director."
  resolved="$(cd -- "$target" && pwd -P)"
  if [[ "$resolved" == "/" ]]; then printf '/%s\n' "${suffix#/}"; else printf '%s%s\n' "$resolved" "$suffix"; fi
}

staff_release_commit() {
  php -r '$r=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); $s=$r["commit"] ?? ""; if (!is_string($s) || preg_match("/^[0-9a-f]{40}$/", $s) !== 1) exit(2); echo $s;' "$1/release.json" \
    || staff_fail "release.json commit este invalid."
}

staff_read_pointer() {
  local pointer="$1" value
  [[ -f "$pointer" && ! -L "$pointer" ]] || staff_fail "Pointer-ul release nu este fișier regulat."
  value="$(tr -d '[:space:]' < "$pointer")"; staff_validate_sha "$value"; printf '%s\n' "$value"
}

staff_write_pointer() {
  local pointer="$1" value="$2" temporary="${1}.tmp.$$"
  staff_validate_sha "$value"; printf '%s\n' "$value" > "$temporary"; chmod 0644 "$temporary"; mv -f -- "$temporary" "$pointer"
}

staff_atomic_copy() {
  local source="$1" destination="$2" temporary="${2}.tmp.$$"
  cp -p -- "$source" "$temporary"; mv -f -- "$temporary" "$destination"
}

staff_activate_release() {
  local retained="$1" live="$2" storage="$3" commit="$4"
  mkdir -p -- "$live/assets"
  rsync --archive "$retained/assets/" "$live/assets/"
  local source name
  for source in "$retained"/* "$retained"/.[!.]*; do
    [[ -f "$source" && ! -L "$source" ]] || continue
    name="${source##*/}"
    case "$name" in index.html|release.json|SHA256SUMS) continue ;; esac
    staff_atomic_copy "$source" "$live/$name"
  done
  staff_atomic_copy "$retained/release.json" "$live/release.json"
  [[ "${ARASYA_TEST_FAIL_BEFORE_INDEX:-0}" != "1" ]] || staff_fail "Eșec simulat înainte de index."
  staff_atomic_copy "$retained/index.html" "$live/index.html"
  while IFS= read -r asset; do [[ -f "$live/${asset#/}" ]] || staff_fail "Asset activ lipsă: $asset"; done \
    < <(grep -oE '/assets/[A-Za-z0-9._-]+' "$live/index.html" | LC_ALL=C sort -u)
  local old=""; if [[ -f "$storage/active-release" ]]; then old="$(staff_read_pointer "$storage/active-release")"; fi
  if [[ -n "$old" && "$old" != "$commit" ]]; then staff_write_pointer "$storage/previous-release" "$old"; fi
  staff_write_pointer "$storage/active-release" "$commit"
}

staff_release_mtime() { if stat -c '%Y' "$1" >/dev/null 2>&1; then stat -c '%Y' "$1"; else stat -f '%m' "$1"; fi; }

staff_gc_releases() {
  local storage="$1" live="$2" retain="$3" dry_run="${4:-0}"
  local active previous="" listing="$storage/.gc-list.$$" directory name kept=0 protected=0
  [[ "$retain" =~ ^[0-9]+$ && "$retain" -ge 1 ]] || staff_fail "Retenția trebuie să fie pozitivă."
  active="$(staff_read_pointer "$storage/active-release")"; if [[ -f "$storage/previous-release" ]]; then previous="$(staff_read_pointer "$storage/previous-release")"; fi
  : > "$listing"
  for directory in "$storage"/*; do [[ -d "$directory" && ! -L "$directory" ]] || continue; name="${directory##*/}"; [[ "$name" =~ ^[0-9a-f]{40}$ ]] || continue; printf '%s %s\n' "$(staff_release_mtime "$directory")" "$name" >> "$listing"; done
  [[ -d "$storage/$active" ]] && protected=$((protected + 1))
  [[ -n "$previous" && "$previous" != "$active" && -d "$storage/$previous" ]] && protected=$((protected + 1))
  kept="$protected"
  while read -r _ name; do
    [[ -n "$name" ]] || continue
    if [[ "$name" == "$active" || "$name" == "$previous" ]]; then continue; fi
    if (( kept < retain )); then kept=$((kept + 1)); continue; fi
    directory="$storage/$name"; [[ "${directory%/*}" == "$storage" ]] || staff_fail "Curățare release nesigură."
    if [[ "$dry_run" == "1" ]]; then staff_log "Ar elimina release: $name"; else rm -rf -- "$directory"; fi
  done < <(sort -rn "$listing")
  rm -f -- "$listing"

  local asset relative retained found
  [[ -d "$live/assets" ]] || return 0
  while IFS= read -r -d '' asset; do
    relative="${asset#"$live/assets/"}"; found=0
    for retained in "$storage"/[0-9a-f]*; do [[ -f "$retained/assets/$relative" ]] && { found=1; break; }; done
    if [[ "$found" == "0" ]]; then if [[ "$dry_run" == "1" ]]; then staff_log "Ar elimina asset: $relative"; else rm -f -- "$asset"; fi; fi
  done < <(find "$live/assets" -type f -print0)
}
