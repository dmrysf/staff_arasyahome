# Handoff: B2B + Finance development program (2026-11-10, release preparation added the same day)

This page is for the next person or session that continues this program. It holds no secret, credential, customer record or real amount.

## Status in one line

Engineering done on draft PRs. Nothing is merged, deployed or activated. Production stays at API 2.66.0 / Staff 2.48.0 with the B2B finance gate unset, which means `off`.

## Pull requests (all draft)

| Repository | PR | Content | Version |
|---|---|---|---|
| staff_arasyahome | #123 | B2B order types, 1 → 2 → 14 route, finance gate at finalization and at production submission, decision journal, Finance journal read, integrated journey test | API 2.67.0, Staff 2.49.0, migration 050 |
| b2b_arasyahome | #6 | Order type choice, read-only finance panel, refusal messages, modal closes on a server refusal | B2B 0.9.0 |
| finance_arasyahome | #23 | Read-only B2B gate journal screen | Finance 0.5.0 |

Not touched: staff #115 and yd-soft #31 (ING), the AWB station code, Trendyol PDF printing, SmartBill and WooCommerce.

## Migration 050 runs after 051

051 (AWB station) is already applied in production. The runner applies every pending file in name order, so 050 is applied after 051. No file is renumbered. The integration test proves that the 051 record, the AWB tables and the AWB grants stay unchanged.

## Finance gate

- Keys: `ARASYA_B2B_FINANCE_GATE` (finalization) and `ARASYA_B2B_FINANCE_RELEASE_GATE` (production submission; absent means "same as the finalization key").
- Values: `off`, `shadow`, `enforce`. Any other value is refused with `503 FINANCE_GATE_MISCONFIGURED` on the two gated actions only. It is never read as `off`. `FinanceConfigGuard` (fingerprint `arasya-finance-config-v3`) refuses a deploy with a malformed value.
- Decisions are taken under the company row lock. The journal `b2b_finance_gate_decisions` is insert-only. A refusal is journaled after the rollback.
- Design and owner decisions D1-D6: `docs/b2b-finance-gate.md`.

## Release preparation (second pass, 2026-11-10)

| Topic | Document | Code and tests |
|---|---|---|
| Zero-downtime, reversible release order | `docs/release-plan-b2b-finance.md` | `GET /b2b/access` lists `features`; B2B 0.9.0 runs on API 2.66.0 without the order type; API 2.66.0 runs on a schema with 050, so 050 is applied before the 2.67.0 code is activated |
| finance.arasyahome.ro: CORS, session, cPanel, health check, rollback | finance repo `docs/deployment.md` | `FinanceConfigGuard` origin checks (`finance_origin_listed` / `_absent`, malformed and near-miss origins refused at deploy); journey test section F; Finance deploy, rollback, validator and `live-check.mjs` with tests |
| Finance access and transition matrix | `docs/finance-iam-matrix.md` | nothing granted |
| Real-data pilot | `docs/finance-real-data-pilot.md` | nothing imported |
| D1–D6 against the code | `docs/b2b-finance-gate.md` §12 | D6 now codes only in the B2B order detail (`FinanceGate::forOrderView`); read-only `bin/b2b-finance-gate-readiness.php` (§14) |

### Verification of the second pass (2026-11-10, staff `18668b1`, b2b `c6f4229`, finance `8ac5d02`)

- Full API suite (24 commands of the CI MariaDB job), run one after the other: `fails=0` on MySQL 8.4 and on MariaDB 10.11. Quality job steps (lint, migration checks, 106 unit tests, release validator, package, deploy and maintenance tests) pass.
- API 2.66.0 (`41fddcd`) on a schema with migration 050 (only the migration file and the test harness added, no product code): 14/14 integration tests on MySQL 8.4 and 14/14 on MariaDB 10.11.
- B2B: 85 unit tests, 39/39 browser smoke (including B2B 0.9.0 against an API 2.66.0 double), real-API E2E 18/18 on both databases.
- Finance: 38 unit tests, build verification, deploy script test.
- CI green on all three heads.
- Local lesson: the migration advisory lock is server-wide; never run two suites against the same database server at once.

