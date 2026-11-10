# Finance collections, four-eyes approval and bank transaction identity (API 2.68.0, migration 052)

Status: DRAFT. Not merged, not deployed, migration 052 not run in production. Every switch below is off in production
until a separate owner decision. Finance frontend: `finance_arasyahome` 0.6.0. B2B frontend: `b2b_arasyahome` 0.10.0.

## 1. Architecture: one ledger, Finance requests in front of it

- **Single financial record:** `b2b_account_movements`, written only through `B2B\AccountLedger` (unchanged since 011).
  There is no Finance ledger and no balance cache. Every figure is derived from the ledger on read.
- **Finance requests** (`finance_ledger_requests`): a payment, an adjustment, a reversal or a refund entered in Finance is
  a request. It reaches the ledger only when a second, authorized person approves it. Approval locks the company row
  (the same order as every ledger writer: company → request → ledger) and posts through `AccountLedger` in the same
  transaction. The movement snapshot names the request, the recorder and the approver.
- **B2B** keeps the account screen. Viewing stays in B2B (`b2b.accounts.view`). With `ARASYA_B2B_ACCOUNT_WRITES=finance_only`
  the B2B write routes refuse every request on the server (`409 ACCOUNT_WRITES_MOVED_TO_FINANCE`) and the screen is read-only.
  Order receivables and their cancellation reversals keep following the order lifecycle in every mode.

| Responsibility | Where | Permission |
|---|---|---|
| Company, orders, proposals, order history, production status | B2B | `b2b.*` |
| Account summary, movements, statements, Finance request status (read-only) | B2B | `b2b.accounts.view` (`export` for files) |
| Enter a payment request | Finance | `finance.collections.record` |
| Verify and approve or reject a payment | Finance | `finance.collections.approve` |
| Request an adjustment or reversal | Finance | `finance.ledger.request` |
| Approve or reject an adjustment or reversal | Finance | `finance.ledger.approve` |
| Request a refund / approve it / record the bank payout | Finance | `finance.refunds.request` / `.approve` / `.payout` |
| Allocate payments to orders, release allocations | Finance | `finance.reconciliation.manage` |
| Ledger view, request queue, bank reconciliation view | Finance | `finance.accounts.view` |
| Audit | Finance | `finance.audit.view` |

All eight new permissions are role-grantable, bound to the Finance application, and granted to nobody by migration 052.

## 2. Request lifecycle

`DRAFT → PENDING_APPROVAL → APPROVED` (posted), or `REJECTED` (by a second person, with a reason), or `WITHDRAWN` (by the
creator, before a decision). A posted payment, adjustment or opening balance that is later reversed through an approved
`REVERSAL` request becomes `REVERSED`. A refund goes `APPROVED` (posted, payout pending) → `PAID_OUT` (the bank or cash desk
paid it, recorded with the outgoing transaction identity) or → `REVERSED` (payout cancelled; the refund movement is reversed
and the customer credit returns). Approval never means that money left the bank.

## 3. Four eyes

- The person who approves or rejects is never the creator, the submitter or the last editor of the request
  (`403 FOUR_EYES_REQUIRED`). Holding both the record and the approve role does not change this. Root holds every
  permission but is refused like anyone else. There is no override.
- The database enforces the same rule: `chk_finance_ledger_four_eyes` refuses a decided row whose decider equals its
  creator, submitter or last editor, even for a direct SQL write.
- A refund payout is confirmed by someone other than the requester (application check and `chk_finance_ledger_payout_actor`).
- Permissions are re-read inside the approval transaction: a person whose role or Finance application was removed is
  refused on the very next request.

## 4. One real bank transaction, one entry

- A bank transfer or card payment needs its identity: provider (`ING`, a card processor, `CASH`…), the internal account
  alias (for example `ING-RON-01`; an IBAN-shaped value is refused, so full account numbers are never stored) and the
  provider's transaction id. The normalized identity (`IN|provider|account|currency|transaction id`, letters and digits only)
  is unique among live requests (`uq_finance_ledger_active_bank`, kept consistent with the status by a CHECK).
- The same identity again — with another Idempotency-Key, written differently, by another person — is refused with
  `409 BANK_TRANSACTION_ALREADY_RECORDED`. No acknowledgement overrides it. Only a reversal approved by a second person frees
  the identity, so a wrongly recorded payment can be recorded once more, correctly.
- Free text is never an identity. Entries of the same company and currency with the same normalized reference, or the
  same amount within three days, are listed as possible duplicates. Approval then needs `acknowledgePossibleDuplicates: true`
  (`409 POSSIBLE_DUPLICATE_UNACKNOWLEDGED` otherwise); the acknowledgement is stored on the request.
- Refund payouts carry an outgoing identity (`OUT|…`), unique as well: one bank transfer pays one refund.
- Retries: the per-actor Idempotency-Key replays the recorded result of a command (for example after a lost answer). A
  failure during posting rolls the whole approval back: no movement, the request stays pending.

## 5. Refunds

