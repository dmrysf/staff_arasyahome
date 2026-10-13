#!/usr/bin/env bash
# Publishes only the factory-simulation Dashboard into exactly one docroot named dashboard.sim.arasyahome.ro.
# No API, live release pointer, migration, database, cron, secret or other docroot is touched; Node is not needed.
# DRY_RUN=1 performs every check (package integrity, provenance, private runtime bytes, hosted manifest, artifact
# isolation, destination) and publishes nothing.
set -euo pipefail
[ "$#" -eq 4 ] || { echo 'Usage: package private-release-root private-manifest dashboard-sim-docroot' >&2; exit 2; }
for argument in "$1" "$2" "$3" "$4"; do [ ! -L "$argument" ] || { echo 'SIMULATION_LINK_BOUNDARY' >&2; exit 2; }; done
package=$(cd "$1" && pwd -P)
release=$(cd "$2" && pwd -P)
manifest=$3
dashboard=$(cd "$4" && pwd -P)
[ "$(basename "$dashboard")" = 'dashboard.sim.arasyahome.ro' ] || { echo 'SIMULATION_DOCROOT_BOUNDARY' >&2; exit 2; }
[ "$(basename "$(dirname "$release")")" = 'releases' ] && [ "$(basename "$(dirname "$(dirname "$release")")")" = 'arasya-simulation' ] || { echo 'SIMULATION_RELEASE_BOUNDARY' >&2; exit 2; }
sha=$(basename "$release")
[[ "$sha" =~ ^[a-f0-9]{40}$ ]] || exit 2
case "$release:$manifest:$dashboard:$package" in *'"'*|*'|'*) exit 2 ;; esac
case "$release/" in "$dashboard/"*) exit 2 ;; esac
case "$package/" in "$dashboard/"*) exit 2 ;; esac
case "$manifest" in "$dashboard/"*) echo 'SIMULATION_PRIVATE_MANIFEST_BOUNDARY' >&2; exit 2 ;; esac
[ -f "$manifest" ] && [ -f "$package/SHA256SUMS" ] && [ -f "$package/dashboard/index.html" ] && [ -f "$package/dashboard/.htaccess" ] && [ -f "$package/dashboard/release.json" ] || exit 2
[ -z "$(find "$package" "$release" "$dashboard" -type l -print -quit)" ] || { echo 'SIMULATION_LINK_BOUNDARY' >&2; exit 2; }
(cd "$package" && sha256sum --check --status SHA256SUMS)
php -r '$r=json_decode(file_get_contents($argv[1]),true); if(($r["environment"]??"")!=="simulation" || ($r["apiOrigin"]??"")!=="https://api.sim.arasyahome.ro" || ($r["dashboardOrigin"]??"")!=="https://dashboard.sim.arasyahome.ro" || ($r["sourceCommit"]??"")!==$argv[2] || !($r["workingTreeClean"]??false) || !($r["dashboardWorkingTreeClean"]??false) || !preg_match("/^[a-f0-9]{40}$/D",(string)($r["dashboardCommit"]??""))) exit(2);' "$package/release.json" "$sha"
php -r '$a=json_decode(file_get_contents($argv[1]),true); $b=json_decode(file_get_contents($argv[2]),true); if(($b["environment"]??"")!=="simulation" || ($b["sourceCommit"]??"")!==($a["sourceCommit"]??"x") || ($b["dashboardCommit"]??"")!==($a["dashboardCommit"]??"x") || ($b["apiOrigin"]??"")!=="https://api.sim.arasyahome.ro") exit(2);' "$package/release.json" "$package/dashboard/release.json"
# Verify private runtime bytes before executing any of them.
while read -r checksum file; do
  case "$file" in
    runtime/*) relative=${file#runtime/}; [ -f "$release/$relative" ] || exit 2; actual=$(sha256sum "$release/$relative"); [ "${actual%% *}" = "$checksum" ] || exit 2 ;;
  esac
done < "$package/SHA256SUMS"
php -d allow_url_fopen=0 "$release/simulation/verify-hosted-manifest.php" "$manifest"
# Artifact isolation (one gate among several): the simulation API and banner are compiled in, the CSP allows exactly the
# simulation API, and no production host appears anywhere in the published files.
grep -qF "connect-src 'self' https://api.sim.arasyahome.ro;" "$package/dashboard/.htaccess" || { echo 'SIMULATION_DASHBOARD_CSP' >&2; exit 2; }
grep -rqF 'https://api.sim.arasyahome.ro' "$package/dashboard/assets" || { echo 'SIMULATION_DASHBOARD_API' >&2; exit 2; }
grep -rqF 'data-arasya-simulation' "$package/dashboard/assets" || { echo 'SIMULATION_DASHBOARD_BANNER' >&2; exit 2; }
for host in 'https://api.arasyahome.ro' 'https://staff.arasyahome.ro' 'https://dashboard.arasyahome.ro' 'https://b2b.arasyahome.ro' '127.0.0.1'; do
  if grep -rqF "$host" "$package/dashboard"; then echo 'SIMULATION_DASHBOARD_PRODUCTION_HOST' >&2; exit 2; fi
done
if [ "${DRY_RUN:-0}" = 1 ]; then echo 'SIMULATION_DASHBOARD_DRY_RUN_OK'; exit; fi
mkdir -p "$dashboard/assets"
cp -R "$package/dashboard/assets/." "$dashboard/assets/"
for file in "$package/dashboard/"*; do
  [ -f "$file" ] || continue
  [ "$(basename "$file")" = index.html ] && continue
  cp "$file" "$dashboard/$(basename "$file").sim-new"
  mv "$dashboard/$(basename "$file").sim-new" "$dashboard/$(basename "$file")"
done
cp "$package/dashboard/.htaccess" "$dashboard/.htaccess.sim-new"
mv "$dashboard/.htaccess.sim-new" "$dashboard/.htaccess"
# The entry point goes last, so a browser never loads an index that refers to assets not yet published.
cp "$package/dashboard/index.html" "$dashboard/index.html.sim-new"
mv "$dashboard/index.html.sim-new" "$dashboard/index.html"
echo "SIMULATION_DASHBOARD_ACTIVATED $sha"
