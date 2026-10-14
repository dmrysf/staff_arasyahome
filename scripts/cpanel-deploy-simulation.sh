#!/usr/bin/env bash
# Publishes only explicitly named simulation docroots (Staff + API). No live release pointer, migrations, secrets or
# DB reset. The simulation Dashboard has its own publisher (deploy-simulation-dashboard.sh). DRY_RUN=1 checks only.
set -euo pipefail
[ "$#" -eq 5 ] || { echo 'Usage: package private-release-root private-manifest sim-docroot api-sim-docroot' >&2; exit 2; }
# Symbolic links could redirect a write into a live tree: refuse them as arguments and inside the package.
for argument in "$1" "$2" "$3" "$4" "$5"; do [ ! -L "$argument" ] || { echo 'SIMULATION_LINK_BOUNDARY' >&2; exit 2; }; done
package=$(cd "$1" && pwd -P)
release=$(cd "$2" && pwd -P)
manifest=$3
staff=$(cd "$4" && pwd -P)
api=$(cd "$5" && pwd -P)
[ "$(basename "$staff")" = 'sim.arasyahome.ro' ] && [ "$(basename "$api")" = 'api.sim.arasyahome.ro' ] || { echo 'SIMULATION_DOCROOT_BOUNDARY' >&2; exit 2; }
[ "$staff" != "$api" ] || exit 2
[ "$(basename "$(dirname "$release")")" = 'releases' ] && [ "$(basename "$(dirname "$(dirname "$release")")")" = 'arasya-simulation' ] || { echo 'SIMULATION_RELEASE_BOUNDARY' >&2; exit 2; }
sha=$(basename "$release")
[[ "$sha" =~ ^[a-f0-9]{40}$ ]] || exit 2
case "$release:$manifest:$staff:$api" in *'"'*|*'|'*) exit 2 ;; esac
case "$release/" in "$staff/"*|"$api/"*) exit 2 ;; esac
[ -f "$manifest" ] && [ -f "$package/SHA256SUMS" ] || exit 2
case "$manifest" in "$staff/"*|"$api/"*) echo SIMULATION_PRIVATE_MANIFEST_BOUNDARY >&2; exit 2 ;; esac
case "$package/" in "$staff/"*|"$api/"*) exit 2 ;; esac
[ -z "$(find "$package" "$release" "$staff" "$api" -type l -print -quit)" ] || { echo 'SIMULATION_LINK_BOUNDARY' >&2; exit 2; }
(cd "$package" && sha256sum --check --status SHA256SUMS)
php -r '$r=json_decode(file_get_contents($argv[1]),true); if(($r["environment"]??"")!=="simulation" || ($r["apiOrigin"]??"")!=="https://api.sim.arasyahome.ro" || ($r["staffOrigin"]??"")!=="https://sim.arasyahome.ro" || ($r["dashboardOrigin"]??"")!=="https://dashboard.sim.arasyahome.ro" || ($r["sourceCommit"]??"")!==$argv[2] || !($r["workingTreeClean"]??false)) exit(2);' "$package/release.json" "$sha"
# Verify private runtime bytes before executing any of them.
while read -r checksum file; do
  case "$file" in
    runtime/*) relative=${file#runtime/}; [ -f "$release/$relative" ] || exit 2; actual=$(sha256sum "$release/$relative"); [ "${actual%% *}" = "$checksum" ] || exit 2 ;;
  esac
done < "$package/SHA256SUMS"
# The hosted manifest (schema 2): exact Staff + Dashboard origins, one simulation DB identity, private 0600/0700 paths.
php -d allow_url_fopen=0 "$release/simulation/verify-hosted-manifest.php" "$manifest"
php -d allow_url_fopen=0 "$release/simulation/check-environment.php" "$manifest" --hosted
if [ "${DRY_RUN:-0}" = 1 ]; then echo 'SIMULATION_DEPLOY_DRY_RUN_OK'; exit; fi
# Runtime must have been uploaded and checksummed under the PRIVATE release root first.
[ -f "$release/operations-api/bootstrap.php" ] && [ -f "$release/simulation/check-environment.php" ] || exit 2
# Published files get explicit web-readable modes: an upload extracted under umask 077 (0600/0700) would otherwise be
# copied as-is, and the web server could read neither the files nor .htaccess (HTTP 403, no CSP).
publish() { cp "$1" "$2.sim-new"; chmod 0644 "$2.sim-new"; mv "$2.sim-new" "$2"; }
mkdir -p "$staff/assets"
cp -R "$package/staff/assets/." "$staff/assets/"
find "$staff/assets" -type d -exec chmod 0755 {} +
find "$staff/assets" -type f -exec chmod 0644 {} +
for file in "$package/staff/"*; do
  [ -f "$file" ] || continue
  [ "$(basename "$file")" = index.html ] && continue
  publish "$file" "$staff/$(basename "$file")"
done
publish "$package/staff/.htaccess" "$staff/.htaccess"
sed -e "s|__RELEASE_ROOT__|$release|g" -e "s|__PRIVATE_MANIFEST__|$manifest|g" "$package/api/.htaccess.template" > "$api/.htaccess.sim-new"
chmod 0644 "$api/.htaccess.sim-new"
publish "$package/api/.user.ini" "$api/.user.ini"
publish "$package/api/index.php" "$api/index.php"
mv "$api/.htaccess.sim-new" "$api/.htaccess"
publish "$package/staff/index.html" "$staff/index.html"
echo "SIMULATION_ACTIVATED $sha"