A refund is its own flow and its own movement type (`refund`, debit, with the payout method). It needs the payment it comes
from; the amount must not exceed that payment's unallocated part nor the company's credit in that currency. Approval posts
the refund movement and allocates the payment to it (that allocation cannot be released). The payout is recorded
separately. A refund that will not be paid is cancelled by a second person: the refund movement is reversed.

## 6. Bank reconciliation view

`GET /finance/reconciliation?currency=&from=&to=[&bankAccountKey=]` (at most 366 days, `finance.accounts.view`): every money
movement recorded with a bank identity, totals per account alias (in, out, net), ledger payments without a bank identity
(entered in B2B, or cash without a receipt id) and requests still pending. There is no bank connection: ING, SmartBill and
SAGA are not integrated (program decision). A person compares this view with the downloaded statement.

## 7. Independent switches (private configuration)

| Key | Values (default first) | Effect |
|---|---|---|
| `ARASYA_FINANCE_COLLECTIONS` | `disabled`, `enabled` | Only `enabled` lets Finance create, approve or post requests and allocations. Reads always work. |
| `ARASYA_B2B_ACCOUNT_WRITES` | `enabled`, `finance_only` | `finance_only` closes the B2B account write routes on the server. Any unrecognized value is treated as `finance_only` (it never widens what B2B may write). |
| `ARASYA_B2B_ORDER_TYPES` | `off`, `enabled` | Only `enabled` announces `orderTypes`, accepts a type on drafts and requires it at finalization. Off: a sent type is refused, a duplicate drops it, finalization needs none (full route, exactly as API 2.66.0). |

`GET /b2b/access` announces each feature only while its own switch is on: `orderTypes`, `financeGate` (the finance gate in
shadow or enforce) and `accountsReadOnly`. Turning one on never turns on another. `bin/finance-config-check.php`
(fingerprint v4) reports each key; a non-default value needs `ARASYA_FINANCE_CONFIG_ACK`, a malformed one blocks deployment.

## 8. HTTP contract

| Method | Route | Permission |
|---|---|---|
| GET | `/finance/ledger-requests` (`status`, `kind`, `companyId`, `mine=1`, `awaitingMe=1`, `cursor`) | `finance.accounts.view` |
| GET | `/finance/ledger-requests/{id}` (request, allocations, decision hints, possible duplicates, evidence, history) | `finance.accounts.view` |
| POST | `/finance/ledger-requests` (`kind`: `PAYMENT`, `ADJUSTMENT`, `REVERSAL`, `REFUND`; `submit` default true) | request permission of the kind |
| POST | `/finance/ledger-requests/{id}/update` · `/submit` · `/withdraw` | request permission, creator only |
| POST | `/finance/ledger-requests/{id}/approve` (`acknowledgePossibleDuplicates`, `allocationMode`) · `/reject` | approve permission of the kind, never the creator |
| POST | `/finance/ledger-requests/{id}/payout` · `/cancel-payout` | `finance.refunds.payout` · `finance.refunds.approve` |
| GET | `/finance/ledger/companies/{id}` · `/movements` · `/movements/{mid}` · `/open-items` | `finance.accounts.view` |
| POST | `/finance/ledger/companies/{id}/allocations` · `/allocations/{aid}/release` | `finance.reconciliation.manage` |
| GET | `/finance/reconciliation` | `finance.accounts.view` |
| GET | `/b2b/accounts/{id}/finance-requests` (codes, kinds, statuses, amounts, dates) | `b2b.accounts.view` |

Every write needs the session, the Finance application and `finance.access`, the exact origin, CSRF and an Idempotency-Key.

## 9. Tests

| Test | Proves |
|---|---|
| `mysql-finance-collections-integration.php` | Owner scenarios 1–12 on the new flow: partial payment, advance, overpayment credit, split payment, cancelled order and refund with separate payout, four eyes (also with both roles, root and a direct SQL write), the same bank transaction with another key, two approvers at once, lost answer and failure during posting, revoked approver, wrong company and currency; migration 052 additive and recorded once, existing ledger rows unchanged; B2B read-only mode and Finance-owned movements; independent order-type and finance-gate features; reconciliation view; audit without bank identities. |
| `mysql-b2b-finance-scenarios-integration.php` | Owner scenarios A–G on the ledger through the B2B routes of the transition period, and `finance_only` closing them. |
| `SourceConnectionUnitTests.php` | Config guard v4 for the three switches; `GET /b2b/access` features per switch; unrecognized values never widen anything. |
| `OperationsUnitTests.php` | Architecture: Finance posts only through `AccountLedger`, no second ledger, no Staff finance or IAM grant table touched. |

Both integration suites run on MySQL 8.4 and MariaDB 10.11 in CI.

## 10. Rollout and rollback

See `docs/release-plan-b2b-finance.md` (section 9). Rollback floor: 2.68.0 while a refund movement or an open request
exists (`bin/check-rollback.php`). Migration 052 is additive; an older API ignores the request table.
