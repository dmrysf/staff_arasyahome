# Release plan: API 2.67.0, Staff 2.49.0, B2B 0.9.0, Finance 0.5.0 (prepared, not performed)

Status: plan only. Nothing here has been merged, deployed or run in production. Every numbered step needs a separate owner
authorization. Live today: API 2.66.0, Staff 2.48.0, B2B 0.8.2, Finance not deployed, B2B finance gate unset (`off`).

Pull requests: staff #123 (API 2.67.0, Staff 2.49.0, migration 050), b2b #6 (B2B 0.9.0), finance #23 (Finance 0.5.0).

## 1. Goal: no downtime, every step reversible on its own

The order below never runs a client against an API it cannot use, and never runs API code against a schema it does not know.
Three properties make that possible:

1. **B2B 0.9.0 runs on API 2.66.0.** API 2.67.0 announces `features: ["orderTypes", "financeGate"]` on `GET /b2b/access`.
   B2B 0.9.0 shows, sends and requires the order type only when that list is present. On API 2.66.0 it behaves like B2B 0.8.2
   for orders (no type, finalization without one) and hides the finance panel. B2B re-reads the access once a minute and on tab
   focus, so after the API switch the order type appears within a minute, without a reload, and after an API rollback it
   disappears the same way.
2. **Migration 050 is additive and API 2.66.0 runs on it.** 050 adds nullable columns, a new insert-only table and one more
   allowed value in a CHECK. It rewrites no row. API 2.66.0 code was run against a schema that already has 050 (section 6),
   so 050 can be applied **before** the API 2.67.0 code is activated. API 2.67.0 code, in contrast, reads
   `operational_orders.service_route` on every production read: activating it before 050 would break Staff production, the AWB
   station and B2B production for every order until the migration ran.
3. **Staff 2.49.0 runs on API 2.66.0.** It reads the new `serviceRoute` field only when present.

## 2. Compatibility matrix

| | API 2.66.0 (live) | API 2.66.0 + migration 050 | API 2.67.0 (050 applied) |
|---|---|---|---|
| B2B 0.8.2 (live) | yes | yes | drafts yes; finalization refused (`422`, order type required) |
| B2B 0.9.0 | yes, without order type and finance panel | yes, as on the left | yes, full |
| Staff 2.48.0 (live) | yes | yes | yes; short-route label not shown (routing is decided by the API) |
| Staff 2.49.0 | yes, no route label | yes | yes, full |
| Finance 0.4.0 / 0.5.0 | yes; `#/b2bGate` shows the error state (`404`) | same | yes, full |
| API 2.67.0 code without migration 050 | — | — | **not allowed** (production reads fail) |

## 3. Merge order (owner)

1. staff #123. Its `main` run publishes `api-deploy` and `deploy` (Staff). Nothing reaches the server by itself.
2. b2b #6 (its real-API E2E is pinned to the #123 head; the commit stays reachable after the merge). Its `main` run publishes
   the B2B `deploy` branch.
3. finance #23. Its `main` run publishes the Finance `deploy` branch (job `verified-release`).

Confirm each `main` run is green and each `release.json` names the merged `main` SHA before step R1.

## 4. Release steps

Times are guidance; each step is short. Stop at the first failed check and use the rollback of that step.

