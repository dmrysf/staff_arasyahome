#!/usr/bin/env bash
set -Eeuo pipefail

target="${1:?target required}"; source_commit="${2:?source commit required}"
[[ "$source_commit" =~ ^[0-9a-f]{40}$ ]] || { printf 'Invalid fixture source commit.\n' >&2; exit 1; }
mkdir -p "$target/config" "$target/scripts"
cp operations-api/bootstrap.php operations-api/.htaccess operations-api/.cpanel.yml operations-api/README.md "$target/"
cp -R operations-api/public operations-api/src operations-api/database operations-api/bin "$target/"
cp operations-api/config/config.example.php operations-api/config/config.production.example.php operations-api/config/secrets.example.json "$target/config/"
cp operations-api/scripts/*.sh "$target/scripts/"
chmod +x "$target/scripts/"*.sh
printf '{"sourceCommit":"%s","builtAt":"2026-08-19T00:00:00Z","version":"2.26.1"}\n' "$source_commit" > "$target/release.json"
/bin/bash "$target/scripts/generate-sha256s.sh" "$target"
/bin/bash "$target/scripts/validate-release.sh" "$target" >/dev/null
