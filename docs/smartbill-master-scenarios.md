# Master update scenarios (API 2.45.0, Staff 2.35.0, YD SOFT 2.19.0)

The owner's 100 scenarios, each mapped to the automated test that covers it. Status: **PASS** (an automated test asserts
it), **BLOCKED** (needs an accountant or owner decision; not built), **NOT VERIFIED** (depends on the live SmartBill, ANAF
or FAN Courier behavior, or on a live configuration; never tested against a real service).

Test references:
- `YD-unit money` = `yd-soft/tests/money/run.php`; `YD-unit finance` = `yd-soft/tests/finance/run.php`;
- `YD-smoke N` = `yd-soft/tests/wp-smoke/invoice.php` section N (real WordPress + WooCommerce + SmartBill 3.4.10 plugin,
  SmartBill test double, HPOS and posts, PHP 7.4 / 8.4 / 8.5); section 13 = 100 seeded synthetic COD orders;
- `API-inv` / `API-fin` = `operations-api/tests/mysql-confirmare-invoice-integration.php` / `...-finance-...` (MySQL 8.4 and
  MariaDB 10.11); `API-val` = `operations-api/tests/source-access.php`;
- `Staff` = `tests/confirmare-invoice.test.ts`; `E2E` = `e2e/zzzzzzzzzzzzzzzzzzz-invoice-real-api.spec.ts` (real API);
- `Cross` = the real-HTTP check of the API client against the TEST WordPress (scratch, not in the repositories).

