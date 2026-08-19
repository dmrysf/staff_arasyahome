# Arasya Staff

Mobile-first React + TypeScript + Vite SPA/PWA for `staff.arasyahome.ro`. Production is a static `dist/` directory served by Apache/cPanel; no Node process is required after deployment.

## Local development

Requires Node 22.13+ and Corepack. Copy `.env.example` to `.env.local`. For local fixtures only, set `VITE_STAFF_DEMO_MODE=true`, then run:

```sh
corepack pnpm install --frozen-lockfile
corepack pnpm dev
```

`VITE_STAFF_API_BASE_URL` configures the Operations API. Demo mode is enabled only by the Vite development runtime and can never activate in a production build. Production Preview Mode is a separate, explicit build mode documented in [`docs/preview-mode.md`](docs/preview-mode.md).

Individual commands:

```sh
pnpm typecheck
pnpm lint
pnpm test
pnpm build
pnpm preview
```

The authoritative pre-deployment gate is:

```sh
pnpm verify
```

It runs typecheck, lint, unit/security tests, production build, artifact verification, and checksum-aware deployment/rollback simulation in fail-fast order. Real Chromium smoke is the first GitHub publishing gate. See [`docs/production-reliability.md`](docs/production-reliability.md) for the production runbook, [`docs/cpanel-deployment.md`](docs/cpanel-deployment.md) for Staff delivery, [`docs/staff-security.md`](docs/staff-security.md) for browser policy, and [`docs/github-production-guardrails.md`](docs/github-production-guardrails.md) for manual repository settings.

The independent PHP identity foundation lives in [`operations-api/`](operations-api/) with architecture, security, and cPanel provisioning documented in [`docs/operations-api-auth.md`](docs/operations-api-auth.md), [`docs/staff-auth-security.md`](docs/staff-auth-security.md), and [`docs/operations-api-cpanel.md`](docs/operations-api-cpanel.md).

V2.0.4 established the normalized, API-owned 14-stage curtain-production catalog. V2.0.5 hardened its content-aware ETag, CORS revalidation, visible-app refresh lifecycle, and memory/browser continuity. V2.0.6 made the `curtain-production@1` stage ID-to-ordinal structure an exact fail-closed contract. V2.1.0 closes the foundation with checksummed retained releases, atomic API pointer activation, near-atomic Staff entrypoint activation, code rollback, maintenance/readiness tooling, environment-scoped workflow cache, safe routing, recursive redaction, security headers, and Chromium smoke. The exact 14-stage contract remains unchanged. Details remain in [`docs/production-workflow.md`](docs/production-workflow.md).

## Production delivery

Pushes to `main` trigger **Staff Build & Publish** in GitHub Actions. Only a fully verified, checksummed static release is published to generated `deploy`; the API independently publishes generated `api-deploy`. cPanel validates bytes, retains releases, and switches entrypoints/pointers without Node or a package manager. Production migrations and seeds remain manual.

`VITE_STAFF_PREVIEW_MODE` and `VITE_STAFF_API_BASE_URL` are GitHub build-time repository variables. A production build enters Preview Mode only when `VITE_STAFF_PREVIEW_MODE` is exactly `true`; a missing or different value fails closed to strict production services. Never place credentials, tokens, or other secrets in Vite variables.
