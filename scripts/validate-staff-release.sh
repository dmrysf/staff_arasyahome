#!/usr/bin/env bash
set -Eeuo pipefail

fail() { printf '[Arasya Staff] RELEASE INVALID: %s\n' "$1" >&2; exit 1; }
root="${1:-}"; [[ -n "$root" && -d "$root" ]] || fail "Release directory is missing."
for command in find sha256sum php grep sort; do command -v "$command" >/dev/null 2>&1 || fail "$command is required."; done
root="$(cd -- "$root" && pwd -P)"
[[ -z "$(find "$root" -type l -print -quit)" ]] || fail "Symlinks are forbidden in Staff releases."
for file in index.html .htaccess manifest.webmanifest sw.js release.json SHA256SUMS; do [[ -f "$root/$file" ]] || fail "Required file is missing: $file"; done
[[ -d "$root/assets" ]] || fail "assets directory is missing."
(cd -- "$root" && sha256sum -c SHA256SUMS >/dev/null) || fail "SHA256SUMS verification failed."
commit="$(php -r '$r=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); $s=$r["commit"] ?? ""; if (!is_string($s) || preg_match("/^[0-9a-f]{40}$/", $s) !== 1 || !in_array($r["version"] ?? null, ["2.3.0", "2.3.1", "2.3.2", "2.3.3", "2.4.0"], true) || !is_bool($r["preview"] ?? null)) exit(2); echo $s;' "$root/release.json")" \
  || fail "release.json commit is invalid."
[[ "$commit" =~ ^[0-9a-f]{40}$ ]] || fail "release.json commit is invalid."
grep -q '/assets/' "$root/index.html" || fail "index.html does not reference Vite assets."
# Here-strings instead of process substitution: cPanel deploy shells have no /dev/fd.
referenced_assets="$(grep -oE '/assets/[A-Za-z0-9._-]+' "$root/index.html" | LC_ALL=C sort -u)"
[[ -n "$referenced_assets" ]] || fail "index.html does not reference Vite assets."
while IFS= read -r asset; do [[ -f "$root/${asset#/}" ]] || fail "Referenced asset is missing: $asset"; done <<< "$referenced_assets"
grep -Fq 'Content-Security-Policy' "$root/.htaccess" || fail "Staff CSP is missing."
grep -Fq "frame-ancestors 'none'" "$root/.htaccess" || fail "Staff frame protection is missing."
! grep -Fq '__ARASYA_CONNECT_SRC__' "$root/.htaccess" || fail "Staff CSP placeholder remains unresolved."
printf '[Arasya Staff] Release validation passed: %s\n' "$commit"
