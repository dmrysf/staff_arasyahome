# Arasya Staff

Operations API 2.11.0 adds [B2B Operations + Staff V1](docs/b2b-production.md): explicit, exactly-once submission of a finalized commercial order into canonical Staff production. Finalize still only freezes and posts the receivable; submission changes no money. Migration 012 adds immutable handoffs and narrow permissions without automatic role grants. Staff 2.3.2 renders frozen manufacturing context. Deployment is manual after CI; no production deployment occurs in this implementation task.

Operations API 2.10.0 introduced [B2B Current Account V1](docs/b2b-current-account.md): an insert-only receivables ledger per company and currency (RON and EUR kept separate). Finalizing a Classic order posts its receivable in the same transaction; cancelling before production submission posts a linked reversal. Payments with optional allocations, opening balances, adjustments, reversals, and CSV/PDF statements remain unchanged. Migration 011 is additive, grants nothing and does not backfill.

Operations API 2.9.1 is a corrective release: order activity records `line_updated` only for lines whose business fields actually changed, and a draft save lists `lines` as changed only when line identities, order or fields changed. No schema, permission or route change.

Operations API 2.9.0 adds [B2B Classic Orders V1](docs/b2b-orders.md): isolated commercial drafts, structured lines, exact RON/EUR totals, explicit finalization/cancellation, immutable snapshots and safe activity. Migration 010 grants no role automatically and never touches production/source commerce. B2B 0.3.0 and Dashboard 0.5.2 consume this contract. Staff 2.3.1 preserves the existing small Bucharest-calendar boundary correction needed for the real-API regression suite; no B2B order integration enters Staff.

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

It runs typecheck, lint, unit/security tests, production build, artifact verification, and checksum-aware deployment/rollback simulation in fail-fast order. The Operations API gate is `pnpm verify:api` (PHP lint, migration checks, unit tests, release and deploy simulations, and the MySQL suites when `ARASYA_TEST_DB_*` names a dedicated `*test*` database). Two Chromium suites gate GitHub publishing: the isolated Preview smoke (`pnpm test:e2e:preview`) and the real Operations API + MySQL flow (`ARASYA_E2E_DB_NAME=<*e2e*test*> pnpm test:e2e:real`). See [`docs/production-reliability.md`](docs/production-reliability.md) for the production runbook, [`docs/cpanel-deployment.md`](docs/cpanel-deployment.md) for Staff delivery, [`docs/staff-security.md`](docs/staff-security.md) for browser policy, and [`docs/github-production-guardrails.md`](docs/github-production-guardrails.md) for manual repository settings.

The independent PHP identity foundation lives in [`operations-api/`](operations-api/) with architecture, security, and cPanel provisioning documented in [`docs/operations-api-auth.md`](docs/operations-api-auth.md), [`docs/staff-auth-security.md`](docs/staff-auth-security.md), and [`docs/operations-api-cpanel.md`](docs/operations-api-cpanel.md).

**Staff 2.3.1 fixes Calendar history around midnight.** Calendar defaults and activity labels now use Europe/Bucharest, matching the API, even when the employee browser uses another timezone. Regression tests cover UTC day boundaries and daylight-saving changes. There is no B2B production handoff.

**Operations API 2.8.0 adds B2B Companies V1.** A separate `operations-api/src/B2B/` module and migration 009 (additive) hold wholesale companies with server-generated UUIDs and `B2B-000001` codes, country plus normalized tax identifier uniqueness, multiple contacts and typed addresses with safe primary rules, internal notes, deactivate/reactivate (no delete), optimistic concurrency, idempotency and an immutable B2B activity history. Four narrow role-grantable permissions (`b2b.companies.view`, `.create`, `.update`, `.manage_status`) work only together with `b2b.access`; no role receives them automatically. No B2B orders, balances, payments or inventory, and no production or source change. Staff is unchanged at 2.3.0. See [`docs/b2b-companies.md`](docs/b2b-companies.md).

**Operations API 2.7.0 registers Arasya B2B as a Central IAM application.** Migration 008 (data only) adds the `b2b` application and the never-role-grantable `b2b.access` permission; nobody but root receives it automatically. `GET /b2b/access` is the B2B application gate on the same central session. Production must list `https://b2b.arasyahome.ro` in `ARASYA_ALLOWED_ORIGINS`. No B2B business tables, no order ingestion and no production change. Staff is unchanged at 2.3.0. See [`docs/central-iam.md`](docs/central-iam.md).

