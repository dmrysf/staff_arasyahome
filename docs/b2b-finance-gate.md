# B2B finance gate (API 2.67.0)

Status: DRAFT. Not merged, not deployed. Production value: `ARASYA_B2B_FINANCE_GATE` absent, which is `off`. Nothing in this document is active in production.

The gate connects a B2B commercial order to the Finance decisions of the company (verification, account, colour, purchase limit). It is a server-side authorization. The browser never sends a balance, a limit or an eligibility result.

Code: `operations-api/src/B2B/FinanceGate.php` (decision), `FinanceGateJournal.php` (audit), `OrderCommands::status` (finalization), `ProductionCommands::submit` (release). The basis is `Finance\Credit\CreditService::profileData`, read from the same database and transaction.

## 1. What is a credit order

Every B2B order posts one receivable at finalization (`AccountLedger::postOrderReceivable`). B2B orders have no payment-terms field. So every B2B order is a credit order for the gate. The order type (`FULL_TAILORING`, `CUT_ONLY`, `FULL_ROLL_BAG`) selects the manufacturing route only. All three types get the same finance decision.

## 2. Two checks

| Check | Where | Lock | Before |
|---|---|---|---|
| Finalization | `POST /b2b/orders/{id}/finalize` | company row, then order row | the status change and the receivable |
| Release | `POST /b2b/orders/{id}/production` | company row, then order row | the canonical production order, its QR and its ticket |

Finance owner decisions (`OwnerDecisionService`) lock the same company row. A colour or limit decision and an order action therefore run one after the other, never interleaved.

Nothing is checked after the handoff exists. QR issuance, canonical intake and the 14 stages are unchanged. Finance never stops, holds or invalidates an order that is already in production.

## 3. Modes

Two reviewed server settings in the private configuration:

| Key | Values | Absent means |
|---|---|---|
| `ARASYA_B2B_FINANCE_GATE` | `off`, `shadow`, `enforce` | `off` |
| `ARASYA_B2B_FINANCE_RELEASE_GATE` | `off`, `shadow`, `enforce` | the same value as `ARASYA_B2B_FINANCE_GATE` |

| Mode | Finalization | Release |
|---|---|---|
| `off` | Nothing is evaluated. Snapshot `NOT_EVALUATED`. | Nothing is evaluated. Snapshot `NOT_EVALUATED` on the handoff. |
| `shadow` | Evaluated and recorded. A refusal is recorded as `WOULD_BLOCK`; the order is finalized. | Evaluated and recorded. A refusal is recorded as `WOULD_BLOCK`; the order is released. |
| `enforce` | A refusal returns 409 `FINANCE_NOT_ELIGIBLE`. The order stays a draft: no receivable, no snapshot. | A refusal returns 409 `FINANCE_RELEASE_BLOCKED`. The order stays finalized and unsubmitted: no handoff, no canonical order, no QR. |

### Invalid configuration

Any other value (`enforced`, `OFF`, `on`, an empty gate value) is a configuration failure. Leading and trailing spaces are trimmed when the configuration is loaded. An empty release value means absent at run time, and the deployment check still reports it.

- It is never read as `off`. `Config` keeps the value exactly as written; `FinanceGate` validates it where it is used.
- The gated action answers 503 `FINANCE_GATE_MISCONFIGURED`. An invalid gate value refuses B2B finalization. An invalid release value refuses B2B production submission.
- Only those two B2B actions are refused. Staff, production stages, Trendyol, the web shops, AWB and the rest of B2B keep working. The gate is not a factory-wide switch.
- The order detail reports `financeGate.configured: false` so the B2B screen can say why the button fails.
- `bin/finance-config-check.php` (run by `scripts/cpanel-deploy-api.sh` and `bin/readiness.php`) reports `FAIL b2b_finance_gate_unrecognized` or `FAIL b2b_finance_release_gate_unrecognized`. A deployment with such a value is blocked. An acknowledgement never excuses a malformed value.

An active mode (`shadow`, `enforce`) is an activation. The deployment check blocks it until `ARASYA_FINANCE_CONFIG_ACK` holds the printed fingerprint of the exact reviewed values. The fingerprint (version 3) covers both gate keys. Changing either key later makes the acknowledgement stale.

## 4. Finalization decision

Allowed when all of these hold:

