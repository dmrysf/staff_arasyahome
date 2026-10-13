#!/usr/bin/env bash
# Publishes only explicitly named simulation docroots. No live release pointer, migrations, secrets or DB reset.
set -euo pipefail
[ "$#" -eq 5 ] || { echo 'Usage: package private-release-root private-manifest sim-docroot api-sim-docroot' >&2; exit 2; }
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
(cd "$package" && sha256sum --check --status SHA256SUMS)
php -r '$r=json_decode(file_get_contents($argv[1]),true); if(($r["environment"]??"")!=="simulation" || ($r["apiOrigin"]??"")!=="https://api.sim.arasyahome.ro" || ($r["sourceCommit"]??"")!==$argv[2] || !($r["workingTreeClean"]??false)) exit(2);' "$package/release.json" "$sha"
# Verify private runtime bytes before executing any of them.
while read -r checksum file; do
  case "$file" in
    runtime/*) relative=${file#runtime/}; [ -f "$release/$relative" ] || exit 2; actual=$(sha256sum "$release/$relative"); [ "${actual%% *}" = "$checksum" ] || exit 2 ;;
  esac
done < "$package/SHA256SUMS"
php -r '$m=json_decode(file_get_contents($argv[1]),true); if(($m["environment"]["ARASYA_APP_ENV"]??"")!=="simulation" || ($m["environment"]["ARASYA_ALLOWED_ORIGINS"]??"")!=="https://sim.arasyahome.ro") exit(2);' "$manifest"
php -d allow_url_fopen=0 "$release/simulation/check-environment.php" "$manifest"
if [ "${DRY_RUN:-0}" = 1 ]; then echo 'SIMULATION_DEPLOY_DRY_RUN_OK'; exit; fi
# Runtime must have been uploaded and checksummed under the PRIVATE release root first.
[ -f "$release/operations-api/bootstrap.php" ] && [ -f "$release/simulation/check-environment.php" ] || exit 2
cp -R "$package/staff/assets/." "$staff/assets/" 2>/dev/null || { mkdir -p "$staff/assets"; cp -R "$package/staff/assets/." "$staff/assets/"; }
for file in "$package/staff/"*; do
  [ -f "$file" ] || continue
  [ "$(basename "$file")" = index.html ] && continue
  cp "$file" "$staff/$(basename "$file").sim-new"
  mv "$staff/$(basename "$file").sim-new" "$staff/$(basename "$file")"
done
cp "$package/staff/.htaccess" "$staff/.htaccess.sim-new"
mv "$staff/.htaccess.sim-new" "$staff/.htaccess"
sed -e "s|__RELEASE_ROOT__|$release|g" -e "s|__PRIVATE_MANIFEST__|$manifest|g" "$package/api/.htaccess.template" > "$api/.htaccess.sim-new"
cp "$package/api/.user.ini" "$api/.user.ini.sim-new"
cp "$package/api/index.php" "$api/index.php.sim-new"
mv "$api/.user.ini.sim-new" "$api/.user.ini"
mv "$api/index.php.sim-new" "$api/index.php"
mv "$api/.htaccess.sim-new" "$api/.htaccess"
cp "$package/staff/index.html" "$staff/index.html.sim-new"
mv "$staff/index.html.sim-new" "$staff/index.html"
echo "SIMULATION_ACTIVATED $sha"
