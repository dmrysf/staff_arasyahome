# Test matrix (dual-store release, 2026-10-25)

Every result below comes from a run on the build machine on 2026-10-25, against disposable local databases and local
test doubles (TEST data only). YD SOFT runs were all under `tests/wp-smoke/offline.sh` (no network beyond the
machine). Nothing ran against a live system. A row without a result did not run and is not counted as passed.

Trees: staff_arasyahome `release/dual-store`, yd-soft `release/dual-store` (commit hashes in the master report).

## 1. Operations API (PHP 8.5)

| Suite | Engine | Result |
|---|---|---|
| CI replay of `.github/workflows/operations-api-ci.yml`, shared database in CI order (every test command, `php -l`, `check-migrations`) | MySQL 8.4 | **63 commands, 0 failed** |
| the same | MariaDB 10.11 | **54 commands, 0 failed** |
| `mysql-confirmare-cycle-integration.php` (new) | MySQL 8.4 | **127 checks passed** |
| `mysql-confirmare-operations-relay-integration.php` (new) | MySQL 8.4 | **159 checks passed** |
| `mysql-confirmare-invoice-integration.php` | MySQL 8.4 | **487 checks** PASS |
| `source-access.php` (incl. the `card_full_refund` finance flag) | — | PASS |
| `mysql-smartbill-rollback-integration.php` §4b (043 floor) | both | PASS (in the CI replays) |

## 2. Staff 2.36.0

| Check | Result |
|---|---|
| `tsc --noEmit` | PASS |
| ESLint | PASS |
| Unit tests (`tsx --test tests/*.test.ts`), incl. the new `confirmare-ops.test.ts` | **313 / 313** |
| `verify-staff-security.mjs` (CSP, framing, camera, cache) | PASS |
| Production build + `verify-build.mjs` | PASS |
| `tests/staff-deploy.sh` (retained releases, rollback by checksum) | PASS |
| Real-API E2E (Playwright, real Operations API on MySQL 8.4, TEST shop; local run), incl. the new operations spec: AWB at stage 12, label, e-Factura, health at 320 / 360 / 375 / 390 / 414 / 430 px, no horizontal scroll, 44 px targets, WCAG 2.2 AA | **72 / 72** |

## 3. YD SOFT 2.20.0

Final tree: `release/dual-store` 6351ef9.

| Run | PHP | Storage | Result |
|---|---|---|---|
| Unit suites (`tests/run-unit.sh`, no network) | 8.5 / 8.4 / 7.4 | — | PASS / PASS / PASS |
| Invoice + operations smokes (SmartBill 3.4.10 plugin, local SmartBill Cloud and FAN Courier doubles) | 8.5 | HPOS + posts | **506 / 0** |
| the same | 8.4 | HPOS + posts | **506 / 0** |
| the same | 7.4 | HPOS + posts | **506 / 0** |
| Source smokes | 8.5 / 8.4 / 7.4 | HPOS + posts | **360 / 0** each |
| Cross smoke (real HTTP between the API and a WordPress site) | 8.5 | — | runs failed 0 |
| Confirmare pilot simulation (real API + WordPress) | 8.5 | HPOS / posts | **75 / 0** each |
| the same | 7.4 (API on 8.5) | HPOS | **75 / 0** |
| Connector smoke | 8.5 | — | SMOKE PASSED |
| Zip from the committed branch (`.github/scripts/build-zip.sh 2.20.0`) | — | — | built; contains the new classes, version 2.20.0, no tests |

## 4. Mutations (each must be caught by a test that ran)

| Set | Caught |
|---|---|
| API, new rules (three calls, 10-minute gap, cycle complete, 24 h / 72 h / 120 h, bank check before cancel, bank check covering the expiry, unconfirmed proforma, e-Factura 5 working days and holidays, second reactivation without money, reactivation after production start, reactivation not reopened, AWB before stage 12, AWB by any shop employee, refund without the approve scope, lost answer declared at once, backfill of started orders, TBI into Confirmare, card refund flag refused, health across shops) | **22 / 22** (18 at the first run; the 4 survivors led to 4 new tests, then caught) |
| Staff (card refund in the finance decision, verifying one's own record, shop tasks for everyone, PHP empty map, refusal reason ignored, refund flag refused, cycle run for anyone) | **7 / 7** |
| YD SOFT (collection from the order total, AWB on an old amount, collection per parcel, second AWB, WordPress button not fenced, telephone not required, refund four eyes, refund written twice, second refund request, finance decision on a card refund, reminder twice, reminder before the e-mail, unconfirmed ignored, TBI fenced, SmartBill send error read as success) | **15 / 15** (13 at the first run; the 2 survivors led to 2 new smoke checks, then caught) |

## 5. GitHub Actions

| Pull request | Result |
|---|---|
| staff_arasyahome #92 (`release/dual-store`, at 39a9f16) | quality, MariaDB compatibility, API quality, real-API E2E MySQL 8.4 and MariaDB 10.11, browser smoke: **all pass** |
| yd-soft #23 | **did not run**: "The job was not started because recent account payments have failed or your spending limit needs to be increased" |

The documentation commit after 39a9f16 changes only `docs/`.

## 6. Owner scenarios (brief §14.2)

| Scenario | Covered by | Status |
|---|---|---|
| S1 Trend Home COD 1,000 / 300 proforma / two finance verifications / one SMART invoice / 700 COD / QR / stage 12 AWB | pilot simulation (2.45.0 scope) + invoice suite + relay §3 (AWB from stage 12, collection = what is owed) | parts in different suites; no single end-to-end run |
| S2 Outlet Perdele 250 accepted, invoice 1,000, COD 750 | invoice suite (advance accept) + MoneyPosition unit tests | covered by parts |
| S3 three calls → proforma → reminders → day-5 bank check → SmartBill cancel → shop cancellation | cycle suite §2–4 | covered |
| S4 three calls → proforma → payment → no telephone confirmation → production blocked | cycle suite §2 (production stays closed) | covered |
| S5 / S6 reactivation, later only with money, history kept | relay suite §2 | covered |
| S7 / S8 card 1,000 revised to 1,200, 200 COD / extra proforma | YD operations smoke §2–3 (any payment method collects what is owed), invoice smoke | covered by parts |
| S9 card full refund, one click, Romanian e-mail | YD smoke §8, relay §4 | covered as a **manual** refund (no gateway API) |
| S10 two card transactions 700 + 300 | YD smoke §8 (two entries, partial state, completion, one e-mail) | covered |
| S11 refund outcome unknown → no second refund | relay suite (unknown → reconcile, not received after 4 min), YD stored answers | covered |
| S12 full bank transfer → COD 0 | MoneyPosition unit tests, YD smoke §3 | covered |
| S13 company customer e-Factura task | relay §5 (task per final invoice) | covered (company data fields unchanged) |
| S14 / S15 stage 12 AWB, one parcel / three parcels, collection once | YD smoke §2, relay §3, E2E | covered |
| S16 address change via FAN panel | YD smoke §6, relay §3, E2E | covered |
| S17 same order number in both shops | pilot simulation (2.45.0), cross smoke | covered (unchanged code) |
| S18 22 October backfill, no second invoice | relay §6, YD smoke §10 | covered |
| S19 TBI unchanged | relay §7, YD smoke §9 | covered |
| S20 an order event during the go-live | source ingestion suites (idempotent per event, outbox) | covered (unchanged code) |