- no eligibility blocker in the Finance profile (`IDENTITY_NOT_VERIFIED`, `ACCOUNT_NOT_RECONCILED`, `CURRENCY_ISSUE_OPEN`, `RISK_NOT_ASSIGNED`, `RISK_RED`, `NO_APPROVED_LIMIT`, `LIMIT_EXPIRED`, `LIMIT_SUSPENDED`, `LIMIT_NOT_YET_EFFECTIVE`, `LIMIT_ZERO`, `LIMIT_CURRENCY_MISMATCH`, `CREDIT_REVIEW_REQUIRED`, `NO_AVAILABLE_CREDIT`);
- the order currency equals the verified account currency (`ORDER_CURRENCY_MISMATCH` otherwise; there is no conversion);
- the order gross total is not more than the available credit (`CREDIT_LIMIT_EXCEEDED` otherwise). Available credit is the approved limit minus the current ledger exposure, before this order's receivable.

Rules that follow from the profile:

- `UNVERIFIED` is not `GREEN`: a company without a colour has `RISK_NOT_ASSIGNED`.
- A missing limit is `NO_APPROVED_LIMIT`. It is not a limit of zero. A limit of zero is `LIMIT_ZERO`.
- Missing data never becomes an allowance: without a reconciled account there is no exposure and no available credit, and the blocker stands.

## 5. Release decision and changes after finalization

Time passes between finalization, production submission, QR issuance and intake. The customer's position may change in that time. The release check handles it.

At release the order's own receivable is already in the ledger exposure. The order is within credit while the exposure does not exceed the approved limit. The check is:

- every profile blocker except `NO_AVAILABLE_CREDIT` (that one speaks about the next order; this order is already counted);
- `ORDER_CURRENCY_MISMATCH`;
- `CREDIT_LIMIT_EXCEEDED` when the exposure is above the limit.

What it detects: a colour changed to RED, a revoked, suspended, expired or lowered limit, a lost verification, a reopened account, a payment reversal or another movement that pushed the exposure above the limit, and an expired CEO exception.

The release snapshot is stored on the handoff (`b2b_production_handoffs.finance_decision`). It holds `finalization` (the earlier outcome, mode and time), `changedSinceFinalization` (the basis fields that differ) and `grandfathered`.

| Case | Behaviour |
|---|---|
| Decision unchanged, still eligible | Released. |
| Limit revoked, suspended, expired, lowered under the exposure; colour RED; verification or account lost | `enforce`: 409 `FINANCE_RELEASE_BLOCKED` with the reason codes. The order stays finalized. Its receivable is not touched. A later Finance decision, a recorded payment or an approved CEO exception makes the next submission pass. `shadow`: released, `WOULD_BLOCK` recorded. |
| Limit exactly filled by the finalized orders | Released. |
| Order finalized without an evaluated decision (before activation, or in mode `off`): "grandfathered" | Evaluated at release like any other order, marked `grandfathered: true`. Under `enforce` an ineligible one is not released. To release such orders without a check, review `ARASYA_B2B_FINANCE_RELEASE_GATE` as `off` or `shadow` (owner decision D3). |
| Order already submitted to production | Never evaluated again. A repeated submission answers 200 with the existing handoff. Stages, QR, reprints and Livrare are unchanged. |
| Order already produced or handed over | Never touched. |
| Commercial cancellation of a refused order | Unchanged: allowed while there is no handoff; it posts the linked reversal of the receivable. |

The refusal is not a production stop. It sets no `production_stopped_at`, opens no production exception and writes no stage event. It only refuses the handoff.

## 6. CEO exception

`finance_credit_exceptions` (Finance V0.3) already records an order-bound exception that only the configured CEO (`ARASYA_FINANCE_CEO_EMPLOYEE_UUID`) can approve, never the requester. The gate reads it in `shadow` and `enforce` mode. Outcome `ALLOWED_BY_CEO_EXCEPTION`, with the exception id, amount and expiry in the snapshot.

An approved exception allows the order only when all of these hold:

- it is bound to this order and this company and its status is `APPROVED`;
- it has not expired at the moment of the decision;
- its currency is the order currency and its amount is at least the order gross total;
- the company is RED now (an exception exists only for a RED company);
- every refusal reason is a credit reason: `RISK_RED`, `NO_APPROVED_LIMIT`, `LIMIT_EXPIRED`, `LIMIT_SUSPENDED`, `LIMIT_NOT_YET_EFFECTIVE`, `LIMIT_ZERO`, `CREDIT_REVIEW_REQUIRED`, `NO_AVAILABLE_CREDIT`, `CREDIT_LIMIT_EXCEEDED`.

