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
| R0 | Read-only preflight on the server: `curl -s https://api.arasyahome.ro/health` (2.66.0); `php bin/migration-status.php` from the active API release (050 `PENDING`, 001–049 and 051 `APPLIED`); `php bin/check-rollback.php 2.66.0` (safe). Database server version, row count and size of the four tables 050 rebuilds, and free disk space (section 7.2). Full database backup and a timestamped copy of `$HOME/arasya-config/secrets.json`; restore test of the backup into a separate rehearsal database on the same server, and the 050 rehearsal on it (section 7.3). | All as stated; backup file size plausible; restore tested; rehearsal timing recorded. | Nothing changed in production. Drop the rehearsal database afterwards. |
| R1 | Deploy **B2B 0.9.0** (cPanel Git, `b2b_arasyahome`, Update from Remote, Deploy HEAD Commit). | `https://b2b.arasyahome.ro/release.json` names the B2B `main` SHA. A test account: a new draft shows no order type, saves and finalizes as before. | `scripts/cpanel-rollback-b2b.sh --previous` (0.8.2). |
| R2 | Deploy **Staff 2.49.0**. | `post-deploy-smoke.sh` with `EXPECTED_API_VERSION=2.66.0`; Staff release SHA; one production order opens. | `scripts/cpanel-rollback-staff.sh --previous`. |
| R3 | In the API cPanel Git checkout (`api-deploy`): **Update from Remote only, do not deploy yet.** Then `DRY_RUN=1 /bin/bash scripts/cpanel-deploy-api.sh` from that checkout. | Dry run passes; it prints `OK finance_config_defaults`, `OK b2b_finance_gate_off`, `OK b2b_finance_release_gate_off_inherited`, `OK finance_origin_absent`, no `FAIL`. | Nothing changed. |
| R4 | **Apply migration 050 while API 2.66.0 is still active**, in a quiet window, from the updated checkout (procedure in section 7.4): fresh backup; check that no long transaction is open; `cd <api-deploy checkout> && /usr/local/bin/php bin/migration-status.php` (only 050 `PENDING`), then `/usr/local/bin/php bin/migrate.php --lock-wait-timeout=5`, watched from a second session. The private configuration is read from `$HOME/arasya-config`, as for the active release. | `Session lock_wait_timeout: 5 s`, then `Applied: 050_b2b_order_types.sql`; `migration-status` shows every file `APPLIED`; `/health` 200; Staff and B2B keep working on 2.66.0 (one Staff order, one B2B order list). | 050 stays (additive; 2.66.0 runs on it and on any partial state of it). Do not edit `schema_migrations`. `Migration stopped: … 1205`: nothing to undo; remove the cause and run the same command again (section 7.5). Restore the backup only for the cases in section 7.5. **Never start R5 before `migration-status` shows 050 `APPLIED`.** |
| R5 | Deploy **API 2.67.0** (Deploy HEAD Commit). | Deploy output names the source SHA; `/health` version 2.67.0; `php bin/migration-status.php` all `APPLIED`; `php bin/readiness.php` no `FAIL`; `post-deploy-smoke.sh` with `EXPECTED_API_VERSION=2.67.0`; within a minute B2B shows the order type; one Trendyol order and the AWB station list open in Staff. | `cpanel-rollback-api.sh --previous` while `php bin/check-rollback.php 2.66.0` says safe (no open short-route B2B order, no finalized unsubmitted one). B2B 0.9.0 and Staff 2.49.0 adapt by themselves. |
| R6a | **Isolated full end-to-end, no production write.** The complete short-route flow (typed order, finalization, receivable, release, QR, stage 2, route 1 → 2 → 14, Livrare) is proven on disposable databases: CI `mysql-b2b-finance-journey-integration.php` J1–J18 and `mysql-b2b-order-types-integration.php`, B2B real-API E2E, on MySQL 8.4 and MariaDB 10.11, against the exact merged SHAs. In production after R5 only read-only checks run (R5 column). No synthetic company and no test order are created in production (section 8). | The CI runs of the merged `main` SHAs are green for these suites. | Nothing changed in production. |
| R6b | **Controlled live pilot, only with a separate, explicit owner authorization** naming the real customer, the real order, the people (sales, stage 2, Livrare) and the stop rule (section 8). The first `CUT_ONLY` or `FULL_ROLL_BAG` order is a real order that would be placed anyway; it is watched end to end. Gate stays `off`. | Route 1 → 2 → 14 in Staff; receivable equals the order total; nothing changed for Trendyol and web-shop orders. | Before submission: commercial cancellation as today (audited). After submission: finish at Livrare. Records are never deleted. |
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
| 050 lock wait fails fast, partial state, safe re-run | `mysql-migration-lock-timeout-integration.php` on MySQL 8.4 and MariaDB 10.11: an open transaction on `operational_orders`; `bin/migrate.php --lock-wait-timeout=2` stops within seconds with 1205, earlier 050 statements stay, 050 is not recorded; after the transaction ends the same command applies 050 once. |
| 050 ALTERs rebuild the tables on MariaDB | Measured on MariaDB 10.11.18 (section 7.1). |

