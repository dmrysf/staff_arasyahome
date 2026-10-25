# Business rules — final (dual-store release, 2026-10-25)

Release: Operations API 2.46.0 (migration 043), Staff 2.36.0, YD SOFT 2.20.0.
Source of the rules: the owner's master brief of 2026-10-25 (decisions K-01 to K-17 and sections 3.1 to 3.30), which
overrides earlier technical designs where they conflict. Each rule names where it is enforced and how it is tested.
"Server" means the Operations API; "shop" means YD SOFT inside the WooCommerce site. Staff only presents; every rule is
checked again on the server and, for shop data, in the shop.

Status words: **DONE** (implemented and covered by a local test that ran), **MANUAL** (a person does it outside Arasya;
Arasya records the result), **EXTERNAL** (needs a live system or a document this release cannot verify),
**UNCHANGED** (kept as it was, by decision).

## 1. The 17 decisions

| # | Decision | How it is implemented | Status |
|---|---|---|---|
| K-01 | TVA normal (not TVA la încasare) | No tax logic changes. The live SmartBill company settings were not readable (no server access): checking the VAT regime in SmartBill Cloud stays on the go-live checklist. | EXTERNAL |
| K-02 | One full SMART invoice per order; the advance is a collection on it; COD = the rest | Unchanged from API 2.45.0 / YD 2.19.0: `MoneyPosition` K (total − verified money) is the one calculation; the invoice action issues one document per order (idempotent, shop lock, plugin-document check). 2.20.0 also takes the AWB collection from the same K (section 3.27). | DONE |
| K-03 | SmartBill through the shops' existing, authorised plugin connection; no token shown anywhere | Unchanged: the SmartBill 3.4.10 plugin's own client in the shop (`SmartBillAdapter`); the token never leaves the shop. New calls: `document/send` for the proforma reminders; success read from `status.code === 0`. | DONE (code) / EXTERNAL (live call) |
| K-04 | e-Factura followed by the shop employee who issued the invoice | New `invoice_efactura_tasks` (043): one task per final invoice, due 5 working days later (Bucharest calendar, Romanian legal holidays 2026–2027), queue `/confirmare/efactura` for the issuer and the shop's invoice managers; states open → sent → accepted / rejected → resolved, recorded by the employee after checking SmartBill Cloud or SPV. Nothing is set automatically: SmartBill's API gives no e-Factura status. | DONE |
| K-05 | Day-5 bank check before the automatic proforma cancellation | `confirmare_bank_checks` (043). An expired cycle waits (`waiting_bank_check`) until a finance employee records a bank check covering the expiry instant; a payment being verified keeps it waiting (`waiting_finance`); a verified payment closes it as paid (no cancellation). | DONE |
| K-06 | Orders from 22 October 2026 enter the new system; old documents and payments kept; no mass cancellation or back-dated e-mails | Two steps, both read-only by default: `wp yd-arasya backfill` (shop) queues the window's orders that never reached Arasya as normal signed snapshots; `bin/confirmare-backfill.php` (server) takes those still before production into Confirmare (reason `backfill_22_october`). Orders in production keep their stage and documents. No proforma cycle starts for them (cycles start only from a recorded third unanswered call or a proforma sent from now on). See BACKFILL-22-OCTOBER.md. | DONE |
| K-07 | A real payment after the second cancellation is accepted; the operator calls again; prices and sizes re-checked | Reactivation: the first ordinary reactivation needs no payment; any later one only with a verified bank exception entry of a finance employee of the shop (`payment_required`, `finance_scope_required`). A reactivated order returns to Confirmare `pending`, assigned to the reactivating operator, who must call and approve again (new review fingerprint). The old proforma is never made valid again. | DONE |
| K-08 | NETOPIA on both sites; plugins and API versions verified separately | The reviewed plugin is the official NETOPIA WooCommerce plugin (copy reviewed 2026-10-23). The live plugin versions of each site were not readable (no server access). | EXTERNAL |
| K-09 | Full NETOPIA refund by the operator, one click, no second confirmation window; backend checks kept | `POST …/card-refund` (operator or supervisor of the order holding the finance `approve` scope). NETOPIA has no refund API (official docs and plugin, NETOPIA-REFUND-VERIFICATION.md), so the click creates one **manual** refund task in the shop (`manual_required`); a second click creates nothing; finance records the refund done in the NETOPIA panel and a different finance employee verifies it; only then is the WooCommerce refund written (no gateway call) and the customer e-mailed in Romanian. | DONE (manual path) |
| K-10 | Staff button: full refund only; partial refunds manually in NETOPIA, reconciled in Staff | No amount choice on the button. A partial refund is done in the NETOPIA panel and recorded through the existing finance refund (submit + another employee's decision) or the card refund record with the real amount. | DONE |
| K-11 | Several card captures: order-level full refund; each transaction refunded; partial success handled | The request names the number of NETOPIA transactions (IPN notes). With one transaction its amount is known; with several, finance reads each one in the panel and records one entry per transaction. The request is complete only when every transaction has a verified entry; before that Staff shows "partly: x of n". The customer e-mail goes once, at completion. | DONE |
| K-12 | TBI out of scope; existing TBI flow untouched | A TBI order (`tbi_bank`, `tbiro`) is classified legacy (`financing_legacy`) and never fenced by Confirmare; no TBI code path changed. | DONE |
| K-13 | One FAN Courier account for both shops; rebuild the AWB if unhealthy | Audit: the YD SOFT generation path is sound (single path, lock, duplicate guard, journal, costCenter reconciliation) but its collection was wrong for Confirmare orders (order total − deposit, COD method only). 2.20.0: Staff AWB from stage 12 through the same single path, the collection = MoneyPosition K once per AWB whatever the parcel count, the WordPress buttons refuse Confirmare orders once `awb enforce`. See FAN-COURIER-VERIFICATION.md. | DONE (code) / EXTERNAL (live API) |
| K-14 | Urgent address change: API if safe, otherwise FAN panel + verification in Staff | FAN Courier API v2.0 has no AWB update endpoint. A task (`awb/address task`) with a short reason, then the employee records the change made and checked in the FAN panel (`done`, shown as the employee's declaration). No address text is stored in Arasya. | DONE (manual path) |
| K-15 | Uninterrupted go-live: shops keep taking orders; no loss, no duplicate | Every new feature is off at deploy (API: tables unused until used; YD: `operations` switches off, invoice/finance/access modes unchanged). Order ingestion is unchanged (outbox + retry). New actions are idempotent per key, journaled before they are sent, settled by status routes after a lost answer. See DUAL-STORE-GO-LIVE-RUNBOOK.md. | DONE (design + local tests) |
| K-16 | Fast go-live with the existing credentials | Uses the shops' existing SmartBill and FAN Courier plugin settings; no new credential is needed for this release. The live check could not be done (no server access). | EXTERNAL |
| K-17 | Broad technical authority, not financial authority | Commits and feature-branch pushes done; no merge to `main`/`master`, no release, no deploy, no live data change, no payment, refund, invoice, e-mail or AWB on a real order. | — |

## 2. Merged operating rules (brief section 3)

| § | Rule | Where | Status |
|---|---|---|---|
| 3.1 | Both shops together, no pilot, no first-10 limit; from 22.10.2026 00:00 Europe/Bucharest; 14 stages kept | Backfill window computed in Europe/Bucharest (tested across the 2026-10-25 DST change); no stage changed | DONE |
| 3.3 | Calls recorded one by one; at most 3 unanswered per cycle; never three at once; never deleted | `ConfirmareCycles::assertNoAnswerAllowed`: the 4th `no_answer` of a cycle is refused (`CONFIRMARE_CALL_CYCLE_COMPLETE`); two `no_answer` closer than 10 minutes are refused (`CONFIRMARE_CALL_TOO_SOON`); records are append-only | DONE |
| 3.4 | After 3 unanswered calls: eligibility check; 30 % ARS proforma e-mailed; shown as not confirmed by phone; production still needs the phone confirmation | The third `no_answer` starts a cycle; the proforma is issued with `unconfirmed: true` (the shop's note "NU este confirmată telefonic") and sent through SmartBill; the Confirmare approval still needs a confirmed call | DONE |
| 3.5 | Timer from the real send; reminders at 24 h and 72 h; 120 h check and cancel; each once across workers; bank check; real SmartBill cancel then the WooCommerce cancel as separate steps | `bin/confirmare-cycles.php` (cron every 5 min, advisory lock, per-row claim, one idempotency key per step attempt, lost answers settled by status); proforma cancel (`expired_unpaid`) then the order cancellation request through cancellation sync; a failed second step keeps the first and is retried/reconciled | DONE |
| 3.6 | "Customer came back" once without payment; paid exception with full evidence | Reactivation journal `confirmare_reactivations` (sequence, kind, reason, employee, time, exception entry id); see K-07 | DONE |
| 3.7 | Late payment on a cancelled proforma: recorded, never lost, never twice; finance exception | Unchanged from 2.45.0 (transfer exceptions, `cancelled_proforma` flag by finance decision) | DONE |
| 3.8 | Bank matching suggestion is not proof; two different people verify | Unchanged (four eyes in finance; the card refund verification enforces it too) | DONE |
| 3.9 | Advance below 30 % only by explicit operator decision; below half needs a reason | Unchanged from 2.45.0 (`advance_accept`) | DONE |
| 3.10 | Proforma replaced when the total changes before payment | Unchanged from 2.45.0; a cycle closes as `proforma_replaced` | DONE |
| 3.11 | Only the routed shop employee invoices; one document; no double click; plugin-made document detected | Unchanged from 2.45.0 | DONE |
| 3.12 | TVA normal, document timing; stop only the unverified financial action | No change; invoice actions refuse with a reason instead of issuing a wrong document | DONE / EXTERNAL (accountant) |
| 3.13 | SmartBill collection on the invoice; no second collection | Unchanged from 2.45.0 (see SMARTBILL-VERIFICATION.md) | DONE (code) / EXTERNAL |
| 3.14 | Romanian e-mail with the invoice, total, paid and remaining | Unchanged from 2.45.0 (SmartBill `document/send` of the plugin) | DONE (code) / EXTERNAL (PDF template) |
| 3.15 | e-Factura task for the issuer; no invented status; "PDF sent ≠ ANAF accepted" shown | K-04 | DONE |
| 3.16 | Card payment revised upwards: extra proforma or COD for the difference; no forced wait | MoneyPosition K; the AWB collects the difference whatever the payment method (fixed in 2.20.0: before, a card order collected 0) | DONE |
| 3.17 | Full bank transfer: COD 0 | MoneyPosition K = 0; the AWB collects 0 | DONE |
| 3.19 | Advance kept after a customer cancellation in production: manager decision | Unchanged (cancellation control, manager decision) | UNCHANGED |
| 3.20 | Sales contract version and acceptance kept with the order | Not changed in this release (existing order snapshot keeps the terms acceptance the shop sends) | UNCHANGED / EXTERNAL |
| 3.21 | Full NETOPIA refund checks (order, permission, captured payment, transaction id, refundable amount, earlier refunds, no duplicate, no concurrent duplicate, unknown never retried) | Shop: NETOPIA payment method, IPN transaction ids, `card_refundable` (captured − explained refunds), one open request, finance lock, ledger reference hash; server: role check, idempotency key + request HMAC, journal before send, status route after a lost answer | DONE (manual path) |
| 3.22 | Several transactions, partial success recorded, never the same amount twice | K-11 | DONE |
| 3.23 | Partial refund manual | K-10 | DONE |
| 3.24 | TBI untouched | K-12 | DONE |
| 3.25 | FAN Courier: AWB, pickup and delivery are different states | Staff shows the AWB state, "pickup tracked separately" and delivered as separate facts | DONE |
| 3.26 | Stage 12 AWB queue for the shop employee; refused at stage 11; listed checks | `/confirmare/awb-queue`, `stage_not_ready` below 12; shop checks invoice, payment verifying, finance attention, address, telephone, shipping, earlier AWB, COD | DONE |
| 3.27 | Several parcels: the collection once | `packages.parcel = N`, one `cod` per AWB (FAN API v2.0) | DONE |
| 3.28 | Address change only by an authorised person with a reason; never "fixed" before FAN changed it | K-14 | DONE (manual path) |
| 3.29 | Staff cancellation written back to the shop safely | Unchanged (cancellation sync, 2.35.0) | UNCHANGED |
| 3.30 | 14-stage factory unchanged | No stage, QR or physical workflow change; the full production suites ran again | DONE |

## 3. Permissions (brief section 4)

| Role | Holds | New in this release |
|---|---|---|
| Operator | Confirmare `operate` scope of the shop | calls, proforma cycle "run now" (own order), reactivation (own order) |
| Supervisor | Confirmare `supervise` | the same on every order of the shop; health screen |
| Finance (record / approve) | finance scopes | bank check (record or approve), card refund record (record), card refund verify (approve, never the recorder) |
| Operator with finance `approve` | both scopes | the one-click NETOPIA full refund of their order |
| Shop employee | invoice `issue` + document `operate` + routed to the order | AWB, label, address task, e-Factura of their invoices |
| Invoice manager | invoice `manage` | AWB and e-Factura of the whole shop; health screen |
| Root | break-glass | sees health; never acts as a shop employee |

Hiding a button is never the authorisation: every route checks identity, scope, source, order state and transition.
