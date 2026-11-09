# Finance V0.3 — company master data, current accounts, verification, credit risk and limits (DRAFT)

**Not deployed. Migration 047 not run in production. No employee granted. No Staff workflow changed.**
Cristina, Zelal and every other accounting employee keep their Staff roles, scopes, tasks and approvals; nothing in
this change reads or writes `employee_finance_scopes`, `finance_verification_requests`, `order_finance_actions`,
`role_permissions`, `employee_role_assignments` or `employee_application_access` (a unit test enforces it).

Stacked on PR #118 (Finance application gate, migration 046). Frontend: `finance_arasyahome` branch
`feat/finance-v03-workspace`.

## 1. Architecture inventory (Sprint A, from the code on 2026-11-08)

| Topic | Current state |
|---|---|
| main | API 2.60.0 (b24da6a), live migrations through 045 |
| Open Finance PRs | #118 (046, GET-only gate, draft), Finance #8 (workspace UI), #12 (read-only adapters, stacked on #8) |
| Other drafts touching numbering | #115 ING Phase 0 (API 2.58.0, no migration). API version is not bumped here; it is assigned at merge time. |
| Identity | Central IAM sessions (HttpOnly cookie, CSRF token per session), `applications` + `employee_application_access`, role permissions; `AuthorizationService` re-reads the identity on every request. Root holds every permission but four-eyes checks compare employee ids, so root cannot approve its own work. |
| Canonical company | `b2b_companies` (unique `country_code + tax_identifier_normalized`, `company_code` B2B-000001…), contacts, addresses, insert-only `b2b_company_activity_events`. |
| Ledger | `b2b_account_movements` (insert-only, DECIMAL(16,2), RON/EUR, `opening_balance` type, one active opening per currency, reversal movements, allocations), written only through `B2B\AccountLedger` inside the caller's transaction with the company row locked first. |
| Idempotency pattern | per-actor `Idempotency-Key`, request hash, replay inside the same transaction (B2B account and company commands). Reused as `finance_idempotency`. |
| Hosting | cPanel PHP, no Redis/queues/S3/background workers. Large work is therefore chunked into short, resumable HTTP calls (cursor on the batch row). |
| Tests | PHP unit suites (`tests/run.php`), per-feature MySQL/MariaDB integration scripts, GitHub Actions MySQL 8.4 + MariaDB 10.11 jobs. |

## 2. Canonical data ownership

- **Company identity:** `b2b_companies` only. Finance never edits or deletes a B2B company. An approved verification
  case either links to an existing company or — only when the tax identifier was verified with evidence — creates one
  with status `inactive` (B2B ordering stays a separate decision). Without a verified tax identifier the case stays a
  verified prospect in Finance staging: no fabricated company, no invented tax id.
- **Balances:** `b2b_account_movements` only. Finance stores a reviewed opening-balance *proposal*; the only way it
  reaches the ledger is one `opening_balance` movement via `AccountLedger` after two people agreed and the posting
  switch is on. An identical opening already in the ledger is adopted, never duplicated. Corrections are reversals.
- **Finance-owned state:** import staging, verification cases and decisions, duplicate candidates, evidence references,
  onboarding workflow, risk classifications and policies, credit limits, CEO exception records, audit events.

## 3. Schema (migration 047, additive)

```
finance_import_batches 1─* finance_import_rows 1─0..1 finance_company_cases *─0..1 b2b_companies
                                                         │ 1─* finance_case_decisions (immutable)
                                                         │ 1─* finance_duplicate_candidates ─> b2b_companies | finance_company_cases
b2b_companies 1─0..1 finance_account_onboardings ─> finance_company_cases, b2b_account_movements (posted, unique)
b2b_companies 1─* finance_risk_assessments (≤1 APPROVED: unique active_company_uuid) ─> finance_risk_policies
b2b_companies 1─* finance_credit_limits (≤1 current: unique active_company_uuid) ─> onboarding, risk assessment, policy
b2b_companies 1─* finance_credit_exceptions ─> b2b_orders (≤1 open per order)
finance_evidence_refs (entity_type, entity_uuid) · finance_activity_events (immutable) · finance_idempotency
```

