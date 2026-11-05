# ADR: ING automatic advance reconciliation for NEW proformas only

Status: **PROPOSED / non-operational. Phase 0 code (shadow only) in draft PRs, 2026-11-05.** No bank access, automatic settlement, migration, setting change or feature activation has been performed on any live system. See "Phase 0 result (2026-11-05)" at the end.

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

## Phase 0 result (2026-11-05)

Draft PRs: staff_arasyahome (API 2.58.0, this branch) and yd-soft (2.27.0, `feat/ing-advance-shadow-phase0`). Nothing merged,
deployed, migrated or activated. No migration exists in either PR.

### Bank data source: what was verified

| Path | Finding | Status |
| --- | --- | --- |
| SmartBill public API | The endpoints ARASYA uses (`estimate`, `estimate/invoices`, `estimate/cancel`, `invoice`, `invoice/paymentstatus`, `series`, `document/send`) and the public reference expose no read of bank statement lines and no incoming-payment webhook. SmartBill's own help describes bank import as a button in its UI ("Preia tranzactii", up to 90 days back) and proforma association that must be saved and generates collections. | **Rejected** as a feed (no API; association has invoice side effects; UI scraping forbidden) |
| ING PSD2 AIS (developer.ing.com/openbanking, ING Bank N.V. Bucharest Branch) | Exists for regulated TPPs (AISP licensed by BNR, eIDAS certificates). ARASYA is not a TPP. Public sources report that ING Business / Inside Business clients may need API access enabled by ING first. | **Not verified for the company account**; needs a licensed AISP or ING's corporate API |
| Licensed aggregators (e.g. GoCardless Bank Account Data lists `ING_INGBROBU`; Smart Fintech, Finqware are Romanian AISPs) | Coverage of ING Romania is listed by third parties; business-account coverage, consent length, refresh latency, transaction id stability and price are not confirmed. | **Not verified**; requires a contract, sandbox and a consent by the account holder |
| ING Business statement export (MT940/MT942, ING publishes the layout) | A machine-readable, bank-authenticated statement a person exports. Slower (manual export) but needs no third party. Field 61 bank reference uniqueness and field 86 content on the company's real export are not checked yet. | **Implemented as a read-only file adapter, unverified on a real export** |

Conclusion: **no verified, supported, read-only bank feed exists today.** Per the STOP rule, Phase 0 ships interfaces,
deterministic fixtures, an MT940 file reader and a shadow preview only; automatic application does not exist in code.

### Where the code lives (and why)

Operations never stores amounts or bank data (Decision 2), and the shop is the money authority (Decision 4). So:

- **YD SOFT 2.27.0 (shop):** `SourceAccess/Ing/` — `IngSettings` (all switches off; no setter exists; any stored
  `dryRun:false`/`autoApply:true` reads as invalid defaults; `auto_apply_enabled()` is a constant false), `BankFeed`
  interface, `BankCredit` (integer bani, stable `(account hash, bank transaction id)` identity), `FixtureBankFeed`,
  `Mt940Statement` (balance continuity check: a damaged/truncated file is `partial`, never "no payment"; reversals refused;
  MT942 lines are `pending`), `AdvanceMatcher` (pure), `OrderSnapshot` (WooCommerce API only: HPOS and posts),
  `ShadowPreview` (watches every SQL statement; any write is `write_detected`) and `wp yd-arasya ing status|preview`.
  New proformas record `document.account` = hash of the IBAN they print (`ProformaBankSettings::account_hash`); a
  proforma without it (every proforma issued before 2.27.0, all Libra proformas) is permanently `legacy_excluded`.
- **API 2.58.0 (Operations):** `Confirmare\IngSettlementGuard` + `bin/ing-settlement-guard.php` — SELECT-only. Refuses an
  order with ANY finance verification request (any purpose, any state: Cristina's assigned, checking,
  needs_clarification, exception and closed tasks), ANY finance action (including `unknown`), an open money attention,
  outside Confirmare, unavailable or unknown; always adds `auto_apply_unavailable`. `--snapshot` prints a digest of every
  request, request event, finance action and finance scope for before/after equality.

### Eligibility predicate as implemented (shadow)

A bank line becomes a proposal only if: feed read completely; booked credit > 0 in RON on the designated ING account
hash; bank transaction id present and seen once (a replay is `duplicate`; two different lines under one id stop the
order); exactly one payment reference of this shop (`<prefix><order number>`; another shop's prefix is `foreign_shop`,
two references `ambiguous`, an amount or name never matches); order found uniquely, open (not cancelled, refunded,
completed, finally invoiced), cash on delivery; its current proforma issued at/after the cutover with no proforma event
before it, `document.account` equal to the ING hash, issued, current (`basis` unchanged), not replaced/cancelled/unknown,
RON; no finance entry of any kind or state, no advance collection, no bank exception with the reference, no money
attention, the bank reference not recorded by a person; money known. Then lines of one order are summed once and
projected with `MoneyPosition::compute` (the shop's one formula): `overpayment_exception` if net > total, else
`candidate_exact` if net ≥ advance, else `candidate_partial`. Operations then applies `IngSettlementGuard`.

Worked examples (tests): 1000/300 → 300 credit: 700 COD; 350: 650 COD, +50 over advance; 1000: 0; 250: partial, 750, 50
missing; 200+150: 350 once; 1200: exception, 200 excess.

### Open technical blockers (each is a NO-GO for automatic application)

1. No verified bank feed (above). Needs owner choice: licensed AISP contract (consent, renewal every ≤180 days,
   latency, price) or ING corporate API, or an approved MT940 export routine.
2. MT940 adapter unverified on a real ING Romania export (field 61 bank reference present and unique? field 86 holds the
   payer's remittance text?).
3. Live configuration not inventoried in this session: whether each shop's `yd_arasya_proforma_bank` (the IBAN the
   proforma PRINTS, set by `wp yd-arasya invoice proforma-bank`) already names ING. SmartBill's displayed bank does not
   change it. Read-only check: `wp yd-arasya invoice status` (masked IBAN, BIC, prefix) on both shops.
4. Payment reference is per order, not per proforma revision: a replaced proforma keeps the reference; the matcher
   therefore refuses replaced proformas (`proforma_replaced`) instead of guessing which document a transfer paid.
5. Prefix uniqueness across shops must be confirmed live (TH vs OP).
6. No persistent bank inbox yet (Phase 1: encrypted, restricted, retention defined, unique (account, transaction id)).
7. The apply path (machine-verified settlement policy, `verified_by_kind = bank_automation`, commit-time re-check under
   lock, kill switch, reconciliation of unknown outcomes) is not written; it needs separate owner approval.

### GO / NO-GO

- **Phase 0 shadow code: GO for review** (draft PRs; tests below). Merging is still a separate owner decision; merging
  yd-soft master triggers its automatic release.
- **Phase 1 shadow on live data: NO-GO** until blocker 1 or 2 is resolved and the live inventory (3, 5) is done.
- **Automatic application (Phase 2): NO-GO.** Requires 1–7, owner approval of the machine policy, MySQL/MariaDB +
  HPOS/posts + Staff E2E on the apply path, rollback plan and a pilot. Latency caveat: an MT940 export is as fresh as the
  person exporting it; an AISP feed is typically polled (minutes to hours, provider-dependent), not real time.