**Operations API 2.6.0 adds Production Control V2: supervisor owner interventions.** `POST /management/orders/{id}/release-owner` and `PUT /management/orders/{id}/owner` let holders of the new `production.manage_owner` permission release or reassign the current production owner, with eligibility checks, the IAM authority ceiling, optimistic concurrency, idempotency, an immutable production activity event and an IAM audit event. They never change the production stage, the commerce status or the source. Order rows gain a neutral `attention` state. Migration 007 is additive. Staff is unchanged at 2.3.0. See [`docs/production-control.md`](docs/production-control.md) and [`docs/pilot-readiness.md`](docs/pilot-readiness.md).

**Operations API 2.5.0 adds Production Control V1.** `GET /management/orders` (keyset-paginated, filterable) and `GET /management/orders/{globalOrderId}` give the Dashboard a read-only order workspace: commerce status and production stage side by side, current owner, normalized items and the immutable production timeline. Source integrations stay inbound-only: Arasya production transitions do not mutate WooCommerce order statuses, enforced by `operations-api/tests/inbound-only-guard.php`. Migration 006 adds two indexes. Staff is unchanged at 2.3.0. See [`docs/production-control.md`](docs/production-control.md).

**Operations API 2.4.0 adds the production overview.** `GET /management/production-overview` gives the Dashboard real, server-aggregated production state: active/waiting/in-work/unassigned/completed-today counts, all 14 stages, oldest active orders, recent production activity and source health, each section gated by its own permission. It is read-only and needs no migration; Staff is unchanged at 2.3.0. See [`docs/production-overview.md`](docs/production-overview.md).

**V2.3.0 makes the Operations API the central identity provider.** One person has one identity for every Arasya application (Staff, the new Dashboard at `dashboard.arasyahome.ro`, and future B2B/Finance). Application access, a server-defined permission catalog, ranked roles with an authority ceiling, Staff stage assignments, departments, a single protected root identity (`arasya.root.owner`), forced first-login password change and an immutable IAM audit are enforced by the API on every request. Staff now refuses identities without Staff access and requires temporary passwords to be changed first. See [`docs/central-iam.md`](docs/central-iam.md).

**V2.2.0 completes Staff operations.** Employees sign in, scan an order QR (native `BarcodeDetector`, or a lazily loaded jsQR fallback on Safari/iOS) or type the order number, see source, products, measurements and the current stage, claim the order, and confirm completion of the current stage. The Operations API alone chooses the next stage (N → N+1), protects every mutation with session, CSRF, idempotency key and expected production version in one locked transaction, hands the order to the next stage, and records immutable activity with stage label snapshots for History and Home metrics. Trendhome/OutletPerdele push signed events through the included WooCommerce connector; the Trendyol adapter pulls packages once credentials are configured. Commerce status never moves production. See [`docs/staff-operations-api.md`](docs/staff-operations-api.md) and [`docs/source-integrations.md`](docs/source-integrations.md).

V2.0.4 established the normalized, API-owned 14-stage curtain-production catalog. V2.0.5 hardened its content-aware ETag, CORS revalidation, visible-app refresh lifecycle, and memory/browser continuity. V2.0.6 made the `curtain-production@1` stage ID-to-ordinal structure an exact fail-closed contract. V2.1.0 closes the foundation with checksummed retained releases, atomic API pointer activation, near-atomic Staff entrypoint activation, code rollback, maintenance/readiness tooling, environment-scoped workflow cache, safe routing, recursive redaction, security headers, and Chromium smoke. The exact 14-stage contract remains unchanged. Details remain in [`docs/production-workflow.md`](docs/production-workflow.md).

## Production delivery

Pushes to `main` trigger **Staff Build & Publish** in GitHub Actions. Only a fully verified, checksummed static release is published to generated `deploy`; the API independently publishes generated `api-deploy`. cPanel validates bytes, retains releases, and switches entrypoints/pointers without Node or a package manager. Production migrations and seeds remain manual.

`VITE_STAFF_PREVIEW_MODE` and `VITE_STAFF_API_BASE_URL` are GitHub build-time repository variables. A production build enters Preview Mode only when `VITE_STAFF_PREVIEW_MODE` is exactly `true`; a missing or different value fails closed to strict production services. Never place credentials, tokens, or other secrets in Vite variables.