Repository tests do not prove the production state. Each production check above is unverified until it is run on the server.

## 7. Migration 050 on the live MariaDB: locks, timeouts, partial failure

### 7.1 What 050 does to the server

- Seven DDL statements: four `ALTER TABLE … ADD COLUMN …, ADD CONSTRAINT … CHECK` (`b2b_orders` twice,
  `b2b_production_handoffs` twice), one on `operational_orders`, a `CREATE TABLE` with three foreign keys
  (`b2b_orders`, `b2b_companies`, `employees`), and on `production_route_decisions` one `DROP CONSTRAINT` and one
  `ADD CONSTRAINT`. Every statement is guarded (it checks `information_schema` and skips what already exists).
- **Each ALTER that adds a CHECK constraint rebuilds the table (`ALGORITHM=COPY`).** Measured on MariaDB 10.11.18:
  `INSTANT`, `INPLACE` and `NOCOPY` are refused for these statements ("Try ALGORITHM=COPY"). During the copy the table can
  be read but not written; a write waits until the copy ends. On a 200,000-row test table the copy took under a second on
  a developer machine; the shared host is slower, so the real time comes from the rehearsal (7.3).
- **Metadata lock.** At its end every ALTER needs an exclusive metadata lock. An open transaction that has touched the
  table (a long report, a stuck worker, an idle session left inside a transaction) holds that lock back. While the ALTER
  waits, **every later read and write of that table waits behind it** (measured: a plain `SELECT` waited until the blocking
  transaction ended). The server default `lock_wait_timeout` is 86400 s, so without a limit Staff production, the AWB
  station and B2B production could stall for as long as the blocking transaction lives.
- **No atomicity across statements.** DDL commits on its own. If a statement fails, the statements before it stay
  applied, the later ones did not run, and the runner does not record 050. The failed statement itself is undone by the
  server (the copy is discarded; the original table is unchanged).

### 7.2 Read-only facts to collect in R0

- Server version: `SELECT VERSION();`
- Size of the rebuilt tables: `SELECT table_name, table_rows, ROUND((data_length + index_length) / 1048576) AS mb FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('b2b_orders', 'b2b_production_handoffs', 'operational_orders', 'production_route_decisions');`
- Free disk space for the account (cPanel, Disk Usage): a copy needs about the size of the largest of these tables free.
- Recurring writers in the window: Trendyol intake cron (every 5 minutes, writes `operational_orders`), shop sync, the
  maintenance cron. They wait for the duration of a copy and continue; choose a window away from the nightly maintenance run.

### 7.3 Rehearsal (owner authorization; data stays on the server)

1. cPanel, MySQL Databases: create a separate database and user for the rehearsal (rights on that database only).
2. Restore the R0 backup into it. This is also the restore test of R0.
3. Run the 050 step against it from the updated checkout with a separate private configuration: a directory
   `$HOME/arasya-rehearsal` (mode 0700) holding a copy of `arasya-config/secrets.json` (mode 0600) in which only the database
   name, user and password point at the rehearsal database; then
   `HOME=$HOME/arasya-rehearsal /usr/local/bin/php bin/migrate.php --lock-wait-timeout=5`, timed with `time`.
4. Record the duration. Run `bin/migration-status.php` with the same `HOME`: every file `APPLIED`.
5. Delete the rehearsal configuration copy and drop the rehearsal database and user.

The rehearsal never points at the production database name. It is the only step that copies production data, and the copy
never leaves the server.

### 7.4 Procedure for R4

1. Window: outside production shifts and away from the nightly maintenance run; the owner announces it. Expected write
   pause per table: the rehearsal duration of that statement.
