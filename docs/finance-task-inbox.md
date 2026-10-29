# Finance task inbox (API 2.50.0, migration 044, Staff 2.41.0, Dashboard 0.16.0, YD SOFT 2.23.0)

The finance employee works only the finance tasks assigned to them by name. A finance scope no longer opens any order of
a shop: it reaches an order only through a finance task (default deny); `record` or `approve` alone reaches nothing. The
two-person rule for recorded bank transfers stays the production default; single-person verification exists behind a
policy that is off everywhere.

## Scope (owner decision 2026-10-29)

Every Trend Home and Outlet Perdele order first submitted at or after **2026-10-22 00:00 Europe/Bucharest
(2026-10-21T21:00:00Z, inclusive)** is covered, whatever its workflow cohort (`confirmare`, `legacy`, `NULL`,
`undetermined`) and whenever it reached Arasya (`FinanceTaskScope`). The date is the shop's first real submission
(`operational_orders.source_submitted_at` and its evidence kind), never the last change, a proforma date or the last
synchronisation; the first acceptance in Arasya (`created_at`) is reported apart. An order without submission evidence
is `undetermined`: never counted in or out silently, no task created for it (manual review). Covering an order never
creates a task by itself: a task exists only when an operator asks for one or the task inventory points one at an
existing finance record.

An order outside Confirmare keeps its workflow: it is never moved into Confirmare for finance. YD SOFT 2.23.0 lets
Arasya **read** its financial summary for a task (route `finance` only; every write, status and invoice route keeps
refusing it with `order_not_eligible`). Its task uses the `manual` ledger: a payment found on the statement ends the task
in `requires_manual_review` (the old workflow records it); it is never money received in Arasya.

Nothing here issues a document, creates an AWB, starts production, changes the Confirmare approval or sends anything to a
customer.

## Roles

| Who | Grant | Sees | Does |
| --- | --- | --- | --- |
| Confirmare operator (assignee or approver of the order, or a supervisor of the shop) | Confirmare `operate` / `supervise` | step 4 "Plata și avansul" of their order and the request's state; for an order outside Confirmare, the screen "Avansul unei comenzi din fluxul vechi" opened by the order number (any operator or supervisor of the shop: such an order has no Confirmare assignee) | sends the advance to finance, answers a clarification, withdraws a request |
| Finance employee | finance `record` (or `verify`) on the shop | "Verificări de plată": the tasks assigned to them by name (advance checks, exception tasks), and the second checks they are the named checker of | starts a check, confirms the payment, says it was not received, asks for a clarification, does a second check (`approve`), works an exception task in the order's money panel, reconciles a lost answer |
| Finance supervisor | `employee_finance_supervision` (root only, Dashboard "Supraveghere contabilitate") | "Activitatea contabilității": the requests of the employees they supervise, who recorded and who decided, the audit trail and the order's bank transfers | nothing (read only) |
| Finance `view` only | finance `view` | nothing new (no tasks) | nothing |

A finance employee never sees the customer, the products, the measurements, the address, the telephone or the order's
history. The inbox card shows the shop, the order number, the proforma's series and number and the state, never an
amount. The task screen reads the shop when it is opened (audited) and shows the proforma (series, number, amount,
currency), the advance (required, already verified, remaining), the proforma's payment reference, the bank account the
proforma names (the API sends the IBAN masked: country, check digits and last four characters) and the order's bank
transfers in the shop.

## Reach rule (`FinanceTaskAccess::reaches`)

A finance scope reaches an order only when one of these holds:

1. a finance task of the order is assigned to the employee, or they recorded or decided it;
2. an open task of the order names the employee as its checker (`checker_employee_uuid`: the second employee of a recorded
   transfer, or the approver of an exception task) and they still hold `approve`;
3. a finance action of the order they sent themselves is still pending or unknown (they reconcile it).

