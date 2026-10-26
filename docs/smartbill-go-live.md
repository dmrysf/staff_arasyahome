# SmartBill go-live: the one deployment file (API 2.45.0, Staff 2.35.0, YD SOFT 2.19.0)

Status 2026-10-25 (security integration): preparation only. Nothing in this file was run against a live system. Every
step needs the owner's explicit approval, given step by step. The accountant's decisions stay **BLOCKED** until the
accountant answers; this file never assumes them.

Related: `smartbill-invoicing.md` (what the feature does), `smartbill-live-verification.md` (the read-only checks, the
SmartBill posting procedure, the controlled ARSTEST test), `smartbill-master-scenarios.md` (the scenarios),
`confirmare-cohort.md` (the per-source Confirmare readiness).

## 1. Where things stand

**Code (local, not committed):** one integration tree per repository, built in throw-away worktrees. Nothing is
committed, pushed or merged; the original branches are unchanged.
- `staff_arasyahome`: on `6921278` (branch `feature/smartbill-invoicing`, on top of draft PR #90 `c36f388`), in this
  order:
  1. the five safety commits of 2026-10-23 (`5d62a60`, `703f748`, `4016a2e`, `a453842`, `e95c68c`), all clean;
  2. the uncommitted SmartBill 2.44/2.45 work, with five conflicts resolved by hand (both sides kept);
  3. the per-source Confirmare readiness, migration 042 and the invoice-aware rollback check.
- `yd-soft`: on `b057068` (branch `feature/smartbill-invoicing`, on top of draft PR #21 `1127f20`), in this order:
  1. the safety commits `2241fc5` (clean), `53cde94` + `ed47657` (conflicts with `2241fc5` resolved once, verified line
     by line against both commits), `bbb80f7` (clean);
  2. the uncommitted SmartBill 2.18/2.19 work (conflict in `FinanceActions.php`: the K-1 cancelled-order check and the
     2.19.0 over-payment confirmation kept side by side);
  3. the connector readiness report, the accountant attestation and the database mode of the live checks.

**Live, from the deployment records (NOT VERIFIED today; section 4 confirms it read-only):**
- API 2.34.x with migrations up to 031. The last recorded deploy is 2.34.0 (2026-10-15); `main` (7184bf4, 2.34.2) is
  not recorded as deployed.
- Staff 2.26.x.
- YD SOFT 2.9.1 on Trend Home and Outlet Perdele, with the production, QR, document and tracking authorities enforced.
- The payment gate is off on both sources.

## 2. What the release brings

| Layer | Pull requests / commits | API | Staff | Migrations |
|---|---|---|---|---|
| Order cancellation control and sync | #73, #74 (in #90) | 2.35.0, 2.36.0 | 2.27.0, 2.28.0 | 033, 034 (resumable since c36f388) |
| Confirmare phases 1 to 6 | #84 to #89 (in #90) | 2.37.0 to 2.42.0 | 2.29.0 to 2.32.0 | 035 to 039 |
| Safety fixes (rules 1 and 2, K-1, K-2, contract, pickup window, texts) | the nine local commits of section 1 | in place | texts | none |
| SmartBill invoicing | fe0b2b9, 6921278, uncommitted | 2.43.0 to 2.45.0 | 2.33.0 to 2.35.0 | 041 |
| Confirmare readiness per source, rollback floors | uncommitted (this integration) | 2.45.0 | none | 042 |
| YD SOFT | #13 to #20 via #21, b057068, uncommitted | | | (WordPress options and two tables of the access schema) |

- Migration 040 is reserved for unrelated work and does not exist.
- 032 belongs to the factory simulation draft (#64) and is not part of this release.
- The Dashboard changes of the cancellation stack (PRs #16 and #17, local commit `2cbb431`) are a separate approval.

## 3. Blockers before any deploy

| # | Blocker | State | What clears it |
|---|---|---|---|
| B1 | VAT on the advance (Codul fiscal art. 282(2)(b)), the invoice by the 15th of the next month (art. 319(16)), a separate advance invoice or not, the SmartBill posting channel (A or B) and collection type, the cash advance link | **BLOCKED (accountant)** | written answers of the accountant. YD SOFT refuses invoice `enforce` without `--accountant-approval="<reference>"`, and Arasya refuses Confirmare activation with invoicing unless the shop reports that record |
| B2 | Confirmare could not be activated for any source (`WORKFLOW_READY` false) | **resolved in code (local)** | `ConfirmareReadiness`: activation per source only when every required check passes (section 7) |
| B3 | Safety commits not in the SmartBill branches | **resolved locally** | the integration trees of section 1; every suite run again (section 11) |
| B4 | `bin/check-rollback.php` did not know the invoice state | **resolved in code (local)** | floor 2.45.0 for open, unknown or half-done invoice work; 041/042 checksums verified (section 9) |
| B5 | A push to yd-soft `master` builds a release at once; the GitHub Actions billing block stops every yd-soft job (CI and release) | **open (billing)** | billing fixed by the owner. Until then no yd-soft CI result exists and none is assumed. The pilot does not need a release: section 8 installs a locally built zip on Trend Home only |
| B6 | Real SmartBill answers (create, PDF, invoiced-from-proforma, cancel) | **NOT VERIFIED** | the ARSTEST test (live-verification §4) |
| B7 | e-Factura: SPV connection and automatic sending on the live SmartBill account | **NOT VERIFIED** | live-verification §2 and §3 C |
| B8 | Duration of 033 to 042 on the live table size | **measured on synthetic data; live size NOT VERIFIED** | section 5; the live row counts and a timed run on a restored copy of the live backup |
| B9 | TBI orders never clear the Confirmare payment check | **owner decision** | section 10 |
| B10 | Refunds stay manual; refunds of cancelled orders are refused | **owner decision** (kept) | section 10 |
| B11 | Sinhala texts of the new Staff screens | **review** | a native reader |

## 4. Live compatibility check (one plan for both shops, read-only)

Run in this order, on Trend Home first and then Outlet Perdele. The commands, what they read, what they need and what
they can change are in `smartbill-live-verification.md` §3; **the database mode runs first** (WordPress not loaded,
READ ONLY session and transaction). Results are written next to each line. Nothing has been run: every line is
**NOT VERIFIED**.

| # | What | Trend Home expected | Outlet Perdele expected | Means |
|---|---|---|---|---|
| L0 | Must-use plugins, WP-CLI and PHP versions, `DISABLE_WP_CRON` in wp-config, literal DB settings in wp-config (database mode) | listed | listed | shell, read-only |
| L1 | Active versions: WordPress, PHP, WooCommerce, YD SOFT, SmartBill, NETOPIA | YD SOFT 2.9.1, SmartBill 3.4.10 | same | A `versions`, `smartbill_plugin_3_4_10` |
| L2 | YD SOFT not on automatic updates | PASS | PASS | A `yd_soft_auto_update_off` |
| L3 | HPOS on or off, sync with posts | recorded (INFO), then fixed as `hpos=` | same | A `hpos` |
| L4 | Source key, delivery mode, authority modes, Confirmare mode, cancellation sync, last connection | `trendhome`, ingestion, authorities enforce, Confirmare absent | `outletperdele`, same | A `arasya_source` |
| L5 | SmartBill company in the plugin, credentials present | `RO42340172__subsidiary_1` (to confirm per shop) | to confirm | A `company_in_plugin`, `smartbill_connected` |
| L6 | Final document type, SMART and ARS in the plugin | invoice, SMART, ARS | same (to confirm) | A |
| L7 | Plugin paths that act alone: automatic e-mail, cashing, draft, automatic issue statuses, payment link, currency | all off, RON | same | A `plugin_settings_kept` |
| L8 | SMART (`f`) and ARS (`p`) exist in the SmartBill company | both | both | B (one GET, separate approval) |
| L9 | ING account RO79INGB0000999915112089 / INGBROBU | not yet configured in YD (absent before 2.18) | same | A `yd_approved_bank` (INFO until YD 2.19.0) |
| L10 | Automatic invoicing of proformas: "Include și proformele" off, no online payment button on documents | off | off | C, SmartBill administrator |
| L11 | Whether Rapoarte › Tranzacții bancare exists and receives ING (step 0 of the posting procedure) | recorded | recorded | C |
| L12 | Free shipping at 600 lei (min_amount or either), flat rate, other methods | 600, verifiable methods only | same | A `free_shipping_threshold`, `shipping_methods_verifiable` |
| L13 | Effective advance percentage | 30 | 30 | A `advance_percentage` |
| L14 | Enabled payment methods | recorded; TBI per B9 | recorded | A `payment_gateways` |
| L15 | e-Factura: SPV connected, automatic sending, delay | on, at most one day | same | C |
| L16 | Arasya API version and migrations | 2.34.x, up to 031 | (one API) | D: `GET /health`, `php bin/migration-status.php` |
| L17 | Row counts and sizes of `operational_orders`, `operational_order_items` and the database (for B8) | recorded | (one API) | D: one `SELECT` on `information_schema.TABLES`, read-only |

The check is accepted when every line has a recorded result and A and B end with PASS on both shops (or each FAIL has
an owner decision written next to it).

## 5. Migrations

- Applied only by `bin/migrate.php` during the API deploy, in order: 033, 034, 035, 036, 037, 038, 039, 041, 042.
- They are never run by hand on the live database.
- 041 creates five tables, 042 one table (`source_connector_readiness`), both with `CREATE TABLE IF NOT EXISTS`; neither
  grants anything, writes a row or alters another table.
- 033, 035, 036 (twice), 038 and 039 rebuild `operational_orders` by copy (table lock for writes while they run).
- Rollback never drops a table: the new tables stay, unused by older releases.

**Measured (2026-10-25, TEST ONLY, disposable local databases, synthetic data):** the live schema (`main` 7184bf4,
migrations 001 to 031) seeded with N orders, then 033 to 042 of this release applied while a second process wrote to
`operational_orders` once per 100 ms.

| Engine | Orders | All of 033 to 042 | Longest wait of one write | Database size before → after | Errors |
|---|---|---|---|---|---|
| MySQL 8.4 | 5,000 | 1.97 s | 0.20 s | small | 0 |
| MySQL 8.4 | 50,000 | 9.89 s | 1.54 s | small | 0 |
| MySQL 8.4 | 200,000 | 39.18 s | 6.53 s | 287 → 307 MB | 0 |
| MariaDB 10.11 | 200,000 | 23.82 s | 3.85 s | 285 → 303 MB | 0 |

- Every run: indexes and foreign keys kept, the data unchanged, a second run changes nothing. 036 is the longest step.
- **Backup, restore and interruption (MySQL 8.4, 50,000 orders):** a `mysqldump --single-transaction` of the live schema,
  restored into a second database, is identical (`CHECKSUM TABLE` of every table, schema). The migration process was
  killed three times (during 036, during 038 and during 039); each time a second run finished without manual repair and gave the
  same schema and data as an uninterrupted run (date-times written by the runs themselves compared as "run time").
- **Not known:** the live row counts and the live order-item data (only 41 items were seeded). The live duration is
  therefore **NOT VERIFIED**; L17 gives the sizes, and the timing is repeated on a restored copy of the live backup
  before the deploy window is fixed. No duration is promised for the live database.

Before the deploy:
- Take a full backup of the live database, kept outside the server, and restore it once into a disposable database.
- Time the run on that copy (B8).
- Run `php bin/migration-status.php` in the installed release (read-only).

## 6. Compatibility between the layers (also: a partial deploy)

| Combination | Behaviour |
|---|---|
| API 2.45.0 with YD SOFT 2.9.1 | Works with every new source setting off: the API calls a shop only when `ARASYA_SOURCE_ACCESS_<SRC>` and its URL and key are set. With them set, a shop without the routes answers 404, the API reports `SOURCE_ERROR` and changes nothing (fail closed). The heartbeat answer gains `connectorReadiness: 1`; YD SOFT 2.9.1 ignores unknown answer keys (checked in its `check_heartbeat`). Confirmare cannot be activated: no readiness report arrives. |
| YD SOFT 2.19.0 with API 2.34.x | Not planned: deploy the API first. Every YD SOFT 2.19.0 mode is off after installation (`access`, `finance`, `invoice`, Confirmare, cancellation sync). It sends no readiness report to a server that does not announce it. |
| Staff 2.35.0 with API older than 2.45.0 | Not allowed: the new screens call routes that do not exist. Deploy Staff right after the API. |
| Staff 2.26.x with API 2.45.0 | Works for the short time between the two deploys (older screens use older routes). |
| SmartBill plugin other than 3.4.10 | The adapter refuses (`SmartBillAdapter` is pinned to 3.4.10); invoice actions report the plugin as not supported; the readiness report says `smartbillReady: false`. |
| Trend Home on YD 2.19.0, Outlet Perdele on 2.9.1 | The intended pilot state. Outlet Perdele stays legacy: no Confirmare, no invoice action, its orders never reach a Trend Home employee (pilot simulation, section 11). |

## 7. Switches (all off at deploy)

| Where | Switch | Values | At deploy |
|---|---|---|---|
| API env, per source | `ARASYA_SOURCE_CONFIRMARE_<SRC>` | off / on | off |
| API env, per source | `ARASYA_SOURCE_ACCESS_<SRC>` (+ `_URL_`, `_KEY_`) | off, read, edit | off |
| API env, per source | `ARASYA_SOURCE_FINANCE_<SRC>` | off, read, record | off |
| API env, per source | `ARASYA_SOURCE_INVOICE_<SRC>` | off, observe, enforce | off |
| API env, per source | `ARASYA_SOURCE_PAYMENT_GATE_<SRC>` with `_VERIFIED_<SRC>` equal to it | off, … | off |
| API env | `ARASYA_CANCELLATION_SYNC` | off / on | off |
| API, per source | Confirmare cohort activation (`bin/workflow-cohort.php`) | refused unless `--readiness` passes | not activated |
| YD SOFT, per shop | `wp yd-arasya access …`, `finance-mode`, `invoice mode` (`enforce` needs `--accountant-approval`) | off, observe, enforce | off |
| YD SOFT, per shop | `wp yd-arasya invoice proforma-bank …` (company, SMART, ARS, ING) | set by an operator | not set |
| YD SOFT, per shop | Confirmare mode, cancellation sync option | off | off |
| Arasya, per employee | invoice scopes `issue`, `manage` (`PUT /management/employees/{id}/invoice-scopes`, root only) | granted to named people | none |

**Confirmare readiness per source** (`php bin/workflow-cohort.php --readiness <source>`, read-only; the activation runs
the same checks and refuses with `CONFIRMARE_NOT_READY` and the failed list). Required:

| Check | Passes when |
|---|---|
| `schema` | 035 to 039 and 042 applied; 041 too when invoicing is not `off` |
| `permitted` | `ARASYA_SOURCE_CONFIRMARE_<SRC>` permits Confirmare for the source |
| `ingesting` | the source is active (real ingestion) |
| `operators` | at least one working employee with the Confirmare `operate` scope for the source |
| `source_access` | access `read` or `edit` with URL and key |
| `finance` | finance `record` |
| `finance_four_eyes` | one person holds finance `record` and a different person holds `approve` for the source |
| `documents` | at least one working employee with the document `operate` scope for the source who may also generate documents |
| `invoicing` | invoice `off`, or `observe`/`enforce` with 041 applied and at least one person holding `issue` or `manage` |
| `connector` | 042 applied and the shop's report at most 15 minutes old, from YD SOFT 2.19.0 or later, its schema ready, its access mode at least Arasya's, finance `record` when Arasya records; with invoicing on, the same invoice mode and `smartbillReady`; with `enforce`, also the accountant attestation |

Supervisors, controlled edits and the Staff release are reported (INFO), not required. Outlet Perdele stays not ready
as long as its switches stay off.

## 8. Deploy order (each step a separate approval; GO only when the step's precondition is verified)

| Step | What | Precondition | Today |
|---|---|---|---|
| 0 | The commits of the commit plan (safety, then SmartBill, then readiness) on the integration branches, pushed as draft PRs | owner approval of the commit plan | **NO-GO** (not approved) |
| 1 | Staff CI green on the integration branch; yd-soft CI result | staff CI runs; yd-soft billing fixed | **NO-GO** (yd-soft billing) |
| 2 | Section 4 accepted on both shops, L17 recorded; live backup taken and restored once; B8 timed on that copy | owner approval of the read-only checks | **NO-GO** (not run) |
| 3 | **API 2.45.0** through the owner's usual release path (applies 033 to 042). Then `GET /health` shows 2.45.0, `migration-status` shows nothing pending, `bin/readiness.php` passes. Every switch stays off | steps 0 to 2 | **NO-GO** |
| 4 | **Staff 2.35.0** right after the API. Smoke check: login, the existing queues, no new screen visible without scopes | step 3 | **NO-GO** |
| 5 | **YD SOFT 2.19.0 on Trend Home only**, from a zip built locally with `.github/scripts/build-zip.sh` from the reviewed commit and installed by hand (no release, no `master` push; automatic updates stay off; Outlet Perdele stays on 2.9.1). Then section 4 A again, `wp yd-arasya invoice status` shows every mode off | step 4; L2 PASS | **NO-GO** |
| 6 | Trend Home: `proforma-bank` with the approved values, `invoice live-check --offline`, then without `--offline` | step 5 | **NO-GO** |
| 7 | The ARSTEST test (live-verification §4) | step 6; its own approval | **NO-GO** |
| 8 | Trend Home only, one step per working day: access `read`, finance `read`, invoice `observe` | step 7 | **NO-GO** |
| 9 | Trend Home: finance `record`, the scopes of section 12, invoice `enforce --accountant-approval`, then `--readiness` PASS and Confirmare activation | B1 answered; B9 decided; step 8 without unexpected error | **BLOCKED** (B1) |
| 10 | Outlet Perdele repeats steps 5 to 9 | the Trend Home pilot accepted in writing | **NO-GO** |

The zip of step 5: `.github/scripts/build-zip.sh` packs only files git tracks (`git ls-files`). Build it from the
committed, reviewed commit in a clean checkout, never from a working tree with uncommitted files (found 2026-10-25: a zip
of the uncommitted integration tree lacked the new classes and the connector failed with `Class ... not found`).

## 9. Rollback

- **Fastest, per shop, no deploy:**
  - `wp yd-arasya invoice mode off --yes` stops every invoice action and the AWB shipping gate.
  - `wp yd-arasya access finance-mode off --yes` stops the finance actions.
- **Per source, in Arasya:** set `ARASYA_SOURCE_INVOICE_<SRC>` (or `_ACCESS_`, `_FINANCE_`) to `off`; suspend the
  cohort with `bin/workflow-cohort.php --source=<key> --suspend` (see `confirmare-cohort.md` for what happens to orders
  already in Confirmare).
- **Code rollback:** run the current release's `php bin/check-rollback.php <target>` first; it is read-only. Floors:
  - 2.45.0 while an invoice action is pending or unknown, a routed order is not yet invoiced, or proforma, total or
    advance work exists on an order without its final invoice;
  - 2.42.0 while a Confirmare order is open or a finance action is pending or unknown;
  - finished history and empty tables add no floor;
  - the checksums of 041 and 042 are verified when recorded.
  Below the floor a rollback is refused. Tested on MySQL 8.4 (`tests/mysql-smartbill-rollback-integration.php`).
- YD SOFT goes back by installing the previous release zip; its options stay and are ignored by older versions.
- **SmartBill:** Arasya never deletes a SmartBill document. A wrongly issued final invoice is handled in SmartBill by
  the accountant (storno only, once e-Factura has validated it).

## 10. Open owner decisions: TBI and refunds (not decided here)

**B9, TBI (`tbi_bank`, `tbiro`, `tbiirisro`).** What the code does, verified by the YD unit tests (`tests/payment`):
the TBI plugin records only the credit decision (`_tbi_checkout_status`; "Approved" is not money received), so a TBI
order is `unverified`, is not cash on delivery and never clears the Confirmare payment condition. A Trend Home TBI order
under Confirmare would stay in Confirmare without reaching production. The live evidence of 2026-10-13 shows no TBI order
past `processing`; L14 records whether TBI is enabled today. Options for the owner:
- (a) TBI off at checkout on Trend Home for the pilot (WooCommerce setting, owner's action);
- (b) TBI stays on; such orders wait in Confirmare and are handled by hand outside Arasya;
- (c) a later, separate phase that defines proof of a TBI disbursement (needs TBI's documentation and the
  accountant's view). Nothing of (c) is built.

**B10, refunds.** What the code does: no electronic refund (Netopia stores no transaction id; TBI is credit); a refund
is paid by bank transfer outside Arasya and then recorded by one finance employee and verified by another
(`wc_create_refund` with `refund_payment => false`, no restock). A refund above the order total and a refund of a
cancelled order are refused and reconciled by hand. Money already received is never deleted or rewritten: entries are
added, rejected or verified, never removed. Decisions for the owner: who pays out refunds and the evidence required;
whether WooCommerce sends its "refunded" e-mail; how refunds of cancelled orders are recorded.

## 11. Evidence (local, TEST ONLY, 2026-10-25)

- Pilot simulation (`yd-soft/tests/wp-smoke/run-confirmare-pilot.sh`): Trend Home activated through its readiness (no
  test override), 1000 → 1200 lei, advance 360 lei, transfer 300 lei recorded and verified by two people, accepted,
  routed, QR scanned, one ARS proforma of 360 lei and one SMART invoice of 1200 lei without payment or payment link,
  courier COD 900.00 lei; Outlet Perdele stays legacy and invisible to Trend Home.
- Every suite of the API CI job on MySQL 8.4 and MariaDB 10.11, Staff `pnpm verify`, YD unit suites on PHP 7.4, 8.4
  and 8.5, invoice and source smokes, mutation sets. The exact results are in the handoff report.

## 12. Trend Home pilot: acceptance criteria

**Before the pilot starts:**
- B1 has the accountant's written answers, and the reference is recorded with `--accountant-approval`.
- `bin/workflow-cohort.php --readiness trendhome` passes.
- Section 4 is accepted on Trend Home.
- The ARSTEST test matches.
- B9 is decided.
- Named people hold the scopes: at least one invoice issuer, and two finance employees, because verification needs a
  second person.

**During the pilot (invoice `enforce`, the first 10 Confirmare orders of Trend Home, at most 10 working days):**
- Every order has at most one ARS proforma and exactly one SMART final invoice; no duplicate document in SmartBill.
- For every order, T = N + K holds: the total equals the verified advance plus the FAN Courier cash-on-delivery
  amount.
  - The Arasya money card, the AWB cash-on-delivery amount and `GET /invoice/paymentstatus` `unpaidAmount` agree.
- Every advance is posted once in SmartBill, through the one channel the accountant chose.
- No order shows `shipping_mismatch` at proforma, invoice or AWB time without an operator correction.
- Every final invoice reaches e-Factura status "Validată" within one working day. The daily control of "Cu eroare"
  and old "De trimis" invoices is done and recorded.
- No action stays `unknown` longer than one hour without a recorded resolution.
- No customer receives an e-mail from SmartBill (automatic e-mail stays off).

**Stop at once and switch invoice mode `off` on Trend Home** if any of these happens:
- a duplicate document;
- money posted twice;
- an e-Factura error not fixed within one working day;
- a wrong cash-on-delivery amount on an AWB;
- an unexplained difference between Arasya and SmartBill.

**Accepted when** the 10 orders are closed (delivered and invoiced), every criterion above is met, and the owner and
the accountant sign the result. Only then does Outlet Perdele start (section 8, step 10).