| # | Scenario | Covered by | Status |
|---|---|---|---|
| 1 | 1000 lei, 300 advance | YD-unit money (owner examples), YD-smoke 13, API-val 5d | PASS |
| 2 | 1000 lei, 250 accepted | YD-unit money, YD-unit finance 10, YD-smoke 12b, API-inv 4d, E2E, Cross | PASS |
| 3 | 1200 lei, 360 advance | YD-unit money, YD-smoke 12a (advance of the new total) | PASS |
| 4 | 1200 lei, 300 accepted | YD-unit money, YD-smoke 12c (re-acceptance on the new price) | PASS |
| 5 | Full advance at once | YD-smoke 11, 13 | PASS |
| 6 | Advance in three parts | YD-smoke 12c, 13 | PASS |
| 7 | Extra payment after acceptance | YD-smoke 12c, API-fin 8c | PASS |
| 8 | Whole order paid in advance | YD-smoke 13 (kind `full`: owed 0, courier 0) | PASS |
| 9 | Metres changed before the proforma | YD-smoke 12a (changed lines, acceptance, advance on the new total); Phase 4B source-edit smokes | PASS |
| 10 | Product changed before the proforma | Phase 4B source-edit smokes (add/remove line); YD-smoke 12a (change log `added`) | PASS |
| 11 | Price changed before the proforma | YD-smoke 12a | PASS |
| 12 | After the proforma, before payment, price up | YD-smoke 10 (old proforma cancelled in SmartBill, then a new one) | PASS |
| 13 | After the proforma, before payment, price down | same code path as 12 (any change of the proforma basis); tested with an increase | PASS (path) |
| 14 | After payment, price up | YD-smoke 12c2 + 14b (final business rule 2026-10-25: never kept by itself; the operator's explicit `accept_received` keeps the money paid as the advance, no second proforma; `request_difference` asks the difference on its own proforma; zero refused; money being verified refused), 15j/15k (concurrency), API-inv 4d, Staff | PASS |
| 15 | After payment, price down | YD-unit finance 5 and 10 (decrease, liability rules) | PASS |
| 16 | After an accepted smaller advance, price up | YD-smoke 12c, YD-unit finance 10 | PASS |
| 17 | After an accepted smaller advance, price down | YD-unit finance 10 | PASS |
| 18 | Customer refuses the new price | YD-smoke 12a (no proforma, no invoice: `total_not_accepted`); Confirmare reject flow | PASS |
| 19 | Two revisions in a row | YD-smoke 12a | PASS |
| 20 | Invoice on an old revision | API review/approval staleness, `revision_changed`, `total_not_accepted` (YD-smoke 12a) | PASS |
| 21 | Unpaid proforma cancelled | YD-smoke 12e, API-inv 4d, E2E, Cross | PASS |
| 22 | SmartBill cancellation succeeds | YD-smoke 12e (one `estimate/cancel` call) | PASS |
| 23 | SmartBill cancellation refused | YD-smoke 12e, API-inv 4d | PASS |
| 24 | SmartBill cancellation timeout | YD-smoke 12e (unknown, retry, manager resolve), API-inv 4d | PASS |
| 25 | Cancel an already cancelled proforma | YD-smoke 12e (lost answer then refusal stays unknown; `proforma_missing`) | PASS |
| 26 | Cancel with a partial payment | YD-smoke 12e (`advance_payment_received`) | PASS |
| 27 | Cancel with full payment | same rule as 26 (any money received) | PASS |
| 28 | Cancel while a payment is verified | YD-smoke 12e (`payment_verifying`) | PASS |
| 29 | New proforma after a cancellation | YD-smoke 12e, E2E | PASS |
| 30 | Payment after the cancellation | YD-smoke 12f, API-fin 8c | PASS |
| 31 | 299 instead of 300 | YD-smoke 12b (10 lei short; acceptance, owed T − N) | PASS |
| 32 | 250 instead of 300 | YD-unit finance 10, API-inv 4d, E2E | PASS |
| 33 | 10 instead of 300 | YD-smoke 12c (below half: confirmation + reason) | PASS |
| 34 | Zero advance accepted | YD-smoke 12b, YD-unit finance 10, API-inv 4d | PASS |
| 35 | Negative amount | finance amount pattern (YD and API) | PASS |
| 36 | The same payment twice | YD-smoke 11 (`duplicate_reference`), API-fin | PASS |
| 37 | 1050 on a 1000 order | YD-smoke 12d, YD-unit money, API-fin 8c | PASS |
| 38 | Remaining after a partial refund | YD-unit money (N = P − I), YD-unit finance (refund rules) | PASS |
| 39 | Remaining after a full refund | YD-unit money | PASS |
| 40 | Payment on the wrong order | reject reason `wrong_order`, the unidentified transfers record (YD-smoke 11) | PASS |
| 41 | One final invoice | YD-smoke 12b, API-inv 5 | PASS |
| 42 | The same invoice twice | YD-smoke 12b (`document_exists`), API-inv 5 | PASS |
| 43 | Invoice retry after a SmartBill timeout | API-inv 6, YD-smoke (unknown outcomes) | PASS |
| 44 | Proforma already invoiced elsewhere | YD-smoke 11, API-inv 4c (`proforma_invoiced`) | PASS |
| 45 | Invoice made, e-mail failed | YD-smoke (e-mail retry), API-inv 5 | PASS |
| 46 | Invoice made, local record failed | journal written before the call → unknown (YD-smoke, API-inv 6) | PASS |
| 47 | Price revision after the invoice | document `outdated`, re-issue only by a manager (existing rules) | PASS |
| 48 | Order cancelled after the invoice | cancellation sync keeps the money record; the storno is a manual SmartBill step | PASS (no automatic change) / storno NOT VERIFIED |
| 49 | Partial collection linked to the invoice | Arasya never writes a payment to SmartBill. Official API: a payment is linked to an invoice only when it is created (`POST payment` with `invoicesList`); an existing unallocated payment cannot be re-linked by API. Manual path defined (docs/smartbill-live-verification.md §1): associate the real ING bank transaction with the SMART invoice in SmartBill › Tranzacții bancare. Accountant confirmation of the procedure pending | BLOCKED (accountant) |
| 50 | Proforma total used as the invoice total | YD-smoke 12b (invoice of the whole order, no advance line) | PASS |
| 51 | Advance at the start of the month | YD-unit money (deadline) | PASS |
| 52 | Advance at the end of the month | YD-unit money, YD-unit finance 10 (Bucharest day) | PASS |
| 53 | Advance in two months | YD-unit finance 10 (the first collection fixes the deadline) | PASS |
| 54 | Full invoice before the deadline | YD-smoke 12h (`invoiced`) | PASS |
| 55 | One day left | YD-unit money (level `critical`) | PASS |
| 56 | Deadline passed | YD-unit money, Staff (overdue finance exception) | PASS |
| 57 | Shop QR too late for the deadline | warning on the operator and finance screens; no invoicing power for the operator (API-inv) | PASS |
| 58 | A separate advance invoice is required | accountant decision; never issued automatically | BLOCKED |
| 59 | Offset of an advance invoice in the final invoice | depends on 58 | BLOCKED |
| 60 | e-Factura transmission error | SmartBill transmits (auto-send in Configurare › e-Factura); no SmartBill API exposes the SPV state, so Arasya shows `not_tracked` and the Staff note says where to check (SmartBill › Rapoarte › Facturi emise, e-Factura column); daily manual control defined (docs/smartbill-live-verification.md §2) | NOT VERIFIED (live) |
| 61 | 300 advance, 700 COD | YD-unit money, YD-smoke 13 (CodCalculator = K) | PASS |
| 62 | 250 advance, 750 COD | YD-smoke 12b, 13 | PASS |
| 63 | 300 advance, 900 COD | YD-unit money, YD-smoke 13 | PASS |
| 64 | Fully paid, 0 COD | YD-smoke 13 | PASS |
| 65 | Over-payment, 0 COD | YD-smoke 12d | PASS |
| 66 | New payment before the AWB | COD computed when the AWB is made (GenerationService payload check) | PASS (existing) |
| 67 | New payment after the AWB | YD-smoke 12i (mismatch shown and noted, AWB untouched) | PASS |
| 68 | Price revision after the AWB | same comparison as 67 (owed vs AWB collection); tested with a payment | PASS (path) |
| 69 | COD change on a delivered order | YD-smoke 12i (`delivered`, never compared again) | PASS |
| 70 | Free-shipping threshold with the remaining amount | YD-smoke 14: WooCommerce cart + checkout at 600 lei (600.00 free; 590.00, 595.00 with 15 % coupon, 599.99 not free; discounts ignored: free), proforma = ROUND(T×30 %), FAN COD = T − A, one invoice of T; Arasya edits across 600 lei need the explicit method choice. 14c (owner 2026-10-25): ShippingCheck refuses proforma, invoice and AWB on a shipping amount the configuration does not give (flat rate changed, free shipping lost outside the edit, a free shipping coupon no longer valid); 15l edits vs AWB generation. Live threshold settings: `arasya-live-readonly.php free-shipping-min=600` | PASS (local) / live settings NOT VERIFIED |
| 71 | Unauthorized operator cancels a proforma | API-inv 4d (shop employee, finance: 403), YD-smoke 12 (roles 400) | PASS |
| 72 | Unauthorized operator asks for the invoice | API-inv, YD-smoke 10 | PASS |
| 73 | Wrong shop employee QR | API-inv QR checks | PASS |
| 74 | Old QR | API-inv QR checks | PASS |
| 75 | Cancelled QR | API-inv QR checks | PASS |
| 76 | QR of another shop | API-inv QR checks | PASS |
| 77 | The same QR twice | API-inv QR checks | PASS |
| 78 | Acceptance without finance verification | YD-smoke 12b (`payment_verifying`, `advance_not_received`) | PASS |
| 79 | One person, two finance approvals | YD-smoke 11, API-fin (`same_employee`) | PASS |
| 80 | Another order's document reference | unique bank reference, `transfer_not_recorded` (YD-smoke 11, API-fin 8b) | PASS |
| 81 | Two operators issue at once | YD-smoke 15a (two PHP processes at the same instant: one proforma), 15i (the same key twice: one proforma, the same answer) | PASS |
| 82 | Cancel and resend at once | YD-smoke 15b (two cancellations at once: one SmartBill cancellation), 15h (two invoices + a cancellation at once: one invoice, the paid proforma kept) | PASS |
| 83 | Finance approval during the cancellation | YD-smoke 15c (a transfer recorded while the cancellation asks SmartBill: the race found by this test is FIXED, the cancellation re-reads the money under the finance lock and is refused), 15d (a transfer while SmartBill cancels: refused) | PASS |
| 84 | Acceptance while a new payment arrives | YD-smoke 15e/15f (two acceptances at once: recorded once); the summary revision includes every payment | PASS |
| 85 | Invoice while the order is revised | YD-smoke 15g (an edit while the proforma is issued: shown outdated, the new total needs the customer's acceptance, nothing invoiced on it); edits exist only before the Confirmare approval, the final invoice only after the handoff (API) | PASS |
| 86 | AWB made while a payment is updated | payload COD check at generation; CodCheck afterwards | PASS |
| 87 | Repeated callback / response | idempotency replay (YD-smoke 12, API-inv 4d) | PASS |
| 88 | The same payment on another order | shop-wide unique bank reference | PASS |
| 89 | Restart in the middle of an operation | `in_flight` older than 180 s is unknown (existing tests) | PASS |
| 90 | Database lost after SmartBill succeeded | journal before the call; unknown, never repeated | PASS |
| 91 | Trend Home HPOS | YD-smoke on HPOS and posts | PASS |
| 92 | Outlet Perdele HPOS | same plugin code; the TEST site runs as one shop (per-site settings are separate options) | PASS (code) |
| 93 | Conflict with the SmartBill plugin | AJAX capability guard, plugin 3.4.10 pinned (YD-smoke) | PASS |
| 94 | The normal invoice series | `invoice_series_not_approved` (YD) | PASS |
| 95 | Trendyol orders unaffected | verify:api (Trendyol suites) | PASS |
| 96 | B2B unaffected | verify:api (B2B suites) | PASS |
| 97 | Stage 2–3 handover rules | verify:api (cutting intake, handoff) | PASS |
| 98 | Tailoring supervisor and physical return | verify:api (tailoring supervision, per-piece rework) | PASS |
| 99 | Stage 13–14 rules | verify:api (production control) | PASS |
| 100 | FAN Courier duplicate AWB guard | logistics code unchanged; YD unit suites | PASS (unchanged) |

Live checks still needed (NOT VERIFIED until the owner runs them; see docs/smartbill-live-verification.md): the read-only
`wp yd-arasya invoice live-check` on both shops (company, ARS/SMART, ING account, plugin settings, 600 lei threshold), and
the controlled SmartBill test (§4: one proforma on a test series, `areInvoicesCreated` before and after, the cancellation
twice, the printed bank accounts). The official API reference already documents the idempotent cancellation (200 "este
deja anulata") and the draft-invoice and informative-errorText answers of `estimate/invoices`; the test double follows it.