2. Take a fresh full backup immediately before (the R0 backup is older; restoring it would lose the writes since R0).
3. Look for long transactions (the API user sees its own sessions):
   `SELECT id, time, state, LEFT(info, 60) FROM information_schema.processlist WHERE command <> 'Sleep' AND time > 5;`
   and `SELECT trx_id, trx_started, trx_mysql_thread_id FROM information_schema.innodb_trx WHERE trx_started < NOW() - INTERVAL 10 SECOND;`
   Start only when both are empty.
4. Run `/usr/local/bin/php bin/migrate.php --lock-wait-timeout=5`. Keep a second session open and repeat the processlist
   query; a state `Waiting for table metadata lock` lasts at most the timeout.
5. Check: `Applied: 050_b2b_order_types.sql`, `bin/migration-status.php` all `APPLIED`, `/health` 200, one Staff order and the
   B2B order list open.

### 7.5 When 050 stops

| Symptom | Meaning | Action |
|---|---|---|
| `Migration stopped: … 1205 Lock wait timeout exceeded` | A transaction held the table; the blocked statement was undone; earlier 050 statements stay. API 2.66.0 keeps working (every part of 050 is additive and unknown to it). | Find and end the blocking session (7.4 step 3), then run the same command again. The guards skip what exists; 050 is recorded once at the end (tested). |
| Any other `Migration stopped:` (disk full during a copy, connection lost, server restart) | The failed statement is undone by the server; earlier statements stay. | Fix the cause (disk space) and run again. `migration-status` shows 050 `PENDING` until it completes. |
| A table is damaged or the server reports an inconsistent table after a crash | Not expected (each ALTER is atomic on MariaDB 10.6+ and MySQL 8; the version is read in R0). | Stop. Restore the backup from 7.4 step 2 only with an owner decision, with Staff, B2B and the crons stopped so that no write is lost unnoticed. Never edit `schema_migrations` by hand. |

API 2.67.0 (R5) needs every part of 050. It is never activated while 050 is `PENDING`.

## 8. Why no synthetic order in production

A finalized B2B order is not a test artefact that can be removed. In production it creates, among others: a receivable
in the company's B2B ledger, a production handoff, a canonical operational order with a QR and a ticket revision, stage
history, a Livrare handover, B2B and factory history, finance gate journal rows (when the gate is not `off`) and audit
records. Several of these are insert-only by design and the ledger is corrected only by a reversal, never by deletion.
They would also appear in production queues, KPIs and analytics. A synthetic company would in addition become a B2B
company that Finance must later verify or reject.

Therefore:

1. The full flow is proven only on disposable databases (R6a). That proof already exists in CI for the merged SHAs.
2. The first production short-route order is a real order of a real customer (R6b), with a separate, explicit owner
   authorization that names the customer, the order, the people at each step and the stop rule: stop at the first
   deviation (wrong route, missing receivable, a Trendyol or web-shop order affected), leave the order where it is, report.
3. The authorization is per order. It does not cover later orders or a gate change.


## 9. Update 2026-11-10: API 2.68.0, Finance 0.6.0, B2B 0.10.0 (prepared, not performed)

Live today: API 2.66.0, Staff 2.49.1, B2B 0.9.1; migrations 050 and 052 pending; Finance not deployed. API 2.67.0 is never
deployed on its own any more: 2.68.0 contains it and makes order types, the finance gate and B2B account writes independent
server switches (`docs/finance-collections.md`, section 7). With the production defaults API 2.68.0 behaves like 2.66.0 for
B2B orders (no order type, no finance panel) and like 2.66.0 for the B2B account (B2B still writes).

