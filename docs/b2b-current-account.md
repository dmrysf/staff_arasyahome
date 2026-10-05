# B2B Current Account V1 — Operations API 2.10.0

An internal commercial receivables ledger per B2B company and currency, on the existing Central IAM session. It records what a company owes Arasya for finalized Classic orders, the payments it made, optional opening balances, manual adjustments and reversals. There are no invoices, no SmartBill, no FX conversion, no payment terms and no credit limits. Orders are never blocked by a balance. Nothing here writes to production, Staff, WooCommerce, Trendyol or any source integration.

## Sign convention and balance

Every movement has a positive `amount` and an explicit `direction`:

- **debit** increases what the company owes: a finalized order receivable, a debit opening balance, a debit adjustment, or the reversal of a credit.
- **credit** reduces it: a payment, a credit opening balance, a credit adjustment, or the reversal of a debit.

Balance per company and currency = sum of debits − sum of credits, computed by the database from the immutable movements (DECIMAL(16,2), no floats in PHP or SQL). A positive balance is money owed to Arasya, zero is settled, a negative balance is company credit in that currency. RON and EUR are fully separate: a payment can only settle receivables of its own currency and there is no conversion. There is no balance cache; every figure is derived from the ledger on read.

## Movements

| Type | Direction | Source |
|---|---|---|
| `order_receivable` | debit | Posted automatically when a Classic order is finalized |
| `payment` | credit | Recorded by a user with `record_payment` |
| `opening_balance` | debit or credit | Optional, at most one active per company and currency |
| `adjustment` | debit or credit | Manual correction with a mandatory reason |
| `reversal` | opposite of the original | Linked to exactly one original movement |

Movement rows are insert-only. The application never updates or deletes them and no foreign key cascades. A correction is always: original → reversal referencing it → optionally a new movement. Each movement has a UUID, a sequence number and a display code `B2B-MV-000001` (gaps allowed), a business `value_date` (the statement date, Europe/Bucharest calendar, never in the future), and separately `created_at` and the creating actor.

### Order receivables

Finalizing a Classic order posts its receivable in the **same database transaction** as the finalization, under the same company and order row locks and the same order idempotency record. The ledger code never opens or commits a transaction. If the ledger insert fails, the finalization rolls back and the order stays a draft. The amount is the server-calculated `gross_total` stored on the order at finalization, in the order currency. The value date is the finalization date in Europe/Bucharest. The movement keeps a snapshot: source type `b2b_order`, order UUID and code, currency, net/VAT/gross and the company identity frozen on the order. Later changes to the live company never rewrite it.

`receivable_order_uuid` is a unique column that is set only on order receivables (a CHECK constraint enforces this), so the database allows at most one receivable per order even outside the application. A finalize retry with the same key replays the recorded result; a new finalize request is refused with `ORDER_FINALIZED`. An order with a zero total posts no receivable, because every movement amount must be positive.

The Classic lifecycle is unchanged: `draft → finalized → cancelled` or `draft → cancelled`. Cancelling a **finalized** order posts one automatic `reversal` of its receivable in the same transaction (reason code `order_cancelled`) and releases the allocations that settled it. Cancelling a draft posts nothing. A receivable cannot be reversed manually (`ORDER_RECEIVABLE_FOLLOWS_ORDER`): the order lifecycle owns it, so order status and ledger can never disagree.

Migration 011 does not backfill. Orders finalized before 2.10.0 have no receivable. If an earlier finalized order must appear in the account, record it as a debit opening balance or adjustment with a reason.

### Payments

Required: currency, amount (decimal string, up to 2 places), value date and method. Method rules:

| Method | Reference | Note |
|---|---|---|
| `bank_transfer` | required | optional |
| `card` | optional | optional |
| `cash` | optional | optional |
| `compensation` | reference or note required | |
| `other` | optional | required |

Overpayment is allowed and produces company credit in the same currency.

### Allocations

Allocation is optional reconciliation detail; the ledger alone decides the balance. A payment can be allocated (when recorded, or later) to one or more unreversed order receivables of the same company and currency, partially. The total can never exceed the payment's unallocated amount (`ALLOCATION_EXCEEDS_PAYMENT`) or a receivable's outstanding amount (`ALLOCATION_EXCEEDS_OUTSTANDING`). An allocation row never changes. Releasing it inserts one release row (`manual` with a reason, or automatically `payment_reversed` / `receivable_reversed` when either side is reversed). Reallocation is a release plus a new allocation.

### Opening balances and adjustments

Both need currency, direction, amount, value date and a reason. At most one unreversed opening balance per company and currency (`OPENING_BALANCE_EXISTS`); reverse it to replace it. Nothing is created automatically. V1 has no second approval; the movement records the actor, so an approval workflow can be added later without changing the ledger.

