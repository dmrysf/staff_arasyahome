# Finance V0.4.0: owner-direct risk colour and purchase-limit decisions

Status: DRAFT. Not merged, not deployed. Migration 049 has not run in production. Nobody is provisioned and nobody is granted.
Tracking issue: dmrysf/finance_arasyahome#19. Operations API 2.63.0.

## 1. Owner decision

Only Mesut Yeman and Zelal Yeman make a customer's risk colour (GREEN, YELLOW, RED) or purchase limit effective.

- Each of them decides alone. Neither needs the other's approval or countersignature.
- A later decision by either one replaces the earlier one. Both stay in the history.
- Everyone else can only collect facts and record recommendations, which are never effective.

Owner autonomy covers the colour and the limit only. Company identity verification and opening-balance reconciliation keep their independent checks.

## 2. Authority (server side only)

An effective owner command passes all of these checks, in this order. Each request repeats them.

| # | Guard | Where | Refusal |
|---|---|---|---|
| 1 | Session, exact origin, CSRF, Finance application, `finance.access` | kernel, `FinanceController`, `FinanceAccess::gate` | 401/403 |
| 2 | Operation permission: `finance.risk.view` and `finance.risk.approve` for a colour; `finance.credit.view` and `finance.credit.approve` to set a limit; `finance.credit.revoke` to suspend, reinstate or revoke | `FinanceStore::mutate`, re-read inside the transaction | 403 `UNAUTHORIZED_ACTION` |
| 3 | Immutable employee UUID in `ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS`. The variable accepts only UUIDs; names and usernames are refused at configuration time. An empty list means nobody. | `DecisionAuthority::require` | 403 `RISK_LIMIT_AUTHORITY_REQUIRED` |
| 4 | The identity loaded for the request is active, has no pending password change and holds the Finance application | `DecisionAuthority::verify` | 403 `RISK_LIMIT_AUTHORITY_REQUIRED` |
| 5 | The Central IAM registry row, re-read through `EmployeeRepository` inside the decision transaction, still says the same: active, Finance application, same `authorization_version` | `DecisionAuthority::verify` | 403 `RISK_LIMIT_AUTHORITY_REQUIRED` |

Root and Finance administrators hold every permission, but they are refused at guard 3. Role, root status, display name, username and e-mail never authorize. The frontend decides nothing. `/finance/access` returns an informational `owner` block that the screen uses only to hide controls.

The existing V0.3 approve, suspend, reinstate and revoke paths now use the same `verify()`.

## 3. Database (migration 049)

Every change is additive and re-runnable. No row is rewritten, and no permission, grant or allowlist value is added.

- `finance_risk_assessments.decision_mode` and `finance_credit_limits.decision_mode`: `REVIEWED` (default; every existing row) or `OWNER_DIRECT`.
- `chk_finance_risk_separation` is replaced by `chk_finance_risk_separation_049`.
  - REVIEWED: identical to 047.
  - OWNER_DIRECT: proposer = decider, decided at the same instant. Status is only APPROVED, SUPERSEDED or SUSPENDED, never PROPOSED or REJECTED.
- `chk_finance_credit_verified` and `chk_finance_credit_approved` are replaced by `_049`.
  - REVIEWED: identical to 047 (three distinct people).
  - OWNER_DIRECT: proposer = verifier = approver at the same instant. Status is never a proposal, review or rejection.
- `finance_credit_limits.risk_assessment_uuid` becomes nullable for OWNER_DIRECT only (`chk_finance_credit_risk_basis_049`). An owner limit is a decision on the company and its account, not on a colour.
- New insert-only table `finance_owner_decisions`. Each row is one owner command, in a gap-free sequence per company (`UNIQUE (company_uuid, sequence_no)`). A row stores:
  - subject, action and effect (EFFECTIVE or NOT_EFFECTIVE);
  - the previous and new value, and the currency;
  - the reason and the basis (verification case and version, onboarding and version, reconciliation, account currency, registered country, ledger hash, active rows);
  - the actor UUID and an identity snapshot;
  - the SHA-256 fingerprint of the allowlist at decision time;
  - the state token before and after, the request ID and the Idempotency-Key.
