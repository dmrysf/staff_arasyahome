# Finance access and transition matrix (proposal, nothing granted)

Status: proposal for owner decision. No role, application grant, permission or configuration in this document exists in
production. Nobody has Finance access today. Applying any row needs an explicit owner instruction for that row.

Scope: the Finance application (`finance.arasyahome.ro`, API 2.63.0+), the B2B finance gate (API 2.67.0), and how they relate to
the Staff accounting work that runs today. Built on the existing Central IAM rules (`docs/central-iam.md`,
`docs/organization-iam.md`); it adds no new mechanism.

## 1. How Finance access works (code, not proposal)

| Layer | Rule | Where |
|---|---|---|
| Application | The `finance` application grant gives the intrinsic `finance.access`. `finance.access` is not role-grantable. | `Iam/ApplicationAccess`, migration 046 |
| Operation | Every Finance screen and command needs one exact `finance.*` permission from a role, on every request. Staff, B2B and Dashboard permissions never open Finance. | `Finance/FinanceAccess` |
| Owner decisions | An effective risk colour or purchase limit needs the employee UUID in `ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS`, the Finance application, an active account and `finance.risk.view` + `finance.risk.approve` (colour) or the credit permissions (limit). Re-checked in the decision transaction. No role, root status or name substitutes. | `Finance/Risk/DecisionAuthority` |
| CEO exception | Only the UUID in `ARASYA_FINANCE_CEO_EMPLOYEE_UUID`, with the Finance application, decides; never the requester. Empty today. | `Finance/Credit/CreditService` |
| Four eyes | Company verification: submitter or editor in the cycle cannot approve. Opening balance: proposer cannot verify. Risk and credit chain: proposer, verifier and approver are different people. Answer: `409 FOUR_EYES_REQUIRED`. | `CaseService`, `OnboardingService`, `RiskService`, `CreditService` |
| Revocation | Removing the application or a role stops the next request; no new login needed. | journey test F7 |
| Root | Root holds every permission by system semantics. Root is not a Finance operator and is never in the owner-decision list. | `docs/central-iam.md` |

## 2. Permission catalogue

| Permission | Allows | Four-eyes partner |
|---|---|---|
| `finance.companies.view` | Company list, cases, facets | — |
| `finance.companies.import` | Upload, map, analyze and confirm an import file | — |
| `finance.companies.review` | Take, edit and submit a verification case | `verify` |
| `finance.companies.resolve_duplicates` | Link, keep separate or reject a duplicate | — |
| `finance.companies.verify` | Approve, reject or reopen a submitted case | `review` |
| `finance.companies.supervise` | Independent review where required; record a recusal | — |
| `finance.accounts.view` | Current-account onboarding, balances | — |
| `finance.accounts.reconcile` | Open onboarding, record discrepancies | — |
| `finance.accounts.propose_opening_balance` | Draft and submit an opening balance with evidence | `verify_opening_balance` |
| `finance.accounts.verify_opening_balance` | Verify an opening balance proposal | `propose_opening_balance` |
| `finance.accounts.post_approved_opening_balance` | Post (or reverse) a verified opening balance; inactive while `ARASYA_FINANCE_OPENING_BALANCE_POSTING` is not `enabled` | — |
| `finance.risk.view` / `.propose` / `.approve` | Risk indicators; propose; approve (effective only for the owner-decision list) | propose ≠ approve |
| `finance.credit.view` | Limits, exposure, exceptions, **B2B gate journal** (`#/b2bGate`) | — |
| `finance.credit.propose` / `.verify` / `.approve` / `.revoke` | Credit-limit chain and CEO exception request; approve/revoke effective only for the owner-decision list | three different people |
| `finance.audit.view` / `.sensitive` | Finance activity trail; old and new values | — |
| `finance.collections.record` / `.approve` | Enter a payment request / verify, approve or reject it (API 2.68.0) | record ≠ approve on the same request (API and SQL) |
| `finance.ledger.request` / `.approve` | Request an adjustment or reversal / approve or reject it | request ≠ approve |
| `finance.refunds.request` / `.approve` / `.payout` | Request a refund / approve or cancel it / record the bank payout | requester ≠ approver ≠ (payout recorder ≠ requester) |
| `finance.reconciliation.manage` | Allocate payments to orders and release allocations (no balance change) | — |

## 3. Proposed Finance roles (created once by root in the Dashboard, assigned to nobody)