Holding `record` or `approve` alone reaches nothing, on any date. The old finance queue (`GET /confirmare/finance`) lists
no order to a finance scope any more (operators keep their advance tasks). The ordinary wait for a COD advance is
**not** finance work on its own: it becomes a task only when an operator sends it. The Confirmare order detail
(`GET /confirmare/orders/{id}`) is not open to a finance scope; an exception task is worked in Staff's finance-only money
panel (`/confirmare/finance/orders/{globalOrderId}`), which reads `GET /confirmare/orders/{id}/finance` only.

## Task inventory (`bin/finance-task-inventory.php`, dry run first)

Existing open finance work becomes tasks of named employees, from real records only:

- `second_check`: a transfer recorded in the shop through Arasya (`order_finance_actions` `submit_transfer`, applied, with
  its shop entry id) that no second employee decided and that the shop still reports `transfer_pending`. Assigned to its
  recorder; the least loaded other employee with `approve` is named its checker. The transfer is never recorded again and
  never decided by the inventory.
- `exception`: an exceptional money state of a Confirmare order (`refund_due`, `refund_liability`, `refund_pending`,
  `additional_payment_due`, `reconciliation_required`) or a full card refund requested and not verified. Assigned to the
  least loaded employee with `record`, its approver named as checker; resolved by the system when the state clears.
- Skipped with a reason, for a manual review: `evidence_missing` (the shop waits on a transfer Arasya has no record of),
  `out_of_scope`, `scope_undetermined`, `outside_confirmare` (a money attention of an order outside Confirmare),
  `open_task_exists`, `shop_not_waiting`.

`php bin/finance-task-inventory.php --report` prints the read-only reconciliation of the covered orders (scope, cohort,
evidence, payment method, money state, closed and completed orders, TBI, open actions, transfers awaiting a second
check, open tasks) and the plan. `--apply --yes` creates the planned tasks; applying it on production is a separate owner
authorization. `ARASYA_SOURCE_FINANCE_TASKS_<SRC>=auto` (default `off`) makes the minute's Confirmare distribution run it
too. A transfer recorded from an exception task's money panel gets its named checker at once (`onFinanceApplied`).

## Lifecycle

```
operator ─request─▶ requested ─(an eligible finance employee)─▶ assigned ─start─▶ checking
checking ─verified (single)───────────────────────────────▶ verified
checking ─verified (four_eyes: submit_transfer)─▶ checking + recorded + named checker ─second check verify─▶ verified
checking ─verified (manual ledger: an order outside Confirmare)─▶ requires_manual_review (nothing written in the shop)
                                                                       └─second check reject─▶ not_received
assigned/checking/exception ─not_received─▶ not_received
assigned/checking/exception ─needs_clarification─▶ needs_clarification ─operator resubmits─▶ assigned
requested/assigned/needs_clarification ─operator withdraws─▶ cancelled
a lost shop answer ─▶ exception (outcome_unknown) ─reconcile with the shop's stored answer─▶ verified / checking
inventory ─▶ second_check (checking, assigned to the recorder, named checker) ─second check─▶ verified / not_received
inventory ─▶ exception (assigned, named checker) ─money panel actions─▶ … ─state clears─▶ resolved (system)
```

- One open request per order (`open_order_uuid` unique). Asking again, a second tab or another operator returns the open
  one (`created: false`).
- Every command names the version it saw (`expectedVersion`) and carries an `Idempotency-Key`; a retried key answers
  with the current state. Money commands also name the shop's revision (`expectedRevision`).
- The money goes through `ConfirmareFinance` (order lock, revision, idempotency, one action per order, production block);
  its applied answers settle the request (`onFinanceApplied`), also when the same entry is decided from the money panel.
- A lost answer is never verified on a guess: the request stays `exception` until `reconcile` reads the shop's stored
  answer of the same key.
- Assignment: the least loaded eligible employee of the shop (`record`, or `verify` first under `single`). A request
  without anybody eligible stays `requested` and is assigned when someone opens the inbox (`rebalance`).

## Verification evidence

