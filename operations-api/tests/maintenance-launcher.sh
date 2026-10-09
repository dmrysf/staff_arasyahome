#!/usr/bin/env bash
set -Eeuo pipefail

# The nightly cron runs bin/maintenance-active.sh with a minimal PATH. On cPanel `php` there is the CGI/FastCGI
# binary, which has no $argv, so the launcher must use the PHP CLI by absolute path and maintenance.php must refuse
# any other SAPI clearly. No database is needed: a recording stub stands in for the PHP CLI.
workspace="$(mktemp -d "${TMPDIR:-/tmp}/arasya-maintenance-test.XXXXXX")"
[[ "$workspace" == *"/arasya-maintenance-test."* ]] || exit 1
cleanup() { rm -rf -- "$workspace"; }
trap cleanup EXIT
fail() { printf 'FAIL maintenance launcher test: %s\n' "$1" >&2; exit 1; }

launcher_source="operations-api/scripts/maintenance-active.sh"
grep -q 'php_cli="${ARASYA_PHP_CLI:-/usr/local/bin/php}"' "$launcher_source" || fail 'the default PHP CLI is not /usr/local/bin/php'
if grep -nE '(^|[^_"$])exec php([[:space:]]|$)' "$launcher_source"; then fail 'the launcher still executes `php` from PATH'; fi

sha="$(printf 'e%.0s' {1..40})"
api_root="$workspace/arasya-operations-api"
mkdir -p "$api_root/bin" "$api_root/releases/$sha/bin" "$workspace/no-php"
cp "$launcher_source" "$api_root/bin/maintenance-active.sh"
printf '<?php\n' > "$api_root/releases/$sha/bin/maintenance.php"
printf '%s\n' "$sha" > "$api_root/active-release"
record="$workspace/record"
cat > "$workspace/php-cli" <<STUB
#!/bin/bash
printf '%s\n' "\$@" > "$record"
STUB
chmod +x "$workspace/php-cli"
# A `php` earlier in PATH must never be used.
cat > "$workspace/no-php/php" <<STUB
#!/bin/bash
printf 'path-php\n' > "$record"
exit 97
STUB
chmod +x "$workspace/no-php/php"

run() { env -i HOME="$workspace" PATH="$workspace/no-php:/usr/bin:/bin" "$@" /bin/bash "$api_root/bin/maintenance-active.sh" --dry-run; }

rm -f "$record"
run ARASYA_PHP_CLI="$workspace/php-cli" >/dev/null || fail 'the launcher failed with a valid PHP CLI'
expected="$(cd "$api_root/releases/$sha" && pwd -P)/bin/maintenance.php"
[[ "$(sed -n 1p "$record")" == "$expected" ]] || fail "the active release was not resolved: $(sed -n 1p "$record")"
[[ "$(sed -n 2p "$record")" == '--dry-run' && "$(wc -l < "$record" | tr -d ' ')" == 2 ]] || fail '--dry-run was not forwarded exactly'

rm -f "$record"
if output="$(run ARASYA_PHP_CLI="$workspace/missing-php" 2>&1)"; then fail 'a missing PHP CLI was accepted'; fi
[[ "$output" == *'PHP CLI'*'is unavailable'* && ! -e "$record" ]] || fail "a missing PHP CLI did not fail clearly: $output"
if output="$(run ARASYA_PHP_CLI=php 2>&1)"; then fail 'a relative PHP CLI was accepted'; fi
[[ ! -e "$record" ]] || fail 'a relative PHP CLI ran something from PATH'

printf 'not-a-sha\n' > "$api_root/active-release"
if output="$(run ARASYA_PHP_CLI="$workspace/php-cli" 2>&1)"; then fail 'an invalid release pointer was accepted'; fi
[[ "$output" == *'pointer is invalid'* && ! -e "$record" ]] || fail "an invalid pointer did not fail clearly: $output"

# The Trendyol intake launcher resolves the active release the same way and runs only bin/sync-trendyol.php.
trendyol_source="operations-api/scripts/trendyol-intake-active.sh"
grep -q 'php_cli="${ARASYA_PHP_CLI:-/usr/local/bin/php}"' "$trendyol_source" || fail 'the Trendyol launcher default PHP CLI is not /usr/local/bin/php'
if grep -nE '(^|[^_"$])exec php([[:space:]]|$)' "$trendyol_source"; then fail 'the Trendyol launcher executes `php` from PATH'; fi
cp "$trendyol_source" "$api_root/bin/trendyol-intake-active.sh"
printf '%s\n' "$sha" > "$api_root/active-release"
printf '<?php\n' > "$api_root/releases/$sha/bin/sync-trendyol.php"
rm -f "$record"
env -i HOME="$workspace" PATH="$workspace/no-php:/usr/bin:/bin" ARASYA_PHP_CLI="$workspace/php-cli" /bin/bash "$api_root/bin/trendyol-intake-active.sh" >/dev/null || fail 'the Trendyol launcher failed with a valid PHP CLI'
[[ "$(sed -n 1p "$record")" == "$(cd "$api_root/releases/$sha" && pwd -P)/bin/sync-trendyol.php" && "$(wc -l < "$record" | tr -d ' ')" == 1 ]] || fail "the Trendyol launcher did not run the active sync command: $(cat "$record")"

# maintenance.php itself: invalid arguments are rejected before any database access, and a non-CLI SAPI refuses
# without a PHP fatal error.
php_bin="$(command -v php)"
set +e; output="$("$php_bin" operations-api/bin/maintenance.php --delete-everything 2>&1)"; status=$?; set -e
[[ "$status" == 2 && "$output" == *'Usage: php bin/maintenance.php [--dry-run]'* ]] || fail "invalid arguments were not rejected ($status): $output"
cgi_bin="$(command -v php-cgi || true)"
if [[ -n "$cgi_bin" ]]; then
  set +e; output="$("$cgi_bin" -f operations-api/bin/maintenance.php -- --dry-run 2>&1)"; status=$?; set -e
  [[ "$status" != 0 && "$output" == *'requires the PHP CLI (current SAPI: cgi-fcgi)'* ]] || fail "the CGI SAPI was not refused clearly ($status): $output"
  [[ "$output" != *'Fatal error'* && "$output" != *'Undefined variable'* ]] || fail "the CGI SAPI produced a PHP error: $output"
else
  grep -q "PHP_SAPI !== 'cli'" operations-api/bin/maintenance.php || fail 'maintenance.php has no CLI guard'
  printf 'NOTE php-cgi is not installed; the CLI guard was checked statically.\n'
fi

printf 'PASS maintenance launcher uses the absolute PHP CLI, ignores PATH, resolves the active release, forwards --dry-run and fails clearly.\n'