| Step | Action | Check (expected result) | Rollback of this step |
|---|---|---|---|
| R0 | Read-only preflight on the server: `curl -s https://api.arasyahome.ro/health` (2.66.0); `php bin/migration-status.php` from the active API release (050 `PENDING`, 001–049 and 051 `APPLIED`); `php bin/check-rollback.php 2.66.0` (safe). Full database backup and a timestamped copy of `$HOME/arasya-config/secrets.json`; restore test of the backup. | All as stated; backup file size plausible, restore tested. | Nothing changed. |
| R1 | Deploy **B2B 0.9.0** (cPanel Git, `b2b_arasyahome`, Update from Remote, Deploy HEAD Commit). | `https://b2b.arasyahome.ro/release.json` names the B2B `main` SHA. A test account: a new draft shows no order type, saves and finalizes as before. | `scripts/cpanel-rollback-b2b.sh --previous` (0.8.2). |
| R2 | Deploy **Staff 2.49.0**. | `post-deploy-smoke.sh` with `EXPECTED_API_VERSION=2.66.0`; Staff release SHA; one production order opens. | `scripts/cpanel-rollback-staff.sh --previous`. |
| R3 | In the API cPanel Git checkout (`api-deploy`): **Update from Remote only, do not deploy yet.** Then `DRY_RUN=1 /bin/bash scripts/cpanel-deploy-api.sh` from that checkout. | Dry run passes; it prints `OK finance_config_defaults`, `OK b2b_finance_gate_off`, `OK b2b_finance_release_gate_off_inherited`, `OK finance_origin_absent`, no `FAIL`. | Nothing changed. |
| R4 | **Apply migration 050 while API 2.66.0 is still active**, from the updated checkout: `cd <api-deploy checkout> && /usr/local/bin/php bin/migration-status.php` (only 050 `PENDING`), then `/usr/local/bin/php bin/migrate.php`. The private configuration is read from `$HOME/arasya-config`, as for the active release. | `Applied: 050_b2b_order_types.sql`; `migration-status` shows every file `APPLIED`; `/health` 200; Staff and B2B keep working on 2.66.0 (one Staff order, one B2B order list). | 050 stays (additive; 2.66.0 runs on it). Do not edit `schema_migrations`. If 050 failed part-way: restore the R0 backup. |
| R5 | Deploy **API 2.67.0** (Deploy HEAD Commit). | Deploy output names the source SHA; `/health` version 2.67.0; `php bin/migration-status.php` all `APPLIED`; `php bin/readiness.php` no `FAIL`; `post-deploy-smoke.sh` with `EXPECTED_API_VERSION=2.67.0`; within a minute B2B shows the order type; one Trendyol order and the AWB station list open in Staff. | `cpanel-rollback-api.sh --previous` while `php bin/check-rollback.php 2.66.0` says safe (no open short-route B2B order, no finalized unsubmitted one). B2B 0.9.0 and Staff 2.49.0 adapt by themselves. |
| R6 | First real use, with the owner's test account only: a `CUT_ONLY` B2B order for a synthetic company, finalize, submit, QR, stage 2, Livrare. Gate stays `off`. | Route 1 → 2 → 14 in Staff; nothing changed for Trendyol and web-shop orders. | Cancel the test order commercially before submission; after submission finish it at Livrare. |
| R7 | **Finance origin.** Add `https://finance.arasyahome.ro` to `ARASYA_ALLOWED_ORIGINS` (exact, from the timestamped backup copy). Run `php bin/finance-config-check.php`. | `OK finance_origin_listed`, no `FAIL`. Staff, Dashboard and B2B still log in. | Restore the backup copy of `secrets.json` (applies on the next request). |
| R8 | **Finance 0.5.0**: subdomain and cPanel Git repository as in `finance_arasyahome/docs/deployment.md` section 3, then deploy. | `node scripts/live-check.mjs <finance main sha>`: every check `PASS`. A test account with only the Finance application sees no content (default deny). | `scripts/cpanel-rollback-finance.sh --previous`, or withdraw (remove the origin and the grants). |
| R9 | Later, separately: **D1** (`shadow`), then the shadow analysis, then **D2** (`enforce`), as in `docs/b2b-finance-gate.md` section 12. | `php bin/finance-config-check.php` with the acknowledgement; `#/b2bGate` and `bin/b2b-finance-gate-readiness.php`. | Write `off` (no acknowledgement needed). |

R1 and R2 may also follow R5; the order above is preferred because it removes the window in which B2B 0.8.2 cannot finalize.

## 5. Why not the shorter order (API first, then clients)

- API 2.67.0 activated, then 050: production reads fail for every order between the two commands.
- 050, API 2.67.0, then B2B 0.9.0: B2B 0.8.2 cannot finalize until B2B 0.9.0 is live (minutes, but a real refusal for a user).
- The plan above has neither window.

## 6. Evidence

| Claim | How it was checked |
|---|---|
| API 2.66.0 runs on a schema with 050 | API 2.66.0 source (`main` 41fddcd) with `050_b2b_order_types.sql` added and only the test harness adjusted (expected migration lists, the shared schema fixture): the 2.66.0 B2B, production, AWB, cutting, QR, documents and Finance integration tests on MySQL 8.4 and MariaDB 10.11. Results in the handoff document. |
| B2B 0.9.0 on API 2.66.0 | B2B unit test (feature list absent: no order type is sent) and browser smoke "zero-downtime release" against an API double that refuses `orderType` as an unknown field and finalizes without it. |
| B2B 0.9.0 on API 2.67.0 | Real-API E2E against the #123 head on MySQL 8.4 and MariaDB 10.11 (CI). |
| 050 after 051 | `mysql-b2b-order-types-integration.php` upgrades a database that already has 051 and checks the AWB tables, grants and the 051 record. |
| Rollback floor | `bin/check-rollback.php` refuses 2.66.0 while short-route work is open (same test, section N). |
| Finance CORS, session, default deny | `mysql-b2b-finance-journey-integration.php` section F; Finance `tests/finance-deploy.sh` and `tests/finance-deploy.test.mjs`. |

Repository tests do not prove the production state. Each production check above is unverified until it is run on the server.