| Step | Action | GO when | Rollback |
|---|---|---|---|
| S1 | Deploy **B2B 0.10.0** (runs on 2.66.0; nothing visible changes) | release.json names the B2B main SHA; account screen and order list open | `cpanel-rollback-b2b.sh --previous` (0.9.1), only while no refund movement exists |
| S2 | Server: update the API checkout to the 2.68.0 `api-deploy` head; `DRY_RUN=1 cpanel-deploy-api.sh` | `OK finance_config_defaults`, `OK b2b_order_types_off`, `OK finance_collections_disabled`, `OK b2b_account_writes_enabled`, no FAIL; the dry run lists 050 and 052 as pending migrations (expected before S3) | nothing changed |
| S3 | Fresh backup; migrations **050 then 052** with API 2.66.0 active, `bin/migrate.php --lock-wait-timeout=5`, quiet window (section 7) | both `APPLIED`; `/health` 200; Staff order and B2B lists open on 2.66.0 | additive; leave in place. 052 rebuilds the small ledger and Finance tables (ALGORITHM=COPY), rehearse it like 050 |
| S4 | Deploy **API 2.68.0**, every switch at its default (the deploy script refuses while any shipped migration is pending) | `/health` 2.68.0; `GET /b2b/access` features `[]`; B2B finalizes without a type; smoke tests; AWB station and Trendyol unchanged | `cpanel-rollback-api.sh --previous` while `check-rollback.php 2.66.0` is safe |
| S5 | Finance origin and **Finance 0.6.0** (R7/R8). Collections still `disabled` | `live-check.mjs` PASS; default deny with the application alone; collection screens show "not enabled" | remove origin, `cpanel-rollback-finance.sh --previous` |
| S6 | Owner decisions: second verifier named; roles created and assigned (`docs/finance-iam-matrix.md`) | two different people hold record and approve | remove roles |
| S7 | **In one configuration change:** `ARASYA_FINANCE_COLLECTIONS=enabled` and `ARASYA_B2B_ACCOUNT_WRITES=finance_only` (one ack). There is no parallel period in which B2B and Finance both post payments | each pilot payment approved by a second person; B2B screen read-only; B2B balance equals Finance view; refused attempts reviewed (`GET /finance/refused-attempts`) | write both back to the defaults (`disabled`, `enabled`) |
| S8 | Separate owner decision after the pilot: remove the B2B account write permissions (`b2b.accounts.record_payment`, `.adjust`, `.reverse`) from the roles; Contabilitate/director-financiar keep `b2b.accounts.view` | B2B write permissions held by nobody | give the permissions back |
| S9 | Finance gate D1 `shadow` for at least 14 days, then pilot, then D2 `enforce` (sections 4 and 12 of `docs/b2b-finance-gate.md`) | weekly journal review; every company that buys on credit `ready_for_credit` | `off` |
| S10 | `ARASYA_B2B_ORDER_TYPES=enabled` only when the owner decides to use order types | features `["orderTypes", …]`; first typed order as a controlled real order | write `off` |

Never approve a Finance refund before B2B 0.10.0 is live: B2B 0.9.x refuses an unknown movement type, so the account
screen of that company would fail.

### 9.1 Per step: prerequisites, switches, rollback floor, data risk, approval

Every step needs its own written owner approval; none is implied by an earlier one. "Floor" is the output of
`bin/check-rollback.php <target>`; `cpanel-rollback-api.sh` runs it itself and refuses an unsafe target. `cpanel-rollback-b2b.sh` refuses a release before
0.10.0 while a refund movement exists (it asks the active API release, fail closed). The Finance rollback has no floor.

| Step | Needs before | Migration | Switches after the step | Rollback and floor | Data-integrity risk |
|---|---|---|---|---|---|
| S1 B2B 0.10.0 | API 2.66.0 live (works on 2.66, 2.67 and 2.68) | none | none (client only) | `cpanel-rollback-b2b.sh --previous`; the script refuses 0.9.x while a refund movement exists | none: reads only what the API sends |
| S2 API dry run | S1 GO | none | none | nothing to roll back | none |
| S3 Migrations 050, 052 | S2 GO; fresh, verified backup; API 2.66.0 active; quiet window | 050 then 052 (order fixed; 052 needs 050) | none (2.66.0 ignores the new tables and types) | additive, stays in place; API 2.66.0 keeps running on it | 052 rebuilds the ledger tables with `ALGORITHM=COPY` to swap CHECKs: a lock-wait timeout aborts cleanly (section 7); existing rows are kept unchanged (proven by test M) |
| S4 API 2.68.0 | S3 `APPLIED` on both | 050 + 052 required; `cpanel-deploy-api.sh` refuses activation while any shipped migration is pending or the schema cannot be read | all defaults: `ARASYA_B2B_FINANCE_GATE=off`, `ARASYA_B2B_ORDER_TYPES=off`, `ARASYA_FINANCE_COLLECTIONS=disabled`, `ARASYA_B2B_ACCOUNT_WRITES=enabled` | `cpanel-rollback-api.sh --previous` to 2.66.0; floor is empty while no Finance request, no refund movement and no typed short-route order exists | none: behaves like 2.66.0 for B2B orders and accounts |
| S5 Finance 0.6.0 + origin | S4 GO | none | collections `disabled` (screens show "not enabled") | `cpanel-rollback-finance.sh --previous`, remove the origin; no floor (client only) | none: Finance cannot write while collections are disabled |
| S6 Roles | S5 GO; owner names the recorder(s) and the second verifier | none | none | remove the roles | none; four eyes needs two different people, the API refuses one person deciding their own entry |
| S7 Collections on | S6 GO; **B2B 0.10.0 live (S1) confirmed in `release.json` before the first refund request**; opening-balance cut-off and pilot companies decided | none | `ARASYA_FINANCE_COLLECTIONS=enabled` together with `ARASYA_B2B_ACCOUNT_WRITES=finance_only` (one ack) | write both back to the defaults: open requests stay, nothing is posted; API floor becomes 2.68.0 as soon as a draft, pending request, unpaid approved refund or refund movement exists | none from parallel writing: the B2B write routes are closed on the server in the same change. Payments entered in B2B before S7 have no bank identity; Finance flags a new request with the same amount near the same date against them (acknowledgement required), and the reconciliation view lists them "without bank identity" |
| S8 B2B write permissions removed | pilot clean; owner decision | none | none (roles only) | give the permissions back | none; order receivables still follow the order lifecycle |
| S9 Finance gate | S8; every credit customer reviewed | none (050) | `ARASYA_B2B_FINANCE_GATE=shadow`, later `enforce` | write `off` | shadow writes journal rows only; enforce blocks finalization, never posts money |
| S10 Order types | owner decision | none (050) | `ARASYA_B2B_ORDER_TYPES=enabled` | write `off`; API floor 2.67.0 while a typed short-route order is open | none for money |