An exception never covers `IDENTITY_NOT_VERIFIED`, `ACCOUNT_NOT_RECONCILED`, `CURRENCY_ISSUE_OPEN`, `LIMIT_CURRENCY_MISMATCH` or `ORDER_CURRENCY_MISMATCH`.

The same exception is checked again at release. If it expired in between, the release is refused.

In production no CEO approver is configured, so no exception can be approved and this branch has no effect. Which reasons an exception covers, and its maximum lifetime (30 days in V0.3), are owner decision D4.

## 7. Prepaid orders

Not modelled. There is no payment-terms field on a B2B order, and the gate does not infer "prepaid" from a credit balance. Under `enforce`, an order of a company without an approved limit is refused even if the company paid in advance. This is the safe default. A prepaid rule is owner decision D5.

Rules that hold whatever D5 decides:

- **An order counts as prepaid only after a real payment is verified and recorded.** Verified means a person checked the
  money on the bank statement (or the payment processor report) against the order, and a second person confirmed it.
  Recorded means a payment movement in the B2B ledger of that company, with the evidence reference. A customer's promise,
  a proforma, a screenshot, an e-mail or a sales note is not a payment.
- **No artificial or symbolic credit limit.** A limit is an owner decision to sell on credit up to that amount. Giving a
  prepaid customer a small limit so the gate lets orders through would (a) open real unsecured credit up to that amount for
  orders that are not paid, (b) make the risk journal and the audit state a credit decision that nobody took, and (c) hide
  from the gate exactly the case it exists to stop. It is not a safe workaround and is not recommended.
- Bank and payment-processor data are matched by a person. ING and SmartBill automatic reconciliation are out of scope
  (program decision), so nothing here reads a bank feed.

## 8. Audit

`b2b_finance_gate_decisions` (migration 050) is an insert-only journal. One row per evaluated decision: order, company, phase, mode, outcome, reason codes, the full snapshot, actor, request id and time.

- Mode `off` writes nothing.
- `ALLOWED`, `ALLOWED_BY_CEO_EXCEPTION` and `WOULD_BLOCK` rows commit with the order action.
- A `BLOCKED` row is written after the refused transaction rolled back. A refusal is therefore auditable although the order did not change. Each refused attempt writes one row.
- The application never updates or deletes a row. A CHECK refuses an impossible mode and outcome pair.

The journal is the data for the shadow analysis: `WOULD_BLOCK` rows grouped by reason show what `enforce` would refuse.

## 9. Idempotency and concurrency

- Two simultaneous finalizations of one company wait on the company lock. The second one sees the first receivable in the exposure. They cannot both use the same credit.
- A double click (the same idempotency key) finalizes once: one receivable, one decision row.
- A limit or colour decision racing a finalization: the order is decided on one consistent basis, the one before or the one after the Finance decision.
- Two simultaneous releases of one order: one handoff, one canonical order, one release decision.
- A refused action stores no idempotency record. The same request sent again is evaluated again.

## 10. API

Order detail (`GET /b2b/orders/{id}`), new fields:

| Field | Content |
|---|---|
| `financeDecision` | The finalization decision, codes only: `phase`, `mode`, `outcome`, `reasons`, `decidedAt`, `orderType`, `exceptionId` when an exception applied. `null` on a draft and on every order finalized before migration 050. |
| `financeReleaseDecision` | The release decision from the handoff, codes only, plus `changedSinceFinalization`, `grandfathered` and `finalization` (outcome, mode, time). `null` before the handoff. |
| `financeGate` | `{configured, finalizationMode, releaseMode, preview}`. `preview` is `{phase, mode, outcome, reasons}` for the next decision of this order (finalization for a draft, release for a finalized order not in production), or `null`. |

The stored snapshots keep the full Finance basis (limit, exposure, available credit, order gross, exception amount) in the database and the journal. The B2B order detail never returns it (owner decision D6, default; `FinanceGate::forOrderView`): a B2B order viewer is not a Finance or account viewer.

`GET /b2b/access` lists `features: ["orderTypes", "financeGate"]` (API 2.67.0). B2B 0.9.0 shows and sends the order type only when the list says so, so it also runs on API 2.66.0 (see `docs/release-plan-b2b-finance.md`).

`preview` is advisory. It is read without a lock and never stored. The binding decision is taken again under the company lock when the action runs. It carries codes only, no amount.

Errors:

| Status | Code | Details |
|---|---|---|
| 409 | `FINANCE_NOT_ELIGIBLE` | `{reasons: [...], phase: "finalization"}` |
| 409 | `FINANCE_RELEASE_BLOCKED` | `{reasons: [...], phase: "production_submission"}` |
| 503 | `FINANCE_GATE_MISCONFIGURED` | none |

Journal read for the shadow analysis (`GET /finance/b2b/gate-decisions`, Finance application, `finance.credit.view`):

| Parameter | Values |
|---|---|
| `outcome` | `ALLOWED`, `ALLOWED_BY_CEO_EXCEPTION`, `WOULD_BLOCK`, `BLOCKED` |
| `phase` | `finalization`, `production_submission` |
| `cursor` | the `nextCursor` of the previous page (100 rows per page, newest first) |

Each item has the order and company codes, phase, mode, outcome, reason codes, order type, `changedSinceFinalization`, `grandfathered`, the CEO exception id, the actor and the request id. `summary` counts the rows per phase and outcome under the same filters. Limit, exposure and order amounts of the stored snapshot are not returned (owner decision D6). Before switching to `enforce` (D2), Finance reads the `WOULD_BLOCK` rows of the shadow period here.

## 11. What a decision never does

A finance decision never creates a shipment, an AWB or a delivery record, never marks an order delivered, never writes a ledger movement, never writes a stage event and never changes a QR. The receivable is still posted exactly once, by the finalization, under its unique key.

## 12. Owner decisions (none is taken here)

Each row compares the decision with what the code does today, the risk, and a recommendation. Nothing here is decided or
activated. Read-only evidence for D2 and D3: `php bin/b2b-finance-gate-readiness.php [--detail]` (section 14).

| Id | Decision | What the code does until decided | Risk | Recommendation |
|---|---|---|---|---|
| D1 | Switch production to `shadow`. | `off`: nothing is evaluated, nothing journaled. `shadow` needs `ARASYA_FINANCE_CONFIG_ACK` with the printed fingerprint, or the next API deployment is refused. | Low. Orders continue; each finalization and release adds a profile read under the company lock (a few queries) and one journal row. While few companies are onboarded, almost every row is `WOULD_BLOCK` with `IDENTITY_NOT_VERIFIED` or `RISK_NOT_ASSIGNED`: expected noise, not an incident. | After API 2.67.0 runs without incident for 2–3 working days. Write both keys explicitly (`ARASYA_B2B_FINANCE_GATE=shadow`, `ARASYA_B2B_FINANCE_RELEASE_GATE=shadow`), record the fingerprint as the acknowledgement, run `php bin/finance-config-check.php`. Review `#/b2bGate` weekly. |
| D2 | Switch production to `enforce`, after the shadow analysis. | Not possible without a new acknowledgement. Under `enforce` every company without a verified identity, a reconciled account, a colour and an approved limit is refused at finalization. | **High.** Enforce on a partly onboarded customer base stops B2B sales for every company not yet through Finance. A finalized order of such a company is also stopped at release (D3). | Only when `bin/b2b-finance-gate-readiness.php` shows every company that orders on credit as `ready_for_credit`, and every `WOULD_BLOCK` row of at least two weeks of shadow has an explanation. Stage it: `ARASYA_B2B_FINANCE_GATE=enforce` with `ARASYA_B2B_FINANCE_RELEASE_GATE=shadow` for one week, then the release check to `enforce`. Rollback: write `off` (the default needs no acknowledgement) or `shadow`. |
| D3 | Orders finalized before activation: evaluate at release (default) or release without a check. | Evaluated at release when the release check is `enforce`, marked `grandfathered: true`. Under `shadow` they are released and journaled. | Medium at the D2 switch: a finalized, unsubmitted order of a company that is not ready is stopped at release although it was accepted earlier. | Keep the default. Before the release check goes to `enforce`, list such orders with `bin/b2b-finance-gate-readiness.php --detail` (`production_submission/BLOCKED/grandfathered`) and either submit them during shadow or complete the company's Finance onboarding. No code change needed. |
| D4 | CEO exception: which reasons it covers and how long it lives. | Credit reasons only, RED companies only, at most 30 days, re-checked at release. Inactive: `ARASYA_FINANCE_CEO_EMPLOYEE_UUID` is empty, so no exception can be approved. | Low while inactive. Once configured, one person can override a credit refusal for one order; requester and approver are always different people and every exception is journaled. | Keep inactive until after D2. When needed: configure Mesut Yeman's employee UUID (with the acknowledgement), keep the reason list and 30 days. Review the exceptions monthly in Finance. |
| D5 | Prepaid orders. | Not modelled. Under `enforce` an order of a company without an approved limit is refused even if the customer paid in advance. The refusal is correct until a verified, recorded payment can be shown to the gate. | Medium under `enforce` for cash or advance-payment customers: their B2B orders stop at finalization. | **Do not use an artificial or symbolic credit limit** (section 7: it opens real unsecured credit and falsifies the credit record). Before D2: (1) list the companies that buy only with advance payment (readiness `--detail`, `NO_APPROVED_LIMIT`); (2) either keep the finalization check in `shadow` until a prepaid rule exists, or accept that those orders wait; (3) a separate, later change adds an explicit payment term on the B2B order and a rule that allows finalization or release only when a payment movement verified by two people (Finance, with bank evidence) covers the order total in the order currency. A credit limit is given only where the owner actually decides to sell on credit. |
| D6 | Whether B2B sees the limit and exposure amounts of the finalization snapshot. | **Changed in this PR: codes only.** The B2B order detail returns outcome, mode, phase, reason codes and what changed; limit, exposure, available credit, order gross and exception amount stay in the database and the journal (`FinanceGate::forOrderView`). Finance's journal read also returns codes only. | Low. Before this change every holder of `b2b.orders.view` could read a customer's approved limit and ledger exposure from the order detail, including people without `b2b.accounts.view`. | Keep codes only. If sales needs amounts later, add them behind `b2b.accounts.view` in a separate change. |