"Confirmă încasarea" needs the amount, the bank reference and the value date from the statement, the payment reference as
the statement shows it (it must equal the proforma's reference; a different one is a clarification, never a guess), the
currency of the order, and the explicit tick "Am găsit plata în extrasul bancar". The shop refuses an over-payment, a
duplicate bank reference, a cancelled or changed proforma and a cancelled order. Staff shows a confirmation before every
command and a receipt after it.

## Policy: four eyes (default) and single verification

| Layer | Switch | Default |
| --- | --- | --- |
| Operations API | `ARASYA_SOURCE_FINANCE_VERIFICATION_<SRC>` = `four_eyes` \| `single` | `four_eyes` |
| YD SOFT (each shop) | `wp yd-arasya access finance-verification four_eyes\|single --yes` (audited `finance_verification_changed`) | `four_eyes` |
| Employee | finance `verify` on the shop (root, Dashboard "Verificare") | none |

Single verification applies only when all three say so: both switches `single` and the employee holds `verify`. Then the
shop records and verifies the transfer in one step (`verify_transfer`, flag `single_verification`, the same employee as
recorder and decider). Otherwise the request uses `four_eyes`: the shop records the transfer (`submit_transfer`) and a
second employee with `approve` verifies it; nothing is money received before that.

**Sign-off required before `single` is switched on anywhere:** the owner and the accountant approve the policy in
writing. Today there is no trusted bank evidence feed (only manual statements and the bank-check records), so the
two-person rule remains the production safeguard. Switching `single` on is a financial-policy change, not a deployment
step.

## Operator side (Staff step 4)

"Trimite la contabilitate pentru verificare" (confirmation first) on the payment step of an approved COD order with an
advance proforma. The card shows the state in words ("La contabilitate", "Se verifică", "Al doilea control",
"Verificat", "Neprimit", "Necesită clarificare") and, when verified, "Avansul a fost verificat de contabilitate." It asks
`GET /confirmare/orders/{id}/finance-request` again from the server's hint (5 s while finance checks or the shop's
money state is on its way, 10 s while waiting; Staff clamps the hint to 5–10 s), never while the tab is hidden, not
at all once decided, and never after 30 minutes without a change (the employee can ask again). The poll is a database
read; it never sends a command and never asks the shop.

## Advance received short (owner decision 2026-10-29)

The customer may pay less than the advance asked (200.00 asked, 190.00 on the statement). No new money calculation was
written; the existing mechanisms carry it:

1. Finance records the REAL amount of the statement (`submit_transfer`, or `verify_transfer` under `single`). The task
   screen compares it with the advance left: a smaller amount shows "record exactly the amount on the statement: do not
   mark 'Payment not received'" and the difference; a larger one is refused in the browser (and by the shop) and needs a
   clarification. The amount is never rounded up to the advance asked.
2. Under `four_eyes` (default) the transfer is not money until the second employee verifies it (the shop's finance record,
   `DepositService`). The task then closes as `verified_partial`.
3. The operator's advance proforma section shows, from the shop's one money calculation (`MoneyPosition`): "Avans
   solicitat" (A), "Avans încasat și verificat" (N), "Diferență de avans" (G = A − N) and "Rest de plată la livrare"
   (K = T − N), with two choices:
   - "Acceptă avansul primit și continuă comanda": the existing `advance_accept` (a reason and a low-advance confirmation
     below half). Order total, invoice total and money received stay as they are; the difference stays inside K, so it is
     collected on delivery once. Production still opens only through the payment and production gates.
   - "Solicită diferența de avans": `advance_difference` (YD SOFT 2.23.0, route `POST
     /confirmare/orders/{id}/proforma/request-difference`), recorded in the shop only (who, when, amounts, prices). The order
     keeps waiting; the current proforma stays the document the customer pays (same payment reference); no document is
     issued and no e-mail is sent. Once paid, the operator sends a new finance request. A new proforma is needed only after
     a price change, through the existing rules (`total_accept` with `request_difference`, then `proforma_issue` for the
     missing advance only). The operator may still accept the amount received later.