Constraints worth knowing: every FK is RESTRICT; the account currency CHECK makes `RO ⇔ RON, other ⇔ EUR` the only
possible pairing; `NO_INFORMATION` cannot carry an amount; a `ZERO` must be 0.00 and DEBIT/CREDIT must be > 0; an
approved case needs an approver different from the submitter; verified/posted onboardings need a verifier different
from the proposer; credit limits need proposer ≠ verifier ≠ approver; a CEO exception decider ≠ requester. MySQL 8.4 and
MariaDB 10.11 both run the full suite. `row_number` and `cycle` are avoided as column names (reserved words).

## 4. State machines

- **Case:** IMPORTED → NEEDS_REVIEW | NEEDS_INFORMATION | SUSPECTED_DUPLICATE → IN_REVIEW (assigned) →
  READY_FOR_SECOND_CHECK → APPROVED; branches NEEDS_INFORMATION (returned), REJECTED, REOPENED. `CaseWorkflow::ALLOWED`
  is the only transition table. Approval requires the exact submitted version and a verifier who neither submitted
  nor edited the case in this review cycle. Editing identity fields reopens their checklist items and reruns duplicate
  detection; tax "verified" needs an evidence reference.
- **Onboarding:** NO_INFORMATION → AWAITING_RECONCILIATION (draft) → SUBMITTED → VERIFIED → RECONCILED (posted or adopted);
  verified ZERO → RECONCILED directly; DISPUTED; reopen = reversal + new cycle. Ledger fingerprint taken at submission
  must be unchanged at verification and posting.
- **Risk:** PROPOSED → APPROVED (active) | REJECTED; APPROVED → SUPERSEDED | SUSPENDED. No approved row = UNVERIFIED.
  The facts the proposal was based on (case version, onboarding version, ledger fingerprint) must be unchanged at approval.
- **Credit limit:** PROPOSED → UNDER_REVIEW → APPROVED → SUSPENDED ⇄ APPROVED, REVOKED, SUPERSEDED; REJECTED. EXPIRED is
  computed from `expires_on`. No row = NO APPROVED LIMIT (never zero, never unlimited).

## 5. Endpoints (all under `/finance`, session + Finance app + `finance.access` + exact permission)

Reads: `access`, `overview`, `companies`, `company-search`, `b2b/accounts`, `imports[/{id}[/rows|/rejections.csv]]`,
`cases[/{id}]`, `accounts[/{id}]`, `risk`, `risk/policies`, `risk/companies/{id}`, `credit`, `credit/companies/{id}`,
`companies/{id}/credit-profile`, `audit`.
Writes (POST, exact origin, CSRF, Idempotency-Key, 600/min per employee): imports (raw CSV/XLSX body ≤ 4 MiB),
`imports/{id}/{mapping|analyze|confirm|cancel}`, `cases`, `cases/from-b2b`,
`cases/{id}/{claim|release|update|checklist|request-information|mark-duplicate|return|submit|approve|reject|reopen|link}`,
`cases/{id}/duplicates/{candidate}/resolve`, `accounts`, `accounts/{id}/{draft|submit|verify|return|post|dispute|reopen|currency-issue|correct-currency}`,
`risk`, `risk/{id}/{approve|reject|suspend}`, `risk/policies`, `risk/policies/{id}/{approve|retire}`, `credit`,
`credit/{id}/{verify|approve|reject|suspend|reinstate|revoke}`, `credit/exceptions`, `credit/exceptions/{id}/{approve|reject|withdraw}`.
Every response carries `Cache-Control: no-store, private`. The only request-size change outside `/finance` is none:
`RequestFactory` reads up to 4 MiB + 1 byte for `POST /finance/imports` only; every other route keeps 256 KiB.

## 6. Permissions (047, role-grantable, mapped to the Finance application, granted to nobody)

`finance.companies.{import,review,verify,resolve_duplicates}`, `finance.accounts.{reconcile,propose_opening_balance,verify_opening_balance,post_approved_opening_balance}`,
`finance.risk.{view,propose,approve}`, `finance.credit.{view,propose,verify,approve,revoke}`, `finance.audit.view` —
plus 046's `finance.companies.view`, `finance.accounts.view` and the non-role-grantable `finance.access`.
Staff and B2B permissions never authorize Finance (`FinanceAccess::gate`). The CEO exception approver is not a
permission: it is the single employee UUID in `ARASYA_FINANCE_CEO_EMPLOYEE_UUID` (empty = nobody).