Further operating risks:

- **Configuration takes effect without a deployment.** The API reads `ARASYA_B2B_FINANCE_GATE` from the private configuration on each request; the acknowledgement is checked only by the deployment and by `bin/readiness.php`. Procedure: after every edit of the private configuration run `php bin/finance-config-check.php` and keep the timestamped backup. The file stays mode 600 under the owner's control.
- **Mode `off` is the emergency stop.** Writing `off` (or removing both keys) needs no acknowledgement and applies on the next request. Journal rows stay as audit.

## 13. Tests

| Test | What it proves |
|---|---|
| `mysql-b2b-order-types-integration.php` M1-M6 | Journal, invalid configuration (never off), release check, CEO exception, real concurrent finalizations and releases, lock waits, Finance journal read. |
| `mysql-b2b-finance-journey-integration.php` J1-J18 | One synthetic legacy customer end to end: Finance import, duplicate link, two-person identity verification, opening balance posted once, account currency, owner colour and limit, typed order, preview, one finalization and one receivable, release decision on the handoff, QR and ticket revision 1, 1 → 2 → 14, Livrare handover, exposure = opening balance + receivable, B2B and factory history, Finance audit, and an unchanged TrendHome order and AWB station state. |
| same file, P1-P2 | Blocked path under `enforce` (refusal journaled, no receivable) and shadow path (`WOULD_BLOCK` recorded, the action goes on; `enforce` then stops the release with no handoff, no canonical order and no QR). |
| `mysql-b2b-order-types-integration.php` D2, D6 | The readiness CLI evaluates every open order as `enforce`, prints codes only and writes no journal row; the B2B order detail holds no Finance amount while the database keeps the basis; `GET /b2b/access` lists the features. |
| `mysql-b2b-finance-journey-integration.php` F1-F8 | Finance origin and session: credentialed preflight, gate from the exact origin, 401 readable by the origin, CSRF and origin refusal, default deny with the application grant alone, revocation on the next request, cookie flags. |

Both run on MySQL 8.4 and MariaDB 10.11 in CI.

## 14. Readiness report (read-only)

```bash
php bin/b2b-finance-gate-readiness.php            # counts and reason codes
php bin/b2b-finance-gate-readiness.php --detail   # plus internal order and company codes with reason codes
```

It evaluates every draft and every finalized, unsubmitted B2B order as `enforce` would now, and every active company's Finance
eligibility. It runs in a `READ ONLY` transaction, writes no journal row, never changes a mode, and prints no amount, name or
contact. Lines: `ORDERS <phase>/<outcome>[/grandfathered] <n>`, `REASON <phase>/<code> <n>`, `COMPANIES active|ready_for_credit <n>`,
`BLOCKER <code> <n>`, and with `--detail` `DETAIL <order code> <company code> <phase> <outcome> <codes>` or
`DETAIL <company code> company <codes>`.