| Role (key) | Permissions | Purpose |
|---|---|---|
| `finance-operator` — Operator Finance | companies.view, companies.import, companies.review, companies.resolve_duplicates, accounts.view, accounts.reconcile, accounts.propose_opening_balance | Prepares: imports, cases, onboarding, opening balance proposals |
| `finance-verifier` — Verificator Finance | companies.view, companies.verify, accounts.view, accounts.verify_opening_balance | Second person: approves cases and opening balances it did not prepare |
| `finance-poster` — Înregistrare sold inițial | accounts.view, accounts.post_approved_opening_balance | Posts verified opening balances once posting is enabled; not the proposer |
| `finance-supervisor` — Supervizor Finance | companies.view, companies.supervise, accounts.view, risk.view, credit.view, audit.view | Oversight, supervisory reviews, gate journal reading |
| `finance-owner-decision` — Decizie risc și limită | companies.view, accounts.view, risk.view, risk.approve, credit.view, credit.approve, credit.revoke | Colour and purchase limit; effective only with the UUID list |
| `finance-auditor` — Auditor Finance | companies.view, accounts.view, risk.view, credit.view, audit.view, audit.sensitive | Read-only audit |
| `finance-cashier` — Încasări (2.68.0) | accounts.view, collections.record, refunds.request, reconciliation.manage | Enters payments and refund requests, allocates |
| `finance-controller` — Verificare încasări (2.68.0) | accounts.view, collections.approve, ledger.approve, refunds.approve | Second person for payments, corrections and refunds |
| `finance-payout` — Plăți rambursări (2.68.0) | accounts.view, refunds.payout | Records that the bank paid an approved refund |
| `finance-corrections` — Cereri corecții (2.68.0) | accounts.view, ledger.request | Requests adjustments and reversals |

Not proposed for now: `risk.propose`, `credit.propose`, `credit.verify` (the V0.3 chain is replaced by owner-direct decisions,
Finance V0.4.0) and the CEO exception (owner decision D4).

Combinations to avoid on one person: `finance-operator` + `finance-verifier` (the code still refuses the same person on the same
item, but the role design should not invite it); `finance-operator` + `finance-poster`.

## 4. People (proposal)

| Person | Today (unchanged by this document) | Finance proposal | Phase |
|---|---|---|---|
| Mesut Yeman (CEO) | Dashboard, CEO principal; no Finance | Finance application + `finance-owner-decision`; UUID in `ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS`. Later, only after D4: `ARASYA_FINANCE_CEO_EMPLOYEE_UUID`. | T2 |
| Zelal Yeman (Finance Director) | Staff/B2B as configured; Confirmare finance supervision on both shops (no `approve`); B2B `director-financiar` role | Finance application + `finance-owner-decision` + `finance-supervisor`; UUID in the authority list. Optionally `finance-verifier` for the pilot if no second accountant is named (owner decision G-IAM-2). | T2 (owner role), T3 (pilot) |
| Nita Cristina (Accounting) | Staff Confirmare finance `view + record + verify` (single verification, owner decision 2026-10-29); B2B `contabilitate` role | Finance application + `finance-operator` for the pilot. Staff scopes and tasks stay exactly as they are. | T3 |
| Second accountant (to be named) | — | `finance-verifier` (and `finance-poster` when posting is enabled) | T3 |
| Other Staff accounting operators | Their Staff scopes | No Finance access. | — |
| B2B sales (DR7, DR9) | B2B roles | No Finance access. In B2B they see finance reason codes only (D6). | — |
| Root | System root | Break-glass only; never a daily Finance user, never in the authority list. | — |
| Test accounts | — | Two synthetic accounts (`finance-operator`, `finance-verifier`) for the post-deploy check and the rehearsal; disabled afterwards. | T1 |

The authority list and the CEO UUID are configuration: each change needs `ARASYA_FINANCE_CONFIG_ACK` with the new fingerprint
(`bin/finance-config-check.php`).

## 5. Transition phases

| Phase | Entry condition | Change (each needs owner instruction) | Exit check |
|---|---|---|---|
| T0 today | — | none | Dashboard, employees: nobody holds the Finance application. |
| T1 technical check | API 2.67.0 and Finance 0.5.0 deployed, origin listed | Create the six roles (no assignment). Create two synthetic test accounts with the Finance application and one role each. | Default deny with the application alone; each test account sees only its screens; remove the grants. |
| T2 decision makers | T1 done | Mesut Yeman and Zelal Yeman: Finance application + `finance-owner-decision` (+ `finance-supervisor` for Zelal). Configure the authority list with both UUIDs and acknowledge. | `GET /finance/access` reports `owner.authorityConfigured: true` and `owner.decisionMaker: true` for both; no decision is taken before pilot data is verified. |
| T3 pilot operators | Real-data pilot authorized (`docs/finance-real-data-pilot.md`) | Nita Cristina: `finance-operator`. Second person: `finance-verifier`. | Pilot batch completed with four eyes; audit shows both people. |
| T4 gate journal | D1 (`shadow`) active | Readers of `#/b2bGate`: Mesut Yeman, Zelal Yeman (already via `credit.view`). | Weekly review recorded. |
| T5 steady state | Pilot accepted | Further operators by the same rule; posting switch by separate decision. | Quarterly access review in the Dashboard (Finance application holders and roles) and the Finance audit. |

At no phase are existing Staff scopes, Confirmare finance tasks, B2B roles or Dashboard access of any employee removed or changed
by this matrix. Moving accounting work from Staff to Finance is out of scope and needs its own owner decision.

## 6. How a grant is applied (when authorized)

1. Root, Dashboard: create the role once (name and permissions above).
2. Root, Dashboard: grant the `finance` application to the named employee; assign the role. Both actions are audited in Central IAM.
3. The person signs in to Staff and opens `https://finance.arasyahome.ro`.
4. Revocation: remove the role or the application; effective on the next request.