4. What the courier collects is always WooCommerce's single net balance K (`Operations::cod_for`). An AWB made before a
   payment was verified collects the old amount: `CodCheck` names the mismatch, the label (`awb_label`) is refused with
   `cod_mismatch` (API `CONFIRMARE_AWB_COD_MISMATCH`), and the AWB is never changed or re-created silently. The employee
   corrects the cash on delivery in the FAN Courier panel and states the new amount (`awb_cod_corrected`, route `POST
   /confirmare/orders/{id}/awb/cod-corrected`, `{amount, reasonNote}`; the amount must be what is owed now). The statement
   is recorded (who, when) and shown as such, never as a FAN Courier confirmation; a later payment is a mismatch again.

Migration 044 widens the action CHECK of `order_invoice_actions` (`advance_difference`) and the kind CHECK of
`order_source_operations` (`awb_cod_corrected`); both are audited without any amount.

## Data and audit

- `finance_verification_requests` (minimized): the task, its purpose (`advance`, `second_check`, `exception`), task code,
  ledger (`shop`, `manual`), state and version, the assignee, recorder, named checker and decider, the policy, the shop
  entry id, an HMAC of the evidence (never the evidence itself) and the proforma's series and number (to notice a
  cancelled or replaced proforma). No amount, currency, payment reference, IBAN, bank transaction,
  customer or product is stored: "Operations stores no amount" holds. Finance data comes from an authorized read of the
  shop at the moment it is shown or decided.
- `finance_verification_request_events`: append-only, one row per version (codes, ids and instants only).
- `employee_finance_supervision`: who observes whom (root only, audited `employee.finance_supervision_changed`).
- Every task and oversight-detail read of the shop is audited like the finance panel's read.

## Rollback

`bin/check-rollback.php` sets the floor to 2.50.0 while a request is open (`openFinanceRequests`). Close or withdraw the
open requests (or let them settle) before rolling back below 2.50.0. Migration 044 adds tables and widens four CHECK
constraints; it changes no existing row. An older Staff keeps working against API 2.50.0 except that a finance-only
employee no longer opens Confirmare orders (by design); an older Dashboard keeps the three finance columns (the API keeps
the stored `verify` rows when `verify` is absent).

## Tests

- API: `tests/mysql-finance-requests-integration.php` (MySQL and MariaDB: the 22 October scope matrix with 21.10 23:59:59
  and 22.10 00:00 Bucharest, no evidence, late arrival, paid, cancelled, completed, TBI, both shops, the manual ledger,
  task-only reach, named checkers, the inventory), the adapted finance, invoice and relay suites.
- Staff: `tests/finance-requests.test.ts` (mapping, polling bounds, phases, access, routes, privacy of the screens),
  `tests/confirmare-invoice.test.ts` (the operator's decision on a smaller advance), `tests/confirmare-ops.test.ts` (the
  AWB collecting another amount).
- Advance received short: API `mysql-finance-requests-integration.php` (285.00 of 300.00 under four eyes),
  `mysql-confirmare-invoice-integration.php` (request the difference), `mysql-confirmare-operations-relay-integration.php`
  (label refused on a mismatch, the stated correction); YD SOFT `tests/money/run.php` (122.50/122.00, 200/190, full, zero,
  1.00, the difference paid later), `tests/wp-smoke/invoice.php` 12j and `tests/wp-smoke/operations.php` 6b.
- Browser, against the real API: `e2e/zzzzzzzzzzzzzzzzzzzzzz-finance-requests-real-api.spec.ts` (four-eyes verification,
  not received, clarification and resubmission, read-only oversight, and the operator's polling measured in the
  browser: 10 s waiting, 5 s checking, none while hidden, none once decided).
- Dashboard: `tests/ui.test.tsx`, `tests/api-client.test.ts` (verify column, supervision card, client).
- YD SOFT: `tests/finance/run.php` (settings, source checks, the 22 October boundary), `tests/wp-smoke/finance.php`
  sections 3b (single verification) and 6 (an order of 21.10 23:59:59 is neither read nor written; a covered order outside
  Confirmare is read only and changed in nothing), on a throwaway WordPress, offline.