### Final release checks (third pass, 2026-11-10)

- D5 corrected: no artificial or symbolic credit limit for prepaid customers. A prepaid order counts as paid only after a payment verified against the bank statement by two people and recorded in the ledger; until a prepaid rule exists, their orders wait under `enforce` (`docs/b2b-finance-gate.md` section 7 and D5).
- R6 replaced: the full short-route flow is proven only on disposable databases (R6a, CI); the first production short-route order is a real order under a separate, explicit owner authorization (R6b). No synthetic company or test order in production (`docs/release-plan-b2b-finance.md` section 8).
- Migration 050 on the live MariaDB: every ALTER that adds a CHECK rebuilds its table (`ALGORITHM=COPY`, measured on MariaDB 10.11.18), writes wait during the copy, and an ALTER waiting for its metadata lock holds back all later queries of the table (server default wait 86400 s). New `bin/migrate.php --lock-wait-timeout=SECONDS` (1-600, default behaviour unchanged) makes such a wait fail within seconds; the partial state is harmless for API 2.66.0 and a re-run completes 050 once (`tests/mysql-migration-lock-timeout-integration.php`). Rehearsal on a restored copy, window, pre-checks and failure table: release plan section 7.
- Finance UI: axe-core WCAG 2.2 A/AA and keyboard checks (finance repo `e2e/`), with the fixes they required.

## Owner decisions still open

1. Merge each PR (#123, #6, #23).
2. Release in the order of `docs/release-plan-b2b-finance.md`: backup and rehearsal, B2B 0.9.0, Staff 2.49.0, migration 050 under API 2.66.0 with `--lock-wait-timeout=5`, API 2.67.0, Finance origin, Finance 0.5.0. The first real short-route order (R6b) needs its own authorization.
3. D1: switch the gate to `shadow`. D2: switch to `enforce` after reading the shadow journal in Finance (`#/b2bGate`).
4. D3-D6 as listed in `docs/b2b-finance-gate.md` §12. D5: no symbolic limit; prepaid needs a verified, recorded payment rule.
5. Finance roles and people per `docs/finance-iam-matrix.md` (who gets `finance.credit.view` to read the journal, the authority list, the pilot operators).
6. The real-data pilot per `docs/finance-real-data-pilot.md` (cut-off date, primary source, evidence, pilot list).
7. Nobody receives Finance access automatically. Cristina, Zelal and the Staff accounting operators keep their current tasks.

## How to verify locally

- API: run every command of the CI MariaDB job against a disposable MySQL 8.4 and MariaDB 10.11 server. Reset the test database after a change to a migration file (the runner refuses a changed checksum).
- B2B: `pnpm typecheck && pnpm lint && pnpm test`, then `VITE_B2B_API_BASE_URL=https://api.arasyahome.ro pnpm build`, `pnpm test:e2e:smoke`, and `pnpm test:e2e:real` with `ARASYA_E2E_DB_NAME` and `ARASYA_TEST_DB_*` set and `./operations-api` linked to this repository's `operations-api`.
- Finance: `npm run check`.

## Rollback

- API: back to 2.66.0 only while `bin/check-rollback.php 2.66.0` says safe (no open short-route B2B order, no finalized unsubmitted one). Otherwise roll forward. 050 is additive and stays; 2.66.0 runs on it.
- B2B 0.9.0 adapts to an API rollback by itself (feature list). B2B 0.8.2 cannot finalize under API 2.67.0, so roll B2B back to 0.8.2 only together with the API.
- Finance: `scripts/cpanel-rollback-finance.sh --previous`; withdrawal by removing the origin and the grants.
- Gate: set it back to `off` (owner decision). Journal rows stay as audit.

## Excluded from this program

ING and SmartBill automatic reconciliation. Real customer import. Any production configuration, credential, role or permission change.
