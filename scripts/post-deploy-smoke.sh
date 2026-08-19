#!/usr/bin/env bash
set -Eeuo pipefail

fail() { printf '[Arasya smoke] FAIL: %s\n' "$1" >&2; exit 1; }
pass() { printf '[Arasya smoke] OK: %s\n' "$1"; }
for command in curl php grep sed mktemp; do command -v "$command" >/dev/null 2>&1 || fail "Required command is missing: $command"; done

api="${API_BASE_URL:-}"; staff="${STAFF_BASE_URL:-}"; expected_version="${EXPECTED_API_VERSION:-}"; expected_commit="${EXPECTED_SOURCE_COMMIT:-}"
[[ "$api" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?/?$ ]] || fail "API_BASE_URL must be an exact HTTPS origin."
[[ "$staff" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?/?$ ]] || fail "STAFF_BASE_URL must be an exact HTTPS origin."
[[ "$expected_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "EXPECTED_API_VERSION is invalid."
[[ "$expected_commit" =~ ^[0-9a-f]{40}$ ]] || fail "EXPECTED_SOURCE_COMMIT is invalid."
api="${api%/}"; staff="${staff%/}"

workspace="$(mktemp -d "${TMPDIR:-/tmp}/arasya-smoke.XXXXXX")"
[[ "$workspace" == *'/arasya-smoke.'* ]] || fail "Temporary workspace is unsafe."
cleanup() { rm -rf -- "$workspace"; }
trap cleanup EXIT

request() {
  local name="$1" url="$2"; shift 2
  curl --silent --show-error --location --max-time 20 --dump-header "$workspace/$name.headers" --output "$workspace/$name.body" --write-out '%{http_code}' "$@" "$url"
}

status="$(request health "$api/health")"; [[ "$status" == "200" ]] || fail "API health returned HTTP $status."
php -r '$r=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); if (($r["status"] ?? null) !== "ok" || ($r["version"] ?? null) !== $argv[2]) exit(2);' "$workspace/health.body" "$expected_version" \
  || fail "API health payload/version is unexpected."
pass "API health $expected_version"

status="$(request cors "$api/production/workflow" --request OPTIONS \
  --header "Origin: $staff" --header 'Access-Control-Request-Method: GET' --header 'Access-Control-Request-Headers: if-none-match,x-request-id')"
[[ "$status" == "204" ]] || fail "Conditional CORS preflight returned HTTP $status."
grep -Eqi '^Access-Control-Allow-Headers:.*If-None-Match' "$workspace/cors.headers" || fail "CORS does not allow If-None-Match."
pass "conditional CORS"

status="$(request cors-denied "$api/production/workflow" --request OPTIONS \
  --header "Origin: $staff" --header 'Access-Control-Request-Method: GET' --header 'Access-Control-Request-Headers: if-none-match,x-arbitrary-secret-header')"
[[ "$status" == "403" ]] || fail "Unknown CORS header was not denied."
pass "unknown CORS header denial"

for path in RuntimeLocator.php src/ database/ config/ release.json; do
  key="private-$(printf '%s' "$path" | tr '/.' '--')"; status="$(request "$key" "$api/$path")"
  [[ "$status" != "200" ]] || fail "Private API path is public: /$path"
done
pass "API private paths inaccessible"

status="$(request staff-index "$staff/")"; [[ "$status" == "200" ]] || fail "Staff entrypoint returned HTTP $status."
for header in strict-transport-security x-content-type-options x-frame-options referrer-policy permissions-policy content-security-policy; do
  grep -Eqi "^${header}:" "$workspace/staff-index.headers" || fail "Staff header missing: $header"
done
grep -Eqi '^Cache-Control:.*no-store' "$workspace/staff-index.headers" || fail "Staff index cache policy is not no-store."
pass "Staff entrypoint security and cache headers"

status="$(request staff-release "$staff/release.json")"; [[ "$status" == "200" ]] || fail "Staff release metadata returned HTTP $status."
release_state="$(php -r '$r=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); if (($r["commit"] ?? null) !== $argv[2]) exit(2); echo (($r["preview"] ?? false) === true ? "preview" : "production");' "$workspace/staff-release.body" "$expected_commit")" \
  || fail "Staff release provenance is unexpected."
grep -Eqi '^Cache-Control:.*no-store' "$workspace/staff-release.headers" || fail "Staff release metadata is cacheable."
pass "Staff source commit $expected_commit"

csp="$(grep -Ei '^Content-Security-Policy:' "$workspace/staff-index.headers" | tr -d '\r' | head -1)"
if [[ "$release_state" == "preview" ]]; then
  [[ "$csp" != *"$api"* ]] || fail "Preview CSP unexpectedly allows the production API."
else
  [[ "$csp" == *"$api"* ]] || fail "Production CSP does not allow the configured API origin."
fi

assets="$(grep -oE '/assets/[A-Za-z0-9._-]+' "$workspace/staff-index.body" | LC_ALL=C sort -u)"
[[ -n "$assets" ]] || fail "Staff index references no hashed assets."
while IFS= read -r asset; do
  key="asset-$(printf '%s' "$asset" | tr '/.' '--')"; status="$(request "$key" "$staff$asset")"
  [[ "$status" == "200" ]] || fail "Staff asset returned HTTP $status: $asset"
  grep -Eqi '^Cache-Control:.*max-age=31536000.*immutable' "$workspace/$key.headers" || fail "Hashed asset is not immutable: $asset"
done <<< "$assets"
pass "Staff referenced assets and immutable caching"

status="$(request sw "$staff/sw.js")"; [[ "$status" == "200" ]] || fail "Staff service worker returned HTTP $status."
grep -Eqi '^Cache-Control:.*no-cache.*must-revalidate' "$workspace/sw.headers" || fail "Service worker cache policy is unsafe."
pass "Staff service worker revalidation"
