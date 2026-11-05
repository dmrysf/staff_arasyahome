# ADR: ING automatic advance reconciliation for NEW proformas only

Status: **PROPOSED / non-operational**. Initial branch carries documentation only. No new bank access, automatic settlement, migration, or feature activation has been performed.

Related issue: https://github.com/dmrysf/staff_arasyahome/issues/114
Owner request date: 2026-11-05 (Europe/Bucharest).

## Context and non-negotiable user constraint

Trend Home and Outlet Perdele sell made-to-measure curtain products through WooCommerce. Operators use Staff Confirmare to issue SmartBill advance proformas, and current finance staff manually verify bank transfers. Owner has switched the SmartBill displayed bank for **new** proformas to ING and connected ING to SmartBill.

Historical proformas were issued with Libra and remain payable through their existing manual workflow. **Cristina Nita has assigned, unfinished finance tasks that must continue unchanged.** Enabling ING bank connectivity must NEVER silently move, auto-decide, or hide existing tasks.

Target: if a new eligible advance proforma requests 300 RON for a 1000 RON order, a settled and uniquely matched ING bank credit of 350 RON automatically results in a verified 350 RON advance and 650 RON COD remaining, with authoritative calculation at the shop; no human finance confirmation for strictly safe, separately approved machine-verified matches. 300 RON receipt -> 700 RON remaining; 250 RON -> partial 250 RON, 750 RON remaining but production still blocked absent separately authorized lower-advance acceptance. Amounts are integer bani; no float accounting.

## Existing ownership boundaries (keep them)

- `ConfirmareInvoice.php`: proforma issuance and revision/identity; `ConfirmareCycles.php`: no-answer and reminder/expiry lifecycle.
- `FinanceVerificationRequests.php`, `FinanceTaskScope.php`, `FinanceTaskAccess.php`: named human finance tasks, minimal reach, four-eyes, exception and audit.
- `ConfirmareFinance.php`: signed finance action relay; `ConfirmationClearance.php`: independently recomputed production gate.
- Shop `yd-soft/includes/Integration/Arasya/SourceAccess/FinanceLedger.php` and `ConfirmareFinancial.php`: source of truth for verified receipts and COD balance, with refund/partial/pricing handling.
- Operations historically keeps finance event codes and hashes rather than customer bank details or amounts. Any new bank-data service must respect that boundary or explicitly document an approved, restricted exception.
- No source status auto-mutation, no change to production QR, existing final invoice/SmartBill/e-Factura sequence, or source customer privacy.

## Decision 1: No production access by default

