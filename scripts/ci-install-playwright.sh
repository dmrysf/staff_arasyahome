#!/usr/bin/env bash
# CI only (GitHub-hosted Ubuntu runners): installs Playwright Chromium and its system packages with bounded waits.
# `playwright install --with-deps` runs apt-get update/install; without limits an unresponsive Ubuntu mirror stalls the
# job until the 6 h GitHub limit (2026-10-22: azure.archive.ubuntu.com failed over to archive.ubuntu.com and apt hung
# for over an hour). apt now gives up on a silent mirror and retries; each attempt is capped and retried twice.
#
# The system packages are installed by a root `timeout`, so apt-get runs in that timeout's own process group and a
# timed-out attempt is ended together with its apt-get children; nothing else on the runner is signalled. The browser
# itself is downloaded without root in a separate bounded step.
set -euo pipefail

playwright="$(pwd)/node_modules/.bin/playwright"
[[ -x "$playwright" ]] || { echo "Playwright is not installed; run pnpm install first." >&2; exit 1; }

printf '%s\n' \
  'Acquire::Retries "3";' \
  'Acquire::http::Timeout "30";' \
  'Acquire::https::Timeout "30";' \
  'DPkg::Lock::Timeout "120";' | sudo tee /etc/apt/apt.conf.d/99arasya-ci-timeouts >/dev/null

# Runs one bounded attempt of "$@" up to three times.
bounded() {
  local label="$1"
  shift
  for attempt in 1 2 3; do
    if "$@"; then
      return 0
    fi
    echo "${label}: attempt ${attempt} failed or timed out." >&2
    sleep $((attempt * 20))
  done
  echo "${label}: failed after 3 attempts." >&2
  return 1
}

bounded "Playwright system packages" sudo env "PATH=$PATH" timeout --kill-after=30 240 "$playwright" install-deps chromium
bounded "Playwright Chromium" timeout --kill-after=30 240 "$playwright" install chromium
