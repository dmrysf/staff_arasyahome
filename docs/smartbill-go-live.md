# SmartBill go-live: the one deployment file (API 2.45.0, Staff 2.35.0, YD SOFT 2.19.0)

Status 2026-10-25: preparation only. Nothing in this file was run against a live system. Every step needs the owner's
explicit approval, given step by step. The accountant's decisions stay **BLOCKED** until the accountant answers; this
file never assumes them.

Related: `smartbill-invoicing.md` (what the feature does), `smartbill-live-verification.md` (the read-only checks, the
SmartBill posting procedure, the controlled ARSTEST test), `smartbill-master-scenarios.md` (the scenarios).

## 1. Where things stand

**Code (local, not committed):**
- `staff_arasyahome`, branch `feature/smartbill-invoicing`: local commits `fe0b2b9` and `6921278` on top of `c36f388`
  (the head of draft PR #90, `integration/cancellation-confirmare`), plus the uncommitted 2.44/2.45 work.
- `yd-soft`, branch `feature/smartbill-invoicing`: local commit `b057068` on top of `1127f20` (the head of draft PR #21,
  `integration/yd-2.16.0`), plus the uncommitted 2.18/2.19 work.
- The safety fixes approved on 2026-10-23 are local commits on their own branches and are **not** in the SmartBill
  branches: staff `5d62a60`, `703f748`, `4016a2e`, `a453842`, `e95c68c`; yd-soft `2241fc5`, `53cde94`, `ed47657`,
  `bbb80f7` (section 3, B3).

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
| Safety fixes (rules 1 and 2, contract, pickup window, texts) | local commits listed above | in place | texts | none |
| SmartBill invoicing | fe0b2b9, 6921278, uncommitted | 2.43.0 to 2.45.0 | 2.33.0 to 2.35.0 | 041 |
| YD SOFT | #13 to #20 via #21, b057068, uncommitted | | | (WordPress options and two tables of the access schema) |

- Migration 040 is reserved for unrelated work and does not exist.
- 032 belongs to the factory simulation draft (#64) and is not part of this release.
- The Dashboard changes of the cancellation stack (PRs #16 and #17, local commit `2cbb431`) are a separate approval.

## 3. Blockers before any deploy

| # | Blocker | State | What clears it |
|---|---|---|---|
| B1 | VAT on the advance (Codul fiscal art. 282(2)(b)), the invoice by the 15th of the next month (art. 319(16)), a separate advance invoice or not, the SmartBill posting channel (A or B) and collection type, the cash advance link | **BLOCKED (accountant)** | written answers of the accountant |
| B2 | `ConfirmationGate::WORKFLOW_READY` is `false`: no source can activate Confirmare (`bin/workflow-cohort.php` refuses), and invoicing acts only on Confirmare orders | **BLOCKED (owner, code)** | a separate, approved change that sets it, with its own tests; not built |
| B3 | The safety commits are not in the SmartBill branches. Applying the SmartBill work on top of them conflicts in staff `domain/confirmareFinance.ts`, `i18n/messages/confirmareFinance.ts`, `SourceFinance.php`, `mysql-confirmare-finance-integration.php`, `tests/confirmare-finance.test.ts`. In yd-soft, `ed47657` and `53cde94` conflict with `2241fc5`, and `FinanceActions.php` conflicts with the SmartBill work | **open** | one integration branch per repository (safety commits first, then the SmartBill commits), conflicts resolved by hand, every suite run again |
| B4 | `bin/check-rollback.php` knows no invoice state: its highest floor is 2.42.0. A rollback below 2.45.0 with invoice actions or proforma records leaves the shop's invoice state without an API that reads it | **open** | proposed: floor 2.45.0 when `order_invoice_actions` has rows or any source has invoice mode `observe`/`enforce`; not built |
| B5 | A push to yd-soft `master` builds a release at once (version from the header, 2.19.0), and both shops see it in their update screen. The GitHub Actions billing block stops CI and releases | **open** | billing fixed; YD SOFT not on WordPress automatic updates on either shop (live check `yd_soft_auto_update_off`) |
| B6 | Real SmartBill answers (create, PDF, invoiced-from-proforma, cancel) | **NOT VERIFIED** | the ARSTEST test (live-verification §4) |
| B7 | e-Factura: SPV connection and automatic sending on the live SmartBill account | **NOT VERIFIED** | live-verification §2 and §3 C |
| B8 | Migrations 033, 035, 036 (twice), 038 and 039 rebuild `operational_orders` by copy; their duration on the live table size is unknown | **NOT VERIFIED** | a fresh database backup and a timed run on a copy of the live database (no live write) |
| B9 | TBI orders never clear the Confirmare payment check (they would wait forever) | **owner decision** | TBI off at checkout on the pilot shop, or held orders accepted |
| B10 | Refunds stay manual (bank transfer, then the two-person record); refunds of cancelled orders are refused | **owner decision** (kept) | none for the pilot |
| B11 | Sinhala texts of the new Staff screens | **review** | a native reader |

## 4. Live compatibility check (one plan for both shops, read-only)

Run in this order, on Trend Home first and then Outlet Perdele. The commands, what they read, what they need and what
they can change are in `smartbill-live-verification.md` §3. Results are written next to each line. Nothing has been
run: every line is **NOT VERIFIED**.

| # | What | Trend Home expected | Outlet Perdele expected | Means |
|---|---|---|---|---|
| L0 | Must-use plugins, WP-CLI and PHP versions, `DISABLE_WP_CRON` in wp-config | listed | listed | shell, read-only |
| L1 | Active versions: WordPress, PHP, WooCommerce, YD SOFT, SmartBill, NETOPIA | YD SOFT 2.9.1, SmartBill 3.4.10 | same | A `versions`, `smartbill_plugin_3_4_10` |
| L2 | YD SOFT not on automatic updates | PASS | PASS | A `yd_soft_auto_update_off` |
| L3 | HPOS on or off, sync with posts | recorded (INFO), then fixed as `hpos=` | same | A `hpos` |
| L4 | Source key, delivery mode, authority modes, Confirmare mode, cancellation sync, last connection | `trendhome`, ingestion, authorities enforce, Confirmare absent | `outletperdele`, same | A `arasya_source` |
| L5 | SmartBill company in the plugin, credentials present | `RO42340172_subsidiary_1` (to confirm per shop) | to confirm | A `company_in_plugin`, `smartbill_connected` |
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

The check is accepted when every line has a recorded result and A and B end with PASS on both shops (or each FAIL has
an owner decision written next to it).

## 5. Migrations

- Applied only by `bin/migrate.php` during the API deploy, in order: 033, 034, 035, 036, 037, 038, 039, 041.
- They are never run by hand on the live database.
- Before the deploy:
  - Take a full backup of the live database, kept outside the server.
  - Time the run on a copy of it (B8).
  - Run `php bin/migration-status.php` in the installed release (read-only).
- 033 and 034 are resumable after an interrupted run (guarded, c36f388); 035 to 039 and 041 are resumable as well.
- 041 creates five tables with `CREATE TABLE IF NOT EXISTS`; it grants nothing, writes no row and alters no other table.
- Rollback never drops a table: the new tables stay, unused by older releases.

## 6. Compatibility between the layers

| Combination | Behaviour |
|---|---|
| API 2.45.0 with YD SOFT 2.9.1 | Works with every new source setting off: the API calls a shop only when `ARASYA_SOURCE_ACCESS_<SRC>` and its URL and key are set. With them set, a shop without the routes answers 404, the API reports `SOURCE_ERROR` and changes nothing (fail closed). |
| YD SOFT 2.19.0 with API 2.34.x | Not planned: deploy the API first. Every YD SOFT 2.19.0 mode is off after installation (`access`, `finance`, `invoice`, Confirmare, cancellation sync). |
| Staff 2.35.0 with API older than 2.45.0 | Not allowed: the new screens call routes that do not exist. Deploy Staff right after the API. |
| Staff 2.26.x with API 2.45.0 | Works for the short time between the two deploys (older screens use older routes). |
| SmartBill plugin other than 3.4.10 | The adapter refuses (`SmartBillAdapter` is pinned to 3.4.10); invoice actions report the plugin as not supported. |

## 7. Switches (all off at deploy)

| Where | Switch | Values | At deploy |
|---|---|---|---|
| API env, per source | `ARASYA_SOURCE_CONFIRMARE_<SRC>` | off / on (needs B2) | off |
| API env, per source | `ARASYA_SOURCE_ACCESS_<SRC>` (+ `_URL_`, `_KEY_`) | off, read, edit | off |
| API env, per source | `ARASYA_SOURCE_FINANCE_<SRC>` | off, read, record | off |
| API env, per source | `ARASYA_SOURCE_INVOICE_<SRC>` | off, observe, enforce | off |
| API env, per source | `ARASYA_SOURCE_PAYMENT_GATE_<SRC>` with `_VERIFIED_<SRC>` equal to it | off, … | off |
| API env | `ARASYA_CANCELLATION_SYNC` | off / on | off |
| API code | `ConfirmationGate::WORKFLOW_READY` | false | false (B2) |
| YD SOFT, per shop | `wp yd-arasya access …`, `finance-mode`, `invoice mode` | off, observe, enforce | off |
| YD SOFT, per shop | `wp yd-arasya invoice proforma-bank …` (company, SMART, ARS, ING) | set by an operator | not set |
| YD SOFT, per shop | Confirmare mode, cancellation sync option | off | off |
| Arasya, per employee | invoice scopes `issue`, `manage` (`PUT /management/employees/{id}/invoice-scopes`, root only) | granted to named people | none |

## 8. Deploy order (each step a separate approval)

1. Section 4 accepted on both shops; the backup of the live database taken; B3 and B4 closed; CI green on the
   integration branches.
2. **API 2.45.0** through the owner's usual release path. It applies 033 to 039 and 041. Then `GET /health` shows
   2.45.0, `migration-status` shows nothing pending, and `bin/readiness.php` passes. Every switch stays off.
3. **Staff 2.35.0** right after the API. Smoke check: login, the existing queues, no new screen visible without scopes.
4. **YD SOFT 2.19.0** on **Trend Home only** (manual update; Outlet Perdele stays on 2.9.1). Then section 4 A runs again,
   and `wp yd-arasya invoice status` shows every mode off.
5. On Trend Home: `wp yd-arasya invoice proforma-bank …` with the approved company, SMART, ARS and ING values; then the
   live check with `wp yd-arasya invoice live-check … --offline`, then without `--offline`.
6. The ARSTEST test (live-verification §4), with its own approval.
7. Stepwise switches for Trend Home only: access `read` (API and shop), then finance `read`, then invoice `observe`.
   Each step runs at least one working day without an unexpected error.
8. Confirmare activation for Trend Home (needs B2), then finance `record` and invoice `enforce` for the pilot (section
   10).
9. Outlet Perdele repeats steps 4 to 8 only after the Trend Home pilot is accepted in writing.

## 9. Rollback

- **Fastest, per shop, no deploy:**
  - `wp yd-arasya invoice mode off --yes` stops every invoice action and the AWB shipping gate.
  - `wp yd-arasya access finance-mode off --yes` stops the finance actions.
- **Per source, in Arasya:** set `ARASYA_SOURCE_INVOICE_<SRC>` (or `_ACCESS_`, `_FINANCE_`) to `off`.
- **Code rollback:**
  - Run the current release's `php bin/check-rollback.php <target>` first; it is read-only.
  - The floor rises to 2.42.0 as soon as a Confirmare order is open. Below that floor, a rollback is refused.
  - B4 adds 2.45.0 once invoice state exists.
  - YD SOFT goes back by installing the previous release zip; its options stay and are ignored by older versions.
- **SmartBill:** Arasya never deletes a SmartBill document. A wrongly issued final invoice is handled in SmartBill by
  the accountant (storno only, once e-Factura has validated it).

## 10. Trend Home pilot: acceptance criteria

**Before the pilot starts:**
- B1 has the accountant's written answers.
- B2 is approved and deployed.
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
the accountant sign the result. Only then does Outlet Perdele start (section 8, step 9).