- All foreign keys are RESTRICT. Foreign-key checks are never disabled.

Tested on MySQL 8.4 and MariaDB 10.11:

- a fresh install;
- re-running 049;
- a real 048 to 049 upgrade over existing REVIEWED rows (all rows preserved byte for byte);
- the REVIEWED separation still enforced after the upgrade.

## 4. API

| Method | Path | Purpose |
|---|---|---|
| GET | `/finance/owner/companies?q=&color=&limit=&cursor=` | Search canonical companies by name, code, linked legacy code, or tax id (tax id only with `finance.companies.view`). Filter by colour (GREEN, YELLOW, RED, UNVERIFIED) and limit (NO_APPROVED_LIMIT, APPROVED, SUSPENDED, ZERO). |
| GET | `/finance/owner/companies/{id}` | Returns everything the decision screen shows (see below). |
| POST | `/finance/owner/companies/{id}/risk` | Body: `expectedState`, `classification`, `effect` (EFFECTIVE or NOT_EFFECTIVE), `reason`. |
| POST | `/finance/owner/companies/{id}/credit` | Body: `expectedState`, `action` (SET, SUSPEND, REINSTATE, REVOKE), and for SET also `amount` (decimal string, `0.00` allowed), `currency`, optional dates, `effect`, `reason`. |

The detail response contains:

- company, profile, verification and reconciliation, and the expected currency;
- linked cases with their open flags (XX/ZZ included), and warnings;
- colour and limit with their history and employee recommendations;
- the owner decision journal and pending instructions;
- what each colour or limit option would need (`options`, `effectiveBlock`);
- the actor's owner status, and the state token.

Every command runs in one transaction with these protections:

- an Idempotency-Key: a retry replays the same decision, and a different body with the same key returns 409;
- the company row lock, which serialises two owners;
- the `expectedState` token: a stale token returns 409 `OWNER_STATE_STALE` with `currentState`;
- the authoritative compact state in every successful response.

Repeating the current colour or limit returns 409 `NO_CHANGE`.

## 5. Lifecycles

**Colour.** The owner opens the customer, picks GREEN, YELLOW or RED, writes a reason, reviews the change and confirms.

- With a verified identity, RED or YELLOW becomes effective immediately. GREEN also needs a reconciled account without an open currency issue.
- The previous active row becomes SUPERSEDED (kept). The new OWNER_DIRECT row is the only active one.
- Without the basis, the owner can save a NOT_EFFECTIVE instruction. It never becomes effective by itself.
- No colour means UNVERIFIED, never GREEN. Indicators are information only; nothing is classified from debt.

**Limit.** The same flow, plus currency and optional review and expiry dates.

- Effective only with a verified identity, a reconciled account without an open currency issue, and the account currency of the registered country: RON for Romania, EUR for every other country. There is no conversion.
- SET replaces the active limit (SUPERSEDED, kept).
- SUSPEND keeps the limit attached but stops it. REINSTATE requires the same reconciled basis; otherwise the response is 409 `CREDIT_BASIS_STALE` and the owner sets a new limit. REVOKE ends the limit.
- `0.00` is an approved zero. No row means `NO_APPROVED_LIMIT` (null, never 0).
- There is no unlimited option, and nothing is limited automatically.
- A later reconciliation or data change never activates an instruction.

**Employee recommendations.** The V0.3 `POST /finance/risk` and `POST /finance/credit` create REVIEWED proposals. They stay PROPOSED or UNDER_REVIEW and are listed as "not effective". An ordinary employee or a Finance administrator cannot approve them; only a provisioned owner can, on the V0.3 path.

## 6. Purchase-limit versus purchase eligibility (future B2B contract)

`GET /finance/companies/{id}/credit-profile` now adds three fields:

- `creditPurchaseEligibility`: ELIGIBLE or BLOCKED.
- `eligibilityBlockers`: IDENTITY_NOT_VERIFIED, ACCOUNT_NOT_RECONCILED, CURRENCY_ISSUE_OPEN, RISK_NOT_ASSIGNED, RISK_RED, NO_APPROVED_LIMIT, LIMIT_SUSPENDED, LIMIT_EXPIRED, LIMIT_NOT_YET_EFFECTIVE, LIMIT_ZERO, LIMIT_CURRENCY_MISMATCH, CREDIT_REVIEW_REQUIRED, NO_AVAILABLE_CREDIT.
- `eligibilityEnforced: false`.

A stored limit alone never makes a company eligible. RED with a limit is BLOCKED (`RISK_RED`). Nothing in B2B reads this yet.

## 7. Audit and misuse detection

- Colour and limit rows are never deleted. Superseded rows keep their actor, time and reason.
- The journal is insert-only. A unit-test guard fails the build if any source file writes `UPDATE` or `DELETE` on it.
- Viewers with `finance.risk.view` or `finance.credit.view` see decisions, values, reasons and basis.
- Only `finance.audit.sensitive` also sees the identity snapshot, the allowlist fingerprint and the state tokens.
- Detection queries (no accusation is implied; they only show a decision taken outside this path):

```sql
-- an OWNER_DIRECT colour without a matching journal row by the same actor
SELECT a.assessment_uuid FROM finance_risk_assessments a
WHERE a.decision_mode = 'OWNER_DIRECT' AND NOT EXISTS (SELECT 1 FROM finance_owner_decisions d
  WHERE d.assessment_uuid = a.assessment_uuid AND d.action = 'SET' AND d.actor_employee_uuid = a.decided_by_employee_uuid);
-- the same for limits
SELECT l.limit_uuid FROM finance_credit_limits l
WHERE l.decision_mode = 'OWNER_DIRECT' AND NOT EXISTS (SELECT 1 FROM finance_owner_decisions d
  WHERE d.limit_uuid = l.limit_uuid AND d.action = 'SET' AND d.actor_employee_uuid = l.approved_by_employee_uuid);
-- decisions whose actor is not in the currently provisioned list: compare actor_employee_uuid with the allowlist,
-- and authority_fingerprint with SHA-256 of the sorted allowlist.
```

## 8. Not changed

The following are not changed:

- the `b2b_account_movements` ledger, canonical companies and debts;
- payment allocations;
- SmartBill, the ING matching draft, and TrendHome / Outlet Perdele Confirmare;
- Staff production, B2B order finalisation and QR handoff;
- company verification and opening balances, including the Issue #18 fix.

Opening-balance posting stays `disabled` by default.

## 9. Provisioning (a separate, explicitly approved step)

1. The owner confirms the two Central IAM employee records. Read the UUIDs from the registry, never from names.
2. Grant each one the Finance application and a role with exactly the permissions in §2, guard 2.
3. Set `ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS=<uuid-mesut>,<uuid-zelal>` in the private configuration.
4. Each owner signs in again, then checks that `/finance/access` returns `owner.decisionMaker: true`.

None of this is done in this release.

## 10. Next sprint: B2B ordering and QR

Narrowest boundary: one server-side check where B2B finalises a commercial order, inside the B2B order-finalisation transaction (`B2B/OrderCommands`).

1. Read the Finance profile through an internal, read-only `CreditService::profileData(companyId)` call. Use the same database; do not call over HTTP. The check uses no browser data.
2. Refuse finalisation when `creditPurchaseEligibility` is BLOCKED. A credit order also needs `order total + current exposure ≤ approved limit` in the limit currency, and the order currency must equal the account currency.
3. Lock the company row as Finance does, so a colour or limit change and a finalisation serialise.
4. Store the profile snapshot (state token, colour, limit, exposure) with the finalised order for audit.
5. Prepaid or CEO-exception orders (existing `finance_credit_exceptions`) are explicit, separate branches. A CEO exception is order-bound, expiring and snapshot-checked.
6. The explicit production submission, the canonical Operations API handoff and the QR flow stay unchanged. They start only after finalisation, so a blocked customer never reaches production or QR.
7. Roll out behind a per-source switch, default off. Run in shadow mode (log the would-be refusals) before enforcing.