## 7. Import security

Formats: CSV (UTF-8, Windows-1250/1252, ISO-8859-2 by explicit choice) and XLSX. Limits: 4 MiB, 20 000 rows, 60 columns,
1 000 characters per cell. XLSX: entry count, path traversal, total expansion (50 MB) and ratio (150×) checked before
reading; macros, ActiveX and embedded objects refused; DOCTYPE/ENTITY refused; formulas never evaluated (cached value
only); streaming XMLReader over `zip://`. The raw file is never stored (only SHA-256 and sanitized cells). The same file
cannot be active twice; a cancelled batch frees it. Rejection reports neutralize `= + - @` cell prefixes. Logs keep
route and error code only (existing `StructuredLogger`); audit details hold codes and field names, never values.

## 8. Future B2B contract (read-only, not consumed yet)

`GET /finance/companies/{id}/credit-profile` → `companyId, verificationStatus (VERIFIED|IN_PROGRESS|REJECTED|NOT_STARTED),
registeredCountry, accountCurrency|null, reconciliationStatus (NOT_OPENED|…|RECONCILED), riskColor (UNVERIFIED|GREEN|YELLOW|RED),
creditLimitStatus (NO_APPROVED_LIMIT|APPROVED|SUSPENDED|EXPIRED|NOT_YET_EFFECTIVE), approvedCreditLimit|null,
creditLimitCurrency|null, currentExposure|null, availableCredit|null, exposureBasis ("ledger_balance_only"),
creditPolicyVersion|null, creditReviewRequired, lastVerifiedAt|null`. Unknown is null or an explicit status, never 0.
Later B2B enforcement must add pending order commitments to exposure with atomic reservations, and re-check an approved
CEO exception (order, amount, snapshot hash, expiry) before release. None of this is active: no B2B, production, AWB,
Trend Home or Outlet Perdele code path reads Finance tables.

## 9. SAGA / SmartBill boundary

No API integration is claimed or built. Exports enter through the import with an explicit source (`SAGA_EXPORT`,
`SMARTBILL_EXPORT`, `OTHER_LEGACY`); the header recognizer already maps common Romanian/Turkish/English column names.
A source name is provenance, never verification. Conflicting amounts from two sources stay side by side on the cases
(declared debit/credit per source) and are resolved by people in the opening-balance proposal with evidence.

## 10. Rollout checklist (each step needs explicit owner approval)

1. Review and merge #118, then this PR (version number assigned at merge; ING drafts renumbered after).
2. Read-only production checks: `php -m` shows `zip` and `xmlreader` (else XLSX answers 503 and CSV still works);
   `post_max_size` ≥ 5M for the upload route; free disk for the DB backup.
3. Full DB backup; deploy API; run 046 and 047 (additive; `bin/check-migrations.php`, migration status).
4. Add `https://finance.arasyahome.ro` to `ARASYA_ALLOWED_ORIGINS` (exact origin), deploy the Finance frontend.
5. Owner names the first Finance users; grant the `finance` application and narrow roles in the Dashboard
   (never to Cristina/Zelal without the owner's separate instruction).
6. Pilot with a small CSV of real companies after the owner authorizes real data import.
7. Only later, separately: `ARASYA_FINANCE_OPENING_BALANCE_POSTING=enabled`, `ARASYA_FINANCE_CEO_EMPLOYEE_UUID=<verified uuid>`,
   risk policy thresholds, and any B2B enforcement.

Rollback: deploy the previous API release; keep the 047 tables (they hold audit history; nothing references them from
older code). A posted opening balance is corrected with a reversal, never deleted.

## 11. Business decisions still open (not guessed)

Risk thresholds and indicator weights; who may approve risk, credit limits and policies (role composition); whether a
GREEN/YELLOW company needs a minimum evidence set before a limit; the opening-balance cut-off date and evidence
requirements per source (SAGA vs SmartBill disagreements); whether Finance-created canonical companies may later be
activated in B2B and by whom; the CEO approver identity and exception expiry policy; when posting is switched on.