Config flags per environment and per shop:
- `ing_bank_feed_enabled = false`
- `ing_reconciliation_dry_run = true`
- `ing_auto_apply_enabled = false`
- `ing_auto_cutover_at_utc = null` (not inferred from today's ING account selection)
- `ing_enabled_shop_sources = []`

Missing/invalid flags fail CLOSED. A bank feed cannot be enabled by clicking a button in the operator UI.

The future opt-in eligible set must satisfy **all** of:
1. Proforma created at or after an owner-approved FUTURE cutover timestamp; exact proforma revision is active and current; no cancelled, replaced, unknown, unpaid historical revision.
2. Proforma explicitly issued for the designated verified ING IBAN, RON, and an explicit source-specific scope (`trendhome`, `outletperdele`); unique payment reference globally across both shops and proforma revisions.
3. Confirmare order is in active eligible cohort, unchanged commercial total/fingerprint, not cancelled/closed, no open payment/invoice actions or refund exceptions.
4. **No existing human/manual finance verification task, second check, unsettled action, unknown outcome, or prior manual payment for this order.** No transition/cleanup of any historical human task is performed on cutover.
5. Authoritative source bank record is a settled credit for the correct account, with bank transaction ID, value/booking timestamps, amount, currency and reference; one stable transaction ID used at most once globally.
6. Matcher produces exactly one eligible order/proforma; collision, no reference, name/amount-only match, account mismatch or inconsistent total fail CLOSED.
7. Bank provider API/credential/consent healthy and sufficient to verify settlement; otherwise no auto-apply.

The no-legacy-task predicate is required at **commit time under locks**, not just in a read preview. Any record created during the race causes auto-apply refusal. An older Libra proforma paid on ING is NOT automatically eligible.

## Decision 2: Bank data must be independent verified evidence

SmartBill's ING connection and balance display prove a usable human UI, **not** a supported API to retrieve source bank transaction events. Public SmartBill API docs (2026-10-16) enumerate estimates, invoices, payments and document send but do not document a read-bank-transactions endpoint. SmartBill's automatic bank-to-proforma association is amount/name sensitive, and saving may create a final invoice — unsuitable as the automated verification trigger.

Investigate direct ING business account-information feed via an authorized AISP/Open Banking provider. Confirm real business account eligibility, consent flow, read-only settled transaction access, stable IDs, time-to-availability, rate limits, PII/security, pricing, and account ownership in sandbox before implementation. Alternatives: explicitly approved read-only machine-readable bank statement files (slower) with bank authentication and provenance; NOT WhatsApp screenshots, customer receipts, scraped SmartBill pages, manually asserted 'bank verified', LLM judgment, or HTML UI automation.

Official references:
- https://api.smartbill.ro/
- https://ajutor.smartbill.ro/article/1073-preluare-tranzactiilor-bancare
- https://ajutorgestiune.smartbill.ro/article/1080-asocierea-si-salvarea-tranzactiilor-preluate-din-banca
- https://ing.ro/ing-in-romania/informatii-utile/payment-service-directive-2

## Decision 3: Safe machine verification is a DIFFERENT policy, not an employee

Under existing human flows `four_eyes` remains the default. New machine evidence must never impersonate Cristina/Zelal or pass `bankStatementConfirmed: true` as a synthetic human action. Create a separately reviewed policy with:
- bank-evidence provenance and signature/checksum;
- dual bank-account and order/proforma identity constraints;
- idempotency by stable bank account+transaction identity;
- distinct `verified_by_kind = bank_automation` audit, rule version, input digest, exact bank ID/evidence hash, source, commit time;
- an atomic shop-ledger apply path with transaction locking and anti-duplication across manual and automatic paths;
- fail-closed state on timeouts, unknown outcomes, changed proforma, provider outage; never blindly retry an external payment write;
- reconciliation after uncertain outcomes, and kill switch;
- no automatic final invoice, no automatic SmartBill proforma association-save or `post/payment` absent a separately verified business design.

Bank statement data belongs in a restricted, encrypted data boundary; finance operator pages receive minimized statuses, not full-bank history. Finance tasks remain assigned and visible solely through existing scopes.

## Decision 4: Delivery semantics

A successfully verified eligible bank receipt updates the existing YD SOFT/WooCommerce financial ledger exactly once. The existing financial evaluator recomputes paid/remaining amounts; signed shop sync updates Operations; Staff shows 'paid/partial/exception', actual received amount and remaining COD. `production_cleared` still depends on phone approval, product/price checks, proof of money and all other existing gates. A bank credit alone does not approve an order.

Suggested deterministic preview matcher states: `unmatched`, `candidate_exact`, `candidate_partial`, `ambiguous`, `duplicate`, `legacy_excluded`, `conflict_manual`, `outdated_proforma`, `provider_unavailable`, `applied`. No auto-application unless all strict predicates and a separately approved machine policy hold. Track cumulative partial payments, never double-count replays; excess over order total is a review/refund-liability exception.

## Engineering stages and GO/NO-GO

### Phase 0 — NOW (safe)
- Read-only audit latest Staff/API and yd-soft; inspect proforma IBAN selection in both shops and actual configured bank source.
- Verify a bank transaction data provider and contract, with NO credentials committed; if absent remain NO-GO on automatic payment.
- Add flags OFF, isolated provider interface + fixture-only matcher, preview reports (redacted), cutover/legacy safety policy and tests on an isolated feature branch; draft PR. No deployment, no writes to live finance/order rows.

### Phase 1 — SHADOW
- Ingest historical/sandbox ING transactions into a restricted read-only inbox; no apply and no automatic payment. Compare matching to genuine finance outcomes offline. Test boundary conditions and race safety.
- Verify both stores and isolate old task list before/after snapshots (Cristina assignments, counts, versions, status, existing proforma identity). All identical.

### Phase 2 — EXPLICIT OWNER GO ONLY
- Confirm bank provider correctness, activation timestamp, shop scope, restore/rollback, migration backup, MySQL/MariaDB and HPOS/posts tests, E2E user roles/privacy, metrics, provider throttling, monitoring and manual fallback.
- Enable new issuance's automated path for a small scoped pilot; keep old tasks on their original manual route.
- No production changes without separately authorized deploy and explicit auto-apply enablement.

## Required invariant test matrix
- Existing Libra proformas + all existing named finance tasks remain untouched even if historical ING bank records exist or old debt is settled later.
- Any new proforma generated before approved cutover is excluded; an old order newly invoiced is excluded if any manual task/action exists.
- 1000 RON order/300 required: 300 credit -> 300 paid, 700 COD; 350 credit -> 350 paid, 650 COD; 250 credit -> partial, 750 COD, production remains gated; multiple credits net exactly once; 1000 credit -> 0 COD.
- 1200 credit -> review and 200 excess; not auto-issued refund.
- Same amount two customers; no/ambiguous reference; two stores and same proforma numbering; revised/cancelled proforma; wrong account/currency; fake/pending credit; duplicate provider event, replay, out-of-order event, bank outage.
- Human finance action races with auto action; no double-paid records; unknown outcome needs reconciliation; four-eyes routes continue unchanged.
- No final invoice created, no automated order state change; no finance-wide order visibility gained.
