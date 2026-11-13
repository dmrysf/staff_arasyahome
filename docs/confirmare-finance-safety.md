# Confirmare finance safety of the proforma expiry (API 2.71.0, Staff 2.51.0, migration 054)

Owner decision of 2026-11-13. Draft; not merged, migrated or deployed.

## Problem

The 120 h advance proforma expiry (`ConfirmareCycles`, owner decision K-05) cancels an unpaid proforma in SmartBill, stops the order in
Arasya and asks the shop to cancel it. It ran once a finance employee recorded a bank check of the shop covering the expiry instant.
Three gaps could make it cancel an order whose customer had paid, or whose payment finance was still looking for:

1. An **open finance task** (`finance_verification_requests`) did not stop it. The task is how finance looks for the money of an
   approved order, but until finance records the transfer, the shop's books show no money.
2. A transfer that arrives **before the approval** has no place on the order. Finance reaches an order only through its finance
   task, and the operator can open the task only once the order is approved (API 2.50.0). This rule is unchanged. Finance could
   record the transfer only as a shop transfer exception, which the expiry does not read.
3. A bank check is shop-wide. The first check after a long pause makes every expired proforma of the shop cancellable at once, and
   whoever records it does not see which orders those are.

The yd-soft proforma smoke reproduced gap 2 end to end, on Trend Home and on Outlet Perdele: the paid proforma was cancelled and the
order stopped, even though the transfer exception had been recorded.

## What changes

### Open finance task

While an order has an open finance task (`requested`, `assigned`, `checking`, `needs_clarification` or `exception`), its expired
proforma waits in `waiting_finance` with `last_error = finance_task_open`:

- nothing is cancelled in SmartBill;
- the order is not stopped;
- nothing is asked from the shop.

The cancellation request (`CancellationSyncService::requestForExpiredProforma`) checks the same thing again under the order lock.

### Finance hold (`confirmare_finance_holds`, migration 054)

A hold is an authorized, reasoned and audited stop of the expiry. It records no money: payments are still recorded only through
the finance task, with four eyes.

| | Who | Input |
| --- | --- | --- |
| Place | Confirmare `supervise` of the shop, finance `record` or `approve` of the shop, or the order's own operator | order number, reason code, note (5–500 characters) |
| Release | Confirmare `supervise` or finance `approve` of the shop, never the employee who placed it | reason code, note |

Reasons to place a hold: `early_transfer`, `transfer_exception`, `customer_reports_payment`, `finance_check_pending`, `other`.

Reasons to release one: `payment_recorded`, `not_customer_payment`, `returned_to_payer`, `no_payment_found`, `other`.

While a hold is open:

- the expiry waits (`last_error = finance_hold`), and a cancellation request already on its way does not stop the order;
- production stays closed: the payment condition of `ConfirmationClearance` does not hold, so no production approval happens
  automatically;
- a hold cannot be placed once production has started or the order is closed.

Guarantees, in the database as well as in the application:

- at most one open hold per order;
- a released hold carries every release field;
- the releasing employee differs from the placing one (CHECK `chk_confirmare_finance_holds_four_eyes`).

The row records who, when and why, both for placing and for releasing. Each action also writes an IAM audit event, with codes only:
`confirmare.finance_hold.placed` and `confirmare.finance_hold.released`.

### Bank check preview

`GET /confirmare/finance/bank-check/preview?source=` lists the expired proformas that the check would cover. For each one it shows
the order number, the expiry and the outcome:

- `may_cancel`: the proforma is cancelled unless the shop shows money at that moment;
- `waits`: an open finance task or a hold keeps it waiting.

The preview also returns a fingerprint and the instant it was computed for. `POST /confirmare/finance/bank-check` must carry that
`coversUntil` and that `previewFingerprint` whenever the check covers an expired proforma:

- without them the check is refused with `CONFIRMARE_BANK_CHECK_PREVIEW_REQUIRED`;
- if the list changed since the preview, it is refused with `CONFIRMARE_BANK_CHECK_PREVIEW_STALE`;
- in both cases nothing is recorded.

The preview shows order numbers to whoever may record the bank check; this is the data finance compares with the statement.
Before 2.71.0 the cycle list was Confirmare-only.

### Review list

`GET /confirmare/finance/holds` is for the shop's Confirmare supervisors and its finance `record` / `approve` employees. It lists:

- open holds, and holds released in the last 7 days;
- finance tasks open for more than 24 h;
- expired proformas, with what each one waits for.

Staff 2.51.0 shows it in the finance screen, together with the place and release forms. The cycle list also says what each expired
proforma waits for.

## Rollback

`bin/check-rollback.php` requires 2.71.0 while a hold is open, because an older API ignores holds. Migration 054 only adds the
table. An older API also has no preview: Staff 2.51.0 would then fail the bank check preview, so Staff rolls back together with
the API.

## Tests

- `tests/mysql-confirmare-cycle-integration.php` section 12 (S12), on MySQL and MariaDB:
  - task, hold, preview, the bank check refusals and the waiting cycles;
  - the control order still cancels;
  - no churn of the waiting cycles;
  - the review list and its stale tasks;
  - release rights, and the four eyes in the application and in the database;
  - a hold stops an order cancellation already requested;
  - production clearance closed while a hold is open;
  - no hold after production started.
- yd-soft proforma smoke (real WordPress and SmartBill 3.4.10, offline):
  - `c-expire` runs through the preview;
  - `T`: an open task waits;
  - `E`: early transfer, hold, expiry waits, approval, four-eyes verification, production closed until a second person releases
    the hold.