### Reversals

`POST …/movements/{id}/reverse` needs a value date (not before the original) and a reason. The unique `reversed_movement_uuid` column allows one reversal per movement (`MOVEMENT_ALREADY_REVERSED`). A reversal cannot itself be reversed (`REVERSAL_NOT_REVERSIBLE`); post a new movement instead.

### Inactive companies

Allowed: view, statements, payments, allocations, releases, reversals and credit adjustments. Refused with `COMPANY_INACTIVE`: new orders, opening balances and debit adjustments.

## Authority

Every route requires B2B application access and `b2b.access`. Migration 011 adds five role-grantable permissions (category `b2b`) without granting them to any role. They are bound to the `b2b` application, so they are inert without B2B access. Root keeps implicit authority.

| Permission | Authority |
|---|---|
| `b2b.accounts.view` | Overview, summary, movements, open items, statement view, activity |
| `b2b.accounts.record_payment` | Record payments, allocate, release allocations |
| `b2b.accounts.adjust` | Opening balances and adjustments |
| `b2b.accounts.reverse` | Reverse movements |
| `b2b.accounts.export` | CSV and PDF statements (together with view) |

## HTTP contract

| Method | Route after `/b2b/accounts` | Permission |
|---|---|---|
| GET | empty (overview: `search`, `status`, `balance=all|open`, `cursor`, `limit`) | view |
| GET | `/{companyId}` (summary) | view |
| GET | `/{companyId}/movements` (`currency`, `type`, `from`, `to`, `cursor`, `limit`); `/movements/{id}` | view |
| GET | `/{companyId}/open-items?currency=` | view |
| GET | `/{companyId}/statement?currency=&from=&to=` ; `/activity` | view |
| GET | `/{companyId}/statement.csv` and `/statement.pdf` (also `lang=ro|tr`) | view + export |
| POST | `/{companyId}/payments` | record_payment |
| POST | `/{companyId}/allocations`; `/allocations/{id}/release` | record_payment |
| POST | `/{companyId}/opening-balances`; `/adjustments` | adjust |
| POST | `/{companyId}/movements/{id}/reverse` | reverse |

There is no PUT, PATCH or DELETE. Writes need the exact origin, the session-bound CSRF token and an `Idempotency-Key`. Account idempotency (`b2b_account_idempotency`) is separate from order idempotency and from the internal business uniqueness above: the same key with the same request returns the recorded references; the same key with a different request, company or operation returns `409 IDEMPOTENCY_CONFLICT`. Permissions are re-checked inside the transaction, so a replay never outlives a revoked permission. A write answers with `movementId`, `allocationIds` and the current `summary` (null without view). Bodies and query strings are strict: unknown fields fail.

Every write locks the company row first (the same order as Companies and Orders: company → order → ledger). Each check (available payment amount, outstanding receivable, active opening balance, existing reversal) is evaluated under that lock, and unique keys back the critical ones. Simultaneous duplicate submissions, over-allocation, double reversal and double posting are covered by multi-process tests on MySQL 8 and MariaDB 10.11.

## Statements and exports

The statement for one company, currency and date range contains the company identity, opening balance (everything before `from`), the movements in business-date order with debit, credit and running balance, the range totals and the closing balance. All figures come from one SQL dataset (DECIMAL sums and a window function over the full history). The JSON view, the CSV and the PDF render that same dataset and never add amounts. A range is limited to 5,000 movements (`STATEMENT_TOO_LARGE`).

- CSV: UTF-8 with BOM, `;` separator, dot decimals, every cell quoted, spreadsheet formulas neutralized in text cells.
- PDF: A4, generated by a small built-in writer with an embedded, subsetted DejaVu Sans Condensed font (RO and TR diacritics render and can be copied). No external service or dependency.
- Romanian by default, Turkish with `lang=tr`.
- Exports contain no internal ids, notes, reasons, permissions or session data. Responses use `Cache-Control: no-store, private`.

## Audit

`b2b_account_activity_events` records one row per posted movement, allocation and release: actor, action, company, movement, allocation, order, currency, request id, idempotency key and time. Rows are never updated. Maintenance prunes only expired account idempotency replay references; movements, allocations and activity are never pruned.

## Rollout and rollback

Back up the database, deploy `api-deploy` 2.10.0, run `php bin/migrate.php`, then `php bin/readiness.php` (expects `b2b_account_permissions` OK). Then root composes roles in the Dashboard and the B2B 0.4.0 frontend is deployed. Migration 011 is additive. A code rollback to 2.9.1 ignores the new tables; keep them and every row. Note that orders finalized or cancelled while running 2.9.1 would then post no ledger rows.
