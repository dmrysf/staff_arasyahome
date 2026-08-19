#!/usr/bin/env bash
set -Eeuo pipefail

root="${1:-}"
[[ -n "$root" && -d "$root" ]] || { printf 'Release directory is required.\n' >&2; exit 1; }
command -v sha256sum >/dev/null 2>&1 || { printf 'sha256sum is required.\n' >&2; exit 1; }
root="$(cd -- "$root" && pwd -P)"; temporary="$root/.SHA256SUMS.tmp.$$"
trap 'rm -f -- "$temporary"' EXIT
(
  cd -- "$root"
  find . -type f ! -name 'SHA256SUMS' ! -name '.SHA256SUMS.tmp.*' ! -path './.git/*' -print0 \
    | LC_ALL=C sort -z | xargs -0 sha256sum
) > "$temporary"
[[ -s "$temporary" ]] || { printf 'Checksum manifest would be empty.\n' >&2; exit 1; }
mv -f -- "$temporary" "$root/SHA256SUMS"; trap - EXIT