### 9.2 After the first refund: rollback below 2.68.0 is closed, recovery is forward

From the first `refund` movement on, `check-rollback.php` reports `requiredFloor: 2.68.0` permanently (the ledger is
insert-only; a cancelled refund keeps its movement and its reversal). `cpanel-rollback-api.sh` then refuses 2.67.x and
2.66.0, and B2B must stay on 0.10.0 or later. This is intended: an older API or B2B cannot read the movement type.

Recovery without going below 2.68.0, in this order:

1. **Stop new Finance writes:** `ARASYA_FINANCE_COLLECTIONS=disabled`. Reads, the B2B account screen and the order
   lifecycle keep working; open requests wait.
2. **Give the account back to B2B if Finance is the problem:** `ARASYA_B2B_ACCOUNT_WRITES=enabled`. B2B payments then use
   the single-person path again (owner decision, the four-eyes rule does not cover it).
3. **Fix forward:** a 2.68.x patch release from the same branch, deployed with `cpanel-deploy-api.sh`; rollback between
   2.68.x releases stays allowed (same floor).
4. **Correct a wrong posting** only through an approved reversal request (Finance) — never by SQL. A wrong refund that was
   not paid out is cancelled (`cancel-payout`, second person); a paid-out refund is corrected with an adjustment request.
5. **B2B front-end defect:** fix forward as 0.10.x; `cpanel-rollback-b2b.sh` refuses 0.9.x while a refund movement
   exists, because 0.9.x would break the account screen of every company with a refund.
6. **Database restore is the last resort**, owner decision only: it discards every ledger row written after the backup
   (real payments included), so those must be re-entered and approved again from the bank statement.

### 9.3 Merge is not deployment

Verified 2026-11-10 (workflows and, read-only, the cPanel account):

- A merge to `main` runs CI and publishes a verified package to a GitHub branch only: `api-deploy` (Operations API,
  `publish-api-release`), `deploy` (Staff, B2B, Finance). No workflow connects to the server, and none runs a migration.
- The cPanel repositories (`staff_arasyahome_api` on `api-deploy`, `staff_arasyahome` and `b2b_arasyahome` on `deploy`;
  Finance has none yet) change only when someone runs "Update from Remote" and then "Deploy HEAD Commit"
  (`uapi VersionControl update` / `VersionControlDeployment create`). Their `post-receive` hook fires only for a push into
  the server repository, which nobody does. The account's cron jobs run the API maintenance, Trendyol and Confirmare
  launchers of the active release; none pulls code.
- Migrations run only by hand (`bin/migrate.php`). From this release `cpanel-deploy-api.sh` refuses to activate an API
  whose shipped migrations are not all applied, so an accidental "Deploy HEAD Commit" of 2.68.0 before S3 changes nothing.
- After the merges production stays on API 2.66.0, Staff 2.49.1 and B2B 0.9.1 until S1.

Refused money commands (four eyes, permission, duplicate bank transaction, closed B2B write path) are kept in
`finance_refused_attempts` (migration 052) and listed by `GET /finance/refused-attempts` (`finance.audit.view`); the web
server error log is rotated within days and is not an audit record.
